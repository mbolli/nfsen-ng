<?php

declare(strict_types=1);

use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;

const TOPN_TOTALS = ['flows' => 10, 'packets' => 100, 'bytes' => 10_000];

/** A midnight UTC with a few whole days of room on both sides. */
const TOPN_DAY0 = 1_787_875_200; // 2026-08-28 00:00 UTC

/**
 * @param array<string, int> $bytesByKey
 *
 * @return list<array{key: string, flows: int, packets: int, bytes: int}>
 */
function topnRows(array $bytesByKey): array {
    $rows = [];
    foreach ($bytesByKey as $key => $bytes) {
        $rows[] = ['key' => (string) $key, 'flows' => max(1, intdiv($bytes, 1000)), 'packets' => max(1, intdiv($bytes, 100)), 'bytes' => $bytes];
    }

    return $rows;
}

/**
 * Random rows from a small key pool, so keys repeat across intervals and some stay out.
 *
 * @return list<array{key: string, flows: int, packets: int, bytes: int}>
 */
function topnRandomRows(int $count, int $pool, string $prefix = '10.0.0.'): array {
    $keys = [];
    while (count($keys) < $count) {
        $keys[$prefix . random_int(1, $pool)] = random_int(1, 50) * 100;
    }

    return topnRows($keys);
}

/**
 * Each rollup row next to the sum of its 5 minute rows, grouped the same way.
 *
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function topnRollupVersusSum(Database $db, string $tier, int $size): array {
    $sum = $db->all("SELECT profile, stat, ts - ts % {$size} AS ts, source, key, SUM(flows) AS flows, SUM(packets) AS packets, SUM(bytes) AS bytes
        FROM topn_5m GROUP BY 1, 2, 3, 4, 5 ORDER BY 1, 2, 3, 4, 5");
    $rollup = $db->all("SELECT profile, stat, ts, source, key, flows, packets, bytes FROM {$tier} ORDER BY 1, 2, 3, 4, 5");

    return [$rollup, $sum];
}

/**
 * The top 50 by one plain GROUP BY over the 5 minute rows: what every tier mix must equal.
 *
 * @param list<string> $sources
 *
 * @return list<array{key: string, source: string, flows: int, packets: int, bytes: int, intervals: int}>
 */
function topnReference(Database $db, string $profile, TopNStat $stat, array $sources, int $start, int $end, string $orderBy = 'bytes'): array {
    $in = implode(', ', array_fill(0, count($sources), '?'));
    $group = $stat->perSource() ? 'source, key' : 'key';
    $source = $stat->perSource() ? 'source' : "'' AS source";
    $rows = $db->all(
        "SELECT key, {$source}, SUM(flows) AS flows, SUM(packets) AS packets, SUM(bytes) AS bytes, COUNT(*) AS intervals
           FROM topn_5m WHERE profile = ? AND stat = ? AND ts >= ? AND ts < ? AND source IN ({$in})
          GROUP BY {$group} ORDER BY {$orderBy} DESC, bytes DESC, source ASC, key ASC LIMIT 50",
        [$profile, $stat->value, $start, $end, ...$sources],
    );

    return array_map(static fn (array $row): array => [
        'key' => (string) $row['key'],
        'source' => (string) $row['source'],
        'flows' => (int) $row['flows'],
        'packets' => (int) $row['packets'],
        'bytes' => (int) $row['bytes'],
        'intervals' => (int) $row['intervals'],
    ], $rows);
}

/** @param list<array<string, mixed>> $rows */
function topnWithoutIntervals(array $rows): array {
    return array_map(static function (array $row): array {
        unset($row['intervals']);

        return $row;
    }, $rows);
}

/**
 * 33 days of 5 minute intervals for two sources, built once for the range tests.
 *
 * @return array{db: Database, repo: TopNRepository}
 */
