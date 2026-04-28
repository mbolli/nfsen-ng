# nfsen-ng

[![GitHub license](https://img.shields.io/github/license/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/issues)
[![Donate a beer](https://img.shields.io/badge/paypal-donate-yellow.svg?style=flat-square)](https://paypal.me/bolli)

nfsen-ng is an in-place replacement for the ageing nfsen.

![nfsen-ng dashboard overview](https://github.com/mbolli/nfsen-ng/assets/722725/c3df942e-3d3c-4ef9-86ad-4e5780c7b6d8)

## What's New

This version includes major architectural improvements:

- **Hypermedia Architecture**: Server-side rendered HTML with [Datastar](https://data-star.dev) for real-time updates via SSE — no JSON API, no client-side routing
- **Server-Push**: inotify watches nfcapd files; RRD updates are broadcast over SSE to all connected tabs automatically
- **OpenSwoole + php-via**: Coroutine-based HTTP server handles 10,000+ concurrent SSE connections in a single worker
- **Caddy Reverse Proxy**: Brotli/zstd/gzip compression, static file serving, TLS termination
- **Configurable RRD Retention**: Adjust via `NFSEN_IMPORT_YEARS` (default: 3 years)
- **Interactive Graph Resolution**: User-selectable data point density (50–2000 points)
- **Enhanced Date Navigation**: Range slider with quick presets, period navigation, graph zoom sync
- **Web Components**: Native Web Components replace third-party widget libraries

## Components

* Front end: [Datastar](https://data-star.dev), [dygraphs](http://dygraphs.com), [noUiSlider](https://refreshless.com/nouislider/)
* Back end: [RRDtool](http://oss.oetiker.ch/rrdtool/), [nfdump tools](https://github.com/phaag/nfdump), [OpenSwoole](https://openswoole.com), [php-via](https://github.com/mbolli/php-via)

## TOC

* [Quick Start](#quick-start)
* [Configuration](#configuration)
  * [Settings file](#settings-file)
  * [Environment variables](#environment-variables)
  * [Nfdump / nfcapd](#nfdump--nfcapd)
* [Import & Daemon](#import--daemon)
  * [Daemon as a systemd service](#daemon-as-a-systemd-service)
* [Logs](#logs)

## Quick Start

Docker is the recommended way to run nfsen-ng. See [QUICKSTART.md](./QUICKSTART.md) for the fastest path and [DOCKER_SETUP.md](./docs/DOCKER_SETUP.md) for dev vs production details.

For a bare-metal install (no Docker), see [INSTALL.md](./INSTALL.md).

```bash
# Production (port 80/443, with bundled Caddy reverse proxy)
docker compose -f deploy/docker-compose.yml --profile proxy up -d

# Development (port 8080, source mounted)
docker compose -f deploy/docker-compose.dev.yml up -d
```

## Configuration

### Environment variables

For Docker deployments (the primary use case), environment variables are all you need. Set `NFSEN_SOURCES`, `NFSEN_PORTS`, `NFSEN_NFDUMP_PROFILES`, and any other options directly in your `docker-compose.yml`. See [ENVIRONMENT_VARIABLES.md](./docs/ENVIRONMENT_VARIABLES.md) for the full reference.

### Settings file

`settings.php` is **optional** — the app runs purely from env vars without it. It is mainly useful for bare-metal installs or complex setups (many sources, custom filters, multiple datasources). Copy `backend/settings/settings.php.dist` and edit it. Key options:

* **general.sources** — list of nfcapd source names, e.g. `['source1', 'source2']`
* **general.ports** — port numbers to track in RRD
* **general.db** — datasource class: `RRD` (default) or `VictoriaMetrics`
* **nfdump.binary** — path to nfdump binary (default: `/usr/bin/nfdump`)
* **nfdump.profiles-data** — path to nfcapd data directory
* **nfdump.profile** — profile folder (default: `live`)
* **nfdump.max-processes** — max concurrent nfdump processes (default: `1`)
* **log.priority** — syslog level constant, e.g. `LOG_INFO`

> **Note:** When `settings.php` is present, `NFSEN_SOURCES`, `NFSEN_PORTS`, `NFSEN_FILTERS`, and `NFSEN_PROCESSOR` env vars are **not** read — the file takes over entirely for those keys. A warning is logged if you set them while a settings file is present.

### Nfdump / nfcapd

> nfsen-ng expects nfcapd files organised as:
> `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
> e.g. `/var/nfdump/profiles-data/live/source1/2024/12/01/nfcapd.202412010025`

nfsen-ng reads nfcapd files produced by nfdump's capture daemon. An example nfcapd invocation:

```ini
options='-z -S 1 -T all -l /var/nfdump/profiles-data/live/<source> -p <port>'
```

* `-z` — compress nfcapd files
* `-S 1` — subdirectory structure `YYYY/MM/DD/`
* `-T all` — capture all extensions
* `-l` — output directory
* `-p` — listening port

To use **sfcapd** instead of nfcapd, update your systemd unit's `ExecStart` to point to the `sfcapd` binary with the same arguments.

If your existing files are in a **flat directory** (no subdirectory structure), `scripts/reorganize_nfcapd.sh` will migrate them into the expected `YYYY/MM/DD/` hierarchy. Edit the `SOURCE_DIR` and `TARGET_BASE` variables at the top of the script before running it.

## Import & Daemon

`app.php` manages the import daemon internally. On startup:

- **Existing data** — a gap-fill runs automatically to catch up on any nfcapd files written while the server was offline.
- **Fresh install (no data)** — startup import is skipped. Use **Admin panel → Initial Import** to trigger the first full scan.

To force a complete rescan or rebuild from scratch, use **Admin panel → Force Rescan**.

To skip the startup gap-fill entirely (e.g. shared database across containers), set `NFSEN_SKIP_INITIAL_IMPORT=true`.

### Daemon as a systemd service

Pre-built unit files are in `deploy/systemd/`. See [deploy/systemd/README.md](./deploy/systemd/README.md) for installation instructions — separate sections cover Docker and bare-metal deployments.

## Logs

nfsen-ng logs to syslog (`nfsen-ng:` prefix). Find logs with:

```bash
# System log
journalctl -t nfsen-ng -f

# Docker logs
docker compose -f deploy/docker-compose.yml logs -f nfsen
```

Adjust verbosity via `NFSEN_LOG_LEVEL` env var or `log.priority` in `settings.php`.
