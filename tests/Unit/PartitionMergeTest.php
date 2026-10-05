<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\NfdumpSummary;
use mbolli\nfsen_ng\query\ConversationPayload;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\PartitionMerge;
use mbolli\nfsen_ng\query\PartitionPlanner;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatisticCatalog;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;
use Tests\Support\captures\FixtureCaptures;

/** 2026-09-29 00:00 UTC, the first file of the fixture captures. */
const MERGE_BASE = 1_790_640_000;

/** Files per source in the window of the fixture captures: 2 hours of 5-minute files, and two more after it. */
const MERGE_FILES = 24;

/** Sources of the second capture tree: a slice can be one interval of six files. */
const MERGE_WIDE = ['r1', 'r2', 'r3', 'r4', 'r5', 'r6'];

/** Intervals the gap tree lacks, by source: gw, the first -M directory, lacks the window's first. */
const MERGE_GAPS = ['gw' => [0, 4, 5, 8, 9, 12, 13, 16, 17, 20], 'dmz' => [10, 20, 23]];

/** @var array<string, string> -A element => the fmt token a part prints it with */
const MERGE_RECORD_TOKENS = ['proto' => '%pr', 'srcport' => '%sp', 'dstport' => '%dp', 'srcip' => '%sa', 'srcip4' => '%sa', 'dstip' => '%da', 'dstip4' => '%da'];

/**
 * A part for exactTop(): key => value as listed, truncated or complete.
 *
 * @param array<string, int> $values
 * @param list<string>       $known
 *
 * @return array{values: array<string, int>, truncated: bool, known: array<string, true>}
 */
function mergePart(array $values, bool $truncated, array $known = []): array {
    return ['values' => $values, 'truncated' => $truncated, 'known' => array_fill_keys($known, true)];
}

/**
 * The top $n of complete per-part maps, as nfdump over all of them ranks it: total, then key.
 *
 * @param list<array<string, int>> $full
 *
 * @return list<array{string, int}>
 */
function mergeTruth(array $full, int $n): array {
    $totals = [];
    foreach ($full as $part) {
        foreach ($part as $key => $value) {
            $totals[$key] = ($totals[$key] ?? 0) + $value;
        }
    }
    uksort($totals, static fn (string $a, string $b): int => [$totals[$b], $a] <=> [$totals[$a], $b]);

    return array_map(null, array_map(strval(...), array_keys(array_slice($totals, 0, $n, true))), array_values(array_slice($totals, 0, $n, true)));
}

/**
 * Whether $got is the top of $truth up to ties: the same values in order, and every key the
 * true total of its value.
 *
 * @param list<string>             $got
 * @param list<array{string, int}> $truth
 * @param list<array<string, int>> $full
 */
function mergeMatchesTruth(array $got, array $truth, array $full): bool {
    $totals = [];
    foreach ($full as $part) {
        foreach ($part as $key => $value) {
            $totals[$key] = ($totals[$key] ?? 0) + $value;
        }
    }

    return count($got) === count($truth)
        && array_map(static fn (string $key): int => $totals[$key], $got) === array_column($truth, 1);
}

/**
 * Settings pointing at a capture tree, with the real nfdump and room for 8 parts.
 *
 * @param list<string> $sources
 */
function mergeUseCaptures(string $root, array $sources = ['gw', 'dmz']): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => $sources, 'ports' => [], 'max_stats_window' => 0],
        'nfdump' => ['binary' => FixtureCaptures::BIN . '/nfdump', 'profiles-data' => $root, 'profile' => 'live', 'max-processes' => 9],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$processorClass = new Nfdump();
}

/** The root of a capture tree beforeAll() built, by name. */
function mergeTree(string $name): string {
    return (string) file_get_contents(sys_get_temp_dir() . '/nfsen-p4-captures-' . $name . '.root');
}

/** A top $n computed from the parts and printed, or a failed test when they cannot prove it. */
function mergeProven(array $ranked, int $n): array {
    $top = PartitionMerge::exactTop($ranked, $n);
    expect($top)->not->toBeNull();

    return $top ?? [];
}

function mergeWindow(): TimeWindow {
    return TimeWindow::raw(MERGE_BASE, MERGE_BASE + MERGE_FILES * 300 - 1);
}

/** The window's file of $interval, relative to a source directory. */
function mergeRelPath(int $interval): string {
    $ts = MERGE_BASE + $interval * 300;

    return gmdate('Y/m/d', $ts) . '/nfcapd.' . gmdate('YmdHi', $ts);
}

/**
 * What nfdump ranks every aggregated record of the window by (in plus out), by its key fields
 * joined as PartitionMerge joins them.
 *
 * @param array<string, mixed> $spec
 *
 * @return array<string, int>
 */
function mergeRecordRanking(array $spec, string $order): array {
    $aggregation = Nfdump::buildAggregationString($spec);
    $tokens = array_map(static fn (string $element): string => MERGE_RECORD_TOKENS[explode('/', $element)[0]], explode(',', $aggregation));
    $nfdump = new Nfdump();
    $nfdump->keepRawOutput();
    $nfdump->setOption('-M', 'gw:dmz');
    $nfdump->setOption('-R', mergeWindow()->toRangeOption());
    $nfdump->setOption('-n', 0);
    $nfdump->setOption('-o', PartitionMerge::recordFormat($tokens));
    $nfdump->setOption('-s', 'record/' . $order);
    $nfdump->setOption('-a', '-A' . $aggregation);

    return PartitionMerge::rankFlows([PartitionMerge::parseRecords($nfdump->execute()['rawOutput'], count($tokens))], $order)[0]['values'];
}

/**
 * What nfdump ranks every pair of the query's window by (in plus out), keyed as PartitionMerge keys them.
 *
 * @return array<string, int>
 */
function mergePairRanking(MatrixQuery $query): array {
    $nfdump = new Nfdump();
    $nfdump->keepRawOutput();
    $nfdump->setOption('-M', 'gw:dmz');
    $nfdump->setOption('-R', $query->window->toRangeOption());
    $nfdump->setOption('-a', '-A' . $query->aggregation());
    $nfdump->setOption('-O', $query->metric());
    $nfdump->setOption('-n', 0);
    $nfdump->setOption('-N', null);
    $nfdump->setOption('-6', null);
    $nfdump->setOption('-o', PartitionMerge::pairFormat($query->outputFormat()));
    $nfdump->setFilter($query->effectiveFilter());

    return PartitionMerge::rankFlows([PartitionMerge::parsePairs($nfdump->execute()['rawOutput'], $query->groupBy() === 'port' ? 3 : 2)], $query->metric())[0]['values'];
}

/**
 * The ranking value of an aggregated record's csv line: its key columns looked up in $ranking.
 *
 * @param array<string, int> $ranking
 */
function mergeRecordValue(array $ranking, int $keyFields): Closure {
    return static fn (string $line): int => $ranking[implode("\0", array_slice(explode(',', $line), 2, $keyFields)) . "\0"] ?? -1;
}

/**
 * The ranking value of a pair row (json of the decoded row): its key looked up in $ranking.
 *
 * @param array<string, int> $ranking
 */
function mergePairValue(array $ranking): Closure {
    return static function (string $row) use ($ranking): int {
        $pair = json_decode($row, true);
        $key = [$pair['sa'], $pair['da'], ...(isset($pair['dp']) ? [(string) $pair['dp']] : [])];

        return $ranking[implode("\0", $key) . "\0"] ?? -1;
    };
}