function topnRangeFixture(): array {
    static $fixture = null;
    if ($fixture !== null) {
        return $fixture;
    }

    srand(20260828);
    $db = Database::open(':memory:');
    $repo = new TopNRepository($db);
    for ($ts = TOPN_DAY0 - 86400; $ts < TOPN_DAY0 + 32 * 86400; $ts += 300) {
        foreach (['gw', 'core'] as $source) {
            $repo->storeInterval('live', $source, $ts, TOPN_TOTALS, TopNRepository::STATUS_OK, $ts + 300, [
                TopNStat::SrcIp->value => topnRandomRows(4, 70),
                TopNStat::InIf->value => topnRandomRows(2, 4, ''),
            ]);
        }
    }

    return $fixture = ['db' => $db, 'repo' => $repo];
}

beforeEach(function (): void {
    $this->db = Database::open(':memory:');
    $this->repo = new TopNRepository($this->db);
    TopNRepository::clearCache();
});

describe('TopNRepository::storeInterval()', function (): void {
    test('writes the interval, its rows and both rollups', function (): void {
        $ts = TOPN_DAY0 + 3600 + 600;
        $this->repo->storeInterval('live', 'gw', $ts, TOPN_TOTALS, TopNRepository::STATUS_OK, 1234, [
            TopNStat::SrcIp->value => topnRows(['10.0.0.1' => 500, '10.0.0.2' => 300]),
            TopNStat::Proto->value => topnRows(['6' => 800]),
        ], 99);

        expect($this->repo->intervalState('live', 'gw', $ts))->toBe(['status' => 0, 'attempts' => 1, 'fileMtime' => 1234])
            ->and($this->db->value('SELECT collected_at FROM topn_interval'))->toBe(99)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_5m'))->toBe(3)
            ->and($this->db->all('SELECT ts, key, bytes FROM topn_1h ORDER BY key'))->toBe([
                ['ts' => TOPN_DAY0 + 3600, 'key' => '10.0.0.1', 'bytes' => 500],
                ['ts' => TOPN_DAY0 + 3600, 'key' => '10.0.0.2', 'bytes' => 300],
                ['ts' => TOPN_DAY0 + 3600, 'key' => '6', 'bytes' => 800],
            ])
            ->and($this->db->all('SELECT DISTINCT ts FROM topn_1d'))->toBe([['ts' => TOPN_DAY0]])
        ;
    });

    test('storing the same interval twice changes nothing', function (): void {
        $rows = [TopNStat::SrcIp->value => topnRows(['10.0.0.1' => 500, '10.0.0.2' => 300])];
        $ts = TOPN_DAY0 + 300;
        $this->repo->storeInterval('live', 'gw', $ts, TOPN_TOTALS, TopNRepository::STATUS_OK, 1234, $rows);
        $snapshot = fn (): array => [
            $this->db->all('SELECT * FROM topn_5m ORDER BY key'),
            $this->db->all('SELECT * FROM topn_1h ORDER BY key'),
            $this->db->all('SELECT * FROM topn_1d ORDER BY key'),
            $this->db->all('SELECT profile, source, ts, flows, packets, bytes, status, attempts, file_mtime FROM topn_interval'),
        ];
        $once = $snapshot();

        $this->repo->storeInterval('live', 'gw', $ts, TOPN_TOTALS, TopNRepository::STATUS_OK, 1234, $rows);

        expect($snapshot())->toBe($once);
    });

    test('duplicate keys of one statistic are summed, not rejected', function (): void {
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, TopNRepository::STATUS_OK, 1, [
            TopNStat::DstPort->value => [...topnRows(['53/udp' => 100]), ...topnRows(['53/udp' => 50])],
        ]);

        expect($this->db->value('SELECT bytes FROM topn_5m'))->toBe(150);
    });

    test('an unknown statistic is rejected before anything is written', function (): void {
        expect(fn () => $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, 0, 1, [42 => topnRows(['x' => 1])]))
            ->toThrow(InvalidArgumentException::class)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval'))->toBe(0)
        ;
    });

    test('re-collecting an interval with other rows keeps the rollups exact and drops keys that reach zero', function (): void {
        $hour = TOPN_DAY0 + 7200;
        $this->repo->storeInterval('live', 'gw', $hour, TOPN_TOTALS, 0, 1, [TopNStat::SrcIp->value => topnRows(['a' => 100, 'b' => 200])]);
        $this->repo->storeInterval('live', 'gw', $hour + 300, TOPN_TOTALS, 0, 1, [TopNStat::SrcIp->value => topnRows(['a' => 1000])]);

        // The rewritten file no longer has b, and brings c.
        $this->repo->storeInterval('live', 'gw', $hour, TOPN_TOTALS, 0, 2, [TopNStat::SrcIp->value => topnRows(['a' => 400, 'c' => 50])]);

        [$hour, $hourSum] = topnRollupVersusSum($this->db, 'topn_1h', 3600);
        [$day, $daySum] = topnRollupVersusSum($this->db, 'topn_1d', 86400);

        expect($this->db->all('SELECT key, bytes FROM topn_1h ORDER BY key'))->toBe([
            ['key' => 'a', 'bytes' => 1400],
            ['key' => 'c', 'bytes' => 50],
        ])
            ->and($hour)->toBe($hourSum)
            ->and($day)->toBe($daySum)
        ;
    });

    test('an empty or failed re-collection takes the old rows out of the rollups', function (): void {
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, 0, 1, [TopNStat::SrcIp->value => topnRows(['a' => 100])]);
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, ['flows' => 0, 'packets' => 0, 'bytes' => 0], TopNRepository::STATUS_EMPTY, 2, []);

        expect($this->db->value('SELECT COUNT(*) FROM topn_5m'))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1h'))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1d'))->toBe(0)
            ->and($this->repo->intervalState('live', 'gw', TOPN_DAY0)['status'])->toBe(TopNRepository::STATUS_EMPTY)
        ;
    });

    test('after random intervals, re-collections and several sources, every rollup row equals the sum of its 5 minute rows', function (): void {
        srand(7);
        $stats = [TopNStat::SrcIp, TopNStat::DstPort, TopNStat::OutIf];
        $slots = [];
        for ($i = 0; $i < 400; ++$i) {
            // Three days around a day boundary, random order, some slots hit more than once.
            $ts = TOPN_DAY0 - 86400 + random_int(0, 3 * 288 - 1) * 300;
            $source = ['gw', 'core', 'edge'][random_int(0, 2)];
            $rows = [];
            foreach ($stats as $stat) {
                if (random_int(0, 4) > 0) {
                    $rows[$stat->value] = topnRandomRows(random_int(1, 6), 12, $stat->name . '-');
                }
            }
            $status = $rows === [] ? TopNRepository::STATUS_EMPTY : TopNRepository::STATUS_OK;
            $this->repo->storeInterval(random_int(0, 5) === 0 ? 'other' : 'live', $source, $ts, TOPN_TOTALS, $status, $i, $rows);
            $slots["{$source}/{$ts}"] = true;
        }

        [$hour, $hourSum] = topnRollupVersusSum($this->db, 'topn_1h', 3600);
        [$day, $daySum] = topnRollupVersusSum($this->db, 'topn_1d', 86400);

        expect(count($slots))->toBeLessThan(400)
            ->and($hour)->not->toBeEmpty()
            ->and($hour)->toBe($hourSum)
            ->and($day)->toBe($daySum)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1h WHERE flows <= 0 AND packets <= 0 AND bytes <= 0'))->toBe(0)
        ;
    });

    test('a repeated failure of the same file counts attempts; a new file or a success starts over', function (): void {
        $fail = fn (int $mtime) => $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, TopNRepository::STATUS_FAILED, $mtime, []);

        $fail(10);
        $fail(10);
        $fail(10);
        $afterThree = $this->repo->intervalState('live', 'gw', TOPN_DAY0);
        $fail(11);
        $afterNewFile = $this->repo->intervalState('live', 'gw', TOPN_DAY0);
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, TopNRepository::STATUS_OK, 11, []);

        expect($afterThree)->toBe(['status' => 2, 'attempts' => 3, 'fileMtime' => 10])
            ->and($afterNewFile)->toBe(['status' => 2, 'attempts' => 1, 'fileMtime' => 11])
            ->and($this->repo->intervalState('live', 'gw', TOPN_DAY0))->toBe(['status' => 0, 'attempts' => 1, 'fileMtime' => 11])
            ->and($this->repo->intervalState('live', 'gw', TOPN_DAY0 + 300))->toBeNull()
        ;
    });
});

