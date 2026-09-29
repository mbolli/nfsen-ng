# nfsen-ng: Agent Instructions

nfsen-ng is a web-based NetFlow analyser (an NfSen replacement).
**Stack:** PHP 8.4 + OpenSwoole, php-via (coroutine HTTP server), Datastar (SSE hypermedia), Twig templates,
Apache ECharts, RRD/VictoriaMetrics for time series, SQLite for everything else.

## Dev Stack

```bash
# Start development environment (port 8080, source mounted, auto-reload)
docker compose -f deploy/docker-compose.dev.yml up -d
docker compose -f deploy/docker-compose.dev.yml logs -f nfsen

# No restart needed for PHP/JS/CSS/.twig changes: entr restarts the server on every change
```

The dev state (preferences, alert rules, `nfsen-ng.sqlite`) lives in `backend/settings/` and is gitignored.

## Useful Commands

```bash
composer install        # Install PHP deps (php-via comes from /develop/php-via for now, see below)
composer test           # Run Pest tests (run them in the app image, see Testing)
composer test-phpstan   # Static analysis (level 8, set in phpstan.neon)
composer fix            # Auto-format PHP
composer before-commit  # fix + phpstan

pnpm install            # Install JS deps (copies datastar.js and echarts into frontend/js/)
pnpm run lint           # Biome lint of frontend/js/components and frontend/css
pnpm run format         # Biome format (write), same paths
pnpm run test-e2e       # Browser suite against a running instance (BASE, CHROME)
```

**Always run `composer before-commit` after a set of PHP changes and fix any reported errors before committing.**

Until php-via 0.13.0 is published, `composer.json` takes `mbolli/php-via` from the local git repository at
`/develop/php-via` (`dev-master as 0.13.0`), so Composer needs that path, also inside a container. Once it is
out, require `^0.13.0`, drop the `repositories` entry and run `composer update mbolli/php-via`.

## Architecture

```
browser GET /           → app.php page('/'): Shell, shell modules and pages declare signals and register actions,
                          then Shell::render() renders the shell and the ACTIVE page only
browser GET /_sse       → SSE connection (php-via keeps a coroutine per tab)
hash change (#/flows)   → nfsen-router.js sets $page at once, then posts `navigate`; the server renders that page
user interaction        → @post(action url) → action closure → $c->sync() → patch pushed over SSE
nfcapd file             → inotify → Import → datasource write, top-N queue, alert evaluation → broadcast('rrd:live')
```

Pages are routed by URL hash (`#/overview`, `#/talkers`, `#/flows`, `#/conversations`, `#/alerts`, `#/health`,
`#/settings`); `page` is a server-known, client-writable TAB signal. There is one server route.

### Backend layout

- [backend/app.php](backend/app.php): server config and the one `page('/')` that loops over `PageRegistry`.
- [backend/pages/](backend/pages/) (`mbolli\nfsen_ng\pages`): the UI composition.
  - `Page` interface: `id()`, `title()`, `lede()`, `icon()`, `group()`, `signals()`, `register()`, `viewData()`.
    One class per page: `OverviewPage`, `TalkersPage`, `FlowsPage`, `ConversationsPage`, `AlertsPage`,
    `HealthPage`, `SettingsPage`.
  - `ShellModule` interface: `RangeControls` (controls bar, global range/sources/protocol/unit/profile),
    `TrafficGraph` (the persistent graph), `QueryKit` (filter validation, estimates), `FilterDrawer`.
  - `PageRegistry` (order, legacy view ids, query kind → page), `Shell` (render, footer status, modal),
    `PageStates` + `state/` (per-tab results in memory), `Revival` (results kept across a context revival).
- [backend/actions/](backend/actions/): action closures per area, registered from a page's or module's
  `register()`. `QueryRunner` runs every capture-file query (progress, Kill, recorded timings).
- [backend/query/](backend/query/): transport-agnostic queries (`StatsQuery`, `FlowsQuery`, `MatrixQuery`,
  `TopNQuery`, `FilterComposer`, `StatisticCatalog`, `QueryEstimator`, ...), shared with the MCP tools.
- [backend/store/](backend/store/) (`mbolli\nfsen_ng\store`): the SQLite store (`Database`, `Migrator`,
  `migrations/`, repositories for top-N, saved filters, alert events, query runs).
- [backend/common/](backend/common/): Config, Settings, EnvRegistry, HealthChecker, AlertManager, ImportDaemon,
  TopNCollector, Debug/LogRing, Table, ...