/**
 * Asserts two row lists are the same up to the order of equal values: the same values in the
 * same order, the same rows for every value above the last one, and as many rows at the last
 * value, each a row of the complete listing ($complete, only asked for when the rows differ).
 *
 * @param list<string>            $single
 * @param list<string>            $split
 * @param Closure(string): int    $value
 * @param Closure(): list<string> $complete
 */
function mergeSameRows(array $single, array $split, Closure $value, Closure $complete, string $case): void {
    if ($single === $split) {
        return;
    }

    expect(array_map($value, $split))->toBe(array_map($value, $single), $case);
    $cut = $single === [] ? 0 : min(array_map($value, $single));
    $above = static function (array $rows) use ($value, $cut): array {
        $byValue = [];
        foreach ($rows as $row) {
            if ($value($row) > $cut) {
                $byValue[$value($row)][] = $row;
            }
        }
        foreach ($byValue as $v => $group) {
            sort($group);
            $byValue[$v] = $group;
        }
        ksort($byValue);

        return $byValue;
    };

    expect($above($split))->toBe($above($single), $case);
    $all = array_flip($complete());
    foreach ($split as $row) {
        if ($value($row) === $cut) {
            expect(isset($all[$row]))->toBeTrue($case . ': ' . $row);
        }
    }
}

/** The value a statistic row ranks by, from nfdump's json or csv line. */
function mergeStatValue(string $order, string $output): Closure {
    if ($output === 'json') {
        return static fn (string $line): int => (int) (json_decode($line, true)[$order] ?? 0);
    }
    $column = ['flows' => 5, 'packets' => 7, 'bytes' => 9][$order];

    return static fn (string $line): int => (int) (str_getcsv($line, ',', '"', '')[$column] ?? 0);
}

/** @return list<string> the data lines of a statistic output: json objects or csv rows */
function mergeLines(mixed $raw): array {
    return array_values(array_filter(explode("\n", (string) $raw), static fn (string $line): bool => str_starts_with($line, '{') || preg_match('/^\d{4}-\d\d-\d\d /', $line) === 1));
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->processorBefore = (new ReflectionProperty(Config::class, 'processorClass'))->isInitialized() ? Config::$processorClass : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    Config::$stateDir = '';
    Database::resetShared();
    PartitionPlanner::useFetchLimit(null);
    PartitionMerge::useZone(null);
});

afterEach(function (): void {
    PartitionPlanner::useFetchLimit(null);
    PartitionMerge::useZone(null);
    foreach (NfdumpSlots::CLASSES as $class) {
        NfdumpSlots::release($class, NfdumpSlots::inUse($class));
    }
    Database::resetShared();
    Config::$stateDir = $this->stateDirBefore ?? '';
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->processorBefore !== null) {
        Config::$processorClass = $this->processorBefore;
    }
});

describe('the exactness check', function (): void {
    test('complete parts prove any top: totals first, equal totals by key', function (): void {
        $top = PartitionMerge::exactTop([
            mergePart(['a' => 5, 'b' => 3], false),
            mergePart(['b' => 2, 'c' => 5, 'd' => 1], false),
        ], 3);

        expect($top)->toBe(['a', 'b', 'c']);
    });

    test('truncated parts prove a top listed everywhere and ahead of what anything else could reach', function (): void {
        $top = PartitionMerge::exactTop([
            mergePart(['a' => 100, 'b' => 60, 'c' => 10], true),
            mergePart(['a' => 90, 'b' => 70, 'd' => 12], true),
            mergePart(['e' => 1], false),
        ], 2);

        expect($top)->toBe(['a', 'b']);
    });

    // The forced failure: the N-th total (100) beats the cutoffs (50 + 40), yet the key a part
    // does not list may hold up to its cutoff there, and z's true total is 140.
    test('refuses a top whose total a truncated part may not have listed, which the cutoff sum alone would accept', function (): void {
        $listed = [mergePart(['x' => 100, 'y' => 50], true), mergePart(['z' => 90, 'w' => 40], true)];
        $truth = mergeTruth([['x' => 100, 'y' => 50, 'z' => 50], ['z' => 90, 'w' => 40]], 1);

        expect(PartitionMerge::exactTop($listed, 1))->toBeNull()
            ->and($truth)->toBe([['z', 140]])
        ;
    });

    test('refuses when a key no part listed could still reach the top once the lookups are in', function (): void {
        // The lookups found a and c nowhere else: each totals 10, while a key neither part
        // listed may hold 9 in both.
        expect(PartitionMerge::exactTop([
            mergePart(['a' => 10, 'b' => 9], true, ['c']),
            mergePart(['c' => 10, 'd' => 9], true, ['a']),
        ], 1))->toBeNull()
            ->and(PartitionMerge::exactTop([
                mergePart(['a' => 10, 'b' => 9], true),
                mergePart(['a' => 10, 'b' => 9], true),
            ], 1))->toBe(['a'])
        ;
    });

    test('a key a lookup found or ruled out in a part counts as known there, and the cutoff stays the last listed value', function (): void {
        $parts = [
            mergePart(['x' => 100, 'y' => 50], true),
            mergePart(['z' => 90, 'w' => 40], true),
        ];
        $lookups = PartitionMerge::lookups($parts, 1);

        expect($lookups)->toBe([0 => ['z'], 1 => ['x']]);

        $parts[0] = mergePart(['x' => 100, 'y' => 50, 'z' => 50], true, ['z']);
        $parts[1] = mergePart(['z' => 90, 'w' => 40], true, ['x']);

        expect(PartitionMerge::exactTop($parts, 1))->toBe(['z']);
    });

    test('no lookup once more keys than it may ask for could reach the top', function (): void {
        $parts = [mergePart(['a' => 100, 'b' => 99, 'c' => 1], true), mergePart(['d' => 100, 'e' => 99, 'f' => 1], true)];

        expect(PartitionMerge::lookups($parts, 2, 3))->toBeNull()
            ->and(PartitionMerge::lookups($parts, 2, 4))->toBe([0 => ['d', 'e'], 1 => ['a', 'b']])
        ;
    });

    test('prove gives the top, or the lookups that could prove it, from one pass over the parts', function (): void {
        $parts = [mergePart(['x' => 100, 'y' => 50], true), mergePart(['z' => 90, 'w' => 40], true)];

        expect(PartitionMerge::prove($parts, 1))->toBe(['top' => null, 'lookups' => [0 => ['z'], 1 => ['x']]])
            ->and(PartitionMerge::prove([mergePart(['x' => 1, 'y' => 1, 'b' => 2, 'a' => 2], false)], 3))->toBe(['top' => ['a', 'b', 'x'], 'lookups' => []])
            ->and(PartitionMerge::prove($parts, 1, 1))->toBe(['top' => null, 'lookups' => null])
            ->and(PartitionMerge::prove([], 1))->toBe(['top' => [], 'lookups' => []])
        ;
    });

    test('absorb adds the found lines and marks every looked-up key known', function (): void {
        $part = ['lines' => ['a' => 1], 'known' => [], 'truncated' => true];

        expect(PartitionMerge::absorb($part, ['lines' => ['b' => 2, 'x' => 9]], ['b', 'c']))
            ->toBe(['lines' => ['a' => 1, 'b' => 2], 'known' => ['b' => true, 'c' => true], 'truncated' => true])
        ;
    });

    // The property behind the check: whatever the check or a lookup proves is the true top.
    test('proves only the true top, over random parts, cutoffs and lookups', function (): void {
        srand(4_2026);
        $proven = 0;
        $looked = 0;
        for ($trial = 0; $trial < 400; ++$trial) {
            $partsCount = random_int(2, 8);
            $n = random_int(1, 12);
            $limit = random_int($n, 3 * $n + 4);
            $keys = random_int($n, 60);
            $full = [];
            for ($p = 0; $p < $partsCount; ++$p) {
                $values = [];
                foreach (range(1, $keys) as $k) {
                    if (random_int(0, 3) > 0) {
                        $values['k' . $k] = (int) (1000 / ($k ** (random_int(5, 20) / 10))) + random_int(0, 30);
                    }
                }
                $full[] = $values;
            }
            $listed = [];
            foreach ($full as $values) {
                arsort($values);
                $listed[] = mergePart(array_slice($values, 0, $limit, true), count($values) > $limit || (count($values) === $limit && random_int(0, 1) === 1));
            }
            $truth = mergeTruth($full, $n);

            $top = PartitionMerge::exactTop($listed, $n);
            if ($top !== null) {
                ++$proven;
                expect(mergeMatchesTruth($top, $truth, $full))->toBeTrue("trial {$trial}");

                continue;
            }

            $lookups = PartitionMerge::lookups($listed, $n) ?? [];
            if ($lookups === []) {
                continue;
            }
            foreach ($lookups as $i => $wanted) {
                $found = array_intersect_key($full[$i], array_flip($wanted));
                $listed[$i] = mergePart($listed[$i]['values'] + $found, true, $wanted);
            }
            $top = PartitionMerge::exactTop($listed, $n);
            if ($top !== null) {
                ++$looked;
                expect(mergeMatchesTruth($top, $truth, $full))->toBeTrue("trial {$trial} after the lookup");
            }
        }

        expect($proven)->toBeGreaterThan(50)
            ->and($looked)->toBeGreaterThan(20)
        ;
    });
});

