<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\TotalsProvider;
use mbolli\nfsen_ng\datasources\VictoriaMetrics;

// ── Test double ───────────────────────────────────────────────────────────────

/**
 * Subclass that replaces HTTP I/O with in-memory stubs so every test runs
 * entirely offline and without curl.
 *
 * @internal
 *
 * @coversNothing
 */
class VictoriaMetricsTest extends VictoriaMetrics {
    /** @var list<string> */
    public array $capturedGetUrls = [];

    /** @var list<string> */
    public array $capturedPostBodies = [];

    public string $nextGetResponse = '{"status":"success","data":{"result":[]}}';
    public bool $nextSendResult = true;

    /** @var list<string> Optional queue of responses for sequential calls; falls back to nextGetResponse */
    public array $getResponseQueue = [];

    /** @var null|Closure(string): string Answers by URL when set, ahead of the queue */
    public ?Closure $responder = null;

    /** @var list<int> */
    public array $capturedTimeouts = [];

    protected function httpGet(string $url, int $timeout = 30): string {
        $this->capturedGetUrls[] = $url;
        $this->capturedTimeouts[] = $timeout;

        if ($this->responder !== null) {
            return ($this->responder)($url);
        }

        if (!empty($this->getResponseQueue)) {
            return array_shift($this->getResponseQueue);
        }

        return $this->nextGetResponse;
    }

    protected function sendToVM(string $url, string $body): bool {
        $this->capturedPostBodies[] = $body;

        return $this->nextSendResult;
    }

    /** @return false|resource */
    protected function tcpConnect(string $host, int $port, int &$errNo, string &$errStr): mixed {
        // Simulate a successful connection so healthChecks proceeds without real I/O
        return fopen('php://memory', 'r');
    }
}

/**
 * Evaluates `sum(max by (source) (sum_over_time(<metric>{...}[<R>s])))` at `time` the way
 * VictoriaMetrics does, over (time - R, time]. Every series of the metric matches.
 *
 * @internal
 *
 * @coversNothing
 */
final class VictoriaMetricsSampleStub extends VictoriaMetrics {
    /** @var list<array{metric: string, source: string, profile: string, samples: array<int, float>}> */
    public array $series = [];

    protected function httpGet(string $url, int $timeout = 30): string {
        $params = vmQueryParams($url);
        if (!preg_match('/^sum\(max by \(source\) \(sum_over_time\((\w+)\{[^}]*\}\[(\d+)s\]\)\)\)$/', $params['query'] ?? '', $m)) {
            throw new RuntimeException('Unexpected query: ' . ($params['query'] ?? ''));
        }
        $time = (int) $params['time'];
        $perSource = [];
        foreach ($this->series as $series) {
            if ($series['metric'] !== $m[1]) {
                continue;
            }
            $sum = 0.0;
            foreach ($series['samples'] as $ts => $value) {
                if ($ts > $time - (int) $m[2] && $ts <= $time) {
                    $sum += $value;
                }
            }
            $perSource[$series['source']] = max($perSource[$series['source']] ?? $sum, $sum);
        }

        return json_encode(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [$time, (string) array_sum($perSource)]]]]]);
    }

    protected function sendToVM(string $url, string $body): bool {
        return true;
    }
}

// ── Bootstrap helper ──────────────────────────────────────────────────────────

/**
 * The decoded query string of a captured VictoriaMetrics URL.
 *
 * @return array<string, string>
 */
function vmQueryParams(string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

    return array_map(static fn (mixed $v): string => (string) $v, $params);
}

/**
 * A query_range answer with one series holding one point.
 */
function vmRangeResponse(int $ts, string $value): string {
    return json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'values' => [[$ts, $value]]]]]]);
}

function makeVmSettings(): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'sources' => ['gateway', 'server1'],
            'ports' => [80, 443],
            'db' => 'VictoriaMetrics',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile' => 'live',
            'max-processes' => 1,
        ],
        'db' => [
            'VictoriaMetrics' => [
                'host' => 'vm-test',
                'port' => 8428,
                'import_years' => 3,
            ],
        ],
        'log' => ['priority' => LOG_WARNING],
    ]);
    Config::$path = sys_get_temp_dir();
}

