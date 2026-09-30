# Reactive Loop: Pages, Signals, Actions & SSE

There is no REST API and no client-side state store. Every piece of UI state the
server needs is a **signal**; every user interaction that needs server logic is
an **action**; every update reaches the browser as an **SSE-pushed DOM patch**.
This is the [Datastar](https://data-star.dev/) model, implemented server-side by
[php-via](https://github.com/mbolli/php-via).

## One route, seven pages

There is exactly one server route, `/`. The pages (Overview, Top Talkers, Flows,
Conversations, Alerts, Health, Settings) are addressed by the URL hash:
`#/overview`, `#/talkers`, `#/flows`, `#/conversations`, `#/alerts`, `#/health`,
`#/settings`. `nfsen-router.js` keeps the hash and the tab signal `page`
in step:

1. A click on a sidebar link changes the hash. The router sets `$page` at once, so
   the page sections swap on the client (inside a view transition) without
   waiting for the server.
2. It then posts one `navigate` action. The server validates the page and renders
   it in full; `navigate` resets an unknown page to the default page.
3. The old hashes of the tab layout (`#/graphs`, `#/statistics`, `#/sankey`,
   `#/investigate`) are rewritten to their pages, and a view persisted by the old
   layout is mapped once by a script in `<head>`.

The server renders **only the active page** (`PageRegistry::LAZY`). The other
page sections hold a skeleton, and each page template reads `pages.<id>` only
while `pages.<id>.active` is true. A page switch therefore costs one render of
one page, and a live tick on Overview does not render the Flows table.

## Composition

`backend/app.php` builds every tab from four kinds of parts, all registered in
`PageRegistry`:

| Part | Classes | Owns |
|---|---|---|
| Shell | `Shell` | The layout, sidebar, footer, notices, modal root, and the render |
| Shell modules | `RangeControls`, `TrafficGraph`, `QueryKit`, `FilterDrawer` | The controls bar, the traffic graph, filter validation and estimates, the filter drawer; each has a top-level Twig key (`range`, `graph`, `querykit`, `drawer`) |
| Pages | `OverviewPage`, `TalkersPage`, `FlowsPage`, `ConversationsPage`, `AlertsPage`, `HealthPage`, `SettingsPage` | Their signals, actions and `pages.<id>` view data |
| Page states | `PageStates` with `ShellState`, `OverviewState`, `TalkersState`, `FlowsState`, `ConversationsState` | Per-tab results, notices and caches, in memory |

Each page implements `Page` (`backend/pages/Page.php`): `id()`, `title()`,
`lede()`, `icon()`, `group()` (`analysis`, `monitor` or `system`), `signals()`,
`register()` and `viewData()`. For every new context, `app.php` calls
`signals()` on the shell, the modules and the pages, then `register()`, then
renders through `Shell::render()`.

## Signals

```php
$c->signal('ip', 'conv_group', clientWritable: true);
```

A signal has a default value, a name, and a scope:

- **TAB scope** (the default) is private to one browser tab (one context).
- **Shared scopes** (`ROUTE`, `SESSION`, `GLOBAL`, or a custom string like
  `rrd:live`) are one instance shared by every context in the scope.
- `clientWritable: true` lets the browser's POST, and a revival, update the
  signal. `clientWritable: false` makes it server-owned: php-via ignores the
  posted copy and sends the server's value back, so a stale copy in the browser
  never overwrites it (`query_running`, the query progress, the stored graph's
  figures). A signal the server sets but whose copy in the browser carries it
  across a revival stays client-writable: `range_live`, `range_preset`,
  `flows_graph_key`. Every declaration says which it is.

php-via puts the values of the first sync into the page itself, as a
`data-signals__ifmissing` meta at the top of `<head>`, so expressions work
before the SSE stream connects. Templates seed only the client-local `_`
signals they introduce.

The global signals every page reads are declared by `RangeControls` and the
shell: `page`, `datestart`, `dateend`, `range_preset`, `range_live`,
`graph_sources` (the global sources), `protocol` and `graph_trafficUnit` (the
global unit). Page signals keep their prefixes: `graph_*` and `ov_*` (Overview),
`stats_*` (Top Talkers), `flows_*` (Flows), `conv_*` and `sankey_*`
(Conversations), `alert_form_*` (Alerts), `settings_*` (Settings), `drawer_*`
(the filter drawer).

A leading `_` means Datastar never posts the signal back. Most of these are
**client-local**: the browser seeds them itself with `data-signals` in the
markup, and templates use them by their bare name. They hold state only the
browser needs: `$_flows_tab`, `$_conv_view` and
`$_settings_tab` (which tab of a page is open), `$_prevRange` (for **Previous
range**), `$_graph_logscale`, `$_graph_stacked`, `$_graph_stepplot`,
`$_sidebarCollapsed`, `$_themeChoice`. Some are persisted per browser in
`localStorage` under `nfsen-persist:<name>`; `_sidebarCollapsed` is written only
by the collapse toggle. Server-owned signals with a leading underscore
(`_flt_<target>`, `_est_<target>`, `_conv_stale`, `_drawer_notice`, `_stats_rows`) are declared with
`$c->signal()`, pushed by the server and never posted back. Their wire id carries the per-context
hash like every server signal, so templates address them as `${{ _conv_stale.id() }}`; a bare
`$_conv_stale` matches nothing.

## Actions

```php
$c->action(static function (Context $c) use ($conversations): void {
    self::run($c, $conversations);
}, 'conversations-run');
```

Actions are closures registered with a name, in the page's `register()` or in an
`*Actions` class it calls. The client calls them with
`@post('{{ conversationsRun.url() }}')` in a `data-on:click` attribute, which
POSTs the current signals as JSON. All actions are TAB scoped; the URL is
`<basePath>_action/<name>` in every tab, and the `via_ctx` signal in the body
names the context. Templates always use `{{ action.url() }}` (Twig name = the
camelCase of the action name), which adds the base path. The handler reads the
signals it needs, does its work (often in a coroutine, often shelling out to
`nfdump`; see [Nfdump Integration](nfdump-integration.md)), and calls
`$c->sync()` to re-render and push the patch, or `$c->syncSignals()` to push
signals only (query progress, filter validation, estimates).

Every action closure catches `\Throwable` and reports the failure through the
page's notices or the `_error` signal. php-via catches what escapes, logs it and
answers `500`, and the worker keeps running, but the tab would show nothing. A
coroutine an action starts itself (`Coroutine::create()`) has no such guard: an
uncaught throw there still ends the worker, so its body catches `\Throwable`
too. The full list is in the [Actions Reference](../api.md).

## Sync and broadcast

- `$c->sync()` updates the calling context only.
- `$app->broadcast($scope)` re-renders every context subscribed to a scope:
  `rrd:live` after each imported file, `admin:import` on import progress,
  `settings:saved` after a settings save, `alerts:fired` when rules fire.
- The import's broadcasts go through `ImportDaemon::broadcast()`, which renders
  a scope at most once every 250 ms after the previous render and always sends
  the state at the end of that window. The start, a cancel and the end of a pass
  go out at once. One render of every tab per imported file runs the worker out
  of memory on a large import with several tabs open.

php-via 0.13 drops element patches for a tab with more than 1 MB still unsent
instead of parking the tab's SSE write, and `app.php` keeps that threshold
(`withSseMaxQueuedBytes()`). With dropping off, an import's stream of broadcasts
parked a slow tab's write: php-via then lost patches from the full queue anyway,
and a stop waited for the parked write. What a drop costs depends on the patch:

- A render carries the whole page, so the tab's next render (an action, a live
  tick, a broadcast) replaces a dropped one.
- A chunk of Flows rows or raw output is appended, so no render replaces it. If
  a chunk has not arrived 8 seconds after the page asked for it, the page asks
  again, three requests in all, and then says which rows or output are missing;
  running the query again fetches them.
- A dialog opened in that state may stay closed: its markup comes with the next
  render, but the script that opens it has already run.

Signals such as `query_running` and scripts are never dropped.

## Render cost and caches

One render is the shell, the modules, and the active page. Anything that is
expensive at render time is cached process-wide, so a broadcast to many tabs does
not multiply the work:

| Data | Cache |
|---|---|
| Footer capture status | Shell, 30 s |
| Health checks and metrics | `HealthPage`, 30 s while Health is open, 5 min otherwise, refreshed in a coroutine, never in a render |
| Settings read-only tabs | `SettingsPage`, 30 s, and fresh after a save |
| Top-N range results | `TopNRepository`, 64-entry LRU, 300 s or until the collector's generation moves |
| Query estimates | `QueryEstimator`, 32-entry LRU, 300 s |
| GeoIP reader | `GeoIpDatabase`, until the file changes |

The graph data and the stored totals behind the KPI card are cached per tab and
fetched only when due. Nothing that reads SQLite at the size of a range query, or
reads a capture file, runs inside a render: actions compute results and store
them in the page state, and the render reads what they stored.

The live window moves `datestart` and `dateend` every render of an analysis page,
at second granularity. Client effects that post (estimates, `overview-topn`)
therefore key on `Math.floor(${{ datestart.id() }} / 300)` and `Math.floor(${{ dateend.id() }} / 300)`
through `window.nfsenChanged(el, ...)`, so they fire at most once per
five-minute interval in live mode. Result fingerprints encode a live window as
`live:<width>` for the same reason.

## Result hosts

Large blocks (the Flows table and raw output, the Top Talkers table, the
Conversations payload and IP pairs table) are sent once per result. The
macro in `components/result-host.html.twig` renders
`<div class="result-host" id="<name>-<resultId>" data-ignore-morph>` with the
content only when `PageState::sendResult()` says the client does not have that
result yet, and empty otherwise. Datastar skips a morph when both the old and the
new element carry `data-ignore-morph`, and replaces an element whose id changed,
so an unchanged result is neither re-sent nor morphed, and a new result (a new
random `resultId`) replaces the old one. A tab that lacks part of a large result
pulls it in chunks through its own action (`flows-rows`, `flows-raw`).

## Practical consequences

- **No client build step.** The frontend is server-rendered Twig plus hand-written
  modules in `frontend/js/components/`. The pieces that need real client-side
  behaviour (the charts, the result table, the Sankey and Matrix, the filter
  editor and the toasts) are Rocket elements, Datastar's component system:
  typed props from attributes, a `setup` and a `cleanup`. The server markup
  stays in their light DOM, where the page CSS and a morph reach it; a
  chart or table host holds only a `<slot>` in its shadow root. The router, the
  menus and the copy buttons are plain modules. The engine,
  `frontend/js/datastar-rocket.js`, is committed, and nothing is bundled at
  runtime. `AGENTS.md` has the rules for Rocket elements and the load order.
- **Signal names are not wire keys.** A signal's rendered id is its name plus a
  per-context hash; the human name is only a server-side lookup key
  (`$c->getSignal('name')`).
- **Actions read signals, not `$_POST`.** `$c->input()` exists for plain query
  parameters (e.g. `delete-alert` taking `?id=`, `set-range` taking `?op=`), but
  the normal path is signals in, `$c->sync()` out.
- **Morphs keep client state only where told.** ECharts canvases sit in
  `data-ignore-morph data-ignore` containers, dialogs use
  `data-preserve-attr="open"`, page sections `data-preserve-attr="hidden"`, and
  any attribute JavaScript sets on server markup is listed in
  `data-preserve-attr`.