describe('merged statistics', function (): void {
    test('sum the counters, span first to last seen, recompute the rates and keep nfdump\'s json', function (): void {
        PartitionMerge::useZone(new DateTimeZone('UTC'));
        $a = PartitionMerge::parseStats(implode("\n", [
            '{ "first" : "2026-09-29T00:00:10.500", "last" : "2026-09-29T00:10:00.000", "proto" : 0, "srcip" : "10.0.0.1", "flows" : 3, "packets" : 30, "bytes" : 3000, "pps" : 0, "bps" : 40, "bpp" : 100}',
            'No matching flows',
        ]));
        $b = PartitionMerge::parseStats('{ "first" : "2026-09-29T00:20:00.000", "last" : "2026-09-29T00:30:10.500", "proto" : 0, "srcip" : "10.0.0.1", "geo" : "CH","flows" : 1, "packets" : 7, "bytes" : 1000, "pps" : 0, "bps" : 13, "bpp" : 142}');

        expect($a['empty'])->toBeTrue()
            ->and($b['lines'][PartitionMerge::statKey(0, '10.0.0.1')][5])->toBe('CH')
            ->and(PartitionMerge::stats([$a, $b], mergeProven(PartitionMerge::rankStats([$a, $b], 'bytes'), 1), 'srcip', 'bytes', 'json'))->toBe(
                // 1800 s: 4000 bytes, 37 packets.
                '{ "first" : "2026-09-29T00:00:10.500", "last" : "2026-09-29T00:30:10.500", "proto" : 0, "srcip" : "10.0.0.1", "flows" : 4, "packets" : 37, "bytes" : 4000, "pps" : 0, "bps" : 17, "bpp" : 108}' . "\n"
            )
        ;
    });

    test('in csv, with shares of the summed totals, the IN columns for flows and protocol names for -s proto', function (): void {
        PartitionMerge::useZone(new DateTimeZone('UTC'));
        $part = static fn (int $tcp, int $udp): array => PartitionMerge::parseStats(implode("\n", [
            '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:00:10.000", "proto" : 6, "proto" : "6", "flows" : ' . $tcp . ', "packets" : 10, "bytes" : 1000, "pps" : 1, "bps" : 800, "bpp" : 100}',
            '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:00:10.000", "proto" : 17, "proto" : "17", "flows" : ' . $udp . ', "packets" : 10, "bytes" : 1000, "pps" : 1, "bps" : 800, "bpp" : 100}',
            '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:00:10.000", "proto" : 0, "dir" : "0", "flows" : ' . ($tcp + $udp) . ', "packets" : 20, "bytes" : 2000, "pps" : 2, "bps" : 1600, "bpp" : 100}',
        ]), 'dir');
        $parts = [$part(3, 1), $part(1, 2)];
        $out = PartitionMerge::stats($parts, mergeProven(PartitionMerge::rankStats($parts, 'flows'), 2), 'proto', 'flows', 'csv');

        expect($out)->toBe(implode("\n", [
            'ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ibps,ibpp',
            '2026-09-29 00:00:00,2026-09-29 00:00:10,10.000,TCP,6,4,57.1,20,50.0,2000,50.0,2,1600,100',
            '2026-09-29 00:00:00,2026-09-29 00:00:10,10.000,UDP,17,3,42.9,20,50.0,2000,50.0,2,1600,100',
        ]) . "\n")
            ->and(MultiStatCsvParser::parse((string) $out, ['proto'])[0][0])->toMatchArray(['key' => '6', 'proto' => 'TCP', 'flows' => 4, 'bytesPct' => 50.0])
        ;
    });

    test('refuses shares when the -n of a part may have cut the list its totals come from', function (): void {
        $line = static fn (string $name, string $value): string => '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:00:10.000", "proto" : 0, "' . $name . '" : "' . $value . '", "flows" : 1, "packets" : 1, "bytes" : 100, "pps" : 0, "bps" : 80, "bpp" : 100}';
        $raw = implode("\n", [$line('srcip', '10.0.0.1'), $line('proto', '6'), $line('proto', '17')]);

        expect(PartitionMerge::parseStats($raw, 'proto', 2)['totals'])->toBeNull()
            ->and(PartitionMerge::parseStats($raw, 'proto', 3)['totals'])->toBe(['flows' => 2, 'packets' => 2, 'bytes' => 200])
            ->and(PartitionMerge::parseStats($raw, 'proto', 0)['totals'])->toBe(['flows' => 2, 'packets' => 2, 'bytes' => 200])
            ->and(PartitionMerge::stats([PartitionMerge::parseStats($raw, 'proto', 2)], [PartitionMerge::statKey(0, '10.0.0.1')], 'srcip', 'bytes', 'csv'))->toBeNull()
            ->and(PartitionMerge::stats([PartitionMerge::parseStats($raw, 'proto', 2)], [PartitionMerge::statKey(0, '10.0.0.1')], 'srcip', 'bytes', 'json'))->not->toBeNull()
        ;
    });

    test('a row whose first and last seen coincide has no rate, as in nfdump', function (): void {
        PartitionMerge::useZone(new DateTimeZone('UTC'));
        $line = '{ "first" : "2026-09-29T00:00:01.000", "last" : "2026-09-29T00:00:01.000", "proto" : 0, "dstport" : "53", "flows" : 1, "packets" : 3, "bytes" : 250, "pps" : 0, "bps" : 0, "bpp" : 83}';

        expect(PartitionMerge::stats([PartitionMerge::parseStats($line)], [PartitionMerge::statKey(0, '53')], 'dstport', 'bytes', 'json'))->toBe($line . "\n");
    });

    test('an empty merge prints what nfdump prints for no flows, where it said so or the processor took the line into its notes', function (): void {
        $empty = PartitionMerge::parseStats("No matching flows\n");
        $header = 'firstSeen,firstSeen,lastSeen,srcAddr,inPackets,inBytes,outPackets,outBytes,flows';
        $records = PartitionMerge::parseRecords($header . "\nNo matching flows\n", 1);

        expect(PartitionMerge::stats([$empty, $empty], [], 'srcip', 'bytes', 'json'))->toBe("No matching flows\n")
            ->and(PartitionMerge::stats([$empty, $empty], [], 'srcip', 'bytes', 'csv'))->toBe("No matching flows\nts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n")
            ->and(PartitionMerge::records([$records, $records], []))->toBe("firstSeen,duration,srcAddr,packets,bytes,bps,bpp,flows\nNo matching flows\n")
            ->and(PartitionMerge::saysNoMatch('', ['No matching flows', 'Execution time: 0.01 seconds']))->toBeTrue()
            ->and(PartitionMerge::saysNoMatch("ts,te\nNo matching flows\n"))->toBeTrue()
            ->and(PartitionMerge::saysNoMatch('{ "srcip" : "No matching flows" }'))->toBeFalse()
            ->and(PartitionMerge::parseStats('')['empty'])->toBeFalse()
        ;
    });

    test('only summed counters can be merged', function (): void {
        expect(fn () => PartitionMerge::rankStats([], 'bps'))->toThrow(InvalidArgumentException::class);
    });

    test('names protocols as nfdump does, and numbers it has no name for', function (): void {
        expect(PartitionMerge::protocolName(1))->toBe('ICMP')
            ->and(PartitionMerge::protocolName(47))->toBe('GRE')
            ->and(PartitionMerge::protocolName(58))->toBe('ICMP6')
            ->and(PartitionMerge::protocolName(137))->toBe('MPLS')
            ->and(PartitionMerge::protocolName(200))->toBe('200  ')
        ;
    });

    test('reads nfdump\'s local times in its zone, across a daylight saving change', function (): void {
        $berlin = new DateTimeZone('Europe/Berlin');

        expect(PartitionMerge::epochMs('2026-10-25T01:30:00.250', $berlin))->toBe(1_792_884_600_250)
            ->and(PartitionMerge::epochMs('2026-10-25 04:00:00.000', $berlin) - PartitionMerge::epochMs('2026-10-25 01:00:00.000', $berlin))->toBe(4 * 3_600_000)
            ->and(PartitionMerge::epochMs('garbage', $berlin))->toBe(0)
        ;
    });

    test('sees an hour nfdump\'s local times repeat within a day of a window', function (): void {
        $berlin = new DateTimeZone('Europe/Berlin');
        // Berlin turns 03:00 back to 02:00 at 2026-10-25 01:00 UTC and 02:00 on to 03:00 at 2026-03-29 01:00 UTC.
        $back = 1_792_890_000;

        expect(PartitionMerge::repeatsLocalTime($back - 7200, $back + 7200, $berlin))->toBeTrue()
            ->and(PartitionMerge::repeatsLocalTime($back + 80_000, $back + 90_000, $berlin))->toBeTrue()
            ->and(PartitionMerge::repeatsLocalTime($back - 90_000, $back - 87_000, $berlin))->toBeFalse()
            ->and(PartitionMerge::repeatsLocalTime($back + 90_000, $back + 100_000, $berlin))->toBeFalse()
            ->and(PartitionMerge::repeatsLocalTime(1_774_746_000 - 3600, 1_774_746_000 + 3600, $berlin))->toBeFalse()
            ->and(PartitionMerge::repeatsLocalTime($back - 7200, $back + 7200, new DateTimeZone('UTC')))->toBeFalse()
        ;
    });

    test('takes nfdump\'s zone from TZ first', function (): void {
        $before = getenv('TZ');
        putenv('TZ=:America/Chicago');

        try {
            PartitionMerge::useZone(null);
            expect(PartitionMerge::zone()->getName())->toBe('America/Chicago');
        } finally {
            $before === false ? putenv('TZ') : putenv('TZ=' . $before);
            PartitionMerge::useZone(null);
        }
    });
});

