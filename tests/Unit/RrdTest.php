<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Rrd;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Bootstrap Config::$settings and Config::$path so Rrd can be instantiated.
 * rrd extension is NOT required for unit tests.
 *
 * @param string $rrdPath directory used as the RRD data_path config value
 */
function makeRrdSettings(string $rrdPath): void
{
    Config::$settings = Settings::fromArray([
        'general' => [
            'sources'   => ['gateway', 'server1'],
            'ports'     => [80, 443],
            'db'        => 'RRD',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary'        => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile'       => 'live',
            'max-processes' => 1,
        ],
        'db' => [
            'RRD' => [
                'data_path'    => $rrdPath,
                'import_years' => 3,
            ],
        ],
        'log' => ['priority' => LOG_WARNING],
    ]);
    Config::$path = sys_get_temp_dir();
}

// ── get_data_path ─────────────────────────────────────────────────────────────

describe('Rrd::get_data_path()', function () {
    beforeEach(function () {
        makeRrdSettings('/rrd/data');
        if (!\function_exists('rrd_version')) {
            test()->markTestSkipped('rrd PECL extension not available');
        }
        $this->rrd = new Rrd();
    });

    test('source only → <path>/<source>.rrd', function () {
        expect($this->rrd->get_data_path('gateway'))
            ->toBe('/rrd/data/gateway.rrd');
    });

    test('source + port → <path>/<source>_<port>.rrd', function () {
        expect($this->rrd->get_data_path('gateway', 80))
            ->toBe('/rrd/data/gateway_80.rrd');
    });

    test('empty source + port → <path>/<port>.rrd', function () {
        expect($this->rrd->get_data_path('', 80))
            ->toBe('/rrd/data/80.rrd');
    });

    test('empty source + port=0 → <path>/.rrd (edge case)', function () {
        expect($this->rrd->get_data_path('', 0))
            ->toBe('/rrd/data/.rrd');
    });
})->skip(!function_exists('rrd_version'), 'rrd PECL extension not available');

// ── validateStructure — no rrd file ──────────────────────────────────────────

describe('Rrd::validateStructure() without existing file', function () {
    beforeEach(function () {
        makeRrdSettings(sys_get_temp_dir() . '/rrd_unit_' . uniqid());
        if (!\function_exists('rrd_version')) {
            test()->markTestSkipped('rrd PECL extension not available');
        }
        $this->rrd = new Rrd();
    });

    test('non-existent source returns valid=false with null rows', function () {
        $result = $this->rrd->validateStructure('nonexistent');
        expect($result)->toMatchArray([
            'valid'         => false,
            'message'       => 'RRD file does not exist',
            'expected_rows' => null,
            'actual_rows'   => null,
        ]);
    });
})->skip(!function_exists('rrd_version'), 'rrd PECL extension not available');

// ── healthChecks — filesystem logic only ─────────────────────────────────────

describe('Rrd::healthChecks() filesystem checks', function () {
    beforeEach(function () {
        if (!\function_exists('rrd_version')) {
            test()->markTestSkipped('rrd PECL extension not available');
        }
    });

    test('non-existent rrdPath emits rrd_dir error entry', function () {
        makeRrdSettings('/nonexistent/path/that/cannot/exist');
        $rrd    = new Rrd();
        $checks = $rrd->healthChecks('test', []);

        $ids = array_column($checks, 'id');
        expect($ids)->toContain('rrd_dir');

        $dirCheck = $checks[array_search('rrd_dir', $ids, true)];
        expect($dirCheck['status'])->toBe('error');
        expect($dirCheck['group'])->toBe('test');
    });

    test('valid writable dir with missing RRD file emits warning per source', function () {
        $dir = sys_get_temp_dir() . '/rrd_hc_' . uniqid();
        mkdir($dir, 0o755, true);
        makeRrdSettings($dir);

        $rrd    = new Rrd();
        $checks = $rrd->healthChecks('test', ['gw']);

        $ids = array_column($checks, 'id');

        // rrd_dir should be ok
        $dirCheck = $checks[array_search('rrd_dir', $ids, true)];
        expect($dirCheck['status'])->toBe('ok');

        // source entry should be warning (no RRD file yet)
        expect($ids)->toContain('rrd_data_gw');
        $srcCheck = $checks[array_search('rrd_data_gw', $ids, true)];
        expect($srcCheck['status'])->toBe('warning');

        rmdir($dir);
    });

    test('healthChecks always includes import_years entry', function () {
        makeRrdSettings(sys_get_temp_dir() . '/rrd_iy_' . uniqid());
        $rrd    = new Rrd();
        $checks = $rrd->healthChecks('grp', []);

        $ids = array_column($checks, 'id');
        expect($ids)->toContain('import_years');

        $iy = $checks[array_search('import_years', $ids, true)];
        expect($iy['status'])->toBe('ok');
        expect($iy['detail'])->toBe('3'); // fromArray import_years = 3
    });
})->skip(!function_exists('rrd_version'), 'rrd PECL extension not available');
