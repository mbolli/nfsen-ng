<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Rrd;

// dbUpdatable() asks the datasource for its last update, so a real RRD file is needed.
if (!function_exists('rrd_version')) {
    test('rrd extension available')->skip('rrd PECL extension not available');

    return;
}

describe('Import rescan mode (#171)', function (): void {
    beforeEach(function (): void {
        $this->dir = sys_get_temp_dir() . '/import_rescan_' . uniqid();
        mkdir($this->dir, 0o755, true);

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => [
                'binary' => '/usr/bin/nfdump',
                'profiles-data' => '/var/nfdump/profiles-data',
                'profile' => 'live',
                'max-processes' => 1,
            ],
            'db' => ['RRD' => ['data_path' => $this->dir, 'import_years' => 3]],
            'log' => ['priority' => LOG_WARNING],
        ]);
        Config::$path = $this->dir;
        Config::$db = new Rrd();

        // Put the cursor at a known point, then ask about a capture file from before it.
        $this->cursor = time() - (time() % 300);
        Config::$db->create('gw');
        Config::$db->write([
            'source' => 'gw',
            'port' => 0,
            'date_timestamp' => $this->cursor,
            'fields' => ['flows' => 1, 'packets' => 1, 'bytes' => 1],
        ]);

        $this->olderFile = '/captures/nfcapd.' . date('YmdHi', $this->cursor - 86400);
    });

    afterEach(function (): void {
        foreach (glob($this->dir . '/*/*.rrd*') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            if (is_dir($d)) {
                @rmdir($d);
            }
        }
        @rmdir($this->dir);
    });

    test('a normal import skips a capture file older than the last update', function (): void {
        $import = new Import();
        $import->setCheckLastUpdate(true);

        expect($import->dbUpdatable($this->olderFile, 'gw'))->toBeFalse();
    });

    test('a rescan offers that same file to the datasource', function (): void {
        $import = new Import();
        $import->setCheckLastUpdate(true);
        $import->setRescan(true);

        expect($import->dbUpdatable($this->olderFile, 'gw'))->toBeTrue();
    });
});

describe('Datasource::acceptsHistoricWrites()', function (): void {
    test('RRD cannot write behind its last update', function (): void {
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/tmp', 'profile' => 'live', 'max-processes' => 1],
            'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
            'log' => ['priority' => LOG_WARNING],
        ]);
        Config::$path = sys_get_temp_dir();

        expect((new Rrd())->acceptsHistoricWrites())->toBeFalse();
    });
});
