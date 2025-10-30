<?php

/**
 * Stream real-time server statistics.
 * Displays active connections, worker stats, and connection events.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\routes;

use mbolli\nfsen_ng\common\Debug;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Lock;
use Swoole\Table;

class ServerStatsHandler extends StreamHandler {
    public function __construct(
        Debug $debug,
        ServerSentEventGenerator $sse,
        private readonly Table $subscriberTable,
        private array &$statsSubscribers,
        private readonly Lock $statsLock
    ) {
        parent::__construct($debug, $sse);
    }

    public function handle(Request $request, Response $response): void {
        // Send initial stats
        $this->sendStatsUpdate($response);

        // Create our own channel and register it for updates
        $myChannel = new Channel(100);
        $myId = uniqid('stats_', true);

        $this->statsLock->lock();
        $this->statsSubscribers[$myId] = $myChannel;
        $this->statsLock->unlock();

        $this->debug->log("Server stats client subscribed (id: {$myId})", LOG_DEBUG);

        // Create event listener coroutine
        $disconnected = false;
        go(function () use ($myChannel, $response, &$disconnected): void {
            while ($response->isWritable() && !$disconnected) {
                // Wait for server stats event from our channel
                // Timeout after 5 seconds to send keepalive and detect disconnects faster
                $event = $myChannel->pop(5.0);

                if ($event !== false) {
                    // Server stats changed (connect/disconnect event)! Send update
                    if (!$this->sendStatsUpdate($response, $event)) {
                        $disconnected = true;

                        break;
                    }
                } else {
                    $this->sendKeepalive($response);
                }
            }
        });

        // Keep connection open until client disconnects
        while ($response->isWritable() && !$disconnected) {
            Coroutine::sleep(1);
        }

        // Cleanup
        $this->statsLock->lock();
        if (isset($this->statsSubscribers[$myId])) {
            unset($this->statsSubscribers[$myId]);
            $this->debug->log("Server stats client unsubscribed (id: {$myId})", LOG_DEBUG);
        }
        $this->statsLock->unlock();

        // End the response
        $response->end();
    }

    /**
     * Send server stats update to client.
     * Returns false if write failed (client disconnected).
     */
    private function sendStatsUpdate(Response $response, ?array $eventData = null): bool {
        // Gather stats from subscriberTable
        $connectionsByType = [];
        $connectionsByWorker = [];
        $connections = [];

        foreach ($this->subscriberTable as $id => $row) {
            $type = $row['type'] ?? 'unknown';
            $workerId = $row['worker_id'] ?? -1;

            // Count by type
            if (!isset($connectionsByType[$type])) {
                $connectionsByType[$type] = 0;
            }
            ++$connectionsByType[$type];

            // Count by worker
            if (!isset($connectionsByWorker[$workerId])) {
                $connectionsByWorker[$workerId] = 0;
            }
            ++$connectionsByWorker[$workerId];

            // Collect connection details
            $connections[] = [
                'id' => $id,
                'type' => $type,
                'worker_id' => $workerId,
                'connected_at' => $row['connected_at'] ?? 0,
                'remote_addr' => $row['remote_addr'] ?? 'unknown',
                'duration' => time() - ($row['connected_at'] ?? time()),
            ];
        }

        // Total connections is the actual count of entries, not table size
        $totalConnections = \count($connections);

        // Sort connections by connected_at (newest first)
        usort($connections, fn ($a, $b) => $b['connected_at'] <=> $a['connected_at']);

        // Build HTML
        $html = $this->generateStatsHTML(
            $totalConnections,
            $connectionsByType,
            $connectionsByWorker,
            $connections,
            $eventData
        );

        // Send update
        $event = $this->sse->patchElements($html);
        if ($response->isWritable()) {
            $writeResult = $response->write($event);
            if ($writeResult === false) {
                $this->debug->log('Write failed - client disconnected', LOG_DEBUG);

                return false;
            }
        }

        return true;
    }

    /**
     * Generate HTML for server stats dashboard.
     */
    private function generateStatsHTML(
        int $totalConnections,
        array $connectionsByType,
        array $connectionsByWorker,
        array $connections,
        ?array $eventData
    ): string {
        $eventBadge = '';
        if ($eventData) {
            $event = $eventData['event'] ?? '';
            $badgeClass = $event === 'connect' ? 'bg-success' : 'bg-secondary';
            $eventBadge = "<span class='badge {$badgeClass} ms-2'>{$event}</span>";
        }

        $typesList = '';
        foreach ($connectionsByType as $type => $count) {
            $typesList .= "<span class='badge bg-info me-1'>{$type}: {$count}</span>";
        }

        $workersList = '';
        foreach ($connectionsByWorker as $workerId => $count) {
            $workersList .= "<span class='badge bg-secondary me-1'>worker-{$workerId}: {$count}</span>";
        }

        $connectionsList = '';
        $maxDisplay = 5;
        $displayedConnections = \array_slice($connections, 0, $maxDisplay);
        foreach ($displayedConnections as $conn) {
            $duration = $this->formatDuration($conn['duration']);
            $connectionsList .= "<tr>
                <td class='text-muted small' style='font-family: monospace;'>{$conn['type']}</td>
                <td class='text-muted small'>{$conn['worker_id']}</td>
                <td class='text-muted small'>{$duration}</td>
                <td class='text-muted small'>{$conn['remote_addr']}</td>
            </tr>";
        }

        if (empty($connectionsList)) {
            $connectionsList = "<tr><td colspan='4' class='text-center text-muted small'>No active connections</td></tr>";
        }

        return <<<HTML
        <div id="server-stats" class="mt-2 p-2 bg-light border rounded">
            <div class="row align-items-center">
                <div class="col-auto">
                    <strong class="small">Server Stats:</strong>
                    <span class="badge bg-primary">{$totalConnections} connections</span>
                    {$eventBadge}
                </div>
                <div class="col-auto">
                    {$typesList}
                </div>
                <div class="col-auto">
                    {$workersList}
                </div>
                <div class="col">
                    <button class="btn btn-sm btn-outline-secondary float-end"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#connectionDetails"
                            aria-expanded="false">
                        Details
                    </button>
                </div>
            </div>
            <div class="collapse mt-2" id="connectionDetails">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th class="small">Type</th>
                            <th class="small">Worker</th>
                            <th class="small">Duration</th>
                            <th class="small">Remote IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$connectionsList}
                    </tbody>
                </table>
            </div>
        </div>
        HTML;
    }

    /**
     * Format duration in human-readable format.
     */
    private function formatDuration(int $seconds): string {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;

            return "{$minutes}m {$secs}s";
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$hours}h {$minutes}m";
    }
}
