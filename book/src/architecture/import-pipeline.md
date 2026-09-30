# Import Pipeline

nfsen-ng never talks to nfcapd directly. It only reads the rotated
`nfcapd.YYYYMMDDHHMM` files nfcapd writes to
`{profiles-data}/{profile}/{source}/YYYY/MM/DD/`. Everything from there is
`backend/common/ImportDaemon.php` and `backend/common/Import.php`, wired up once
at boot in `AppStartup.php`.

## Two passes

1. **Catch-up import**, run once per profile at process start
   (`ImportDaemon: running initial import (last N years)`). It scans
   `nfdump.importYears()` worth of history and writes anything not already
   reflected in the datasource, so a freshly (re)started server shows its history
   instead of an empty graph. A profile with no data yet is skipped until its
   first manual **Trigger**.
2. **Ongoing inotify watch**, registered per source directory
   (`inotify_add_watch(..., IN_CREATE | IN_MOVED_TO)`) and polled every second by
   an OpenSwoole `setInterval` timer that `AppStartup::boot()` sets up
   (`ImportDaemon::pollOnce()`). nfcapd rotates a fresh file every five minutes by
   default; each new file:
   - is summarised with `nfdump -I` (flows, packets and bytes, per protocol) and
     written into the active datasource, and with `NFSEN_PORTS` also queried once
     per port (see [Per-port series](#per-port-series)),
   - is recorded in `ImportStats` (for the rate on the Health page),
   - is queued for the [top-N collector](#top-n-collection), which gets the `-I`
     totals along so it does not run `-I` again,
   - triggers `$app->broadcast('rrd:live')`, so every open tab redraws its graph
     without a reload,
   - is reported to `AlertManager::onFileImported()`, which evaluates the rules
     once per interval (see [Alert evaluation](#alert-evaluation)).

OpenSwoole coroutines cooperate, so a blocking `inotify_read()` inside a
long-running coroutine would stall the whole worker. The watch is polled on a
one-second timer instead; `inotify_read` with no events pending returns
immediately.

## Manual controls

The **Import** card on the **Health** page exposes **Trigger** (`trigger-import`),
**Backfill** (`backfill-import`, VictoriaMetrics) and **Rescan** (`force-rescan`,
RRD). All three lock the profile's `ImportDaemon` (`isLocked()`) for their
duration, so the ongoing inotify poll doesn't race a manual pass and advance the
datasource's last-update watermark ahead of where the manual pass has reached.

A normal pass skips every file at or before that watermark. **Backfill** does not:
it offers every capture file to the datasource, which writes each sample at the
timestamp it belongs to, so an archive older than the install is filled in.
**Rescan** resets the profile's datasource first and then imports everything.
Progress is broadcast on `admin:import`, and `cancel-import` stops a pass. The
progress broadcasts, and the `rrd:live` broadcasts of the catch-up, go out at most
once every 250 ms with the latest state (`ImportDaemon::broadcast()`); the start,
a cancel and the end of a pass go out at once. The warnings and errors of a pass
keep their newest 100 entries in the Import log, and the rest are counted.

## Per-port series

With `NFSEN_PORTS` set, each capture file is queried once per configured port and
the result written to that port's own database. `NFSEN_PORT_DIRECTION` decides
which side of a flow counts:

| Value | Query | Counts |
|---|---|---|
| `dst` (default) | `-s dstport:p 'dst port N'` | Flows whose destination is the port |
| `src` | `-s srcport:p 'src port N'` | Flows whose source is the port |
| `any` | `-s port:p 'port N'` | The port in either direction |

The default is what every release so far counted, and it stays the default:
widening it would step every existing port series upward against data already on
disk under the old meaning.

**Set `any` if your port graphs are empty while the per-source graphs work.** That
is what an exporter reporting one direction of each flow looks like, which
ingress-only or egress-only sampling produces: every flow names the port as its
*source*, so a destination-only query matches nothing
([#173](https://github.com/mbolli/nfsen-ng/issues/173)). A port's traffic is
arguably the conversation on it, so `any` is the truer reading, but it is opt-in
because the numbers it produces are not comparable with the ones already stored.

Under `any`, each port counts only its own rows: `-s port:p` reports both ports of
every matching flow, so a request from `10007` to `443` yields a row for each, and
summing them all would roughly double every value.

A configured port that sees no traffic in a run is named once in the import log,
so a flat graph says why instead of leaving you to guess whether collection is
broken.

## Top-N collection

`TopNCollector` (`backend/common/TopNCollector.php`) fills the per-interval top-N
lists behind the Overview page. Every path that imports a capture file (the
inotify watch, the catch-up, Trigger, Backfill, Rescan) calls
`TopNCollector::enqueue()` with the file and its `-I` totals.

- **Skip rule.** One primary-key lookup in `topn_interval` decides whether a file
  is needed: an interval collected (ok or empty) from a file at least as new as
  this one is skipped, and so is a file that failed three times without changing.
  So a Backfill or Rescan does not collect the same intervals again unless the
  capture file changed.
- **Worker.** A worker coroutine lives while the queue (up to 4096 files) has
  items. It starts up to half the nfdump process limit of collecting coroutines
  (`NfdumpSlots::backgroundMax()`), each taking the oldest queued file. For each
  file a coroutine runs nfdump twice (`-o csv`, eight `-s` statistics and then the
  ninth, since nfdump takes at most eight per run) and parses the
  multi-statistic csv with `MultiStatCsvParser`. It hands the rows to the
  worker, which is the only coroutine that writes. With fewer than 4 processes
  that is one file at a time; with 8 processes on a 20-core host, files of
  300,000 flows took 85 to 110 ms each instead of 250 to 300 ms.
- **Writes.** Each interval is stored in one small transaction together with a
  pending mark. Every 48 stored intervals the worker adds the marked intervals
  to the hour and day sums, one bucket per transaction, and checkpoints the WAL
  every second write. After ten minutes without a store, the minute tick flushes
  what is left, and gives way between two transactions once the worker runs
  again. Range reads include the marked five-minute rows, so every answer stays
  exact while sums are pending.
- **Slot rule.** The collector runs as background work (see
  [Nfdump Integration](nfdump-integration.md#processes-and-slots)): it holds at
  most half the slots, starts a run only while a slot stays free for a user
  query, and never while a user query waits. It also waits while a bulk import
  holds a daemon lock. Its runs use the query handle `topn`, so a user's Kill
  never reaches them.
- **Gap filler.** One minute after start and then every ten minutes while the
  queue is empty, it walks the retention window day by day, newest first, and
  queues up to 500 capture files that have no usable interval. During a
  backfill, the next pass starts once the queue is down to 250 files, until
  nothing is missing or a file fails. Files being collected count as collected,
  so a pass never queues them twice. **Collect missing top-N now** on the Health
  page (`topn-fill`) runs the same pass at once.
- **Pruner.** Five minutes after start and then hourly, it deletes what fell out
  of `NFSEN_TOPN_RETENTION_DAYS`, in small per-statistic chunks with pauses in
  between.
- **Generation.** Each profile has a generation counter that moves every 50
  written files during a backlog and when the queue drains. Cached range answers
  of the Overview page are dropped when it moves.

With retention `0`, or when the SQLite store is unavailable, the collector stays
off and the Health page says why.

## Alert evaluation

`AlertManager::onFileImported()` receives each imported file with its interval.
It evaluates every enabled rule of the profile once per interval, when every
configured source has reported that interval or a newer one, in any arrival
order. A source with nothing waiting on disk once a later interval is in counts
as down and is left out until it reports again. Rules without a traffic filter
read each source's stored value right after its import; rules with a filter run
one `nfdump -s proto -n 0 -o csv` over the interval's files and add up the
protocol rows, so nfdump sums the traffic and the worker reads a few lines. The
evaluation runs as background work and waits at most 60 seconds in total for the
processes of all its filtered rules; a rule still without one is left out of
that interval with `no free nfdump process` in the log. `{time}` in a
notification is the start of the interval. See [Alerts](../features/alerts.md).

## Environment caveat

Cross-container `inotify` (nfcapd writing into a bind-mounted directory that a
*different* container watches) doesn't reliably propagate on every host, notably
WSL2. If the ongoing-watch path never seems to fire in a dev environment, check
that first, not the daemon code.
