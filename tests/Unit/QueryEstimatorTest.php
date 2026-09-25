<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\query\CostEstimate;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;

/** 2024-01-01 00:00 UTC, a multiple of 300. */
const ESTIMATOR_BASE = 1_704_067_200;

/** Writes one capture file per timestamp under <root>/live/<source>/YYYY/MM/DD in the nfcapd timezone. */
function estimatorCaptureFiles(string $root, string $source, array $timestamps, int $bytes): void {
    foreach ($timestamps as $ts) {
        $dt = new DateTimeImmutable('@' . $ts)->setTimezone(Config::nfcapdTimezone());
        $dir = $root . '/live/' . $source . '/' . $dt->format('Y/m/d');
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir . '/nfcapd.' . $dt->format('YmdHi'), str_repeat('x', $bytes));
    }
}

function estimatorRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    $this->root = sys_get_temp_dir() . '/nfsen-estimator-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0o777, true);
    Config::$settings = Settings::fromArray([
        'general' => ['ports' => [80], 'sources' => ['gw', 'edge'], 'db' => 'Rrd', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 4],
        'log' => ['priority' => LOG_ERR],
    ]);
    // No store unless a test installs one: '' reads as "not configured" in Database::shared().
    Config::$stateDir = '';
    Database::resetShared();
    QueryEstimator::resetCache();
    QueryEstimator::useClock(null);
});

afterEach(function (): void {
    QueryEstimator::resetCache();
    QueryEstimator::useClock(null);
    Database::resetShared();
    Config::$stateDir = $this->stateDirBefore ?? '';
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    estimatorRemoveTree($this->root);
});

describe('QueryEstimator defaults', function (): void {
    test('has a default throughput for every query kind', function (): void {
        expect(array_keys(QueryEstimator::DEFAULT_THROUGHPUT))->toEqualCanonicalizing([
            'graph', 'overview-topn', 'stats', 'talkers-panel', 'flows', 'flows-summary', 'flowsgraph', 'conversations',
        ])
            ->and(QueryEstimator::DEFAULT_THROUGHPUT['flows'])->toBe(380_000_000)
            ->and(QueryEstimator::DEFAULT_THROUGHPUT['conversations'])->toBe(230_000_000)
            ->and(QueryEstimator::DEFAULT_THROUGHPUT['stats'])->toBe(350_000_000)
        ;
    });

    test('uses the default without a store', function (): void {
        expect(QueryEstimator::throughput('conversations'))->toBe(['bytesPerSecond' => 230_000_000.0, 'measured' => false]);
    });

    test('uses the default until three qualifying runs are recorded, then their median', function (): void {
        Database::useShared(Database::open(':memory:'));
        $runs = new QueryRunRepository(Database::shared());
        $runs->record('flows', 100_000_000, 0, 1000, true, 1);
        $runs->record('flows', 100_000_000, 0, 500, true, 2);

        expect(QueryEstimator::throughput('flows'))->toBe(['bytesPerSecond' => 380_000_000.0, 'measured' => false]);

        $runs->record('flows', 100_000_000, 0, 250, true, 3);

        expect(QueryEstimator::throughput('flows'))->toBe(['bytesPerSecond' => 200_000_000.0, 'measured' => true])
            ->and(QueryEstimator::throughput('stats')['measured'])->toBeFalse()
        ;
    });

    test('falls back to the default when the store fails', function (): void {
        $db = Database::open(':memory:');
        $db->exec('DROP TABLE query_runs');
        Database::useShared($db);
        ob_start();

        try {
            expect(QueryEstimator::throughput('stats'))->toBe(['bytesPerSecond' => 350_000_000.0, 'measured' => false]);
        } finally {
            ob_end_clean();
        }
    });

    test('rejects an unknown kind', function (): void {
        QueryEstimator::singlePass('sankey', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 600), ['gw'], 'live');
    })->throws(InvalidArgumentException::class);
});

