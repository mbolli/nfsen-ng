# Actions Reference

There is no separate HTTP/REST API for the UI; see
[Reactive Loop](architecture/reactive-loop.md) for how the pieces fit. Every
server-side operation is one of these named actions. All of them are TAB scoped,
and the URL is `<basePath>_action/<name>`, the same in every tab: the `via_ctx`
field of the JSON body tells the server which tab a request belongs to. Templates
always resolve the URL with `{{ actionName.url() }}` so the base path is right
(the Twig name is the camelCase of the action name, `set-range` becomes
`setRange`). A script scrapes `via_ctx` and the signal ids from the page (see
[Environment Notes](development/environment-notes.md#driving-the-app-without-a-browser)).

Inputs are either signals, posted as the JSON body the way Datastar sends them, or
query parameters on the action URL (`?id=`), read with `$c->input()`.

## Shell and controls

| Action | File | Input | Does |
|---|---|---|---|
| `navigate` | `ShellActions.php` | signal `page` | Renders the page the client switched to; an unknown `page` is reset to the default page |
| `dismiss-notification` | `ShellActions.php` | `?page=<page id>&id=<notice id>` | Removes a notice from that page's state; without a known page, from every page |
| `kill-nfdump` | `UtilityActions.php` | none | Sends SIGTERM to this tab's own running nfdump, by query handle (see `NfdumpSlots`); the notice goes to the page that owns `query_kind` |
| `ip-info` | `UtilityActions.php` | `?ip=` | Renders the IP info dialog into the modal root: reverse DNS, then GeoIP or the web service (public) or Netbox (private) |
| `set-range` | `RangeActions.php` | `?op=preset&v=1h\|24h\|7d\|30d\|1y`, `?op=duration&n=6&u=h\|d\|w`, `?op=abs&from=&to=` (epoch seconds), `?op=back`, `?op=forward`, `?op=now`, `?op=zoomout`, `?op=pin` | Moves the global window. Presets, durations and `now` make it live; `back`, `pin` and an absolute window that ends in the past pin it. `back` is refused at the start of the stored data. Reads no capture file |
| `apply-globals` | `RangeActions.php` | signals `graph_sources`, `protocol`, `graph_trafficUnit` | Normalises the global sources, protocol and unit, then re-renders |
| `change-profile` | `RangeActions.php` | signal `selected_profile` | Switches the nfdump profile, moves the window to the end of its data, saves the choice to `preferences.json` |
| `refresh-graphs` | `GraphActions.php` | the `graph_*` signals | Re-renders the traffic graph for the current options (the live tick) |
| `validate-filter` | `QueryKitActions.php` | `?target=overview\|talkers\|flows\|conversations\|drawer\|alert` | Checks the target's filter with `nfdump -Z` and writes the answer into `_flt_<target>`; the newest request wins, and a check that cannot run answers *Filter could not be checked* |
| `estimate-query` | `QueryKitActions.php` | `?target=overview\|overview-topn\|talkers\|flows\|conversations\|drawer` | Writes `_est_<target>`: first pending, then files, bytes, seconds, runs, clamp and whether the rate was measured (`Estimate::toArray()`) |

## Overview

| Action | File | Input | Does |
|---|---|---|---|
| `overview-topn` | `OverviewPage.php` | signals `ov_tab`, `ov_dir`, `ov_limit`, `ov_order`, and the globals | Computes the KPI cards and the top-N table from the SQLite lists in a coroutine; a second request for the same inputs while one runs is dropped |
| `overview-topn-run` | `OverviewPage.php` | the same | The exact run with nfdump for a window outside retention (query kind `overview-topn`) |
| `run-filtered-graph` | `GraphActions.php` | signal `graph_filter` and the graph options | Builds the filtered series behind **Apply filter**, one nfdump per bin (query kind `graph`) |

## Top Talkers

| Action | File | Input | Does |
|---|---|---|---|
| `stats-actions` | `StatsActions.php` | the `stats_*` signals (`stats_for`, `stats_dir`, `stats_count`, `stats_orderBy`, filter, byte limits, aggregation) | Runs the statistic (query kind `stats`) and stores the result for that statistic |
| `talkers-select` | `StatsActions.php` | signal `stats_for` | Re-renders only, so a statistic with a stored result shows it; runs nothing |
| `talkers-panel` | `StatsActions.php` | `?panel=proto\|as` | Runs a side panel: `-o csv -n 10 -s proto/bytes` or `-s as/bytes` with the query card's filter (query kind `talkers-panel`) |

## Flows

| Action | File | Input | Does |
|---|---|---|---|
| `flow-actions` | `FlowActions.php` | the `flows_*` signals | Runs the listing (query kind `flows`) and stores the result |
| `flows-rows` | `FlowActions.php` | `?result=<id>&chunk=<n>` | Sends the next 1,000 table rows of a stored result as their own event |
| `flows-raw` | `FlowActions.php` | `?result=<id>&chunk=<n>` | Sends the next 512 KiB of the raw output |
| `flows-summary-estimate` | `FlowActions.php` | the query's signals | The estimate for the filtered totals |
| `flows-summary-run` | `FlowActions.php` | the query's signals | Computes the filtered totals of the Summary tab (query kind `flows-summary`) |
| `build-flows-graph` | `FlowGraphActions.php` | the query's signals, `flows_graph_unit` | Builds Traffic over time for the current filter, one nfdump per interval (query kind `flowsgraph`) |
| `touch-flows-graph` | `FlowGraphActions.php` | `flows_graph_unit` | Re-renders after a client-side change; reads nothing |

## Conversations

| Action | File | Input | Does |
|---|---|---|---|
| `conversations-run` | `ConversationActions.php` | `conv_group` (`ip\|net24\|net16\|port`), `conv_direction` (`both\|forward`), `sankey_metric`, `sankey_topN`, filter and byte limits | Runs the aggregation (query kind `conversations`). A Kill before nfdump starts or before the result is stored keeps the previous result and its notices |
| `conversations-check` | `ConversationActions.php` | the same signals | Recomputes `_conv_stale` only and reads no capture file; an effect on the query card's signals posts it, so a filter applied from the drawer counts too |

## Filter builder

| Action | File | Input | Does |
|---|---|---|---|
| `drawer-open` | `FilterDrawerActions.php` | signals `drawer_target`, `drawer_filter`, `drawer_open` | Validates the text into `_flt_drawer`; the render adds the editor and the saved list |
| `drawer-close` | `FilterDrawerActions.php` | signal `drawer_open` | Re-renders the closed drawer |
| `filter-save` | `FilterDrawerActions.php` | signals `drawer_filter`, `drawer_name` | Saves the editor's text, named after itself when unnamed; a duplicate answers with a warning |
| `filter-update` | `FilterDrawerActions.php` | `?id=`, optional `&rename=1` | Updates name and expression, or with `rename=1` only the name |
| `filter-delete` | `FilterDrawerActions.php` | `?id=` | Deletes a saved filter |
| `filter-star` | `FilterDrawerActions.php` | `?id=&on=0\|1` | Stars or unstars it |
| `filter-use` | `FilterDrawerActions.php` | `?id=` | Loads the expression into the editor and marks the filter used |
| `filter-migrate-local` | `FilterDrawerActions.php` | signal `drawer_import` | Imports a browser's old saved list |

The drawer opens on the window event `nfsen-open-drawer` with
`{target: overview|talkers|flows|conversations|alert, tab: builder|raw|saved}`;
the filter fields' **Builder** and **Saved** buttons dispatch it.

`filter-migrate-local` reads `drawer_import` and always clears it. When the import
ran, or the list held nothing new, it sets the server-owned signal
`_drawer_imported` to a fresh random id; a post with an empty `drawer_import` is
not acknowledged, and a failure answers with an error-level `_drawer_notice`. The
browser sets `localStorage` `nfsen-filters-migrated` only when `_drawer_imported`
changes while its import is pending, so a failed, lost or unanswered post is
retried on the next load.

## Alerts

| Action | File | Input | Does |
|---|---|---|---|
| `save-alert` | `AlertActions.php` | the `alert_form_*` signals | Creates or updates a rule (by id) |
| `delete-alert` | `AlertActions.php` | `?id=` | Removes a rule and its state |
| `toggle-alert` | `AlertActions.php` | `?id=`, optional `&enabled=true\|false` | Sets a rule on or off; without `enabled` it flips |
| `test-alert` | `AlertActions.php` | `?id=` | Evaluates the rule against the newest complete interval, records a test event, and opens the result dialog |
| `save-alert-templates` | `AlertActions.php` | the four `settings_default*Template` signals | Saves the global notification templates |

## Health

| Action | File | Input | Does |
|---|---|---|---|
| `trigger-import` | `ImportActions.php` | signals `admin_target_profile`, `import_scan_ports` | Catch-up import for a profile |
| `backfill-import` | `ImportActions.php` | the same | Re-reads every capture without resetting, for a datasource that accepts historic writes (VictoriaMetrics) |
| `force-rescan` | `ImportActions.php` | the same | Resets and re-imports a profile (destructive, confirmation first; RRD) |
| `cancel-import` | `ImportActions.php` | none | Cancels a running manual import |
| `topn-fill` | `ImportActions.php` | none | Queues the missing top-N intervals of every profile and source now |
| `health-refresh` | `ImportActions.php` | none | Re-renders; the 10 s tick while Health is open |

## Settings

| Action | File | Input | Does |
|---|---|---|---|
| `save-settings` | `SettingsActions.php` | `?scope=general\|rdns`, the `settings_*` signals and `displayTz` | Saves the General tab (`general`), the reverse DNS switch (`rdns`), or both without a scope; merges into `preferences.json`, keeping the alert rules and templates |

## Query kinds

Every query that reads capture files runs through `QueryRunner` with a kind, which
the progress signals (`query_running`, `query_permille`, `query_status`,
`query_eta`, `query_kind`) report and which decides the page a Kill notice goes
to: `graph` and `overview-topn` (Overview), `stats` and `talkers-panel` (Top
Talkers), `flows`, `flows-summary` and `flowsgraph` (Flows), `conversations`
(Conversations). The estimator records every finished run of a kind in
`query_runs`.

An MCP client does not use these actions, which are bound to a browser tab's
signals. The optional [MCP server](features/mcp.md) exposes ten read-only tools
over stdio or HTTP and calls the query layer directly.

## Reading an action's exact contract

The fastest way to see what signals an action reads and writes is the action
closure itself: they're short, and each starts by pulling its inputs with
`$c->getSignal('name')` or `$c->input('name')`. There is no separate schema to
keep in sync with them.
