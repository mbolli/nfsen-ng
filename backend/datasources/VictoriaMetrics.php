<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\datasources;

use JetBrains\PhpStorm\ExpectedValues;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;

class VictoriaMetrics implements Datasource {
    private readonly Debug $d;
    private readonly string $writeUrl;
    private readonly string $queryUrl;
    private readonly int $importYears;

    public function __construct() {
        $this->d = Debug::getInstance();

        $vmCfg = Config::$settings->datasourceConfig('VictoriaMetrics');
        $vmHost = $vmCfg['host'] ?? 'victoriametrics';
        $vmPort = $vmCfg['port'] ?? 8428;

        $this->writeUrl = "http://{$vmHost}:{$vmPort}/api/v1/import/prometheus";
        $this->queryUrl = "http://{$vmHost}:{$vmPort}/api/v1/query_range";

        $this->importYears = Config::$settings->importYears();

        $this->d->log('VictoriaMetrics datasource initialized: ' . $this->queryUrl, LOG_DEBUG);
    }

    /**
     * Gets the timestamps of the first and last entry of this specific source.
     */
    public function date_boundaries(string $source): array {
        // Query for first and last timestamp of the aggregate (no-port) series.
        $metric = $this->buildMetricName('flows', '');
        $labels = $this->buildLabels($source, 0, null, forQuery: true);
        $query  = "{$metric}{$labels}";

        $first = $this->querySingleValue($query, 'timestamp', true);
        $last  = $this->querySingleValue($query, 'timestamp', false);

        return [(int) $first, (int) $last];
    }

    /**
     * Gets the timestamp of the last update of this specific source.
     *
     * @return int timestamp or 0 if not found
     */
    public function last_update(string $source = '', int $port = 0): int {
        $metric = $this->buildMetricName('flows', '');
        $labels = $this->buildLabels($source, $port, null, forQuery: true);
        $query  = "{$metric}{$labels}";

        try {
            $timestamp = $this->querySingleValue($query, 'timestamp', false);

            return (int) $timestamp;
        } catch (\Exception $e) {
            $this->d->log("Error getting last_update: {$e->getMessage()}", LOG_DEBUG);

            return 0;
        }
    }

    /**
     * Write to VictoriaMetrics with supplied data.
     *
     * @throws \Exception
     */
    public function write(array $data): bool {
        $timestamp = (int) $data['date_timestamp'];
        $timestampMs = $timestamp * 1000; // VM uses milliseconds
        $source = $data['source'];
        $port = $data['port'] ?? 0;

        // Build Prometheus exposition format lines
        $lines = [];
        foreach ($data['fields'] as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Parse field name to extract metric type and protocol
            // e.g., 'flows_tcp' -> metric: 'flows', protocol: 'tcp'
            $parts = explode('_', (string) $field);
            $metricType = $parts[0]; // flows, packets, or bytes
            $protocol = $parts[1] ?? 'total';

            $metricName = $this->buildMetricName($metricType, $protocol);
            $labels = $this->buildLabels($source, $port, $protocol !== 'total' ? $protocol : null);

            // Prometheus format: metric_name{labels} value timestamp
            $lines[] = "{$metricName}{$labels} {$value} {$timestampMs}";
        }

        if (empty($lines)) {
            return true; // Nothing to write
        }

        $body = implode("\n", $lines);

        $this->d->log('Writing to VictoriaMetrics: ' . \count($lines) . ' metrics', LOG_DEBUG);

        // Send to VictoriaMetrics
        return $this->sendToVM($this->writeUrl, $body);
    }

