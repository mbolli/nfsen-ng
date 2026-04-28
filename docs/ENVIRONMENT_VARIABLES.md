# Environment Variables

nfsen-ng supports various environment variables to customize behavior without modifying code.

## Configuration Philosophy

**Recommended Approach:** Use `NFSEN_SETTINGS_FILE` to point to a custom settings file for your environment. The settings file can read environment variables using `getenv()` for maximum flexibility.

**Quick Setup:** For simple deployments, you can use the environment variables directly (they have defaults in the distributed settings file).

---

## Core Configuration

### `NFSEN_SETTINGS_FILE`
**Type:** String (file path)  
**Default:** `backend/settings/settings.php`  
**Description:** Path to custom settings.php configuration file. This is the **recommended approach** for environment-specific configurations.

**Example:**
```yaml
volumes:
  - ./config/production-settings.php:/etc/nfsen-ng/settings.php:ro
environment:
  - NFSEN_SETTINGS_FILE=/etc/nfsen-ng/settings.php
```

**Use cases:**
- Different configs for dev/staging/prod environments
- Multiple instances with different sources/ports
- Keep configuration separate from codebase

---

## Import Configuration

### `NFSEN_IMPORT_VERBOSE`
**Type:** Boolean (`true`, `1`, `false`, `0`)  
**Default:** `false`  
**Description:** Shows verbose output during initial import.

**Example:**
```yaml
environment:
  - NFSEN_IMPORT_VERBOSE=true
```

---

### `NFSEN_SKIP_INITIAL_IMPORT`
**Type:** Boolean (`true`, `1`, `false`, `0`)  
**Default:** `false`  
**Description:** Skips the initial import on container startup. Useful when:
- Using an existing database (mounted volume)
- Testing the HTTP server without data
- Running multiple containers sharing the same database

**Example:**
```yaml
environment:
  - NFSEN_SKIP_INITIAL_IMPORT=true
```

---

### `NFSEN_IMPORT_YEARS`
**Type:** Integer  
**Default:** `3`  
**Description:** Number of years of historical data to import. Controls how far back in time the import will scan for nfcapd files. **This setting also determines the RRD database structure**, specifically the size of the daily data archive.

**Example:**
```yaml
environment:
  - NFSEN_IMPORT_YEARS=5  # Import last 5 years instead of 3
```

**Important Notes:**
- This setting affects the **RRD database structure** (daily sample storage capacity)
- RRD files are created with a fixed structure based on this value
- If you change `NFSEN_IMPORT_YEARS` after data has been imported, you'll see a warning about structure mismatch; use **Admin panel → Force Rescan** to rebuild.
- Larger values will increase initial startup time and database size

---

## OpenSwoole HTTP Server Configuration

### `SWOOLE_WORKER_NUM`
**Type:** Integer  
**Default:** `4`  
**Description:** Number of worker processes for handling HTTP requests. Should typically match your CPU core count for optimal performance.

**Example:**
```yaml
environment:
  - SWOOLE_WORKER_NUM=8  # For an 8-core server
```

**Recommendation:** Set to number of CPU cores. Check with `nproc` or `lscpu`.

---

### `SWOOLE_MAX_REQUEST`
**Type:** Integer  
**Default:** `0` (unlimited)  
**Description:** Maximum number of requests a worker will handle before being restarted. The default of `0` disables restarts, which is correct for long-lived SSE servers. Increase only if you observe memory growth over time.

**Example:**
```yaml
environment:
  - SWOOLE_MAX_REQUEST=50000
```

**Note:** Higher values = less frequent worker restarts but potentially more memory usage.

---

### `SWOOLE_MAX_COROUTINE`
**Type:** Integer  
**Default:** `10000`  
**Description:** Maximum number of concurrent coroutines (simultaneous SSE connections). Increase this if you have many concurrent users.

**Example:**
```yaml
environment:
  - SWOOLE_MAX_COROUTINE=20000
```

**Note:** Each SSE connection uses one coroutine. Increase for high-traffic deployments.

---

## Application Configuration

### `NFSEN_SETTINGS_FILE`
**Type:** String (file path)  
**Default:** `backend/settings/settings.php`  
**Description:** Path to custom settings.php configuration file. Allows using different configuration files for different environments without modifying code. This is the **recommended approach** for Docker deployments with environment-specific configurations.

**Example:**
```yaml
volumes:
  - ./config/production-settings.php:/etc/nfsen-ng/settings.php:ro
environment:
  - NFSEN_SETTINGS_FILE=/etc/nfsen-ng/settings.php
```

**Use cases:**
- Different configs for dev/staging/prod environments
- Multiple instances with different sources/ports/settings
- Keep sensitive configuration out of the main codebase
- Easier configuration management in containerized deployments

**Alternative approaches:**
1. **Mount over default file** (simpler):
   ```yaml
   volumes:
     - ./my-settings.php:/var/www/html/nfsen-ng/backend/settings/settings.php:ro
   ```

2. **Use environment variable** (more explicit):
   ```yaml
   volumes:
     - ./config:/config:ro
   environment:
     - NFSEN_SETTINGS_FILE=/config/settings-prod.php
   ```

---

## Application Configuration

### Settings File Environment Variables

These environment variables are read by the default `settings.php.dist` file. You can use them directly, or create your own settings file with custom logic.

#### `NFSEN_DATASOURCE`
**Type:** String  
**Default:** `RRD`  
**Options:** `RRD`, `VictoriaMetrics`  
**Description:** Which database backend to use.

#### `NFSEN_NFDUMP_BINARY`
**Type:** String (path)  
**Default:** `/usr/bin/nfdump`  
**Description:** Path to nfdump binary.

#### `NFSEN_NFDUMP_PROFILES`
**Type:** String (path)  
**Default:** `/var/nfdump/profiles-data`  
**Description:** Path to nfcapd data files.

