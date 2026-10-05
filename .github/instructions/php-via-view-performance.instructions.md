---
description: "Use when changing the $c->view() render in app.php, what a page's viewData() reads, broadcast performance, throttling expensive RRD/DB reads during imports, or caching per-tab state across re-renders."
applyTo: "backend/app.php"
---

# php-via Render Performance

## The Problem

`app.php` registers one `$c->view()` with `cacheUpdates: false`, so every `$app->broadcast()` renders the tab once
per connected SSE client. Whatever a render reads (RRD fetches, SQLite queries, directory scans) runs once per tab
and per broadcast, and all of it runs in the one worker that serves every tab. During a bulk import that is N tabs
times M files: a 17,856-file import with six tabs ran the 128 MB worker out of memory after about four minutes,
because the frames piled up for the tabs that could not keep up.

## Where the Work Goes

- **Actions compute, renders read.** An action (or a coroutine it starts) runs nfdump or a range-sized SQLite
  read, stores the result in the page state (`PageStates`, `backend/pages/state/`) and calls `$c->sync()`. A
  page's `viewData()` runs only while the page is active and reads what was stored.
- **Per-tab caches live in the page state.** `ShellState` keeps the traffic graph's last fetch (`graphJson`,
  `graphLegend`, `graphStep`) and `graphDue()` decides when to fetch again: after a range or option change, on the
  live tick, and during an import at most every `ShellState::IMPORT_THROTTLE` seconds (10).
- **Process-wide caches** hold what every tab renders the same: the footer capture status (30 s), the Health checks
  and metrics (30 s while Health is open, 5 minutes otherwise, refreshed in a coroutine, never in a render), the
  Settings read-only tabs (30 s), top-N range answers (64-entry LRU), query estimates (32-entry LRU).
- **Import broadcasts are throttled at the source.** `ImportDaemon::broadcast($app, $scope)` sends `admin:import`
  and the import's `rrd:live` at most once every 250 ms after the previous render, always with the state at the
  end of the window; `now: true` for the start, a cancel and the end of a pass. Measured on an import with six
  tabs: 34 to 41 renders a second instead of 252 to 322.

## Rules

- **Never return empty or zero to skip work.** Returning `[]` or `0` shows "no data" or "Never" where there was
  data. Reuse the last cached value instead, as `ShellState::$graphJson` does.
- **The first render always fetches**: a fresh state has `graphFetchedAt = 0`, so the throttle never holds back
  the first graph.
- **Throttle only during an import.** Outside one, the graph follows the live tick and every range change.
- **Derive "importing" from the daemons' lock** (the global state `daemons`, `ImportDaemon::isLocked()`), not
  from a signal: signals are per tab.
- **Keep `withSseMaxQueuedBytes()` above 0** in `app.php`. php-via 0.13 drops element patches for a tab with more
  than 1 MB unsent; at 0 it parks the tab's write instead, which loses patches anyway and holds a stop.

## Broadcast Scopes

`app.php` subscribes every tab to `admin:import`, `rrd:live`, `settings:saved` and `alerts:fired`. A manual pass
unlocks its daemon before its last `rrd:live` broadcast (`now: true`), so that render no longer counts as an
import and every tab fetches its graph again.
