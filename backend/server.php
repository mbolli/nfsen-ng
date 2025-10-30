#!/usr/bin/env php
<?php

/**
 * HTTP Server for nfsen-ng.
 *
 * Built with Swoole which provides:
 * - Async I/O for efficient SSE handling (1000s of connections)
 * - Coroutines for non-blocking operations
 * - Built-in HTTP server (no Apache/Nginx needed)
 * - Low memory footprint per connection
 *
 * Each SSE connection runs in its own coroutine, not blocking workers.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\routes\FlowActionsHandler;
use mbolli\nfsen_ng\routes\GraphStreamHandler;
use mbolli\nfsen_ng\routes\ServerStatsHandler;
use mbolli\nfsen_ng\routes\StatsActionsHandler;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Lock;
use Swoole\Table;

// Initialize config once at server startup
Config::initialize(true);
$debug = Debug::getInstance();

// Create shared memory table for ALL SSE connections (shared across workers)
// Tracks both graph subscribers (with channels) and action-based connections
// Key: connection ID, Value: connection metadata
// This table is shared across all workers via shared memory (no locks needed!)
$subscriberTable = new Table(1024);
$subscriberTable->column('type', Table::TYPE_STRING, 64);        // 'graphs', 'flow-actions', 'stats-actions'
$subscriberTable->column('worker_id', Table::TYPE_INT);          // Which worker handles this connection
$subscriberTable->column('connected_at', Table::TYPE_INT);       // Unix timestamp
$subscriberTable->column('remote_addr', Table::TYPE_STRING, 64); // Client IP address
$subscriberTable->create();

// Per-worker subscriber channels (not shared across workers)
// Only used for graph streams that need push notifications
// Key: subscriber ID, Value: Swoole\Coroutine\Channel
$rrdSubscribers = [];
$subscribersLock = new Lock(SWOOLE_MUTEX);
$rrdWatcherStarted = false;
$currentWorkerId = -1; // Will be set in workerStart

// Per-worker server stats subscribers (receives connect/disconnect events)
$statsSubscribers = [];
$statsLock = new Lock(SWOOLE_MUTEX);

// Create Swoole HTTP server on port 9000 (Caddy proxies to this)
$server = new Server('0.0.0.0', 9000);

// Configure server settings (with environment variable support)
$workerNum = (int) (getenv('SWOOLE_WORKER_NUM') ?: 4);
$maxRequest = (int) (getenv('SWOOLE_MAX_REQUEST') ?: 10000);
$maxCoroutine = (int) (getenv('SWOOLE_MAX_COROUTINE') ?: 10000);

// Use NFSEN_LOG_LEVEL for both application and Swoole logging
$logLevelEnv = getenv('NFSEN_LOG_LEVEL') ?: 'INFO';
$swooleLogLevelMap = [
    'LOG_EMERG' => SWOOLE_LOG_ERROR,
    'LOG_ALERT' => SWOOLE_LOG_ERROR,
    'LOG_CRIT' => SWOOLE_LOG_ERROR,
    'LOG_ERR' => SWOOLE_LOG_ERROR,
    'LOG_WARNING' => SWOOLE_LOG_WARNING,
    'LOG_NOTICE' => SWOOLE_LOG_NOTICE,
    'LOG_INFO' => SWOOLE_LOG_INFO,
    'LOG_DEBUG' => SWOOLE_LOG_DEBUG,
    'EMERG' => SWOOLE_LOG_ERROR,
    'ALERT' => SWOOLE_LOG_ERROR,
    'CRIT' => SWOOLE_LOG_ERROR,
    'ERR' => SWOOLE_LOG_ERROR,
    'ERROR' => SWOOLE_LOG_ERROR,
    'WARNING' => SWOOLE_LOG_WARNING,
    'NOTICE' => SWOOLE_LOG_NOTICE,
    'INFO' => SWOOLE_LOG_INFO,
    'DEBUG' => SWOOLE_LOG_DEBUG,
];
$swooleLogLevel = $swooleLogLevelMap[$logLevelEnv] ?? SWOOLE_LOG_INFO;

$server->set([
    'worker_num' => $workerNum,           // Number of worker processes (CPU cores)
    'max_request' => $maxRequest,         // Restart worker after N requests (prevents memory leaks)
    'max_coroutine' => $maxCoroutine,     // Max concurrent coroutines (SSE connections)
    'enable_coroutine' => true,           // Enable coroutines for async I/O
    'hook_flags' => SWOOLE_HOOK_ALL,      // Hook all blocking functions for async
    'log_level' => $swooleLogLevel,
    'log_file' => '/tmp/swoole.log',
    'reload_async' => true,               // Enable async reload (graceful shutdown)
    'max_wait_time' => 3,                 // Max seconds to wait for workers to finish on reload
    'heartbeat_check_interval' => 60,     // Check for dead connections every 60 seconds
    'heartbeat_idle_time' => 600,         // Close connections idle for 10 minutes
]);

$debug->log("Swoole server configured: workers={$workerNum}, max_request={$maxRequest}, max_coroutine={$maxCoroutine}, log_level={$logLevelEnv}", LOG_INFO);

// Server start event
$server->on('start', function (Server $server) use ($debug): void {
    $debug->log('Swoole HTTP server started on http://0.0.0.0:9000', LOG_INFO);
    $debug->log('Worker processes: ' . $server->setting['worker_num'], LOG_INFO);
    $debug->log('Max coroutines: ' . $server->setting['max_coroutine'], LOG_INFO);
});

// Worker start event (runs in each worker process)
$server->on('workerStart', function (Server $server, int $workerId) use ($debug, &$rrdWatcherStarted, &$rrdSubscribers, $subscribersLock, $subscriberTable, &$currentWorkerId): void {
    $currentWorkerId = $workerId;
    $debug->log("Worker #{$workerId} started (PID: " . posix_getpid() . ')', LOG_DEBUG);

    // Re-initialize config in each worker to avoid shared memory issues
    Config::initialize(true);

    // Start shared RRD file watcher in worker 0 only
    if ($workerId === 0 && !$rrdWatcherStarted) {
        $rrdWatcherStarted = true;
        startSharedRRDWatcher($server, $subscriberTable, $rrdSubscribers, $subscribersLock, $debug);
    }
});

// Handle messages from other workers (RRD update broadcasts and server stats)
$server->on('pipeMessage', function (Server $server, int $srcWorkerId, mixed $message) use ($debug, &$rrdSubscribers, $subscribersLock, &$statsSubscribers, $statsLock): void {
    $eventData = json_decode((string) $message, true);

    if ($eventData && $eventData['type'] === 'rrd_update') {
        $subscriberCount = count($rrdSubscribers);
        $debug->log("Worker received RRD update from worker #{$srcWorkerId}, pushing to {$subscriberCount} local subscribers", LOG_DEBUG);

        // Push to all local subscriber channels
        $subscribersLock->lock();
        foreach ($rrdSubscribers as $id => $channel) {
            if (!$channel->push($eventData, 0.001)) {
                $debug->log("Failed to push to subscriber {$id}, channel may be full", LOG_WARNING);
            }
        }
        $subscribersLock->unlock();
    } elseif ($eventData && $eventData['type'] === 'server_stats') {
        // Push to server stats subscribers (if any)
        $statsSubscriberCount = count($statsSubscribers);
        if ($statsSubscriberCount > 0) { // @phpstan-ignore greater.alwaysFalse
            $event = $eventData['event'];
            $connectionType = $eventData['connection']['type'] ?? 'unknown';

            $debug->log("Server stats event: {$event} ({$connectionType}), pushing to {$statsSubscriberCount} local stats subscribers", LOG_DEBUG);

            $statsLock->lock();
            foreach ($statsSubscribers as $id => $channel) {
                if (!$channel->push($eventData, 0.001)) {
                    $debug->log("Failed to push to stats subscriber {$id}", LOG_WARNING);
                }
            }
            $statsLock->unlock();
        }
    }
});

// Handle HTTP requests
$server->on('request', function (Request $request, Response $response) use ($debug, &$rrdSubscribers, $subscribersLock, $subscriberTable, &$currentWorkerId, $server, &$statsSubscribers, $statsLock): void {
    $path = $request->server['request_uri'] ?? '/';

    // Parse path (remove query string)
    $route = strtok($path, '?');

    // Route requests
    if (str_starts_with($route, '/api/')) {
        // API endpoints - includes both SSE streams and direct API calls
        handleApiRequest($request, $response, $route, $debug, $rrdSubscribers, $subscribersLock, $subscriberTable, $currentWorkerId, $server, $statsSubscribers, $statsLock);
    } elseif ($route === '/' || $route === '/index.php') {
        // Main page - serve index.php
        handleBackendRequest($request, $response, $route, $debug);
    } else {
        // For other requests (static files), return 404 - Caddy handles these
        $response->status(404);
        $response->end('Not Found - Static files should be served by Caddy');
    }
});

$server->start();

/**
 * Handle API requests - includes both SSE streams and direct API calls.
 */
