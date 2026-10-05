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
| `get_graph_data()` | Time series for the traffic graph (by source, protocol or port) |
| `reset()` | Wipe data for a rescan |
| `date_boundaries()` / `last_update()` | First/last timestamps for a source |
| `get_data_path()` | Where this source's data physically lives |
| `healthChecks()` | Storage-specific entries on the Health page |
| `fetchLatestSlot()` / `fetchRollingAverage()` | Aggregate metrics for alert evaluation and the MCP `current_load` |

`fetchLatestSlot()` sums each source's newest stored interval and leaves out a
source more than one interval behind the newest, so a source that stopped
reporting does not pull the value down. `fetchRollingAverage($sources, $profile,
$window, $end)` averages the window that ends where the interval starting at
`$end` begins; without `$end`, the window before the newest complete interval by
the clock. Both answer in one unit: per second on RRD, per 5 minutes on
VictoriaMetrics. They are what `AlertManager` reads for a rule without a traffic
filter. A rule with a filter runs `nfdump` instead, and its percent-of-average
baseline is the average of its own recorded values, kept in the SQLite store
(see [Alerts](../features/alerts.md#baseline-of-percent-of-average-rules)).

Both datasources also implement `TotalsProvider`
(`backend/datasources/TotalsProvider.php`): `fetchTotals()` returns the stored
flows, packets and bytes of `[start, end)` summed over sources for one protocol
(`any`, `tcp`, `udp`, `icmp`, `other`), and `fetchProtocolTotals()` all five in one
pass. The window covers the capture files whose start lies inside it, the same
set an nfdump `-R` over that window reads. The Overview *Total traffic* card and
the Flows *Range totals* read them, so neither needs a capture file. Callers check
`Config::$db instanceof TotalsProvider` and show *Not available* otherwise.

## RRD (default)

One `.rrd` file per source (`Rrd::get_data_path()`), nested under a profile
subdirectory: `{data_path}/{profile}/{source}[_{port}].rrd`. A companion
`.rrd.first` sidecar file tracks the true first-write timestamp, since
`rrd_first()` isn't reliable for that. RRD trades flexibility for
simplicity: it's a single PHP extension (`ext-rrd`), no separate service to
run, and it's what classic NfSen used. For totals over long windows, `Rrd` caches
whole-day sums per file (keyed by the file's inode), so a 30-day or one-year
total is not a long `rrd_fetch` on every render.

## VictoriaMetrics

Writes Prometheus-exposition-format samples over HTTP to a VictoriaMetrics
instance (`deploy/docker-compose.victoriametrics.yml`), queried back via its
PromQL-compatible HTTP API (`query_range`, `tfirst_over_time`,
`tlast_over_time`). This trades the extra moving part for a real
time-series database: longer retention, PromQL for ad-hoc queries, and no
per-source file to manage. `VictoriaMetricsWatcher` polls VM's own health/
readiness so the Health page can report connectivity, not just config sanity.

## Profiles

Both datasources are profile-aware: `Config::detectProfiles()` scans
`nfdump.profiles-data` for source subdirectories (or nested groups of them)
and returns the list. With exactly one profile, paths/health-check ids stay
flat (`rrd_data_gw`); with more than one, everything gets profile-suffixed
(`rrd_data_live_gw`) so the two don't collide. This is how "live" vs. "test"
(or any nfdump profile split) shows up throughout the UI and health checks
without special-casing.

## What is not a datasource

The datasources hold time series only. The per-interval top-N lists, the saved
filters, the alert history and the recorded query timings live in the SQLite
store in the state directory, the same for either datasource; see
[SQLite Store](../features/sqlite-store.md).
