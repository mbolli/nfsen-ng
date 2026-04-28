# Quick Start

## 1. Clone and configure

```bash
git clone https://github.com/mbolli/nfsen-ng
cd nfsen-ng
cp backend/settings/settings.php.dist backend/settings/settings.php
# Edit backend/settings/settings.php — set your sources and nfcapd path
```

## 2. Start

### Option A: Docker (recommended)

**Production** (port 80/443, with bundled Caddy reverse proxy):
```bash
docker compose -f deploy/docker-compose.yml --profile proxy up -d
```

> Without `--profile proxy`, nfsen listens on port 9000 internally — use this when you have your own Traefik/nginx/Caddy in front.

**Development** (port 8080, source mounted, instant code changes):
```bash
docker compose -f deploy/docker-compose.dev.yml up -d
```

See [DOCKER_SETUP.md](docs/DOCKER_SETUP.md) for the differences.

### Option B: Bare-metal (OpenSwoole)

Requires PHP 8.4+, OpenSwoole, inotify, and rrd extensions installed. See [INSTALL.md](INSTALL.md).

```bash
# Install Composer dependencies
composer install --no-dev

# Start the HTTP server (port 9000; put Caddy in front for compression/TLS)
# On startup, app.php runs a gap-fill automatically; use Admin panel → Initial Import for a fresh install.
php backend/app.php
```

## 3. Access

```
http://localhost      # production Docker
http://localhost:8080 # development Docker / bare-metal
```

## Useful commands (Docker)

```bash
# Tail logs
docker compose -f deploy/docker-compose.yml logs -f nfsen
```

## Troubleshooting

**No data showing**
- Check nfcapd files exist in the expected path structure: `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.*`
- Trigger a manual import: use **Admin panel → Initial Import** in the web UI
- Check RRD files: `ls backend/datasources/data/`

**SSE not connecting**
- Open browser DevTools → Network → filter by `_sse` — should show a persistent connection
- Ensure Caddy's `flush_interval -1` is set (already in the provided Caddyfile)

**Need to rebuild all data from scratch**

Use **Admin panel → Force Rescan** in the web UI.

## Next steps

- Review all env vars: [ENVIRONMENT_VARIABLES.md](docs/ENVIRONMENT_VARIABLES.md)
- VictoriaMetrics datasource: [VICTORIAMETRICS.md](docs/VICTORIAMETRICS.md)
- Caddy + compression details: [HTTP_COMPRESSION.md](docs/HTTP_COMPRESSION.md)
