<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\query\TopNQuery;
use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;

const TOPNQ_T0 = 1_787_875_200; // 2026-08-28 00:00 UTC

/** @param array<string, int> $bytesByKey */
function topnqStore(TopNRepository $repo, string $source, int $ts, array $bytesByKey, int $intervalBytes = 1000, string $profile = 'live'): void {
    $rows = [];
    foreach ($bytesByKey as $key => $bytes) {
        $rows[] = ['key' => (string) $key, 'flows' => 1, 'packets' => 2, 'bytes' => $bytes];
    }
    $repo->storeInterval($profile, $source, $ts, ['flows' => 5, 'packets' => 10, 'bytes' => $intervalBytes], TopNRepository::STATUS_OK, 1, [TopNStat::SrcIp->value => $rows]);
}

function topnqQuery(int $start, int $end, int $limit = 10, array $sources = ['gw'], string $orderBy = 'bytes'): TopNQuery {
    return new TopNQuery(TimeWindow::raw($start, $end), $sources, 'live', TopNStat::SrcIp, $limit, $orderBy);
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'core'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => sys_get_temp_dir() . '/nfsen-ng-topnq-none', 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => LOG_ERR],
    ]);
    $this->db = Database::open(':memory:');
    $this->repo = new TopNRepository($this->db);
    TopNRepository::clearCache();
    TopNCollector::reset();

    // Two sources; gw has all 12 intervals of the hour, core 6 of them.
    for ($i = 0; $i < 12; ++$i) {
        topnqStore($this->repo, 'gw', TOPNQ_T0 + $i * 300, ['10.0.0.1' => 600, '10.0.0.2' => 300, '10.0.0.3' => 50]);
        if ($i % 2 === 0) {
            topnqStore($this->repo, 'core', TOPNQ_T0 + $i * 300, ['10.0.0.2' => 400]);
        }
    }
    $this->now = TOPNQ_T0 + 86400;
});

