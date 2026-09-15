# Health Checks & Admin

Two adjacent Settings sub-tabs cover operations: **Import** (manual scan
controls + daemon status) and **Health** (a structured environment/config
audit). Both are populated by `HealthChecker::run()`
(`backend/common/HealthChecker.php`), re-run on every view render with a
30-second throttle.

![Health sub-tab](../images/05-settings-health.png)

## Health check groups

| Group | Covers |
|---|---|
| PHP Extensions | PHP version, `ext-openswoole`, `ext-rrd` (only required for the RRD datasource), `ext-inotify` |
| Timezone | PHP timezone, `NFCAPD_TZ` validity, and a plausibility check comparing the most recent nfcapd filename's timestamp against "now" |
| nfdump | Binary presence/version (minimum 1.7.2 — the JSON field-name scheme changed from 1.6.x) and max-processes, showing how many of the configured slots are in use right now |
| Sources | At least one configured |
| Import Daemon | Running / initializing / watching N directories, last auto-import age |
| nfcapd Paths | `profiles-data` reachable; per-profile, per-source directory presence, flat-vs-nested layout, and capture freshness (warns past ~12 minutes — 2.4× nfcapd's default 5-minute rotation) |
| RRD Storage / VictoriaMetrics | Delegated to the active `Datasource::healthChecks()` |
| Configuration | `EnvRegistry::issues()` — env vars set but invalid (so a default quietly applied), deprecated aliases still in use, unknown `NFSEN_`-prefixed names (typos that do nothing) — plus malformed `url`/`email` values, an active (deprecated) `settings.php`, a saved log-level preference overriding `NFSEN_LOG_LEVEL`, and a `NFSEN_IPINFO_URL`/`NFSEN_IPINFO_TOKEN` pair that can't work together |

Every entry is `ok` / `warning` / `error`, sorted errors-first within its
group, with an optional hint line explaining what to actually do about it.

## nfdump concurrency

`NFSEN_NFDUMP_MAX_PROCESSES` bounds how many nfdump processes run at once, and the
**Max processes** health entry shows how many of those slots are in use right now.

The limit counts the processes nfsen-ng started, including the import daemon's, and a
caller that finds no free slot waits briefly rather than failing. It used to be enforced
by counting every process named `nfdump` on the machine with `ps` or `pgrep`, which
counted runs this app never started and silently enforced nothing when neither tool was
installed — hence the separate process-inspection warning that used to live here, now
removed along with the mechanism that needed it.

## Import (Admin)

![Import sub-tab](../images/07-settings-import.png)

**Trigger import** re-runs the catch-up scan for the selected profile;
**Force rescan** additionally resets the datasource for that profile first
(a destructive re-import, guarded by a confirmation signal). Both lock the
profile's `ImportDaemon` for the duration so the ongoing inotify poll
doesn't advance the datasource ahead of where the manual scan has reached.