    /**
     * @param string   $type    flows/packets/bytes/bits
     * @param string   $display protocols/sources/ports
     * @param null|int $maxrows optional maximum rows to return (null = 500 default)
     */
    public function get_graph_data(
        int $start,
        int $end,
        array $sources,
        array $protocols,
        array $ports,
        #[ExpectedValues(['flows', 'packets', 'bytes', 'bits'])]
        string $type = 'flows',
        #[ExpectedValues(['protocols', 'sources', 'ports'])]
        string $display = 'sources',
        ?int $maxrows = 500,
    ): array|string {
        $useBits = false;
        if ($type === 'bits') {
            $type = 'bytes';
            $useBits = true;
        }

        if (empty($protocols)) {
            $protocols = ['tcp', 'udp', 'icmp', 'other'];
        }
        if (empty($sources)) {
            $sources = Config::$settings->sources;
        }
        if (empty($ports)) {
            $ports = Config::$settings->ports;
        }

        // Calculate step (resolution). Fall back to 500 rows when maxrows is null
        // (matches RRD's default behaviour and avoids division by zero).
        $step = max(300, (int) (($end - $start) / ($maxrows ?? 500)));

        $queries = [];
        $legends = [];

        switch ($display) {
            case 'protocols':
                foreach ($protocols as $protocol) {
                    $proto = ($protocol === 'any') ? null : $protocol;
                    $metricName = $this->buildMetricName($type, $proto);
                    $labels = $this->buildLabels($sources[0], 0, $proto, forQuery: true);
                    $queries[] = "{$metricName}{$labels}";
                    $legends[] = implode('_', array_filter([$protocol, $type, $sources[0]]));
                }

                break;

            case 'sources':
                foreach ($sources as $source) {
                    $proto = ($protocols[0] === 'any') ? null : $protocols[0];
                    $metricName = $this->buildMetricName($type, $proto);
                    $labels = $this->buildLabels($source, 0, $proto, forQuery: true);
                    $queries[] = "{$metricName}{$labels}";
                    $legends[] = implode('_', array_filter([$source, $type, $protocols[0]]));
                }

                break;

            case 'ports':
                foreach ($ports as $port) {
                    $source = ($sources[0] === 'any') ? '' : $sources[0];
                    $proto = ($protocols[0] === 'any') ? null : $protocols[0];
                    $metricName = $this->buildMetricName($type, $proto);
                    // forQuery: true still needed here; port > 0 so port="" is not emitted.
                    $labels = $this->buildLabels($source, $port, $proto, forQuery: true);
                    $queries[] = "{$metricName}{$labels}";
                    $legends[] = implode('_', array_filter([$port, $type, $source, $protocols[0]]));
                }

                break;
        }

        // Execute queries and combine results
        try {
            $allData = [];
            foreach ($queries as $i => $query) {
                $result = $this->queryRange($query, $start, $end, $step);
                if (!empty($result)) {
                    $allData[] = [
                        'legend' => $legends[$i],
                        'data' => $result,
                    ];
                }
            }

            // Transform to expected format
            return $this->transformToOutputFormat($allData, $start, $end, $step, $useBits);
        } catch (\Exception $e) {
            $this->d->log("Error fetching graph data: {$e->getMessage()}", LOG_ERR);

            throw $e;
        }
    }

    /**
     * Creates a new database for every source/port combination.
     * Note: VictoriaMetrics doesn't require pre-creation, but we can verify connectivity.
     */
    public function reset(array $sources): bool {
        // VictoriaMetrics doesn't need reset - it's schemaless
        // But we could delete existing data if needed
        $this->d->log('VictoriaMetrics reset called - no action needed (schemaless DB)', LOG_INFO);

        return true;
    }