describe('QueryEstimator::singlePass()', function (): void {
    test('counts the files and bytes in the window and divides by the throughput', function (): void {
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE, ESTIMATOR_BASE + 300, ESTIMATOR_BASE + 600], 1000);
        estimatorCaptureFiles($this->root, 'edge', [ESTIMATOR_BASE + 300], 500);

        $estimate = QueryEstimator::singlePass('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw', 'edge'], 'live');

        expect($estimate->files)->toBe(4)
            ->and($estimate->bytes)->toBe(3500)
            ->and($estimate->runs)->toBe(1)
            ->and($estimate->seconds)->toBe(1)
            ->and($estimate->measured)->toBeFalse()
            ->and($estimate->clamped)->toBeFalse()
            ->and($estimate->window)->toBe('1 hour')
        ;
    });

    test('rounds the seconds up', function (): void {
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE], 1000);
        Database::useShared(Database::open(':memory:'));
        $runs = new QueryRunRepository(Database::shared());
        // 40 MiB in 100 s: about 419 kB/s, so 1000 bytes still take one second.
        foreach ([1, 2, 3] as $ts) {
            $runs->record('stats', 41_943_040, 0, 100_000, true, $ts);
        }

        $estimate = QueryEstimator::singlePass('stats', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 300), ['gw'], 'live');

        expect($estimate->seconds)->toBe(1)
            ->and($estimate->measured)->toBeTrue()
        ;
    });

    test('has no seconds when there is nothing to read', function (): void {
        $estimate = QueryEstimator::singlePass('stats', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw'], 'live');

        expect($estimate->files)->toBe(0)
            ->and($estimate->seconds)->toBeNull()
            ->and($estimate->toArray()['secondsHuman'])->toBe('')
        ;
    });

    test('reports a clamped window', function (): void {
        $estimate = QueryEstimator::singlePass('stats', TimeWindow::clamped(ESTIMATOR_BASE, ESTIMATOR_BASE + 7 * 86400, 86400), ['gw'], 'live');

        expect($estimate->clamped)->toBeTrue()
            ->and($estimate->window)->toBe('1 day')
        ;
    });
});

describe('QueryEstimator::filteredSeries()', function (): void {
    test('reads the files once and adds the start-up cost of every run', function (): void {
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE, ESTIMATOR_BASE + 300], 1000);
        $window = TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 86400);
        $runs = CostEstimate::runsForFilteredSeries($window, 200, 2);

        $estimate = QueryEstimator::filteredSeries('graph', $window, ['gw'], 'live', 200, 2);

        expect($estimate->runs)->toBe($runs)
            ->and($runs)->toBeGreaterThan(20)
            ->and($estimate->files)->toBe(2)
            ->and($estimate->bytes)->toBe(2000)
            ->and($estimate->seconds)->toBe((int) ceil(2000 / 350_000_000 + $runs * QueryEstimator::SECONDS_PER_RUN))
        ;
    });

    test('has no seconds when there is nothing to read', function (): void {
        $estimate = QueryEstimator::filteredSeries('flowsgraph', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw'], 'live', 100, 1);

        expect($estimate->seconds)->toBeNull()
            ->and($estimate->runs)->toBeGreaterThan(0)
        ;
    });
});

