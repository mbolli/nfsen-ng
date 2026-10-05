---
description: "nfsen-ng backend architecture, conventions, and critical pitfalls. Covers php-via signals, actions and renders, the import pipeline, nfdump processes, the RRD datasource, broadcast scopes, and coroutine safety."
applyTo: "backend/**"
---

# nfsen-ng Architecture & Conventions

`AGENTS.md` at the repository root is the full reference (pages, templates, CSS, Rocket elements, tests). This file
keeps what matters most while editing `backend/`.

## Stack

- **PHP 8.4 + OpenSwoole**: one worker process, a coroutine HTTP server via `mbolli/php-via` 0.13
- **Datastar 1.0.4 with Rocket**: SSE hypermedia; the server pushes the rendered tab and the browser morphs the DOM
- **Twig**: all HTML rendered server-side in `backend/templates/`
- **RRD** (PECL `rrd`, default) or **VictoriaMetrics** for the time series; **SQLite** for everything else
- **nfdump 1.7.10**: `Nfdump.php` runs the binary, several processes at once under `NfdumpSlots`

## Request / Action / Broadcast Flow

```
browser GET /        → app.php page('/'): Shell, shell modules and pages declare signals and register actions,
                       then Shell::render() renders the shell and the ACTIVE page only
browser GET /_sse    → SSE connection kept alive by php-via, one context per tab
user interaction     → @post(action url) → action closure → $c->sync() → the rendered tab pushed over SSE
nfcapd file          → inotify → ImportDaemon::pollOnce() → Import::importFile() → ImportDaemon::broadcast($app, 'rrd:live')
Trigger / Rescan     → ImportActions → Coroutine::create() → Import::start() → ImportDaemon::broadcast($app, 'admin:import')
```

State that must survive across tabs, or a dropped SSE connection, lives in `$app->setGlobalState()`; per-tab
results live in `PageStates` and come back through `Revival` when php-via revives a context.

## Signal Conventions

```php
// Client-writable: the browser may update it (data-bind, an expression); normalise on read
$signal = $c->signal($default, 'name', clientWritable: true);

// Server-owned: php-via ignores the browser's copy on actions and revivals and sends the server value back
$signal = $c->signal($default, 'name', clientWritable: false);

// Read helpers: always use these, never ->getValue() directly
$signal->string();
$signal->int();
$signal->bool();
$signal->array();   // php-via stores arrays natively
```

- Every `$c->signal()` states `clientWritable`; left out, a TAB signal is client-writable.
- A leading `_` means Datastar never posts the signal back. Only `_` signals the browser seeds itself with
  `data-signals` are used by bare name; a server-owned `_` signal declared with `$c->signal()` is hashed like any
  other and needs `.id()`.
- `data-indicator:signal_name` manages a loading boolean for an async action.

## Rendering

`app.php` registers one `$c->view()` that renders `layout.html.twig` through `Shell::render()` on every
`$c->sync()` and every broadcast the tab is subscribed to (`cacheUpdates: false`, so each tab renders its own).
Only the active page renders in full, and its `viewData()` must stay cheap: it reads what actions stored in the
page state and never runs nfdump or a range-sized SQLite read. Expensive render inputs are cached per tab
(`ShellState`: the graph at most every 10 s during an import) or process-wide (Health checks, Settings tabs,
top-N range answers, estimates). See [php-via-view-performance.instructions.md](php-via-view-performance.instructions.md).

## Twig Template Conventions

```twig
{# Server signal: the wire id is name + per-context hash, so always use .id() #}
data-show="${{ graph_display.id() }} == 'sources'"

{# Wrong: a bare server signal name matches nothing and fails silently #}
data-show="$graph_display == 'sources'"

{# Signal binding (two-way, expands to data-bind="hash") #}
{{ bind(signalObject) }}

{# Never use {{ bind(signal) }} inside a data-on:* expression: it expands to an HTML attribute,
   which is invalid JS there. Assign through the id instead. #}
data-on:click="${{ confirmRescan.id() }} = true"

{# Server timestamps are epochs, formatted in the browser in the display timezone #}
<time data-text="new Date(${{ signal.id() }} * 1000).toLocaleString(undefined, tzOptions(${{ displayTz.id() }}, ${{ nfcapdTz.id() }}))"></time>
```

