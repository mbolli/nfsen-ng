<?php

/**
 * Stream real-time graph updates by subscribing to shared RRD watcher.
 * Each connection gets its own subscriber channel for scalability.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\routes;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Lock;

use function Swoole\Coroutine\go;

class GraphStreamHandler extends StreamHandler {
    public function __construct(
        Debug $debug,
        ServerSentEventGenerator $sse,
        private array &$rrdSubscribers,
        private readonly Lock $subscribersLock,
    ) {
        parent::__construct($debug, $sse);
    }

    public function handle(Request $request, Response $response): void {
        $signals = $this->extractSignals($request);

        // Get parameters from signals
        $gSignals = $signals['graph'] ?? [];
        $display = $gSignals['display'] ?? 'sources';
        $sources = $gSignals['sources'] ?? Config::$cfg['general']['sources'];
        $ports = $gSignals['ports'] ?? Config::$cfg['general']['ports'];
        $protocols = $gSignals['protocols'] ?? ['any'];
        $datatype = $gSignals['datatype'] ?? 'traffic';
        $trafficUnit = $gSignals['trafficUnit'] ?? 'bits';
        $maxrows = (int) ($gSignals['resolution'] ?? 500);
        $initialDatestart = (int) ($signals['datestart'] ?? time() - 86400);
        $initialDateend = (int) ($signals['dateend'] ?? time());

        // Determine if this is a live request (end date is within 5 minutes of now)
        $isLive = $this->isLiveRequest($initialDateend);

        // Calculate window size for rolling updates
        $windowSize = $initialDateend - $initialDatestart;
        $graphData = [];
        $error = null;

        // Fetch initial graph data
        try {
            $graphData = Config::$db->get_graph_data(
                $initialDatestart,
                $initialDateend,
                $sources,
                $protocols,
                $ports,
                $datatype !== 'traffic' ? $datatype : $trafficUnit,
                $display,
                $maxrows
            );
            $encodedGraphData = json_encode($graphData, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->debug->log("Error fetching initial graph data: {$e->getMessage()}", LOG_ERR);
            $error = $e->getMessage();
            $encodedGraphData = json_encode([], JSON_THROW_ON_ERROR);
        }

        // Calculate actual resolution from data points
        $actualResolution = $this->calculateResolution($graphData, $windowSize);

        // send elements first (or caddy might not compress the response because too little data)
        $event = $this->sse->patchElements($this->generateGraphElement($encodedGraphData));
        $response->write($event);

        $event = $this->sse->patchSignals([
            'graph' => [
                '_isLive' => $isLive,
                '_actualResolution' => $actualResolution,
            ],
            '_error' => $error ? 'Error fetching initial graph data: ' . $error : null,
        ]);
        $response->write($event);

        // If viewing historical data (not live), close the connection after sending initial data
        if (!$isLive) {
            $this->debug->log('Historical data request - closing connection', LOG_DEBUG);
            $response->end();

            return;
        }

        $this->debug->log('Live data request - keeping connection open', LOG_DEBUG);

        // For live data, subscribe to RRD file change events from shared watcher
        $this->subscribeLiveUpdates(
            $response,
            $sources,
            $display,
            $ports,
            $protocols,
            $windowSize,
            $datatype,
            $trafficUnit,
            $maxrows
        );
    }

    /**
     * Check if a graph request is for live data (within 5 minutes of now).
     * Used to determine if connection should be persistent or closed after initial data.
     */
    public function isLiveRequest(int $dateend): bool {
        return (time() - $dateend) < 300;
    }

    /**
     * Generate graph element HTML for SSE patch.
     */
    private function generateGraphElement(string $encodedGraphData): string {
        return <<<HTML
            <div id="graph">
                <nfsen-chart
                    data-ref="graph._widget"
                    data-attr:data-chart-config="\$graph._config"
                    data-chart-data='{$encodedGraphData}'
                >
                    <div class="chart-container" data-ignore-morph data-ignore>
                        <div class="chart-canvas" style="width: 100%; height: 400px;"></div>
                    </div>
                </nfsen-chart>
            </div>
        HTML;
    }

    /**
     * Subscribe to live RRD updates and push graph updates to client.
     */
    private function subscribeLiveUpdates(
        Response $response,
        array $sources,
        string $display,
        array $ports,
        array $protocols,
        int $windowSize,
        string $datatype,
        string $trafficUnit,
        int $maxrows
    ): void {
        // Create our own channel and register it
        $myChannel = new Channel(100);
        $myId = uniqid('sse_', true);

        $this->subscribersLock->lock();
        $this->rrdSubscribers[$myId] = $myChannel;
        $this->subscribersLock->unlock();

        // Note: Connection is already registered in subscriberTable by handleStreamRequest()
        // We only need to track the channel locally for RRD push notifications

        $this->debug->log("SSE client subscribed to RRD updates (id: {$myId})", LOG_DEBUG);

        // Create event listener coroutine
        $disconnected = false;
        go(function () use (
            $myChannel,
            $response,
            &$sources,
            &$display,
            &$ports,
            &$protocols,
            &$windowSize,
            &$datatype,
            &$trafficUnit,
            &$maxrows,
            &$disconnected
        ): void {
            while ($response->isWritable() && !$disconnected) {
                // Wait for RRD update event from our channel
                // Timeout after 5 seconds to send keepalive and detect disconnects faster
                $event = $myChannel->pop(5.0);

                if ($event !== false) {
                    // RRD file changed! Send update instantly
                    $currentEnd = time();
                    $currentStart = $currentEnd - $windowSize;

                    try {
                        $graphData = Config::$db->get_graph_data(
                            $currentStart,
                            $currentEnd,
                            $sources,
                            $protocols,
                            $ports,
                            $datatype !== 'traffic' ? $datatype : $trafficUnit,
                            $display,
                            $maxrows
                        );
                        $encodedGraphData = json_encode($graphData, JSON_THROW_ON_ERROR);
                    } catch (\Exception $e) {
                        $this->debug->log("Error fetching graph data: {$e->getMessage()}", LOG_ERR);
                        $event = $this->sse->patchSignals([
                            '_error' => "Error fetching graph data: {$e->getMessage()}",
                        ]);
                        if ($response->write($event) === false) {
                            $this->debug->log('Write failed during error - client disconnected', LOG_DEBUG);
                            $disconnected = true;

                            break;
                        }

                        continue;
                    }

                    // Calculate actual resolution from data points
                    $actualResolution = $this->calculateResolution($graphData, $windowSize);

                    // Send update using Datastar SDK
                    $sseEvent = $this->sse->patchSignals([
                        'graph' => [
                            '_lastUpdate' => date('Y-m-dTH:i:s'),
                            '_actualResolution' => $actualResolution,
                        ],
                    ]);
                    if ($response->write($sseEvent) === false) {
                        $this->debug->log('Write failed (signals) - client disconnected', LOG_DEBUG);
                        $disconnected = true;

                        break;
                    }

                    $event = $this->sse->patchElements($this->generateGraphElement($encodedGraphData));
                    if ($response->write($event) === false) {
                        $this->debug->log('Write failed (elements) - client disconnected', LOG_DEBUG);
                        $disconnected = true;

                        break;
                    }
                } elseif ($response->write(": keepalive\n\n") === false) {
                    $this->debug->log('Write failed (keepalive) - client disconnected', LOG_DEBUG);
                    $disconnected = true;

                    break;
                }
            }
        });

        // Keep connection open until client disconnects
        while ($response->isWritable() && !$disconnected) {
            Coroutine::sleep(1);
        }

        // CRITICAL: Cleanup subscriber channel when connection closes
        // Note: Connection removal from subscriberTable is handled by handleStreamRequest()
        $this->subscribersLock->lock();
        if (isset($this->rrdSubscribers[$myId])) {
            unset($this->rrdSubscribers[$myId]);
            $this->debug->log("SSE client unsubscribed from RRD updates (id: {$myId})", LOG_DEBUG);
        }
        $this->subscribersLock->unlock();

        // End the response (sends final chunk)
        $response->end();
    }

    /**
     * Calculate actual resolution from graph data.
     *
     * @param array $graphData  The graph data array with 'data' key containing timestamps
     * @param int   $windowSize The time window size in seconds (unused, kept for consistency)
     *
     * @return int Number of data points in the graph
     */
    private function calculateResolution(array $graphData, int $windowSize): int {
        // Count actual data points
        if (isset($graphData['data']) && \is_array($graphData['data'])) {
            return \count($graphData['data']);
        }

        return 0;
    }
}
