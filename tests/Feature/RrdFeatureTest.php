<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Rrd;

// All tests in this file require the rrd PECL extension.
if (!function_exists('rrd_version')) {
    test('rrd extension available')->skip('rrd PECL extension not available');

    return;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Bootstrap Config for RRD feature tests using a temporary directory.
 *
 * @param list<string> $sources
 *
 * @return string the temp directory path (caller must clean up)
 */
function makeRrdFeatureSettings(int $importYears = 3, array $sources = ['gw']): string {
    $dir = sys_get_temp_dir() . '/rrd_feat_' . uniqid();
    mkdir($dir, 0o755, true);

    Config::$settings = Settings::fromArray([
        'general' => [
            'sources' => $sources,
            'ports' => [80],
            'db' => 'RRD',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile' => 'live',
            'max-processes' => 1,
        ],
        'db' => [
            'RRD' => [
                'data_path' => $dir,
                'import_years' => $importYears,
            ],
        ],
        'log' => ['priority' => LOG_WARNING],
    ]);
    Config::$path = $dir;

    return $dir;
}

/**
 * Remove all files in a temp directory, then remove the directory itself.
 */
function cleanRrdDir(string $dir): void {
    if (is_dir($dir)) {
        // *.rrd.first sidecar files (see Rrd::write()) live alongside the .rrd
        // file, so match both with *.rrd*.
        foreach (glob($dir . '/*.rrd*') ?: [] as $file) {
            unlink($file);
        }
        // Also recurse one level for profile/port sub-dirs if any
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*.rrd*') ?: [] as $f) {
                    unlink($f);
                }
                @rmdir($entry);
            }
        }
        @rmdir($dir);
    }
}

/**
 * One slot at 10 flows/s (tcp 6, udp 2, icmp 1, other 1), packets x50, bytes x10000, all
 * times $m, written as rate x 300 so stored rates and value x step stay whole numbers.
 *
 * @return array<string, int>
 */
function rrdSlotFields(int $m): array {
    $fields = [];
    foreach (['flows' => 1, 'packets' => 50, 'bytes' => 10000] as $metric => $factor) {
        foreach (['' => 10, '_tcp' => 6, '_udp' => 2, '_icmp' => 1, '_other' => 1] as $suffix => $rate) {
            $fields[$metric . $suffix] = $rate * $factor * $m * 300;
        }
    }

    return $fields;
}

/**
 * Writes slots $from, $from + 300, ...; the first write into a fresh RRD stays unknown.
 *
 * @param list<int> $multipliers
 */
function rrdWriteSlots(Rrd $rrd, string $source, int $from, array $multipliers, int $port = 0): void {
    foreach ($multipliers as $i => $m) {
        $rrd->write([
            'source' => $source,
            'port' => $port,
            'profile' => '',
            'date_iso' => '',
            'date_timestamp' => $from + $i * 300,
            'fields' => rrdSlotFields($m),
        ]);
    }
}

/**
 * What the given slots hold for one data source, summed.
 *
 * @param list<int> $multipliers
 */
function rrdSlotVolume(array $multipliers, string $ds): float {
    return (float) array_sum(array_map(static fn (int $m): int => rrdSlotFields($m)[$ds], $multipliers));
}

/**
 * @param list<int> $multipliers
 *
 * @return array{flows: float, packets: float, bytes: float}
 */
function rrdSlotTotals(array $multipliers, string $protocol = 'any'): array {
    $suffix = $protocol === 'any' ? '' : '_' . $protocol;

    return [
        'flows' => rrdSlotVolume($multipliers, 'flows' . $suffix),
        'packets' => rrdSlotVolume($multipliers, 'packets' . $suffix),
        'bytes' => rrdSlotVolume($multipliers, 'bytes' . $suffix),
    ];
}

// ── create / validateStructure ────────────────────────────────────────────────

