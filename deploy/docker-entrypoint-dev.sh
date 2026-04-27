#!/bin/bash
set -e

# Development mode: Use entr to auto-reload on file changes
# The embedded ImportDaemon handles the catch-up import + inotify watch.
echo "Starting Swoole HTTP server with auto-reload (entr)..."
echo "Watching: backend/*.php, frontend/*.js, frontend/*.css, backend/templates/*.twig"

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
    find . -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.twig' \) 2>/dev/null | \
        grep -v vendor | grep -v node_modules | \
        entr -dn sh -c "echo 'File change detected, reloading server...'; \
            kill \$(cat $PID_FILE) 2>/dev/null; sleep 1; \
            php ${ENTRY_SCRIPT} & echo \$! > $PID_FILE; echo \"Started new server (PID: \$(cat $PID_FILE))\""
    # entr -d exits when a new file appears in a watched dir; restart the watch loop
    echo "Restarting file watcher..."
    sleep 0.5
done
