# SQLite Store

Time series stay in RRD or VictoriaMetrics. Everything else nfsen-ng keeps that
is not a preference lives in one SQLite database: the per-interval top-N lists,
the saved filters, the alert history and the recorded query timings. The code is
in `backend/store/`.

## File and driver

The database is `<state dir>/nfsen-ng.sqlite`, with its `-wal` and `-shm`
companions: `/var/lib/nfsen-ng/state/nfsen-ng.sqlite` in the Docker image,
`backend/settings/nfsen-ng.sqlite` in a dev checkout or a default bare-metal
install. It needs the `pdo_sqlite` driver, which the `php:8.4-cli` base image
ships; bare metal needs `php8.4-sqlite3` and `phpenmod pdo_sqlite`.

SQLite is a hard dependency, but a missing driver or an unwritable state
directory never stops the app. `Database::shared()` throws
`StoreUnavailableException` with the path and the reason, remembers the failure
for 60 seconds so a broken store is not retried on every call, and every consumer
degrades: the Overview top-N says *Top-N unavailable*, the filter builder says
*Saved filters unavailable*, the alert history says *History unavailable*, and
estimates fall back to the default read rates. No constructor of a long-lived
object touches the database.

## Connection

`Database::open()` sets:

```sql
PRAGMA busy_timeout = 250;
PRAGMA journal_mode = WAL;     -- DELETE when the filesystem refuses WAL
PRAGMA synchronous = NORMAL;
PRAGMA foreign_keys = ON;
PRAGMA temp_store = MEMORY;
```

FUSE filesystems, such as Unraid's user shares, can refuse WAL. The store then
runs in rollback-journal mode, where reads wait while a write runs; the Health
page shows which mode is in use.

## Rules for code that uses it

OpenSwoole has no PDO hook, so every SQLite call blocks the worker, and all
coroutines of the process share one connection. The binding rules:

- **Keep statements small and indexed.** Measured on the dev host: a 450-row
  insert takes about 1.4 ms, a one-day `GROUP BY` about 5 ms. Query shapes that
  defeat the primary key (`stat BETWEEN`, an `OR` of time ranges) are not used,
  and `TopNRepositoryTest` asserts the query plans.
- **Never hold a transaction across a yield.** nfdump runs, `scandir`, file reads,
  curl, `Coroutine::sleep`, `broadcast` and `sync` all yield, and another
  coroutine would then run inside the open transaction. Do the external work
  first, then open, write and commit. `Database::transaction()` wraps
  `BEGIN IMMEDIATE ... COMMIT` and rolls back on any `\Throwable`.
- **Nothing the size of a range query runs in a render.** Actions compute results
  in a coroutine, in chunks that yield, and store them in the page state; the
  render reads what they stored.
- **Long maintenance runs in batches** with a 10 ms pause between them, outside
  any transaction (pruning, the gap filler).
- **PDO binds floats as TEXT.** Compare a float with an aggregate or expression as
  `CAST(? AS REAL)`, e.g. `HAVING SUM(bytes) / 300.0 > CAST(? AS REAL)`, or the
  comparison is silently wrong.
- **Only the server worker migrates or writes.** The MCP stdio process never calls
  `Database::shared()`; its `status` tool reads the file through
  `Database::inspect()`, which opens it read-only and never creates, migrates or
  throws.

## Migrations

`Migrator` (`backend/store/Migrator.php`) runs every migration in
`backend/store/migrations/` whose version is above `PRAGMA user_version`, each in
its own transaction, and then sets `user_version`. The first `Database::shared()`
in `AppStartup::boot()` migrates. `M0001Initial` is version 1. A database with a
newer version than the code knows (after a downgrade) is logged as an error and
used read-only for the tables the code knows; nothing is dropped.

## Schema

