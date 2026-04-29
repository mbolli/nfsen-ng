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
        foreach (glob($dir . '/*.rrd') ?: [] as $file) {
            unlink($file);
        }
        // Also recurse one level for port sub-dirs if any
        foreach (glob($dir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*.rrd') ?: [] as $f) {
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
        expect(file_exists($this->dir . '/test-src.rrd'))->toBeTrue();
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
        $firstMtime = filemtime($this->dir . '/test-src.rrd');

        // Slight sleep to ensure mtime differs
        sleep(1);

        $result = $this->rrd->create('test-src', 0, true);
        expect($result)->toBeTrue();
        $newMtime = filemtime($this->dir . '/test-src.rrd');
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