// ── Simple interface methods ───────────────────────────────────────────────────

describe('VictoriaMetrics basic interface methods', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
    });

    test('reset() always returns true', function (): void {
        expect($this->vm->reset(['gw', 'srv']))->toBeTrue();
        expect($this->vm->reset([]))->toBeTrue();
    });

    test('get_data_path() contains query_range', function (): void {
        expect($this->vm->get_data_path())->toContain('query_range');
    });

    test('get_data_path() contains configured host and port', function (): void {
        expect($this->vm->get_data_path())->toContain('vm-test:8428');
    });
});

// ── write() ───────────────────────────────────────────────────────────────────

describe('VictoriaMetrics::write()', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->ts = 1700000000; // arbitrary fixed Unix epoch
    });

    test('write() with port=0 does NOT include port label in stored metric (aggregate total)', function (): void {
        $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows' => 100],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // The stored aggregate must have no port label at all
        expect($body)->not->toContain('port=');
    });

    test('flows_tcp field emits correct Prometheus line', function (): void {
        $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows_tcp' => 42],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // nfsen_flows_tcp{source="gw",protocol="tcp"} 42 1700000000000
        expect($body)->toContain('nfsen_flows_tcp{');
        expect($body)->toContain('source="gw"');
        expect($body)->toContain('protocol="tcp"');
        expect($body)->toContain('} 42 ' . ($this->ts * 1000));
    });

    test('flows field (no protocol suffix) emits bare metric name', function (): void {
        $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows' => 100],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // protocol tag should be 'total', which is excluded from labels
        expect($body)->toContain('nfsen_flows');
        expect($body)->toContain('} 100 ' . ($this->ts * 1000));
        // should NOT include a protocol label for 'total'
        expect($body)->not->toContain('protocol="total"');
    });

    test('null and empty fields are skipped', function (): void {
        $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows_tcp' => null, 'flows_udp' => '', 'packets' => 50],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        expect($body)->not->toContain('flows_tcp');
        expect($body)->not->toContain('flows_udp');
        expect($body)->toContain('nfsen_packets');
    });

    test('write() with port > 0 includes port label', function (): void {
        $this->vm->write([
            'source' => 'gw',
            'port' => 443,
            'date_timestamp' => $this->ts,
            'fields' => ['bytes' => 9999],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        expect($body)->toContain('port="443"');
    });

    test('write() returns false when sendToVM fails', function (): void {
        $this->vm->nextSendResult = false;
        $result = $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows' => 1],
        ]);
        expect($result)->toBeFalse();
    });

    test('write() with all-null fields returns true (nothing to send)', function (): void {
        $result = $this->vm->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->ts,
            'fields' => ['flows' => null],
        ]);
        expect($result)->toBeTrue();
        expect($this->vm->capturedPostBodies)->toBeEmpty();
    });
});

// ── get_graph_data() ──────────────────────────────────────────────────────────

