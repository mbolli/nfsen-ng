# Changelog

All notable changes to nfsen-ng are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0-beta.3] — unreleased

### Added

- Environment-variable registry with Health-page validation. Every environment variable is now defined once in a single source of truth (`EnvRegistry` / `EnvVar`) — one typed parse, validator, and default apiece — that all consumers (`Settings`, the `app.php` bootstrap, `Config`, the import daemon) resolve through, replacing scattered `getenv() ?: default` reads and the per-code-path default drift they had accumulated. A new **Configuration** group on Settings → Health surfaces misconfiguration that previously failed silently: values set but invalid (so a default quietly applied), deprecated variable names still in use, unknown `NFSEN_`-prefixed variables (typos that do nothing), malformed NetBox URL / alert-email addresses, an active (deprecated) `settings.php`, and a saved preference overriding `NFSEN_LOG_LEVEL`.
- Consolidated, upgrade-safe app data via `NFSEN_STATE_DIR`. nfsen-ng's own mutable data — the RRD database plus `preferences.json` and the alert rules/state/log — now lives under one directory (`/var/lib/nfsen-ng`, split into `rrd/` and `state/`), mounted as a single `nfsen-data` volume and declared `VOLUME` in the image so even a bare `docker run` persists it. This closes a silent data-loss-on-upgrade hole: the standard compose persisted neither the RRD database nor the saved preferences/alerts, so recreating the container (every image update) wiped them. The nfcapd capture tree stays on its own separate volume — the collector owns it and it grows with traffic — so a Docker deployment now uses two clearly-separated volumes.
- `NFSEN_DEFAULT_THEME` (`auto`|`dark`|`light`) sets a deployment-level default for the dark-mode toggle, seeding a fresh browser that has no saved choice yet (e.g. after a cache wipe) instead of always falling back to the OS `prefers-color-scheme`. An explicit user toggle still persists client-side and wins over it. Also settable as `frontend.defaults.theme`.

### Changed

- `settings.php` is deprecated in favour of environment variables. When present it now acts as an overlay on the env baseline — a key it defines wins, a key it omits falls back to the matching `NFSEN_*` variable, then the built-in default — instead of the previous all-or-nothing behaviour where a settings file silently caused `NFSEN_SOURCES`/`PORTS`/`FILTERS`/`PROCESSOR` to be ignored. An active file is flagged on the Health page, and the deployment docs were reworked around the env-first model.
- `VM_HOST` / `VM_PORT` renamed to `NFSEN_VM_HOST` / `NFSEN_VM_PORT` for prefix consistency with every other variable. The old names keep working as deprecated aliases (with a Health-page nudge to rename); compose files, the VictoriaMetrics seed script, and docs updated.
- Docker volumes consolidated: the separate `rrd-data` volume folded into the single `nfsen-data` volume alongside app state (see Added). Deployments upgrading from an earlier beta's `rrd-data` volume need a one-time copy of their RRD files into `nfsen-data/rrd` (or a Force Rescan) — documented in the upgrade guide.
- `preferences.json` is no longer tracked in git — it is runtime user state, like the already-ignored `settings.php` / `alerts-*.json`, and the committed dev copy (DEBUG log level, test alert rules) had been leaking into from-source installs. It is recreated on first save.
- `NFSEN_DATASOURCE` / `NFSEN_PROCESSOR` accepted-value lists now derive from the internal datasource/processor class maps rather than a duplicated hard-coded list, so the two can't drift.
- Documentation moved from the GitHub wiki into the in-repo mdBook (`book/`), so the operational guides version and ship with the code.
- Dev/packaging housekeeping: added `deploy/unraid/ca_profile.xml` for the Unraid Community Applications submission, renamed `phpunit.xml` to `phpunit.xml.dist`, and tidied stale env-var comments in the compose files.

### Fixed

- nfdump binary default drift: the settings-file path defaulted `nfdump.binary` to `/usr/bin/nfdump` while the env path and the Docker images used `/usr/local/nfdump/bin/nfdump`. Both now use the latter, so a `settings.php` that omits the key resolves the same binary as an env-only deployment.
- Reloaded graphs could render light on a dark page ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). On a full reload the initial SSE sync morphs `<html>`; the server markup carries only the `data-attr:data-bs-theme` directive (never a resolved value, since `_darkMode` is client-local), so idiomorph stripped the resolved `data-bs-theme` on every morph and Datastar re-added it a tick later — and an async chart rebuild (`notMerge`) landing in that null window read "no theme" as light and won the race against the dark correction. The attribute is now preserved across morphs.
- Flow/statistics/Sankey results were blanked when a backgrounded tab's context was revived ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). php-via 0.12.0 context revival re-runs the page handler on SSE reconnect, re-initializing the plain-PHP result containers to empty without re-running the action that filled them — so a returning tab kept its filters and active tab but showed an empty results panel. Each context's rendered results are now snapshotted in app-global state keyed by context id and restored on revival; a genuine reload gets a fresh id and starts clean.