function handleApiRequest(Request $request, Response $response, string $route, Debug $debug, array &$rrdSubscribers, Lock $subscribersLock, Table $subscriberTable, int $currentWorkerId, Server $server, array &$statsSubscribers, Lock $statsLock): void {
    // Extract endpoint
    $endpoint = str_replace('/api/', '', $route);
    $endpoint = strtok($endpoint, '?');
    $endpoint = rtrim($endpoint, '/'); // Remove trailing slashes

    // Route to SSE stream handlers (actual streaming endpoints)
    if (in_array($endpoint, ['graphs', 'server-stats'], true)) {
        handleStreamRequest($request, $response, $route, $debug, $rrdSubscribers, $subscribersLock, $subscriberTable, $currentWorkerId, $server, $statsSubscribers, $statsLock);

        return;
    }

    // Route action handlers (single request/response with SSE format)
    if (in_array($endpoint, ['flow-actions', 'stats-actions', 'host'], true)) {
        handleActionRequest($request, $response, $endpoint, $debug);

        return;
    }

    // Unknown endpoint
    $response->status(404);
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode(['error' => '404 - API endpoint not found']));
}

/**
 * Handle action requests (flow-actions, stats-actions).
 * These are single request/response cycles that return SSE-formatted output.
 */
function handleActionRequest(Request $request, Response $response, string $endpoint, Debug $debug): void {
    // Set SSE headers
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('Connection', 'keep-alive');
    $response->header('X-Accel-Buffering', 'no');

    // Create SSE generator
    $sse = new ServerSentEventGenerator();

    try {
        switch ($endpoint) {
            case 'flow-actions':
                $handler = new FlowActionsHandler($debug, $sse);
                $handler->handle($request, $response);

                break;

            case 'stats-actions':
                $handler = new StatsActionsHandler($debug, $sse);
                $handler->handle($request, $response);

                break;

            case 'host':
                handleHostApi($request, $response, $debug, $sse);

                break;

            default:
                $response->status(404);
                $response->end("event: error\ndata: Unknown action endpoint\n\n");

                break;
        }
    } catch (Throwable $e) {
        $debug->log("Action error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}", LOG_ERR);
        $response->end("event: error\ndata: " . $e->getMessage() . "\n\n");
    }
}

