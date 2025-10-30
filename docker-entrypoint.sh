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
    ${IMPORT_CMD} import
fi

# Start the Swoole import daemon (watches nfcapd files with inotify)
# The daemon also runs an initial import on startup, but since we just did one,
# it will find nothing new to import and proceed to watching for new files
echo "Starting Swoole import daemon..."
php /var/www/html/nfsen-ng/backend/cli.php start

# Wait a moment for daemon to initialize
sleep 3

# Check daemon status
php /var/www/html/nfsen-ng/backend/cli.php status

# Execute container command (Swoole HTTP server)
echo "Starting Swoole HTTP server..."
exec "$@"
