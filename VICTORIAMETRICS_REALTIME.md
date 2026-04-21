# Real-time Updates with VictoriaMetrics

## The Challenge

With RRD, you used **inotify** to watch for file changes:
```php
// RRD approach (file-based)
$inotify = inotify_init();
inotify_add_watch($inotify, '/path/to/data.rrd', IN_MODIFY);
// Wait for file modification events
```

VictoriaMetrics is a **remote database**, not files. You need a different approach.

## Solution Options

### Option 1: Polling (Recommended) ⭐

Poll VictoriaMetrics periodically to check for new data.

**Pros:**
- Simple and reliable
- Works with any database
- Low overhead (VM is very fast)
- No missed updates

**Cons:**
- Small delay (configurable, 1-5 seconds typical)

**Implementation:** See `VictoriaMetricsWatcher.php`

```php
// Check VM every 5 seconds for new data
$watcher = new VictoriaMetricsWatcher($queryUrl);
$watcher->setPollInterval(5000); // 5 seconds
$watcher->start();

// Subscribe to updates
$watcher->subscribe('my-channel', $myChannel);
```

### Option 2: Push from Import Process

Have the import process notify subscribers directly when writing data.

**Pros:**
- Instant updates (no polling delay)
- More efficient

**Cons:**
- Tightly coupled to import process
- Requires shared state mechanism

**Implementation:**
```php
// In Import.php after writing to VM
public function write(array $data): bool {
    $result = Config::$db->write($data);
    
    if ($result) {
        // Notify watchers
        $this->notifyWatchers($data['source'], $data['date_timestamp']);
    }
    
    return $result;
}
```

### Option 3: VictoriaMetrics Webhooks (Advanced)

VictoriaMetrics can send alerts to webhooks, which can trigger updates.

**Pros:**
- Event-driven
- Scalable

**Cons:**
- Complex setup
- Requires vmalert component
- Overkill for single-instance

### Option 4: Hybrid - Inotify on nfcapd files

Continue watching nfcapd files (not RRD files), trigger VM queries on new data.

**Pros:**
- Instant detection of new nfcapd files
- No polling overhead

**Cons:**
- Still file-system dependent
- Requires write access to nfcapd directory

**Implementation:**
```php
// Watch nfcapd files instead of RRD files
$inotify = inotify_init();
$path = '/var/nfdump/profiles_data/live/gateway/'.date('Y/m/d');
inotify_add_watch($inotify, $path, IN_CLOSE_WRITE);

// When new nfcapd file detected, notify subscribers
```

## Recommended Architecture

For nfsen-ng, I recommend **Option 1 (Polling)** with these settings:

```php
// In server.php.php or similar

// Create watcher
$vmHost = getenv('VM_HOST') ?: 'victoriametrics';
$vmPort = getenv('VM_PORT') ?: '8428';
$queryUrl = "http://{$vmHost}:{$vmPort}/api/v1/query_range";

$watcher = new VictoriaMetricsWatcher($queryUrl);

// Poll every 5 seconds (configurable)
$pollInterval = (int) (getenv('VM_POLL_INTERVAL') ?: 5000);
$watcher->setPollInterval($pollInterval);

// Start watching
$watcher->start();

// When SSE client connects, subscribe to watcher
$watcher->subscribe($clientId, $channel);
```

## Performance Comparison

### RRD Inotify
- **Latency:** <100ms (instant)
- **CPU:** Negligible (kernel events)
- **Memory:** Negligible
- **Overhead:** None

### VictoriaMetrics Polling (5s interval)
- **Latency:** 0-5 seconds (average 2.5s)
- **CPU:** ~0.1% (periodic HTTP query)
- **Memory:** Negligible
- **Overhead:** 1 HTTP request every 5 seconds

### VictoriaMetrics Polling (1s interval)
- **Latency:** 0-1 second (average 0.5s)
- **CPU:** ~0.5% (periodic HTTP query)
- **Memory:** Negligible
- **Overhead:** 1 HTTP request per second