/**
 * Handle /api/host endpoint - hostname resolution.
 * Returns SSE with patchElement to update modal content.
 */
function handleHostApi(Request $request, Response $response, Debug $debug, ServerSentEventGenerator $sse): void {
    $params = $request->get ?? [];
    $ip = $params['ip'] ?? '';

    // Validate IP
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        $fragment = '<div class="alert alert-danger">Invalid IP address</div>';
        $response->end($sse->patchElements($fragment, [
            'selector' => '#ip-modal-body',
            'mode' => ElementPatchMode::Inner,
        ]));

        return;
    }

    // Try PHP's built-in gethostbyaddr() first (no external dependencies)
    $hostname = @gethostbyaddr($ip);

    // If gethostbyaddr() returns the IP itself, it failed to resolve
    if ($hostname === $ip || $hostname === false) {
        // Fallback to host command
        $escapedIp = escapeshellarg((string) $ip);
        exec("host -W 5 {$escapedIp} 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            // Show the actual error message from the command
            $errorMsg = !empty($output) ? implode(' ', $output) : 'IP could not be resolved';
            $hostname = 'Error: ' . $errorMsg;
        } else {
            // Parse output for domain names
            $domains = [];
            foreach ($output as $line) {
                if (preg_match('/domain name pointer (.*)\./', $line, $matches)) {
                    $domains[] = $matches[1];
                }
            }
            $hostname = empty($domains) ? 'IP could not be resolved' : implode(', ', $domains);
        }
    }

    // Send hostname as fragment that updates the modal
    $fragment = '<h4>Host: ' . htmlspecialchars($hostname) . '</h4>';
    $response->end($sse->patchElements($fragment, [
        'selector' => '#ip-host-result',
        'mode' => ElementPatchMode::Inner,
    ]));
}

