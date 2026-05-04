# nfsen-ng

[![GitHub license](https://img.shields.io/github/license/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/mbolli/nfsen-ng?style=flat-square)](https://github.com/mbolli/nfsen-ng/commits/master)
[![GitHub stars](https://img.shields.io/github/stars/mbolli/nfsen-ng?style=flat-square)](https://github.com/mbolli/nfsen-ng/stargazers)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![OpenSwoole](https://img.shields.io/badge/OpenSwoole-coroutines-F15B2A?style=flat-square)](https://openswoole.com/)
[![Docker](https://img.shields.io/badge/Docker-ghcr.io-2496ED?style=flat-square&logo=docker&logoColor=white)](https://github.com/mbolli/nfsen-ng/pkgs/container/nfsen-ng)
[![Donate a beer](https://img.shields.io/badge/paypal-donate-yellow.svg?style=flat-square)](https://paypal.me/bolli)

nfsen-ng is a modern, in-place replacement for the ageing [NfSen](http://nfsen.sourceforge.net/) web frontend. It sits on top of the existing [nfdump](https://github.com/phaag/nfdump) tools and adds real-time SSE push, a responsive UI, and support for RRD or VictoriaMetrics as the storage backend.

![nfsen-ng dashboard overview](https://github.com/mbolli/nfsen-ng/assets/722725/c3df942e-3d3c-4ef9-86ad-4e5780c7b6d8)

## Quick start

No clone needed — just grab the compose file and go:

```bash
curl -O https://raw.githubusercontent.com/mbolli/nfsen-ng/master/deploy/docker-compose.yml

# Edit NFSEN_SOURCES, NFSEN_NFDUMP_PROFILES, and other env vars in docker-compose.yml, then:

# Production with bundled Caddy (auto-HTTPS, ports 80/443)
docker compose --profile proxy up -d

# Production behind your own reverse proxy (app on port 9000 only)
docker compose up -d
```

Images are published on GHCR: [`ghcr.io/mbolli/nfsen-ng`](https://github.com/mbolli/nfsen-ng/pkgs/container/nfsen-ng) (app) and [`ghcr.io/mbolli/nfsen-ng-caddy`](https://github.com/mbolli/nfsen-ng/pkgs/container/nfsen-ng-caddy) (Caddy with Brotli).

**Development** (source mounted, auto-reload on file change):

```bash
git clone https://github.com/mbolli/nfsen-ng
cd nfsen-ng
docker compose -f deploy/docker-compose.dev.yml up -d
```

Set `NFSEN_SOURCES`, `NFSEN_NFDUMP_PROFILES`, and other options as environment variables in your compose file. See the [wiki](https://github.com/mbolli/nfsen-ng/wiki) for the full guide.

## Documentation

All documentation lives in the [wiki](https://github.com/mbolli/nfsen-ng/wiki):

- [Installation](https://github.com/mbolli/nfsen-ng/wiki/Installation) — Docker and bare-metal setup, systemd service
- [Configuration](https://github.com/mbolli/nfsen-ng/wiki/Configuration) — settings file, all environment variables
- [Import & admin](https://github.com/mbolli/nfsen-ng/wiki/CLI) — initial import, force rescan, daemon controls
- [Architecture](https://github.com/mbolli/nfsen-ng/wiki/Architecture) — component overview, signals, compression
- [VictoriaMetrics](https://github.com/mbolli/nfsen-ng/wiki/VictoriaMetrics) — alternative datasource setup
- [Upgrading from v0](https://github.com/mbolli/nfsen-ng/wiki/Upgrading-from-v0) — migration guide