describe('merged pairs and aggregated records', function (): void {
    test('pairs keep nfdump\'s layout and add up, ranked by in plus out', function (): void {
        $part = static fn (string $rows, string $summary): array => PartitionMerge::parsePairs(implode("\n", [
            '     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows Out Byte  Out Pkt',
            $rows,
            $summary,
            'Time window: 2026-09-29 00:00:00.425 - 2026-09-29 00:59:59.918, Duration:    00:59:59.493',
            'Total records processed: 10, passed: 10, Blocks skipped: 0, Bytes read: 100',
        ]), 2);
        $a = $part('        10.0.0.1         10.0.0.2     1000       10     1        0        0', 'Summary: total flows: 10, total bytes: 5000, total packets: 50, avg bps: 1, avg pps: 1, avg bpp: 100');
        $b = $part("        10.0.0.1         10.0.0.2      500        5     2        0        0\n        10.0.0.3         10.0.0.2      300        3     1     2000       20", 'Summary: total flows: 5, total bytes: 2800, total packets: 28, avg bps: 1, avg pps: 1, avg bpp: 100');

        expect(PartitionMerge::pairs([$a, $b], mergeProven(PartitionMerge::rankFlows([$a, $b], 'bytes'), 2)))->toBe(implode("\n", [
            '     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows',
            '        10.0.0.3         10.0.0.2      300        3     1',
            '        10.0.0.1         10.0.0.2     1500       15     3',
            'Summary: total flows: 15, total bytes: 7800, total packets: 78',
            'Time window: 2026-09-29 00:00:00.425 - 2026-09-29 00:59:59.918',
            'Total records processed: 20, passed: 20, Blocks skipped: 0, Bytes read: 200',
        ]) . "\n");
    });

    test('a lookup part keeps only the keys it looked up, however many lines its filter matched', function (): void {
        $pairs = implode("\n", [
            '     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows Out Byte  Out Pkt',
            '        10.0.0.1         10.0.0.2     1000       10     1        0        0',
            '        10.0.0.1         10.0.0.3      500        5     1        0        0',
            '        10.0.0.4         10.0.0.2      300        3     1        0        0',
        ]);
        $records = implode("\n", [
            'firstSeen,firstSeen,lastSeen,srcAddr,packets,bytes,outPackets,outBytes,flows',
            '2026-09-29 00:00:01.000,1790640001.000,1790640011.000,10.0.0.1,10,1000,0,0,2',
            '2026-09-29 00:00:02.000,1790640002.000,1790640012.000,10.0.0.5,5,600,0,0,1',
        ]);

        expect(array_keys(PartitionMerge::parsePairs($pairs, 2)['lines']))->toHaveCount(3)
            ->and(array_keys(PartitionMerge::parsePairs($pairs, 2, ["10.0.0.1\x0010.0.0.3\x00" => true])['lines']))->toBe(["10.0.0.1\x0010.0.0.3\x00"])
            ->and(array_keys(PartitionMerge::parseRecords($records, 1)['lines']))->toHaveCount(2)
            ->and(array_keys(PartitionMerge::parseRecords($records, 1, ["10.0.0.5\x00" => true])['lines']))->toBe(["10.0.0.5\x00"])
        ;
    });

    test('aggregated records take the earliest first seen, the latest last seen, and nfdump\'s rates', function (): void {
        $header = 'firstSeen,firstSeen,lastSeen,srcAddr,packets,bytes,outPackets,outBytes,flows';
        $a = PartitionMerge::parseRecords($header . "\n2026-09-29 00:00:01.000,1790640001.000,1790640011.000,10.0.0.1,10,1000,0,0,2", 1);
        $b = PartitionMerge::parseRecords($header . "\n2026-09-29 00:05:00.000,1790640300.000,1790640321.000,10.0.0.1,5,600,0,0,1", 1);

        expect(PartitionMerge::records([$a, $b], mergeProven(PartitionMerge::rankFlows([$a, $b], 'bytes'), 1)))->toBe(
            "firstSeen,duration,srcAddr,packets,bytes,bps,bpp,flows\n2026-09-29 00:00:01.000,320.000,10.0.0.1,15,1600,40,106,3\n"
        );
    });

    test('lookups filter by the listed values, networks with their mask, ICMP type.code as nfdump stores it', function (): void {
        expect(PartitionMerge::inList('srcip', ['10.0.0.1', '2001:db8::1']))->toBe('src ip in [10.0.0.1 2001:db8::1]')
            ->and(PartitionMerge::inList('dstip', ['10.1.2.0'], '24'))->toBe('dst ip in [10.1.2.0/24]')
            ->and(PartitionMerge::inList('ip', ['10.0.0.1']))->toBe('ip in [10.0.0.1]')
            ->and(PartitionMerge::inList('dstport', ['443', '8.0']))->toBe('dst port in [443 2048]')
            ->and(PartitionMerge::inList('as', ['64512']))->toBe('as in [64512]')
            ->and(PartitionMerge::inList('proto', ['6', '17']))->toBe('(proto 6 or proto 17)')
            ->and(PartitionMerge::keyFields(["10.0.0.1\x0010.0.0.2\x00", "10.0.0.1\x0010.0.0.3\x00"]))->toBe([['10.0.0.1'], ['10.0.0.2', '10.0.0.3']])
        ;
    });
});

