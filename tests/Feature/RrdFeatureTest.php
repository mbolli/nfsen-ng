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
 * @return string the temp directory path (caller must clean up)
 */
function makeRrdFeatureSettings(int $importYears = 3): string {
    $dir = sys_get_temp_dir() . '/rrd_feat_' . uniqid();
    mkdir($dir, 0o755, true);

    Config::$settings = Settings::fromArray([
        'general' => [
            'sources' => ['gw'],
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
        // Point at same dir (makeRrdFeatureSettings creates a new dir — override)
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