- [backend/datasources/](backend/datasources/): `Datasource` and `TotalsProvider`, `Rrd` (default), `VictoriaMetrics`.
- [backend/processor/](backend/processor/): `Nfdump` (the only place that runs nfdump queries), `NfdumpSlots`,
  `FilterValidator` (`nfdump -Z`), `FilteredSeries`.

### Templates and render contract

- [backend/templates/layout.html.twig](backend/templates/layout.html.twig): the document and head scripts.
- `shell/`: sidebar, controls-bar, traffic-graph, page-header, page-skeleton, footer, bottom-tabs, icons.
- `pages/<id>.html.twig`: one template per page. `components/`: filter-field, query-estimate, result-host.
  `drawer/filter-drawer.html.twig`. `partials/`: aggregation-controls, progress-button, ip-info-modal.

Twig runs with `strict_variables`. A render provides `shell` (version, pagesMeta, defaults, status, ...), one key
per shell module (`range`, `graph`, `querykit`, `drawer`), and `pages.<id>` with `active: bool`; plus php-via's
auto-data: every signal by name and every action by its camelCase name. Page templates read `pages.<id>.*` only
while the page is active (the layout includes them only then). `viewData()` runs only for the active page and
must stay cheap: read what actions stored, never run a query or a range-sized SQLite read in a render.

Large results go through result hosts (`components/result-host.html.twig`): sent once per result id,
empty `data-ignore-morph` placeholders afterwards.

### Expensive queries

Anything that reads nfcapd capture files runs only on an explicit user action, with its estimate shown first
(`components/query-estimate.html.twig`, `estimate-query`). It runs through `QueryRunner::run($c, $kind, ...)` with
a query kind (`stats`, `flows`, `conversations`, `graph`, ...). Stored-series reads (RRD/VM) and small SQLite reads
may run automatically. `tests/e2e/no-auto-query.test.mjs` enforces this.

### SQLite store rules

OpenSwoole has no PDO hook: every SQLite call blocks the worker, and all coroutines share one connection.

- Keep statements small and indexed; no query shape that defeats the primary key.
- Never hold a transaction across a yield (nfdump, file I/O, curl, `Coroutine::sleep`, `broadcast`, `sync`).
  Do the external work first, then `Database::transaction()`.
- Long maintenance runs in batches with a pause between them, outside transactions.
- PDO binds floats as TEXT: compare a float with an aggregate as `CAST(? AS REAL)`.
- `Database::shared()` throws `StoreUnavailableException`; every consumer degrades with the reason. Never touch
  the database in a constructor of a long-lived object. Only the server worker migrates; MCP uses
  `Database::inspect()` read-only.

## CSS

No framework. Style elements, not utility classes: a `button` is styled as a button, a table as a table. Where a
class is needed it names a component (`.card`, `.segmented`, `.notice`, `.menu`, `.kpi`), never a declaration.
There is no `.mb-3`, `.muted` or `.text-end` to reach for, and adding one is the wrong move. No inline `style` for
presentation either; an inline custom property that carries data (`style="--share: 42%"`) is fine.

- **Files**: `tokens.css` (design tokens), `ui.css` (reset, elements, shared components), one file per shell part
  (`shell.css`, `controls-bar.css`, `traffic-graph.css`, `query-kit.css`, `drawer.css`), `nfsen-ng.css` (charts,
  result tables, aggregation controls), and `pages/<id>.css` for what only one page needs.
- **Layout** uses three primitives: `.stack` (vertical rhythm), `.cluster` (a wrapping row, `data-justify`),
  `.grid` (auto-fit columns). Prefer them over new one-off flex rules.
- **Colours** come from the semantic tokens (`--surface-2`, `--text-2`, `--border`, `--danger`), never from a
  literal. Surfaces, text, borders and controls are neutral greys; colour marks only status (`[data-level]`) and
  data series (`[data-series]`, the eight slots of `theme-colors.js`). A colour means one thing per page.
- **Themes** are `light-dark()` plus a real `color-scheme` set by `:root[data-theme]`, so a new rule usually needs
  no dark-mode counterpart. `<html data-density="compact">` tightens tables.
- **State** belongs in the DOM: `:checked`, `aria-pressed`, `aria-selected`, `aria-current`, `[data-open]`,
  `[data-level]`. Every selected state also shows as a border, bar or outline, for forced colours.

## Writing

No em or en dashes anywhere (code, comments, UI copy, docs): use a comma, a colon or two sentences. No emoji.
Comments only where the code cannot say it, one or two lines. UI messages are plain text; Twig escapes them.

## Signal Conventions (CRITICAL)

php-via `Signal::setValue()` stores values natively. `getValue()` returns the original value: arrays as arrays,
strings as strings.

