#!/bin/bash
set -e

# Start the nfsen-ng HTTP server (php-via / OpenSwoole).
# The embedded ImportDaemon runs the initial catch-up import and inotify watch
# as startup coroutines — no external cli.php / listen.php needed.
# Use NFSEN_SKIP_DAEMON=1 to disable the import daemon (e.g. for testing).
echo "Starting nfsen-ng HTTP server (php-via)..."
exec php /var/www/html/nfsen-ng/backend/app.php