    /**
     * Returns health check entries for this datasource.
     *
     * @param string   $group
     * @param string[] $sources
     * @return list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}>
     */
    public function healthChecks(string $group, array $sources): array {
        $checks      = [];
        $importYears = Config::$settings->importYears();

        $vmCfg  = Config::$settings->datasourceConfig('VictoriaMetrics');
        $vmHost = (string) ($vmCfg['host'] ?? 'victoriametrics');
        $vmPort = (int) ($vmCfg['port'] ?? 8428);

        $checks[] = ['id' => 'vm_config', 'label' => 'VictoriaMetrics config', 'status' => 'ok',
            'detail' => "{$vmHost}:{$vmPort}", 'group' => $group, 'code' => true, 'hint' => '', 'epoch' => 0];

        // TCP connectivity check — 2 s timeout
        $errNo  = 0;
        $errStr = '';
        $sock   = $this->tcpConnect($vmHost, $vmPort, $errNo, $errStr);
        if ($sock === false) {
            $checks[] = ['id' => 'vm_reachable', 'label' => 'VictoriaMetrics reachable',
                'status' => 'error', 'detail' => "Cannot connect: {$errStr} (errno {$errNo})",
                'group' => $group, 'code' => false, 'hint' => '', 'epoch' => 0];
        } else {
            fclose($sock); // @phpstan-ignore argument.type
            $checks[] = ['id' => 'vm_reachable', 'label' => 'VictoriaMetrics reachable',
                'status' => 'ok', 'detail' => 'Connected', 'group' => $group, 'code' => false, 'hint' => '', 'epoch' => 0];

            foreach ($sources as $source) {
                try {
                    $lastUpdate = $this->last_update($source);
                } catch (\Throwable) {
                    $checks[] = ['id' => "vm_data_{$source}", 'label' => "VM data: {$source}",
                        'status' => 'warning', 'detail' => 'Could not query last_update',
                        'group' => $group, 'code' => false, 'hint' => '', 'epoch' => 0];
                    continue;
                }
                if ($lastUpdate === 0) {
                    $checks[] = ['id' => "vm_data_{$source}", 'label' => "VM data: {$source}",
                        'status' => 'warning', 'detail' => 'No data yet', 'group' => $group,
                        'code' => false, 'hint' => 'Go to Admin \u2192 click "Initial Import" to populate the database', 'epoch' => 0];
                } else {
                    $age    = time() - $lastUpdate;
                    $status = $age > 3600 ? 'warning' : 'ok';
                    $ageStr = \mbolli\nfsen_ng\common\HealthChecker::ageStr($age);
                    $detail = $age <= 0 ? 'Just imported'
                        : ($age > 3600 ? "Last import {$ageStr} ago — may be stalled"
                                       : "Last import {$ageStr} ago");
                    $checks[] = ['id' => "vm_data_{$source}", 'label' => "VM data: {$source}",
                        'status' => $status, 'detail' => $detail,
                        'group' => $group, 'code' => false, 'hint' => '', 'epoch' => $lastUpdate];
                }
            }
        }

        $checks[] = ['id' => 'import_years', 'label' => 'Import years',
            'status' => $importYears >= 1 ? 'ok' : 'error',
            'detail' => $importYears >= 1 ? (string) $importYears : 'import_years must be \u2265 1',
            'group' => $group, 'code' => false,
            'hint' => 'Set via NFSEN_IMPORT_YEARS env var (default: 3).',
            'epoch' => 0];

        return $checks;
    }

    /**
     * Gets the path where the datasource's data is stored.
     */
    public function get_data_path(string $source = '', int $port = 0): string {
        return $this->queryUrl;
    }

    /**
     * Open a TCP connection for health-check probing.
     * Extracted to a protected method so tests can override it.
     *
     * @return resource|false
     */
    protected function tcpConnect(string $host, int $port, int &$errNo, string &$errStr): mixed
    {
        return @fsockopen($host, $port, $errNo, $errStr, 2.0);
    }

    /**
     * Build metric name based on type and protocol.
     */
    private function buildMetricName(string $type, ?string $protocol): string {
        $base = "nfsen_{$type}";
        if ($protocol && $protocol !== 'total') {
            $base .= "_{$protocol}";
        }

        return $base;
    }

    /**
     * Build a label selector for VictoriaMetrics.
     *
     * @param bool $forQuery When true (read path), port=0 emits `port=""` which in
     *                       PromQL matches series where the label is absent — i.e. the
     *                       aggregate total — and excludes port-specific series that
     *                       would otherwise also match a plain `{source="…"}` selector.
     */
    private function buildLabels(string $source = '', int $port = 0, ?string $protocol = null, bool $forQuery = false): string {
        $labels = [];

        if (!empty($source)) {
            $labels[] = "source=\"{$source}\"";
        }

        if ($port > 0) {
            $labels[] = "port=\"{$port}\"";
        } elseif ($forQuery) {
            // `port=""` selects only series where the port label is absent,
            // preventing port-specific (sparse) series from shadowing the total.
            $labels[] = 'port=""';
        }

        if ($protocol !== null && $protocol !== 'total') {
            $labels[] = "protocol=\"{$protocol}\"";
        }

        return empty($labels) ? '' : '{' . implode(',', $labels) . '}';
    }

