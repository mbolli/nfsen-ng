# Changelog

All notable changes to nfsen-ng are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- Bundled Caddy no longer serves `/frontend/*` static assets directly — everything is proxied to php-via, which already serves and Brotli-compresses static files itself. Retired the custom `caddy-cbrotli` build (`deploy/Dockerfile.caddy`, `ghcr.io/mbolli/nfsen-ng-caddy`); the bundled-Caddy profile now uses the stock `caddy:latest` image. Bare-metal Caddy configs copied from the old template continue to work unchanged; the `handle /frontend/*` block can be dropped if desired, but isn't required.
- Alert webhook payload now also includes `title`/`message`/`body` fields, so the webhook URL can point directly at a Gotify (`.../message?token=...`) or Apprise API (`.../notify/...`) endpoint without an intermediary ([#153](https://github.com/mbolli/nfsen-ng/issues/153))
- Bumped `mbolli/php-via` to `~0.11.0` and switched to its new `Config::withStaticCacheControl()`: versioned static assets (`?v=` cache-busted CSS/JS, `datastar.js`, `via.css`) now get `public, max-age=31536000, immutable`, unversioned vendor libs (`bootstrap.min.css`, `nouislider.min.js`, `echarts.min.js`) get `public, max-age=604800, must-revalidate`, and everything still gets `no-cache` in dev mode. All static responses now emit `ETag`/`Last-Modified` and honor conditional GET, courtesy of the php-via upgrade.
- `nouislider.min.js` / `echarts.min.js` are now loaded with `defer` instead of blocking the parser — found via a Lighthouse pass while tuning cache headers; nothing consumes them synchronously (only later `type="module"` components do, and those already tolerate late availability).

### Fixed

- Alert rules with an nfdump traffic filter always evaluated to zero, so they could never fire — `AlertManager::fetchFilteredSlot()` set nfdump's `-R` (time range) option before `-M` (sources), but `-R` resolves file paths immediately using the sources `-M` records, so it always found zero nfcapd files ([#153](https://github.com/mbolli/nfsen-ng/issues/153))

### Security

- `withStaticDir()` was pointed at the project root instead of `frontend/`, and php-via's static-file handler has no extension allowlist — it served any file under that root over plain HTTP, including `composer.json`, every `backend/**/*.php` source file, `backend/settings/settings.php`, and the entire `.git/` directory (i.e. the full commit history, dumpable with any standard git-dumper tool). Scoped `withStaticDir()` to `frontend/` only and dropped the now-redundant `frontend/` prefix from asset URLs in `layout.html.twig` to match.

---

## [1.0.0-beta.1] — 2026-07-07

### Added

- Sankey diagram tab visualizing src→dst traffic flow ([#152](https://github.com/mbolli/nfsen-ng/issues/152))
- `DEMO_MODE` with a synthetic live-data generator (`DemoDaemon`) for running the UI without a real capture pipeline
- `displayTimezone` user preference (browser vs. capture timezone), applied across chart/date-range formatting and alert emails
- Custom duration input (numeric + h/d/w unit) alongside the date-range preset buttons ([#148](https://github.com/mbolli/nfsen-ng/issues/148))
- Optional nfdump traffic filter on alert rules
- Health check: surface missing `ps`/`pgrep` as a warning
- Animated tab switching via the View Transitions API
- mdBook documentation site, published to GitHub Pages on every push to `book/`
- End-to-end browser test suite (`tests/e2e/`): drives a real headless Chrome over raw CDP (no Playwright/Puppeteer dependency) against every tab — smoke, graphs, flows, statistics, sankey, settings, alerts — plus a `npm run test-e2e` runner

### Changed

- Graphs tab migrated from Dygraphs to Apache ECharts
- Frontend linting/formatting migrated from ESLint+Prettier to Biome
- `php-via` upgraded to `~0.10.0`, resolving 15 Dependabot alerts
- Settings and Admin tabs merged into a single Settings tab with vertical sub-navigation
- Dark mode migrated from a `filter:invert` hack to Bootstrap 5.3's `data-bs-theme`
- Settings/alert/graph-reconnect toast notifications consolidated to a single bottom-right container

### Fixed

- Table column visibility and sort state now persist across SSE re-renders, not just full page reloads ([#151](https://github.com/mbolli/nfsen-ng/issues/151))
- Import daemon catches up nfcapd files written before its inotify watch was registered, e.g. the first slot(s) after a midnight day-directory rollover ([#146](https://github.com/mbolli/nfsen-ng/issues/146))
- Graph `_error` signal now clears on a subsequent successful load instead of staying stuck
- nfcapd filenames are parsed in `NFCAPD_TZ` instead of always UTC
- Alert emails use `gmdate()`; added timezone-related health checks
- Alerts Delete/Enable/Test-fire buttons were a silent no-op: `@post(url, {id: '...'})` passes its second argument as Datastar request *options*, not a body payload, so `id` never reached `$c->input('id')` — switched to a query-string id, matching the working pattern used elsewhere in the app
- ECharts race condition: rapid datatype switching could call `setOption` on an already-disposed chart instance and crash
- `procps` installed in the test image; stale `Rrd`/`Settings`/`VictoriaMetrics` test fixtures repaired
- Graphs zoom-slider fill color was an off-palette gray, reading too dark against the light theme

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

[Unreleased]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-RC.1...v1.0.0-beta.1
[1.0.0-RC.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0-alpha...v1.0.0-RC.1
[0.4.0]: https://github.com/mbolli/nfsen-ng/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/mbolli/nfsen-ng/compare/v0.3...v0.3.1
[0.3]: https://github.com/mbolli/nfsen-ng/compare/v0.2...v0.3
[0.2]: https://github.com/mbolli/nfsen-ng/compare/v0.1...v0.2
[0.1]: https://github.com/mbolli/nfsen-ng/releases/tag/v0.1
