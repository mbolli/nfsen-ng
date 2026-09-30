<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\CpuBudget;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\HealthMetrics;
use mbolli\nfsen_ng\common\LoopLag;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

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
    removeTree($this->root);
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

    // A name stamped inside the window but filed 30 days back counts only if the scan reads that directory.
    test('a never imported source scans only the 8 day directories of the window', function () use ($now): void {
        $data = $this->root . '/profiles-data';
        healthSettings($data, ['gateway']);
        healthCapture($data, 'live', 'gateway', $now - 300);
        $inWindow = (new DateTimeImmutable('@' . ($now - 600)))->setTimezone(Config::nfcapdTimezone())->format('YmdHi');
        healthCapture($data, 'live', 'gateway', $now - 30 * 86400, null, 'nfcapd.' . $inWindow);
        ($this->importedUntil)([]);

        expect(healthRow(HealthMetrics::sources($now), 'live', 'gateway')['pending'])->toBe(1);
    });

    test('the window is 7 calendar days in the nfcapd timezone, across a DST change too', function (string $local, string $start): void {
        $tz = new DateTimeZone('Europe/Zurich');
        $from = HealthMetrics::windowStart((new DateTimeImmutable($local, $tz))->getTimestamp(), $tz);

        expect((new DateTimeImmutable('@' . $from))->setTimezone($tz)->format('Y-m-d H:i'))->toBe($start);
    })->with([
        'spring forward on 31 March' => ['2024-04-01 00:30', '2024-03-25 00:30'],
        'fall back on 27 October' => ['2024-11-01 00:30', '2024-10-25 00:30'],
        'no change' => ['2024-06-10 12:00', '2024-06-03 12:00'],
    ]);

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
        foreach (NfdumpSlots::CLASSES as $class) {
            NfdumpSlots::release($class, NfdumpSlots::inUse($class));
        }
        foreach (NfdumpSlots::running() as $handle => $pids) {
            foreach ($pids as $pid) {
                NfdumpSlots::unregister($handle, $pid);
            }
        }
    };

    test('counts the slots in use by class', function () use ($reset): void {
        $reset();
        healthSettings($this->root, ['gateway'], 'RRD', [], 4);
        NfdumpSlots::acquireMany(2, NfdumpSlots::INTERACTIVE, 0.0);
        NfdumpSlots::acquire(0.0, NfdumpSlots::BACKGROUND);

        $active = HealthMetrics::activeQueries();
        $reset();

        expect($active)->toBe([
            'inUse' => 3,
            'max' => 4,
            'backgroundMax' => 2,
            'byClass' => ['interactive' => 2, 'background' => 1],
            'waiting' => ['interactive' => 0, 'background' => 0],
        ])
            ->and(HealthMetrics::slotSplit($active))->toBe('2 interactive, 1 background')
        ;
    });

    test('is all zero when idle', function () use ($reset): void {
        $reset();
        healthSettings($this->root, ['gateway'], 'RRD', [], 2);

        expect(HealthMetrics::activeQueries())->toBe([
            'inUse' => 0,
            'max' => 2,
            'backgroundMax' => 1,
            'byClass' => ['interactive' => 0, 'background' => 0],
            'waiting' => ['interactive' => 0, 'background' => 0],
        ]);
    });

    test('names the callers waiting for a slot', function (): void {
        $active = ['inUse' => 2, 'max' => 2, 'backgroundMax' => 1, 'byClass' => ['interactive' => 1, 'background' => 1], 'waiting' => ['interactive' => 1, 'background' => 2]];

        expect(HealthMetrics::slotSplit($active))->toBe('1 interactive, 1 background, 3 waiting');
    });
});

describe('HealthMetrics::processBudget', function (): void {
    test('an auto limit comes from the detected cores, with -W unknown for a missing binary', function (): void {
        healthSettings($this->root, ['gateway'], 'RRD', [], 0);
        $cpu = CpuBudget::detect();

        expect(HealthMetrics::processBudget())->toBe([
            'cores' => $cpu['cores'],
            'coresSource' => $cpu['source'],
            'coresOrigin' => $cpu['origin'],
            'coresFrom' => CpuBudget::sourceLabel($cpu['source']),
            'coresDetail' => $cpu['detail'],
            'processes' => CpuBudget::autoProcesses($cpu['cores']),
            'auto' => true,
            'workers' => 2,
            'workersPassed' => 0,
            'version' => '',
        ]);
    });

    test('an explicit limit is not auto', function (): void {
        healthSettings($this->root, ['gateway'], 'RRD', [], 3);

        expect(HealthMetrics::processBudget())->toMatchArray(['processes' => 3, 'auto' => false]);
    });
});

