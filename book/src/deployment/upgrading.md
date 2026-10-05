# Upgrading

## Upgrading from 1.0.0-beta.5

This release rebuilds the interface around a sidebar and adds an SQLite store next
to the RRD or VictoriaMetrics data. Nothing is re-imported and no setting has to
change, but bare-metal installs need one more PHP extension and a memory limit for
the server, and should run nfdump 1.7.10 (the Health page warns below it).

### Docker

Pull the new image and recreate the container. Everything the upgrade creates lands
in the state directory on the `nfsen-data` volume (`/var/lib/nfsen-ng/state`), next
to `preferences.json`. The image already contains `pdo_sqlite`, nfdump 1.7.10 (it
had 1.7.8) and the server's `memory_limit` of 512M. If the collector runs from the
same image (the Unraid template, `nfcapd` services in a compose file), recreate it
too: most of nfdump 1.7.9's security fixes are in `nfcapd`. Capture files written
by 1.7.8 read unchanged.

### Bare metal

1. Install and enable the SQLite driver:

   ```bash
   apt install php8.4-sqlite3 && phpenmod pdo_sqlite
   ```

   The app starts without it, but saved filters, the alert history and the Overview
   top-N stay unavailable until it is there, and the Health page says so. The
   SQLite library has to be 3.33 or later, or the Overview top-N never gets past
   *Collecting*.
2. Update the code and the dependencies. `composer install` brings in the new
   `maxmind-db/reader` package:

   ```bash
   git pull
   php composer.phar install --no-dev --optimize-autoloader
   ```

