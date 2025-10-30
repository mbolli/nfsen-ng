#!/bin/bash
set -e

# Skip initial import if requested
if [ "${NFSEN_SKIP_INITIAL_IMPORT}" = "true" ] || [ "${NFSEN_SKIP_INITIAL_IMPORT}" = "1" ]; then
    echo "Skipping initial import (NFSEN_SKIP_INITIAL_IMPORT=true)"
else
    # Build import command with options
    IMPORT_CMD="php /var/www/html/nfsen-ng/backend/cli.php -p -ps"

    # Add verbose flag if requested
    if [ "${NFSEN_IMPORT_VERBOSE}" = "true" ] || [ "${NFSEN_IMPORT_VERBOSE}" = "1" ]; then
        IMPORT_CMD="${IMPORT_CMD} -v"
    fi

    # Check if force import is requested (will reset database and start from scratch)
    if [ "${NFSEN_FORCE_IMPORT}" = "true" ] || [ "${NFSEN_FORCE_IMPORT}" = "1" ]; then
        echo "Force import enabled - resetting database and importing from scratch..."
        IMPORT_CMD="${IMPORT_CMD} -f"
    else
        echo "Running initial import to catch up on missed nfcapd files..."
    fi

    # Run the import
    echo "Running: ${IMPORT_CMD} import"
    ${IMPORT_CMD} import
fi

# Start the Swoole import daemon (watches nfcapd files with inotify)
# The daemon also runs an initial import on startup, but since we just did one,
# it will find nothing new to import and proceed to watching for new files
echo "Starting Swoole import daemon..."
php /var/www/html/nfsen-ng/backend/cli.php start

# Wait a moment for daemon to initialize and complete initial import
sleep 3

# Check daemon status
php /var/www/html/nfsen-ng/backend/cli.php status

# Development mode: Use entr to auto-reload on file changes
echo "Starting Swoole HTTP server with auto-reload (entr)..."
echo "Watching: backend/*.php, frontend/*.js, frontend/*.css"

PID_FILE="/tmp/nfsen-ng-server.pid"

# Initial server start
php /var/www/html/nfsen-ng/backend/server.php &
echo $! > "$PID_FILE"
echo "Started Swoole server (PID: $(cat $PID_FILE))"

# Watch PHP/JS/CSS files and reload on changes
# Use entr without -r flag so we can manually control process termination
cd /var/www/html/nfsen-ng
while true; do
    # entr will exit when a file changes (without -r flag)
    find . -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \) 2>/dev/null | \
        grep -v vendor | grep -v node_modules | entr -dn echo "File changed"
    
    echo "File change detected, reloading server..."
    
    # Send SIGTERM to Swoole server
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if kill -0 "$PID" 2>/dev/null; then
            echo "Stopping Swoole server (PID: $PID)..."
            kill -TERM "$PID"
            
            # Wait for graceful shutdown (max 3 seconds)
            for i in {1..30}; do
                if ! kill -0 "$PID" 2>/dev/null; then
                    echo "Server stopped gracefully"
                    break
                fi
                sleep 0.1
            done
            
            # Force kill if still running
            if kill -0 "$PID" 2>/dev/null; then
                echo "Server did not stop gracefully, force killing..."
                kill -9 "$PID" 2>/dev/null || true
                sleep 0.5
            fi
        fi
    fi
    
    # Additional sleep to ensure port is fully released
    sleep 1
    
    # Start new server
    php /var/www/html/nfsen-ng/backend/server.php &
    echo $! > "$PID_FILE"
    echo "Started new Swoole server (PID: $(cat $PID_FILE))"
    
    # Small delay before watching again
    sleep 1
done
