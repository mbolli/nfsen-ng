# VictoriaMetrics

[VictoriaMetrics](https://victoriametrics.com/) is an optional alternative to the
default RRD datasource — useful when you want parallel/out-of-order imports,
longer retention, or PromQL/MetricsQL for ad-hoc queries. The architectural
comparison is in [Data Sources](../architecture/data-sources.md); this page is
the setup guide.

## Trade-offs vs RRD

| | RRD | VictoriaMetrics |
|--|-----|-----------------|
| Extra infrastructure | none (a PHP extension) | a separate VM service |
| Disk footprint | fixed ~5 MiB per source/port file | grows with retention (typically tens of MB+) |
| Parallel / out-of-order writes | no | yes |
| Query language | RRDtool | MetricsQL (PromQL-compatible) |
| HTTP query API | no | yes |
| Setup | simple | moderate |

## Setup

### 1. Start VictoriaMetrics

`deploy/docker-compose.victoriametrics.yml` brings up a full stack —
`victoriametrics`, `nfsen`, and `caddy` — with the app already pointed at VM:

```bash
docker compose -f deploy/docker-compose.victoriametrics.yml up -d
```

VM listens on `8428`; the UI is fronted by Caddy. To add VM to an existing
deployment instead, run a `victoriametrics/victoria-metrics` container reachable
from the app and set the variables below.

### 2. Point nfsen-ng at it

Via environment variables (recommended):

| Variable | Default | Description |
|----------|---------|-------------|
| `NFSEN_DATASOURCE` | `RRD` | Set to `VictoriaMetrics` to activate. |
| `VM_HOST` | `victoriametrics` | VictoriaMetrics hostname. |
| `VM_PORT` | `8428` | VictoriaMetrics HTTP port. |
| `NFSEN_IMPORT_YEARS` | `3` | Lookback window for import and boundary queries. |

Or with a settings file (`backend/settings/settings.victoriametrics.dist` is a
ready template). Remember the file must assign the global `$nfsen_config` array,
not `return` one:

```php
<?php
$nfsen_config = [
    'general' => [
        'sources' => ['gw1', 'router'],
        'ports'   => [80, 443, 22],
        'db'      => 'VictoriaMetrics',
    ],
    'db' => [
        'VictoriaMetrics' => [
            'host' => 'victoriametrics',
            'port' => 8428,
            'import_years' => 3,
        ],
    ],
    // ... nfdump paths, log, etc.
];
```

### 3. Import

On a fresh install, run the first import from **Settings → Import → Trigger
Import** in the web UI. To rebuild from scratch, use **Force Rescan**. `nfcapd`
files are still required — VictoriaMetrics is a storage backend, not a
replacement for the raw captures nfdump reads for Flows and Statistics.

## Data model

nfsen-ng writes Prometheus-exposition samples to
`http://<host>:<port>/api/v1/import/prometheus` (millisecond timestamps) and
queries them back through VM's HTTP API (`/api/v1/query`,
`/api/v1/query_range`, `tfirst_over_time`/`tlast_over_time`).

Metric names are `nfsen_<type>` for `type` in `flows`, `packets`, `bytes`, with a
protocol suffix for the per-protocol series:

```
nfsen_flows{source="gw",profile="live"}                 12345   # aggregate (no port label)
nfsen_flows{source="gw",port="80",profile="live"}       4321    # port-specific
nfsen_flows_tcp{source="gw",protocol="tcp",profile="live"} 8000 # TCP only
nfsen_bytes{source="gw",profile="live"}                 9876543
nfsen_packets{source="gw",profile="live"}               67890
```

Labels:

- `source` — the source name.
- `port` — port number string; **absent** on aggregate totals. On the read path
  the aggregate is selected with `port=""`, which matches series where the label
  is absent (so sparse per-port series don't shadow the dense aggregate).
- `protocol` — `tcp`/`udp`/`icmp`/`other` on the per-protocol series (redundant
  with the metric-name suffix); absent on the aggregate.
- `profile` — the nfdump profile, e.g. `profile="live"`. Data written before
  profiles existed has no label and is matched on read via `profile=~"live|"`.

## Real-time updates

RRD relies on inotify to detect new files directly. With VictoriaMetrics — a
remote database — the embedded import daemon instead broadcasts a re-render to
all SSE clients after each successful write, and a `VictoriaMetricsWatcher` polls
VM's health/readiness so the Admin panel can report connectivity rather than just
config sanity. No browser polling is involved either way.

## Migrating between RRD and VictoriaMetrics

1. Change `general.db` (or `NFSEN_DATASOURCE`) to the target datasource.
2. Run **Force Rescan** to populate it from the `nfcapd` files.
3. The two datasources are independent — switching to VM does not touch existing
   `.rrd` files, and you can roll back by setting the datasource to `RRD` again.
   Remove the now-unused `backend/datasources/data/*.rrd` only after verifying the
   VM data is complete.

## Seeding demo / development data

`scripts/seed_vm_data.php` pushes realistic synthetic NetFlow data into VM — handy
for demos, screenshots, and testing without a live `nfcapd` source. (The RRD
equivalent is `scripts/seed_rrd_data.php`.)

```bash
php scripts/seed_vm_data.php [--host=localhost] [--port=8428] [--source=all] [--days=90] [--ports=80,443]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `VM_HOST` env, else `localhost` | VictoriaMetrics hostname |
| `--port` | `VM_PORT` env, else `8428` | VictoriaMetrics port |
| `--source` | `all` | `source=` label value |
| `--days` | `90` | Days of history to generate |
| `--ports` | _(none)_ | Comma-separated ports to also emit, e.g. `80,443,22` |

It generates diurnal traffic with a realistic protocol mix (TCP 63 % / UDP 22 % /
ICMP 4 % / Other 11 %), weekend dips (~30 % quieter), and ±15 % Gaussian noise —
peaking around 25 000 flow events per 5-minute slot at the busiest hour — using
the same metric names and labels `VictoriaMetrics::write()` produces, so the
output shows up immediately in the graphs. Unlike the RRD seeder (which needs the
app's config and an RRD datasource, so it runs inside the container), this script
only needs PHP and network reach to VM.

> **Warning:** only run this against a development or demo VictoriaMetrics
> instance — it writes a large volume of fake flow events.
