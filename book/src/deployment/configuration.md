# Configuration

nfsen-ng is configured through **environment variables** (`NFSEN_*`), for Docker
and bare metal alike. Every variable has one default and one validator, defined
once in `backend/common/EnvRegistry.php`.

A legacy **`settings.php`** file is still supported for unusual bare-metal
layouts, but is **deprecated**. When present it acts as an overlay on top of the
environment: any key it defines wins, and any key it omits falls back to the
matching environment variable (then the built-in default). An active
`settings.php` is flagged on the **Health** page.

Invalid values, deprecated variable names, and unknown `NFSEN_*` variables
(typos) never fail the boot. A bad value falls back to its default and is called
out on the Health page, so misconfiguration is visible instead of silent. The
**System** tab of Settings lists every variable with the value in effect and
whether it was set or defaulted.

> A third layer sits on top: **`preferences.json`**, the settings saved from
> **Settings > General**. It overlays the deployment config and **wins** for the
> fields that tab owns (UI defaults, the instance theme, display timezone and log
> level). See [Settings](../features/settings.md#how-preferences-layer-with-the-deployment);
> notably, a saved log level overrides `NFSEN_LOG_LEVEL`.

## Environment variables

### Sources & data

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_SOURCES` | _(none)_ | Comma-separated sources, e.g. `gw1,router`. `source:name` shows a name for a source wherever the interface lists it, e.g. `10-20-100-3:dc1rt310` for a directory `nfcapd -M` named after the exporter's address; the source stays what queries and stored data use. |
| `NFSEN_PORTS` | _(none)_ | Comma-separated port numbers to track, e.g. `80,443,22`. |
| `NFSEN_FILTERS` | _(none)_ | JSON array of filter presets, e.g. `["proto tcp","dst port 80"]`. Each one is added to the saved filters once, marked as a preset. A preset you delete in the filter drawer stays deleted. |

> A `settings.php` that defines `general.sources`, `ports`, `filters`, or
> `processor` overrides the matching variable; where the file omits a key, the
> environment variable is used.

### Core

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_STATE_DIR` | `backend/settings` | Directory for mutable runtime state: `preferences.json`, the alert rule state and the SQLite store `nfsen-ng.sqlite` (saved filters, alert history, top-N data). It must be writable. The Docker image sets this to `/var/lib/nfsen-ng/state` (on the persistent volume); see [State & persistence](#state--persistence). |
| `NFSEN_SETTINGS_FILE` | `backend/settings/settings.php` | Path to a custom (deprecated) settings file. The default path is used only if the file exists; a path set here must exist, or the server stops at start. |
| `NFSEN_PREFERENCES_FILE` | `<state dir>/preferences.json` | Override just the preferences file path (normally derived from `NFSEN_STATE_DIR`). |
| `NFSEN_DATASOURCE` | `RRD` | Datasource: `RRD` or `VictoriaMetrics`. |
| `NFSEN_PROCESSOR` | `NfDump` | Flow processor. Only `NfDump` is implemented. |
| `NFSEN_LOG_LEVEL` | `INFO` | Log verbosity. Accepts `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERR`/`ERROR`, `CRIT`, `ALERT`, `EMERG` (and `LOG_`-prefixed forms). Controls both the app and the Swoole server. |
| `NFSEN_MCP_HTTP` | `false` | Serve the read-only MCP endpoint at `/_mcp` on the app's own port. See [MCP Server](../features/mcp.md). |
| `NFSEN_MCP_HOSTS` | *(empty)* | Hostnames an MCP client may address this server as, comma-separated. Empty means localhost only. |
| `NFSEN_MAX_STATS_WINDOW` | `0` | Longest window, in seconds, that Top Talkers, Conversations, a filtered graph and the exact Overview run read (`0` = unlimited). A longer range is shortened to its last part, and the estimate says so. Also `general.max_stats_window` in `settings.php`. |
| `NFSEN_DEFAULT_THEME` | `auto` | Instance theme while **Settings > General > Theme** is left at *Deployment default*. `auto` follows the operating system's `prefers-color-scheme`; `dark` and `light` force it. A browser that picked its own theme in the sidebar keeps its choice. Also settable as `frontend.defaults.theme` in `settings.php`. |
| `NFSEN_DEV_MODE` | `false` | Enables php-via dev mode (static assets served `no-cache`). Leave off in production. |

### nfdump / nfcapd paths

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_NFDUMP_BINARY` | `/usr/local/nfdump/bin/nfdump` | Path to the nfdump binary. The Docker image compiles nfdump to `/usr/local/nfdump/bin`. |
| `NFSEN_NFDUMP_PROFILES` | `/var/nfdump/profiles-data` | Root path to the `nfcapd` data tree. In Docker this must match the container-side bind-mount (the shipped compose maps it to `/data/nfsen-ng`). |
| `NFSEN_NFDUMP_PROFILE` | `live` | Default profile subfolder. See [Profiles](profiles.md). |
| `NFSEN_PORT_DIRECTION` | `dst` | Which side of a flow a per-port graph counts: `dst`, `src`, or `any` for either direction. Set `any` if your exporter reports one direction of each flow and your port graphs are empty. |
| `NFSEN_NFDUMP_MAX_PROCESSES` | `auto` | Parallel nfdump processes; each uses about 2 to 3 CPU cores. `auto` or `0` is a third of the CPU cores, between 2 and 8; any positive number is used as it is. See [nfdump processes and CPU cores](#nfdump-processes-and-cpu-cores). |
| `NFSEN_NFDUMP_WORKERS` | `2` | Filter threads per nfdump process, passed to every run as `-W` (`0` to `16`). `0` leaves nfdump's own default of half the host's cores. |
| `NFCAPD_TZ` | _(PHP default TZ)_ | Timezone `nfcapd` used when writing filenames. Set this when `nfcapd` ran on a non-UTC host and nfsen-ng runs at `TZ=UTC`; otherwise epoch timestamps are off by the UTC offset. E.g. `Europe/Berlin`. |
| `TZ` | _(system)_ | The container/process timezone. nfsen-ng also compares it against php.ini in a health check. |

### nfdump processes and CPU cores

nfdump reads and decompresses each capture file on one thread and aggregates
`-s` and `-A` statistics on its main thread, so one nfdump process keeps about 2
to 3 cores busy whatever its thread settings say. nfsen-ng therefore limits
processes, not threads: `NFSEN_NFDUMP_MAX_PROCESSES` is the number of nfdump runs
that may execute at once. Each run also holds its own aggregation table, about
150 to 200 MB for `-s srcip` over 24 million flows and more for wide windows and
`-A`, so the limit bounds memory as well.

`auto` (the default, also `0`) sets the limit to a third of the CPU cores, at
least 2 and at most 8:

| CPU cores | Parallel processes |
|-----------|--------------------|
| 4 | 2 |
| 8 | 2 |
| 12 | 4 |
| 20 | 6 |
| 24 or more | 8 |

The core count follows container limits. It is the smaller of the CPUs the
process may run on (what `nproc` prints) and the cgroup CPU quota, rounded up
(`cpu.max`, or `cpu.cfs_quota_us` on cgroup v1). A container started with
`--cpus=4` on a 20-core host counts 4. Where neither can be read, nfsen-ng
counts the online CPUs. An explicit number is used as it is, so existing
settings keep working.

A `settings.php` copied from an older template, whose `max-processes` line reads
`(int) (getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 1)`, pins one process when the
variable is unset or `0`, because PHP reads `'0'` as false there. Set the
variable to `auto` instead, delete the line, or write
`getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 'auto'`. The **Parallel processes**
row on the Health page says whether `auto` is in effect.

`NFSEN_NFDUMP_WORKERS` is passed to every nfdump run as `-W`. Without it, each
process starts filter threads for half the host's cores, up to 8, so six
parallel runs could start 48 of them. A second filter thread speeds up a
filtered query by up to about 20%; more make no measurable difference. nfdump
1.7.2 has no `-W`, so nfsen-ng passes it to 1.7.3 and later only. The filter
check while typing (`nfdump -Z`) takes neither a slot nor `-W`.

Every nfdump run takes one slot, and slots come in two classes:

- **User queries** (Top Talkers, Flows, Conversations, a filtered graph, the
  exact Overview run, an alert's **Test** button) may take every free slot and
  wait up to 30 seconds for one.
- **Background work** (the import, the top-N collector and its gap filler, live
  alert evaluation) holds at most half the slots, starts only while one more
  slot stays free, and waits while any user query waits. It waits up to 10
  minutes for a slot, so a long query delays an import instead of dropping a
  file. Live alert evaluation is the exception: the import daemon waits for it,
  so one evaluation waits at most a minute in total for the slots of all its
  filtered rules. A rule still without a slot then is not evaluated for that
  interval, and the log says `no free nfdump process`.

Background work therefore never holds more than half the slots, and a user
query never queues behind background work: it waits only for a running nfdump
to end. With a limit of `1`, a user query waits for at most one background run
that had already started.

One user query may hold several slots. A filtered graph runs as many intervals
at once as there are free slots, and gives one back after each interval to a
user query that waits or to background work that needs one. A large Top Talkers,
Conversations or Overview exact run splits into time slices on up to 8 slots.
Once the limit is 3 or more it leaves one free for another query when 3 or more
are free, and otherwise takes up to 2. It gives each slot back as its slice ends. The top-N collector collects up to half the slots'
worth of capture files at once. With `auto` on fewer than 12 cores the limit is
2 or 3, so a split runs 2 processes and the collector takes one file at a time.
See [Nfdump Integration](../architecture/nfdump-integration.md#statistics-in-parallel)
for how a split query stays exact.

The **nfdump** group on the **Health** page shows the detected cores and where
the number came from, the process limit, the `-W` in use and the slots in use by
class. **Settings > System** lists the same under *In effect*.

### Import

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_IMPORT_YEARS` | `3` | Years of history to scan on import; also sets the depth of the RRD daily archive. |
| `NFSEN_SKIP_INITIAL_IMPORT` | _(off)_ | Set to `1` or `true` to skip the startup gap-fill and only set up inotify watches. |
| `NFSEN_SKIP_DAEMON` | _(off)_ | Set to `1` or `true` to disable the embedded import daemon (and periodic alert evaluation) entirely. |
| `NFSEN_TOPN_RETENTION_DAYS` | `31` | Days of per-interval top-N data kept in SQLite for the Overview tables. `0` turns collection off. See [Top-N data](#top-n-data). |

> **Changing `NFSEN_IMPORT_YEARS` after the first import** only affects the RRD
> daily-archive depth, which is fixed at creation time. To resize it, run
> **Rescan** on the **Health** page to recreate the RRD files. Rebuilding is a UI
> action; there is no import-trigger environment variable.

### Top-N data

While importing, nfsen-ng records the top 50 of nine statistics (source and
destination address, source and destination port, protocol, source and
destination AS, input and output interface) for every capture file, and keeps
exact hourly and daily sums of them. The Overview KPI cards and the top-N table
read these lists instead of running nfdump. `NFSEN_TOPN_RETENTION_DAYS` sets how
many days back they reach; a range that starts earlier is offered as an explicit
**Run exact query** instead.

Budget about **12 MB per source and day** in the state directory: 31 days of one
source come to roughly 180 MB for the per-interval lists and about as much again
for the hourly and daily sums. The variable is read at start. After a restart
with a lower retention, the pruner removes the older days, starting five minutes
in and then hourly; after a raise, the gap filler queues the capture files of the
older days that still exist, 500 at a time and the next ones once the queue is
down to 250, until nothing is missing or a file fails; the collector works
through them on background processes. `0` stops collection, and the Overview table then offers the exact run for
every range. See [SQLite store](../features/sqlite-store.md) for the schema.

### RRD storage

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_RRD_PATH` | `backend/datasources/data` | Where RRD files are stored. The Docker image sets this to `/var/lib/nfsen-ng/rrd` (on the persistent volume, alongside state); see [State & persistence](#state--persistence). Override to relocate. |

### VictoriaMetrics

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_VM_HOST` | `victoriametrics` | VictoriaMetrics hostname. (Legacy alias: `VM_HOST`, still honoured.) |
| `NFSEN_VM_PORT` | `8428` | VictoriaMetrics HTTP port. (Legacy alias: `VM_PORT`, still honoured.) |

See [VictoriaMetrics](victoriametrics.md) for the full setup.

### NetBox IP lookup

nfsen-ng can enrich private/reserved IPs with metadata from a
[NetBox](https://netboxlabs.com/) IPAM instance. When configured, clicking such an
address opens a dialog with its NetBox description, tenant, VRF, role, and status.
Only private/reserved ranges are looked up; public IPs get geolocation instead.

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_NETBOX_URL` | _(empty)_ | Base URL of your NetBox instance, e.g. `https://netbox.example.com`. |
| `NFSEN_NETBOX_TOKEN` | _(empty)_ | Read-only NetBox API token. |

Both are also settable as `general.netbox_url` / `general.netbox_token` in
`settings.php`. The integration is disabled while either value is empty.
**Settings > Integrations** shows whether it is configured, with the token masked.

### Local GeoIP database

Public IPs can be geolocated from a local MaxMind database instead of a web
service. The lookup then never leaves the host and has no rate limit.

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_GEOIP_DB` | _(empty)_ | Path to a MaxMind GeoLite2 or GeoIP2 **City** or **Country** `.mmdb`. When set, IP lookups use it locally instead of the web service below. |

GeoLite2 is free, but MaxMind hands it out only to registered users: create an
account at [maxmind.com](https://www.maxmind.com/en/geolite2/signup), generate a
license key, and download `GeoLite2-City.mmdb` from the account page or keep it
current with MaxMind's
[`geoipupdate`](https://github.com/maxmind/geoipupdate) tool. Then mount the
directory that holds it read-only and point the variable at the file:

```yaml
# docker-compose.yml, nfsen service
volumes:
  - /usr/share/GeoIP:/usr/share/GeoIP:ro
environment:
  - NFSEN_GEOIP_DB=/usr/share/GeoIP/GeoLite2-City.mmdb
```

nfsen-ng reads the file with the pure-PHP `maxmind-db/reader` library (no PHP
extension needed) and reopens it when its modification time changes, so a
`geoipupdate` run takes effect without a restart. Mount the directory, not the
file: `geoipupdate` replaces the file with a new one, and a single-file bind
mount keeps showing the container the old one until it is recreated. **Settings > Integrations**
shows the path, the database type, its build date, and either *Active* or the
reason it cannot be used (file missing, a directory in the path the process
cannot enter, unreadable file, not an `.mmdb`). While the file cannot be opened,
lookups fall back to the web service. The IP info dialog names its source:
*MaxMind database* or the web service's host.

### Geolocation lookup

Without a local database, public IPs are enriched by a geolocation web service,
the counterpart to the NetBox lookup above and the only one of the two that
talks to a third party. Private and reserved ranges never reach it, so internal
addressing stays on your network. The default is
[ipapi.co](https://ipapi.co/), which rate-limits anonymous callers: point this
at another provider, or at the same one with an API token, when you hit that
limit.

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_IPINFO_URL` | `https://ipapi.co/{ip}/json/` | Geolocation endpoint. `{ip}` is replaced with the URL-encoded address (a template without it gets the address appended), `{token}` with `NFSEN_IPINFO_TOKEN`. |
| `NFSEN_IPINFO_TOKEN` | _(empty)_ | API key for that service. Only sent where the URL puts `{token}`. |

The quickest fix if you're only occasionally rate-limited is to stay on ipapi.co
and register for a key:

```bash
NFSEN_IPINFO_URL=https://ipapi.co/{ip}/json/?key={token}
NFSEN_IPINFO_TOKEN=your-key-here
```

Every service spells its key parameter differently (`?key=`, `?token=`,
`?apiKey=`), so the URL owns the spelling and the key stays in its own variable,
where it is masked on the Health page and in Settings instead of sitting in a URL
that gets echoed back at you. Putting the key straight into `NFSEN_IPINFO_URL`
also works; it just forfeits that masking. The Health page flags the two ways the
pair can be set up wrong: a `{token}` placeholder with no key to fill it, and a
key with no placeholder to land in.

#### Services that work out of the box

Each of these was checked against nfsen-ng and renders a populated table with a
country flag. All but ipinfo.io work without an account.

| Service | `NFSEN_IPINFO_URL` | Free tier (as advertised) |
|---------|--------------------|---------------------------|
| [ipapi.co](https://ipapi.co/) _(default)_ | `https://ipapi.co/{ip}/json/` | 1 000/day, 30 000/month, no key |
| [ip-api.com](https://ip-api.com/) | `http://ip-api.com/json/{ip}` | 45/minute, no key; HTTPS is paid-only, hence the `http://` |
| [ipwho.is](https://ipwho.is/) | `https://ipwho.is/{ip}` | 1 000/day, no key |
| [freeipapi.com](https://freeipapi.com/) | `https://freeipapi.com/api/json/{ip}` | 60/minute, no key |
| [ipinfo.io](https://ipinfo.io/) | `https://ipinfo.io/{ip}/json?token={token}` | free token; their free *Lite* plan is unlimited but country-level only |

Quotas change without notice, so treat the last column as a hint about which
service to reach for, not a guarantee. Anything else that answers with a JSON
object works too, for example a geolocation service on your own network:

```bash
NFSEN_IPINFO_URL=http://geoip.internal.example/{ip}
```

#### What the dialog does with the response

The provider decides which fields are shown: nfsen-ng renders whatever JSON
comes back, one row per key, so a service returning more detail shows
more rows. Only two things are interpreted:

- **The country flag** uses `country_code`, falling back to a two-letter
  `country` or `countryCode`. It is omitted if none of them is present. The flag
  is an emoji rendered server-side, not an image fetched from a CDN, so nothing
  in the dialog reaches outside your network except the geolocation call itself.
  Its tooltip uses the country name, taken from whichever of `country_name`,
  `country` or `countryName` the provider filled in.
- **Errors** are shown as a message instead of an empty table. Recognised
  conventions are a truthy `error` (flat or nested `{"error": {"title",
  "message"}}`), `success: false`, `status: "fail"`, and any 4xx/5xx status.
  This is what surfaces `RateLimited` when a quota runs out, rather than
  leaving you guessing.

Nested values (ipwho.is's `connection`, for instance) are rendered as JSON.

### Reverse DNS

The IP info dialog also asks DNS for the host name of the address. Turn that off
under **Settings > Integrations > Reverse DNS** when the DNS server should not see
these lookups or answers slowly; the dialog then says the name was not looked up.
The switch is saved in `preferences.json`; there is no environment variable for it.

### Alert email

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_ALERT_EMAIL_FROM` | _(empty)_ | From-address for alert emails. Setting it enables the email alert channel; leaving it empty disables email delivery. Also settable as `general.alert_email_from`. |

### OpenSwoole server

| Variable | Default | Description |
|----------|---------|-------------|
| `SWOOLE_WORKER_NUM` | `1` | Worker processes. Leave it at `1`. php-via 0.13 can serve a tab from any worker, but nfsen-ng keeps its nfdump slots, running queries, filtered-graph cache and the server-owned tab signals (such as whether a query runs) in each worker's memory, so a Kill or a finished query could reach a worker that does not know the query. The inotify poll and the top-N collector's timer run on the first worker only, but every worker would run the start-up catch-up import against the same files and SQLite store. |
| `SWOOLE_MAX_REQUEST` | `0` | Requests per worker before restart. `0` (unlimited) is correct for a long-lived SSE server. |
| `SWOOLE_MAX_COROUTINE` | `10000` | Max concurrent coroutines / SSE connections. |

### PHP memory limit

One worker serves every browser tab, so its `memory_limit` is the memory of the
whole instance: when it runs out, every open session ends at once and the
restarted server begins with a catch-up import. The Docker images set
`memory_limit = 512M`, and `deploy/systemd/nfsen-ng.service` starts the server
with `php -d memory_limit=512M`. On bare metal without that unit, set the same:
a Debian or Ubuntu CLI `php.ini` usually says `-1`, no limit at all, which also
turns off the check that a Flows listing above 1,000 rows fits before it runs.
Each nfdump process has its own memory on top, outside PHP: about 150 to 200 MB
for `-s srcip` over 24 million flows.

## State & persistence

All of nfsen-ng's **own** persistent data lives under one directory,
`/var/lib/nfsen-ng`, in two subdirectories:

| Path | Contents |
|------|----------|
| `rrd/` (`NFSEN_RRD_PATH`) | RRD database files (unused for the VictoriaMetrics datasource) |
| `state/` (`NFSEN_STATE_DIR`) | `preferences.json` (preferences **and alert rule definitions**), `alerts-state.json` (which rules are firing, cooldowns), and `nfsen-ng.sqlite` with its `-wal`/`-shm` companions (saved filters, alert history, recorded query timings, per-interval top-N data) |

This is deliberately separate from the nfcapd **capture** tree (`/data/nfsen-ng`),
which the collector owns and which is usually managed on its own retention schedule.

**In Docker this must live on a volume**, or a container recreation (every image
upgrade) wipes your RRD graphs, preferences, saved filters and alerts:

- The image defaults `NFSEN_RRD_PATH` and `NFSEN_STATE_DIR` into `/var/lib/nfsen-ng`
  and declares it a `VOLUME`, so even a bare `docker run` persists in an anonymous
  volume.
- The shipped compose files map a **named** volume there (`nfsen-data`), which also
  survives `docker compose down` and is easy to back up. Unraid mounts
  `/mnt/user/appdata/nfsen-ng-data`.
- Dev (bind-mounted source) and bare-metal keep the built-in defaults
  (`backend/datasources/data` and `backend/settings`), next to the code.

The state directory has to be writable by the user nfsen-ng runs as: SQLite
writes its journal next to the database file. When it is not, or when the
`pdo_sqlite` driver is missing, the app still starts. Saved filters, the alert
history and the Overview top-N then show as unavailable with the reason, and the
**Storage (SQLite)** group on the Health page says what to fix.

To back up or migrate an instance, stop the app and copy `/var/lib/nfsen-ng`. That
single directory holds your graphs, your configuration and your saved filters. A
copy of the running app can catch the SQLite database mid-write; to back the
database up live, see [SQLite Store](../features/sqlite-store.md#backup).

## Settings file (deprecated)

> **Deprecated.** File-based config predates the environment-variable model and
> is kept only for backward compatibility. New installs should use `NFSEN_*`
> variables; an active `settings.php` is flagged on the Health page and may be
> removed in a future major release.

For a bare-metal path layout, copy the template and edit it:

```bash
cp backend/settings/settings.php.dist backend/settings/settings.php
```

The file must **assign the global `$nfsen_config` array**: nfsen-ng `include`s
it and reads that global. A file that `return`s an array (or defines any other
variable) is silently ignored and you get empty settings. The file is an overlay
on the environment: any key you set wins, and any key you omit falls back to the
matching `NFSEN_*` variable, then the built-in default.

```php
<?php
$nfsen_config = [
    'general' => [
        'sources' => ['gw1', 'router'],   // nfcapd sources; ['gw1' => 'Main office', ...] adds names
        'ports'   => [80, 443, 22],        // ports to track in RRD
        'filters' => ['proto tcp', 'dst port 80'], // presets for the saved filters
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
        'max-processes' => 'auto',         // or a number of parallel nfdump processes
        'workers'       => 2,              // -W per nfdump run; 0 = nfdump's default
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
| `general.filters` | Filter presets, added once to the saved filters (`string[]`) | a starter set |
| `general.db` | Datasource class name | `getenv('NFSEN_DATASOURCE') ?: 'RRD'` |
| `general.processor` | Flow processor | `'NfDump'` |
| `general.max_stats_window` | Query window cap in seconds (`0` = unlimited) | `0` |
| `general.netbox_url` / `general.netbox_token` | NetBox lookup | empty |
| `general.alert_email_from` | Alert email From-address | _(not in template)_ |
| `nfdump.binary` | nfdump path | `NFSEN_NFDUMP_BINARY`, else `/usr/bin/nfdump` |
| `nfdump.profiles-data` | Capture data root | `/var/nfdump/profiles-data` |
| `nfdump.profile` | Default profile | `live` |
| `nfdump.max-processes` | Parallel nfdump processes: `'auto'`, `0` or a number | `NFSEN_NFDUMP_MAX_PROCESSES`, else `'auto'` |
| `nfdump.workers` | `-W` per nfdump run (`0` to `16`) | `NFSEN_NFDUMP_WORKERS`, else `2` |
| `db.RRD.data_path` | RRD storage dir (`null` = default) | `null` |
| `db.<datasource>.import_years` | Years to import/retain | `3` |
| `log.priority` | Syslog level constant | `\LOG_INFO` |

> Note the key spelling: `nfdump.profiles-data` and `nfdump.max-processes` use
> **dashes**; `general.max_stats_window`, `general.netbox_url`, and
> `general.netbox_token` use **underscores**. `import_years` is read from under
> the sub-key that matches `general.db` (e.g. `db.RRD.import_years`).

The top-N retention and the GeoIP database have no `settings.php` key; set them
through their environment variables.

## Timezones

`nfcapd` names files using the local time of the host running it. nfsen-ng parses
those filenames back to epochs to place data on the timeline. If the two run in
different timezones (classic case: bare-metal `nfcapd` in CEST, nfsen-ng in a
`TZ=UTC` container), set `NFCAPD_TZ` to the capture host's IANA timezone. When
unset, nfsen-ng falls back to PHP's effective timezone. Getting this wrong
shifts every RRD timestamp and chart label by the UTC offset. The **nfcapd file
time** check on the Health page warns when the newest file's name is more than
30 minutes away from the time it was written, which is what a wrong `NFCAPD_TZ`
looks like.

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
is roughly the same size, about 5 MiB**, whether it's an aggregate or a
per-port file, and almost independent of `NFSEN_IMPORT_YEARS` (the daily archive
is a small fraction of the total). Budget accordingly:

- ~5 MiB per source (aggregate)
- ~5 MiB per tracked port, per source

So 10 sources × 5 tracked ports ≈ 60 files ≈ **~300 MB** total. This is a flat,
predictable cost: RRD files are circular buffers that never grow with traffic
volume. The SQLite store in the state directory comes on top; see
[Top-N data](#top-n-data).

### Changing retention depth

To store more (or fewer) years of daily data, set `NFSEN_IMPORT_YEARS` and then
recreate the RRD structure with **Rescan** on the **Health** page (the server
also logs a warning on start if it detects a size mismatch):

```yaml
# docker-compose.yml
environment:
  - NFSEN_IMPORT_YEARS=5   # 5 years of daily graph data
  # then run Rescan once from the Health page to rebuild the RRD files
```

### RRD vs nfcapd files

| | RRD (`.rrd`) | nfcapd (`nfcapd.*`) |
|--|--|--|
| **Purpose** | Graph counters (flows/packets/bytes) | Raw flow records for Top Talkers, Flows and Conversations |
| **Size** | Fixed (~5 MiB/file) | Grows with traffic |
| **Removed with nfcapd files?** | No, independent | n/a |
| **Queries work without them?** | n/a | No, nfdump reads them directly |
| **Retention control** | `NFSEN_IMPORT_YEARS` | Manage separately (e.g. cron + `find -mtime`) |
