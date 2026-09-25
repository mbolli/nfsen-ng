<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\HealthMetrics;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\NfdumpSlots;

/**
 * @param list<string>          $sources
 * @param array<string, string> $rrd     RRD datasource config
 */
function healthSettings(string $profilesData, array $sources, string $datasource = 'RRD', array $rrd = [], int $maxProcesses = 4, int $logPriority = LOG_ERR): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => $sources, 'ports' => [], 'db' => $datasource, 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $profilesData, 'profile' => 'live', 'max-processes' => $maxProcesses],
        'db' => ['RRD' => $rrd],
        'log' => ['priority' => $logPriority],
    ]);
}

/** Writes a rotated capture file for $ts under its nfcapd-timezone day directory; returns the path. */
function healthCapture(string $root, string $profile, string $source, int $ts, ?int $mtime = null, string $name = ''): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
    $dir = implode('/', [$root, $profile, $source, $dt->format('Y'), $dt->format('m'), $dt->format('d')]);
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $path = $dir . '/' . ($name !== '' ? $name : 'nfcapd.' . $dt->format('YmdHi'));
    touch($path, $mtime ?? $ts + 300);

    return $path;
}

function healthRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/** Row for one profile and source out of HealthMetrics::sources(). */
function healthRow(array $rows, string $profile, string $source): array {
    foreach ($rows as $row) {
        if ($row['profile'] === $profile && $row['source'] === $source) {
            return $row;
        }
    }

    throw new RuntimeException("no row for {$profile}/{$source}");
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/nfsen-ng-health-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0o777, true);
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->dbBefore = isset(Config::$db) ? Config::$db : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : '';

    // Stubs Config::$db with last_update per "profile/source"; anything else was never imported.
    $this->importedUntil = function (array $imported): void {
        $db = $this->createStub(Datasource::class);
        $db->method('last_update')->willReturnCallback(
            static fn (string $source, int $port = 0, string $profile = ''): int => $imported["{$profile}/{$source}"] ?? 0
        );
        Config::$db = $db;
    };
});

afterEach(function (): void {
    healthRemoveTree($this->root);
    if ($this->settingsBefore instanceof Settings) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->dbBefore instanceof Datasource) {
        Config::$db = $this->dbBefore;
    }
    Config::$stateDir = $this->stateDirBefore;
});

