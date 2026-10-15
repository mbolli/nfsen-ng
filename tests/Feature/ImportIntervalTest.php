<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\CaptureInterval;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportStats;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\Nfdump;

if (!function_exists('rrd_version')) {
    test('rrd extension available')->skip('rrd PECL extension not available');

    return;
}

describe('Import of one-minute capture files (#184)', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir() . '/import_interval_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/rrd', 0o755, true);

        // An nfdump that answers every -I with 5 flows.
        $this->stub = $this->root . '/nfdump';
        file_put_contents($this->stub, "#!/bin/sh\nprintf 'Flows: 5\\nPackets: 10\\nBytes: 1000\\n'\n");
        chmod($this->stub, 0o755);

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => $this->stub, 'profiles-data' => $this->root . '/profiles-data', 'profile' => 'live', 'max-processes' => 1],
            'db' => ['RRD' => ['data_path' => $this->root . '/rrd', 'import_years' => 3]],
            'log' => ['priority' => LOG_ERR],
        ]);
        Config::$path = $this->root;
        Config::$db = new Rrd();
        Nfdump::$_instance = null;
        CaptureInterval::reset();

        // Three slots of five files each, two hours back.
        $this->slot = time() - time() % 300 - 7200;
        foreach (range(0, 14) as $k) {
            $at = (new DateTimeImmutable('@' . ($this->slot + 60 * $k)))->setTimezone(Config::nfcapdTimezone());
            $dir = $this->root . '/profiles-data/live/gw/' . $at->format('Y/m/d');
            is_dir($dir) || mkdir($dir, 0o755, true);
            touch($dir . '/nfcapd.' . $at->format('YmdHi'));
        }

        ImportStats::reset();
        TopNCollector::reset();
    });

    afterEach(function (): void {
        Nfdump::$_instance = null;
        CaptureInterval::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    });

    test('every file of a slot counts, not one in five', function (): void {
        $import = new Import();
        $import->setQuiet(true);
        $import->start(new DateTime('@' . ($this->slot - 86400)));

        // The first file of a fresh RRD stays unknown, so the first slot is left out.
        $totals = Config::$db->fetchTotals(['gw'], '', $this->slot + 300, $this->slot + 900);

        expect($totals['flows'])->toBe(50.0)
            ->and($totals['bytes'])->toBe(10000.0)
            ->and(Config::$db->last_update('gw'))->toBe($this->slot + 600)
        ;
    });
});
