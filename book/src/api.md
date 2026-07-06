# Actions Reference

There is no separate HTTP/REST API — see
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
| `stats-actions` | `StatsActions.php` | Run the Statistics tab's `-s` query |
| `dismiss-notification` | `StatsActions.php` | Dismiss a flow/stats-panel notification |
| `count-files` | `StatsActions.php` | Recount matching nfcapd files (feeds the ETA/progress display) |
| `sankey-actions` | `SankeyActions.php` | Run the Sankey tab's aggregation query |
| `dismiss-sankey-notification` | `SankeyActions.php` | Dismiss a Sankey-panel notification |
| `change-profile` | `GraphActions.php` | Switch the active nfdump profile |
| `refresh-graphs` | `GraphActions.php` | Re-render the Graphs tab for the current filter set |
| `save-settings` | `SettingsActions.php` | Persist Preferences (merges with existing alert rules) |
| `trigger-import` | `ImportActions.php` | Manual catch-up import for a profile |
| `force-rescan` | `ImportActions.php` | Reset + re-import a profile (destructive, confirmation-gated) |
| `cancel-import` | `ImportActions.php` | Cancel an in-progress manual import |
| `ip-info` | `UtilityActions.php` | Reverse-DNS/Netbox lookup modal |
| `kill-nfdump` | `UtilityActions.php` | Send SIGTERM to the currently-running nfdump subprocess |

## Reading an action's exact contract

The fastest way to see what signals an action actually reads/writes is the
action closure itself — they're short, and every one starts by pulling its
inputs via `$c->getSignal('name')` before doing anything. There's
intentionally no separate schema/OpenAPI layer to keep in sync with it.