describe('VictoriaMetrics::get_graph_data()', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->start = 1700000000;
        $this->end = $this->start + 3600;
    });

    test('display=protocols issues one GET request per protocol', function (): void {
        $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp', 'udp'],
            ports: [],
            display: 'protocols',
        );

        expect($this->vm->capturedGetUrls)->toHaveCount(2);
        expect($this->vm->capturedGetUrls[0])->toContain('nfsen_flows_tcp');
        expect($this->vm->capturedGetUrls[1])->toContain('nfsen_flows_udp');
    });

    test('display=sources includes port="" selector to exclude port-specific series', function (): void {
        $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['any'],
            ports: [],
            display: 'sources',
        );

        // port="" must appear in the query so VM doesn't return sparse port-specific series
        expect(urldecode($this->vm->capturedGetUrls[0]))->toContain('port=""');
    });

    test('display=protocols includes port="" selector to exclude port-specific series', function (): void {
        $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [],
            display: 'protocols',
        );

        expect(urldecode($this->vm->capturedGetUrls[0]))->toContain('port=""');
    });

    test('display=ports uses explicit port label and does NOT add port="" selector', function (): void {
        $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [80],
            display: 'ports',
        );

        $url = urldecode($this->vm->capturedGetUrls[0]);
        expect($url)->toContain('port="80"');
        expect($url)->not->toContain('port=""');
    });

    test('display=sources issues one GET per source', function (): void {
        $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw', 'srv'],
            protocols: ['tcp'],
            ports: [],
            display: 'sources',
        );

        expect($this->vm->capturedGetUrls)->toHaveCount(2);
    });

    test('type=bits queries bytes metric and returns values multiplied by 8', function (): void {
        // Return a single data point
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data' => [
                'result' => [[
                    'metric' => [],
                    'values' => [[$this->start, '1000']],
                ]],
            ],
        ]);

        $result = $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['any'],
            ports: [],
            type: 'bits',
            display: 'sources',
        );

        // URL must use 'bytes' metric, not 'bits'
        expect($this->vm->capturedGetUrls[0])->toContain('nfsen_bytes');

        // Returned value should be bytes * 8
        $values = array_values($result['data']);
        expect($values[0][0])->toBe(8000.0);
    });

    test('output structure has required keys', function (): void {
        $result = $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [],
        );

        expect($result)->toHaveKeys(['data', 'start', 'end', 'step', 'legend']);
    });

    test('empty VM response produces empty data with correct metadata', function (): void {
        $result = $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [],
        );

        expect($result['data'])->toBeArray()->toBeEmpty();
        expect($result['start'])->toBe($this->start);
        expect($result['end'])->toBe($this->end);
        expect($result['legend'])->toBeArray();
    });
});

// ── transformToOutputFormat: alignment edge-cases ────────────────────────────

describe('VictoriaMetrics output format alignment', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->start = 1700000000;
        $this->end = $this->start + 600;
    });

    test('two series with identical timestamps produce two-element arrays per timestamp', function (): void {
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data' => [
                'result' => [[
                    'metric' => [],
                    'values' => [
                        [$this->start, '10'],
                        [$this->start + 300, '20'],
                    ],
                ]],
            ],
        ]);

        $result = $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw', 'srv'],
            protocols: ['tcp'],
            ports: [],
            display: 'sources',
        );

        foreach ($result['data'] as $ts => $values) {
            expect($values)->toHaveCount(2);
        }
    });

    test('ragged timestamps: series with non-overlapping timestamps produce single-element arrays', function (): void {
        // First call returns ts=start, second call returns ts=start+300
        $ts1 = $this->start;
        $ts2 = $this->start + 300;

        $responses = [
            json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'values' => [[$ts1, '10']]]]]]),
            json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'values' => [[$ts2, '20']]]]]]),
        ];

        $callCount = 0;
        // Override httpGet to return different fixture each call
        $vm = new class($responses, $callCount) extends VictoriaMetrics {
            private array $responses;
            private int $callCount = 0;

            public function __construct(array $responses, int &$callCount) {
                parent::__construct();
                $this->responses = $responses;
            }

            protected function httpGet(string $url, int $timeout = 30): string {
                return $this->responses[$this->callCount++] ?? '{"status":"success","data":{"result":[]}}';
            }

            protected function sendToVM(string $url, string $body): bool {
                return true;
            }

            /** @return false|resource */
            protected function tcpConnect(string $host, int $port, int &$errNo, string &$errStr): mixed {
                return fopen('php://memory', 'r');
            }
        };

        $result = $vm->get_graph_data(
            $ts1,
            $this->end,
            sources: ['gw', 'srv'],
            protocols: ['tcp'],
            ports: [],
            display: 'sources',
        );

        // With the current implementation each timestamp appears separately with 1 value:
        // document this as the known behavior (ragged timestamps are not merged/padded).
        expect($result['data'])->toHaveKey($ts1);
        expect($result['data'])->toHaveKey($ts2);
        expect($result['data'][$ts1])->toHaveCount(1);
        expect($result['data'][$ts2])->toHaveCount(1);
    });
});