## Broadcast Scopes

| Scope | Sent by | Subscribers |
|---|---|---|
| `admin:import` | Import progress, through `ImportDaemon::broadcast()` | Every tab (Health, the controls bar's import chip) |
| `rrd:live` | Each imported file and the end of a pass, through `ImportDaemon::broadcast()` | Every tab |
| `settings:saved` | A settings save | Every tab |
| `alerts:fired` | Rules that fired, or a rule change | Every tab |

`app.php` subscribes every tab to all four with `$c->addScope()`.

`ImportDaemon::broadcast($app, $scope)` renders a scope at most once every 250 ms after the previous render and
always sends the state at the end of that window; pass `now: true` for the start, a cancel and the end of a pass.
Rendering every tab once per imported file ran the 128 MB worker out of memory with six tabs open. It also skips
the broadcast while no client is connected.

## Import Architecture

### ImportDaemon (ongoing, event-loop-safe)

One daemon per profile, kept in the global state `daemons`. `AppStartup::boot()` (from `onStart()`) runs the
catch-up in a coroutine and polls inotify every second:

```php
\OpenSwoole\Coroutine::create(fn () => $daemon->initialImport());
$app->setInterval(fn () => $daemon->pollOnce(fn () => ImportDaemon::broadcast($app, 'rrd:live')), 1000);
```

### UI-triggered Import (bulk, coroutine)

The import coroutine **must not** hold a reference to `$c` (the SSE context): if the tab closes, `$c` is gone.
All state goes through `$app->setGlobalState()` (`import_progress`, `import_current_file`, `import_status_text`,
`import_eta`, `import_active_profile`, `import_cancel`), and the warnings of the pass through
`ImportDaemon::appendLog()`, which keeps the newest 100 entries and counts all of them.

### Import::start() Callbacks

| Param | Type | Invoked | Purpose |
|---|---|---|---|
| `$onProgress` | `callable(array):void` | after each successful file write | update progress, broadcast |
| `$onTick` | `callable():void` | after each exception/skip | drain log buffer |
| `$shouldCancel` | `callable():bool` | before each file | user cancel check |

The `$onProgress` array contains: `file`, `source`, `processed`, `total`, `pct`, `eta`.

### Import Lock

`$daemon->lock()` makes `pollOnce()` skip, so inotify cannot interleave with a bulk import. This matters for
Rescan: `reset()` sets `last_update` to 0, and a stray poll would advance it to now and break every later
historical write. Always `unlock()` in a `finally` block. Reset `import_cancel` to `false` before a new run and
again in its `finally`.

## nfdump Processes

- `NfdumpSlots` gives out one slot per nfdump process, up to `NFSEN_NFDUMP_MAX_PROCESSES` (`auto`: a third of
  the CPU cores, 2 to 8). `INTERACTIVE` (the default) may take every free slot and waits 30 s; `BACKGROUND`
  (import, top-N collector, live alert checks) holds at most half, only while one stays free, never while a user
  query waits, and waits up to 10 minutes.
- Background work runs inside `NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, $fn, budget: $seconds)`. The scope is
  per coroutine and not inherited by child coroutines.
- Parallel work: `acquireMany()` for a pool, `runInHeldSlot()` for each child's run, `release()` for each slot.
- Give every concurrent run its own `new Nfdump()`; `Nfdump::getInstance()` is shared, and another coroutine's
  `reset()` during a slot wait changes the options of the run.
- Never read an unbounded output whole: let nfdump aggregate (`-s ... -n`, `-c`).

## Nfdump Processor

`Nfdump::execute()` returns:

```php
[
    'decoded'   => array,   // parsed rows (JSON, csv, or the fixed-width text of -B read back into columns)
    'rawOutput' => string,  // nfdump's stdout, untouched
    'command'   => string,  // the command line as shown to the user
    'stderr'    => string,  // optional: only when nfdump printed more on stderr than benign notices
    'notes'     => list<string>, // side notes: a reached limit, "No matching flows", the exit code
    'exitCode'  => int,
]
```

A non-zero exit with no rows throws `NfdumpException`; `wasStopped()` is true for a Kill. Render every nfdump
message as escaped text: nfdump quotes the filter back.

## RRD Datasource

### File Layout

`{data_path}/{profile}/{source}.rrd`: aggregate for a source
`{data_path}/{profile}/{source}_{port}.rrd`: per-source port breakdown
`{data_path}/{profile}/{port}.rrd`: cross-source port aggregate
A `.rrd.first` sidecar records the first real sample.

### RRA Structure (4 levels)

| Level | Step | Duration |
|---|---|---|
| 5-minute samples | 1×5min | 45 days |
| 30-minute samples | 6×5min | 90 days |
| 2-hour samples | 24×5min | 365 days |
| Daily samples | 288×5min | `import_years × 365` days |

Step = 300s (5 minutes). `RRDCreator` uses `ABSOLUTE:600:U:U` sources. Both `AVERAGE` and `MAX` consolidation
functions are stored.

### Write Safety

`Rrd::write()` applies three guards before calling `RRDUpdater`:

1. **File missing**: `create()` first
2. **Corrupted far-future timestamp** (`rrd_last() > now + 1 year`): `create(..., reset: true)` to recreate
3. **Duplicate/past timestamp** (`nearest <= rrd_last()`): silent `return true`, so a restart mid-import logs no
   "illegal attempt to update"

The "nearest" timestamp is always quantised to 5-minute boundaries: `$ts - ($ts % 300)`.

### Fields (15 data sources per RRD)

`flows`, `flows_tcp`, `flows_udp`, `flows_icmp`, `flows_other`,
`packets`, `packets_tcp`, `packets_udp`, `packets_icmp`, `packets_other`,
`bytes`, `bytes_tcp`, `bytes_udp`, `bytes_icmp`, `bytes_other`

## Config

`Config::$cfg` is empty until `Config::initialize()` runs. **Never read config at module load time** (class
properties, constructor defaults). Typed values are in `Config::$settings` (`nfdumpBinary`, `nfdumpMaxProcesses`,
`nfdumpWorkers`, `importYears()`, ...), the datasource in `Config::$db`.

## Debug / Logging

```php
Debug::getInstance()->log('message', LOG_WARNING);  // LOG_DEBUG, LOG_INFO, LOG_WARNING, LOG_ERR, LOG_CRIT
$entries = Debug::drainBuffer();                     // LOG_WARNING and up since the last drain
```

The import drains the buffer into the Import log (`ImportDaemon::appendLog()`); the Health page's Recent log
reads `Debug::recent()`, the last 200 lines from an in-memory ring.

## nfcapd File Path Structure

```
{profiles-data}/{profile}/{source}/YYYY/MM/DD/nfcapd.YYYYMMDDHHII
```

Files are named by the **start** of the 5-minute capture window, in `NFCAPD_TZ` (`Config::nfcapdTimezone()`).

## Common Pitfalls

- **Never hold `$c` in a coroutine** that can outlive the request: store long-running state in the global state.
- **Every coroutine an action starts catches `\Throwable`**: php-via guards the action, not the coroutines it
  creates, and an uncaught throw there ends the worker and every tab with it.
- **`$c->sync()` before `Coroutine::create()`** so the initiating tab shows the running state at once.
- **Lock the daemon before Rescan**: an inotify event during `reset()` writes a timestamp into a fresh RRD.
- **Shutdown**: `AppStartup::shutdown()` ends the lag probe, the deferred broadcasts, the daemons, the collector
  and the alert checks, and kills the tabs' nfdump runs. A new loop that can run for more than a moment ends
  there or checks `$app->isShuttingDown()`.
- **SQLite blocks the worker**: small indexed statements, no transaction across a yield (nfdump, file I/O, curl,
  `Coroutine::sleep`, `broadcast`, `sync`), long maintenance in batches with pauses.
- **Toggled elements**: render the container and toggle it with `data-show` or the `hidden` attribute, not with
  Twig `{% if %}`, so a morph can update it in place.
- **Timestamps are Unix epochs on the server**: never pass `DateTime` objects to templates for reactive display.
