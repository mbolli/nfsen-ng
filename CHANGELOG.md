# Changelog

All notable changes to nfsen-ng are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

---

## [1.0.0-RC.1] — 2026-05-04

Full architectural rewrite milestone. Server-rendered hypermedia replaces the jQuery/JSON-API stack. The new stack runs as a long-lived OpenSwoole coroutine server, pushing UI updates over SSE without page reloads.

### Added

- **Hypermedia architecture**: [Datastar](https://data-star.dev) SSE, server-rendered Twig templates, no JSON API
- **OpenSwoole coroutine server** via [php-via](https://github.com/mbolli/php-via) replacing Apache/PHP-FPM
- **Caddy reverse proxy**: TLS, HTTP/3, Brotli/zstd/gzip (`deploy/Dockerfile.caddy`, `deploy/Caddyfile`)
- **Real-time graph updates**: inotify watching nfcapd files triggers `rrd:live` broadcast
- dygraphs with zoom + noUiSlider date range slider + quick time-range presets (1 h, 6 h, 1 d, 1 w, 1 m, 1 y)
- Multi-profile UI: profile selector, per-profile datasource storage, auto-detect nfdump profiles
- Alert rules: `AlertRule`/`AlertState` models, `AlertManager` service, UI panel with recent alert history
- Alert notifications: toast overlay, email and webhook delivery, SSE-pushed via `execScript`
- User preferences UI — Settings tab with live save to `preferences.json`
- Filter manager component showing global filters from deployment config
- Netbox IP lookup for private addresses, configurable via `NFSEN_NETBOX_URL`/`NFSEN_NETBOX_TOKEN` ([#112](https://github.com/mbolli/nfsen-ng/issues/112))
- Kill running nfdump process from the UI ([#7](https://github.com/mbolli/nfsen-ng/issues/7))
- Byte threshold filters (lower/upper) for Flows and Statistics tabs ([#8](https://github.com/mbolli/nfsen-ng/issues/8))
- Cap maximum query time window for Flows and Statistics (`NFSEN_MAX_STATS_WINDOW`) ([#6](https://github.com/mbolli/nfsen-ng/issues/6))
- NSEL/NAT support for statistics and table formatting ([#87](https://github.com/mbolli/nfsen-ng/issues/87))
- Graph zoom auto-sync with noUiSlider date range ([#3](https://github.com/mbolli/nfsen-ng/issues/3))
- Date-range slider bound to actual RRD data range
- Service name shown alongside port numbers in flow/stats tables
- Skip auto-import on fresh install; gap-fill on daemon restart
- Admin: scan ports checkbox for import and rescan operations
- VictoriaMetrics datasource as an alternative to RRD (`deploy/docker-compose.victoriametrics.yml`)
- VictoriaMetrics UI link displayed in health check panel
- Health check panel (Admin tab): nfdump binary/version, nfcapd paths, RRD/VM storage, daemon status
- `ImportDaemon` embedded in `app.php`; runs as an inotify-based coroutine loop in `onStart`
- Configurable RRD retention depth (`NFSEN_IMPORT_YEARS`, default 3)
- Seed scripts for RRD and VictoriaMetrics with synthetic port data (`scripts/seed_rrd_data.php`, `scripts/seed_vm_data.php`)
- Serve frontend static assets directly from php-via (no Caddy required for standalone use)
- Docker images published to GHCR: `ghcr.io/mbolli/nfsen-ng` and `ghcr.io/mbolli/nfsen-ng-caddy`
- PHPUnit/Pest test infrastructure with unit tests for `Rrd`, `VictoriaMetrics`, `AlertManager`, `Table`, `Nfdump`, and more

### Changed

- PHP minimum version raised to 8.4; PHP 8.4 asymmetric visibility applied throughout
- `Config::$cfg` array replaced by typed `Settings` class
- `nfcapd.service`: use `-z=lz4` flag (nfdump 1.7.x+)
- Bump nfdump build version 1.7.6 → 1.7.8; minimum version health check ≥ 1.7.2
- Upgrade php-via to `~0.8.0` (auto-inject, `getSignal`/`getAction` refactor)
- Split `app.php` actions into PSR-4 static action classes under `backend/actions/`
- Refactor `deploy/Dockerfile`: `COPY` source from build context instead of cloning git at build time
- Consolidate all Docker files into `deploy/`; `systemd/` → `deploy/systemd/`
- Bootstrap 3.3.7 → 5.3.2; dark-mode support; FooTable/jQuery/ion.RangeSlider removed
- PHPStan level raised to 5; all warnings resolved

### Fixed

- VictoriaMetrics `last_update()` and `date_boundaries()` stale-data lookup
- Cancel import: forward `shouldCancel` flag through all import paths
- `exec`-prefix `proc_open` so `SIGTERM` kills nfdump directly
- Profile propagation through the entire import/nfdump call stack
- Merge `settings.php` filters with `preferences.json` filters on startup ([#4](https://github.com/mbolli/nfsen-ng/issues/4))
- Replace 5-minute-slot loop with day-directory scan in `convert_date_to_path` (fixes silent failures on large date ranges)
- Config system correctness; remove ghost environment variables
- Graph filters layout and filter manager heading alignment

### Removed

- Apache/PHP-FPM stack; `.htaccess`; `backend/index.php`; `backend/listen.php`; `backend/cli.php`
- Old jQuery-based `frontend/index.html` and `frontend/js/nfsen-ng.js`
- Unimplemented `Akumuli` datasource
- Legacy `backend/server.php`, `routes/`, `scripts/start.sh`

---

## [0.4.0] — 2025-09-29

### Added

- Docker setup with `Dockerfile` and `docker-compose.yml` ([@MrAriaNet](https://github.com/MrAriaNet), [#137](https://github.com/mbolli/nfsen-ng/pull/137))
- Dark mode support ([@gmt4](https://github.com/gmt4), [#117](https://github.com/mbolli/nfsen-ng/pull/117))
- Persistent config/localStorage filters ([@gmt4](https://github.com/gmt4), [#119](https://github.com/mbolli/nfsen-ng/pull/119))
- Link formatter for src/dst IPs in Statistics view ([@gmt4](https://github.com/gmt4), [#120](https://github.com/mbolli/nfsen-ng/pull/120))
- Custom Output Format option ([@kw4tts](https://github.com/kw4tts), [#123](https://github.com/mbolli/nfsen-ng/pull/123))
- README overhaul ([@FontouraAbreu](https://github.com/FontouraAbreu), [#106](https://github.com/mbolli/nfsen-ng/pull/106))
- Updated install instructions for Debian/Ubuntu 24.04 ([@Dona21](https://github.com/Dona21))
- Updated dev dependencies: PHPStan 1.9 → 2.1, Rector 0.x → 2.x

### Fixed

- Reload loop when `frontend.defaults.view` not set ([#102](https://github.com/mbolli/nfsen-ng/issues/102))
- nfdump `dst_port=80` output format ([#115](https://github.com/mbolli/nfsen-ng/issues/115))
- `host` command returning multiple lines ([#132](https://github.com/mbolli/nfsen-ng/issues/132))
- Various code style fixes

---

## [0.3.1] — 2024-03-13

### Fixed

- Timezone offset in `Api.php` was in seconds, not hours ([@BobRyan530](https://github.com/BobRyan530), [#105](https://github.com/mbolli/nfsen-ng/issues/105))
- Submit button loading indefinitely when daemon was running ([#104](https://github.com/mbolli/nfsen-ng/issues/104))

---

## [0.3] — 2024-02-28

### Added

- PHP 8.1 minimum (8.3 recommended); PSR-4 autoloader
- Reverse DNS and WHOIS lookup on IP address click ([#84](https://github.com/mbolli/nfsen-ng/issues/84))
- Bits/bytes toggle in traffic mode ([#21](https://github.com/mbolli/nfsen-ng/issues/21))
- Human-readable byte numbers with KMG/KMB units
- Execution time display and thousand-separator formatting
- Client timezone support ([#68](https://github.com/mbolli/nfsen-ng/issues/68))
- 1-hour quick time button ([@jp-asdf](https://github.com/jp-asdf))
- nfdump column name display ([@jp-asdf](https://github.com/jp-asdf))
- PHP CS Fixer, PHPStan, Psalm static-analysis tooling

### Changed

- Bootstrap 3.3.7 → 5.3.2; FooTable updated for Bootstrap 5
- dygraphs 2.1.0 → 2.2.1

### Fixed

- `preg_match` null warning ([#88](https://github.com/mbolli/nfsen-ng/issues/88))
- Class not found error ([#96](https://github.com/mbolli/nfsen-ng/issues/96))
- Source/destination port not visible ([#48](https://github.com/mbolli/nfsen-ng/issues/48))
- Custom output format ([#49](https://github.com/mbolli/nfsen-ng/issues/49))
- Load defaults from config ([#54](https://github.com/mbolli/nfsen-ng/issues/54))
- Subnet field defaults ([#31](https://github.com/mbolli/nfsen-ng/issues/31), [#55](https://github.com/mbolli/nfsen-ng/issues/55))
- Hide aggregation on statistics view ([#56](https://github.com/mbolli/nfsen-ng/issues/56))
- Zoom to data point on click ([#53](https://github.com/mbolli/nfsen-ng/issues/53))
- `TERM` not set in systemd service ([@dominiquefournier](https://github.com/dominiquefournier), [#71](https://github.com/mbolli/nfsen-ng/issues/71))
- Font size overlap ([@dehnli](https://github.com/dehnli), [#97](https://github.com/mbolli/nfsen-ng/issues/97))
- Drop `full IPv6` nfdump flag ([#62](https://github.com/mbolli/nfsen-ng/issues/62))

---

## [0.2] — 2020-05-08

### Added

- Step plot option ([@nrensen](https://github.com/nrensen))
- Processor interface ([@nrensen](https://github.com/nrensen))
- CentOS 7 install instructions ([@Dona21](https://github.com/Dona21))
- nfcapd folder structure documentation
- Support output format for record stats ([@nrensen](https://github.com/nrensen))

### Changed

- Remove "auto" output format ([@nrensen](https://github.com/nrensen))
- Better stats dropdown titles ([@nrensen](https://github.com/nrensen))
- PHP binary via `/usr/bin/env` ([@panaceya](https://github.com/panaceya))
- jQuery 3.2.1 → 3.5.0; Ion.RangeSlider 2.1.7 → 2.2.0; FooTable 3.1.4 → 3.1.6; dygraphs 2.0.0 → 2.1.0

### Fixed

- Log error if RRD database could not be created
- PHP 7.4 deprecation warnings
- Broken port-specific data import
- CLI can run from any working directory
- Passing empty arguments to nfdump
- Missing `use` declaration ([@luizgb](https://github.com/luizgb))
- Flows/stats CSV output row count off by one ([@nrensen](https://github.com/nrensen))

---

## [0.1] — 2018-11-02

Initial release. See the [wiki comparison page](https://github.com/mbolli/nfsen-ng/wiki/Comparison) for a feature comparison with the original NfSen.

[Unreleased]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-RC.1...HEAD
[1.0.0-RC.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0-alpha...v1.0.0-RC.1
[0.4.0]: https://github.com/mbolli/nfsen-ng/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/mbolli/nfsen-ng/compare/v0.3...v0.3.1
[0.3]: https://github.com/mbolli/nfsen-ng/compare/v0.2...v0.3
[0.2]: https://github.com/mbolli/nfsen-ng/compare/v0.1...v0.2
[0.1]: https://github.com/mbolli/nfsen-ng/releases/tag/v0.1
