# HTTP Compression

nfsen-ng uses a split compression strategy:

| Content | Compressed by |
|---------|---------------|
| Dynamic PHP (full-page SSE renders, actions) | **php-via** (OpenSwoole + `ext-brotli`) |
| Static files (`/frontend/*`) | **Caddy** (`encode br zstd gzip` in static handler) |

## Dynamic content — php-via

`app.php` configures php-via with Brotli enabled:

```php
$viaConfig = (new ViaConfig())
    ->withBrotli()   // requires ext-brotli
    // ...
```

php-via calls OpenSwoole's built-in compression, which negotiates the algorithm with the client via `Accept-Encoding`:

1. **Brotli (`br`)** — preferred when `ext-brotli` is installed; ~20-30% smaller than gzip
2. **Gzip** — fallback for older clients

The `ext-brotli` PHP extension is installed in both Dockerfiles:

```dockerfile
RUN install-php-extensions openswoole inotify brotli
```

## Static files — Caddy

`/frontend/*` is served directly by Caddy's `file_server`, so php-via never sees those requests. The `encode` directive lives inside the static file handler:

```caddyfile
handle /frontend/* {
    encode br zstd gzip
    root * /var/www/html/nfsen-ng
    file_server
    header Cache-Control "public, max-age=31536000"
}
```

This requires the custom Caddy build with [`caddy-cbrotli`](https://github.com/dunglas/caddy-cbrotli) (see `Dockerfile.caddy`). The stock `caddy:latest` image only supports gzip.

## Why not let Caddy compress everything?

php-via compresses the response before it leaves OpenSwoole. If Caddy's `encode` were also active globally, Caddy would detect the `Content-Encoding: br` header on the proxied response and skip re-encoding it (RFC-compliant). But it's cleaner to not rely on that and be explicit: `encode` only where Caddy actually generates the content.

## Testing

```bash
# Dynamic page — compressed by php-via
curl -sI -H "Accept-Encoding: br" http://localhost:8080/ \
  | grep -i content-encoding
# Expected: content-encoding: br

# Static asset — compressed by Caddy
curl -sI -H "Accept-Encoding: br" http://localhost:8080/frontend/css/nfsen-ng.css \
  | grep -i content-encoding
# Expected: content-encoding: br

# Compare page sizes
curl -so /dev/null -w "uncompressed: %{size_download} bytes\n" http://localhost:8080/
curl -so /dev/null -w "brotli:       %{size_download} bytes\n" -H "Accept-Encoding: br" http://localhost:8080/
```

## Troubleshooting

**Dynamic page not compressed (no `Content-Encoding` header)**
The `ext-brotli` extension may not be installed. Rebuild the image: `docker-compose build nfsen`. Verify inside container: `php -m | grep brotli`.

**Static files not compressed**
The custom `Dockerfile.caddy` must be used (stock `caddy:latest` lacks native Brotli). Rebuild: `docker-compose build caddy`.

**`502 Bad Gateway` from Caddy**
Ensure OpenSwoole binds to `0.0.0.0:9000`, not `127.0.0.1:9000`.


