# Docker Setup - Development vs Production

nfsen-ng now has separate Docker configurations for development and production environments.

## Production Setup (Default)

**Files:**
- `deploy/Dockerfile` - Production container
- `deploy/docker-compose.yml` - Production compose config
- `deploy/docker-entrypoint.sh` - Production entrypoint script

**Characteristics:**
- Clones code from GitHub
- Optimized production dependencies only (`--no-dev --optimize-autoloader`)
- Smaller image size
- No source code mounted (immutable container)
- Suitable for deployment

**Usage:**
```bash
# Build and start production container
docker compose -f deploy/docker-compose.yml build
docker compose -f deploy/docker-compose.yml up -d

# View logs
docker compose -f deploy/docker-compose.yml logs -f

# Stop
docker compose -f deploy/docker-compose.yml down
```

## Development Setup

**Files:**
- `deploy/Dockerfile.dev` - Development container
- `deploy/docker-compose.dev.yml` - Development compose config
- `deploy/docker-entrypoint-dev.sh` - Development entrypoint script

**Characteristics:**
- Uses local source code (mounted volume)
- Includes dev dependencies (PHPStan, Rector, PHP-CS-Fixer, etc.)
- Code changes instantly reflected (no rebuild needed)
- Auto-reload with `entr` file watcher
- Composer dependencies installed via entrypoint if missing
- Faster iteration during development

**Usage:**
```bash
# Build and start development container
docker compose -f deploy/docker-compose.dev.yml build
docker compose -f deploy/docker-compose.dev.yml up -d

# View logs
docker compose -f deploy/docker-compose.dev.yml logs -f

# Stop
docker compose -f deploy/docker-compose.dev.yml down
```

## Key Differences

| Feature | Production | Development |
|---------|------------|-------------|
| Dockerfile | `deploy/Dockerfile` | `deploy/Dockerfile.dev` |
| Compose File | `deploy/docker-compose.yml` | `deploy/docker-compose.dev.yml` |
| Entrypoint | `deploy/docker-entrypoint.sh` | `deploy/docker-entrypoint-dev.sh` |
| Source Code | Cloned from git | Mounted from host |
| Dependencies | Production only | All (including dev tools) |
| Composer Install | Baked into image | Via entrypoint if needed |
| Code Changes | Requires rebuild | Instant (auto-reload with entr) |
| Image Size | Smaller | Larger |
| Use Case | Deployment | Local development |

## PHP Extensions Installed

Both configurations include:
- **OpenSwoole** - Async HTTP server for SSE scalability (via [php-via](https://github.com/mbolli/php-via))
- **inotify** - Linux file system monitoring for RRD changes
- **rrd** - RRDtool PHP bindings

## Shared Components

Both use:
- PHP 8.4 CLI base image
- nfdump 1.7.6 (compiled from source)
- Same exposed port (9000 for OpenSwoole HTTP server)
- Caddy reverse proxy (port 80/8080)
