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

**Production** (port 80/443, immutable container):
```bash
docker-compose up -d
```

**Development** (port 8080, source mounted, instant code changes):
```bash
docker-compose -f docker-compose.dev.yml up -d
```

See [DOCKER_SETUP.md](DOCKER_SETUP.md) for the differences.

### Option B: Bare-metal (OpenSwoole)

Requires PHP 8.4+, OpenSwoole, inotify, and rrd extensions installed. See [INSTALL.md](INSTALL.md).

```bash
# Install Composer dependencies
composer install --no-dev

# Run initial import
php backend/cli.php -v import

# Start the HTTP server (port 9000; put Caddy in front for compression/TLS)
php backend/app.php
```

## 3. Access

```
http://localhost      # production Docker
http://localhost:8080 # development Docker / bare-metal
```

## Useful commands (Docker)

```bash
# Run a foreground import
docker-compose exec nfsen php backend/cli.php -v import

# Daemon status
docker-compose exec nfsen php backend/cli.php status

# Tail logs
docker-compose logs -f nfsen
```

## Troubleshooting

**No data showing**
- Check nfcapd files exist in the expected path structure: `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.*`
- Run a manual import: `php backend/cli.php -v import`
- Check RRD files: `ls backend/datasources/data/`

**SSE not connecting**
- Open browser DevTools → Network → filter by `_sse` — should show a persistent connection
- Ensure Caddy's `flush_interval -1` is set (already in the provided Caddyfile)

**Need to rebuild all data from scratch**
```bash
docker-compose exec nfsen php backend/cli.php -f -p import
# or set NFSEN_FORCE_IMPORT=true in docker-compose and restart
```

## Next steps

- Review all env vars: [ENVIRONMENT_VARIABLES.md](ENVIRONMENT_VARIABLES.md)
- VictoriaMetrics datasource: [VICTORIAMETRICS.md](VICTORIAMETRICS.md)
- Caddy + compression details: [CADDY_SWOOLE_SETUP.md](CADDY_SWOOLE_SETUP.md)
