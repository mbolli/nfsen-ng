---
description: "nfsen-ng project architecture, conventions, and critical pitfalls. Covers php-via signals/actions/views, import pipeline, RRD datasource, broadcast scopes, and coroutine safety."
applyTo: "backend/**"
---

# nfsen-ng Architecture & Conventions

## Stack

- **PHP 8.4 + OpenSwoole** — single-worker coroutine HTTP server via `mbolli/php-via`
- **Datastar** — SSE hypermedia: server pushes full-page re-renders, browser diffs the DOM
- **Twig** — all HTML rendered server-side in `backend/templates/`
- **PECL rrd** — primary datasource (`Rrd.php`); VictoriaMetrics is an alternative
- **nfdump** — NetFlow processor; `Nfdump.php` shells out to the `nfdump` binary

## Request / Action / Broadcast Flow

```
browser GET /        → page() closure → signals registered → view closure registered
browser GET /_sse    → SSE connection kept alive by php-via
user interaction     → @post(action_id) → action closure runs → $c->sync() → full re-render pushed over SSE
nfcapd file change   → inotify → ImportDaemon::pollOnce() → Import::importFile() → $app->broadcast('rrd:live')
UI import button     → triggerImportAction → Coroutine::create() → Import::start() → $app->broadcast('admin:import') per file
```

All state that must survive across tabs or across a dropped SSE connection lives in `$app->setGlobalState()`.

## Signal Conventions

```php
// Client-writable: browser may update via data-bind / data-on
$signal = $c->signal($default, 'name', clientWritable: true);

// Server-owned (read-only for browser):
$signal = $c->signal($default, 'name');

// Read helpers — always use these, never ->getValue() directly
$signal->string()   // string
$signal->int()      // int
$signal->bool()     // bool
$signal->array()    // array (native — php-via stores arrays natively)

// Write without triggering a broadcast:
$signal->setValue($value, broadcast: false);
```

- Prefix `_` → client-local signal (never posted back to server, e.g. `$_currentView`)
- `data-indicator:signal_name` → auto-manages a loading boolean signal for async actions
- `clientWritable: false` (default) → `setValue()` still works server-side; browser just cannot write

## View Closure

The view closure re-renders the full page on every `$c->sync()` (action) and every `$app->broadcast()` the tab is subscribed to. With `cacheUpdates: false`, each tab gets its own fresh render — no caching across clients.

```php
$c->view(function (bool $isUpdate) use ($c, $signal, &$refVar): string {
    // $isUpdate = true when triggered by broadcast, false on initial SSE connect
    return $c->render('layout.html.twig', [...]);
}, cacheUpdates: false);
```

**Performance rule**: Expensive per-render work (RRD reads, DB calls) must be throttled using `&$ref` variables captured before the closure. See [php-via-view-performance.instructions.md](php-via-view-performance.instructions.md).

## Twig Template Conventions

```twig
{# ✅ Correct signal reference in data-* attributes #}
data-show="$graph_display == 'sources'"
data-class:loading="$flows._indicator"

{# ❌ Invalid — curly braces not valid in data-* #}
data-show="${graph_display == 'sources'}"

{# Signal binding (two-way, expands to data-bind="hash") #}
{{ bind(signalObject) }}

{# ⚠️ NEVER use {{ bind(signal) }} inside a data-on:* JS expression — it expands
   to an HTML attribute, generating invalid JS like `data - bind = "…"`. #}
{# ✅ Use ${{ signal.id() }} for direct value reference inside event expressions #}
data-on:click="${{ confirmRescan.id() }} = true"

{# Server timestamps → display in browser local time #}
<span data-text="new Date(${{ signal.id() }} * 1000).toLocaleString('sv')">
    {{ signal.int() | date('Y-m-d H:i:s') }}
</span>
```

## Broadcast Scopes

| Scope | Trigger | Subscribers |
|---|---|---|
| `admin:import` | Per file during bulk import | Admin tabs only (`$c->addScope('admin:import')`) |
| `rrd:live` | After any import completes (inotify or bulk) | All tabs |

Always guard broadcasts: `if (!empty($app->getClients())) { $app->broadcast('scope'); }`

## Import Architecture

### ImportDaemon (ongoing, event-loop-safe)