---

## [1.0.0-beta.2] — 2026-07-08

### Added

- Sankey diagram: optional **Ports** toggle that inserts the destination L4 port as a middle column, turning the src→dst view into src IP → dst port → dst IP ([#152](https://github.com/mbolli/nfsen-ng/issues/152)). When on, the nfdump aggregation key gains `dstport` (`-A srcip,dstport,dstip`) and the payload becomes three columns; the shared middle `port:` nodes pool all traffic per port, with src→port and port→dst ribbons summed so a busy port renders as one node rather than a stack of duplicates.
- Unraid packaging under `deploy/unraid/`: an all-in-one `docker-compose.unraid.yml` (the web UI plus an `nfcapd` collector sharing one appdata volume — both roles run the single published image) for the Compose Manager plugin, and two Community Applications templates (`nfsen-ng.xml` for the UI, `nfsen-ng-nfcapd.xml` for the collector) with a Sankey-derived icon. The README covers the shared-volume/source-name rule, the flow-exporter prerequisite, and CA submission. Nothing new to build — nfdump, nfcapd and the app already ship in the one image.

### Changed

- Sankey diagram: destination-port labels now sit as a small centered chip on each port node instead of floating above it ([#152](https://github.com/mbolli/nfsen-ng/issues/152)). A top-anchored label detached from the bulk of a thick flow, making the port hard to associate with its ribbons; the label is now vertically centered on the node bar (position `inside`) and backed by a rounded chip so a short port number stays legible where it crosses ribbons on both sides. Only the middle `port:` column changed — src/dst labels are unchanged.
- Docker `:latest` now tracks the newest release regardless of pre-release status — while pre-1.0 that means it follows the current beta/RC instead of only being published on a final stable tag ([`docker-publish.yml`](.github/workflows/docker-publish.yml)). The reduced `:1` / `:1.0` semver tags stay reserved for stable releases (`docker/metadata-action` suppresses them on pre-releases), and `:edge` still tracks `master`. Digest-based update checks (Unraid's Docker page, Watchtower, etc.) now flag each new release for anyone tracking `:latest`.
- Bundled Caddy no longer serves `/frontend/*` static assets directly — everything is proxied to php-via, which already serves and Brotli-compresses static files itself. Retired the custom `caddy-cbrotli` build (`deploy/Dockerfile.caddy`, `ghcr.io/mbolli/nfsen-ng-caddy`); the bundled-Caddy profile now uses the stock `caddy:latest` image. Bare-metal Caddy configs copied from the old template continue to work unchanged; the `handle /frontend/*` block can be dropped if desired, but isn't required.
- Alert webhook payload now also includes `title`/`message`/`body` fields, so the webhook URL can point directly at a Gotify (`.../message?token=...`) or Apprise API (`.../notify/...`) endpoint without an intermediary ([#153](https://github.com/mbolli/nfsen-ng/issues/153))
- Bumped `mbolli/php-via` to `~0.12.0` and switched to its new `Config::withStaticCacheControl()`: versioned static assets (`?v=` cache-busted CSS/JS, `datastar.js`, `via.css`) now get `public, max-age=31536000, immutable`, unversioned vendor libs (`bootstrap.min.css`, `nouislider.min.js`, `echarts.min.js`) get `public, max-age=604800, must-revalidate`, and everything still gets `no-cache` in dev mode. All static responses now emit `ETag`/`Last-Modified` and honor conditional GET, courtesy of the php-via upgrade.
- `nouislider.min.js` / `echarts.min.js` are now loaded with `defer` instead of blocking the parser — found via a Lighthouse pass while tuning cache headers; nothing consumes them synchronously (only later `type="module"` components do, and those already tolerate late availability).

### Fixed

- The Flows tab ignored the source selector — picking a single source still queried every configured source ([#155](https://github.com/mbolli/nfsen-ng/issues/155)). The Flows source `<select>` binds to the `graph_sources` signal, but `FlowActions` passed `Config::$settings->sources` (all sources) to nfdump's `-M` unconditionally instead of the selection. It now resolves the selected sources the same way the Statistics tab already did — honouring the "any"/empty = all-sources fallback — via a shared `Helpers::resolveSources()` (also adopted by the Statistics and count-files actions to remove the duplicated logic).
- The traffic graph's most recent data point always rendered as 0, one slot behind the real latest value ([#154](https://github.com/mbolli/nfsen-ng/issues/154)). RRDtool always returns one trailing empty (NaN) row past the last written slot; `Rrd::get_graph_data()` set that NaN to `null` and then, for the bits/traffic graph, unconditionally ran the `bytes → bits` conversion `$measure *= 8` over it — and PHP evaluates `null * 8` as `0`, so the empty gap became a real zero and the line dropped to the baseline at the right edge. Only the bits traffic graph was affected (flows/packets never multiply, so their gaps stayed `null`). The `*= 8` conversion now runs only on valid measures.
- Alert rules with an nfdump traffic filter always evaluated to zero, so they could never fire — `AlertManager::fetchFilteredSlot()` set nfdump's `-R` (time range) option before `-M` (sources), but `-R` resolves file paths immediately using the sources `-M` records, so it always found zero nfcapd files ([#153](https://github.com/mbolli/nfsen-ng/issues/153))
- The Flows/Statistics/Sankey NFDUMP filter (and other free-text fields) could be silently cleared by an unrelated SSE re-render while the user was still typing ([#151](https://github.com/mbolli/nfsen-ng/issues/151)) — the vendored Datastar bundle predated the `v1.0.2` release's fix for exactly this case (a morph compared a `<textarea>`'s live value against the incoming server render instead of against its own `defaultValue`, so any not-yet-submitted edit lost to the next full-page patch). Upgraded the pin from an untagged commit to `v1.0.2` and added an import map (`frontend/js/components/datastar-persist.js` resolves `datastar` via a bare specifier) so a second, independent copy of the engine can't get loaded by a differently-cache-busted relative import.
- A forced page reload (e.g. when the SSE context's cleanup grace period elapses while a tab is backgrounded) always reset the active tab to Graphs and dark mode to the system default ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). Added a `data-persist` Datastar attribute plugin that round-trips the current tab, settings sub-section, dark-mode choice, and graph display preferences (log scale, stacked/line, step/curve plot, follow-zoom) through `localStorage`, so a forced reload restores them instead of resetting to defaults.
- The tab/theme persistence fix above still didn't reach already-visited browsers, because the `?v=` cache-busting query string on `frontend/js`/`frontend/css` URLs used `Config::VERSION`, which is only hand-bumped on `release:` commits — a mid-release JS/CSS fix reuses the same URL as before, so it stays invisible to any browser that had already cached the old content under `public, max-age=31536000, immutable` ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). Added `Config::assetVersion()`, derived from the newest mtime under `frontend/js` and `frontend/css`, and switched all `?v=` asset URLs in `layout.html.twig` to it — any change to those files now busts the cache automatically, without relying on remembering to bump `VERSION`.
- The `data-persist` plugin from the fix above never actually loaded in any browser, cache notwithstanding ([#151](https://github.com/mbolli/nfsen-ng/issues/151)): the `<script type="importmap">` mapping the bare `datastar` specifier came after two earlier `<script type="module">` blocks (`alert-template-preview.js`, plus two inline ones). Per the import maps spec, an import map is only honoured if no module script — inline or external — has been "prepared" yet, which happens the moment the parser reaches its tag, not when it runs. Any earlier module script silently voids the import map, so `datastar-persist.js`'s `import ... from 'datastar'` failed to resolve and the module (and everything it was supposed to persist) never loaded — consistently, independent of cache state or browser, which is why clearing the cache didn't help. Moved the import map to be the first `type="module"`-adjacent tag in the document.
- A backgrounded tab whose SSE context was torn down after the cleanup grace period no longer hard-reloads (and loses live state) when it returns ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). php-via `0.12.0` adds *context revival*: on an SSE reconnect to a destroyed context it rebuilds an equivalent context server-side — same ID, so the already-loaded DOM's signal/action references keep resolving; page handler re-run; client-held signal values re-seeded from the reconnect — instead of pushing `window.location.reload()`. That preserves the browser's entire live state (active tab, dark mode, scroll, focus, and in-progress unsubmitted filter text), with no reload flash, for any return within the 10-minute revival window (on by default). The `data-persist` localStorage restore above stays as the fallback for a genuine user-initiated reload, which always wipes the client store and never triggers revival.

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

[1.0.0-beta.2]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-RC.1...v1.0.0-beta.1
[1.0.0-RC.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0-alpha...v1.0.0-RC.1
[0.4.0]: https://github.com/mbolli/nfsen-ng/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/mbolli/nfsen-ng/compare/v0.3...v0.3.1
[0.3]: https://github.com/mbolli/nfsen-ng/compare/v0.2...v0.3
[0.2]: https://github.com/mbolli/nfsen-ng/compare/v0.1...v0.2
[0.1]: https://github.com/mbolli/nfsen-ng/releases/tag/v0.1
