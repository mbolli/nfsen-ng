# VictoriaMetrics Integration for nfsen-ng

## Overview

nfsen-ng now supports VictoriaMetrics as an alternative datasource to RRD. VictoriaMetrics offers several advantages:

- ✅ **Out-of-order writes** - Enables parallel import processing
- ✅ **HTTP API** - No need for PECL extensions
- ✅ **Automatic downsampling** - Similar to RRD's rollup archives
- ✅ **Scalability** - Better performance for large datasets
- ✅ **SQL-like queries** - MetricsQL (PromQL compatible)
- ✅ **Compressed storage** - Efficient disk usage

## Trade-offs vs RRD

| Feature | RRD | VictoriaMetrics |
|---------|-----|-----------------|
| Disk footprint | 🏆 ~1 MB (tiny files) | ~30 MB (binary + data) |
| Memory | 🏆 ~1 MB | ~100-500 MB |
| Query speed | Fast | Fast |
| Parallel writes | ❌ Sequential only | ✅ Yes |
| Setup complexity | Simple | Moderate (Docker) |
| Maturity | 🏆 20+ years | 5+ years |

## Installation

### 1. Start VictoriaMetrics with Docker Compose

```bash
# Use the VictoriaMetrics compose file
docker-compose -f docker-compose.victoriametrics.yml up -d
```

### 2. Configure nfsen-ng

Edit your `backend/settings/settings.php`:

```php
<?php
return [
    'general' => [
        'sources' => ['gateway', 'router'],
        'ports' => [80, 443, 22],
        'db' => 'VictoriaMetrics',  // Changed from 'Rrd'
    ],
    'nfdump' => [
        'binary' => '/usr/local/nfdump/bin/nfdump',
        'profiles-data' => '/var/nfdump/profiles_data',
        'profile' => 'live',
        'max-processes' => 1,
    ],
];
```

### 3. Environment Variables

Set these in your `.env` or `docker-compose.yml`:

```bash
# Datasource selection
NFSEN_DATASOURCE=VictoriaMetrics

# VictoriaMetrics connection
VM_HOST=victoriametrics
VM_PORT=8428

# Data retention
NFSEN_IMPORT_YEARS=3
```

### 4. Import Existing Data

```bash
# Force re-import (will write to VictoriaMetrics)
docker exec nfsen-ng php backend/cli.php -f -p -ps import

# Or start fresh import
docker exec nfsen-ng php backend/cli.php -p -ps import
```

## Architecture

### Data Model

VictoriaMetrics stores metrics with labels (similar to Prometheus):

```
nfsen_flows{source="gateway",protocol="tcp"} 12345 1698153600000
nfsen_packets{source="gateway",protocol="tcp"} 67890 1698153600000
nfsen_bytes{source="gateway",protocol="tcp"} 9876543 1698153600000
```

### Metric Names

- `nfsen_flows` - Total flows
- `nfsen_flows_tcp` - TCP flows
- `nfsen_flows_udp` - UDP flows
- `nfsen_flows_icmp` - ICMP flows
- `nfsen_flows_other` - Other protocol flows
- `nfsen_packets` - Total packets
- `nfsen_bytes` - Total bytes

### Labels

- `source` - Source name (e.g., "gateway", "router")
- `port` - Port number (e.g., "80", "443")
- `protocol` - Protocol (e.g., "tcp", "udp")

## Parallel Import

With VictoriaMetrics, you can parallelize the import process:

```php
// Example: Process multiple days in parallel with Swoole
use Swoole\Coroutine;

Coroutine\run(function () {
    $days = getDaysToImport();
    
    foreach ($days as $day) {
        Coroutine::create(function () use ($day) {
            processDay($day); // Each day can be processed independently
        });
    }
});
```

VictoriaMetrics will handle out-of-order writes automatically!

## Querying Data

### VictoriaMetrics UI

Access the built-in UI at: `http://localhost:8428/vmui`

### Example Queries

```promql
# Average TCP flows per second over 5 minutes
rate(nfsen_flows_tcp{source="gateway"}[5m])

# Total bytes across all sources
sum(nfsen_bytes)

# Top 5 sources by traffic
topk(5, sum by (source) (rate(nfsen_bytes[5m])))

# 95th percentile of packet rate
histogram_quantile(0.95, sum(rate(nfsen_packets[5m])) by (source))
```

## Monitoring VictoriaMetrics

Access metrics at: `http://localhost:8428/metrics`

Key metrics to watch:
- `vm_rows` - Total data points stored
- `vm_cache_size_bytes` - Cache usage
- `vm_free_disk_space_bytes` - Available disk space

## Backup & Restore

### Backup

```bash
# Create snapshot
curl http://localhost:8428/snapshot/create

# Snapshot will be at /victoria-metrics-data/snapshots/<snapshot_name>
```

### Restore

```bash
# Copy snapshot to new instance
docker cp vm-data:/victoria-metrics-data/snapshots/<snapshot_name> ./backup/

# Restore on new instance
docker cp ./backup/<snapshot_name> victoriametrics:/victoria-metrics-data/
```

## Migration from RRD

### Step 1: Export RRD Data (Optional)

If you want to keep historical data:

```bash
# The RRD data is small enough to keep alongside VM
# Just start importing to VM, old RRD files remain untouched
```

### Step 2: Switch Datasource

Change `settings.php`:
```php
'db' => 'VictoriaMetrics',
```

### Step 3: Import

```bash
# Import will now write to VictoriaMetrics
docker exec nfsen-ng php backend/cli.php -p import
```

## Performance Tuning

### VictoriaMetrics Settings

```yaml
command:
  # Increase retention
  - "--retentionPeriod=5y"
  
  # Increase memory for caching
  - "--memory.allowedPercent=80"
  
  # Faster ingestion
  - "--maxInsertRequestSize=32MB"
```

### PHP Settings

```ini
; Increase memory for large imports
memory_limit = 512M

; Increase curl timeout for queries
default_socket_timeout = 60
```

## Troubleshooting

### Connection Refused

```bash
# Check if VictoriaMetrics is running
docker ps | grep victoriametrics

# Check logs
docker logs victoriametrics

# Test connectivity
curl http://localhost:8428/health
```

### Slow Queries

```bash
# Check query performance in VM logs
docker logs victoriametrics | grep "slow query"

# Use smaller time ranges
# Increase maxrows parameter
```

### Import Errors

```bash
# Check nfsen-ng logs
docker logs nfsen-ng

# Verify VM is accessible from container
docker exec nfsen-ng curl http://victoriametrics:8428/health
```

## API Reference

### Write Data

```bash
# Prometheus format
curl -d 'nfsen_flows{source="test"} 123 1698153600000' \
  http://localhost:8428/api/v1/import/prometheus
```

### Query Data

```bash
# Range query
curl 'http://localhost:8428/api/v1/query_range?query=nfsen_flows&start=1698153600&end=1698240000&step=300'

# Instant query
curl 'http://localhost:8428/api/v1/query?query=nfsen_flows'
```

## Resources

- [VictoriaMetrics Documentation](https://docs.victoriametrics.com/)
- [MetricsQL Reference](https://docs.victoriametrics.com/metricsql/)
- [Best Practices](https://docs.victoriametrics.com/Single-server-VictoriaMetrics.html#capacity-planning)

## Support

For issues specific to the VictoriaMetrics integration:
1. Check VictoriaMetrics logs: `docker logs victoriametrics`
2. Check nfsen-ng logs: `docker logs nfsen-ng`
3. Open an issue on GitHub with relevant logs