// nfcapd-written trees (sizes in $trees): main, wide (6 sources), bidir (40 % v9 with out counters),
// and gap, a copy of main without the MERGE_GAPS files.
beforeAll(function (): void {
    if (!FixtureCaptures::available()) {
        return;
    }
    $trees = ['main' => [['gw', 'dmz'], MERGE_FILES + 2, 400, 7, 0], 'wide' => [MERGE_WIDE, 14, 60, 7, 0], 'bidir' => [['gw', 'dmz'], MERGE_FILES, 300, 11, 40]];
    foreach ($trees as $name => [$sources, $files, $flows, $seed, $outPercent]) {
        $root = sys_get_temp_dir() . '/nfsen-p4-captures-' . $name . '-' . bin2hex(random_bytes(4));
        FixtureCaptures::build($root, 'live', $sources, MERGE_BASE, $files, $flows, $seed, outPercent: $outPercent);
        file_put_contents(sys_get_temp_dir() . '/nfsen-p4-captures-' . $name . '.root', $root);
    }

    $gap = sys_get_temp_dir() . '/nfsen-p4-captures-gap-' . bin2hex(random_bytes(4));
    FixtureCaptures::copy(mergeTree('main'), $gap);
    foreach (MERGE_GAPS as $source => $intervals) {
        foreach ($intervals as $interval) {
            unlink($gap . '/live/' . $source . '/' . mergeRelPath($interval));
        }
    }
    file_put_contents(sys_get_temp_dir() . '/nfsen-p4-captures-gap.root', $gap);
});

afterAll(function (): void {
    foreach (['main', 'wide', 'bidir', 'gap'] as $name) {
        $pointer = sys_get_temp_dir() . '/nfsen-p4-captures-' . $name . '.root';
        if (is_file($pointer)) {
            FixtureCaptures::remove((string) file_get_contents($pointer));
            unlink($pointer);
        }
    }
});

/**
 * A processor class that counts its runs and keeps the -R and -n of each, to tell the passes apart.
 */
function mergeCounting(): Nfdump {
    return new class extends Nfdump {
        /** @var list<array{string, string, string}> -R, -n and the filter of every run */
        public static array $runs = [];

        private string $range = '';

        private string $limit = '';

        public function setOption(string $option, $value): void {
            if ($option === '-n') {
                $this->limit = (string) $value;
            }
            if ($option === '-R') {
                $this->range = is_string($value) ? $value : (string) json_encode($value);
            }
            parent::setOption($option, $value);
        }

        public function setFilter(string $filter): void {
            self::$runs[] = [$this->range, $this->limit, $filter];
            parent::setFilter($filter);
        }
    };
}

