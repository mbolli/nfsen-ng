#!/bin/bash
set -e

# Development mode: Use entr to auto-reload on file changes
# The embedded ImportDaemon handles the catch-up import + inotify watch.
echo "Starting Swoole HTTP server with auto-reload (entr)..."
echo "Watching: backend/*.php, frontend/*.js, frontend/*.css, backend/templates/*.twig"

export PID_FILE="/tmp/nfsen-ng-server.pid"
export ENTRY_SCRIPT="/var/www/html/nfsen-ng/backend/app.php"

# SIGTERM runs the server's onShutdown in every worker; wait for it (10 s at most) so a
# reload never overlaps two servers and a stop is not cut off.
stop_server() {
    local pid
    pid=$(cat "$PID_FILE" 2>/dev/null) || return 0
    kill -TERM "$pid" 2>/dev/null || return 0
    for _ in $(seq 100); do
        kill -0 "$pid" 2>/dev/null || return 0
        sleep 0.1
    done
}
export -f stop_server

# entr runs its command once when it starts, and that run starts the server; a PID file left
# by an earlier run of this container must not be signalled then.
rm -f "$PID_FILE"

# Restart the server when a watched PHP, JS, CSS or Twig file changes.
cd /var/www/html/nfsen-ng
watch_files() {
    while true; do
        echo "Watching for file changes..."
        find . -type f \( -name '*.php' -o -name '*.js' -o -name '*.css' -o -name '*.twig' \) 2>/dev/null | \
            grep -v vendor | grep -v node_modules | \
            entr -dn bash -c "echo 'File change detected, reloading server...'; stop_server; \
                php ${ENTRY_SCRIPT} & echo \$! > $PID_FILE; echo \"Started new server (PID: \$(cat $PID_FILE))\"" \
            || true
        # entr -d exits with status 2 when a file is added to a watched dir; without `|| true`,
        # set -e would end this script, PID 1, and the container with it.
        echo "Restarting file watcher..."
        sleep 0.5
    done
}

# PID 1 ignores a signal it has no trap for, and bash runs a trap only once the foreground
# command returns, which entr never does: so the watcher runs in the background.
watch_files &
WATCHER=$!
trap 'kill "$WATCHER" 2>/dev/null; echo "Stopping nfsen-ng server..."; stop_server; exit 0' TERM INT
wait "$WATCHER"
