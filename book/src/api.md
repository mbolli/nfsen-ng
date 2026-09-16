# Actions Reference

There is no separate HTTP/REST API for the UI — see
[Reactive Loop](architecture/reactive-loop.md) for why. Every server-side
operation is one of these named actions, each reachable at
`POST /_action/{action-id}` (the id is randomized per browser tab; the
Twig templates always resolve it via `{{ action_name.url() }}`, never a
hardcoded path).

| Action | File | Does |
|---|---|---|
| `save-alert` | `AlertActions.php` | Create/update an alert rule |
| `delete-alert` | `AlertActions.php` | Remove an alert rule (`?id=`) |
| `toggle-alert` | `AlertActions.php` | Enable/disable a rule (`?id=`) |
| `test-alert` | `AlertActions.php` | Evaluate a rule once, on demand, without waiting for the next cycle |
| `flow-actions` | `FlowActions.php` | Run the Flows tab's nfdump query and re-render the table |
| `build-flows-graph` | `FlowGraphActions.php` | Build the Flows tab's traffic-over-time series for the current filter (one nfdump per interval, explicit only) |
| `touch-flows-graph` | `FlowGraphActions.php` | Re-render so the traffic panel notices a client-side change; reads nothing |
| `stats-actions` | `StatsActions.php` | Run the Statistics tab's `-s` query |
| `dismiss-notification` | `StatsActions.php` | Dismiss a flow/stats-panel notification |
| `count-files` | `StatsActions.php` | Recount matching nfcapd files (feeds the Statistics/Sankey "may take a moment" query-time warning, not the import progress bar) |
| `sankey-actions` | `SankeyActions.php` | Run the Sankey tab's aggregation query |
| `dismiss-sankey-notification` | `SankeyActions.php` | Dismiss a Sankey-panel notification |
| `change-profile` | `GraphActions.php` | Switch the active nfdump profile |
| `refresh-graphs` | `GraphActions.php` | Re-render the Graphs tab for the current filter set |
| `run-filtered-graph` | `GraphActions.php` | Build the filtered series behind **Apply filter** |
| `save-settings` | `SettingsActions.php` | Persist Preferences (merges with existing alert rules) |
| `trigger-import` | `ImportActions.php` | Manual catch-up import for a profile |
| `force-rescan` | `ImportActions.php` | Reset + re-import a profile (destructive, confirmation-gated; RRD only) |
| `backfill-import` | `ImportActions.php` | Re-read every capture without resetting, for a datasource that accepts historic writes (VictoriaMetrics) |
| `cancel-import` | `ImportActions.php` | Cancel an in-progress manual import |
| `ip-info` | `UtilityActions.php` | Reverse-DNS lookup modal, plus geo-IP (public IPs) or Netbox (private IPs) |
| `kill-nfdump` | `UtilityActions.php` | Send SIGTERM to this tab's own running nfdump, by query handle (see `NfdumpSlots`) |

An MCP client reaches the same data through a different door: the optional
[MCP server](features/mcp.md) exposes ten read-only tools over stdio or HTTP. It calls the
query layer directly rather than these actions, which are bound to a browser tab's signals.

## Reading an action's exact contract

The fastest way to see what signals an action actually reads/writes is the
action closure itself — they're short, and every one starts by pulling its
inputs via `$c->getSignal('name')` before doing anything. There's
intentionally no separate schema/OpenAPI layer to keep in sync with it.