describe('HealthMetrics::loopLag', function (): void {
    $now = 1_790_000_000.0;

    beforeEach(fn () => LoopLag::reset());
    afterEach(fn () => LoopLag::reset());

    test('says nothing before the first tick', function () use ($now): void {
        expect(HealthMetrics::loopLag($now))->toBe(['samples' => 0, 'window' => 60, 'p50' => '', 'p95' => '', 'max' => '', 'level' => '']);
    });

    test('shows p50, p95 and max of the last minute', function () use ($now): void {
        foreach (range(1, 19) as $i) {
            LoopLag::record(0.44, $now - 1);
        }
        LoopLag::record(85.4, $now);
        LoopLag::record(40.0, $now - 61);

        expect(HealthMetrics::loopLag($now))->toBe(['samples' => 20, 'window' => 60, 'p50' => '0.4 ms', 'p95' => '0.4 ms', 'max' => '85 ms', 'level' => '']);
    });

    // 100 ms is where moving imports out of the request worker (PERF-SPEC P6b) starts to pay.
    test('warns from a p95 that reads 100 ms and turns to an error from one that reads 1.0 s', function (float $p95, string $text, string $level) use ($now): void {
        foreach (range(1, 20) as $i) {
            LoopLag::record($p95, $now);
        }

        expect(HealthMetrics::loopLag($now))->toMatchArray(['p95' => $text, 'level' => $level]);
    })->with([
        'below' => [99.4, '99 ms', ''],
        'rounds up to the warning' => [99.6, '100 ms', 'warning'],
        'at the warning' => [100.0, '100 ms', 'warning'],
        'just below an error' => [999.4, '999 ms', 'warning'],
        'rounds up to the error' => [999.6, '1.0 s', 'error'],
        'at the error' => [1000.0, '1.0 s', 'error'],
    ]);

    // The row on Health (health.html.twig's lag macro): the glyph and its screen-reader word follow the level.
    test('the Event loop lag row shows a warning glyph from 100 ms and an error glyph from 1.0 s', function (float $p95, string $glyph, string $word) use ($now): void {
        foreach (range(1, 20) as $i) {
            LoopLag::record($p95, $now);
        }
        $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $html = (new Context('ctx-lag', '/', $app))
            ->renderString("{% import 'pages/health.html.twig' as health %}{{ health.lag(lag) }}", ['lag' => HealthMetrics::loopLag($now)])
        ;
        $row = HTMLDocument::createFromString('<dl>' . $html . '</dl>', LIBXML_NOERROR)
            ->querySelector('dd.health-lag')
        ;
        $dot = $row?->querySelector('dd.health-lag > .status-dot');

        expect($row)->not->toBeNull()
            ->and($dot?->getAttribute('data-level'))->toBe($glyph === '' ? null : $glyph)
            ->and($dot?->querySelector('.visually-hidden')?->textContent)->toBe($word === '' ? null : $word)
            ->and(preg_replace('/\s+/', ' ', trim((string) $row?->textContent)))->toContain('p95 ' . HealthMetrics::lagText($p95))
        ;
    })->with([
        'no glyph below the warning' => [99.4, '', ''],
        'a warning' => [150.0, 'warning', 'Slow:'],
        'an error' => [1500.0, 'error', 'Stalled:'],
    ]);

    test('writes a lag in ms below a second and in s above', function (?float $ms, string $text): void {
        expect(HealthMetrics::lagText($ms))->toBe($text);
    })->with([
        'none' => [null, ''],
        'zero' => [0.0, '0.0 ms'],
        'sub-ms' => [0.44, '0.4 ms'],
        'rounds up to 10' => [9.96, '10 ms'],
        'tens' => [85.4, '85 ms'],
        'rounds up to a second' => [999.6, '1.0 s'],
        'seconds' => [2345.0, '2.3 s'],
        'minutes' => [125_000.0, '125.0 s'],
    ]);
});