3. Build nfdump 1.7.10 as the [installation page](installation.md#install-the-stack)
   shows, point `nfcapd.service` at `/usr/local/nfdump/bin/nfcapd` if you run the
   source build, and restart `nfcapd`. The Health page warns below 1.7.10.
4. Give the server a memory limit: copy the new `deploy/systemd/nfsen-ng.service`,
   which starts it with `php -d memory_limit=512M`, or set `memory_limit = 512M`
   in the CLI configuration. See [PHP memory limit](configuration.md#php-memory-limit).
5. Make sure the state directory (`NFSEN_STATE_DIR`, by default `backend/settings`)
   is writable by the user the server runs as, then restart the service.

### What happens on the first start

- The SQLite store `nfsen-ng.sqlite` is created in the state directory.
- The alert history moves into it: every entry of `alerts-log.json` becomes a
  *fired* event, and the file is renamed to `alerts-log.json.migrated`. If the move
  fails, the file stays where it is and the next start tries again.
- The filter presets saved in Settings (the old *Filter presets* list in
  `preferences.json`) become saved filters, once, the first time the server reads
  the saved filters (when somebody opens the filter builder, for instance). The
  deployment presets from `NFSEN_FILTERS` or `settings.php` are added at the same
  moment, each one once; a preset you delete stays deleted.
- Each browser's own saved filters, kept by the old filter panel in the browser's
  local storage, are imported the first time that browser opens the new version.
  A failed import is retried on the next load.
- The top-N collector starts recording the Overview lists with the next import, and
  its gap filler works back through the retention window (31 days by default) from
  the capture files that still exist. Until the first interval is in, the Overview
  table says *Collecting*. **Collect missing top-N now** on the Health page queues
  up to 500 missing files right away instead of waiting for the next pass.
- Alert rules with a traffic filter and a **% of rolling average** threshold start
  collecting their own baseline: from now on they compare with the filter's own
  traffic, recorded at every check, so each has no baseline until it has checked
  one interval. Their earlier events keep their thresholds, which were per-second
  averages of all traffic, and the history now labels them as totals per five
  minutes.

### Settings whose default changed

- **`NFSEN_NFDUMP_MAX_PROCESSES`** is `auto` now, a third of the CPU cores between
  2 and 8; it was 2. A number you set keeps working. A `settings.php` copied from
  an older template has `'max-processes' => (int) (getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 1)`,
  which pins one process while the variable is unset or `0`: set it to `auto`,
  delete that line or write
  `getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 'auto'`. See
  [nfdump processes and CPU cores](configuration.md#nfdump-processes-and-cpu-cores).
- **`NFSEN_NFDUMP_WORKERS`** is new: every nfdump run gets `-W 2` instead of
  nfdump's own default, filter threads for half the host's cores in every
  process.

### What looks different

- **Bi-directional** on Top Talkers and Flows merges the two directions of a
  conversation into one row with In and Out filled, where the 1.7.8 of earlier
  images listed them as separate rows.
- Large Top Talkers, Conversations and Overview exact runs, filtered graphs and
  the top-N backfill use several nfdump processes where the limit allows; the
  query status says how many.

### What moved in the interface

| In 1.0.0-beta.5 | Now |
|--------|-----|
| Graphs | **Overview** page |
| Statistics | **Top Talkers** page |
| Flows | **Flows** page |
| Sankey | **Conversations** page |
| Alerts section of Settings | **Alerts** page |
| Import and Health sections of Settings | **Health** page |
| Preferences and System sections of Settings | **Settings** page (tabs General, Sources, Storage, Integrations, System) |
| Date slider | Controls bar (range menu, step buttons, start and end) and a drag across the traffic graph |
| Filter presets textarea, browser-local filter list | Filter builder drawer with saved filters |

Every page has its own address (`#/overview`, `#/talkers`, `#/flows`,
`#/conversations`, `#/alerts`, `#/health`, `#/settings`). A browser that last had
a tab open under the old layout opens the matching page, once. The old names work
in the address too: `#/graphs`, `#/statistics`, `#/sankey` and `#/investigate`
open the page that replaced them.

## Upgrading from v0

v1 is a ground-up rewrite. It is not code-compatible with the v0.x
(NfSen-style) releases, but it reuses the same underlying data: nfsen-ng reads
the `nfcapd` capture files nfdump already writes, so **no data migration is
needed**. Point v1 at your existing capture tree and run an import.

### What changed

#### Architecture

| v0.x | v1 |
|------|----|
| Apache/nginx + PHP-FPM (per-request) | OpenSwoole via [php-via](https://github.com/mbolli/php-via) (one persistent process) |
| REST JSON API + AJAX polling | Hypermedia over SSE ([Datastar](https://data-star.dev/)), no JSON API |
| jQuery frontend | Server-rendered Twig + Datastar signals; hash links per page, no client-side framework or build step |
| RRD only | RRD (default) or [VictoriaMetrics](victoriametrics.md), plus SQLite for saved filters, alert history and top-N data |
| No live push | inotify → SSE broadcast to every open tab |

See [Architecture: Overview](../architecture/overview.md) for how the v1 pieces
fit together.

#### Frontend

- jQuery, ion.rangeSlider, and the old REST client are gone.
- Replaced by Datastar and [Apache ECharts](https://echarts.apache.org/) for
  graphs (v1 migrated the graphs off Dygraphs).
- The server pushes full HTML re-renders over SSE and Datastar morphs the DOM.

#### Backend

- The entry point is now `backend/app.php` (the OpenSwoole server), not a web
  server document root.
- **The `cli.php` interface was removed.** Import is driven from the web UI
  (**Trigger**, **Backfill** and **Rescan** on the **Health** page) by the daemon
  embedded in `app.php`.
- Configuration moved to environment variables (`NFSEN_*`); the `settings.php`
  file still works but is now a **deprecated** overlay on top of them. If you
  keep one, start from the current `backend/settings/settings.php.dist` rather
  than reusing a v0 file verbatim (the schema was expanded and reorganised: new
  `general.db`, `db.<datasource>.*`, `frontend.defaults.*`, and more), or skip
  the file entirely and configure via environment variables. See
  [Configuration](configuration.md).

#### Docker

- v0 ran Apache inside the container; v1 runs the OpenSwoole app and fronts it
  with a **stock `caddy:latest`** container (optional, behind the `proxy`
  profile). There is no custom Caddy image.
- Deployment layout moved under `deploy/` (`docker-compose.yml`,
  `docker-compose.dev.yml`, …). See [Installation](installation.md).
- **Persistent data is consolidated** under a single `nfsen-data` volume at
  `/var/lib/nfsen-ng` (`rrd/` + `state/`). If you ran an earlier v1 beta with the
  separate `rrd-data` volume (`/var/nfsen-ng/rrd`), copy your RRD files into the
  new volume once, e.g. `docker run --rm -v rrd-data:/old -v nfsen-data:/new
  alpine cp -a /old/. /new/rrd/`, or rebuild them with **Rescan**.
  See [State & persistence](configuration.md#state--persistence).
- **Unraid template users**: the UI template gained a matching **App data** path
  (`/var/lib/nfsen-ng`, defaulting to `/mnt/user/appdata/nfsen-ng-data`) in
  v1.0.0-beta.3. An install created before that has no such mapping, so add it
  when you update the container, otherwise the RRD database, preferences, saved
  filters and alert rules are recreated empty on every image update.

### Migration steps

1. **(Optional) Back up your old RRD files**, in case you want to keep the v0
   graph history around:
   ```bash
   cp -r backend/datasources/data/ /backup/rrd-$(date +%Y%m%d)/
   ```

2. **Deploy v1.** The simplest path is the published Docker image
   (`ghcr.io/mbolli/nfsen-ng:latest`) with `deploy/docker-compose.yml`. If you
   run from source, check out a `v1.0.0-*` release tag (or the `v1` branch)
   rather than the old v0 tags. Full steps: [Installation](installation.md).

3. **Point v1 at your existing capture tree**: set `NFSEN_NFDUMP_PROFILES` (or
   `nfdump.profiles-data`) to the same `profiles-data` directory `nfcapd` already
   writes to, and list your sources in `NFSEN_SOURCES`.

4. **Review configuration.** Map any custom v0 settings onto the current keys or
   environment variables ([Configuration](configuration.md)).

5. **Build the graph data.** Run **Trigger** on the **Health** page (or
   **Rescan** on RRD, **Backfill** on VictoriaMetrics) once. This rebuilds the
   RRD/VictoriaMetrics graph data from your `nfcapd` files. The v1 RRD structure
   differs from v0's, so re-importing from the captures is the reliable path
   rather than reusing old `.rrd` files. Top Talkers, Flows and Conversations work
   directly off the capture files and need no import.

### Known differences from v0 / NfSen

- The v0 REST API endpoints (`/api/…`) no longer exist; v1 has no JSON API.
- NfSen's alert/plugin mechanisms are not carried over; v1 has its own
  [alerting](../features/alerts.md).
- Some v0 profile-filter behaviour differs; v1's profile model is
  auto-detected from the capture tree ([Profiles](profiles.md)).
