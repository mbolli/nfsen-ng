# Reactive Loop: Signals, Actions & SSE

There is no REST API and no client-side state store. Every piece of UI state
is a **signal** living on the server; every user interaction that needs
server logic is an **action**; every update reaches the browser as an
**SSE-pushed DOM patch**. This is the [Datastar](https://data-star.dev/)
model, implemented server-side by [php-via](https://github.com/mbolli/php-via).

## Signals

```php
$graphSources = $c->signal(Config::$settings->sources, 'graph_sources', clientWritable: true);
```

A signal has a default value, a human-readable name, and a scope:

- **TAB scope** (the default) — private to one browser tab/context. Most form
  state (`alert_form_*`, `graph_*`, `flows_*`) is TAB-scoped.
- **Shared scopes** (`ROUTE`, `SESSION`, `GLOBAL`, or a custom string like
  `rrd:live`) — one signal instance shared across every context in that
  scope, so a write in one tab can broadcast to every other subscribed tab.
- `clientWritable: true` lets the browser's own POST update the signal
  (through an action); server-owned signals omit it and can only change from
  PHP.

Client-local signals — prefixed with `_`, e.g. `$_currentView`,
`$_darkMode` — never round-trip to the server at all. Every "page" nfsen-ng
appears to have (Graphs, Flows, Statistics, Sankey, the Settings sub-tabs) is
actually one of these: a client-local signal toggling `data-show` on a
`<div>` that's already in the DOM. There's exactly one server route (`/`).

## Actions

```php
$c->action(function (Context $c) use (&$flowTableHtml): void {
    $filter = $c->getSignal('flows_filter');
    // ... run nfdump, build $flowTableHtml ...
    $c->sync();
}, 'flow-actions');
```

Actions are closures registered with a name; the client calls them via
`@post('{{ action_name.url() }}')` in a `data-on:click` attribute, which
POSTs to `/_action/{id}` with the current signal values as the JSON body.
The handler reads whatever signals it needs, does its work (frequently
shelling out to `nfdump` — see [Nfdump Integration](nfdump-integration.md)),
and calls `$c->sync()`, which re-renders and pushes the diff to that
context's SSE connection.

## The `$c->sync()` / broadcast split

- `$c->sync()` updates the calling context only.
- `$app->broadcast($scope)` (used e.g. after an import completes) pushes to
  every context subscribed to that scope — this is how a new nfcapd file
  landing updates every open browser tab's graph without any of them having
  clicked anything.

## Practical consequences

- **No client build step.** The frontend is server-rendered Twig +
  hand-written Web Components (`frontend/js/components/`) for the pieces that
  need real client-side behaviour (charts, the date-range slider, the flow
  table). There's nothing to bundle.
- **Signal names are wire keys.** A signal's rendered `data-bind` id is a
  hash of its name plus a per-context salt; the human name is only a
  server-side lookup key (`$c->getSignal('name')`), not what's transmitted.
- **Actions read signals, not `$_POST`.** `$c->input()` exists for the rare
  case an action needs a plain query/form parameter (e.g. `delete-alert`
  taking `?id=`), but the normal path is signals in, `$c->sync()` out.
