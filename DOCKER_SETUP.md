# Docker Setup - Development vs Production

nfsen-ng now has separate Docker configurations for development and production environments.

## Production Setup (Default)

**Files:**
- `Dockerfile` - Production container
- `docker-compose.yml` - Production compose config
- `docker-entrypoint.sh` - Production entrypoint script

**Characteristics:**
- Clones code from GitHub
- Optimized production dependencies only (`--no-dev --optimize-autoloader`)
- Smaller image size
- No source code mounted (immutable container)
- Suitable for deployment

**Usage:**
```bash
# Build and start production container
docker-compose build
docker-compose up -d

# View logs
docker-compose logs -f

# Stop
docker-compose down
```

## Development Setup

**Files:**
- `Dockerfile.dev` - Development container
- `docker-compose.dev.yml` - Development compose config
- `docker-entrypoint-dev.sh` - Development entrypoint script

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
docker-compose -f docker-compose.dev.yml build
docker-compose -f docker-compose.dev.yml up -d

# View logs
docker-compose -f docker-compose.dev.yml logs -f

# Stop
docker-compose -f docker-compose.dev.yml down
```

## Key Differences

| Feature | Production | Development |
|---------|------------|-------------|
| Dockerfile | `Dockerfile` | `Dockerfile.dev` |
| Compose File | `docker-compose.yml` | `docker-compose.dev.yml` |
| Entrypoint | `docker-entrypoint.sh` | `docker-entrypoint-dev.sh` |
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
- PHP 8.3 CLI base image
- nfdump 1.7.6 (compiled from source)
- Same exposed port (9000 for OpenSwoole HTTP server)
- Caddy reverse proxy (port 80/8080)