describe('split runs over nfcapd captures give the rows of one run', function (): void {
    beforeEach(function (): void {
        if (!FixtureCaptures::available()) {
            $this->markTestSkipped('nfdump and nfcapd are not installed here');
        }
        mergeUseCaptures(mergeTree('main'));
    });

    test('for every statistic of the catalog, in json, over random slice counts, each part listing its share of the line budget', function (): void {
        srand(29_09);
        $unsupported = StatisticCatalog::unsupported();
        Config::$processorClass = mergeCounting();
        $runs = Config::$processorClass::class;
        $split = 0;
        foreach (StatisticCatalog::all() as $i => $entry) {
            $element = $entry['value'];
            if ($element === 'record' || isset($unsupported[$element])) {
                continue;
            }
            $order = PartitionMerge::SUMMED_ORDERS[$i % 3];
            $n = [1, 5, 10, 20, 50, 100][random_int(0, 5)];
            $parts = random_int(2, 8);
            $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, $n, handle: 'p4-test');
            $case = "{$element}/{$order} top {$n} in {$parts} parts";

            $single = $query->run();
            $runs::$runs = [];
            $merged = $query->runPartitioned('stats', parts: $parts);
            $limits = array_column($runs::$runs, 1);
            $split += $merged->parts > 1 ? 1 : 0;

            mergeSameRows(mergeLines($single->rawOutput), mergeLines($merged->rawOutput), mergeStatValue($order, 'json'), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, 0)->run()->rawOutput), $case);
            expect($merged->notes)->toBe(array_values(array_filter($merged->notes, static fn (string $note): bool => !str_starts_with($note, 'Part '))), $case);
            if ($merged->parts > 1) {
                expect($merged->command)->toBe($single->command, $case)
                    ->and($merged->partCommands)->toHaveCount($merged->parts)
                ;
            }
            if ($merged->parts > 1) {
                // The first is the query's own processor, whose command the result shows.
                expect(array_slice($limits, 1, $merged->parts))->toBe(array_fill(0, $merged->parts, (string) PartitionPlanner::listLimit($n, $merged->parts)), $case);
            }
        }

        expect($split)->toBeGreaterThan(40);
    });

    test('for the csv statistics of the Overview and the side panels, with nfdump\'s shares, for every order', function (): void {
        srand(2_909);
        foreach (['srcip', 'dstip', 'srcport', 'dstport', 'proto', 'srcas', 'dstas', 'as', 'inif', 'outif'] as $element) {
            foreach (PartitionMerge::SUMMED_ORDERS as $order) {
                $n = [1, 10, 20, 50][random_int(0, 3)];
                $parts = random_int(2, 8);
                $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, $n, output: 'csv');

                $single = $query->run();
                $merged = $query->runPartitioned('talkers-panel', parts: $parts);

                expect($merged->parts)->toBeGreaterThan(1, "{$element}/{$order}");
                mergeSameRows(mergeLines($single->rawOutput), mergeLines($merged->rawOutput), mergeStatValue($order, 'csv'), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, 0, output: 'csv')->run()->rawOutput), "{$element}/{$order} top {$n} in {$parts} parts");
                if ($single->rawOutput === $merged->rawOutput) {
                    expect($query->statRows($merged))->toBe($query->statRows($single));
                }
            }
        }
    });

    test('for the Overview exact run: keys, counters and shares, over random slice counts', function (): void {
        srand(290_926);
        foreach (['talkers', 'ports', 'protocols', 'asns', 'interfaces'] as $tab) {
            foreach (['src', 'dst'] as $dir) {
                $order = TopNRepository::ORDER_BY[random_int(0, 2)];
                $in = ['start' => MERGE_BASE, 'end' => MERGE_BASE + MERGE_FILES * 300, 'live' => false, 'sources' => ['gw', 'dmz'], 'profile' => 'live', 'protocol' => 'any', 'tab' => $tab, 'dir' => $dir, 'limit' => [10, 20, 50][random_int(0, 2)], 'order' => $order];
                $query = OverviewPage::exactQuery($in, 'p4-test');
                $parts = random_int(2, 8);
                $rows = static fn (QueryResult $result): array => array_map(json_encode(...), OverviewPage::exactRows($query->statRows($result)));
                $value = static fn (string $row): int => (int) json_decode($row, true)[$order];

                $merged = $query->runPartitioned('overview-topn', parts: $parts);

                expect($merged->parts)->toBeGreaterThan(1, "{$tab} {$dir}");
                mergeSameRows($rows($query->run()), $rows($merged), $value, static function () use ($in): array {
                    $complete = OverviewPage::exactQuery(['limit' => 0] + $in, 'p4-test');

                    return array_map(json_encode(...), OverviewPage::exactRows($complete->statRows($complete->run())));
                }, "{$tab} {$dir} {$order} top {$in['limit']} in {$parts} parts");
            }
        }
    });

    test('for Flow Records aggregated with -A, and by one process for the 5-tuple or a bidirectional aggregation', function (): void {
        srand(909);
        $specs = [
            ['srcip' => 'srcip'],
            ['dstip' => 'dstip'],
            ['proto' => true, 'dstport' => true],
            ['srcip' => 'srcip4', 'srcipPrefix' => '24', 'dstip' => 'dstip4', 'dstipPrefix' => '16'],
            ['proto' => true, 'srcport' => true],
            ['srcip' => 'srcip', 'dstip' => 'dstip', 'dstport' => true],
        ];
        foreach ($specs as $spec) {
            foreach (PartitionMerge::SUMMED_ORDERS as $order) {
                $n = [1, 10, 20][random_int(0, 2)];
                $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', $order, $n, aggregation: $spec);
                $case = Nfdump::buildAggregationString($spec) . "/{$order} top {$n}";
                $column = ['flows' => -1, 'packets' => -5, 'bytes' => -4][$order];
                $value = static fn (string $line): int => (int) array_slice(explode(',', $line), $column, 1)[0];

                $single = $query->run();
                $merged = $query->runPartitioned('stats', parts: random_int(2, 8));

                expect($merged->parts)->toBeGreaterThan(1, $case);
                mergeSameRows(mergeLines($single->rawOutput), mergeLines($merged->rawOutput), $value, static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', $order, 0, aggregation: $spec)->run()->rawOutput), $case);
            }
        }

        expect(new StatsQuery(mergeWindow(), ['gw'], 'live', 'record', 'bytes', 10)->splittable())->toBeFalse()
            ->and(new StatsQuery(mergeWindow(), ['gw'], 'live', 'record', 'bytes', 10, aggregation: ['bidirectional' => true])->splittable())->toBeFalse()
            ->and(new StatsQuery(mergeWindow(), ['gw'], 'live', 'srcip', 'bps', 10)->splittable())->toBeFalse()
            ->and(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', 'bytes', 10)->runPartitioned('stats', parts: 4)->parts)->toBe(1)
        ;
    });

    test('for Conversations pairs, every grouping, direction and metric', function (): void {
        srand(29);
        foreach (MatrixQuery::GROUPS as $group) {
            foreach (MatrixQuery::DIRECTIONS as $direction) {
                if ($group === 'port' && $direction === 'both') {
                    continue;
                }
                foreach (['bytes', 'packets'] as $metric) {
                    $topN = [5, 20, 50][random_int(0, 2)];
                    $query = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', $metric, $topN, groupBy: $group, direction: $direction);
                    $case = "{$group} {$direction} {$metric} top {$topN}";
                    $rows = static fn (QueryResult $result): array => array_map(static fn (array $row): string => json_encode($row), $result->rows);
                    $value = static fn (string $row): int => (int) json_decode($row, true)[$metric === 'packets' ? 'ipkt' : 'ibyt'];

                    $single = $query->run();
                    $merged = $query->runPartitioned(parts: random_int(2, 8));

                    expect($merged->parts)->toBeGreaterThan(1, $case);
                    mergeSameRows($rows($single), $rows($merged), $value, static function () use ($metric, $group, $rows): array {
                        $complete = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', $metric, MatrixQuery::MAX_TOP_N, groupBy: $group, direction: 'forward');
                        $processor = $complete->processor();
                        $processor->setOption('-n', 0);

                        return $rows($complete->run($processor));
                    }, $case);
                    expect(ConversationPayload::totalsFrom(NfdumpSummary::fromTextFooter((string) $merged->rawOutput)))
                        ->toBe(ConversationPayload::totalsFrom(NfdumpSummary::fromTextFooter((string) $single->rawOutput)), $case)
                    ;
                }
            }
        }
    });

    test('a part hands back nfdump\'s output undecoded, and fails when nfdump does', function (): void {
        $part = static function (string $filter): Nfdump {
            $nfdump = new Nfdump();
            $nfdump->keepRawOutput();
            $nfdump->setOption('-M', mergeTree('main') . '/live/gw:dmz');
            $nfdump->setOption('-R', '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290025');
            $nfdump->setOption('-n', 0);
            $nfdump->setOption('-o', 'json');
            $nfdump->setOption('-s', 'dstport/flows');
            $nfdump->setFilter($filter);

            return $nfdump;
        };
        $output = $part('')->execute();

        expect($output['decoded'])->toBe([])
            ->and(count(PartitionMerge::parseStats($output['rawOutput'])['lines']))->toBeGreaterThan(100)
            ->and($part('proto 47')->execute()['rawOutput'])->toBe("No matching flows\n")
            ->and(fn () => $part('src port >')->execute())->toThrow(NfdumpException::class)
        ;
    });

    test('with no matching flows, as nfdump says it', function (): void {
        $notes = static fn (QueryResult $result): array => array_values(array_filter($result->notes, static fn (string $note): bool => !str_starts_with($note, 'Execution time')));
        $queries = [
            'json' => new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'srcip', 'bytes', 10, filter: 'proto 47'),
            'csv' => new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'dstport', 'flows', 10, filter: 'proto 47', output: 'csv'),
            '-A' => new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', 'bytes', 10, filter: 'proto 47', aggregation: ['srcip' => 'srcip']),
            'pairs' => new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'bytes', 20, filter: 'proto 47', direction: 'forward'),
        ];
        foreach ($queries as $case => $query) {
            $single = $query->run();
            $merged = $query instanceof MatrixQuery ? $query->runPartitioned(parts: 4) : $query->runPartitioned('stats', parts: 4);

            expect($merged->parts)->toBe(4, $case)
                ->and($merged->rows)->toBe($single->rows, $case)
                ->and($notes($merged))->toBe($notes($single), $case)
            ;
            if (!$query instanceof MatrixQuery) {
                expect($merged->rawOutput)->toBe($single->rawOutput, $case);
            }
        }

        expect($notes($queries['json']->run()))->toBe(['No matching flows']);
    });

    test('when the parts cannot prove the top: a lookup of the missing keys, or one process', function (): void {
        Config::$processorClass = mergeCounting();
        $runs = Config::$processorClass::class;
        $seen = ['looked up' => 0, 'one process' => 0];
        $cases = [['srcip', 'json'], ['ip', 'json'], ['dstport', 'csv'], ['srctos', 'json'], ['inif', 'csv'], ['srcmask', 'json'], ['nhip', 'json'], ['dstas', 'csv']];
        foreach ($cases as [$element, $output]) {
            foreach ([1, 2, 3, 5] as $n) {
                foreach ([$n, $n + 1] as $fetch) {
                    PartitionPlanner::useFetchLimit($fetch);
                    $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, 'flows', $n, output: $output);
                    $single = $query->run();
                    $runs::$runs = [];
                    $merged = $query->runPartitioned('stats', parts: 6);
                    $case = "{$element} top {$n}, parts listing {$fetch}";

                    // After the query's own processor and the six parts.
                    $second = array_slice($runs::$runs, 7);
                    $seen['looked up'] += count(array_filter($second, static fn (array $run): bool => $run[1] === '0' && str_contains($run[2], ' in ['))) > 0 ? 1 : 0;
                    $seen['one process'] += $merged->parts === 1 ? 1 : 0;
                    mergeSameRows(mergeLines($single->rawOutput), mergeLines($merged->rawOutput), mergeStatValue('flows', $output), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, 'flows', 0, output: $output)->run()->rawOutput), $case);
                }
            }
        }

        expect($seen['looked up'])->toBeGreaterThan(0)
            ->and($seen['one process'])->toBeGreaterThan(0)
        ;

        PartitionPlanner::useFetchLimit(10);
        $pairs = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'bytes', 10);
        expect(array_column($pairs->runPartitioned(parts: 5)->rows, 'ibyt'))->toBe(array_column($pairs->run()->rows, 'ibyt'));
    });
});