/**
 * Asserts the chunks tile [start, end) without gaps and each tier is aligned to its size.
 *
 * @return array<string, int> chunks per tier
 */
function topnAssertTiles(int $start, int $end): array {
    $chunks = TopNRepository::chunks($start, $end);
    $cursor = $start;
    foreach ($chunks as [$tier, $from, $to]) {
        expect($from)->toBe($cursor)->and($to)->toBeGreaterThan($from);
        if ($tier === TopNRepository::TIER_1H) {
            expect($from % 3600)->toBe(0)->and($to % 3600)->toBe(0)->and($to - $from)->toBeLessThanOrEqual(6 * 3600);
        }
        if ($tier === TopNRepository::TIER_1D) {
            expect($from % 86400)->toBe(0)->and($to - $from)->toBe(86400);
        }
        $cursor = $to;
    }
    expect($cursor)->toBe($end);

    return array_count_values(array_column($chunks, 0));
}

describe('TopNRepository::chunks()', function (): void {
    test('up to 6 hours is one 5 minute chunk', function (): void {
        expect(TopNRepository::chunks(TOPN_DAY0 + 420, TOPN_DAY0 + 420 + 1800))->toBe([[TopNRepository::TIER_5M, TOPN_DAY0 + 420, TOPN_DAY0 + 2220]])
            ->and(TopNRepository::chunks(TOPN_DAY0 + 300, TOPN_DAY0 + 300 + 6 * 3600))->toHaveCount(1)
            ->and(TopNRepository::chunks(TOPN_DAY0, TOPN_DAY0))->toBe([])
        ;
    });

    test('a range with ragged edges reads 5 minute edges, whole hours and whole days', function (): void {
        $tiers = topnAssertTiles(TOPN_DAY0 + 3 * 3600 + 1500, TOPN_DAY0 + 2 * 86400 + 5 * 3600 + 600);

        expect($tiers)->toBe([TopNRepository::TIER_5M => 2, TopNRepository::TIER_1H => 5, TopNRepository::TIER_1D => 1]);
    });

    test('no whole day: hours in 6 hour chunks between the 5 minute edges', function (): void {
        $tiers = topnAssertTiles(TOPN_DAY0 + 3 * 3600 + 1500, TOPN_DAY0 + 86400 + 2 * 3600 + 600);

        expect($tiers)->toBe([TopNRepository::TIER_5M => 2, TopNRepository::TIER_1H => 4]);
    });

    test('31 aligned days are 31 day chunks', function (): void {
        expect(topnAssertTiles(TOPN_DAY0, TOPN_DAY0 + 31 * 86400))->toBe([TopNRepository::TIER_1D => 31]);
    });
});