/**
 * Broadcast server stats to all workers.
 * This allows real-time monitoring of connection counts and events.
 * Reads from the shared subscriberTable for accurate cross-worker stats.
 */
function broadcastServerStats(Server $server, Table $subscriberTable, string $event, array $connectionInfo, int $currentWorkerId, array &$statsSubscribers, Lock $statsLock): void {
    $stats = [
        'type' => 'server_stats',
        'event' => $event, // 'connect' or 'disconnect'
        'connection' => $connectionInfo,
        'total_connections' => count($subscriberTable),
        'connections_by_type' => [],
        'timestamp' => time(),
    ];

    // Count connections by type from shared table
    foreach ($subscriberTable as $id => $row) {
        $type = $row['type'];
        if (!isset($stats['connections_by_type'][$type])) {
            $stats['connections_by_type'][$type] = 0;
        }
        ++$stats['connections_by_type'][$type];
    }

    // Broadcast to all OTHER workers via sendMessage() (can't send to self)
    $workerCount = $server->setting['worker_num'];
    $encodedMessage = json_encode($stats);

    for ($workerId = 0; $workerId < $workerCount; ++$workerId) {
        if ($workerId !== $currentWorkerId) {
            $server->sendMessage($encodedMessage, $workerId);
        }
    }

    // Handle local stats subscribers (current worker) directly
    $localStatsCount = count($statsSubscribers);
    if ($localStatsCount > 0) {
        $statsLock->lock();
        foreach ($statsSubscribers as $id => $channel) {
            if (!$channel->push($stats, 0.001)) {
                // Channel full, skip
            }
        }
        $statsLock->unlock();
    }
}

/**
 * Start a shared RRD file watcher that broadcasts to all workers.
 * Runs in worker 0 only, uses a single inotify instance for efficiency.
 * Broadcasts events to all workers via sendMessage() and locally to worker 0 subscribers.
 */
