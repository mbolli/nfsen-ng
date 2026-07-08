# Upgrading from v0

v1 is a ground-up rewrite. It is not code-compatible with the v0.x
(NfSen-style) releases, but it reuses the same underlying data: nfsen-ng reads
the `nfcapd` capture files nfdump already writes, so **no data migration is
needed** — point v1 at your existing capture tree and run an import.

## What changed

### Architecture

| v0.x | v1 |
|------|----|
| Apache/nginx + PHP-FPM (per-request) | OpenSwoole via [php-via](https://github.com/mbolli/php-via) (one persistent process) |
| REST JSON API + AJAX polling | Hypermedia over SSE ([Datastar](https://data-star.dev/)) — no JSON API |
| jQuery frontend | Server-rendered Twig + Datastar signals; no client-side routing or build step |
| RRD only | RRD (default) or [VictoriaMetrics](victoriametrics.md) |
| No live push | inotify → SSE broadcast to every open tab |

See [Architecture → Overview](../architecture/overview.md) for how the v1 pieces
fit together.

### Frontend

- jQuery, ion.rangeSlider, and the old REST client are gone.
- Replaced by Datastar, [noUiSlider](https://refreshless.com/nouislider/), and
  [Apache ECharts](https://echarts.apache.org/) for graphs (v1 migrated the
  Graphs tab off Dygraphs).
- No client-side routing — the server pushes full HTML re-renders over SSE and
  Datastar morphs the DOM.

### Backend

- The entry point is now `backend/app.php` (the OpenSwoole server), not a web
  server document root.
- **The `cli.php` interface was removed.** Import is driven from the web UI
  (**Settings → Import → Trigger Import** / **Force Rescan**) by the daemon
  embedded in `app.php`.
- Settings still live in a PHP file that assigns the global `$nfsen_config`
  array, but the schema was expanded and reorganised (new `general.db`,
  `db.<datasource>.*`, `frontend.defaults.*`, and more). Start from the current
  `backend/settings/settings.php.dist` rather than reusing a v0 file verbatim —
  or skip the file entirely and configure via environment variables. See
  [Configuration](configuration.md).

### Docker

- v0 ran Apache inside the container; v1 runs the OpenSwoole app and fronts it
  with a **stock `caddy:latest`** container (optional, behind the `proxy`
  profile) — there is no custom Caddy image.
- Deployment layout moved under `deploy/` (`docker-compose.yml`,
  `docker-compose.dev.yml`, …). See [Installation](installation.md).

## Migration steps

1. **(Optional) Back up your old RRD files**, in case you want to keep the v0
   graph history around:
   ```bash
   cp -r backend/datasources/data/ /backup/rrd-$(date +%Y%m%d)/
   ```

2. **Deploy v1.** The simplest path is the published Docker image
   (`ghcr.io/mbolli/nfsen-ng:latest`) with `deploy/docker-compose.yml`. If you
   run from source, check out a `v1.0.0-*` release tag (or the `v1` branch)
   rather than the old v0 tags. Full steps: [Installation](installation.md).

3. **Point v1 at your existing capture tree** — set `NFSEN_NFDUMP_PROFILES` (or
   `nfdump.profiles-data`) to the same `profiles-data` directory `nfcapd` already
   writes to, and list your sources in `NFSEN_SOURCES`.

4. **Review configuration.** Map any custom v0 settings onto the current keys or
   environment variables ([Configuration](configuration.md)).

5. **Build the graph data.** Run **Settings → Import → Trigger Import** (or
   **Force Rescan**) once. This rebuilds the RRD/VictoriaMetrics graph data from
   your `nfcapd` files — the v1 RRD structure differs from v0's, so re-importing
   from the captures is the reliable path rather than reusing old `.rrd` files.
   Flows and Statistics work directly off the capture files and need no import.

## Known differences from v0 / NfSen

- The v0 REST API endpoints (`/api/…`) no longer exist — v1 has no JSON API.
- NfSen's alert/plugin mechanisms are not carried over; v1 has its own
  [alerting](../features/alerts.md).
- Some v0 profile-filter behaviour differs; v1's profile model is
  auto-detected from the capture tree ([Profiles](profiles.md)).
