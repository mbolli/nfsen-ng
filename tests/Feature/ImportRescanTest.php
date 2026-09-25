<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportStats;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;

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

/** Removes a directory tree the tests below created. */
function importRescanRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/** @return list<string> the -r argument of every nfdump call the stub logged */
function importRescanReads(string $log): array {
    $reads = [];
    foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (preg_match("/-r '?([^' ]+)'?/", $line, $m) === 1) {
            $reads[] = $m[1];
        }
    }

    return $reads;
}

describe('Backfill through Import::start()', function (): void {
    beforeEach(function (): void {
        $this->root = sys_get_temp_dir() . '/import_backfill_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/rrd', 0o755, true);

        // An nfdump that logs its arguments and answers every -I with the same totals.
        $this->log = $this->root . '/nfdump.log';
        $this->stub = $this->root . '/nfdump';
        file_put_contents($this->stub, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($this->log) . "\nprintf 'Flows: 5\\nPackets: 10\\nBytes: 1000\\n'\n");
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

        $this->cursor = time() - time() % 300;
        Config::$db->create('gw');
        Config::$db->write(['source' => 'gw', 'port' => 0, 'date_timestamp' => $this->cursor, 'fields' => ['flows' => 1, 'packets' => 1, 'bytes' => 1]]);

        // Two capture files a day behind the cursor.
        $this->files = [];
        foreach ([$this->cursor - 86400 - 600, $this->cursor - 86400 - 300] as $ts) {
            $at = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
            $rel = $at->format('Y/m/d') . '/nfcapd.' . $at->format('YmdHi');
            $path = $this->root . '/profiles-data/live/gw/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o755, true);
            }
            touch($path);
            $this->files[$rel] = $ts;
        }
        $this->from = new DateTime('@' . ($this->cursor - 2 * 86400));

        ImportStats::reset();
        TopNCollector::reset();
        TopNRepository::clearCache();
    });

    afterEach(function (): void {
        TopNCollector::reset();
        ImportStats::reset();
        Nfdump::$_instance = null;
        importRescanRemoveTree($this->root);
    });

    test('a normal import does not read files older than the last update', function (): void {
        $import = new Import();
        $import->setQuiet(true);
        $import->setCheckLastUpdate(true);
        $import->start($this->from);

        expect(importRescanReads($this->log))->toBe([]);
    });

    test('a Backfill reads the files older than the last update and records the import rate', function (): void {
        $import = new Import();
        $import->setQuiet(true);
        $import->setCheckLastUpdate(true);
        $import->setRescan(true);
        $import->start($this->from);

        expect(importRescanReads($this->log))->toBe(array_keys($this->files))
            ->and(ImportStats::summary(time())['samples'])->toBe(2)
        ;
    });

    test('every written file reaches the top-N collector with the -I totals of the import', function (): void {
        TopNCollector::start(new TopNRepository(Database::open(':memory:')), 31, time());
        $import = new Import();
        $import->setQuiet(true);
        $import->setRescan(true);
        $import->start($this->from);

        $items = [];
        while (($item = TopNCollector::next()) !== null) {
            $items[$item['relPath']] = [$item['profile'], $item['source'], $item['ts'], $item['totals']];
        }

        $totals = ['flows' => 5, 'packets' => 10, 'bytes' => 1000];
        expect($items)->toBe(array_map(static fn (int $ts): array => ['live', 'gw', $ts, $totals], $this->files));
    });

    test('importFile() hands the file to the collector and says whether it was written', function (): void {
        TopNCollector::start(new TopNRepository(Database::open(':memory:')), 31, time());
        $rel = array_key_first($this->files);
        $import = new Import();
        $import->setQuiet(true);

        $written = $import->importFile($rel, 'gw', true);
        $item = TopNCollector::next();

        expect($written)->toBeTrue()
            ->and($item['ts'] ?? null)->toBe($this->files[$rel])
            ->and($item['totals'] ?? null)->toBe(['flows' => 5, 'packets' => 10, 'bytes' => 1000])
            ->and($import->importFile('2026/01/01/not-a-capture', 'gw', true))->toBeFalse()
        ;
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