describe('TopNRepository::rangeTop()', function (): void {
    test('equals one GROUP BY over the 5 minute rows for 30 min, 3 h, 2 days with ragged edges and 31 days', function (string $order): void {
        ['db' => $db, 'repo' => $repo] = topnRangeFixture();
        $windows = [
            '30 min' => [TOPN_DAY0 + 5 * 3600 + 420, TOPN_DAY0 + 5 * 3600 + 420 + 1800],
            '3 h' => [TOPN_DAY0 + 86400 - 3600, TOPN_DAY0 + 86400 + 7200],
            '2 days, ragged' => [TOPN_DAY0 + 3 * 3600 + 1500, TOPN_DAY0 + 2 * 86400 + 3 * 3600 + 1500],
            '31 days' => [TOPN_DAY0 - 3600 + 2100, TOPN_DAY0 + 30 * 86400 + 2100],
        ];

        foreach ($windows as $label => [$start, $end]) {
            foreach ([['gw'], ['gw', 'core']] as $sources) {
                foreach ([TopNStat::SrcIp, TopNStat::InIf] as $stat) {
                    $got = $repo->rangeTop('live', $stat, $sources, $start, $end, $order);
                    $want = topnReference($db, 'live', $stat, $sources, $start, $end, $order);

                    expect($got)->not->toBeEmpty();
                    if ($end - $start <= TopNRepository::EXACT_MAX) {
                        expect($got)->toBe($want, $label);
                    } else {
                        expect(topnWithoutIntervals($got))->toBe(topnWithoutIntervals($want), $label);
                    }
                }
            }
        }
    })->with(['bytes', 'packets', 'flows']);

    test('interfaces are grouped per source, everything else across sources', function (): void {
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, 0, 1, [
            TopNStat::InIf->value => topnRows(['3' => 100]),
            TopNStat::SrcIp->value => topnRows(['10.0.0.1' => 100]),
        ]);
        $this->repo->storeInterval('live', 'core', TOPN_DAY0, TOPN_TOTALS, 0, 1, [
            TopNStat::InIf->value => topnRows(['3' => 300]),
            TopNStat::SrcIp->value => topnRows(['10.0.0.1' => 300]),
        ]);

        $interfaces = $this->repo->rangeTop('live', TopNStat::InIf, ['gw', 'core'], TOPN_DAY0, TOPN_DAY0 + 300, 'bytes');
        $addresses = $this->repo->rangeTop('live', TopNStat::SrcIp, ['gw', 'core'], TOPN_DAY0, TOPN_DAY0 + 300, 'bytes');

        expect(array_map(static fn (array $r): array => [$r['source'], $r['key'], $r['bytes']], $interfaces))->toBe([['core', '3', 300], ['gw', '3', 100]])
            ->and($addresses)->toBe([['key' => '10.0.0.1', 'source' => '', 'flows' => 2, 'packets' => 4, 'bytes' => 400, 'intervals' => 2]])
        ;
    });

    test('keeps the top 50 and only the sources asked for', function (): void {
        $keys = [];
        for ($i = 1; $i <= 60; ++$i) {
            $keys["10.0.1.{$i}"] = $i * 100;
        }
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, 0, 1, [TopNStat::SrcIp->value => topnRows($keys)]);
        $this->repo->storeInterval('live', 'core', TOPN_DAY0, TOPN_TOTALS, 0, 1, [TopNStat::SrcIp->value => topnRows(['10.9.9.9' => 1_000_000])]);

        $rows = $this->repo->rangeTop('live', TopNStat::SrcIp, ['gw'], TOPN_DAY0, TOPN_DAY0 + 300, 'bytes');

        expect($rows)->toHaveCount(50)
            ->and($rows[0]['key'])->toBe('10.0.1.60')
            ->and($rows[49]['key'])->toBe('10.0.1.11')
            ->and($this->repo->rangeTop('live', TopNStat::SrcIp, [], TOPN_DAY0, TOPN_DAY0 + 300, 'bytes'))->toBe([])
        ;
    });

    test('ties are ordered by key as text, the way SQLite orders them', function (): void {
        $this->repo->storeInterval('live', 'gw', TOPN_DAY0, TOPN_TOTALS, 0, 1, [TopNStat::Proto->value => topnRows(['6' => 100, '17' => 100, '100' => 100])]);

        expect(array_column($this->repo->rangeTop('live', TopNStat::Proto, ['gw'], TOPN_DAY0, TOPN_DAY0 + 300, 'bytes'), 'key'))
            ->toBe(['100', '17', '6'])
        ;
    });

    test('calls $yield between chunks, not before the first or after the last', function (): void {
        $start = TOPN_DAY0 + 3 * 3600 + 1500;
        $end = TOPN_DAY0 + 2 * 86400 + 5 * 3600 + 600;
        $calls = 0;

        $this->repo->rangeTop('live', TopNStat::SrcIp, ['gw'], $start, $end, 'bytes', static function () use (&$calls): void {
            ++$calls;
        });

        expect($calls)->toBe(count(TopNRepository::chunks($start, $end)) - 1);
    });

    test('rejects an unknown order', function (): void {
        expect(fn () => $this->repo->rangeTop('live', TopNStat::SrcIp, ['gw'], TOPN_DAY0, TOPN_DAY0 + 300, 'bits'))->toThrow(InvalidArgumentException::class);
    });
});