#### `NFSEN_NFDUMP_PROFILE`
**Type:** String  
**Default:** `live`  
**Description:** Profile name to use.

#### `NFSEN_NFDUMP_MAX_PROCESSES`
**Type:** Integer  
**Default:** `1`  
**Description:** Maximum concurrent nfdump processes.

#### `NFSEN_RRD_PATH`
**Type:** String (path)  
**Default:** `backend/datasources/data`  
**Description:** Path where RRD database files are stored.

#### `VM_HOST`
**Type:** String (hostname)  
**Default:** `victoriametrics`  
**Description:** VictoriaMetrics server hostname.

#### `VM_PORT`
**Type:** Integer  
**Default:** `8428`  
**Description:** VictoriaMetrics server port.

**Example:**
```yaml
environment:
  - NFSEN_DATASOURCE=VictoriaMetrics
  - VM_HOST=victoriametrics
  - VM_PORT=8428
  - NFSEN_NFDUMP_PROFILES=/data/nfsen-ng
  - NFSEN_RRD_PATH=/var/nfsen-ng/rrd
```

---

### System Configuration

### `TZ`
**Type:** String (timezone identifier)  
**Default:** `UTC`  
**Description:** Sets the container timezone. Use standard timezone identifiers.

**Example:**
```yaml
environment:
  - TZ=Europe/Zurich
```

---

### `NFSEN_LOG_LEVEL`
**Type:** String  
**Default:** `INFO`  
**Options:** `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERR`/`ERROR`, `CRIT`, `ALERT`, `EMERG` (or with `LOG_` prefix)  
**Description:** Application-level logging verbosity. Controls both application logs (to syslog/console) and OpenSwoole's internal logging.

**Example:**
```yaml
environment:
  - NFSEN_LOG_LEVEL=DEBUG
  # or
  - NFSEN_LOG_LEVEL=LOG_DEBUG
```

**Levels (from most to least verbose):**
- `DEBUG` - Detailed debugging information (application + OpenSwoole internals)
- `INFO` - General informational messages
- `NOTICE` - Normal but significant conditions
- `WARNING` - Warning conditions
- `ERR`/`ERROR` - Error conditions
- `CRIT` - Critical conditions
- `ALERT` - Action must be taken immediately
- `EMERG` - System is unusable

**Note:** This single variable controls logging for both the nfsen-ng application and the OpenSwoole HTTP server.

---

## Example Configurations

### Production (minimal logging)
```yaml
environment:
  - TZ=Europe/Zurich
  - NFSEN_LOG_LEVEL=INFO
  - SWOOLE_WORKER_NUM=8
  - NFSEN_RRD_PATH=/var/nfsen-ng/rrd
volumes:
  - rrd-data:/var/nfsen-ng/rrd
```

### Development (verbose, skip initial import)
```yaml
environment:
  - TZ=UTC
  - NFSEN_SKIP_INITIAL_IMPORT=true
  - NFSEN_IMPORT_VERBOSE=true
  - NFSEN_LOG_LEVEL=DEBUG
```

### Extended import retention
```yaml
environment:
  - TZ=UTC
  - NFSEN_IMPORT_VERBOSE=true
  - NFSEN_IMPORT_YEARS=5
```

### High-Performance Setup
```yaml
environment:
  - TZ=UTC
  - SWOOLE_WORKER_NUM=16
  - SWOOLE_MAX_COROUTINE=50000
  - NFSEN_LOG_LEVEL=WARNING
```

### Multiple Environments with Different Settings
```yaml
# Production
environment:
  - NFSEN_SETTINGS_FILE=/config/settings-prod.php
  - NFSEN_RRD_PATH=/var/nfsen-ng/rrd
  - TZ=Europe/Zurich
volumes:
  - ./config/settings-prod.php:/config/settings-prod.php:ro
  - rrd-data:/var/nfsen-ng/rrd

# Staging (different sources/ports)
environment:
  - NFSEN_SETTINGS_FILE=/config/settings-staging.php
  - NFSEN_RRD_PATH=/var/nfsen-ng/rrd
  - TZ=UTC
volumes:
  - ./config/settings-staging.php:/config/settings-staging.php:ro
  - staging-rrd-data:/var/nfsen-ng/rrd
```

### Import Last 10 Years of Data
```yaml
environment:
  - TZ=UTC
  - NFSEN_IMPORT_YEARS=10
  - NFSEN_IMPORT_VERBOSE=true
```

---

## Implementation Status

✅ **Fully Implemented:**

**Core:**
- `NFSEN_SETTINGS_FILE` - Custom settings file path

**Import Control:**
- `NFSEN_IMPORT_VERBOSE` - Verbose import output
- `NFSEN_SKIP_INITIAL_IMPORT` - Skip startup import
- `NFSEN_IMPORT_YEARS` - Configurable import timeframe

**Application (via settings.php):**
- `NFSEN_DATASOURCE` - Database backend selection
- `NFSEN_RRD_PATH` - RRD data storage path
- `NFSEN_NFDUMP_BINARY` - nfdump binary path
- `NFSEN_NFDUMP_PROFILES` - nfcapd data path
- `NFSEN_NFDUMP_PROFILE` - Profile name
- `NFSEN_NFDUMP_MAX_PROCESSES` - Max concurrent processes
- `VM_HOST` / `VM_PORT` - VictoriaMetrics connection

**Performance:**
- `SWOOLE_WORKER_NUM` - Worker process count
- `SWOOLE_MAX_REQUEST` - Max requests per worker
- `SWOOLE_MAX_COROUTINE` - Max concurrent connections
- `NFSEN_LOG_LEVEL` - Logging verbosity

**System:**
- `TZ` - Timezone configuration