    /**
     * Query VictoriaMetrics for a range of data.
     */
    private function queryRange(string $query, int $start, int $end, int $step): array {
        $params = http_build_query([
            'query' => $query,
            'start' => $start,
            'end' => $end,
            'step' => $step . 's',
        ]);

        $url = "{$this->queryUrl}?{$params}";
        $this->d->log("Querying VictoriaMetrics: {$query}", LOG_DEBUG);

        $response = $this->httpGet($url);
        $data = json_decode($response, true);

        if (!isset($data['status']) || $data['status'] !== 'success') {
            throw new \Exception('VictoriaMetrics query failed: ' . ($data['error'] ?? 'Unknown error'));
        }

        // Extract values from first result series
        if (empty($data['data']['result'])) {
            return [];
        }

        $values = [];
        foreach ($data['data']['result'][0]['values'] as $point) {
            $timestamp = (int) $point[0];
            $value = is_numeric($point[1]) ? (float) $point[1] : null;
            $values[$timestamp] = $value;
        }

        return $values;
    }

    /**
     * Query for the first or last actual raw-sample timestamp of a metric.
     *
     * Uses VictoriaMetrics MetricsQL functions:
     *   tfirst_over_time(v[d]) — timestamp of the oldest raw sample in the window
     *   tlast_over_time(v[d])  — timestamp of the newest raw sample in the window
     *
     * Both return the real data timestamp as value[1], not the query evaluation
     * time (unlike `timestamp(last_over_time(...))` which returns ~eval_time).
     */
    private function querySingleValue(string $query, string $field = 'timestamp', bool $first = true): int {
        $windowDays  = $this->importYears * 365;
        $fn          = $first ? 'tfirst_over_time' : 'tlast_over_time';
        $wrappedQuery = "{$fn}({$query}[{$windowDays}d])";

        $url = str_replace('query_range', 'query', $this->queryUrl) . '?' . http_build_query([
            'query' => $wrappedQuery,
        ]);

        $response = $this->httpGet($url);
        $data = json_decode($response, true);

        if (!isset($data['status']) || $data['status'] !== 'success' || empty($data['data']['result'])) {
            return 0;
        }

        // value[1] is the actual data timestamp (float string)
        return isset($data['data']['result'][0]['value'][1])
            ? (int) (float) $data['data']['result'][0]['value'][1]
            : 0;
    }

    /**
     * Transform VictoriaMetrics response to expected output format.
     */
    private function transformToOutputFormat(array $allData, int $start, int $end, int $step, bool $useBits): array {
        $output = [
            'data' => [],
            'start' => $start,
            'end' => $end,
            'step' => $step,
            'legend' => [],
        ];

        foreach ($allData as $series) {
            $output['legend'][] = $series['legend'];

            foreach ($series['data'] as $timestamp => $value) {
                if ($useBits && $value !== null) {
                    $value *= 8;
                }

                if (\array_key_exists($timestamp, $output['data'])) {
                    $output['data'][$timestamp][] = $value;
                } else {
                    $output['data'][$timestamp] = [$value];
                }
            }
        }

        return $output;
    }

    /**
     * Send data to VictoriaMetrics.
     */
    protected function sendToVM(string $url, string $body): bool {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: text/plain',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        $this->d->log("VictoriaMetrics write failed: HTTP {$httpCode}, Response: {$response}", LOG_ERR);

        return false;
    }

    /**
     * HTTP GET request.
     */
    protected function httpGet(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

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

        throw new \Exception("HTTP request failed with code {$httpCode}: {$response}");
    }
}
