# Import Pipeline

nfsen-ng never talks to nfcapd directly — it only reads the rotated
`nfcapd.YYYYMMDDHHMM` files nfcapd writes to
`{profiles-data}/{profile}/{source}/YYYY/MM/DD/`. Everything from there is
`backend/common/ImportDaemon.php` and `backend/common/Import.php`, wired up
once at boot in `AppStartup.php`.

## Two passes

1. **Catch-up import**, run once per profile at process start
   (`ImportDaemon: running initial import (last N years)`). Scans
   `nfdump.importYears()` worth of history, writing anything not already
   reflected in the datasource. This is what makes a freshly (re)started
   server come up already showing historical graphs instead of an empty
   dashboard.
2. **Ongoing inotify watch**, registered per source directory
   (`inotify_add_watch(..., IN_CREATE | IN_MOVED_TO)`) and polled every
   second by an OpenSwoole `setInterval` timer (`AppStartup::pollOnce()`).
   nfcapd rotates a fresh file roughly every 5 minutes by default; each new
   file triggers:
   - the file's counters written into the active datasource,
   - `$app->broadcast('rrd:live')` — every open tab's graph updates without
     a reload,
   - `AlertManager::runPeriodic()` for that profile (see
     [Alerts](../features/alerts.md)).

## Why polling, not a blocking inotify read

OpenSwoole coroutines cooperate; a blocking `inotify_read()` call inside a
long-running coroutine would stall the whole worker. Polling on a 1-second
timer keeps the check non-blocking and cheap (`inotify_read` with no events
pending returns immediately).

## Manual controls

The Admin sub-tab (`Settings → Import`) exposes **Force rescan** and
**Trigger import**, both of which lock the profile's `ImportDaemon`
(`isLocked()`) for the duration so the ongoing inotify poll doesn't race a
manual scan and advance the datasource's last-update watermark ahead of
where the manual scan has actually reached.

## Environment caveat

Cross-container `inotify` (nfcapd writing into a bind-mounted directory that
a *different* container watches) doesn't reliably propagate on every host —
notably WSL2. If the ongoing-watch path never seems to fire in a dev
environment, that's the first thing to check, not the daemon code itself.