describe('HealthMetrics::sources', function (): void {
    // 2024-01-03 12:00 UTC
    $now = 1704283200;

    test('reports newest file, data until, written, imported, pending and state per profile and source', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway', 'swi6', 'old', 'ghost']);

        foreach ([$now - 900, $now - 600, $now - 300] as $ts) {
            $newest = healthCapture($data, 'live', 'gateway', $ts, $now - 60);
        }
        // Rewritten continuously by nfcapd, so it must not count as the newest capture.
        healthCapture($data, 'live', 'gateway', $now, $now, 'nfcapd.current.4242');

        healthCapture($data, 'live', 'swi6', $now - 2 * 86400, $now - 2 * 86400 + 300);
        healthCapture($data, 'live', 'swi6', $now - 9 * 86400);
        healthCapture($data, 'live', 'swi6', $now - 30 * 86400);

        healthCapture($data, 'live', 'old', $now - 10 * 86400);

        healthCapture($data, 'backup', 'gateway', $now - 300, $now - 100);

        ($this->importedUntil)(['live/gateway' => $now - 600, 'backup/gateway' => $now - 300]);

        $rows = HealthMetrics::sources($now);

        expect(array_map(static fn (array $r): string => $r['profile'] . '/' . $r['source'], $rows))->toBe([
            'backup/gateway', 'backup/swi6', 'backup/old', 'backup/ghost',
            'live/gateway', 'live/swi6', 'live/old', 'live/ghost',
        ])
            ->and(healthRow($rows, 'live', 'gateway'))->toBe([
                'profile' => 'live',
                'source' => 'gateway',
                'newestFile' => basename($newest),
                'dataUntil' => $now,
                'written' => $now - 60,
                'imported' => $now - 600,
                'pending' => 1,
                'state' => 'healthy',
            ])
            ->and(healthRow($rows, 'live', 'swi6'))->toMatchArray([
                'dataUntil' => $now - 2 * 86400 + 300,
                'written' => $now - 2 * 86400 + 300,
                'imported' => 0,
                'pending' => 1,
                'state' => 'stale',
            ])
            ->and(healthRow($rows, 'live', 'old'))->toMatchArray([
                'newestFile' => null,
                'dataUntil' => null,
                'written' => null,
                'pending' => 0,
                'state' => 'nodata',
            ])
            ->and(healthRow($rows, 'live', 'ghost'))->toMatchArray(['newestFile' => null, 'pending' => 0, 'state' => 'missing'])
            ->and(healthRow($rows, 'backup', 'gateway'))->toMatchArray(['pending' => 0, 'state' => 'healthy', 'imported' => $now - 300])
            ->and(healthRow($rows, 'backup', 'swi6')['state'])->toBe('missing')
        ;
    });

    test('stale starts after 720 seconds without a new file', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['edge', 'late']);
        healthCapture($data, 'live', 'edge', $now - 900, $now - 720);
        healthCapture($data, 'live', 'late', $now - 900, $now - 721);
        ($this->importedUntil)([]);

        $rows = HealthMetrics::sources($now);

        expect(healthRow($rows, 'live', 'edge')['state'])->toBe('healthy')
            ->and(healthRow($rows, 'live', 'late')['state'])->toBe('stale')
        ;
    });

    test('a never imported source counts only the last 7 days as pending', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway']);
        foreach ([$now - 7 * 86400, $now - 7 * 86400 - 300, $now - 8 * 86400, $now - 400 * 86400, $now - 300] as $ts) {
            healthCapture($data, 'live', 'gateway', $ts);
        }
        ($this->importedUntil)([]);

        expect(healthRow(HealthMetrics::sources($now), 'live', 'gateway')['pending'])->toBe(2);
    });

    test('pending is capped at 10 000', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['busy']);
        // One file per minute over the whole window: 10 081 names.
        for ($ts = $now - 7 * 86400; $ts <= $now; $ts += 60) {
            healthCapture($data, 'live', 'busy', $ts, $now);
        }
        ($this->importedUntil)([]);

        expect(healthRow(HealthMetrics::sources($now), 'live', 'busy')['pending'])->toBe(HealthMetrics::PENDING_CAP);
    });

    test('a file older than 7 days is no data even when its day directory is scanned', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['aged', 'edge']);
        // 7 days and 1 hour old: newest() still reaches its day directory, the 7-day window does not.
        healthCapture($data, 'live', 'aged', $now - 7 * 86400 - 3600);
        healthCapture($data, 'live', 'edge', $now - 7 * 86400);
        ($this->importedUntil)([]);

        $rows = HealthMetrics::sources($now);

        expect(NfcapdFiles::newest('live', 'aged', HealthMetrics::WINDOW_DAYS, $now))->not->toBeNull()
            ->and(healthRow($rows, 'live', 'aged'))->toMatchArray([
                'newestFile' => null,
                'dataUntil' => null,
                'written' => null,
                'pending' => 0,
                'state' => 'nodata',
            ])
            ->and(healthRow($rows, 'live', 'edge'))->toMatchArray([
                'dataUntil' => $now - 7 * 86400 + 300,
                'pending' => 1,
                'state' => 'stale',
            ])
        ;
    });

    test('a source without a capture directory is not asked for its import time', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['ghost']);
        mkdir($data . '/live', 0o777, true);
        ($this->importedUntil)(['live/ghost' => $now - 300]);

        expect(healthRow(HealthMetrics::sources($now), 'live', 'ghost'))->toMatchArray(['imported' => 0, 'state' => 'missing']);
    });

    test('skips RRD sources without a file and logs nothing for them', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        $rrdConfig = ['data_path' => $this->root . '/rrd'];
        healthSettings($data, ['gateway', 'swi6', 'ghost'], 'RRD', $rrdConfig);
        healthCapture($data, 'live', 'gateway', $now - 300);
        healthCapture($data, 'live', 'swi6', $now - 300);
        $rrd = new Rrd();
        $rrd->create('gateway', 0, false, 'live');
        Config::$db = $rrd;
        $gatewayImported = $rrd->last_update('gateway', 0, 'live');

        healthSettings($data, ['gateway', 'swi6', 'ghost'], 'RRD', $rrdConfig, 4, LOG_INFO);
        $seq = Debug::recent(1)[0]['seq'] ?? 0;
        $rows = HealthMetrics::sources($now);

        expect($gatewayImported)->toBeGreaterThan(0)
            ->and(healthRow($rows, 'live', 'gateway')['imported'])->toBe($gatewayImported)
            ->and(healthRow($rows, 'live', 'swi6')['imported'])->toBe(0)
            ->and(healthRow($rows, 'live', 'ghost')['imported'])->toBe(0)
            ->and(array_column(Debug::recentSince($seq), 'message'))->toBe([])
        ;
    })->skip(!function_exists('rrd_version'), 'needs the rrd extension');

    test('a datasource that cannot answer counts the source as never imported', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway']);
        healthCapture($data, 'live', 'gateway', $now - 300);
        $db = $this->createStub(Datasource::class);
        $db->method('last_update')->willThrowException(new RuntimeException('rrd file missing'));
        Config::$db = $db;

        expect(healthRow(HealthMetrics::sources($now), 'live', 'gateway'))->toMatchArray(['imported' => 0, 'pending' => 1]);
    });
});

