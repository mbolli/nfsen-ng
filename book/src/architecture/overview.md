# Overview

nfsen-ng has three moving layers, all inside a single long-running PHP process:

```
nfcapd (external)              → writes rotated capture files to disk
      │  inotify
      ▼
ImportDaemon (backend/common)  → parses new files, writes to the datasource,
      │                           evaluates alert rules
      ▼
Datasource (Rrd|VictoriaMetrics)
      │
      ▼
Signals + Actions + SSE (php-via / Datastar) → browser
```

## Stack

| Layer | Technology |
|---|---|
| Runtime | PHP 8.4 on [OpenSwoole](https://openswoole.com/) coroutines |
| Web framework | [php-via](https://github.com/mbolli/php-via) — signals, actions, SSE, in-house |
| Reactivity | [Datastar](https://data-star.dev/) — server-driven DOM patching over SSE |
| Templates | Twig |
| Flow decoding | [nfdump](https://github.com/phaag/nfdump) CLI, invoked as a subprocess |
| Storage | RRD (default) or VictoriaMetrics, pluggable per the `Datasource` interface |
| Charts | Apache ECharts (time-series graphs and Sankey diagram) |

## Why one long-running process

Unlike classic PHP-FPM, `backend/app.php` is started once and stays running:
OpenSwoole's HTTP server, the SSE broadcaster, and the import daemon's inotify
watch all live in the same process's memory for as long as it's up. This is
what makes the reactive loop cheap — signals and their subscribers are plain
PHP objects, not something serialized to a session store between requests —
but it also means the process holds real state: alert cooldowns, the import
daemon's file-watch handles, per-session signal stores. A deploy is a process
restart, not just a new request.

In development, `deploy/docker-compose.dev.yml` runs this same process under
`entr`, which kills and restarts it whenever a watched `.php`/`.twig`/`.js`/
`.css` file changes — so there's no build step, but there's also no
hot-module-reload: a restart means every open browser tab's Datastar session
re-connects the SSE stream and gets a resynced page.

See [Reactive Loop](reactive-loop.md) for how signals/actions/SSE fit
together, [Data Sources](data-sources.md) for the storage backends, and
[Import Pipeline](import-pipeline.md) for how nfcapd files become RRD/VM data.