describe('TopNRepository interval queries', function (): void {
    beforeEach(function (): void {
        $store = fn (string $source, int $ts, int $status, int $bytes = 1000) => $this->repo->storeInterval('live', $source, $ts, ['flows' => 1, 'packets' => 10, 'bytes' => $bytes], $status, 5, []);
        $store('gw', TOPN_DAY0, TopNRepository::STATUS_OK);
        $store('gw', TOPN_DAY0 + 300, TopNRepository::STATUS_EMPTY, 0);
        $store('gw', TOPN_DAY0 + 600, TopNRepository::STATUS_FAILED, 7000);
        $store('gw', TOPN_DAY0 + 900, TopNRepository::STATUS_FAILED);
        $store('gw', TOPN_DAY0 + 900, TopNRepository::STATUS_FAILED);
        $store('gw', TOPN_DAY0 + 900, TopNRepository::STATUS_FAILED);
        $store('core', TOPN_DAY0, TopNRepository::STATUS_OK, 3000);
        $store('gw', TOPN_DAY0 + 3600, TopNRepository::STATUS_OK);
    });

    test('rangeTotals() sums the collected intervals of the sources in [start, end)', function (): void {
        expect($this->repo->rangeTotals('live', ['gw'], TOPN_DAY0, TOPN_DAY0 + 3600))->toBe(['flows' => 2, 'packets' => 20, 'bytes' => 1000, 'intervals' => 2])
            ->and($this->repo->rangeTotals('live', ['gw', 'core'], TOPN_DAY0, TOPN_DAY0 + 3601))->toBe(['flows' => 4, 'packets' => 40, 'bytes' => 5000, 'intervals' => 4])
            ->and($this->repo->rangeTotals('live', [], TOPN_DAY0, TOPN_DAY0 + 3600))->toBe(['flows' => 0, 'packets' => 0, 'bytes' => 0, 'intervals' => 0])
            ->and($this->repo->rangeTotals('other', ['gw'], TOPN_DAY0, TOPN_DAY0 + 3600)['intervals'])->toBe(0)
        ;
    });

    test('collectedTs() lists ok, empty and given-up intervals, not those still to retry', function (): void {
        expect($this->repo->collectedTs('live', 'gw', TOPN_DAY0, TOPN_DAY0 + 3600))->toBe([TOPN_DAY0, TOPN_DAY0 + 300, TOPN_DAY0 + 900])
            ->and($this->repo->collectedTs('live', 'core', TOPN_DAY0, TOPN_DAY0 + 3600))->toBe([TOPN_DAY0])
        ;
    });

    test('oldestTs() and profiles()', function (): void {
        $this->repo->storeInterval('other', 'gw', TOPN_DAY0 + 600, TOPN_TOTALS, 0, 1, [TopNStat::Proto->value => topnRows(['6' => 1])]);

        expect($this->repo->oldestTs('live'))->toBe(TOPN_DAY0)
            ->and($this->repo->oldestTs('nothing'))->toBeNull()
            ->and($this->repo->oldestTs('other', TopNStat::Proto, TopNRepository::TIER_1H))->toBe(TOPN_DAY0)
            ->and($this->repo->oldestTs('other', TopNStat::SrcIp, TopNRepository::TIER_1H))->toBeNull()
            ->and($this->repo->profiles())->toBe(['live', 'other'])
        ;
    });
});