// ── date_boundaries / last_update ─────────────────────────────────────────────

describe('VictoriaMetrics::date_boundaries() and last_update()', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
    });

    test('date_boundaries() returns [firstTs, lastTs] from VM response', function (): void {
        // Both calls are now instant queries returning value[1] as the timestamp.
        // First call: tfirst_over_time → first data timestamp
        // Second call: tlast_over_time → last data timestamp
        $makeFixture = static fn (int $ts) => json_encode([
            'status' => 'success',
            'data' => ['result' => [['metric' => [], 'value' => [time(), (string) $ts]]]],
        ]);
        $this->vm->getResponseQueue = [$makeFixture(1700000000), $makeFixture(1700005000)];

        [$first, $last] = $this->vm->date_boundaries('gw');
        expect($first)->toBeInt()->toBe(1700000000);
        expect($last)->toBeInt()->toBe(1700005000);
    });

    test('date_boundaries() returns [0, 0] for empty VM result', function (): void {
        // nextGetResponse already defaults to empty result
        [$first, $last] = $this->vm->date_boundaries('gw');
        expect($first)->toBe(0);
        expect($last)->toBe(0);
    });

    test('last_update() returns actual data timestamp from tlast_over_time response', function (): void {
        // tlast_over_time instant query: VM responds with value=[eval_now, last_raw_ts_as_string].
        // value[1] is the actual last raw-sample timestamp (NOT the query eval time).
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data' => [
                'result' => [[
                    'metric' => [],
                    'value' => [time(), '1714000000'],
                ]],
            ],
        ]);

        expect($this->vm->last_update('gw'))->toBe(1714000000);
    });

    test('date_boundaries() query uses tfirst_over_time and tlast_over_time', function (): void {
        $this->vm->date_boundaries('gw');
        expect(count($this->vm->capturedGetUrls))->toBe(2);
        expect(urldecode($this->vm->capturedGetUrls[0]))->toContain('tfirst_over_time');
        expect(urldecode($this->vm->capturedGetUrls[1]))->toContain('tlast_over_time');
    });

    test('last_update() returns 0 when httpGet throws', function (): void {
        $vm = new class extends VictoriaMetrics {
            protected function httpGet(string $url, int $timeout = 30): string {
                throw new Exception('connection refused');
            }

            protected function sendToVM(string $url, string $body): bool {
                return true;
            }

            /** @return false|resource */
            protected function tcpConnect(string $host, int $port, int &$errNo, string &$errStr): mixed {
                return fopen('php://memory', 'r');
            }
        };

        expect($vm->last_update('gw'))->toBe(0);
    });
});

// ── healthChecks() ────────────────────────────────────────────────────────────

describe('VictoriaMetrics::healthChecks()', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
    });

    test('always returns vm_config and import_years entries', function (): void {
        $checks = $this->vm->healthChecks('grp', []);
        $ids = array_column($checks, 'id');

        expect($ids)->toContain('vm_config');
        expect($ids)->toContain('import_years');
    });

    test('vm_config entry shows configured host:port', function (): void {
        $checks = $this->vm->healthChecks('grp', []);
        $ids = array_column($checks, 'id');
        $cfg = $checks[array_search('vm_config', $ids, true)];

        expect($cfg['detail'])->toContain('vm-test');
        expect($cfg['detail'])->toContain('8428');
    });

    test('import_years entry is ok when >= 1', function (): void {
        $checks = $this->vm->healthChecks('grp', []);
        $ids = array_column($checks, 'id');
        $iy = $checks[array_search('import_years', $ids, true)];

        expect($iy['status'])->toBe('ok');
    });
});

// ── get_graph_data() over several sources ─────────────────────────────────────