describe('HealthChecker nfdump group', function (): void {
    test('an nfdump below 1.7.2 is an error', function (): void {
        expect(HealthChecker::nfdumpVersionCheck('1.6.23'))->toMatchArray(['status' => 'error', 'detail' => 'v1.6.23. nfsen-ng requires nfdump 1.7.2 or later.']);
    });

    // 1.7.9 brings the security fixes, 1.7.10 the -B pairing fix for gcc builds of 1.7.8 and 1.7.9;
    // nothing in nfsen-ng breaks below them.
    test('an nfdump below 1.7.10 is a warning that names the fixes it lacks', function (string $version, bool $security, bool $pairing): void {
        $check = HealthChecker::nfdumpVersionCheck($version);

        expect($check['status'])->toBe('warning')
            ->and($check['detail'])->toBe("v{$version}. nfdump 1.7.10 or later is recommended.")
            ->and(str_contains($check['hint'], 'security'))->toBe($security)
            ->and(str_contains($check['hint'], 'Bi-directional'))->toBe($pairing)
            ->and($check['hint'])->toEndWith('Upgrade to nfdump 1.7.10.')
        ;
    })->with([
        '1.7.2' => ['1.7.2', true, false],
        '1.7.7' => ['1.7.7', true, false],
        '1.7.8' => ['1.7.8', true, true],
        '1.7.9' => ['1.7.9', false, true],
    ]);

    test('1.7.10 and later pass', function (string $version): void {
        expect(HealthChecker::nfdumpVersionCheck($version))->toBe(['status' => 'ok', 'detail' => '1.7.10 or later', 'hint' => '']);
    })->with(['1.7.10', '1.8.0']);

    $budget = static fn (array $over = []): array => [
        'cores' => 20, 'coresSource' => 'affinity', 'coresOrigin' => 'nproc', 'coresFrom' => 'the CPU affinity', 'coresDetail' => 'Cpus_allowed_list 0-19',
        'processes' => 6, 'auto' => true, 'workers' => 2, 'workersPassed' => 2, 'version' => '1.7.10',
        ...$over,
    ];
    $idle = ['inUse' => 1, 'max' => 6, 'backgroundMax' => 3, 'byClass' => ['interactive' => 0, 'background' => 1], 'waiting' => ['interactive' => 0, 'background' => 0]];

    test('shows the cores and their source, the process limit, -W and the slots by class', function () use ($budget, $idle): void {
        $rows = array_column(HealthChecker::processBudgetChecks($budget(), $idle), null, 'id');

        expect(array_keys($rows))->toBe(['nfdump_cpu_cores', 'nfdump_max_processes', 'nfdump_workers', 'nfdump_slots'])
            ->and(array_unique(array_column($rows, 'group')))->toBe(['nfdump'])
            ->and(array_unique(array_column($rows, 'status')))->toBe(['ok'])
            ->and($rows['nfdump_cpu_cores']['detail'])->toBe('20 cores, from the CPU affinity (Cpus_allowed_list 0-19)')
            ->and($rows['nfdump_max_processes']['detail'])->toBe('6, auto: a third of 20 cores, between 2 and 8')
            ->and($rows['nfdump_max_processes']['hint'])->toBe('Each nfdump process uses about 2 to 3 CPU cores.')
            ->and($rows['nfdump_workers']['detail'])->toBe('-W 2 on every nfdump run')
            ->and($rows['nfdump_slots']['detail'])->toBe('1 of 6: 0 interactive, 1 background')
            ->and($rows['nfdump_slots']['hint'])->toContain('at most 3')
        ;
    });

    test('says why -W is not passed', function () use ($budget, $idle): void {
        $workers = static fn (array $over): string => array_column(HealthChecker::processBudgetChecks($budget($over), $idle), 'detail', 'id')['nfdump_workers'];

        expect($workers(['workersPassed' => 0, 'workers' => 0]))->toBe("Not passed: nfdump's own default (NFSEN_NFDUMP_WORKERS=0)")
            ->and($workers(['workersPassed' => 0, 'version' => '1.7.2']))->toBe('Not passed: nfdump 1.7.2 has no -W (added in 1.7.3)')
            ->and($workers(['workersPassed' => 0, 'version' => '']))->toBe('Not passed: the nfdump version is unknown')
        ;
    });

    test('an explicit limit and a cgroup limit read as such', function () use ($budget, $idle): void {
        $rows = array_column(HealthChecker::processBudgetChecks($budget([
            'auto' => false, 'processes' => 4, 'cores' => 4, 'coresSource' => 'cgroup', 'coresOrigin' => 'cpu.max',
            'coresFrom' => CpuBudget::sourceLabel('cgroup'), 'coresDetail' => 'cgroup cpu.max 400000 100000',
        ]), $idle), 'detail', 'id');

        expect($rows['nfdump_cpu_cores'])->toBe("4 cores, from the container's CPU limit (cgroup cpu.max 400000 100000)")
            ->and($rows['nfdump_max_processes'])->toBe('4, set by NFSEN_NFDUMP_MAX_PROCESSES or settings.php')
        ;
    });

    // The checks are cached for up to five minutes; the slot count is not.
    test('withLiveSlots() recounts only the slots row', function () use ($budget, $idle): void {
        $cached = [
            ['id' => 'nfdump_version', 'label' => 'Minimum version', 'status' => 'ok', 'detail' => '1.7.10 or later', 'group' => 'nfdump', 'code' => false, 'hint' => '', 'epoch' => 0],
            ...HealthChecker::processBudgetChecks($budget(), $idle),
        ];
        $busy = [...$idle, 'inUse' => 4, 'byClass' => ['interactive' => 2, 'background' => 2]];
        $live = HealthChecker::withLiveSlots($cached, $busy);

        expect(array_column($live, 'id'))->toBe(array_column($cached, 'id'))
            ->and(array_column($live, 'detail', 'id')['nfdump_slots'])->toBe('4 of 6: 2 interactive, 2 background')
            ->and(array_filter($live, static fn (array $c): bool => $c['id'] !== 'nfdump_slots'))
            ->toBe(array_filter($cached, static fn (array $c): bool => $c['id'] !== 'nfdump_slots'))
        ;
    });

    test('run() puts the budget rows in the nfdump group', function (): void {
        healthSettings($this->root, ['gateway']);
        ($this->importedUntil)([]);

        $ids = array_column(array_filter(HealthChecker::run(true), static fn (array $c): bool => $c['group'] === 'nfdump'), 'id');

        expect($ids)->toContain('nfdump_cpu_cores')
            ->and($ids)->toContain('nfdump_max_processes')
            ->and($ids)->toContain('nfdump_workers')
            ->and($ids)->toContain('nfdump_slots')
        ;
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
