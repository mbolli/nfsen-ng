# Configuration

nfsen-ng is configured through **environment variables** (`NFSEN_*`) — the
recommended path for both Docker and bare-metal. Every variable has one default
and one validator, defined once in `backend/common/EnvRegistry.php`.

A legacy **`settings.php`** file is still supported for custom filter presets or
unusual bare-metal layouts, but is **deprecated**. When present it acts as an
overlay on top of the environment: any key it defines wins, and any key it omits
falls back to the matching environment variable (then the built-in default). An
active `settings.php` is flagged on the **Settings → Health** page.

Invalid values, deprecated variable names, and unknown `NFSEN_*` variables
(typos) never fail the boot — a bad value falls back to its default and is
called out on the Health page, so misconfiguration is visible instead of silent.

> A third layer sits on top: **`preferences.json`**, the user settings saved from
> the web UI. It overlays the deployment config and **wins** for the fields the
> Preferences tab owns (UI defaults, filter presets, display timezone, and log
> level). See [Preferences](../features/preferences.md#preferences) — notably, a
> saved `logPriority` overrides `NFSEN_LOG_LEVEL`.

## Environment variables

### Sources & data

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_SOURCES` | _(none)_ | Comma-separated source names, e.g. `gw1,router`. |
| `NFSEN_PORTS` | _(none)_ | Comma-separated port numbers to track, e.g. `80,443,22`. |
| `NFSEN_FILTERS` | _(none)_ | JSON array of nfdump filter presets, e.g. `["proto tcp","dst port 80"]`. |

> A `settings.php` that defines `general.sources`, `ports`, `filters`, or
> `processor` overrides the matching variable; where the file omits a key, the
> environment variable is used.

### Core

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_STATE_DIR` | `backend/settings` | Directory for mutable runtime state — `preferences.json` and the alert rules/state/log. The Docker image sets this to `/var/lib/nfsen-ng/state` (on the persistent volume); see [State & persistence](#state--persistence). |
| `NFSEN_SETTINGS_FILE` | `backend/settings/settings.php` | Path to a custom (deprecated) settings file (used only if it exists). |
| `NFSEN_PREFERENCES_FILE` | `<state dir>/preferences.json` | Override just the preferences file path (normally derived from `NFSEN_STATE_DIR`). |
| `NFSEN_DATASOURCE` | `RRD` | Datasource: `RRD` or `VictoriaMetrics`. |
| `NFSEN_PROCESSOR` | `NfDump` | Flow processor. Only `NfDump` is implemented. |
| `NFSEN_LOG_LEVEL` | `INFO` | Log verbosity. Accepts `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERR`/`ERROR`, `CRIT`, `ALERT`, `EMERG` (and `LOG_`-prefixed forms). Controls both the app and the Swoole server. |
| `NFSEN_MAX_STATS_WINDOW` | `0` | Max statistics query window in seconds (`0` = unlimited). Also `general.max_stats_window` in `settings.php`. |
| `NFSEN_DEFAULT_THEME` | `auto` | Default UI colour theme for a browser with no saved preference (e.g. after a cache wipe). `auto` follows the OS `prefers-color-scheme`; `dark`/`light` force it. A user's manual dark-mode toggle is stored client-side and always overrides this. Also settable as `frontend.defaults.theme` in `settings.php`. |
| `NFSEN_DEV_MODE` | `false` | Enables php-via dev mode (static assets served `no-cache`). Leave off in production. |

### nfdump / nfcapd paths

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_NFDUMP_BINARY` | `/usr/local/nfdump/bin/nfdump` | Path to the nfdump binary. The Docker image compiles nfdump to `/usr/local/nfdump/bin`. |
| `NFSEN_NFDUMP_PROFILES` | `/var/nfdump/profiles-data` | Root path to the `nfcapd` data tree. In Docker this must match the container-side bind-mount (the shipped compose maps it to `/data/nfsen-ng`). |
| `NFSEN_NFDUMP_PROFILE` | `live` | Default profile subfolder. See [Profiles](profiles.md). |
| `NFSEN_NFDUMP_MAX_PROCESSES` | `1` | Max concurrent nfdump processes (floored at 1). |
| `NFCAPD_TZ` | _(PHP default TZ)_ | Timezone `nfcapd` used when writing filenames. Set this when `nfcapd` ran on a non-UTC host and nfsen-ng runs at `TZ=UTC` — otherwise epoch timestamps are off by the UTC offset. E.g. `Europe/Berlin`. |
| `TZ` | _(system)_ | The container/process timezone. nfsen-ng also compares it against php.ini in a health check. |

### Import

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_IMPORT_YEARS` | `3` | Years of history to scan on import; also sets the depth of the RRD daily archive. |
| `NFSEN_SKIP_INITIAL_IMPORT` | _(off)_ | Set to `1` or `true` to skip the startup gap-fill and only set up inotify watches. |
| `NFSEN_SKIP_DAEMON` | _(off)_ | Set to `1` or `true` to disable the embedded import daemon (and periodic alert evaluation) entirely. |

> **Changing `NFSEN_IMPORT_YEARS` after the first import** only affects the RRD
> daily-archive depth, which is fixed at creation time. To resize it, run
> **Force Rescan** from the Settings → Import panel to recreate the RRD files.
> Rebuilding is a UI action — there is no import-trigger environment variable.

### RRD storage

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_RRD_PATH` | `backend/datasources/data` | Where RRD files are stored. The Docker image sets this to `/var/lib/nfsen-ng/rrd` (on the persistent volume, alongside state) — see [State & persistence](#state--persistence). Override to relocate. |

### VictoriaMetrics

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_VM_HOST` | `victoriametrics` | VictoriaMetrics hostname. (Legacy alias: `VM_HOST`, still honoured.) |
| `NFSEN_VM_PORT` | `8428` | VictoriaMetrics HTTP port. (Legacy alias: `VM_PORT`, still honoured.) |

See [VictoriaMetrics](victoriametrics.md) for the full setup.

### NetBox IP lookup

nfsen-ng can enrich private/reserved IPs in the Flows view with metadata from a
[NetBox](https://netboxlabs.com/) IPAM instance. When configured, clicking such
an address opens a modal with its NetBox description, tenant, VRF, role, and
status. Only private/reserved ranges are looked up; public IPs fall through to
the geo lookup.

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_NETBOX_URL` | _(empty)_ | Base URL of your NetBox instance, e.g. `https://netbox.example.com`. |
| `NFSEN_NETBOX_TOKEN` | _(empty)_ | Read-only NetBox API token. |

Both are also settable as `general.netbox_url` / `general.netbox_token` in
`settings.php`. The integration is disabled while either value is empty.

### Alert email

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_ALERT_EMAIL_FROM` | _(empty)_ | From-address for alert emails. Setting it enables the email alert channel; leaving it empty disables email delivery. Also settable as `general.alert_email_from`. |

### OpenSwoole server

| Variable | Default | Description |
|----------|---------|-------------|
| `SWOOLE_WORKER_NUM` | `1` | Worker processes. Raise toward CPU-core count for CPU-bound loads. |
| `SWOOLE_MAX_REQUEST` | `0` | Requests per worker before restart. `0` (unlimited) is correct for a long-lived SSE server. |
| `SWOOLE_MAX_COROUTINE` | `10000` | Max concurrent coroutines / SSE connections. |

> The comments in the shipped compose files quote different Swoole defaults
> (`4` / `10000`); the values above are what the code actually defaults to.

## State & persistence

All of nfsen-ng's **own** persistent data lives under one directory,
`/var/lib/nfsen-ng`, in two subdirectories:

| Path | Contents |
|------|----------|
| `rrd/` (`NFSEN_RRD_PATH`) | RRD database files (unused for the VictoriaMetrics datasource) |
| `state/` (`NFSEN_STATE_DIR`) | `preferences.json` (UI prefs **and alert rule definitions**), `alerts-state.json`, `alerts-log.json` |

This is deliberately separate from the nfcapd **capture** tree (`/data/nfsen-ng`),
which the collector owns and which is usually managed on its own retention schedule.

**In Docker this must live on a volume**, or a container recreation (every image
upgrade) wipes your RRD graphs, preferences, and alerts:

- The image defaults `NFSEN_RRD_PATH` and `NFSEN_STATE_DIR` into `/var/lib/nfsen-ng`
  and declares it a `VOLUME`, so even a bare `docker run` persists in an anonymous
  volume.
- The shipped compose files map a **named** volume there (`nfsen-data`), which also
  survives `docker compose down` and is easy to back up. Unraid mounts
  `/mnt/user/appdata/nfsen-ng-data`.
- Dev (bind-mounted source) and bare-metal keep the built-in defaults
  (`backend/datasources/data` and `backend/settings`), next to the code.

To back up or migrate an instance, copy `/var/lib/nfsen-ng` — that single directory
holds both your graphs and your configuration.

## Settings file (deprecated)

> **Deprecated.** File-based config predates the environment-variable model and
> is kept only for backward compatibility. New installs should use `NFSEN_*`
> variables; an active `settings.php` is flagged on the Health page and may be
> removed in a future major release.

For custom filter presets or a bare-metal path layout, copy the template and
edit it:

```bash
cp backend/settings/settings.php.dist backend/settings/settings.php
```

The file must **assign the global `$nfsen_config` array** — nfsen-ng `include`s
it and reads that global. A file that `return`s an array (or defines any other
variable) is silently ignored and you get empty settings. The file is an overlay
on the environment: any key you set wins, and any key you omit falls back to the
matching `NFSEN_*` variable, then the built-in default.

```php
<?php
$nfsen_config = [
    'general' => [
        'sources' => ['gw1', 'router'],   // nfcapd source names
        'ports'   => [80, 443, 22],        // ports to track in RRD
        'filters' => ['proto tcp', 'dst port 80'], // nfdump filter presets
        'db'      => 'RRD',                // 'RRD' or 'VictoriaMetrics'
        'processor' => 'NfDump',
        'max_stats_window' => 0,           // seconds; 0 = unlimited
        'netbox_url'   => '',
        'netbox_token' => '',
    ],
    'nfdump' => [
        'binary'        => '/usr/local/nfdump/bin/nfdump',
        'profiles-data' => '/var/nfdump/profiles-data',
        'profile'       => 'live',
        'max-processes' => 1,
    ],
    'db' => [
        'RRD'            => ['data_path' => null, 'import_years' => 3],
        'VictoriaMetrics'=> ['host' => 'victoriametrics', 'port' => 8428, 'import_years' => 3],
    ],
    'log' => ['priority' => \LOG_INFO],
];
```

Key reference (the template ships more, including `frontend.defaults.*` UI
defaults):

| Key | Meaning | Default in template |
|-----|---------|---------------------|
| `general.sources` | nfcapd source names (`string[]`) | `['source1','source2']` |
| `general.ports` | Ports to track (`int[]`) | `[80, 22, 53]` |
| `general.filters` | nfdump filter presets shown in the UI (`string[]`) | a starter set |
| `general.db` | Datasource class name | `getenv('NFSEN_DATASOURCE') ?: 'RRD'` |
| `general.processor` | Flow processor | `'NfDump'` |
| `general.max_stats_window` | Statistics query cap in seconds (`0` = unlimited) | `0` |
| `general.netbox_url` / `general.netbox_token` | NetBox lookup | empty |
| `general.alert_email_from` | Alert email From-address | _(not in template)_ |
| `nfdump.binary` | nfdump path | `/usr/local/nfdump/bin/nfdump` |
| `nfdump.profiles-data` | Capture data root | `/var/nfdump/profiles-data` |
| `nfdump.profile` | Default profile | `live` |
| `nfdump.max-processes` | Max concurrent nfdump procs | `1` |
| `db.RRD.data_path` | RRD storage dir (`null` = default) | `null` |
| `db.<datasource>.import_years` | Years to import/retain | `3` |
| `log.priority` | Syslog level constant | `\LOG_INFO` |

> Note the key spelling: `nfdump.profiles-data` and `nfdump.max-processes` use
> **dashes**; `general.max_stats_window`, `general.netbox_url`, and
> `general.netbox_token` use **underscores**. `import_years` is read from under
> the sub-key that matches `general.db` (e.g. `db.RRD.import_years`).

## Timezones

`nfcapd` names files using the local time of the host running it. nfsen-ng parses
those filenames back to epochs to place data on the timeline. If the two run in
different timezones (classic case: bare-metal `nfcapd` in CEST, nfsen-ng in a
`TZ=UTC` container), set `NFCAPD_TZ` to the capture host's IANA timezone. When
unset, nfsen-ng falls back to PHP's effective timezone. Getting this wrong
shifts every RRD timestamp and chart label by the UTC offset.

## RRD data retention

> **RRD files are independent of `nfcapd` files.** Deleting old capture files does
> not touch RRD graphs, and vice versa. See
> [Data Sources](../architecture/data-sources.md) for the conceptual split.

nfsen-ng creates one fixed-size `.rrd` file per source (and per tracked port).
The size is set at creation and never grows. Each file holds four resolutions
(each stored as both an `AVERAGE` and a `MAX` archive):

| Resolution | Retention |
|------------|-----------|
| 5-minute samples | 45 days |
| 30-minute samples | 90 days |
| 2-hour samples | 1 year |
| 1-day samples | `NFSEN_IMPORT_YEARS` years (default 3) |

Only the daily archive's depth changes with `NFSEN_IMPORT_YEARS`; the finer
three are fixed.

### Disk space

Because the fine-resolution archives dominate and are fixed, **every `.rrd` file
is roughly the same size — about 5 MiB** — whether it's an aggregate or a
per-port file, and almost independent of `NFSEN_IMPORT_YEARS` (the daily archive
is a small fraction of the total). Budget accordingly:

- ~5 MiB per source (aggregate)
- ~5 MiB per tracked port, per source

So 10 sources × 5 tracked ports ≈ 60 files ≈ **~300 MB** total. This is a flat,
predictable cost — RRD files are circular buffers that never grow with traffic
volume.

### Changing retention depth

To store more (or fewer) years of daily data, set `NFSEN_IMPORT_YEARS` and then
recreate the RRD structure with **Settings → Import → Force Rescan** (the server
also logs a warning on start if it detects a size mismatch):

```yaml
# docker-compose.yml
environment:
  - NFSEN_IMPORT_YEARS=5   # 5 years of daily graph data
  # then run Force Rescan once from the web UI to rebuild the RRD files
```

### RRD vs nfcapd files

| | RRD (`.rrd`) | nfcapd (`nfcapd.*`) |
|--|--|--|
| **Purpose** | Graph counters (flows/packets/bytes) | Raw flow records for Flows & Statistics |
| **Size** | Fixed (~5 MiB/file) | Grows with traffic |
| **Removed with nfcapd files?** | No — independent | — |
| **Flows/Stats work without them?** | — | No — nfdump reads them directly |
| **Retention control** | `NFSEN_IMPORT_YEARS` | Manage separately (e.g. cron + `find -mtime`) |