describe('VictoriaMetrics::get_graph_data() multi-source series', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->start = 1700000100;
        $this->end = $this->start + 3600;
        $this->vm->responder = fn (string $url): string => vmRangeResponse($this->start, '1');
        $this->queries = fn (): array => array_map(static fn (string $url): string => vmQueryParams($url)['query'], $this->vm->capturedGetUrls);
    });

    test('protocols display sums every protocol over the source regex, each source once', function (): void {
        $result = $this->vm->get_graph_data($this->start, $this->end, ['gw', 'srv'], ['tcp', 'any'], [], 'flows', 'protocols');

        expect(($this->queries)())->toBe([
            'sum(max by (source) (nfsen_flows_tcp{source=~"gw|srv",port="",protocol="tcp"}))',
            'sum(max by (source) (nfsen_flows{source=~"gw|srv",port=""}))',
        ]);
        expect($result['legend'])->toBe(['tcp_flows', 'any_flows']);
    });

    test('protocols display with any reads every configured source, profile included', function (): void {
        $this->vm->get_graph_data($this->start, $this->end, ['any'], ['udp'], [], 'packets', 'protocols', profile: 'live');

        expect(($this->queries)())->toBe(['sum(max by (source) (nfsen_packets_udp{source=~"gateway|server1",port="",protocol="udp",profile=~"live|"}))']);
    });

    test('a profile name is matched literally, not as a regex', function (): void {
        $this->vm->get_graph_data($this->start, $this->end, ['gw'], ['any'], [], 'flows', 'protocols', profile: 'a.b+c');

        expect(($this->queries)())->toBe(['sum(max by (source) (nfsen_flows{source="gw",port="",profile=~"a\\\\.b\\\\+c|"}))']);
    });

    test('ports display sums a source subset per port', function (): void {
        $result = $this->vm->get_graph_data($this->start, $this->end, ['gw', 'srv'], ['any'], [80, 443], 'bytes', 'ports');

        expect(($this->queries)())->toBe([
            'sum(max by (source) (nfsen_bytes{source=~"gw|srv",port="80"}))',
            'sum(max by (source) (nfsen_bytes{source=~"gw|srv",port="443"}))',
        ]);
        expect($result['legend'])->toBe(['80_bytes_any', '443_bytes_any']);
    });

    test('ports display with one source keeps the source in the legend', function (): void {
        $result = $this->vm->get_graph_data($this->start, $this->end, ['gateway'], ['tcp'], [80], 'flows', 'ports');

        expect(($this->queries)())->toBe(['sum(max by (source) (nfsen_flows_tcp{source="gateway",port="80",protocol="tcp"}))']);
        expect($result['legend'])->toBe(['80_flows_gateway_tcp']);
    });

    test('ports display over every configured source reads the cross-source series', function (): void {
        foreach ([['gateway', 'server1'], ['any']] as $sources) {
            $this->vm->capturedGetUrls = [];
            $result = $this->vm->get_graph_data($this->start, $this->end, $sources, ['any'], [80], 'flows', 'ports');

            expect(($this->queries)())->toBe(['sum(max by (source) (nfsen_flows{source="",port="80"}))']);
            expect($result['legend'])->toBe(['80_flows_any']);
        }
    });

    test('regex characters in source names are escaped for the string literal', function (): void {
        $this->vm->get_graph_data($this->start, $this->end, ['gw-1', 'edge.2'], ['any'], [], 'flows', 'protocols');

        expect(($this->queries)()[0])->toBe('sum(max by (source) (nfsen_flows{source=~"gw\\\\-1|edge\\\\.2",port=""}))');
    });

    test('sources display still issues one plain query per source', function (): void {
        $this->vm->get_graph_data($this->start, $this->end, ['gw', 'srv'], ['tcp'], [], 'flows', 'sources');

        expect(($this->queries)())->toBe([
            'nfsen_flows_tcp{source="gw",port="",protocol="tcp"}',
            'nfsen_flows_tcp{source="srv",port="",protocol="tcp"}',
        ]);
    });
});

// ── fetchTotals() / fetchProtocolTotals() ─────────────────────────────────────