```php
$daemon = new ImportDaemon();
// 1. Initial catch-up bulk import — run in a coroutine (blocking-safe)
\OpenSwoole\Coroutine::create(fn() => $daemon->initialImport());
// 2. Ongoing inotify poll every 1s — runs in the event loop via setInterval
$app->setInterval(fn() => $daemon->pollOnce(fn() => $app->broadcast('rrd:live')), 1000);
```

### UI-triggered Import (bulk, coroutine)

The import coroutine **must not** hold a reference to `$c` (the SSE context). If the user closes the tab, `$c` becomes invalid. All state goes via `$app->setGlobalState()`:

```php
$c->action(function (Context $c) use ($app, $daemon): void {
    $daemon->lock();                          // blocks pollOnce() from interleaving
    $app->setGlobalState('import_progress', 0);
    $app->setGlobalState('import_cancel', false);
    $c->sync();                               // sync the initiating tab immediately

    \OpenSwoole\Coroutine::create(function () use ($app, $daemon): void {
        // No $c here — it may be gone by the time this runs
        $importer->start(
            $start,
            function (array $progress) use ($app): void {
                $app->setGlobalState('import_progress', $progress['pct']);
                $app->broadcast('admin:import');
            },
            $flushLog,
            static fn(): bool => (bool) $app->globalState('import_cancel', false)
        );
    });
}, 'trigger-import');
```

### Import::start() Callbacks

| Param | Type | Invoked | Purpose |
|---|---|---|---|
| `$onProgress` | `callable(array):void` | after each successful file write | update progress, broadcast |
| `$onTick` | `callable():void` | after each exception/skip | drain log buffer |
| `$shouldCancel` | `callable():bool` | before each file | user cancel check |

The `$onProgress` array contains: `file`, `source`, `processed`, `total`, `pct`, `eta`.

### Import Lock

`$daemon->lock()` sets `$importLocked = true`. While locked:
- `pollOnce()` skips execution — prevents inotify from interleaving mid-bulk-import
- Critical for force-rescan: `reset()` sets `last_update=0`; a stray poll would advance it to "now", breaking all subsequent historical writes

Always call `$daemon->unlock()` in a `finally` block.

### Cancel Flag

```php
// Set from cancelImportAction:
$app->setGlobalState('import_cancel', true);

// Checked before each file in Import::start():
static fn(): bool => (bool) $app->globalState('import_cancel', false)
```

Reset `import_cancel` to `false` at the start of every new import run (before the coroutine), and again in the `finally` block after it finishes.

## RRD Datasource

### File Layout

`backend/datasources/data/{source}.rrd` — aggregate for a source  
`backend/datasources/data/{source}_{port}.rrd` — per-source port breakdown  
`backend/datasources/data/{port}.rrd` — cross-source port aggregate

### RRA Structure (4 levels)

| Level | Step | Duration |
|---|---|---|
| 5-minute samples | 1×5min | 45 days |
| 30-minute samples | 6×5min | 90 days |
| 2-hour samples | 24×5min | 365 days |
| Daily samples | 288×5min | `import_years × 365` days |

Step = 300s (5 minutes). `RRDCreator` uses `ABSOLUTE:600:U:U` sources. Both `AVERAGE` and `MAX` consolidation functions are stored.

### Write Safety

`Rrd::write()` applies three guards before calling `RRDUpdater`:

1. **File missing** → `create()` first
2. **Corrupted far-future timestamp** (`rrd_last() > now + 1 year`) → `create(..., reset: true)` to recreate
3. **Duplicate/past timestamp** (`nearest <= rrd_last()`) → silent `return true` (avoid "illegal attempt to update" noise when restarting mid-import)

The "nearest" timestamp is always quantised to 5-minute boundaries: `$ts - ($ts % 300)`.

### reset() Pitfall (fixed)

`reset()` iterates ports then sources. The port=0 iteration has no `create()` call (only sources get created for port=0). `$return` must be initialised to `true`, **not** `false` — otherwise the `if ($return === false) return false` guard fires immediately and reset() exits without doing anything.

### Fields (15 data sources per RRD)

`flows`, `flows_tcp`, `flows_udp`, `flows_icmp`, `flows_other`,  
`packets`, `packets_tcp`, `packets_udp`, `packets_icmp`, `packets_other`,  
`bytes`, `bytes_tcp`, `bytes_udp`, `bytes_icmp`, `bytes_other`

### get_data_path() Naming

```php
get_data_path('gateway')      → data/gateway.rrd
get_data_path('gateway', 80)  → data/gateway_80.rrd
get_data_path('', 80)         → data/80.rrd
get_data_path('', 0)          → data/.rrd   ← avoid; port=0 means "source aggregate"
```