describe('QueryEstimator cache', function (): void {
    test('keys on the window rounded down to 300 s and on the sorted sources', function (): void {
        $key = QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw', 'edge'], 'live');

        expect(QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE + 299, ESTIMATOR_BASE + 3899), ['edge', 'gw'], 'live'))->toBe($key)
            ->and(QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE + 300, ESTIMATOR_BASE + 3600), ['gw', 'edge'], 'live'))->not->toBe($key)
            ->and(QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3900), ['gw', 'edge'], 'live'))->not->toBe($key)
            ->and(QueryEstimator::cacheKey('stats', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw', 'edge'], 'live'))->not->toBe($key)
            ->and(QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw', 'edge'], 'other'))->not->toBe($key)
            ->and(QueryEstimator::cacheKey('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw'], 'live'))->not->toBe($key)
        ;
    });

    test('reuses the walk within the same 300 s bucket', function (): void {
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE], 1000);
        $first = QueryEstimator::singlePass('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600), ['gw'], 'live');
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE + 300], 1000);

        $sameBucket = QueryEstimator::singlePass('flows', TimeWindow::raw(ESTIMATOR_BASE + 120, ESTIMATOR_BASE + 3720), ['gw'], 'live');
        $nextBucket = QueryEstimator::singlePass('flows', TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3900), ['gw'], 'live');

        expect($first->files)->toBe(1)
            ->and($sameBucket->files)->toBe(1)
            ->and($nextBucket->files)->toBe(2)
        ;
    });

    test('walks again after 300 s', function (): void {
        $now = 10_000;
        QueryEstimator::useClock(static function () use (&$now): int {
            return $now;
        });
        $window = TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600);
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE], 1000);
        QueryEstimator::singlePass('flows', $window, ['gw'], 'live');
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE + 300], 1000);

        $now += QueryEstimator::CACHE_TTL_SECONDS - 1;
        $cached = QueryEstimator::singlePass('flows', $window, ['gw'], 'live');
        ++$now;
        $fresh = QueryEstimator::singlePass('flows', $window, ['gw'], 'live');

        expect($cached->files)->toBe(1)
            ->and($fresh->files)->toBe(2)
        ;
    });

    test('evicts the least recently used entry beyond 32', function (): void {
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE], 1000);
        $window = static fn (int $i): TimeWindow => TimeWindow::raw(ESTIMATOR_BASE, ESTIMATOR_BASE + 3600 + $i * 300);
        for ($i = 0; $i < QueryEstimator::CACHE_SIZE; ++$i) {
            QueryEstimator::singlePass('flows', $window($i), ['gw'], 'live');
        }
        // Touch the oldest so the second oldest is evicted by the next insert.
        QueryEstimator::singlePass('flows', $window(0), ['gw'], 'live');
        QueryEstimator::singlePass('flows', $window(QueryEstimator::CACHE_SIZE), ['gw'], 'live');
        estimatorCaptureFiles($this->root, 'gw', [ESTIMATOR_BASE + 300], 1000);

        expect(QueryEstimator::singlePass('flows', $window(0), ['gw'], 'live')->files)->toBe(1)
            ->and(QueryEstimator::singlePass('flows', $window(1), ['gw'], 'live')->files)->toBe(2)
        ;
    });
});

describe('Estimate', function (): void {
    test('is heavy above 16 GiB', function (): void {
        $estimate = static fn (int $bytes): Estimate => new Estimate(1, $bytes, 1, 1, false, false, '1 day');

        expect(Estimate::HEAVY_BYTES)->toBe(16 * 1024 ** 3)
            ->and($estimate(Estimate::HEAVY_BYTES)->toArray()['heavy'])->toBeFalse()
            ->and($estimate(Estimate::HEAVY_BYTES + 1)->toArray()['heavy'])->toBeTrue()
        ;
    });

    test('toArray has the shape the query-estimate component seeds', function (): void {
        $array = new Estimate(41, 3_435_973_837, 1, 34, true, false, '1 day')->toArray();

        expect(array_keys($array))->toBe(['files', 'bytes', 'bytesHuman', 'runs', 'seconds', 'secondsHuman', 'measured', 'clamped', 'window', 'heavy'])
            ->and($array)->toBe([
                'files' => 41,
                'bytes' => 3_435_973_837,
                'bytesHuman' => '3.2 GiB',
                'runs' => 1,
                'seconds' => 34,
                'secondsHuman' => '34 s',
                'measured' => true,
                'clamped' => false,
                'window' => '1 day',
                'heavy' => false,
            ])
        ;
    });

    test('words durations in seconds, minutes, then hours', function (): void {
        expect(Estimate::humanSeconds(null))->toBe('')
            ->and(Estimate::humanSeconds(1))->toBe('1 s')
            ->and(Estimate::humanSeconds(119))->toBe('119 s')
            ->and(Estimate::humanSeconds(120))->toBe('2 min')
            ->and(Estimate::humanSeconds(750))->toBe('13 min')
            ->and(Estimate::humanSeconds(3600))->toBe('1 h')
            ->and(Estimate::humanSeconds(4800))->toBe('1 h 20 min')
        ;
    });

    test('words sizes in binary units', function (): void {
        expect(Estimate::humanBytes(0))->toBe('0 B')
            ->and(Estimate::humanBytes(1536))->toBe('1.5 KiB')
            ->and(Estimate::humanBytes(3_435_973_837))->toBe('3.2 GiB')
        ;
    });
});