function startSharedRRDWatcher(Server $server, Table $subscriberTable, array &$localSubscribers, Lock $subscribersLock, Debug $debug): void {
    go(function () use ($server, $subscriberTable, &$localSubscribers, $subscribersLock, $debug): void {
        $debug->log('Starting shared RRD file watcher', LOG_INFO);

        // Initialize inotify
        $inotify = inotify_init();
        stream_set_blocking($inotify, false);

        // Watch the RRD data directory
        $rrdDataDir = dirname(Config::$db->get_data_path(''));
        $debug->log("RRD data directory path: {$rrdDataDir}", LOG_INFO);

        // Note: Skip directory existence check in coroutine context
        // file_exists() and is_dir() may not work reliably with Swoole hooks
        // The directory should be created by docker-entrypoint.sh or manually

        // IN_CLOSE_WRITE: File closed after writing (rrdtool update completes)
        // IN_CREATE: New RRD files created
        $watchDescriptor = @inotify_add_watch($inotify, $rrdDataDir, IN_CLOSE_WRITE | IN_CREATE);
        $debug->log("Watching RRD directory: {$rrdDataDir} ({$watchDescriptor})", LOG_INFO);

        $updateCounter = 0;

        // Main watcher loop
        while (true) { // @phpstan-ignore while.alwaysTrue
            $events = inotify_read($inotify);

            if ($events) {
                ++$updateCounter;
                $timestamp = time();

                $subscriberCount = count($subscriberTable);
                $debug->log("RRD file updated (event #{$updateCounter}), broadcasting to {$subscriberCount} subscribers across all workers", LOG_INFO);

                // Prepare event data
                $eventData = [
                    'type' => 'rrd_update',
                    'timestamp' => $timestamp,
                    'counter' => $updateCounter,
                ];

                // Broadcast to all OTHER workers via sendMessage()
                $workerCount = $server->setting['worker_num'];
                $encodedMessage = json_encode($eventData);

                for ($workerId = 1; $workerId < $workerCount; ++$workerId) {
                    $server->sendMessage($encodedMessage, $workerId);
                }

                // Handle our own subscribers (worker 0) directly
                // Can't sendMessage to self, so push to local channels
                $localSubscriberCount = count($localSubscribers);
                if ($localSubscriberCount > 0) {
                    $debug->log("Pushing to {$localSubscriberCount} local subscribers (worker 0)", LOG_DEBUG);

                    $subscribersLock->lock();
                    foreach ($localSubscribers as $id => $channel) {
                        if (!$channel->push($eventData, 0.001)) {
                            $debug->log("Failed to push to local subscriber {$id}", LOG_WARNING);
                        }
                    }
                    $subscribersLock->unlock();
                }
            }

            // Small sleep to avoid busy-waiting
            Coroutine::sleep(0.5);
        }

        // Cleanup (unreachable, but good practice)
        inotify_rm_watch($inotify, $watchDescriptor); // @phpstan-ignore deadCode.unreachable
        fclose($inotify);
    });
}

/**
 * Handle SSE streaming requests in a coroutine.
 * Each connection runs independently without blocking workers.
 */
