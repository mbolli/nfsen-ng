# nfsen-ng

[![GitHub release](https://img.shields.io/github/v/release/mbolli/nfsen-ng?style=flat-square)](https://github.com/mbolli/nfsen-ng/releases)
[![GitHub license](https://img.shields.io/github/license/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/issues)
[![GitHub last commit](https://img.shields.io/github/last-commit/mbolli/nfsen-ng?style=flat-square)](https://github.com/mbolli/nfsen-ng/commits/master)
[![GitHub stars](https://img.shields.io/github/stars/mbolli/nfsen-ng?style=flat-square)](https://github.com/mbolli/nfsen-ng/stargazers)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![OpenSwoole](https://img.shields.io/badge/OpenSwoole-coroutines-F15B2A?style=flat-square)](https://openswoole.com/)
[![Docker](https://img.shields.io/badge/Docker-ghcr.io-2496ED?style=flat-square&logo=docker&logoColor=white)](https://github.com/mbolli/nfsen-ng/pkgs/container/nfsen-ng)
[![Donate a beer](https://img.shields.io/badge/paypal-donate-yellow.svg?style=flat-square)](https://paypal.me/bolli)

nfsen-ng is a modern, in-place replacement for the ageing [NfSen](http://nfsen.sourceforge.net/) web frontend. It sits on top of the existing [nfdump](https://github.com/phaag/nfdump) tools and adds real-time SSE push, a responsive UI, and support for RRD or VictoriaMetrics as the storage backend.

> **Requires Linux.** The backend runs on the [OpenSwoole](https://openswoole.com/) PHP extension, which has no maintained FreeBSD/other-BSD port — see [openswoole/ext-openswoole#233](https://github.com/openswoole/ext-openswoole/issues/233). Docker images are Linux-only.

![nfsen-ng dashboard overview, light and dark](https://raw.githubusercontent.com/mbolli/nfsen-ng/master/book/src/images/00-page-graphs.png)

![nfsen-ng Sankey diagram visualizing src IP -> dst port -> dst IP traffic flow, light and dark](https://raw.githubusercontent.com/mbolli/nfsen-ng/master/book/src/images/guide-sankey-ports.png)
*Sankey traffic-flow diagram with the optional destination-port column, available since v1.0.0-beta.1.*

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

The app image is published on GHCR: [`ghcr.io/mbolli/nfsen-ng`](https://github.com/mbolli/nfsen-ng/pkgs/container/nfsen-ng). The bundled-Caddy profile uses the stock [`caddy:latest`](https://hub.docker.com/_/caddy) image — no custom build needed, since php-via serves and Brotli-compresses static assets itself. `:latest` tracks the newest release, betas included while pre-1.0; `:edge` always tracks the newest `master` build; or pin an explicit version tag from [Releases](https://github.com/mbolli/nfsen-ng/releases) for a fixed image.

**Development** (source mounted, auto-reload on file change):

```bash
git clone https://github.com/mbolli/nfsen-ng
cd nfsen-ng
docker compose -f deploy/docker-compose.dev.yml up -d
```

Set `NFSEN_SOURCES`, `NFSEN_NFDUMP_PROFILES`, and other options as environment variables in your compose file. See [Installation](https://mbolli.github.io/nfsen-ng/deployment/installation.html) and [Configuration](https://mbolli.github.io/nfsen-ng/deployment/configuration.html) in the book for the full guide.

## Documentation

The full user guide and developer reference now live in the **[nfsen-ng book](https://mbolli.github.io/nfsen-ng/)** — installation, configuration, every tab's feature docs, and the architecture/signals/SSE internals for contributors.

Migrating from the old v0.x NfSen-style release? See the [upgrade guide](https://mbolli.github.io/nfsen-ng/deployment/upgrading.html) in the book.

