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
ENTRY_SCRIPT="/var/www/html/nfsen-ng/backend/app.php"
php "${ENTRY_SCRIPT}" &
SERVER_PID=$!
echo $SERVER_PID > "$PID_FILE"
echo "Started nfsen-ng server (PID: $SERVER_PID)"

# Watch PHP/JS/CSS files and reload on changes using entr -r (auto-restart on change)
cd /var/www/html/nfsen-ng
while true; do
    echo "Watching for file changes..."
    # entr -r kills and restarts the command when files change
    # We wrap the kill+start in a subshell so we can update the PID file
    find . -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' \) 2>/dev/null | \
        grep -v vendor | grep -v node_modules | \
        entr -dn sh -c "echo 'File change detected, reloading server...'; \
            kill \$(cat $PID_FILE) 2>/dev/null; sleep 1; \
            php ${ENTRY_SCRIPT} & echo \$! > $PID_FILE; echo \"Started new server (PID: \$(cat $PID_FILE))\""
    # entr -d exits when a new file appears in a watched dir; restart the watch loop
    echo "Restarting file watcher..."
    sleep 0.5
done