```php
// Correct: getValue() returns the native type
$sources = $graphSources->array(); // returns array

// Scalar helpers still work
$display = $graphDisplay->string();
```

- Scalar signals (string/int/bool): use `->string()`, `->int()`, `->bool()` helpers directly
- Array signals: use `->array()` helper
- Every `$c->signal()` says `clientWritable: true` or `false`; leaving it out means `true` for a TAB signal
- `clientWritable: true`: the browser writes it (`data-bind`, an expression) and its value is taken on every
  action; normalise it on read, the client can post anything. A server-set signal whose browser copy carries it
  across a context revival is `true` too (`range_live`, `range_preset`, `flows_graph_key`)
- `clientWritable: false`: server-owned. php-via ignores the posted copy on actions and revivals and sends the
  server's value back, so the server must rebuild it after a revival (first render, `restoreSignals()`).
  `query_running` is one, so a stale browser copy cannot start a second query in the tab
- Global signals (every page): `page`, `datestart`, `dateend`, `range_preset`, `range_live`, `graph_sources`,
  `protocol`, `graph_trafficUnit`, `selected_profile`

## Datastar Template Syntax

**In Twig templates** ([backend/templates/](backend/templates/)):

```twig
{# Server signal: the wire id is name + per-context hash, so always use .id() #}
data-show="${{ graph_display.id() }} == 'sources'"

{# Client-local `_` signal seeded by data-signals in markup: the bare name works #}
data-show="$_flows_tab == 'raw'"

{# Server-owned `_` signal (declared with $c->signal()): hashed like any other, so .id() #}
data-attr:hidden="!${{ _conv_stale.id() }}"

{# Wrong: a bare server signal name matches nothing and fails silently #}
data-show="$graph_display == 'sources'"

{# Signal binding (two-way) #}
{{ bind(signalObject) }}

{# Signal ID for expressions: use .id() #}
data-computed:graph._config="{{ '{' }} sources: ${{ graph_sources.id() }} {{ '}' }}"

{# Action trigger: the action's camelCase name, and its url() #}
data-on:click="@post('{{ refreshGraphs.url() }}')"
data-on:click="@post('{{ deleteAlert.url() }}?id={{ rule.id|url_encode }}')"
```

- Prefix `_` → never posted back to the server. Only the ones the browser seeds itself (`data-signals` in a
  template, e.g. `$_flows_tab`, `$_sidebarCollapsed`) are used by bare name; server-owned `_` signals declared
  with `$c->signal()` (`_stats_rows`, `_conv_stale`, `_flt_<target>`, `_est_<target>`, `_drawer_notice`) need `.id()`
- `data-indicator:graph._connecting` → auto-manages a loading boolean signal
- Attributes JavaScript sets on server markup go into `data-preserve-attr`, or the next morph resets them
- ECharts canvases sit in a `data-ignore-morph data-ignore` container

## Adding an Action

Actions belong to a page (or a shell module) and are registered from its `register()`:

```php
// backend/pages/ConversationsPage.php
public static function signals(Context $c): void {
    $c->signal('ip', 'conv_group', clientWritable: true);
    $c->signal(false, '_conv_stale', clientWritable: false);
}

public static function register(Context $c, Via $app, PageStates $states): void {
    ConversationActions::register($c, $states);
}

// backend/actions/ConversationActions.php
public static function register(Context $c, PageStates $states): void {
    $conversations = $states->conversations;
    $c->action(static function (Context $c) use ($conversations): void {
        self::run($c, $conversations);
    }, 'conversations-run');
}

private static function run(Context $c, ConversationsState $state): void {
    $p = self::params($c); // the signals, normalised: the client can post anything
    try {
        $query = new MatrixQuery(/* from $p */);
        // Syncs at the start and at the end, runs the closure in a coroutine with progress and Kill,
        // and records the run for the estimates.
        QueryRunner::run($c, 'conversations', static fn (): int => $query->totalBytes(), 'Starting nfdump…',
            static function () use ($query, $state, $p): void { /* run nfdump, store the result in $state */ });
    } catch (\Throwable $e) {
        // Shown in the tab; php-via would only log it and answer 500
        self::storeFailure($state, $e, false); // plain-text notice, the command in its own code block
        $c->sync();                            // re-renders the tab, pushes the patch over SSE
    }
}
```

Trigger from Twig: `data-on:click="@post('{{ conversationsRun.url() }}')"`. The URL is `<basePath>_action/<name>`
in every tab (the `via_ctx` signal picks the context), but always go through `url()` so the base path is right. An
action that reads no capture file ends with `$c->sync()` (or `$c->syncSignals()` when only signals changed).
Document a new action in `book/src/api.md`.