describe('Rrd file creation and structure validation', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3);
        $this->rrd = new Rrd();
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    test('create() produces a valid RRD file', function (): void {
        $result = $this->rrd->create('test-src');
        expect($result)->toBeTrue();
        expect(file_exists($this->dir . '/live/test-src.rrd'))->toBeTrue();
    });

    test('validateStructure() passes after create() with matching import_years', function (): void {
        $this->rrd->create('test-src');
        $v = $this->rrd->validateStructure('test-src');
        expect($v['valid'])->toBeTrue();
        expect($v['expected_rows'])->toBe(3 * 365);
        expect($v['actual_rows'])->toBe(3 * 365);
    });

    test('create() with reset=true recreates an existing file', function (): void {
        $this->rrd->create('test-src');
        $firstMtime = filemtime($this->dir . '/live/test-src.rrd');

        // Slight sleep to ensure mtime differs
        sleep(1);

        $result = $this->rrd->create('test-src', 0, true);
        expect($result)->toBeTrue();
        $newMtime = filemtime($this->dir . '/live/test-src.rrd');
        expect($newMtime)->toBeGreaterThan($firstMtime);
    });

    test('validateStructure() detects mismatch when import_years differs', function (): void {
        // Create file with 3 years
        $this->rrd->create('test-src');

        // Re-configure with different import_years (without recreating)
        makeRrdFeatureSettings(5);
        // Point at same dir (makeRrdFeatureSettings creates a new dir, so override)
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/x', 'profile' => 'live', 'max-processes' => 1],
            'db' => ['RRD' => ['data_path' => $this->dir, 'import_years' => 5]],
            'log' => ['priority' => LOG_WARNING],
        ]);
        Config::$path = $this->dir;
        $rrd5 = new Rrd();

        $v = $rrd5->validateStructure('test-src');
        expect($v['valid'])->toBeFalse();
        expect($v['expected_rows'])->toBe(5 * 365);
        expect($v['actual_rows'])->toBe(3 * 365);
    });
});

// ── write / last_update / date_boundaries ─────────────────────────────────────

describe('Rrd write, last_update and date_boundaries', function () {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3);
        $this->rrd = new Rrd();
        $this->rrd->create('test-src');

        // Use a timestamp in the past so it falls inside the RRD's range and
        // aligns to a 5-minute boundary.
        $ts = strtotime('-1 hour');
        $this->ts = $ts - ($ts % 300); // floor to 5-min boundary
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    /**
     * Build a minimal write data array.
     */
    function rrdWriteData(string $source, int $timestamp): array {
        return [
            'source' => $source,
            'port' => 0,
            'date_timestamp' => $timestamp,
            'fields' => [
                'flows' => 100,
                'flows_tcp' => 60,
                'flows_udp' => 20,
                'flows_icmp' => 10,
                'flows_other' => 10,
                'packets' => 5000,
                'packets_tcp' => 3000,
                'packets_udp' => 1000,
                'packets_icmp' => 500,
                'packets_other' => 500,
                'bytes' => 1000000,
                'bytes_tcp' => 600000,
                'bytes_udp' => 200000,
                'bytes_icmp' => 100000,
                'bytes_other' => 100000,
            ],
        ];
    }

    test('write() returns true', function (): void {
        $result = $this->rrd->write(rrdWriteData('test-src', $this->ts));
        expect($result)->toBeTrue();
    });

    test('last_update() returns the written timestamp after write()', function (): void {
        $this->rrd->write(rrdWriteData('test-src', $this->ts));
        $lu = $this->rrd->last_update('test-src', 0);
        expect($lu)->toBeGreaterThanOrEqual($this->ts);
    });

    test('duplicate write() with same timestamp returns true silently', function (): void {
        $data = rrdWriteData('test-src', $this->ts);
        $this->rrd->write($data);
        $result = $this->rrd->write($data); // same ts → should skip, not throw
        expect($result)->toBeTrue();
    });

    test('date_boundaries() returns [firstTs, lastTs] as integers after write()', function (): void {
        $this->rrd->write(rrdWriteData('test-src', $this->ts));
        [$first, $last] = $this->rrd->date_boundaries('test-src');
        expect($first)->toBeInt();
        expect($last)->toBeInt();
        expect($last)->toBeGreaterThan(0);
    });
});

describe('Rrd get_graph_data trailing-slot handling (#154)', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3);
        $this->rrd = new Rrd();
        $this->rrd->create('gw');

        // Four consecutive 5-min slots ~1h ago. Consecutive (within the 600s
        // heartbeat) so each carries a real ABSOLUTE rate rather than an
        // out-of-heartbeat gap.
        $ts = strtotime('-1 hour');
        $this->base = $ts - ($ts % 300);
        for ($i = 0; $i < 4; ++$i) {
            $this->rrd->write(rrdWriteData('gw', $this->base + $i * 300));
        }
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    // Regression for #154: RRD always returns a trailing NaN row past rrd_last.
    // In bits mode that NaN was set to null and then multiplied (`null * 8 === 0`
    // in PHP), turning the empty slot into a real 0 and making the traffic graph
    // drop to zero at the right edge. The empty slot must stay null (a gap).
    test('empty trailing slot stays null (not 0) in bits mode', function (): void {
        $result = $this->rrd->get_graph_data(
            $this->base - 600,
            $this->base + 4 * 300 + 600, // extend past the last write
            ['gw'],
            ['any'],
            [],
            'bits',
            'sources',
        );

        expect($result)->toBeArray();
        $series = array_map(static fn (array $row) => $row[0], $result['data']);

        // Real slots are present and positive (bytes → bits, so > 0)...
        $realValues = array_filter($series, static fn ($v) => $v !== null);
        expect($realValues)->not->toBeEmpty();
        expect(max($realValues))->toBeGreaterThan(0);

        // ...and the trailing empty slot is a null gap, never a real 0.
        expect(end($series))->toBeNull();
        foreach ($series as $v) {
            expect($v === 0 || $v === 0.0)->toBeFalse();
        }
    });
});