## Config

`Config::$cfg` is empty until `Config::initialize()` runs. **Never read config at module load time** (class properties, constructor defaults before `initialize()`).

Key config paths:
- `Config::$cfg['general']['sources']` — array of source names
- `Config::$cfg['general']['ports']` — array of port numbers to track
- `Config::$cfg['nfdump']['profiles-data']` — nfcapd root dir
- `Config::$cfg['nfdump']['profile']` — active profile (default: `live`)
- `Config::$cfg['db']['RRD']['import_years']` — RRD depth (default: 3)
- `Config::$db` — datasource instance (Rrd or VictoriaMetrics)
- `Config::$processorClass` — processor class (Nfdump)

## Debug / Logging

```php
$debug = Debug::getInstance();
$debug->log('message', LOG_WARNING);  // LOG_DEBUG, LOG_INFO, LOG_WARNING, LOG_ERR, LOG_CRIT

// Ring buffer — populated for LOG_WARNING and above
$entries = Debug::drainBuffer();  // returns array<{ts, level, msg}> and clears the buffer
```

The ring buffer is used to stream import warnings to the admin log panel. Drain it before starting a new import run (`Debug::drainBuffer()` to discard stale entries), then drain inside `$onProgress`/`$onTick` and broadcast.

## nfcapd File Path Structure

```
{profiles-data}/{profile}/{source}/YYYY/MM/DD/nfcapd.YYYYMMDDHHII
```

Example: `.../live/gateway/2025/10/15/nfcapd.202510151200`

Files are named by the **start** of the 5-minute capture window. `Import::start()` walks day directories and processes files in scandir order (lexicographic = chronological).

## Nfdump Processor

`Nfdump::execute()` returns:
```php
[
    'decoded'   => array,   // parsed JSON rows (may be empty array)
    'rawOutput' => string,  // raw nfdump stdout
    'command'   => string,  // the shell command that was run
    'stderr'    => string,  // nfdump stderr warnings
]
```

Always check `$result['decoded'] ?? []` — `decoded` is absent if nfdump returned no records. Guard: `if (empty($input['decoded'])) { return false; }` before processing.

## Global State Keys (import pipeline)

| Key | Type | Description |
|---|---|---|
| `daemon` | `ImportDaemon\|null` | daemon instance, set in `onStart` |
| `import_progress` | `int` (0–100) | current progress % |
| `import_current_file` | `string` | path of file being processed |
| `import_status_text` | `string` | human-readable status |
| `import_eta` | `string` | formatted ETA string |
| `import_log` | `array` | accumulated warning/error entries |
| `import_cancel` | `bool` | user-requested cancel flag |
| `_fatalError` | `string\|null` | startup error, blocks normal rendering |

## Common Pitfalls

- **Never hold `$c` in a coroutine** — the SSE context is tab-scoped and becomes invalid if the tab closes. Store all long-running state in `$app->setGlobalState()`.
- **Config is empty at module load** — `Config::$cfg` is `[]` until `Config::initialize()` runs in `onStart`. Don't read it in constructors or class properties.
- **`$c->sync()` before `Coroutine::create()`** — sync the initiating tab immediately so the UI reflects "import running" before the coroutine starts. Otherwise the button stays enabled.
- **`reset()` then `pollOnce()`** — always lock the daemon before force-rescan. An inotify event mid-reset would write a timestamp to a freshly-zeroed RRD, corrupting the import.
- **Twig `{{ bind(signal) }}` in event handlers** — expands to an HTML attribute, producing invalid JS inside `data-on:*`. Use `${{ signal.id() }}` for direct signal value references.
- **`data-show` with conditional rendering** — if a `div` is conditionally absent from the DOM, Datastar cannot morph it in place on the next update. Always render the container (use `invisible` CSS class or `style="display:none"` + `data-show`) rather than Twig `{% if %}` for elements that toggle.
- **Timestamps are Unix epoch on the server** — never pass `DateTime` objects to templates for reactive display. Store as `int` (Unix epoch) and format client-side with `new Date(ts * 1000).toLocaleString('sv')`.
- **RRD step quantisation** — always quantise timestamps to 5-minute boundaries before writing: `$ts - ($ts % 300)`. Writing a non-quantised timestamp causes "illegal attempt to update" errors.