function handleStreamRequest(Request $request, Response $response, string $route, Debug $debug, array &$rrdSubscribers, Lock $subscribersLock, Table $subscriberTable, int $workerId, Server $server, array &$statsSubscribers, Lock $statsLock): void {
    // Extract stream type
    $streamType = str_replace('/api/', '', $route);
    $streamType = strtok($streamType, '?');

    // Generate unique connection ID
    $connectionId = uniqid('conn_', true);
    $connectionInfo = [
        'id' => $connectionId,
        'type' => $streamType,
        'worker_id' => $workerId,
        'connected_at' => time(),
        'remote_addr' => $request->server['remote_addr'] ?? 'unknown',
    ];

    // Determine if this is a long-lived connection we should track
    // Only track: server-stats (always) and graphs (if live)
    $isLongLived = true;

    // Historical graph requests (not live updates) - check if it's live
    if ($streamType === 'graphs') {
        // Parse dateend from request signals
        $rawBody = $request->getContent();
        if ($rawBody) {
            try {
                $signals = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR)['datastar-signals'] ?? null;
                if ($signals) {
                    $signals = json_decode((string) $signals, true, 512, JSON_THROW_ON_ERROR);
                    $dateend = (int) ($signals['dateend'] ?? time());
                    // Check if it's a live request using GraphStreamHandler's logic
                    $handler = new GraphStreamHandler($debug, new ServerSentEventGenerator(), $rrdSubscribers, $subscribersLock);
                    if (!$handler->isLiveRequest($dateend)) {
                        $isLongLived = false;
                    }
                }
            } catch (Throwable) {
                // Ignore errors, assume short-lived
                $isLongLived = false;
            }
        }
    }

    if ($isLongLived) {
        // Register connection in shared table (no lock needed - Table is thread-safe!)
        $subscriberTable->set($connectionId, [
            'type' => $streamType,
            'worker_id' => $workerId,
            'connected_at' => time(),
            'remote_addr' => $request->server['remote_addr'] ?? 'unknown',
        ]);

        $totalConnections = count($subscriberTable);
        $debug->log("SSE stream started: {$streamType} (id: {$connectionId}, total: {$totalConnections})", LOG_INFO);

        // Broadcast connection event to all workers
        broadcastServerStats($server, $subscriberTable, 'connect', $connectionInfo, $workerId, $statsSubscribers, $statsLock);
    } else {
        $debug->log("SSE request: {$streamType} (id: {$connectionId}, short-lived)", LOG_DEBUG);
    }

    // Set SSE headers
    $response->header('Content-Type', 'text/event-stream');
    $response->header('Cache-Control', 'no-cache');
    $response->header('Connection', 'keep-alive');
    $response->header('X-Accel-Buffering', 'no'); // Disable nginx buffering if behind proxy

    // Create SSE generator
    $sse = new ServerSentEventGenerator();

    try {
        switch ($streamType) {
            case 'graphs':
                $handler = new GraphStreamHandler($debug, $sse, $rrdSubscribers, $subscribersLock);
                $handler->handle($request, $response);

                break;

            case 'server-stats':
                $handler = new ServerStatsHandler($debug, $sse, $subscriberTable, $statsSubscribers, $statsLock);
                $handler->handle($request, $response);

                break;

            default:
                $response->status(404);
                $response->end("event: error\ndata: Unknown stream endpoint\n\n");

                break;
        }
    } catch (Throwable $e) {
        $debug->log("SSE stream error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}", LOG_ERR);
        $response->end("event: error\ndata: " . $e->getMessage() . "\n\n");
    }

    // Cleanup: Remove connection from shared table (only if it was tracked)
    if ($isLongLived) {
        $debug->log("Cleaning up connection: {$connectionId}", LOG_DEBUG);
        $subscriberTable->del($connectionId);
        $totalConnections = count($subscriberTable);

        $debug->log("SSE stream ended: {$streamType} (id: {$connectionId}, total: {$totalConnections})", LOG_INFO);

        // Broadcast disconnection event to all workers
        broadcastServerStats($server, $subscriberTable, 'disconnect', $connectionInfo, $workerId, $statsSubscribers, $statsLock);
    } else {
        $debug->log("SSE request completed: {$streamType} (id: {$connectionId})", LOG_DEBUG);
    }
}

/**
 * Handle backend API requests (non-streaming).
 */
function handleBackendRequest(Request $request, Response $response, string $route, Debug $debug): void {
    // Map route to backend file
    if ($route === '/' || $route === '/index.php') {
        $file = __DIR__ . '/../frontend/templates/index.php';
    } else {
        $file = __DIR__ . '/..' . $route;
    }

    if (!file_exists($file)) {
        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error' => 'Not found']));

        return;
    }

    // Capture output from PHP file
    ob_start();

    // Set up $_GET, $_POST, $_SERVER for legacy code
    $_GET = $request->get ?? [];
    $_POST = $request->post ?? [];
    $_SERVER = array_merge($_SERVER, $request->server ?? []);

    try {
        require $file;
        $output = ob_get_clean();

        // index.php returns HTML, backend APIs return JSON
        $contentType = ($route === '/' || $route === '/index.php') ? 'text/html' : 'application/json';
        $response->header('Content-Type', $contentType);
        $response->end($output);
    } catch (Throwable $e) {
        ob_end_clean();
        $debug->log("Backend error: {$e->getMessage()}", LOG_ERR);
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error' => $e->getMessage()]));
    }
}