// ── createMissingPortDatabases (#172) ─────────────────────────────────────────

describe('Rrd::createMissingPortDatabases()', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3);
        $this->rrd = new Rrd();
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    test('creates the aggregate and per-source database of a port with no traffic', function (): void {
        expect($this->rrd->createMissingPortDatabases(['gw'], true, true))->toBeTrue();

        expect(file_exists($this->dir . '/live/80.rrd'))->toBeTrue();
        expect(file_exists($this->dir . '/live/gw_80.rrd'))->toBeTrue();
    });

    test('leaves an existing database untouched', function (): void {
        $this->rrd->create('gw', 80);
        $file = $this->dir . '/live/gw_80.rrd';
        $before = filemtime($file);

        sleep(1);
        $this->rrd->createMissingPortDatabases(['gw'], true, true);

        expect(filemtime($file))->toBe($before);
    });

    test('creates only the aggregate when ports are not processed by source', function (): void {
        $this->rrd->createMissingPortDatabases(['gw'], true, false);

        expect(file_exists($this->dir . '/live/80.rrd'))->toBeTrue();
        expect(file_exists($this->dir . '/live/gw_80.rrd'))->toBeFalse();
    });

    test('creates only the per-source database when the aggregate is not processed', function (): void {
        $this->rrd->createMissingPortDatabases(['gw'], false, true);

        expect(file_exists($this->dir . '/live/80.rrd'))->toBeFalse();
        expect(file_exists($this->dir . '/live/gw_80.rrd'))->toBeTrue();
    });
});

// ── graph data with missing databases (#172) ──────────────────────────────────

describe('Rrd::get_graph_data() with missing databases', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3);
        $this->rrd = new Rrd();
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    // Before #172 a port RRD that was never created made rrd_xport fail, taking the whole
    // graph down with "No such file or directory" instead of drawing the ports that do exist.
    test('a port without a database yields an empty series instead of an error', function (): void {
        $result = $this->rrd->get_graph_data(time() - 3600, time(), ['gw'], ['any'], [80], 'flows', 'ports');

        expect($result)->toBeArray();
        expect($result['data'])->toBe([]);
        expect($result['legend'])->toBe([]);
    });

    test('an existing port is still graphed when another port has no database', function (): void {
        $this->rrd->create('', 80);

        $result = $this->rrd->get_graph_data(time() - 3600, time(), ['any'], ['any'], [80, 443], 'flows', 'ports');

        expect($result)->toBeArray();
        expect($result['legend'])->toHaveCount(1);
    });

    test('a source without a database yields an empty series instead of an error', function (): void {
        $result = $this->rrd->get_graph_data(time() - 3600, time(), ['gw'], ['any'], [], 'flows', 'sources');

        expect($result)->toBeArray();
        expect($result['data'])->toBe([]);
    });
});

// ── Stored totals (TotalsProvider) ────────────────────────────────────────────

