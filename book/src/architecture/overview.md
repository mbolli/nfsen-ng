# Overview

nfsen-ng has three moving layers, all inside a single long-running PHP process:

```
nfcapd (external)              → writes rotated capture files to disk
      │  inotify
      ▼
ImportDaemon (backend/common)  → reads each new file with nfdump, writes the
      │                           datasource, queues the file for the top-N
      │                           collector, evaluates alert rules per interval
      ▼
Datasource (Rrd|VictoriaMetrics)   time series: flows, packets, bytes per 5 min
SQLite store (backend/store)       top-N lists, saved filters, alert history,
      │                            query timings
      ▼
Shell + pages + actions + SSE (php-via / Datastar) → browser
```

## Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8.4 on [OpenSwoole](https://openswoole.com/) coroutines |
| Web framework | [php-via](https://github.com/mbolli/php-via): signals, actions, SSE, in-house |
| Reactivity | [Datastar](https://data-star.dev/) 1.0.4: server-driven DOM patching over SSE, and its Rocket components for the charts, the table and the filter editor |
| Templates | Twig, one template per page plus the shell parts |
| Flow decoding | [nfdump](https://github.com/phaag/nfdump) 1.7.10 CLI, invoked as a subprocess, several at once |
| Time series | RRD (default) or VictoriaMetrics, pluggable per the `Datasource` interface |
| Everything else | SQLite through `pdo_sqlite`, one file in the state directory |
| Charts | Apache ECharts (traffic graph, Sankey, Matrix) |
| Components | `sb-relative-time` and `sb-popover` from [Starbase](https://github.com/zweiundeins/starbase), vendored |

## One long-running process

`backend/app.php` is started once and stays running: OpenSwoole's HTTP server,
the SSE broadcaster, the import daemon's inotify watch, the top-N collector's
queue and the SQLite connection all live in the same process's memory for as long
as it's up. That makes the reactive loop cheap (signals and their subscribers are
plain PHP objects, and each browser tab's page state is an object in memory, not
something serialised to a session store between requests), but the process holds
real state: per-tab results, alert cooldowns, file-watch handles, caches. A
deploy is a process restart, not just a new request. When php-via revives a
tab's context (it re-runs the page handler under the same context id), `Revival`
hands the tab its results back; a reload gets a new context and starts clean.
Signals only the server sets start from scratch on a revival, so a query that
was running when the tab went to the background does not leave it waiting
forever.

One process also means one memory budget for every tab. The Docker images run
PHP with `memory_limit` 512M, and `deploy/systemd/nfsen-ng.service` passes
`-d memory_limit=512M`: a worker that runs out ends every session at once and
starts a catch-up import when it comes back. Large results therefore stay bounded
where they are read. nfdump aggregates before the worker parses anything (`-s`
with `-n`, `-c` for a listing, `-s proto` for an alert's traffic filter), a Flows
listing above 1,000 rows first checks that it fits, and the import sends at most
one progress render every 250 ms. The System card on the Health page shows the
event-loop lag, how late a 100 ms timer fires in this process.

A stop (`docker stop`, `systemctl stop`, Ctrl-C) runs `AppStartup::shutdown()`
from php-via's `onShutdown`: the import daemon, the top-N collector and the
alert checks end, the nfdump runs of the tabs' queries and of MCP calls and the
capture file walks stop, an import finishes the capture file it is on, and the
process exits within about a second rather than being killed after
`max_wait_time`.

In development, `deploy/docker-compose.dev.yml` runs this same process under
`entr`, which stops and restarts it whenever a watched `.php`/`.twig`/`.js`/
`.css` file changes. There's no build step, but also no hot-module reload: a
restart means every open browser tab's Datastar session reconnects the SSE stream
and gets a resynced page.

See [Reactive Loop](reactive-loop.md) for how pages, signals, actions and SSE fit
together, [Data Sources](data-sources.md) for the storage backends,
[Import Pipeline](import-pipeline.md) for how nfcapd files become stored data, and
[SQLite Store](../features/sqlite-store.md) for the non-time-series data.
