# nfsen-ng — Agent Instructions

nfsen-ng is a modern web-based NetFlow analyzer (nfsen replacement).
**Stack:** PHP 8.4 + OpenSwoole, php-via (coroutine HTTP server), Datastar (SSE hypermedia), Twig templates, Dygraphs, RRD/VictoriaMetrics.

## Dev Stack

```bash
# Start development environment (port 8080, source mounted, auto-reload)
docker compose -f deploy/docker-compose.dev.yml up -d
docker compose -f deploy/docker-compose.dev.yml logs -f nfsen

# No restart needed for PHP/JS/CSS/.twig changes — entr watches mounted files
```

## Useful Commands

```bash
composer install        # Install PHP deps
composer test           # Run Pest tests
composer test-phpstan   # Static analysis (level 5)
composer fix            # Auto-format PHP
composer before-commit  # fix + phpstan

pnpm install            # Install JS deps (copies datastar.js to frontend/js/)
pnpm run lint           # ESLint frontend/
pnpm run format         # Prettier frontend/
```

## Architecture

```
browser GET /    → page() closure in backend/app.php (signals + action registration)
browser GET /_sse → SSE connection (php-via keeps coroutine alive)
user interaction → @post(action_id) → action closure runs → $c->sync() → full-page re-render pushed over SSE
nfcapd file change → inotify → Import → RRD update → $app->broadcast('rrd:live') → all tabs re-render
```

Key files:
- [backend/app.php](backend/app.php) — entry point: all signals, actions, views defined here
- [backend/common/](backend/common/) — Config, Debug, Import, ImportDaemon, Table, Misc
- [backend/datasources/Rrd.php](backend/datasources/Rrd.php) — primary datasource (default)
- [backend/templates/](backend/templates/) — Twig templates (layout + partials)
- [frontend/js/components/](frontend/js/components/) — Web Components (nfsen-chart, nfsen-table, etc.)

## Signal Conventions (CRITICAL)

php-via `Signal::setValue()` stores values natively. `getValue()` returns the original value — arrays as arrays, strings as strings.

```php
// ✅ Correct — getValue() returns the native type
$sources = $graphSources->array(); // returns array

// ✅ Scalar helpers still work
$display = $graphDisplay->string();
```

- Scalar signals (string/int/bool): use `->string()`, `->int()`, `->bool()` helpers directly
- Array signals: use `->array()` helper
- Client-writable: `$c->signal($default, 'name', clientWritable: true)`
- Server-owned (read-only for browser): omit `clientWritable`

## Datastar Template Syntax

**In Twig templates** ([backend/templates/](backend/templates/)):

```twig
{# ✅ Correct signal reference in data-* attributes #}
data-show="$graph_display == 'sources'"
data-class:loading="$flows._indicator"

{# ❌ Invalid — curly braces not valid in data-* #}
data-show="${graph_display == 'sources'}"

{# Signal binding (two-way) #}
{{ bind(signalObject) }}

{# Signal ID for expressions — use .id() #}
data-computed:graph._config="{{ '{' }} sources: ${{ graphSources.id() }} {{ '}' }}"

{# Action trigger #}
data-on:click="@post('{{ action_refreshGraphs }}')"
```

- Prefix `_` → client-local signal (never posted back to server, e.g. `$_currentView`)
- `data-indicator:flows._indicator` → auto-manages a loading boolean signal

## Adding an Action

All actions are closures in `backend/app.php`:

```php
$myAction = $c->action(function (Context $c) use ($signal1, &$htmlVar): void {
    $val = $signal1->array(); // array if signal holds an array
    // ... do work ...
    $signal1->setValue($newValue, broadcast: false);
    $htmlVar = '<p>result</p>';
    $c->sync(); // re-renders full page, pushes via SSE
}, 'my-action');
```

Trigger from Twig: `data-on:click="@post('{{ action_myAction }}')"`.

## JS: Parsing Datastar Signal Values

With native signal storage, array signals arrive as real JS arrays on the client. Code that reads them from `data-chart-config` attributes (HTML strings) still needs to parse, so use a defensive helper:

```js
const parse = (v) => Array.isArray(v) ? v : JSON.parse(v);
const items = parse(config.sources); // config from data-chart-config attr (string); signal expr already a JS array
```

## Dates and Timezones

The container runs `TZ=UTC`. To display timestamps in the user's local timezone, store them as Unix epoch on the server and format client-side:

```php
// server (app.php)
$graphLastUpdate->setValue(time(), broadcast: false); // Unix epoch int
```

```twig
{# Twig template — format in browser local time #}
<span data-text="new Date(${{ signal.id() }} * 1000).toLocaleString('sv')"></span>
{# 'sv' locale produces ISO-like YYYY-MM-DD HH:MM:SS #}
```

## Testing

Tests live in [tests/](tests/) using Pest PHP. Follow existing patterns in [tests/Unit/](tests/Unit/).

```bash
composer test                  # all tests
composer test-coverage         # coverage
docker compose exec nfsen pest # inside container
```

## Common Pitfalls

- **Signal arrays**: use `->array()` helper, not `->getValue()`
- **Twig data-* syntax**: `$signalName` not `${signalName}` 
- **`$c->sync()`** sends the full rendered page — Datastar diffs it client-side
- **Broadcast scope**: Only contexts that called `$c->addScope('rrd:live')` receive broadcasts
- **Config**: `Config::$cfg` is empty until `Config::initialize()` runs — don't read config at module load
- **nfcapd path structure**: `<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
- **Import daemon**: Not coroutine-safe — runs in a dedicated background process via `cli.php start`
- **`{{ bind() }}` in event handlers**: `{{ bind(signal) }}` expands to `data-bind="hash"` (an HTML attribute). Using it inside a `data-on:*` JS expression generates invalid JS (e.g. `data - bind = "…"`) and silently breaks the entire handler. Use `${{ signal.id() }}` for direct signal assignment inside event expressions.
