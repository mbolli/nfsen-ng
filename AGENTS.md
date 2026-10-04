# nfsen-ng: Agent Instructions

nfsen-ng is a web-based NetFlow analyser (an NfSen replacement).
**Stack:** PHP 8.4 + OpenSwoole, php-via 0.13 (coroutine HTTP server), Datastar 1.0.4 with Rocket (SSE hypermedia
and components), Twig templates, Apache ECharts, nfdump 1.7.10, RRD/VictoriaMetrics for time series, SQLite for
everything else.

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
composer install        # Install PHP deps
composer test           # Run Pest tests (run them in the app image, see Testing)
composer test-phpstan   # Static analysis (level 8, set in phpstan.neon)
composer fix            # Auto-format PHP
composer before-commit  # fix + phpstan

pnpm install            # Install JS deps; rebuilds frontend/js/datastar-rocket.js, copies ECharts and the licences
pnpm run lint           # Biome lint of frontend/js/components and frontend/css
pnpm run format         # Biome format (write), same paths
pnpm run test-e2e       # Browser suite against a running instance (BASE, CHROME)
```

**Always run `composer before-commit` after a set of PHP changes and fix any reported errors before committing.**

`pnpm install`'s postinstall runs `sh scripts/vendor-rocket.sh --if-tools`: it rebuilds
`frontend/js/datastar-rocket.js` from `node_modules/datastar` and the patches in `patches/rocket/`, offline, and
checks the result byte for byte against the sha256 in `patches/rocket/rocket.lock.json`. When esbuild or patch(1) is
missing, or the build differs (as right after a pin bump), it warns and keeps the committed bundle. Then it copies
`datastar.LICENSE.md`, `echarts.min.js`, `echarts.LICENSE` and `echarts.NOTICE` into `frontend/js/`. The source map
embeds the TypeScript sources, so DevTools shows them.

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
empty `data-ignore-morph` placeholders afterwards. The Flows list is such a host of its own (`FlowRows`), filled
by windows: its requests (`flows-window`, `flows-sort`, `flows-columns`, `flows-export`) post only `via_ctx` and
read everything else from `FlowsState`, so they never depend on the tab's signals.

### Expensive queries

Anything that reads nfcapd capture files runs only on an explicit user action, with its estimate shown first
(`components/query-estimate.html.twig`, `estimate-query`). It runs through `QueryRunner::run($c, $kind, ...)` with
a query kind (`stats`, `flows`, `conversations`, `graph`, ...). Stored-series reads (RRD/VM) and small SQLite reads
may run automatically. `tests/e2e/no-auto-query.test.mjs` enforces this.

### nfdump processes

`NfdumpSlots` hands out one slot per nfdump process, up to `NFSEN_NFDUMP_MAX_PROCESSES` (`auto`: a third of
`CpuBudget::cores()`, 2 to 8). Slots come in two classes: `INTERACTIVE` (a user waits; may take every free slot,
waits up to 30 s) and `BACKGROUND` (import, top-N collector, live alert checks; at most half the slots, only while
one stays free, never while a user query waits; waits up to 10 minutes).

- `Nfdump::execute()` takes a slot of the calling scope's class. Background work wraps its runs in
  `NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, $fn, budget: $seconds)`; the budget limits all slot waits of that
  scope together (live alert evaluation: `AlertManager::LIVE_SLOT_BUDGET_SECONDS`, 60 s). The scope is per
  coroutine and not inherited by child coroutines.
- Parallel work takes a pool with `$n = NfdumpSlots::acquireMany($want, $class, $wait)` (1 to `$want` slots),
  runs each child's nfdump inside `NfdumpSlots::runInHeldSlot($class, $fn)` and gives slots back with
  `NfdumpSlots::release($class, $n)`. `FilteredSeries` (one bin per slot) and `PartitionPlanner` (time slices of
  one statistic, merged exactly by `PartitionMerge`) work this way.
- Every concurrent run gets its own `Nfdump` instance: `Nfdump::getInstance()` is shared, and a `reset()` by
  another coroutine during a slot wait would change the run's options.
- Every run passes `-W` (`NFSEN_NFDUMP_WORKERS`, default 2) after the caller's options, from nfdump 1.7.3 on.

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
class is needed it names a component (`.card`, `.segmented`, `.notice`, `.popover-list`, `.kpi`), never a declaration.
There is no `.mb-3`, `.muted` or `.text-end` to reach for, and adding one is the wrong move. No inline `style` for
presentation either; an inline custom property that carries data (`style="--share: 42%"`) is fine.

- **Files**: `tokens.css` (design tokens), `starbase.css` (Starbase's `--sb-*` tokens mapped onto ours, see
  Starbase components), `ui.css` (reset, elements, shared components), one file per shell part (`shell.css`,
  `controls-bar.css`, `traffic-graph.css`, `query-kit.css`, `drawer.css`), `nfsen-ng.css` (charts, result tables,
  aggregation controls), and `pages/<id>.css` for what only one page needs.
- **Layout** uses three primitives: `.stack` (vertical rhythm), `.cluster` (a wrapping row, `data-justify`),
  `.grid` (auto-fit columns). Prefer them over new one-off flex rules.
- **Colours** come from the semantic tokens (`--surface-2`, `--text-2`, `--border`, `--danger`), never from a
  literal. Surfaces, text, borders and controls are neutral greys; colour marks only status (`[data-level]`) and
  data series (`[data-series]`, the eight slots of `theme-colors.js`). A colour means one thing per page.
- **Themes** are `light-dark()` plus a real `color-scheme` set by `:root[data-theme]`, so a new rule usually needs
  no dark-mode counterpart. `<html data-density="compact">` tightens tables.
- **State** belongs in the DOM: `:checked`, `aria-pressed`, `aria-selected`, `aria-current`, `aria-expanded`,
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

## Front-end elements (Rocket)

The page loads `frontend/js/datastar-rocket.js`: Datastar 1.0.4 with its Rocket component system (beta.2) and the
patches in `patches/rocket/` (see Bumping Datastar and Rocket). It is the only engine on the page; its URL in the
`<script>` tag and in the import map is the same string, `?v=` included, since a second URL would load a second
engine. nfsen-ng's own elements are Rocket elements of three shapes, none with `render`:

| Shape | Definition | Elements |
|---|---|---|
| A, enhancer | open shadow root holding only a `<slot>` that `setup` appends; the server markup stays in the light DOM and the logic works on it | `nfsen-chart`, `nfsen-table`, `nfsen-sankey`, `nfsen-matrix` |
| B, owned content | light mode; `setup` builds the DOM with the DOM API, inside a `data-ignore-morph` container | `nfsen-toast` |
| C, controller | light mode, no children; props on plain attributes, targets found by id (`for="drawerFilterTextarea"`) | `nfsen-filter-editor` |

None of them uses `render`: their content is server markup, which stays in the light DOM, so `ui.css`, the page
CSS and the tests' selectors reach it. Components with `render`, such as the vendored Starbase components, are safe
with the patched bundle: 0008 keeps a first render during a server morph from clearing the outer morph's id maps
(Datastar issue #1209: elements with ids that the outer morph moved later were recreated and lost their client
state), and 0009 lets a light component render inside a `data-ignore-morph` container.

Rules, checked in part by `tests/e2e/rocket.test.mjs` (and K1 in the templates' popovers by
`tests/Unit/PopoverMarkupTest.php`):

- K1. No Datastar plugin attribute (`data-on`, `data-text`, `data-bind:*`, `data-ref`, `data-signals`, ...) in the light
  DOM of a Rocket host, except inside a `data-ignore` subtree: Rocket rescopes them to the component. It renames
  `data-signals`, `data-ref` and keyed `data-bind:`, `data-computed:` and `data-indicator:` inside `data-ignore` as
  well, so those stay out of it. The exception is `sb-popover`, whose slotted trigger and panel may carry `data-on:*`,
  `data-attr:*`, `data-class:*`, `data-style:*`, `data-effect`, `data-text`, `data-show` and value-form `data-bind` that
  read and write page signals (`$name`, `${{ signal.id() }}`, never `$$`): patch 0011 binds them on first load, and
  Rocket's rewrite of `@name(` to `@dispatchRocket("name",` falls back to the page's action. Keyed signal attributes,
  `data-ref`, `data-init`, `data-persist`, `data-json-signals` and the `data-on-*` plugins stay out of a popover, and no
  `data-*` value inside any Rocket host holds free text from users or nfdump, since that rewrite also changes every `$$`
  and `@word(` in it: such text goes into element content.
- K2. No `data-init` on a Rocket host: it can run twice.
- K3. A light host (shapes B and C) carries no `data-*` attribute from the server or the page; its props use plain
  names (`level`, `message`, `for`).
- K4. Every host attribute the client sets (`data-attr`, a script) is listed in the host's `data-preserve-attr`,
  unless the host sits in a `data-ignore-morph` container; a morph resets the rest and with it the props.
- K5. Props belong to the server: an element never writes its own props. Client state lives in `nfsen/host-state`,
  not in `$$` signals (deleted on disconnect) or closure variables of `setup`.
- K6. Should templates come back, signal attributes use value forms (`data-text="$$x"`), never keyed forms, which a
  host id with uppercase letters corrupts.
- K7. No server or user string goes into a `data-*` value of a Rocket template; use `textContent` or the DOM API.
- K8. Public methods and read-only state are `defineHostProp` entries; an element held in a signal through
  `data-ref` has a `toJSON()` that returns a small plain value.
- K9. A module that defines `window.*` helpers read during Datastar's first pass does not import `'datastar'` and
  loads before the bundle.
- K10. Shared code comes from `nfsen/format`, `nfsen/clipboard`, `nfsen/download`, `nfsen/host-state`,
  `nfsen/chunks`, `nfsen/theme-colors` and `nfsen/tz-utils`; no element keeps its own copy.
- K11. Events keep their names and `bubbles: true` (Rocket's `emit()` adds `composed: true`).
- K12. Each element declares `manifest.events` and documents its props with `.docs({ description })`.
- K13. `render` is safe (patches 0008 and 0009), but nfsen-ng's own elements keep their shapes: a shadow host
  appends its `<slot>` in `setup`, a light host builds its DOM there.
- K14. State that must survive a move lives in `nfsen/host-state`: `hostState(host, create)` and `peekState(host)`.
  `cleanup` calls `whenGone(host, release)`, which after a microtask releases the state of a host no move
  reconnected (disposing what the state holds, such as an ECharts instance), empties it and its shadow root,
  removes its attributes and detaches it. Patch 0010 lets removed hosts be collected; `rocket.test.mjs` checks it.
- K15. `observeProps` handlers never touch the DOM: they run in the middle of a morph, so they queue one
  `queueMicrotask(update)` per burst, which runs after it.

Load order in `layout.html.twig`:

1. The import map: `datastar`, `nfsen/theme-colors`, `nfsen/tz-utils`, `nfsen/format`, `nfsen/clipboard`,
   `nfsen/download`, `nfsen/host-state`, `nfsen/chunks`, each with `?v=`. Production caches static files for a
   year, so a module that another module imports goes through the import map, never a relative import.
2. The inline module that sets `window.tzOptions`.
3. Plain modules whose `window.*` helpers `data-init` and `data-effect` expressions read (K9): `nfsen-router`,
   `alert-template-preview`, `filter-drawer`, `chunks`, `flows-list`.
4. `datastar-rocket.js`, then `datastar-persist.js` (a Datastar plugin).
5. The elements `nfsen-chart`, `nfsen-sankey`, `nfsen-matrix`, `nfsen-table`, `nfsen-toast`,
   `nfsen-filter-editor`, then the plain `nfsen-controls` (tabs and the popover layer that completes `sb-popover`,
   both delegated on `document`) and `clipboard`.
6. The Starbase components whose lock entry says `"load": true`.

A module with a script tag that is also an import-map target (`chunks`, `clipboard`) uses the identical URL, so it
runs once. Tests reach Datastar's store through the import map, `(await import('datastar')).root`, never through a
script URL.

## Starbase components

Components from [Starbase](https://github.com/zweiundeins/starbase) (MIT) are vendored under
`frontend/js/starbase/<slug>@<version>/`, unchanged, with `LICENSE` and `starbase.lock.json`. The version in the
folder name is Starbase's content hash of the folder, so the URL changes with every byte. Never edit a vendored
file: change Starbase first, then move the pin. Three are vendored: `sb-relative-time` (Alerts, Last triggered),
`sb-popover` (every menu: the controls bar's range, start and end, and sources, the Live menu, the theme menu, the tab
bar's More, the Export menus, the Columns pickers and the saved-filter kebab; see Popovers below) and
`sb-virtual-scroll` (the Flows list).

```bash
node scripts/starbase-vendor.mjs check                        # offline: hashes, lock, licence, imports, Datastar banner
node scripts/starbase-vendor.mjs pull --from ../starbase --ref <commit> relative-time   # from a local clone, never the network
node scripts/starbase-vendor.mjs load relative-time on|off    # whether the layout loads it
node scripts/starbase-vendor.mjs remove relative-time
node scripts/starbase-vendor.mjs verify-remote --base https://starbase.zweiundeins.gmbh   # optional, online
```

`pull` copies the committed bytes at the ref (`git archive`), refuses a Starbase whose
`static/vendor/datastar-rocket.js` has another banner than ours, and records that bundle's sha256 and patch set in
the lock. A vendored module may import only `'datastar'` or a file inside its own folder. `StarbaseAssets::modules()`
reads the lock, and the layout loads every `"load": true` entry after the elements; no template names a component.
A pin bump is a commit that touches only `frontend/js/starbase/**` and names the Starbase commit and every version.
Pin from a commit on Starbase's main (`--from ../starbase --ref <commit>`): a feature nfsen-ng needs goes into
Starbase first.

`frontend/css/starbase.css` maps every `--sb-*` token onto nfsen-ng's tokens (`--sb-notch: 0` for smooth corners), so
one `:root` block serves light and dark. A package that adopts a component:

- maps every `var(--sb-*)` the component reads, or lists it as a size knob (`StarbaseBridgeTest` fails otherwise);
- overrides `--sb-*` on the host only for a component without slots, and uses `::part()` otherwise, since tokens set
  on a host inherit into slotted children (slotting: alert, button, card, details, dropdown, echarts, modal, qr-code,
  tabs, tooltip);
- relies on page `::part()` rules winning over the component's own without `!important`; an outer shadow or outline
  added through a part also needs `clip-path: none`, since the notch `clip-path` stays a full rectangle with
  `--sb-notch: 0`;
- adds no sheet to `shadowRoot.adoptedStyleSheets`: such sheets survive re-renders but target private class names;
- gives a token that the component uses in two roles a per-component override: modal and drawer paint their
  translucent backdrop with `--sb-surface-overlay`, `sb-echarts` its tooltip, so an adopter sets
  `sb-echarts { --sb-surface-overlay: var(--chart-tooltip-bg); }` (the chart reads it on the host, and its slot holds
  only a plain fallback);
- puts the component's rules into `starbase.css` under a comment naming the tag, and checks them in light, dark and
  forced colours in its e2e file;
- makes selected, checked, current and invalid states differ from the default in more than font weight (a
  background and an inset bar, a `Highlight` outline under forced colours);
- overrides `--sb-warn` and `--sb-danger` with `--warning-emphasis` and `--danger-emphasis` where the component uses
  them as text colour (4.5:1);
- checks the component under `data-density="compact"`: Starbase hard-codes control heights.

### Popovers

Every floating panel under a button is an `sb-popover`, built to one contract (`PopoverMarkupTest` and
`rocket.test.mjs` check K1 in its markup, `ui-controls.test.mjs` its behaviour). There is no other menu: the
`.menu` lists and the `role=menu` action menu are gone, and so is the code in `nfsen-controls` that drove them.

- Host: `<sb-popover id="..." class="<component>" label="<name of the panel>" placement="...">` with a stable `id`,
  so the morph matches it (a replaced menu's host takes that menu's id). Never `open`, `mode`, `arrow` or `name`; no
  `data-ref` or `data-init`; a host attribute set by `data-attr` is in the host's `data-preserve-attr`.
- Trigger: one slotted `<button type="button" slot="trigger" class="menu-toggle" data-size="sm"
  data-preserve-attr="aria-expanded aria-haspopup">`, so the default trigger never renders. The shell's triggers
  (`#themeMenu`, `#tabbarMoreMenu`) take their size from `sidebar-action` and `tabbar-item` instead of `data-size`.
  `sb-popover` writes `aria-haspopup="dialog"` and `aria-expanded`, the server neither. Only the Columns trigger has
  `aria-controls`.
- Panel: K1's popover exception applies. Commands go into a `<ul class="popover-list">` (a `div.popover-list` for
  mixed content) of `button.menu-item` or `a.menu-item[href]`, separators are `li.menu-sep[role=separator]`, current
  choices keep `aria-pressed` or `aria-current` with the check mark. No `role=menu`, `menuitem`, `role=none` or
  `tabindex="-1"`. A list keeps the id of the menu list it replaces.
- CSS: component rules in `starbase.css` under `/* sb-popover */`, item rules as `.popover-list` in `ui.css`, a page's
  placement and panel size (`::part(panel)`) in its page CSS.
- Behaviour: click, Enter or Space opens the panel and moves focus to its `[autofocus]` or first focusable element; Tab
  and Shift+Tab move through the panel and on out of it; Escape closes the innermost popover and returns focus to
  its trigger, leaving a modal dialog around it open; an outside press closes it; a morph keeps it open with focus in
  place. The popover layer of `nfsen-controls` adds the rest: choosing a `button` or `a[href]` closes it after the
  item's handler and returns focus (`data-menu-keep`, `aria-disabled="true"`, `preventDefault()` or `stopPropagation()`
  in the handler keep it open; fields, checkboxes and switches never close it); focus leaving the popover closes it;
  ArrowDown or ArrowUp on the trigger opens on the first or last `.popover-list` item, and the arrows, Home and End move
  among the items and wrap; a popover that opens closes the others, except one it sits in or holds; a modal dialog
  opening closes every popover outside it. A page switch leaves focus in an open popover that stays on screen (the
  controls bar, the sidebar, the tab bar); `nfsen-router` closes one the switch hides (Live on the way to Health), so
  it does not come back open with its page.

`ui-controls.test.mjs` tests that layer on fixture popovers in `#client-root` (`popoverKeys`, `popoverStyles`,
`popoverChoose`, `popoverLayers`, `popoverUnderModal`, `popoverMove`, `popoverSyncAround`, `popoverPageSwitch`,
`popoverForcedColors`, `popoverPhone`, `popoverBeforeUpgrade`). The files of the pages and the shell test their own
popovers (`controls`, `graphs`, `smoke`, `mobile`, `drawer`, `flows`, `talkers`, `conversations`, `columns`), each
with a sync while one is open (`page.syncNow(id)`, in `graphs` the Overview live tick). Until `sb-popover` is
defined, `starbase.css` shows only a host's trigger.

## Bumping Datastar and Rocket

In this order:

1. Set the pin in `package.json` (`"datastar": "github:starfederation/datastar#v1.0.x"`), run `pnpm install` (it
   warns that the build differs and keeps the committed bundle) and commit what it writes.
2. Take the patch set that matches the release from Starbase, which drops a patch once a release contains it:
   `sh scripts/vendor-rocket.sh --from ../starbase --ref <commit>`. It rebuilds the bundle and rewrites
   `patches/rocket/`. With no patch left, load the release's `bundles/datastar-rocket.js` directly and delete the
   folder.
3. Update the banner line in `tests/Unit/FrontendAssetsTest.php`.
4. Read `git diff <old> <new> -- library/src/rocket library/src/engine library/src/plugins/watchers/patchElements.ts`
   in a Datastar clone and note what changed.
5. Rerun the pantry repro against the new bundle: one morph parks `<section><span id="z">stale z</span></section>`
   in Datastar's pantry `<div hidden>` and takes only part of it back, then a full-document morph follows. With
   1.0.2 to 1.0.4 the detached pantry still holds the section and the second morph reuses the stale `#z`. While it
   does, keep the observer in `nfsen-router.js` that empties the pantry after each morph.
6. Rerun the Rocket repro pages against the new bundle, in Starbase's `docs/repro/`: `rocket-morph-reentrancy` and
   `rocket-morph-ids` (#1209, patch 0008), `rocket-render-ignore-morph` (light `render`, 0009),
   `rocket-removed-elements` (K14, 0010), `rocket-queued-definition-children` (K1, 0011) and
   `rocket-observer-rescan` (0012, the reorder cost that `nfsen-table` avoids by emptying its body first). A move
   that sets a host up again has no page: patch 0002 fixes it. Without that branch, `/tmp/rkt-review/exp.js` on the development host holds one
   case per page, e1 to e12, run by `run.mjs` next to it, as long as that folder exists. Relax K13, K14 or K15
   only in a change of its own. A bundle that fails `rocket-queued-definition-children` ends K1's popover
   exception, and every popover moves its behaviour onto the host: no plugin attribute in its light DOM, `data-on`
   handlers on the host that act on `evt.target.closest('[data-...]')`, and a host `data-effect` that writes into
   the children. That effect runs twice at load, so it is idempotent and never posts; an attribute it writes is in
   the child's `data-preserve-attr`, and text it writes goes into a `data-ignore-morph` span.
7. Update the plugin list of K1 in `tests/e2e/rocket.test.mjs` and `tests/Unit/PopoverMarkupTest.php` if
   `library/src/plugins/attributes` changed. The heap cases of `rocket.test.mjs` assert that every removed host is
   collected, so a bundle without patch 0010's fix fails them.
8. Move the Starbase pin to a commit whose `static/vendor/datastar-rocket.js` has the same banner (`pull` refuses
   otherwise).
9. Run `node scripts/starbase-vendor.mjs check`, `sh scripts/vendor-rocket.sh --check`,
   `node tests/e2e/run.mjs rocket starbase-bridge` and then the whole suite.

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
- `tests/Helpers.php` is loaded before every test file (`makeCaptureTree()`, `removeTree()`); a helper two files
  need goes there, so each file also runs on its own.
- Test doubles are anonymous classes: a named class at the top of a test file gives *Class not found* once Pest
  runs several files, and php-cs-fixer renames it after the file.
- OpenSwoole's `Coroutine::run()` hooks file functions, so `mkdir` and `file_put_contents` yield inside it. A
  timing-sensitive test creates its files before it starts the coroutine it races with.

Browser tests (`tests/e2e/`, raw CDP, no Playwright dependency) drive a running instance:

```bash
CHROME=/usr/bin/chromium BASE=http://localhost:8080 node tests/e2e/run.mjs   # all files
node tests/e2e/run.mjs flows rocket                                          # the named files
node tests/e2e/flows.test.mjs                                                # one file on its own
E2E_SKIP_MUTATING=1 node tests/e2e/run.mjs                                   # leave persisted state alone
```

`tests/e2e/lib/cdp.mjs` has the helpers (`withPage`, `gotoPage`, `runQuery`, `setRangePreset`, `signalValue`, ...).
Only the active page is rendered in full: wait for `#page-<id>[data-ready]`. The dev app restarts on every file
change, and php-via then reloads every tab: the next call of a test fails at once with `AppReloadedError` (*THE
APP RELOADED the page mid-test*), and the file can be rerun. A navigation the test causes itself is announced
with `page.expectNavigation()`. `run.mjs` fails a file after `E2E_FILE_TIMEOUT` seconds (900) and kills its
browsers; Chromium never outlives the Node process, and its profile directory is removed.

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
- **Import broadcasts**: send `admin:import` and the import's `rrd:live` through `ImportDaemon::broadcast($app,
  $scope)`, which renders at most one every 250 ms and always sends the last state; `now: true` for the start,
  cancel and end of a pass. One render per imported file for every tab ran the worker out of memory
- **Memory**: one worker holds every tab and runs with `memory_limit` 512M (images, systemd unit). Never read an
  unbounded nfdump output whole: let nfdump aggregate (`-s ... -n`, `-c`), as the alert checks' `-s proto` does
- **Config**: `Config::$cfg` is empty until `Config::initialize()` runs; don't read config at module load
- **nfcapd path structure**: `<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
- **Import daemon**: embedded in `app.php`; `AppStartup::boot()` (from `onWorkerStart()`) runs the catch-up in a coroutine
  and polls inotify every second with `setInterval` (`ImportDaemon::pollOnce()`)
- **Shutdown**: `AppStartup::shutdown()` (from `onWorkerStop()`, on every stop) stops the event-loop lag probe, the
  deferred import broadcasts, the daemons, the top-N collector and the alert checks, refuses new interactive nfdump
  slots and kills the runs of tabs and MCP calls.
  A new loop or coroutine that can run for more than a moment ends there or checks `$app->isShuttingDown()`, or it
  holds every stop for `max_wait_time`
- **`{{ bind() }}` in event handlers**: `{{ bind(signal) }}` expands to `data-bind="hash"` (an HTML attribute).
  Using it inside a `data-on:*` JS expression generates invalid JS and silently breaks the entire handler. Use
  `${{ signal.id() }}` for direct signal assignment inside event expressions.
- **Live window**: `datestart`/`dateend` move every render of an analysis page. An effect that posts must key on
  `Math.floor(${{ datestart.id() }} / 300)` through `window.nfsenChanged(el, ...)`, or it fires on every render.
- **nfdump errors quote the filter**: render every nfdump message as escaped text, never `|raw`.
