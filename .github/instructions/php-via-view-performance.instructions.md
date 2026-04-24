---
description: "Use when adding or modifying the $c->view() closure in app.php, or when work involves broadcast performance, throttling expensive RRD/DB reads during imports, or caching per-tab state across re-renders."
applyTo: "backend/app.php"
---

# php-via View Closure Performance

## The Problem

With `cacheUpdates: false`, every `$app->broadcast()` call triggers one full view render per connected SSE client. If the view closure calls expensive operations (RRD reads via `fetchGraphData()`, `last_update()` DB calls), those run N times per broadcast — once per tab.

During a bulk import, broadcasts fire on every file processed. N clients × M files × expensive reads = O(N×M) bottleneck.

## Pattern: Ref-Variable Cache

Declare cache variables **before** the `$c->view()` closure, then capture them with `&$ref` (by reference). The view closure closure updates them when it actually runs the expensive work; the values persist for the lifetime of the tab's SSE context (i.e., between renders).

```php
// Declare before $c->view()
$lastExpensiveFetch = 0;
$cachedResult = '[]';
/** @var array<...> $cachedSources */
$cachedSources = [];

$c->view(function (bool $isUpdate) use (
    ...,
    &$lastExpensiveFetch, &$cachedResult, &$cachedSources
): string {
    $now = time();
    // Always run on first render (lastFetch=0); throttle to 10s during import
    if (!$isImporting || ($now - $lastExpensiveFetch) >= 10) {
        $cachedResult  = json_encode($expensiveRead(), JSON_THROW_ON_ERROR);
        $cachedSources = $buildSourceList();
        $lastExpensiveFetch = $now;
    }
    // Use cached values — never return [] or 0 which lose previously visible data
    $graphData     = $cachedResult;
    $importSources = $cachedSources;
    ...
});
```

## Rules

- **Never return empty/zero to skip work** — returning `[]` or `0` replaces real data with "no data available" or "Never" in the UI. Always reuse the last cached value instead.
- **The initial render always runs** — since `$lastFetch = 0`, the condition `time() - 0 >= 10` is always true on the first render, so caches are populated before any throttling kicks in.
- **Cache is per-tab** — ref variables are scoped to the page closure and live for the SSE context's lifetime. Different tabs have independent caches.
- **Throttle only during import** — outside of import (`!$isImporting`), always call the expensive function fresh so graph data stays live.
- **Check `$isImporting` from daemon lock** — derive it from `$app->globalState('daemon', null)?->isLocked() ?? false`, not from a signal value, since signals are per-tab.

## Broadcast Scopes

- `admin:import` — fired per file during import; only admin tabs subscribe via `$c->addScope('admin:import')`
- `rrd:live` — fired once when import completes; all tabs receive a fresh full render with real data

The `rrd:live` broadcast at import completion resets the cache naturally because the 10-second window will have elapsed (import takes minutes).