describe('Rrd::fetchTotals() and fetchProtocolTotals() over [start, end)', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3, ['gw', 'srv']);
        $this->rrd = new Rrd();

        $ts = strtotime('-2 hours');
        $this->base = $ts - ($ts % 300);
        // Slot i holds multiplier i + 1; slot 0 is the unknown first write.
        rrdWriteSlots($this->rrd, 'gw', $this->base, range(1, 10));
        $this->slot = fn (int $i): int => $this->base + $i * 300;
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    // Slots 1 and 6 carry data right outside both edges, so an off-by-one on either side shows.
    test('sums value x step over the slots in [start, end), the slot on end excluded', function (): void {
        $totals = $this->rrd->fetchTotals(['gw'], '', ($this->slot)(2), ($this->slot)(6));

        expect($totals)->toBe(rrdSlotTotals([3, 4, 5, 6]));
    });

    test('a one-slot window holds exactly the slot starting at start', function (): void {
        expect($this->rrd->fetchTotals(['gw'], '', ($this->slot)(4), ($this->slot)(5)))->toBe(rrdSlotTotals([5]));
    });

    test('an unaligned window counts the slots whose start lies inside it', function (): void {
        $totals = $this->rrd->fetchTotals(['gw'], '', ($this->slot)(2) + 1, ($this->slot)(6) + 1);

        expect($totals)->toBe(rrdSlotTotals([4, 5, 6, 7]));
    });

    test('the protocol variant reads the protocol data sources', function (): void {
        expect($this->rrd->fetchTotals(['gw'], '', ($this->slot)(2), ($this->slot)(6), 'tcp'))
            ->toBe(rrdSlotTotals([3, 4, 5, 6], 'tcp'))
        ;
        expect($this->rrd->fetchTotals(['gw'], '', ($this->slot)(2), ($this->slot)(6), 'other'))
            ->toBe(rrdSlotTotals([3, 4, 5, 6], 'other'))
        ;
    });

    test('fetchProtocolTotals() answers every protocol and agrees with fetchTotals()', function (): void {
        $start = ($this->slot)(2);
        $end = ($this->slot)(6);
        $all = $this->rrd->fetchProtocolTotals(['gw'], '', $start, $end);

        expect(array_keys($all))->toBe(['any', 'tcp', 'udp', 'icmp', 'other']);
        foreach ($all as $protocol => $totals) {
            expect($totals)->toBe(rrdSlotTotals([3, 4, 5, 6], $protocol));
            expect($this->rrd->fetchTotals(['gw'], '', $start, $end, $protocol))->toBe($totals);
        }
        expect($all['tcp']['bytes'] + $all['udp']['bytes'] + $all['icmp']['bytes'] + $all['other']['bytes'])
            ->toBe($all['any']['bytes'])
        ;
    });

    test('reads each source database once, for one protocol or all of them', function (): void {
        rrdWriteSlots($this->rrd, 'srv', $this->base, range(1, 10));
        $rrd = new class extends Rrd {
            /** @var list<string> */
            public array $reads = [];

            protected function rrdFetch(string $file, array $options): array|false {
                $this->reads[] = basename($file);

                return parent::rrdFetch($file, $options);
            }
        };

        $rrd->fetchProtocolTotals(['gw', 'srv'], '', ($this->slot)(2), ($this->slot)(6));
        expect($rrd->reads)->toBe(['gw.rrd', 'srv.rrd']);

        $rrd->reads = [];
        $rrd->fetchTotals(['gw', 'srv'], '', ($this->slot)(2), ($this->slot)(6), 'udp');
        expect($rrd->reads)->toBe(['gw.rrd', 'srv.rrd']);
    });

    test('sums over sources, and an empty list or any means every configured source', function (): void {
        rrdWriteSlots($this->rrd, 'srv', $this->base, array_map(static fn (int $m): int => 2 * $m, range(1, 10)));
        $expected = rrdSlotTotals([3, 4, 5, 6, 6, 8, 10, 12]);

        foreach ([['gw', 'srv'], [], ['any']] as $sources) {
            expect($this->rrd->fetchTotals($sources, '', ($this->slot)(2), ($this->slot)(6)))->toBe($expected);
        }
    });

    test('a source without a database adds nothing', function (): void {
        expect($this->rrd->fetchTotals(['gw', 'nosuch'], '', ($this->slot)(2), ($this->slot)(6)))
            ->toBe(rrdSlotTotals([3, 4, 5, 6]))
        ;
    });

    test('a consolidated row counts pro rata for the slots inside the window', function (): void {
        // Only a 30-minute archive, so rrd_fetch answers with 1800 s rows.
        $file = $this->rrd->get_data_path('srv');
        $start = intdiv((int) strtotime('-1 day'), 1800) * 1800;
        $creator = new RRDCreator($file, (string) $start, 300);
        $creator->addDataSource('flows:ABSOLUTE:600:U:U');
        $creator->addArchive('AVERAGE:0.5:6:100');
        $creator->save();
        $updater = new RRDUpdater($file);
        for ($i = 1; $i <= 12; ++$i) {
            $updater->update(['flows' => 3000], (string) ($start + $i * 300));
        }

        // Nine slots: the whole first row and half of the second.
        $totals = $this->rrd->fetchTotals(['srv'], '', $start + 300, $start + 3000);

        expect($totals['flows'])->toBe(27000.0);
        expect($totals['bytes'])->toBe(0.0);
    });
});

