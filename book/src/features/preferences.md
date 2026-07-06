# Preferences & Timezones

Two more Settings sub-tabs: **Preferences** (user-editable, persisted to
`backend/settings/preferences.json`) and **System** (a read-only dump of
deployment config for reference).

![Preferences sub-tab](../images/04-settings-preferences.png)

## Preferences

| Section | Fields |
|---|---|
| General | Default view (which tab opens on load), log level |
| Graph defaults | Default display mode, datatype, protocols |
| Flow & statistics defaults | Default flow limit, default statistics order-by |
| Date & time display | Browser-local vs. server timezone for displayed timestamps |
| Filter presets | A saved list of nfdump filter strings, offered as quick picks across Flows/Statistics/Sankey |

Saving persists through `SettingsActions::register()`'s single `save-settings`
action, which merges the new values with whatever's already in
`preferences.json` — including the alert rules, so saving preferences never
touches alert state.

### Timezones

The container itself runs `TZ=UTC`; nfcapd filenames are parsed in
`NFCAPD_TZ` if set (falls back to the PHP timezone otherwise), independent
of the browser display setting above. If nfcapd runs in a different
timezone than the container, set `NFCAPD_TZ` explicitly — the Health
sub-tab's "nfcapd file time" check will flag an implausible (future or very
stale) timestamp if this is wrong.

## System

![System sub-tab](../images/08-settings-system.png)

Read-only: sources, ports, active datasource, import-years, nfdump binary
path, profiles-data path, and the preferences file location — everything
that comes from environment variables or `settings.php` and needs a
container restart to change. Useful as a "what is this instance actually
configured with" reference without grepping env vars.