describe('VictoriaMetrics stored totals', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->start = 1700000100;
        $this->end = $this->start + 3600;
        $this->params = fn (): array => array_map(vmQueryParams(...), $this->vm->capturedGetUrls);
    });

    test('is a TotalsProvider', function (): void {
        expect($this->vm)->toBeInstanceOf(TotalsProvider::class);
    });

    test('one instant query per metric: sum_over_time over end - start, evaluated at end - 1', function (): void {
        $this->vm->fetchTotals(['gw', 'srv'], 'live', $this->start, $this->end);

        $selector = '{source=~"gw|srv",port="",profile=~"live|"}[3600s]';
        expect(($this->params)())->toBe([
            ['query' => "sum(max by (source) (sum_over_time(nfsen_flows{$selector})))", 'time' => (string) ($this->end - 1)],
            ['query' => "sum(max by (source) (sum_over_time(nfsen_packets{$selector})))", 'time' => (string) ($this->end - 1)],
            ['query' => "sum(max by (source) (sum_over_time(nfsen_bytes{$selector})))", 'time' => (string) ($this->end - 1)],
        ]);
        expect(parse_url($this->vm->capturedGetUrls[0], PHP_URL_PATH))->toBe('/api/v1/query')
            ->and($this->vm->capturedTimeouts)->toBe(array_fill(0, 3, VictoriaMetrics::TOTALS_TIMEOUT))
        ;
    });

    test('returns the value of each metric', function (): void {
        $this->vm->responder = static function (string $url): string {
            preg_match('/nfsen_(\w+)\{/', vmQueryParams($url)['query'], $m);
            $value = ['flows' => '12', 'packets' => '340', 'bytes' => '56000'][$m[1]];

            return json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'value' => [0, $value]]]]]);
        };

        expect($this->vm->fetchTotals(['gw'], '', $this->start, $this->end))
            ->toBe(['flows' => 12.0, 'packets' => 340.0, 'bytes' => 56000.0])
        ;
    });

    test('the protocol variant selects the protocol series', function (): void {
        $this->vm->fetchTotals(['gw'], '', $this->start, $this->end, 'icmp');

        expect(($this->params)()[0]['query'])
            ->toBe('sum(max by (source) (sum_over_time(nfsen_flows_icmp{source="gw",port="",protocol="icmp"}[3600s])))')
        ;
    });

    test('an empty source list or any means every configured source', function (): void {
        foreach ([[], ['any']] as $sources) {
            $this->vm->capturedGetUrls = [];
            $this->vm->fetchTotals($sources, '', $this->start, $this->end);

            expect(($this->params)()[0]['query'])
                ->toBe('sum(max by (source) (sum_over_time(nfsen_flows{source=~"gateway|server1",port=""}[3600s])))')
            ;
        }
    });

    test('an empty or inverted window is zero without a query', function (): void {
        expect($this->vm->fetchTotals(['gw'], '', $this->end, $this->end))->toBe(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]);
        expect($this->vm->fetchTotals(['gw'], '', $this->end, $this->start))->toBe(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]);
        expect($this->vm->capturedGetUrls)->toBe([]);
    });

    test('rejects a protocol outside any|tcp|udp|icmp|other', function (): void {
        $this->vm->fetchTotals(['gw'], '', $this->start, $this->end, 'sctp');
    })->throws(InvalidArgumentException::class, 'sctp');

    test('an unreachable VictoriaMetrics throws after one query instead of answering zero', function (): void {
        $this->vm->responder = static fn (string $url): string => throw new Exception('connection refused');

        expect(fn () => $this->vm->fetchProtocolTotals(['gw'], '', $this->start, $this->end))
            ->toThrow(RuntimeException::class, 'VictoriaMetrics did not answer: connection refused')
            ->and($this->vm->capturedGetUrls)->toHaveCount(1)
        ;
    });

    test('an error answer throws; a window without samples is zero', function (): void {
        $this->vm->nextGetResponse = '{"status":"error","error":"bad query"}';
        expect(fn () => $this->vm->fetchTotals(['gw'], '', $this->start, $this->end))->toThrow(RuntimeException::class, 'without a result');

        $this->vm->nextGetResponse = '{"status":"success","data":{"result":[]}}';
        expect($this->vm->fetchTotals(['gw'], '', $this->start, $this->end))->toBe(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]);
    });

    test('fetchProtocolTotals() queries every metric of every protocol', function (): void {
        $this->vm->responder = static function (string $url): string {
            preg_match('/nfsen_(\w+?)(?:_(tcp|udp|icmp|other))?\{/', vmQueryParams($url)['query'], $m);
            $value = ['flows' => 1, 'packets' => 10, 'bytes' => 100][$m[1]] * ['' => 5, 'tcp' => 1, 'udp' => 2, 'icmp' => 3, 'other' => 4][$m[2] ?? ''];

            return json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'value' => [0, (string) $value]]]]]);
        };

        $totals = $this->vm->fetchProtocolTotals(['gw'], '', $this->start, $this->end);

        expect($this->vm->capturedGetUrls)->toHaveCount(15);
        expect(array_unique(array_column(($this->params)(), 'time')))->toBe([(string) ($this->end - 1)]);
        expect($totals)->toBe([
            'any' => ['flows' => 5.0, 'packets' => 50.0, 'bytes' => 500.0],
            'tcp' => ['flows' => 1.0, 'packets' => 10.0, 'bytes' => 100.0],
            'udp' => ['flows' => 2.0, 'packets' => 20.0, 'bytes' => 200.0],
            'icmp' => ['flows' => 3.0, 'packets' => 30.0, 'bytes' => 300.0],
            'other' => ['flows' => 4.0, 'packets' => 40.0, 'bytes' => 400.0],
        ]);
    });

    test('half-open window: the sample on start counts, the sample on end does not', function (): void {
        $vm = new VictoriaMetricsSampleStub();
        $vm->series[] = ['metric' => 'nfsen_flows', 'source' => 'gw', 'profile' => '', 'samples' => [
            $this->start - 300 => 1000.0,
            $this->start => 1.0,
            $this->start + 300 => 2.0,
            $this->end - 300 => 4.0,
            $this->end => 8000.0,
        ]];

        expect($vm->fetchTotals(['gw'], '', $this->start, $this->end)['flows'])->toBe(7.0);
        // Adjacent windows share no sample.
        expect($vm->fetchTotals(['gw'], '', $this->end, $this->end + 300)['flows'])->toBe(8000.0);
        expect($vm->fetchTotals(['gw'], '', $this->start - 300, $this->start)['flows'])->toBe(1000.0);
    });

    test('a source re-imported next to its pre-profile unlabelled series counts once', function (): void {
        $vm = new VictoriaMetricsSampleStub();
        $samples = [$this->start => 1.0, $this->start + 300 => 2.0];
        $vm->series = [
            ['metric' => 'nfsen_flows', 'source' => 'gw', 'profile' => 'live', 'samples' => $samples],
            ['metric' => 'nfsen_flows', 'source' => 'gw', 'profile' => '', 'samples' => $samples],
            ['metric' => 'nfsen_flows', 'source' => 'srv', 'profile' => 'live', 'samples' => [$this->start => 10.0]],
        ];

        expect($vm->fetchTotals(['gw', 'srv'], 'live', $this->start, $this->end)['flows'])->toBe(13.0);
    });
});

// ── fetchLatestSlot() / fetchRollingAverage() ─────────────────────────────────

describe('VictoriaMetrics load queries', function (): void {
    beforeEach(function (): void {
        makeVmSettings();
        $this->vm = new VictoriaMetricsTest();
        $this->queries = fn (): array => array_map(static fn (string $url): string => vmQueryParams($url)['query'], $this->vm->capturedGetUrls);
    });

    test('fetchLatestSlot() sums the sources, each source once', function (): void {
        $this->vm->fetchLatestSlot(['gw', 'srv'], 'live');

        expect(($this->queries)()[0])->toBe('sum(max by (source) (last_over_time(nfsen_flows{source=~"gw|srv",port="",profile=~"live|"}[5m])))');
    });

    test('fetchRollingAverage() sums the sources, each source once', function (): void {
        $this->vm->fetchRollingAverage(['gw'], 'live', 3600);

        expect(($this->queries)()[0])->toBe('sum(max by (source) (avg_over_time(nfsen_flows{source="gw",port="",profile=~"live|"}[3600s])))');
    });
});