describe('HealthMetrics::disks', function (): void {
    test('merges capture, data and state on one filesystem into one entry', function (): void {
        foreach (['capture', 'rrd', 'state'] as $dir) {
            mkdir($this->root . '/' . $dir);
        }
        healthSettings($this->root . '/capture', ['gateway'], 'RRD', ['data_path' => $this->root . '/rrd']);
        Config::$stateDir = $this->root . '/state';

        $disks = HealthMetrics::disks();

        expect($disks)->toHaveCount(1)
            ->and($disks[0]['label'])->toBe('Capture, Data, State')
            ->and($disks[0]['path'])->toBe($this->root . '/capture')
            ->and($disks[0]['total'])->toBeInt()->toBeGreaterThan(0)
            ->and($disks[0]['free'])->toBeInt()->toBeLessThanOrEqual($disks[0]['total'])
            ->and($disks[0]['usedPct'])->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(100.0)
        ;
    });

    test('keeps separate filesystems apart, deduplicated by device', function (): void {
        mkdir($this->root . '/capture');
        healthSettings($this->root . '/capture', ['gateway'], 'RRD', ['data_path' => '/proc']);
        Config::$stateDir = $this->root;

        $disks = HealthMetrics::disks();
        $sameDevice = stat('/proc')['dev'] === stat($this->root)['dev'];

        expect(array_column($disks, 'label'))->toBe($sameDevice ? ['Capture, Data, State'] : ['Capture, State', 'Data'])
            ->and(array_column($disks, 'path'))->toBe($sameDevice ? [$this->root . '/capture'] : [$this->root . '/capture', '/proc'])
        ;
    })->skip(!is_dir('/proc'), 'needs a second filesystem at /proc');

    test('reports VictoriaMetrics data as remote and missing directories without figures', function (): void {
        mkdir($this->root . '/state');
        healthSettings($this->root . '/missing', ['gateway'], 'VictoriaMetrics');
        Config::$stateDir = $this->root . '/state';

        $disks = HealthMetrics::disks();

        expect($disks)->toHaveCount(3)
            ->and($disks[0])->toBe(['label' => 'Capture', 'path' => $this->root . '/missing', 'free' => null, 'total' => null, 'usedPct' => null])
            ->and($disks[1])->toBe(['label' => 'Data', 'path' => 'remote', 'free' => null, 'total' => null, 'usedPct' => null])
            ->and($disks[2]['label'])->toBe('State')
            ->and($disks[2]['total'])->toBeInt()
        ;
    });

    test('leaves the state directory out until it is known', function (): void {
        mkdir($this->root . '/capture');
        healthSettings($this->root . '/capture', ['gateway'], 'VictoriaMetrics');
        Config::$stateDir = '';

        expect(array_column(HealthMetrics::disks(), 'label'))->toBe(['Capture', 'Data']);
    });
});