describe('TopNRepository::pruneChunk()', function (): void {
    test('deletes one statistic of one tier, or the intervals, in [from, to)', function (): void {
        foreach ([0, 300, 3600] as $offset) {
            $this->repo->storeInterval('live', 'gw', TOPN_DAY0 + $offset, TOPN_TOTALS, 0, 1, [
                TopNStat::SrcIp->value => topnRows(['a' => 1]),
                TopNStat::Proto->value => topnRows(['6' => 1]),
            ]);
        }

        $srcIp = $this->repo->pruneChunk('live', TOPN_DAY0, TOPN_DAY0 + 3600, TopNStat::SrcIp);
        $intervals = $this->repo->pruneChunk('live', TOPN_DAY0, TOPN_DAY0 + 3600);
        $hours = $this->repo->pruneChunk('live', TOPN_DAY0, TOPN_DAY0 + 3600, TopNStat::Proto, TopNRepository::TIER_1H);

        expect([$srcIp, $intervals, $hours])->toBe([2, 2, 1])
            ->and($this->db->all('SELECT stat, ts FROM topn_5m ORDER BY stat, ts'))->toBe([
                ['stat' => 1, 'ts' => TOPN_DAY0 + 3600],
                ['stat' => 5, 'ts' => TOPN_DAY0],
                ['stat' => 5, 'ts' => TOPN_DAY0 + 300],
                ['stat' => 5, 'ts' => TOPN_DAY0 + 3600],
            ])
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval'))->toBe(1)
            ->and($this->db->all('SELECT stat, ts FROM topn_1h ORDER BY stat, ts'))->toHaveCount(3)
            ->and(fn () => $this->repo->pruneChunk('live', 0, 1, TopNStat::SrcIp, 'meta'))->toThrow(InvalidArgumentException::class)
        ;
    });
});