## Query Optimization

The watcher uses an optimized query to check for updates:

```promql
# Get the most recent timestamp across all metrics
max(max_over_time(nfsen_flows[5m]))
```

This query:
- ✅ Very fast (milliseconds)
- ✅ Checks all sources/ports at once
- ✅ Uses VM's built-in aggregation
- ✅ Minimal data transfer

## Configuration

### Environment Variables

```bash
# Polling interval in milliseconds (default: 5000)
VM_POLL_INTERVAL=5000

# Alternative: Use push notifications from import
VM_USE_PUSH_NOTIFICATIONS=false
```

### Adjusting for Your Needs

```php
// Real-time dashboard (faster updates, more overhead)
$watcher->setPollInterval(1000); // 1 second

// Normal monitoring (balanced)
$watcher->setPollInterval(5000); // 5 seconds (default)

// Low-priority monitoring (less overhead)
$watcher->setPollInterval(30000); // 30 seconds
```

## Migration from RRD Inotify

### Before (RRD)
```php
// Watch RRD files
$watcher = new \Swoole\Process\Pool(1);
$watcher->on('workerStart', function($pool, $workerId) {
    $inotify = inotify_init();
    stream_set_blocking($inotify, false);
    
    // Watch each RRD file
    foreach ($sources as $source) {
        $rrdFile = "/path/to/{$source}.rrd";
        inotify_add_watch($inotify, $rrdFile, IN_MODIFY);
    }
    
    // Event loop
    while (true) {
        $events = inotify_read($inotify);
        if ($events) {
            notifySubscribers();
        }
        usleep(100000); // 100ms
    }
});
```

### After (VictoriaMetrics)
```php
// Poll VictoriaMetrics
$watcher = new VictoriaMetricsWatcher($queryUrl);
$watcher->setPollInterval(5000); // 5 seconds
$watcher->start();

// Subscribe
$watcher->subscribe($id, $channel);

// That's it! Much simpler.
```

## Advanced: Hybrid Approach

Best of both worlds - watch nfcapd files, but query VictoriaMetrics:

```php
// Watch nfcapd directory for new files
$nfcapdWatcher = new NfcapdWatcher('/var/nfdump/profiles_data');
$nfcapdWatcher->onNewFile(function($file) use ($vmWatcher) {
    // New nfcapd file detected, notify VM subscribers
    $vmWatcher->notifySubscribers([
        'type' => 'new_file',
        'file' => $file,
    ]);
});
```

## Monitoring the Watcher

Check watcher health:

```php
// Get watcher stats
$stats = [
    'subscribers' => count($watcher->getSubscribers()),
    'poll_interval' => $watcher->getPollInterval(),
    'last_check' => $watcher->getLastCheckTime(),
    'updates_sent' => $watcher->getUpdateCount(),
];
```

## Troubleshooting

### Updates are slow
```bash
# Reduce poll interval
VM_POLL_INTERVAL=1000 docker-compose up
```

### Too many requests to VM
```bash
# Increase poll interval
VM_POLL_INTERVAL=10000 docker-compose up
```

### No updates detected
```bash
# Check VM connectivity
curl http://localhost:8428/api/v1/query?query=nfsen_flows

# Check watcher logs
docker logs nfsen-ng | grep "VM watcher"
```

## Summary

| Method | Latency | Complexity | Recommended |
|--------|---------|------------|-------------|
| Polling (5s) | ~2.5s | Low | ✅ Yes |
| Polling (1s) | ~0.5s | Low | ✅ Good for real-time |
| Push from import | <100ms | Medium | For high-frequency |
| nfcapd inotify | <100ms | Medium | Hybrid approach |
| VM webhooks | <100ms | High | Enterprise only |

**For most use cases, polling every 5 seconds is the sweet spot** - simple, reliable, and imperceptible latency for monitoring dashboards.
