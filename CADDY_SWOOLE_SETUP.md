# HTTP Compression with Caddy + OpenSwoole

## Architecture

```
Browser → Caddy (port 8080)  → OpenSwoole (port 9000, internal)
          ↓                       ↓
     Static Files            Dynamic PHP + SSE
     (compressed)            (compressed via Caddy)
```

## Components

### Caddy (Reverse Proxy + Static File Server)
- **Custom Build:** Built with `caddy-cbrotli` module for Brotli support
- **Compression:** Brotli, Zstandard, and Gzip (automatic negotiation)
- **Static Files:** `/frontend/*` served directly with caching
- **Security Headers:** X-Frame-Options, X-Content-Type-Options, Referrer-Policy
- **Reverse Proxy:** Proxies all dynamic requests to OpenSwoole

### OpenSwoole (Application Server)
- **Port:** 9000 (internal, not exposed)
- **Handles:** Dynamic PHP content, Backend APIs, SSE streaming
- **Workers:** 4 processes, 10k coroutines
- **Features:** RRD file watching, inter-worker broadcasts

## Files

### Dockerfile.caddy
```dockerfile
FROM caddy:builder AS builder

# Install build dependencies for Brotli (requires CGO and brotli-dev)
RUN apk add --no-cache gcc musl-dev brotli-dev

# Enable CGO for Brotli compilation
ENV CGO_ENABLED=1

# Build Caddy with Brotli encoder module
RUN xcaddy build \
    --with github.com/dunglas/caddy-cbrotli

FROM caddy:latest

# Install runtime Brotli libraries
RUN apk add --no-cache brotli-libs

# Copy the custom-built Caddy binary
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
```

### Caddyfile
```caddyfile
:8080 {
    # Security headers
    header {
        -Server
        X-Content-Type-Options nosniff
        X-Frame-Options DENY
        Referrer-Policy strict-origin-when-cross-origin
    }

    # Static files: Caddy compresses directly (php-via never sees /frontend/* requests)
    handle /frontend/* {
        encode br zstd gzip
        root * /var/www/html/nfsen-ng
        file_server
        header Cache-Control "public, max-age=31536000"
    }

    # Dynamic content: proxied to OpenSwoole; php-via applies Brotli compression
    reverse_proxy nfsen:9000 {
        # Keep connection open for SSE
        flush_interval -1
    }
}
```

### docker-compose.dev.yml (Development)
```yaml
services:
  nfsen:
    build:
      context: .
      dockerfile: Dockerfile.dev
    container_name: nfsen-ng
    expose:
      - "9000"  # OpenSwoole HTTP server (internal only)
    volumes:
      - /var/nfdump/profiles-data:/data/nfsen-ng
      - .:/var/www/html/nfsen-ng
    environment:
      - TZ=UTC
    entrypoint: ["/bin/bash", "/var/www/html/nfsen-ng/docker-entrypoint-dev.sh"]
    restart: unless-stopped
  
  caddy:
    build:
      context: .
      dockerfile: Dockerfile.caddy
    container_name: nfsen-caddy
    ports:
      - "8080:80"  # External HTTP port
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./frontend:/var/www/html/nfsen-ng/frontend:ro
    depends_on:
      - nfsen
    restart: unless-stopped
```

See [DOCKER_SETUP.md](DOCKER_SETUP.md) for production vs development configuration details.

## Compression Test

```bash
# Test Brotli compression on CSS
curl -I -H "Accept-Encoding: br" http://localhost:8080/frontend/css/nfsen-ng.css
# Should show: Content-Encoding: br

# Test compression on JavaScript
curl -I -H "Accept-Encoding: br" http://localhost:8080/frontend/js/nfsen-ng.js
# Should show: Content-Encoding: br

# Test compression on main page (proxied through OpenSwoole)
curl -I -H "Accept-Encoding: br" http://localhost:8080/
# Should show: Content-Encoding: br, Via: 1.1 Caddy
```

## Why This Approach?

1. **Separation of Concerns:** Caddy handles HTTP layer (compression, static files), OpenSwoole focuses on application logic
2. **Better Compression:** Caddy's Brotli implementation is more mature than OpenSwoole's
3. **Static File Optimization:** Direct file serving with proper caching headers
4. **Security:** Caddy adds security headers automatically
5. **SSE Support:** flush_interval=-1 ensures SSE streams work correctly

## Performance Impact

- **Brotli Compression:** ~20-30% better than gzip for text files
- **Static File Caching:** max-age=31536000 (1 year) reduces repeated requests
- **OpenSwoole Benefits:** Still get async I/O, coroutines, and low memory per connection
- **Minimal Overhead:** Reverse proxy adds <1ms latency on localhost

## Troubleshooting

### Caddy won't start - missing Brotli libraries
**Error:** `Error loading shared library libbrotlienc.so.1`
**Fix:** Ensure `brotli-libs` is installed in final image (see Dockerfile.caddy)

### OpenSwoole not accessible from Caddy
**Error:** `502 Bad Gateway`
**Fix:** Ensure OpenSwoole binds to `0.0.0.0:9000` not `127.0.0.1:9000`

### Static files not compressed
**Check:** Verify file size > 1KB (Caddy's minimum for compression)
**Check:** Client sends `Accept-Encoding: br` or `Accept-Encoding: gzip`

## References

- [Caddy Documentation](https://caddyserver.com/docs/)
- [caddy-cbrotli Module](https://github.com/dunglas/caddy-cbrotli)
- [OpenSwoole Documentation](https://openswoole.com/)
- [Brotli Compression](https://github.com/google/brotli)