/**
 * Every statement of the repository with its placeholders filled in, once per tier.
 *
 * @return array<string, string>
 */
function topnStatements(): array {
    $statements = [];
    foreach ((new ReflectionClass(TopNRepository::class))->getConstants() as $name => $sql) {
        if (!str_starts_with($name, 'SQL_') || !is_string($sql)) {
            continue;
        }
        $tiers = str_contains($sql, '{tier}') ? [TopNRepository::TIER_5M, TopNRepository::TIER_1H, TopNRepository::TIER_1D] : [''];
        $groups = str_contains($sql, '{group}') ? ['key', 'source, key'] : [''];
        foreach ($tiers as $tier) {
            foreach ($groups as $group) {
                $statements["{$name} {$tier} {$group}"] = str_replace(
                    ['{tier}', '{stats}', '{in}', '{group}'],
                    [$tier, implode(',', TopNStat::values()), '?, ?, ?', $group],
                    $sql,
                );
            }
        }
    }

    return $statements;
}

/** @return list<string> */
function topnPlan(Database $db, string $sql): array {
    $stmt = $db->pdo()->prepare('EXPLAIN QUERY PLAN ' . $sql);
    $stmt->execute();

    return array_map(static fn (array $row): string => (string) $row['detail'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

describe('TopNRepository query plans', function (): void {
    test('range chunks and prune statements seek on (profile, stat, ts)', function (): void {
        $plans = [];
        foreach ([TopNRepository::TIER_5M, TopNRepository::TIER_1H, TopNRepository::TIER_1D] as $tier) {
            $plans[] = topnPlan($this->db, TopNRepository::rangeChunkSql($tier, false, 3));
            $plans[] = topnPlan($this->db, TopNRepository::rangeChunkSql($tier, true, 1));
        }
        foreach (topnStatements() as $name => $sql) {
            if (str_starts_with($name, 'SQL_RANGE_CHUNK') || str_starts_with($name, 'SQL_PRUNE_STAT')) {
                $plans[] = topnPlan($this->db, $sql);
            }
        }

        foreach ($plans as $plan) {
            expect(implode("\n", $plan))->toMatch('/SEARCH topn_(5m|1h|1d) USING PRIMARY KEY \(profile=\? AND stat=\? AND ts>\? AND ts<\?\)/');
        }
    });

    test('the write statements seek on (profile, stat, ts, source)', function (): void {
        foreach (topnStatements() as $name => $sql) {
            if (!preg_match('/^SQL_(ROLLUP_SUBTRACT|ROLLUP_DROP_ZERO|INTERVAL_ROWS_DELETE|ROLLUP_ADD)/', $name)) {
                continue;
            }
            foreach (topnPlan($this->db, $sql) as $line) {
                if (preg_match('/\b(topn_5m|topn_1h|topn_1d|r)\b/', $line) === 1) {
                    expect($line)->toMatch('/USING PRIMARY KEY \(profile=\? AND stat=\? AND ts=\? AND source=\?/', $name);
                }
            }
        }
    });

    test('no statement seeks on a stat range, and topn_interval is only scanned for its profile list', function (): void {
        foreach (topnStatements() as $name => $sql) {
            foreach (topnPlan($this->db, $sql) as $line) {
                expect($line)->not->toMatch('/stat>\? AND stat<\?/', $name);
                if (!str_starts_with($name, 'SQL_PROFILES') && str_contains($line, 'topn_')) {
                    expect($line)->not->toMatch('/^SCAN/', $name);
                }
            }
        }
    });
});
