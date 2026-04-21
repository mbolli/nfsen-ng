<?php

/**
 * VictoriaMetrics watcher for real-time updates.
 *
 * Unlike RRD which uses inotify on files, VictoriaMetrics is a remote database.
 * This watcher polls VM for new data and notifies subscribers.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\datasources;

use mbolli\nfsen_ng\common\Debug;
use Swoole\Timer;

class VictoriaMetricsWatcher {
    private readonly Debug $d;
    private array $subscribers = [];
    private array $lastTimestamps = [];
    private int $pollInterval = 5000; // 5 seconds in milliseconds

    public function __construct(private readonly string $queryUrl) {
        $this->d = Debug::getInstance();
    }

    /**
     * Start watching for new data.
     * Uses polling instead of inotify since VM is a remote database.
     */
    public function start(): void {
        $this->d->log('Starting VictoriaMetrics watcher with ' . $this->pollInterval . 'ms poll interval', LOG_INFO);

        // Use Swoole timer for periodic polling
        Timer::tick($this->pollInterval, function (): void {
            $this->checkForUpdates();
        });
    }

    /**
     * Subscribe a channel to receive update notifications.
     *
     * @param mixed $channel
     */
    public function subscribe(string $id, $channel): void {
        $this->subscribers[$id] = $channel;
        $this->d->log("Subscriber {$id} registered", LOG_DEBUG);
    }

    /**
     * Unsubscribe a channel.
     */
    public function unsubscribe(string $id): void {
        unset($this->subscribers[$id]);
        $this->d->log("Subscriber {$id} unregistered", LOG_DEBUG);
    }

    /**
     * Set the polling interval (milliseconds).
     */
    public function setPollInterval(int $milliseconds): void {
        $this->pollInterval = max(1000, $milliseconds); // Minimum 1 second
    }

    /**
     * Check VictoriaMetrics for new data.
     * Queries the last timestamp and compares with cached value.
     */
    private function checkForUpdates(): void {
        try {
            // Query VM for the latest timestamp across all metrics
            // Using instant query to get the most recent data point
            $query = 'max(max_over_time(nfsen_flows[5m]))';
            $url = str_replace('query_range', 'query', $this->queryUrl) . '?' . http_build_query([
                'query' => $query,
            ]);

            $response = $this->httpGet($url);
            $data = json_decode($response, true);

            if (!isset($data['status']) || $data['status'] !== 'success') {
                $this->d->log('VM watcher query failed', LOG_WARNING);

                return;
            }

            // Check if we have results
            if (empty($data['data']['result'])) {
                return;
            }

            $latestTimestamp = (int) $data['data']['result'][0]['value'][0];

            // Check if this is newer than what we've seen
            $cacheKey = 'latest';
            if (!isset($this->lastTimestamps[$cacheKey]) || $latestTimestamp > $this->lastTimestamps[$cacheKey]) {
                $this->d->log("New data detected at timestamp {$latestTimestamp}", LOG_DEBUG);
                $this->lastTimestamps[$cacheKey] = $latestTimestamp;

                // Notify all subscribers
                $this->notifySubscribers([
                    'type' => 'update',
                    'timestamp' => $latestTimestamp,
                ]);
            }
        } catch (\Exception $e) {
            $this->d->log("Error in VM watcher: {$e->getMessage()}", LOG_WARNING);
        }
    }

    /**
     * Notify all subscribers of an update.
     */
    private function notifySubscribers(array $event): void {
        foreach ($this->subscribers as $id => $channel) {
            try {
                $pushed = $channel->push($event, 0.1); // Non-blocking push with 100ms timeout
                if (!$pushed) {
                    $this->d->log("Failed to notify subscriber {$id} (channel full or closed)", LOG_DEBUG);
                }
            } catch (\Exception $e) {
                $this->d->log("Error notifying subscriber {$id}: {$e->getMessage()}", LOG_WARNING);
                $this->unsubscribe($id);
            }
        }

        if (!empty($this->subscribers)) {
            $this->d->log('Notified ' . \count($this->subscribers) . ' subscribers', LOG_DEBUG);
        }
    }

    /**
     * HTTP GET request.
     */
    private function httpGet(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \Exception("HTTP request failed: {$error}");
        }

        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $response;
        }

        throw new \Exception("HTTP request failed with code {$httpCode}");
    }
}