| Table | Holds |
|---|---|
| `meta` | Key/value pairs: `migrated.preference_filters`, `migrated.alerts_log`, `deployment_presets.seen` |
| `topn_interval` | One row per collected capture file (profile, source, interval start): its flows, packets and bytes from `nfdump -I`, status (ok, empty, failed), attempts, and the file's mtime when collected |
| `topn_5m` | The top 50 by bytes per statistic, interval and source: key, flows, packets, bytes |
| `topn_1h`, `topn_1d` | Exact hourly and daily (UTC) sums of the `topn_5m` rows, every key |
| `saved_filters` | Name, expression, normalised expression (unique), starred, origin, created, updated, last used, use count |
| `alert_events` | `fired`, `resolved` or `test`, time, rule id and name, profile, sources, metric, operator, value, threshold, origin (`live` or `migrated`) |
| `query_runs` | Per finished run: kind, time, capture bytes read, files, elapsed milliseconds, ok |

The top-N and alert tables are `WITHOUT ROWID` or indexed on their time columns,
so every range read is a primary-key range.

## Top-N data

`TopNCollector` writes one `topn_interval` row and up to 450 `topn_5m` rows (nine
statistics, top 50) per capture file, and `TopNRepository` adds the same rows to
the hour and day sums in the same transaction. Replacing an interval (a capture
file rewritten, then re-collected) subtracts the old rows from the sums first and
drops sums that reach zero, so the sums stay exact.

Retention is `NFSEN_TOPN_RETENTION_DAYS` (default 31, `0` disables collection).
The pruner runs five minutes after start and then hourly, and deletes what fell
out of the window in small per-statistic chunks. For sizing, one source over 31
days is about 4 million `topn_5m` rows, measured at 181 MB; the hour and day
sums each hold at most as many rows as `topn_5m` (keys that never repeat), and
about 45 % and 38 % of it with typical key churn. Budget about 12 MB per source and day.

## Saved filters

`SavedFilterRepository` keeps one list for the whole instance. Filters are unique
by their normalised expression (trimmed, every whitespace run collapsed to one
space); saving a duplicate raises `DuplicateFilterException`, which the drawer
shows as *Already saved as …*. The list is ordered starred first, then by last
use, then by name. `origin` records where a filter came from: `user`, `browser`
(imported from a browser's local storage), `preference` (the old Settings
presets) or `deployment` (`NFSEN_FILTERS` or `settings.php`).

`SavedFilterSeeder` fills the list on the first read per process: the
preference presets once (`migrated.preference_filters`), and every deployment
preset whose key is not yet in `deployment_presets.seen`, so a deployment preset
the user deleted stays deleted. Browser lists arrive through the drawer's
`filter-migrate-local` action (see [Actions Reference](../api.md#filter-builder)).

## Query timings

`QueryRunner` records every finished run in `query_runs`, and keeps the newest
200 per kind. `QueryEstimator` turns them into a read rate: the median bytes per
second of the last 20 successful runs of a kind that read at least 32 MiB and took
at least 200 ms, once there are three of them; until then it uses a default rate.
Estimates show *measured* when they use recorded runs.

## Alert events

See [Alerts](alerts.md#fired-and-resolved).

## Backup

The database is part of the state directory. Stop the app, then copy that
directory.

Copying the files while the app runs is not safe: the collector and the alerts
commit while the copy is in progress, and the copied database and `-wal` can come
from different moments. To back up the running app, let SQLite write a consistent
copy with `VACUUM INTO`. The image has no `sqlite3` shell, so go through PHP:

```bash
docker exec nfsen-ng php -r "(new PDO('sqlite:/var/lib/nfsen-ng/state/nfsen-ng.sqlite'))->exec(\"VACUUM INTO '/tmp/nfsen-ng.sqlite'\");"
docker cp nfsen-ng:/tmp/nfsen-ng.sqlite ./nfsen-ng.sqlite
docker exec nfsen-ng rm /tmp/nfsen-ng.sqlite
```

The target file must not exist yet. On bare metal, run the same `php -r` line with
the path of your state directory. The copy is a single file; to restore it, stop
the app, put it in place as `nfsen-ng.sqlite` and delete any `-wal` and `-shm`
files next to it.