## JS: Parsing Datastar Signal Values

With native signal storage, array signals arrive as real JS arrays on the client. Code that reads them from
`data-chart-config` attributes (HTML strings) still needs to parse, so use a defensive helper:

```js
const parse = (v) => Array.isArray(v) ? v : JSON.parse(v);
const items = parse(config.sources); // config from data-chart-config attr (string); signal expr already a JS array
```

## Dates and Timezones

The container runs `TZ=UTC`. Store timestamps as Unix epochs and format them in the browser, in the display
timezone the user chose (browser or capture timezone):

```twig
<time data-text="new Date(${{ signal.id() }} * 1000).toLocaleString(undefined, tzOptions(${{ displayTz.id() }}, ${{ nfcapdTz.id() }}))"></time>
```

nfcapd file names are parsed in `NFCAPD_TZ` (`Config::nfcapdTimezone()`).

## Testing

Tests live in [tests/](tests/) using Pest PHP. Follow existing patterns in [tests/Unit/](tests/Unit/). Run them in
the app image; host PHP has no `rrd` extension and silently skips those tests:

```bash
docker run --rm --entrypoint php -v "$PWD":/app -w /app deploy-nfsen vendor/bin/pest
docker run --rm --entrypoint php -v "$PWD":/app -w /app deploy-nfsen -d memory_limit=1G \
    vendor/bin/phpstan analyse backend -a backend/settings/settings.php --memory-limit=1G
```

- SQLite in tests: `Database::useShared(Database::open(':memory:'))`, and `Database::resetShared()` afterwards.
- nfdump in tests: `tests/Support/FakeProcessor.php`, and the fake binaries in `tests/Support/bin/` (keep the exec bit).

Browser tests (`tests/e2e/`, raw CDP, no Playwright dependency) drive a running instance:

```bash
CHROME=/usr/bin/chromium BASE=http://localhost:8080 node tests/e2e/run.mjs   # all files
node tests/e2e/flows.test.mjs                                                # one file
E2E_SKIP_MUTATING=1 node tests/e2e/run.mjs                                   # leave persisted state alone
```

`tests/e2e/lib/cdp.mjs` has the helpers (`withPage`, `gotoPage`, `runQuery`, `setRangePreset`, `signalValue`, ...).
Only the active page is rendered in full: wait for `#page-<id>[data-ready]`. The dev app restarts on every file
change; rerun a file that failed on a lost context.

The book's screenshots come from `book/_capture.mjs` (same driver, needs ImageMagick):

```bash
CHROME=/usr/bin/chromium BASE=http://localhost:8080 node book/_capture.mjs   # OUT=/tmp/shots to check first
```

## Common Pitfalls

- **Signal arrays**: use `->array()` helper, not `->getValue()`
- **Twig data-* syntax**: every signal declared with `$c->signal()`, `_` prefixed or not, as `${{ signal.id() }}`; bare `$name`
  only for `_` signals seeded by `data-signals` in markup; never `${...}` around an expression
- **`$c->sync()`** sends the rendered tab (shell and active page); Datastar diffs it client-side
- **Broadcast scope**: only contexts that called `$c->addScope('rrd:live')` receive broadcasts
- **Config**: `Config::$cfg` is empty until `Config::initialize()` runs; don't read config at module load
- **nfcapd path structure**: `<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
- **Import daemon**: embedded in `app.php`; `AppStartup::boot()` (from `onStart()`) runs the catch-up in a coroutine
  and polls inotify every second with `setInterval` (`ImportDaemon::pollOnce()`)
- **Shutdown**: `AppStartup::shutdown()` (from `onShutdown()`, on every stop) stops the daemons, the top-N
  collector and the alert checks, refuses new interactive nfdump slots and kills the runs of tabs and MCP calls.
  A new loop or coroutine that can run for more than a moment ends there or checks `$app->isShuttingDown()`, or it
  holds every stop for `max_wait_time`
- **`{{ bind() }}` in event handlers**: `{{ bind(signal) }}` expands to `data-bind="hash"` (an HTML attribute).
  Using it inside a `data-on:*` JS expression generates invalid JS and silently breaks the entire handler. Use
  `${{ signal.id() }}` for direct signal assignment inside event expressions.
- **Live window**: `datestart`/`dateend` move every render of an analysis page. An effect that posts must key on
  `Math.floor(${{ datestart.id() }} / 300)` through `window.nfsenChanged(el, ...)`, or it fires on every render.
- **nfdump errors quote the filter**: render every nfdump message as escaped text, never `|raw`.