describe('HealthMetrics::activeQueries', function (): void {
    $reset = static function (): void {
        while (NfdumpSlots::inUse() > 0) {
            NfdumpSlots::release();
        }
        foreach (NfdumpSlots::running() as $handle => $pids) {
            foreach ($pids as $pid) {
                NfdumpSlots::unregister($handle, $pid);
            }
        }
    };

    test('splits running nfdump processes into import and user queries', function () use ($reset): void {
        $reset();
        healthSettings($this->root, ['gateway'], 'RRD', [], 4);
        for ($i = 0; $i < 3; ++$i) {
            NfdumpSlots::acquire(0.0);
        }
        NfdumpSlots::register('default', 101);
        NfdumpSlots::register('topn', 102);
        NfdumpSlots::register('flows-3fa1', 103);
        NfdumpSlots::register('stats-77b0', 104);

        $active = HealthMetrics::activeQueries();
        $reset();

        expect($active)->toBe(['inUse' => 3, 'max' => 4, 'byOwner' => ['user' => 2, 'import' => 2]]);
    });

    test('is all zero when idle', function () use ($reset): void {
        $reset();
        healthSettings($this->root, ['gateway'], 'RRD', [], 2);

        expect(HealthMetrics::activeQueries())->toBe(['inUse' => 0, 'max' => 2, 'byOwner' => ['user' => 0, 'import' => 0]]);
    });
});

describe('HealthChecker', function (): void {
    /** @return array<string, array{id: string, label: string, status: string, detail: string, group: string, code: bool, hint: string, epoch: int}> */
    $checksById = static function (): array {
        $byId = [];
        foreach (HealthChecker::run(true) as $check) {
            $byId[$check['id']] = $check;
        }

        return $byId;
    };

    test('lists pdo_sqlite with the SQLite version under PHP Extensions', function () use ($checksById): void {
        healthSettings($this->root, ['gateway']);
        ($this->importedUntil)([]);

        $row = $checksById()['ext_pdo_sqlite'] ?? null;

        expect($row)->not->toBeNull()
            ->and($row['group'])->toBe('PHP Extensions')
            ->and($row['status'])->toBe('ok')
            ->and($row['detail'])->toMatch('/^Loaded \(SQLite 3\.\d+\.\d+\)$/')
        ;
    })->skip(!extension_loaded('pdo_sqlite'), 'needs pdo_sqlite');

    test('capture freshness ignores nfcapd.current.* files', function () use ($checksById): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway']);
        ($this->importedUntil)([]);
        $now = time();
        $rotated = healthCapture($data, 'live', 'gateway', $now - 3900, $now - 3600);
        touch(dirname($rotated) . '/nfcapd.current.4242', $now);

        $row = $checksById()['capture_fresh_gateway'];

        expect($row['status'])->toBe('warning')
            ->and($row['detail'])->toBe('Last file 1h ago. nfcapd may have stopped.')
            ->and($row['epoch'])->toBe($now - 3600)
        ;
    })->skip(
        fn (): bool => (new DateTimeImmutable('@' . (time() - 3900)))->setTimezone(Config::nfcapdTimezone())->format('Ymd')
            !== (new DateTimeImmutable('now', Config::nfcapdTimezone()))->format('Ymd'),
        'the rotated file would fall on yesterday'
    );

    test('check details and hints carry no em or en dashes', function () use ($checksById): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway', 'ghost']);
        ($this->importedUntil)([]);
        healthCapture($data, 'live', 'gateway', time() - 300);

        foreach ($checksById() as $id => $row) {
            expect($row['detail'] . ' ' . $row['hint'])->not->toMatch('/[\x{2013}\x{2014}]/u', $id);
        }
    });
});