afterEach(function (): void {
    TopNRepository::clearCache();
    TopNCollector::reset();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('TopNQuery::run()', function (): void {
    test('ranks, shares and counts intervals over the rounded window', function (): void {
        // 00:02 to 01:03 reads the intervals of [00:00, 01:00).
        $result = topnqQuery(TOPNQ_T0 + 120, TOPNQ_T0 + 3600 + 180, 10, ['gw', 'core'])->run($this->repo, 31, $this->now);

        expect(array_column($result->rows, 'key'))->toBe(['10.0.0.1', '10.0.0.2', '10.0.0.3'])
            ->and($result->rows[0])->toBe(['key' => '10.0.0.1', 'source' => '', 'flows' => 12, 'packets' => 24, 'bytes' => 7200, 'share' => 40.0, 'intervals' => 12])
            ->and($result->rows[1]['bytes'])->toBe(12 * 300 + 6 * 400)
            ->and($result->rows[1]['intervals'])->toBe(18)
            ->and($result->totals)->toBe(['flows' => 90, 'packets' => 180, 'bytes' => 18000, 'intervals' => 18])
        ;
    });

    test('coverage is collected intervals over expected intervals times sources', function (): void {
        $both = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600, 10, ['gw', 'core'])->run($this->repo, 31, $this->now);
        $gw = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now);
        $twoHours = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 7200)->run($this->repo, 31, $this->now);
        $tiny = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 120)->run($this->repo, 31, $this->now);

        expect($both->coverage)->toBe(18 / 24)
            ->and($gw->coverage)->toBe(1.0)
            ->and($twoHours->coverage)->toBe(0.5)
            ->and($tiny->coverage)->toBe(0.0)
        ;
    });

    test('outOfRetention compares the window start with now minus the retention', function (): void {
        $query = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600);

        expect($query->run($this->repo, 31, TOPNQ_T0 + 31 * 86400)->outOfRetention)->toBeFalse()
            ->and($query->run($this->repo, 31, TOPNQ_T0 + 31 * 86400 + 1)->outOfRetention)->toBeTrue()
            ->and($query->run($this->repo, 0, $this->now)->outOfRetention)->toBeTrue()
            ->and($query->run($this->repo, 31, $this->now)->retentionDays)->toBe(31)
        ;
    });

    test('exactIntervals only while the window is answered from 5 minute rows (6 hours)', function (): void {
        expect(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 6 * 3600)->run($this->repo, 31, $this->now)->exactIntervals)->toBeTrue()
            ->and(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 6 * 3600 + 300)->run($this->repo, 31, $this->now)->exactIntervals)->toBeFalse()
        ;
    });

    test('share is 0 when nothing was collected', function (): void {
        $result = topnqQuery(TOPNQ_T0 + 86400, TOPNQ_T0 + 86400 + 3600)->run($this->repo, 31, $this->now);

        expect($result->rows)->toBe([])
            ->and($result->totals['bytes'])->toBe(0)
        ;
    });

    test('orders by packets or flows when asked', function (): void {
        topnqStore($this->repo, 'gw', TOPNQ_T0 + 3 * 3600, ['10.0.0.9' => 5]);
        $this->db->exec("UPDATE topn_5m SET packets = 1000 WHERE key = '10.0.0.9'");

        $byPackets = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 4 * 3600, 10, ['gw'], 'packets')->run($this->repo, 31, $this->now);

        expect($byPackets->rows[0]['key'])->toBe('10.0.0.9');
    });

    test('limits share one cached top 50', function (): void {
        $one = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600, 1)->run($this->repo, 31, $this->now);
        $this->db->exec('DELETE FROM topn_5m');

        $ten = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600, 10)->run($this->repo, 31, $this->now + 10);
        $otherWindow = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 1800, 10)->run($this->repo, 31, $this->now + 10);

        expect(array_column($one->rows, 'key'))->toBe(['10.0.0.1'])
            ->and(array_column($ten->rows, 'key'))->toBe(['10.0.0.1', '10.0.0.2', '10.0.0.3'])
            ->and($otherWindow->rows)->toBe([])
        ;
    });

    test('the cache key ignores the source order and seconds inside the 5 minute slot', function (): void {
        topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600, 10, ['gw', 'core'])->run($this->repo, 31, $this->now);
        $this->db->exec('DELETE FROM topn_5m');

        expect(topnqQuery(TOPNQ_T0 + 299, TOPNQ_T0 + 3899, 10, ['core', 'gw', 'core'])->run($this->repo, 31, $this->now)->rows)->toHaveCount(3);
    });

    test('a cached result expires after 300 s', function (): void {
        topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now);
        $this->db->exec('DELETE FROM topn_5m');

        expect(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now + 299)->rows)->toHaveCount(3)
            ->and(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now + 300)->rows)->toBe([])
        ;
    });

    test('a new collector generation of the profile invalidates the cache', function (): void {
        topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now);
        $this->db->exec("DELETE FROM topn_5m WHERE key = '10.0.0.1'");
        $cached = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now);

        // The worker stores an empty file for another profile, then for this one; each drain bumps.
        TopNCollector::start($this->repo, 31, time(), ['live', 'other']);
        TopNCollector::process(['profile' => 'other', 'source' => 'gw', 'relPath' => '2026/08/28/nfcapd.202608280100', 'ts' => time() - 600, 'totals' => ['flows' => 0, 'packets' => 0, 'bytes' => 0]]);
        $otherProfileBumped = topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now);
        TopNCollector::process(['profile' => 'live', 'source' => 'gw', 'relPath' => '2026/08/28/nfcapd.202608280100', 'ts' => time() - 600, 'totals' => ['flows' => 0, 'packets' => 0, 'bytes' => 0]]);

        expect(TopNCollector::generation('live'))->toBe(1)
            ->and(array_column($cached->rows, 'key'))->toBe(['10.0.0.1', '10.0.0.2', '10.0.0.3'])
            ->and(array_column($otherProfileBumped->rows, 'key'))->toBe(['10.0.0.1', '10.0.0.2', '10.0.0.3'])
            ->and(array_column(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 3600)->run($this->repo, 31, $this->now)->rows, 'key'))->toBe(['10.0.0.2', '10.0.0.3'])
        ;
    });

    test('passes $yield to the range query', function (): void {
        $calls = 0;
        topnqQuery(TOPNQ_T0 - 86400 + 1500, TOPNQ_T0 + 86400 + 7200)->run($this->repo, 31, $this->now, static function () use (&$calls): void {
            ++$calls;
        });

        expect($calls)->toBeGreaterThan(0);
    });
});

describe('TopNQuery::__construct()', function (): void {
    test('rejects an unknown order and a limit outside 1 to 50', function (): void {
        expect(fn () => topnqQuery(TOPNQ_T0, TOPNQ_T0 + 300, 10, ['gw'], 'bits'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => topnqQuery(TOPNQ_T0, TOPNQ_T0 + 300, 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => topnqQuery(TOPNQ_T0, TOPNQ_T0 + 300, 51))->toThrow(InvalidArgumentException::class)
            ->and(topnqQuery(TOPNQ_T0, TOPNQ_T0 + 300, 50)->limit)->toBe(50)
        ;
    });
});

describe('TopNRepository result cache', function (): void {
    test('keeps the 64 most recently used entries', function (): void {
        for ($i = 0; $i <= TopNRepository::CACHE_SIZE; ++$i) {
            TopNRepository::remember("k{$i}", 0, 100, [], ['flows' => 0, 'packets' => 0, 'bytes' => 0, 'intervals' => $i]);
            if ($i === 10) {
                // Touching k0 makes k1 the least recently used.
                expect(TopNRepository::cached('k0', 0, 100))->not->toBeNull();
            }
        }

        expect(TopNRepository::cached('k0', 0, 100))->not->toBeNull()
            ->and(TopNRepository::cached('k1', 0, 100))->toBeNull()
            ->and(TopNRepository::cached('k2', 0, 100))->not->toBeNull()
            ->and(TopNRepository::cached('k2', 1, 100))->toBeNull()
        ;
    });
});