describe('split runs over captures with out counters, which nfdump ranks records and pairs by', function (): void {
    beforeEach(function (): void {
        if (!FixtureCaptures::available()) {
            $this->markTestSkipped('nfdump and nfcapd are not installed here');
        }
        mergeUseCaptures(mergeTree('bidir'));
    });

    test('give the rows of one run: element statistics, aggregated Flow Records and pairs', function (): void {
        srand(4_040);
        // The out counters reorder the pairs: by in bytes alone the top would read in order.
        $ibyt = array_map(intval(...), array_column(new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'bytes', 20, direction: 'forward')->run()->rows, 'ibyt'));
        $sorted = $ibyt;
        rsort($sorted);
        expect($ibyt)->not->toBe($sorted);

        foreach ([['srcip', 'bytes', 'json', 10], ['dstip', 'packets', 'csv', 20], ['dstport', 'bytes', 'json', 10], ['proto', 'flows', 'csv', 5], ['srcas', 'bytes', 'json', 20]] as [$element, $order, $output, $n]) {
            $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, $n, output: $output);
            $parts = random_int(2, 8);
            $merged = $query->runPartitioned('stats', parts: $parts);

            expect($merged->parts)->toBeGreaterThan(1);
            mergeSameRows(mergeLines($query->run()->rawOutput), mergeLines($merged->rawOutput), mergeStatValue($order, $output), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, 0, output: $output)->run()->rawOutput), "{$element}/{$order} top {$n} in {$parts} parts");
        }

        $specs = [
            ['srcip' => 'srcip', 'dstip' => 'dstip'],
            ['proto' => true, 'dstport' => true],
            ['srcip' => 'srcip4', 'srcipPrefix' => '24'],
            ['dstip' => 'dstip'],
        ];
        foreach ($specs as $spec) {
            foreach (PartitionMerge::SUMMED_ORDERS as $order) {
                $n = [1, 10, 20][random_int(0, 2)];
                $parts = random_int(2, 8);
                $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', $order, $n, aggregation: $spec);
                $case = Nfdump::buildAggregationString($spec) . "/{$order} top {$n} in {$parts} parts";
                $merged = $query->runPartitioned('stats', parts: $parts);

                expect($merged->parts)->toBeGreaterThan(1, $case);
                mergeSameRows(mergeLines($query->run()->rawOutput), mergeLines($merged->rawOutput), mergeRecordValue(mergeRecordRanking($spec, $order), count(explode(',', Nfdump::buildAggregationString($spec)))), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', $order, 0, aggregation: $spec)->run()->rawOutput), $case);
            }
        }

        foreach (MatrixQuery::GROUPS as $group) {
            foreach (MatrixQuery::DIRECTIONS as $direction) {
                if ($group === 'port' && $direction === 'both') {
                    continue;
                }
                foreach (['bytes', 'packets'] as $metric) {
                    $topN = [5, 20, 50][random_int(0, 2)];
                    $parts = random_int(2, 8);
                    $query = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', $metric, $topN, groupBy: $group, direction: $direction);
                    $rows = static fn (QueryResult $result): array => array_map(static fn (array $row): string => json_encode($row), $result->rows);
                    $merged = $query->runPartitioned(parts: $parts);

                    expect($merged->parts)->toBeGreaterThan(1);
                    mergeSameRows($rows($query->run()), $rows($merged), mergePairValue(mergePairRanking($query)), static function () use ($metric, $group, $rows): array {
                        $complete = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', $metric, MatrixQuery::MAX_TOP_N, groupBy: $group, direction: 'forward');
                        $processor = $complete->processor();
                        $processor->setOption('-n', 0);

                        return $rows($complete->run($processor));
                    }, "{$group} {$direction} {$metric} top {$topN} in {$parts} parts");
                }
            }
        }
    });

    test('when the parts cannot prove the top of aggregated records or pairs: a lookup of the missing keys, or one process', function (): void {
        $specs = [
            ['proto' => true, 'dstport' => true],
            ['srcip' => 'srcip4', 'srcipPrefix' => '24'],
            ['srcip' => 'srcip', 'dstip' => 'dstip'],
        ];
        $rankings = [];
        foreach ($specs as $i => $spec) {
            $rankings[$i] = mergeRecordRanking($spec, 'bytes');
        }
        $pairQueries = [];
        foreach (['ip', 'net24', 'port'] as $group) {
            foreach ([1, 3, 5] as $n) {
                $pairQueries[] = [$query = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'bytes', $n, groupBy: $group, direction: 'forward'), mergePairRanking($query)];
            }
        }
        Config::$processorClass = mergeCounting();
        $runs = Config::$processorClass::class;
        $seen = ['records looked up' => 0, 'pairs looked up' => 0, 'one process' => 0];
        $lookedUp = static function () use ($runs): bool {
            // After the query's own processor and the six parts.
            return array_filter(array_slice($runs::$runs, 7), static fn (array $run): bool => $run[1] === '0' && str_contains($run[2], ' in [')) !== [];
        };

        foreach ($specs as $i => $spec) {
            foreach ([1, 3, 5] as $n) {
                foreach ([$n, $n + 1] as $fetch) {
                    PartitionPlanner::useFetchLimit($fetch);
                    $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', 'bytes', $n, aggregation: $spec);
                    $single = $query->run();
                    $runs::$runs = [];
                    $merged = $query->runPartitioned('stats', parts: 6);
                    $seen['records looked up'] += $lookedUp() ? 1 : 0;
                    $seen['one process'] += $merged->parts === 1 ? 1 : 0;
                    mergeSameRows(mergeLines($single->rawOutput), mergeLines($merged->rawOutput), mergeRecordValue($rankings[$i], count(explode(',', Nfdump::buildAggregationString($spec)))), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', 'bytes', 0, aggregation: $spec)->run()->rawOutput), Nfdump::buildAggregationString($spec) . " top {$n}, parts listing {$fetch}");
                }
            }
        }
        foreach ($pairQueries as [$query, $ranking]) {
            foreach ([$query->fetchLimit(), $query->fetchLimit() + 1] as $fetch) {
                PartitionPlanner::useFetchLimit($fetch);
                $rows = static fn (QueryResult $result): array => array_map(static fn (array $row): string => json_encode($row), $result->rows);
                $single = $query->run();
                $runs::$runs = [];
                $merged = $query->runPartitioned(parts: 6);
                $seen['pairs looked up'] += $lookedUp() ? 1 : 0;
                $seen['one process'] += $merged->parts === 1 ? 1 : 0;
                mergeSameRows($rows($single), $rows($merged), mergePairValue($ranking), static function () use ($query, $rows): array {
                    $processor = $query->processor();
                    $processor->setOption('-n', 0);

                    return $rows($query->run($processor));
                }, "{$query->groupBy()} top {$query->topN}, parts listing {$fetch}");
            }
        }

        expect($seen['records looked up'])->toBeGreaterThan(0)
            ->and($seen['pairs looked up'])->toBeGreaterThan(0)
        ;
    });
});

