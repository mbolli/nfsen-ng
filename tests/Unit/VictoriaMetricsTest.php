<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\VictoriaMetrics;

// ── Test double ───────────────────────────────────────────────────────────────

/**
 * Subclass that replaces HTTP I/O with in-memory stubs so every test runs
 * entirely offline and without curl.
 */
class TestVM extends VictoriaMetrics
{
    /** @var list<string> */
    public array $capturedGetUrls = [];

    /** @var list<string> */
    public array $capturedPostBodies = [];

    public string $nextGetResponse = '{"status":"success","data":{"result":[]}}';
    public bool $nextSendResult    = true;

    /** @var list<string> Optional queue of responses for sequential calls; falls back to nextGetResponse */
    public array $getResponseQueue = [];

    protected function httpGet(string $url): string
    {
        $this->capturedGetUrls[] = $url;

        if (!empty($this->getResponseQueue)) {
            return array_shift($this->getResponseQueue);
        }

        return $this->nextGetResponse;
    }

    protected function sendToVM(string $url, string $body): bool
    {
        $this->capturedPostBodies[] = $body;

        return $this->nextSendResult;
    }
}

// ── Bootstrap helper ──────────────────────────────────────────────────────────

function makeVmSettings(): void
{
    Config::$settings = Settings::fromArray([
        'general' => [
            'sources'   => ['gateway', 'server1'],
            'ports'     => [80, 443],
            'db'        => 'VictoriaMetrics',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary'        => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile'       => 'live',
            'max-processes' => 1,
        ],
        'db' => [
            'VictoriaMetrics' => [
                'host'         => 'vm-test',
                'port'         => 8428,
                'import_years' => 3,
            ],
        ],
        'log' => ['priority' => LOG_WARNING],
    ]);
    Config::$path = sys_get_temp_dir();
}

// ── Simple interface methods ───────────────────────────────────────────────────

describe('VictoriaMetrics basic interface methods', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm = new TestVM();
    });

    test('reset() always returns true', function () {
        expect($this->vm->reset(['gw', 'srv']))->toBeTrue();
        expect($this->vm->reset([]))->toBeTrue();
    });

    test('get_data_path() contains query_range', function () {
        expect($this->vm->get_data_path())->toContain('query_range');
    });

    test('get_data_path() contains configured host and port', function () {
        expect($this->vm->get_data_path())->toContain('vm-test:8428');
    });
});

// ── write() ───────────────────────────────────────────────────────────────────

describe('VictoriaMetrics::write()', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm = new TestVM();
        $this->ts = 1700000000; // arbitrary fixed Unix epoch
    });

    test('write() with port=0 does NOT include port label in stored metric (aggregate total)', function () {
        $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows' => 100],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // The stored aggregate must have no port label at all
        expect($body)->not->toContain('port=');
    });

    test('flows_tcp field emits correct Prometheus line', function () {
        $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows_tcp' => 42],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // nfsen_flows_tcp{source="gw",protocol="tcp"} 42 1700000000000
        expect($body)->toContain('nfsen_flows_tcp{');
        expect($body)->toContain('source="gw"');
        expect($body)->toContain('protocol="tcp"');
        expect($body)->toContain('} 42 ' . ($this->ts * 1000));
    });

    test('flows field (no protocol suffix) emits bare metric name', function () {
        $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows' => 100],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        // protocol tag should be 'total', which is excluded from labels
        expect($body)->toContain('nfsen_flows');
        expect($body)->toContain('} 100 ' . ($this->ts * 1000));
        // should NOT include a protocol label for 'total'
        expect($body)->not->toContain('protocol="total"');
    });

    test('null and empty fields are skipped', function () {
        $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows_tcp' => null, 'flows_udp' => '', 'packets' => 50],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        expect($body)->not->toContain('flows_tcp');
        expect($body)->not->toContain('flows_udp');
        expect($body)->toContain('nfsen_packets');
    });

    test('write() with port > 0 includes port label', function () {
        $this->vm->write([
            'source'         => 'gw',
            'port'           => 443,
            'date_timestamp' => $this->ts,
            'fields'         => ['bytes' => 9999],
        ]);

        $body = $this->vm->capturedPostBodies[0] ?? '';
        expect($body)->toContain('port="443"');
    });

    test('write() returns false when sendToVM fails', function () {
        $this->vm->nextSendResult = false;
        $result = $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows' => 1],
        ]);
        expect($result)->toBeFalse();
    });

    test('write() with all-null fields returns true (nothing to send)', function () {
        $result = $this->vm->write([
            'source'         => 'gw',
            'port'           => 0,
            'date_timestamp' => $this->ts,
            'fields'         => ['flows' => null],
        ]);
        expect($result)->toBeTrue();
        expect($this->vm->capturedPostBodies)->toBeEmpty();
    });
});

// ── get_graph_data() ──────────────────────────────────────────────────────────