// ── Multi-source series ───────────────────────────────────────────────────────

describe('Rrd::get_graph_data() protocols display across sources', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3, ['gw', 'srv']);
        $this->rrd = new Rrd();

        $ts = strtotime('-2 hours');
        $this->base = $ts - ($ts % 300);
        rrdWriteSlots($this->rrd, 'gw', $this->base, [1, 2, 3, 4, 5, 6]);
        // srv stops after slot 3.
        rrdWriteSlots($this->rrd, 'srv', $this->base, [2, 4, 6, 8]);
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    test('one series per protocol, summed over the sources', function (): void {
        $result = $this->rrd->get_graph_data($this->base, $this->base + 6 * 300, ['gw', 'srv'], [], [], 'flows', 'protocols');

        expect($result['legend'])->toBe(['tcp_flows', 'udp_flows', 'icmp_flows', 'other_flows']);
        // Slot 3: gw 4, srv 8 times the per-protocol rate.
        expect($result['data'][$this->base + 3 * 300])->toBe([72.0, 24.0, 12.0, 12.0]);
    });

    test('a slot one source has no data for still shows the others (ADDNAN)', function (): void {
        $result = $this->rrd->get_graph_data($this->base, $this->base + 6 * 300, ['gw', 'srv'], ['tcp', 'any'], [], 'flows', 'protocols');

        expect($result['legend'])->toBe(['tcp_flows', 'any_flows']);
        expect($result['data'][$this->base + 5 * 300])->toBe([36.0, 60.0]);
    });

    test('the all-sources selection sums every configured source', function (): void {
        $result = $this->rrd->get_graph_data($this->base, $this->base + 6 * 300, ['any'], ['any'], [], 'flows', 'protocols');

        expect($result['legend'])->toBe(['any_flows']);
        expect($result['data'][$this->base + 3 * 300])->toBe([120.0]);
    });

    test('the sources display still draws one series per source', function (): void {
        $result = $this->rrd->get_graph_data($this->base, $this->base + 6 * 300, ['gw', 'srv'], ['tcp'], [], 'flows', 'sources');

        expect($result['legend'])->toBe(['gw_flows_tcp', 'srv_flows_tcp']);
        expect($result['data'][$this->base + 3 * 300])->toBe([24.0, 48.0]);
    });
});

describe('Rrd::get_graph_data() ports display over a source subset', function (): void {
    beforeEach(function (): void {
        $this->dir = makeRrdFeatureSettings(3, ['gw', 'srv', 'edge']);
        $this->rrd = new Rrd();

        $ts = strtotime('-2 hours');
        $this->base = $ts - ($ts % 300);
        rrdWriteSlots($this->rrd, 'gw', $this->base, [1, 2, 3, 4], 80);
        rrdWriteSlots($this->rrd, 'srv', $this->base, [2, 4, 6, 8], 80);
        // The cross-source database, deliberately not the sum of the two above.
        rrdWriteSlots($this->rrd, '', $this->base, [5, 10, 15, 20], 80);
        $this->graph = fn (array $sources): array => $this->rrd->get_graph_data($this->base, $this->base + 4 * 300, $sources, ['any'], [80], 'flows', 'ports');
    });

    afterEach(function (): void {
        cleanRrdDir($this->dir);
    });

    test('a subset sums the per-source port databases', function (): void {
        $result = ($this->graph)(['gw', 'srv']);

        expect($result['legend'])->toBe(['80_flows_any']);
        expect($result['data'][$this->base + 3 * 300])->toBe([120.0]);
    });

    test('a single source reads its own port database', function (): void {
        $result = ($this->graph)(['gw']);

        expect($result['legend'])->toBe(['80_flows_gw_any']);
        expect($result['data'][$this->base + 3 * 300])->toBe([40.0]);
    });

    test('a per-source port database that does not exist adds nothing', function (): void {
        expect(($this->graph)(['gw', 'edge'])['data'][$this->base + 3 * 300])->toBe([40.0]);
    });

    test('every configured source, or any, reads the cross-source port database', function (): void {
        foreach ([['gw', 'srv', 'edge'], ['any'], []] as $sources) {
            $result = ($this->graph)($sources);

            expect($result['legend'])->toBe(['80_flows_any']);
            expect($result['data'][$this->base + 3 * 300])->toBe([200.0]);
        }
    });
});