describe('split runs over captures with gaps, the first source\'s too', function (): void {
    beforeEach(function (): void {
        if (!FixtureCaptures::available()) {
            $this->markTestSkipped('nfdump and nfcapd are not installed here');
        }
    });

    // nfdump -M reads nothing when its first directory lacks the first -R file, and says so only on stderr.
    test('read every file there is, as one process or split, whichever source holds the first file of a slice', function (): void {
        srand(3_5);
        $flows = static function (string $tree): int {
            mergeUseCaptures(mergeTree($tree));

            return array_sum(array_column(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'proto', 'flows', 0)->run()->rows, 'flows'));
        };
        $removed = 0;
        foreach (MERGE_GAPS as $source => $intervals) {
            foreach ($intervals as $interval) {
                $removed += FixtureCaptures::flows(mergeTree('main') . '/live/' . $source . '/' . mergeRelPath($interval));
            }
        }

        expect($removed)->toBeGreaterThan(0)
            ->and($flows('gap'))->toBe($flows('main') - $removed)
        ;

        mergeUseCaptures(mergeTree('gap'));
        $led = 0;
        $count = static function (QueryResult $result) use (&$led): void {
            foreach ($result->partCommands as $command) {
                $led += str_contains($command, "/live/dmz:gw'") ? 1 : 0;
            }
        };
        foreach ([['srcip', 'bytes', 'json', 10], ['dstport', 'flows', 'csv', 20], ['proto', 'packets', 'json', 5], ['srcas', 'bytes', 'json', 10], ['dstip', 'packets', 'csv', 50], ['inif', 'flows', 'json', 5]] as [$element, $order, $output, $n]) {
            foreach ([2, 3, 4, 5] as $parts) {
                $query = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, $n, output: $output);
                $merged = $query->runPartitioned('stats', parts: $parts);
                $count($merged);

                expect($merged->parts)->toBeGreaterThan(1);
                mergeSameRows(mergeLines($query->run()->rawOutput), mergeLines($merged->rawOutput), mergeStatValue($order, $output), static fn (): array => mergeLines(new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', $element, $order, 0, output: $output)->run()->rawOutput), "{$element}/{$order} top {$n} in {$parts} parts");
            }
        }

        $pairs = new MatrixQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'bytes', 20, direction: 'forward');
        $records = new StatsQuery(mergeWindow(), ['gw', 'dmz'], 'live', 'record', 'bytes', 10, aggregation: ['srcip' => 'srcip', 'dstip' => 'dstip']);
        foreach ([2, 4, 5] as $parts) {
            $mergedPairs = $pairs->runPartitioned(parts: $parts);
            $mergedRecords = $records->runPartitioned('stats', parts: $parts);
            $count($mergedPairs);
            $count($mergedRecords);

            expect(array_column($mergedPairs->rows, 'ibyt'))->toBe(array_column($pairs->run()->rows, 'ibyt'))
                ->and(mergeLines($mergedRecords->rawOutput))->toBe(mergeLines($records->run()->rawOutput))
            ;
        }

        expect($led)->toBeGreaterThan(0);
    });
});

describe('split runs over six sources, with files after the window', function (): void {
    beforeEach(function (): void {
        if (!FixtureCaptures::available()) {
            $this->markTestSkipped('nfdump and nfcapd are not installed here');
        }
        mergeUseCaptures(mergeTree('wide'), MERGE_WIDE);
    });

    // A slice of one interval is six files; nfdump -M reads a lone -R file up to the end of its day.
    test('read exactly their slices, one interval ones too', function (): void {
        srand(1_4);
        $window = TimeWindow::raw(MERGE_BASE, MERGE_BASE + 12 * 300 - 1);
        $single = 0;
        foreach ([['proto', 'flows', 5], ['srcip', 'bytes', 10], ['dstport', 'packets', 10], ['dstas', 'flows', 20]] as [$element, $order, $n]) {
            foreach ([6, 7, 8] as $parts) {
                $query = new StatsQuery($window, MERGE_WIDE, 'live', $element, $order, $n);
                $merged = $query->runPartitioned('stats', parts: $parts);
                $case = "{$element}/{$order} top {$n} in {$parts} parts";
                foreach ($merged->partCommands as $command) {
                    $single += preg_match("#-R '?([^' ]+):([^' ]+)#", $command, $m) === 1 && $m[1] === $m[2] ? 1 : 0;
                }

                expect($merged->parts)->toBe($parts, $case);
                mergeSameRows(mergeLines($query->run()->rawOutput), mergeLines($merged->rawOutput), mergeStatValue($order, 'json'), static fn (): array => mergeLines(new StatsQuery($window, MERGE_WIDE, 'live', $element, $order, 0)->run()->rawOutput), $case);
            }
        }
        $pairs = new MatrixQuery($window, MERGE_WIDE, 'live', 'bytes', 20, direction: 'forward');
        $records = new StatsQuery($window, MERGE_WIDE, 'live', 'record', 'bytes', 10, aggregation: ['srcip' => 'srcip', 'dstip' => 'dstip']);

        expect($single)->toBeGreaterThan(0)
            ->and(array_column($pairs->runPartitioned(parts: 8)->rows, 'ibyt'))->toBe(array_column($pairs->run()->rows, 'ibyt'))
            ->and(mergeLines($records->runPartitioned('stats', parts: 8)->rawOutput))->toBe(mergeLines($records->run()->rawOutput))
        ;
    });
});