describe('VictoriaMetrics::get_graph_data()', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm    = new TestVM();
        $this->start = 1700000000;
        $this->end   = $this->start + 3600;
    });

    test('display=protocols issues one GET request per protocol', function () {
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

    test('display=sources includes port="" selector to exclude port-specific series', function () {
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

    test('display=protocols includes port="" selector to exclude port-specific series', function () {
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

    test('display=ports uses explicit port label and does NOT add port="" selector', function () {
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

    test('display=sources issues one GET per source', function () {
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

    test('type=bits queries bytes metric and returns values multiplied by 8', function () {
        // Return a single data point
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data'   => [
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

    test('output structure has required keys', function () {
        $result = $this->vm->get_graph_data(
            $this->start,
            $this->end,
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [],
        );

        expect($result)->toHaveKeys(['data', 'start', 'end', 'step', 'legend']);
    });

    test('empty VM response produces empty data with correct metadata', function () {
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

// ── transformToOutputFormat — alignment edge-cases ───────────────────────────

describe('VictoriaMetrics output format alignment', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm    = new TestVM();
        $this->start = 1700000000;
        $this->end   = $this->start + 600;
    });

    test('two series with identical timestamps produce two-element arrays per timestamp', function () {
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data'   => [
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

    test('ragged timestamps: series with non-overlapping timestamps produce single-element arrays', function () {
        // First call returns ts=start, second call returns ts=start+300
        $ts1 = $this->start;
        $ts2 = $this->start + 300;

        $responses = [
            json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'values' => [[$ts1, '10']]]]]]),
            json_encode(['status' => 'success', 'data' => ['result' => [['metric' => [], 'values' => [[$ts2, '20']]]]]]),
        ];

        $callCount = 0;
        // Override httpGet to return different fixture each call
        $vm = new class ($responses, $callCount) extends VictoriaMetrics {
            private array $responses;
            private int $callCount = 0;

            public function __construct(array $responses, int &$callCount)
            {
                parent::__construct();
                $this->responses = $responses;
            }

            protected function httpGet(string $url): string
            {
                return $this->responses[$this->callCount++] ?? '{"status":"success","data":{"result":[]}}';
            }

            protected function sendToVM(string $url, string $body): bool
            {
                return true;
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

        // With the current implementation each timestamp appears separately with 1 value —
        // document this as the known behavior (ragged timestamps are not merged/padded).
        expect($result['data'])->toHaveKey($ts1);
        expect($result['data'])->toHaveKey($ts2);
        expect($result['data'][$ts1])->toHaveCount(1);
        expect($result['data'][$ts2])->toHaveCount(1);
    });
});

// ── date_boundaries / last_update ─────────────────────────────────────────────

describe('VictoriaMetrics::date_boundaries() and last_update()', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm = new TestVM();
    });

    test('date_boundaries() returns [firstTs, lastTs] from VM response', function () {
        // Both calls are now instant queries returning value[1] as the timestamp.
        // First call: tfirst_over_time → first data timestamp
        // Second call: tlast_over_time → last data timestamp
        $makeFixture = static fn (int $ts) => json_encode([
            'status' => 'success',
            'data'   => ['result' => [['metric' => [], 'value' => [time(), (string) $ts]]]],
        ]);
        $this->vm->getResponseQueue = [$makeFixture(1700000000), $makeFixture(1700005000)];

        [$first, $last] = $this->vm->date_boundaries('gw');
        expect($first)->toBeInt()->toBe(1700000000);
        expect($last)->toBeInt()->toBe(1700005000);
    });

    test('date_boundaries() returns [0, 0] for empty VM result', function () {
        // nextGetResponse already defaults to empty result
        [$first, $last] = $this->vm->date_boundaries('gw');
        expect($first)->toBe(0);
        expect($last)->toBe(0);
    });

    test('last_update() returns actual data timestamp from tlast_over_time response', function () {
        // tlast_over_time instant query: VM responds with value=[eval_now, last_raw_ts_as_string].
        // value[1] is the actual last raw-sample timestamp (NOT the query eval time).
        $this->vm->nextGetResponse = json_encode([
            'status' => 'success',
            'data'   => [
                'result' => [[
                    'metric' => [],
                    'value'  => [time(), '1714000000'],
                ]],
            ],
        ]);

        expect($this->vm->last_update('gw'))->toBe(1714000000);
    });

    test('date_boundaries() query uses tfirst_over_time and tlast_over_time', function () {
        $this->vm->date_boundaries('gw');
        expect(count($this->vm->capturedGetUrls))->toBe(2);
        expect(urldecode($this->vm->capturedGetUrls[0]))->toContain('tfirst_over_time');
        expect(urldecode($this->vm->capturedGetUrls[1]))->toContain('tlast_over_time');
    });

    test('last_update() returns 0 when httpGet throws', function () {
        $vm = new class extends VictoriaMetrics {
            protected function httpGet(string $url): string
            {
                throw new \Exception('connection refused');
            }

            protected function sendToVM(string $url, string $body): bool
            {
                return true;
            }
        };

        expect($vm->last_update('gw'))->toBe(0);
    });
});

// ── healthChecks() ────────────────────────────────────────────────────────────

describe('VictoriaMetrics::healthChecks()', function () {
    beforeEach(function () {
        makeVmSettings();
        $this->vm = new TestVM();
        // Seed 'OK' as first httpGet response (the /health endpoint call)
        $this->vm->getResponseQueue = ['OK'];
    });

    test('always returns vm_config and import_years entries', function () {
        $checks = $this->vm->healthChecks('grp', []);
        $ids    = array_column($checks, 'id');

        expect($ids)->toContain('vm_config');
        expect($ids)->toContain('import_years');
    });

    test('vm_config entry detail contains configured host:port', function () {
        $checks = $this->vm->healthChecks('grp', []);
        $ids    = array_column($checks, 'id');
        $cfg    = $checks[array_search('vm_config', $ids, true)];

        expect($cfg['detail'])->toContain('vm-test');
        expect($cfg['detail'])->toContain('8428');
        expect($cfg['detail'])->toContain('/vmui');
    });

    test('import_years entry is ok when >= 1', function () {
        $checks = $this->vm->healthChecks('grp', []);
        $ids    = array_column($checks, 'id');
        $iy     = $checks[array_search('import_years', $ids, true)];

        expect($iy['status'])->toBe('ok');
    });
});
