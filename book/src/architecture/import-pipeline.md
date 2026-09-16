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

## Per-port series

With `NFSEN_PORTS` set, each capture file is queried once per configured port and the result
written to that port's own database. `NFSEN_PORT_DIRECTION` decides which side of a flow
counts:

| Value | Query | Counts |
|---|---|---|
| `dst` (default) | `-s dstport:p 'dst port N'` | Flows whose destination is the port |
| `src` | `-s srcport:p 'src port N'` | Flows whose source is the port |
| `any` | `-s port:p 'port N'` | The port in either direction |

The default is what every release so far counted, and it stays the default deliberately:
widening it would step every existing port series upward against data already on disk under
the old meaning.

**Set `any` if your port graphs are empty while the per-source graphs work.** That is what an
exporter reporting one direction of each flow looks like, which ingress-only or egress-only
sampling produces: every flow names the port as its *source*, so a destination-only query
matches nothing ([#173](https://github.com/mbolli/nfsen-ng/issues/173)). A port's traffic is
arguably the conversation on it, so `any` is the truer reading, but it is opt-in because the
numbers it produces are not comparable with the ones already stored.

Under `any`, each port counts only its own rows: `-s port:p` reports both ports of every
matching flow, so a request from `10007` to `443` yields a row for each, and summing them all
would roughly double every value.

A configured port that sees no traffic in a run is named once in the import log, so a flat
graph says why instead of leaving you to guess whether collection is broken.

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
