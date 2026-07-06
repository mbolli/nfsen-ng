# Data Sources: RRD vs. VictoriaMetrics

Storage is pluggable behind one interface, `Datasource`
(`backend/datasources/Datasource.php`), selected by `general.db` in config
(`RRD` or `VictoriaMetrics`) via `Settings::datasourceClass()`. Both
implementations live in `backend/datasources/`.

## The contract

Every datasource implements:

| Method | Used for |
|---|---|
| `write()` | Persist one source's per-5-minute-slot counters after import |
| `get_graph_data()` | Time-series for the Graphs tab (by source/protocol/port) |
| `reset()` | Wipe data for a rescan |
| `date_boundaries()` / `last_update()` | First/last timestamps for a source |
| `get_data_path()` | Where this source's data physically lives |
| `healthChecks()` | Storage-specific entries in the Admin health panel |
| `fetchLatestSlot()` / `fetchRollingAverage()` | Aggregate metrics for alert evaluation |

`fetchLatestSlot()`/`fetchRollingAverage()` are what `AlertManager` reads by
default; an alert rule with a traffic filter bypasses them entirely and runs
`nfdump` directly instead (see [Alerts](../features/alerts.md)).

## RRD (default)

One `.rrd` file per source (`Rrd::get_data_path()`), nested under a profile
subdirectory: `{data_path}/{profile}/{source}[_{port}].rrd`. A companion
`.rrd.first` sidecar file tracks the true first-write timestamp, since
`rrd_first()` isn't reliable for that. RRD trades flexibility for
simplicity — it's a single PHP extension (`ext-rrd`), no separate service to
run, and it's what classic NfSen used.

## VictoriaMetrics

Writes Prometheus-exposition-format samples over HTTP to a VictoriaMetrics
instance (`deploy/docker-compose.victoriametrics.yml`), queried back via its
PromQL-compatible HTTP API (`query_range`, `tfirst_over_time`,
`tlast_over_time`). This trades the extra moving part for a real
time-series database: longer retention, PromQL for ad-hoc queries, and no
per-source file to manage. `VictoriaMetricsWatcher` polls VM's own health/
readiness so the Admin health panel can report connectivity, not just
config sanity.

## Profiles

Both datasources are profile-aware: `Config::detectProfiles()` scans
`nfdump.profiles-data` for source subdirectories (or nested groups of them)
and returns the list. With exactly one profile, paths/health-check ids stay
flat (`rrd_data_gw`); with more than one, everything gets profile-suffixed
(`rrd_data_live_gw`) so the two don't collide. This is how "live" vs. "test"
(or any nfdump profile split) shows up throughout the UI and health checks
without special-casing.
