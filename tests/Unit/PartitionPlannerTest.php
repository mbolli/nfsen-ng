<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\PartitionMerge;
use mbolli\nfsen_ng\query\PartitionPlanner;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\store\Database;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

/** 2026-09-28 00:00 UTC. */
const PARTITION_BASE = 1_790_553_600;

/**
 * NfcapdFiles::list() rows of $intervals 5-minute intervals per source, $size bytes each.
 *
 * @param list<string> $sources
 *
 * @return list<array{ts: int, path: string, relPath: string, source: string, size: int}>
 */
function partitionFiles(int $intervals, array $sources = ['gw'], int $size = 100_000_000, int $from = PARTITION_BASE): array {
    $files = [];
    for ($i = 0; $i < $intervals; ++$i) {
        $ts = $from + $i * 300;
        foreach ($sources as $source) {
            $rel = gmdate('Y/m/d', $ts) . '/nfcapd.' . gmdate('YmdHi', $ts);
            $files[] = ['ts' => $ts, 'path' => '/data/' . $source . '/' . $rel, 'relPath' => $rel, 'source' => $source, 'size' => $size];
        }
    }

    return $files;
}

/** A processor that answers with its own options, after $seconds in a coroutine. */
function partitionProcessor(float $seconds = 0.0, ?Throwable $throw = null): Processor {
    return new class($seconds, $throw) implements Processor {
        public static int $running = 0;

        public static int $peak = 0;

        /** @var array<string, mixed> */
        public array $options = [];

        public string $filter = '';

        public function __construct(private readonly float $seconds, private readonly ?Throwable $throw) {}

        public function setOption(string $option, $value): void {
            $this->options[$option] = $value;
        }

        public function setFilter(string $filter): void {
            $this->filter = $filter;
        }

        public function setQueryHandle(string $handle): void {}

        public function setProfile(string $profile): void {}

        public function execute(): array {
            self::$peak = max(self::$peak, ++self::$running);

            try {
                if ($this->seconds > 0) {
                    Coroutine::getCid() > 0 ? Coroutine::usleep((int) ($this->seconds * 1e6)) : usleep((int) ($this->seconds * 1e6));
                }
                if ($this->throw !== null) {
                    throw $this->throw;
                }
            } finally {
                --self::$running;
            }

            return [
                'command' => 'nfdump -R ' . $this->options['-R'] . ' -n ' . $this->options['-n'],
                'rawOutput' => json_encode(['range' => $this->options['-R'], 'limit' => $this->options['-n'], 'filter' => $this->filter]),
                'decoded' => [],
                'exitCode' => 0,
            ];
        }
    };
}

/**
 * PartitionPlanner::run() for a top 1 over parts whose keys $lines scripts: key => value by part
 * index, limit and lookup filter (null in the first pass). A part listing $limit or more keys is
 * truncated. The key filter names the keys as `key in [..]`.
 *
 * @param null|Closure(int, int, ?string): array<string, int>    $lines     default: 'k' => 1 in every part, complete
 * @param null|Closure(list<string>): ?string                    $filter
 * @param list<array{range: string, limit: int, filter: string}> $seen      every part run, in order
 * @param list<array{int, array{int, int}, bool, bool}>          $splits    what onSplit heard
 * @param null|Closure(): Processor                              $processor
 * @param list<null|array<string, true>>                         $only      the keys each parse was told to keep
 */
function partitionRun(array $files, ?Closure $lines = null, ?int $parts = null, array &$seen = [], array &$splits = [], ?Closure $cancelled = null, ?Closure $processor = null, int &$singles = 0, ?Closure $filter = null, int $limit = 1000, int $n = 1, array &$only = []): QueryResult {
    $window = TimeWindow::raw(PARTITION_BASE, PARTITION_BASE + 86_400);
    $processor ??= static fn (): Processor => partitionProcessor();
    $lines ??= static fn (int $index, int $limit, ?string $filter): array => ['k' => 1];

    return PartitionPlanner::run(
        kind: 'stats',
        files: $files,
        n: $n,
        limit: $limit,
        handle: 'ctx-p4',
        single: static function () use ($window, &$singles): QueryResult {
            ++$singles;

            return new QueryResult([['single' => true]], 'nfdump single', '', 0.1, $window);
        },
        build: static function (array $part, int $limit, ?string $keys) use ($processor): Processor {
            $p = $processor();
            $p->setOption('-R', $part['range']);
            $p->setOption('-n', $limit);
            $p->setFilter($keys ?? '');

            return $p;
        },
        parse: static function (array $part, array $output, int $limit, ?array $keys = null) use (&$seen, &$only, $lines): array {
            $run = json_decode($output['rawOutput'], true);
            $seen[] = $run;
            $only[] = $keys;
            $values = $lines($part['index'], $limit, $run['filter'] === '' ? null : $run['filter']);

            return ['lines' => $values, 'known' => [], 'truncated' => $limit > 0 && count($values) >= $limit];
        },
        rank: static fn (array $data): array => array_values(array_map(static fn (array $part): array => ['values' => $part['lines'], 'truncated' => $part['truncated'], 'known' => $part['known']], $data)),
        render: static fn (array $data, array $top, array $meta): QueryResult => new QueryResult(
            array_values(array_map(static fn (array $part): array => $part['lines'], $data)),
            implode("\n", $meta['commands']),
            '',
            0.1,
            $window,
            rawOutput: implode(',', $top),
            parts: count($data),
            partCommands: $meta['commands'],
        ),
        keyFilter: $filter ?? static fn (array $keys): string => 'key in [' . implode(' ', $keys) . ']',
        onSplit: static function (int $count, Closure $sample, bool $again, bool $single) use (&$splits): void {
            $splits[] = [$count, $sample(), $again, $single];
        },
        cancelled: $cancelled,
        parts: $parts,
    );
}

/**
 * Two truncated parts listing two keys each, where the top key 'a' of part 0 has to be looked up
 * in part 1 and 'c' of part 1 in part 0: a lookup finds 'c' => 10 in part 0 and 'a' => 5 in part 1.
 *
 * @return Closure(int, int, ?string): array<string, int>
 */
function partitionLookupLines(): Closure {
    return static function (int $index, int $limit, ?string $filter): array {
        if ($filter !== null) {
            return $index === 0 ? ['c' => 10] : ['a' => 5];
        }

        return $index === 0 ? ['a' => 100, 'b' => 50] : ['c' => 90, 'd' => 40];
    };
}

function partitionFreeSlots(): void {
    foreach (NfdumpSlots::CLASSES as $class) {
        NfdumpSlots::release($class, NfdumpSlots::inUse($class));
    }
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'dmz'], 'ports' => [], 'max_stats_window' => 0],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/var/nfdump/profiles-data', 'profile' => 'live', 'max-processes' => 8],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$stateDir = '';
    Database::resetShared();
    PartitionPlanner::useFetchLimit(null);
    PartitionMerge::useZone(new DateTimeZone('UTC'));
    partitionFreeSlots();
    partitionProcessor()::$running = 0;
    partitionProcessor()::$peak = 0;
});

afterEach(function (): void {
    partitionFreeSlots();
    PartitionPlanner::useFetchLimit(null);
    PartitionMerge::useZone(null);
    Database::resetShared();
    Config::$stateDir = $this->stateDirBefore ?? '';
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('when to split', function (): void {
    test('one process below 12 files, at or below a second, or with fewer than 2 slots', function (): void {
        expect(PartitionPlanner::parts(11, 30.0, 8))->toBe(1)
            ->and(PartitionPlanner::parts(48, 1.0, 8))->toBe(1)
            ->and(PartitionPlanner::parts(48, 30.0, 1))->toBe(1)
            ->and(PartitionPlanner::parts(48, 30.0, 0))->toBe(1)
        ;
    });

    test('P is the smallest of the slots to take, a part per 6 files and 8', function (): void {
        expect(PartitionPlanner::parts(12, 1.1, 8))->toBe(2)
            ->and(PartitionPlanner::parts(17, 5.0, 8))->toBe(2)
            ->and(PartitionPlanner::parts(48, 5.0, 3))->toBe(3)
            ->and(PartitionPlanner::parts(48, 5.0, 20))->toBe(8)
            ->and(PartitionPlanner::parts(8928, 600.0, 6))->toBe(6)
        ;
    });

    test('a split leaves one slot free for another query once there are three', function (): void {
        expect(PartitionPlanner::freeSlots())->toBe(7);

        NfdumpSlots::acquireMany(5, NfdumpSlots::INTERACTIVE, 0.0);
        expect(PartitionPlanner::freeSlots())->toBe(2);

        NfdumpSlots::acquire(0.0);
        expect(PartitionPlanner::freeSlots())->toBe(2);

        NfdumpSlots::acquire(0.0);
        expect(PartitionPlanner::freeSlots())->toBe(1);

        partitionFreeSlots();
        Config::$settings = Settings::fromArray(['nfdump' => ['max-processes' => 2]]);
        expect(PartitionPlanner::freeSlots())->toBe(2);
    });

    test('the parts list 40000 keys between them, 10000 each at most, and each at least twice the rows asked for', function (): void {
        expect(PartitionPlanner::listLimit(10, 2))->toBe(10_000)
            ->and(PartitionPlanner::listLimit(10, 4))->toBe(10_000)
            ->and(PartitionPlanner::listLimit(100, 5))->toBe(8000)
            ->and(PartitionPlanner::listLimit(100, 8))->toBe(5000)
            ->and(PartitionPlanner::listLimit(2000, 8))->toBe(5000)
            ->and(PartitionPlanner::listLimit(3000, 8))->toBe(6000)
            ->and(PartitionPlanner::listLimit(10, 0))->toBe(10_000)
        ;

        PartitionPlanner::useFetchLimit(3);
        expect(PartitionPlanner::listLimit(10, 4))->toBe(3);
    });

    test('the speed-up follows the measured 1.6, 1.9 and 2.2 and rises with every process', function (): void {
        $factors = array_map(PartitionPlanner::speedup(...), range(1, 8));

        expect(PartitionPlanner::speedup(2))->toBe(1.6)
            ->and(PartitionPlanner::speedup(4))->toBe(1.9)
            ->and(PartitionPlanner::speedup(8))->toBe(2.2)
            ->and(PartitionPlanner::speedup(0))->toBe(1.0)
            ->and(PartitionPlanner::speedup(20))->toBe(2.2)
        ;
        foreach (array_slice($factors, 1) as $i => $factor) {
            expect($factor)->toBeGreaterThan($factors[$i]);
        }
    });

    test('the files are read from the floored start, as nfdump -R does', function (): void {
        $root = sys_get_temp_dir() . '/nfsen-p4-files-' . bin2hex(random_bytes(4));
        $dir = $root . '/live/gw/' . gmdate('Y/m/d', PARTITION_BASE);
        mkdir($dir, 0o777, true);
        foreach ([0, 300, 600] as $offset) {
            file_put_contents($dir . '/nfcapd.' . gmdate('YmdHi', PARTITION_BASE + $offset), 'x');
        }
        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw']], 'nfdump' => ['profiles-data' => $root, 'profile' => 'live']]);

        try {
            $files = PartitionPlanner::files(TimeWindow::raw(PARTITION_BASE + 120, PARTITION_BASE + 599), ['gw'], 'live');

            expect(array_column($files, 'ts'))->toBe([PARTITION_BASE, PARTITION_BASE + 300]);
        } finally {
            array_map(unlink(...), glob($dir . '/*') ?: []);
            for ($path = $dir; $path !== $root; $path = dirname($path)) {
                rmdir($path);
            }
            rmdir($root);
        }
    });
});

describe('time slices', function (): void {
    test('cut the files into consecutive ranges of equal size that cover every interval once', function (): void {
        $slices = PartitionPlanner::slices(partitionFiles(24, ['gw', 'dmz']), 4);
        $first = gmdate('Y/m/d', PARTITION_BASE) . '/nfcapd.';

        expect($slices)->toHaveCount(4)
            ->and(array_column($slices, 'files'))->toBe([12, 12, 12, 12])
            ->and(array_column($slices, 'index'))->toBe([0, 1, 2, 3])
            ->and($slices[0]['range'])->toBe($first . gmdate('YmdHi', PARTITION_BASE) . ':' . $first . gmdate('YmdHi', PARTITION_BASE + 5 * 300))
            ->and($slices[3]['to'])->toBe(PARTITION_BASE + 23 * 300)
        ;
        foreach (array_slice($slices, 1) as $i => $slice) {
            expect($slice['from'])->toBe($slices[$i]['to'] + 300);
        }
    });

    test('balance by size, not by count', function (): void {
        $files = partitionFiles(24);
        foreach ($files as $i => $file) {
            $files[$i]['size'] = $i < 12 ? 300 : 100;
        }

        $slices = PartitionPlanner::slices($files, 2);

        expect(array_column($slices, 'files'))->toBe([8, 16])
            ->and(array_column($slices, 'bytes'))->toBe([2400, 2400])
        ;
    });

    test('hold at least six files each, however skewed the sizes', function (): void {
        $files = partitionFiles(48);
        $files[0]['size'] = 1_000_000_000;

        expect(array_column(PartitionPlanner::slices($files, 4), 'files'))->toBe([6, 14, 14, 14])
            ->and(PartitionPlanner::slices(partitionFiles(8), 4))->toHaveCount(1)
            ->and(PartitionPlanner::slices(partitionFiles(13), 8))->toHaveCount(2)
            ->and(PartitionPlanner::slices(partitionFiles(3, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l']), 6))->toHaveCount(3)
        ;
        foreach ([[48, 8], [30, 4], [100, 8], [13, 2]] as [$count, $parts]) {
            $skewed = partitionFiles($count);
            foreach ($skewed as $i => $file) {
                $skewed[$i]['size'] = ($i * 7919) % 97 + ($i % 11 === 0 ? 5000 : 1);
            }
            $slices = PartitionPlanner::slices($skewed, $parts);

            expect(min(array_column($slices, 'files')))->toBeGreaterThanOrEqual(PartitionPlanner::MIN_FILES_PER_PART)
                ->and(array_sum(array_column($slices, 'files')))->toBe($count)
            ;
        }
    });

    // nfdump -M reads a lone -R file as that file up to the end of its directory.
    test('name a slice of one interval by its file as first and last', function (): void {
        $slices = PartitionPlanner::slices(partitionFiles(2, ['a', 'b', 'c', 'd', 'e', 'f']), 2);
        $file = gmdate('Y/m/d', PARTITION_BASE) . '/nfcapd.' . gmdate('YmdHi', PARTITION_BASE);

        expect(array_column($slices, 'files'))->toBe([6, 6])
            ->and($slices[0]['range'])->toBe($file . ':' . $file)
        ;
    });
});

describe('PartitionPlanner::run()', function (): void {
    test('runs one process for fewer than 12 files, a small read, a slot the caller holds or every key (N = 0)', function (): void {
        $singles = 0;
        $seen = [];
        partitionRun(partitionFiles(11), seen: $seen, singles: $singles);
        partitionRun(partitionFiles(24, size: 1_000_000), seen: $seen, singles: $singles);
        partitionRun(partitionFiles(48), parts: 4, seen: $seen, singles: $singles, n: 0);
        NfdumpSlots::acquire();
        NfdumpSlots::runInHeldSlot(NfdumpSlots::INTERACTIVE, static function () use (&$seen, &$singles): void {
            partitionRun(partitionFiles(48), seen: $seen, singles: $singles);
        });

        expect($singles)->toBe(4)
            ->and($seen)->toBe([])
        ;
    });

    test('splits a large read into a part per slot it may take, a part per 6 files at most, and gives every slot back', function (): void {
        $seen = [];
        $splits = [];
        $result = partitionRun(partitionFiles(24, ['gw', 'dmz']), seen: $seen, splits: $splits);

        expect($result->parts)->toBe(7)
            ->and(array_column($seen, 'limit'))->toBe(array_fill(0, 7, 1000))
            ->and(array_column($seen, 'filter'))->toBe(array_fill(0, 7, ''))
            ->and(count(array_unique(array_column($seen, 'range'))))->toBe(7)
            ->and($result->partCommands)->toHaveCount(7)
            ->and($splits)->toBe([[7, [0, 48], false, false]])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('takes only the slots that are free, less one for another query', function (): void {
        NfdumpSlots::acquireMany(4, NfdumpSlots::INTERACTIVE, 0.0);
        $seen = [];
        $result = partitionRun(partitionFiles(48), seen: $seen);

        expect($result->parts)->toBe(3)
            ->and(NfdumpSlots::inUse())->toBe(4)
        ;

        NfdumpSlots::acquireMany(3, NfdumpSlots::INTERACTIVE, 0.0);
        $singles = 0;
        partitionRun(partitionFiles(48), singles: $singles);

        expect($singles)->toBe(1)
            ->and(NfdumpSlots::inUse())->toBe(7)
        ;
    });

    test('each part gives its slot back when it ends, so another query can start before the split is done', function (): void {
        $inUse = [];
        Coroutine::run(static function () use (&$inUse): void {
            Coroutine::create(static function () use (&$inUse): void {
                Coroutine::usleep(150_000);
                $inUse[] = NfdumpSlots::inUse();
            });
            $calls = 0;
            partitionRun(partitionFiles(24), parts: 4, processor: static function () use (&$calls): Processor {
                return partitionProcessor(++$calls === 1 ? 0.4 : 0.05);
            });
            $inUse[] = NfdumpSlots::inUse();
        });

        expect($inUse)->toBe([1, 0]);
    });

    test('looks up the missing keys in the parts that lack them, with -n 0 and the filter of those keys', function (): void {
        $seen = [];
        $splits = [];
        $only = [];
        $result = partitionRun(partitionFiles(24), partitionLookupLines(), parts: 2, seen: $seen, splits: $splits, limit: 2, only: $only);

        expect($result->parts)->toBe(2)
            ->and($only)->toBe([null, null, ['c' => true], ['a' => true]])
            ->and(array_slice($seen, 2))->toBe([
                ['range' => $seen[0]['range'], 'limit' => 0, 'filter' => 'key in [c]'],
                ['range' => $seen[1]['range'], 'limit' => 0, 'filter' => 'key in [a]'],
            ])
            ->and($result->rawOutput)->toBe('a')
            ->and($result->rows)->toBe([['a' => 100, 'b' => 50, 'c' => 10], ['c' => 90, 'd' => 40, 'a' => 5]])
            ->and($splits[1])->toBe([2, [24, 48], true, false])
            ->and($result->command)->toBe(implode("\n", array_map(static fn (array $part): string => 'nfdump -R ' . $part['range'] . ' -n 2', array_slice($seen, 0, 2))))
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('runs as one process when nothing can prove the merge, a lookup cannot name the keys or its filter would be too long', function (Closure $filter): void {
        $singles = 0;
        $splits = [];
        $seen = [];
        $result = partitionRun(partitionFiles(24), partitionLookupLines(), parts: 4, seen: $seen, splits: $splits, singles: $singles, filter: $filter, limit: 2);

        expect($singles)->toBe(1)
            ->and($result->command)->toBe('nfdump single')
            ->and($seen)->toHaveCount(4)
            ->and($splits)->toBe([[4, [0, 24], false, false], [1, [24, 48], true, true]])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    })->with([
        'no filter' => [static fn (array $keys): ?string => null],
        'too long' => [static fn (array $keys): string => 'key in [' . str_repeat('x', PartitionPlanner::MAX_FILTER_BYTES) . ']'],
    ]);

    test('a second pass that fails runs the query as one process, while a killed one ends it', function (): void {
        $failing = static fn (Throwable $e): Closure => static function () use ($e): Processor {
            return new class($e) implements Processor {
                private string $filter = '';

                /** @var array<string, mixed> */
                private array $options = [];

                public function __construct(private readonly Throwable $e) {}

                public function setOption(string $option, $value): void {
                    $this->options[$option] = $value;
                }

                public function setFilter(string $filter): void {
                    $this->filter = $filter;
                }

                public function setQueryHandle(string $handle): void {}

                public function setProfile(string $profile): void {}

                public function execute(): array {
                    if ($this->filter !== '') {
                        throw $this->e;
                    }

                    return ['command' => 'nfdump', 'rawOutput' => json_encode(['range' => $this->options['-R'], 'limit' => $this->options['-n'], 'filter' => '']), 'decoded' => [], 'exitCode' => 0];
                }
            };
        };
        $singles = 0;
        $result = partitionRun(partitionFiles(24), partitionLookupLines(), parts: 2, processor: $failing(new Exception('Failed to start nfdump process')), singles: $singles, limit: 2);

        expect($singles)->toBe(1)
            ->and($result->command)->toBe('nfdump single')
            ->and(NfdumpSlots::inUse())->toBe(0)
            ->and(fn () => partitionRun(partitionFiles(24), partitionLookupLines(), parts: 2, processor: $failing(new NfdumpException('nfdump was stopped (signal 15)', 'nfdump', '', 15)), limit: 2))
            ->toThrow(NfdumpException::class, 'nfdump was stopped (signal 15)')
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('counts the processes a lookup runs in, not the parts of the first pass', function (): void {
        $splits = [];
        $lines = static function (int $index, int $limit, ?string $filter): array {
            if ($index > 1) {
                return [];
            }

            return partitionLookupLines()($index, $limit, $filter);
        };
        $result = partitionRun(partitionFiles(24), $lines, parts: 4, splits: $splits, limit: 2);

        expect($result->parts)->toBe(4)
            ->and(array_column($splits, 0))->toBe([4, 2])
            ->and($splits[1][2])->toBeTrue()
        ;
    });

    test('runs as one process when a part says nfdump skipped a file it was given', function (): void {
        $calls = 0;
        $processor = static function () use (&$calls): Processor {
            $inner = partitionProcessor();
            if (++$calls !== 2) {
                return $inner;
            }

            return new class($inner) implements Processor {
                public function __construct(private readonly Processor $inner) {}

                public function setOption(string $option, $value): void {
                    $this->inner->setOption($option, $value);
                }

                public function setFilter(string $filter): void {
                    $this->inner->setFilter($filter);
                }

                public function setQueryHandle(string $handle): void {}

                public function setProfile(string $profile): void {}

                public function execute(): array {
                    return ['stderr' => "stat() error '/data/gw/2026/09/28/nfcapd.202609280100': File not found!"] + $this->inner->execute();
                }
            };
        };
        $singles = 0;
        $splits = [];
        $result = partitionRun(partitionFiles(24), parts: 3, processor: $processor, singles: $singles, splits: $splits);

        expect($singles)->toBe(1)
            ->and($result->command)->toBe('nfdump single')
            ->and(end($splits))->toBe([1, [24, 48], true, true])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('runs as one process within a day of a daylight saving change that repeats an hour', function (): void {
        PartitionMerge::useZone(new DateTimeZone('Europe/Berlin'));
        $singles = 0;
        // 2026-10-25 01:00 UTC, when Berlin turns 03:00 back to 02:00.
        partitionRun(partitionFiles(24, from: 1_792_890_000 - 3600), singles: $singles);
        partitionRun(partitionFiles(24, from: 1_792_890_000 + 20 * 3600), singles: $singles);
        $spring = partitionRun(partitionFiles(24, from: 1_774_746_000), singles: $singles);
        $later = partitionRun(partitionFiles(24, from: 1_792_890_000 + 2 * 86_400), singles: $singles);

        expect($singles)->toBe(2)
            ->and($spring->parts)->toBeGreaterThan(1)
            ->and($later->parts)->toBeGreaterThan(1)
        ;
    });

    test('stops before a second pass once Kill was pressed, and before any part when it came first', function (): void {
        $singles = 0;
        $seen = [];
        $cancel = false;
        // The Kill lands as the last part of the first pass ends.
        $lines = static function (int $index, int $limit, ?string $filter) use (&$cancel): array {
            $cancel = $cancel || $index === 1;

            return partitionLookupLines()($index, $limit, $filter);
        };
        $cancelled = static function () use (&$cancel): bool {
            return $cancel;
        };

        expect(function () use (&$seen, &$singles, $lines, $cancelled): void {
            partitionRun(partitionFiles(24), $lines, parts: 2, seen: $seen, cancelled: $cancelled, singles: $singles, limit: 2);
        })
            ->toThrow(RuntimeException::class, 'Query cancelled.')
            ->and($singles)->toBe(0)
            ->and($seen)->toHaveCount(2)
            ->and(fn () => partitionRun(partitionFiles(24), partitionLookupLines(), parts: 2, seen: $seen, cancelled: $cancelled, singles: $singles, limit: 2))
            ->toThrow(RuntimeException::class, 'Query cancelled.')
            ->and($seen)->toHaveCount(2)
            ->and($singles)->toBe(0)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('a Kill between two parts starts no further part, as no nfdump of the query was running to stop', function (): void {
        $seen = [];
        $cancel = false;
        // The Kill lands as the first part ends.
        $lines = static function () use (&$cancel): array {
            $cancel = true;

            return ['k' => 1];
        };

        expect(function () use (&$seen, &$cancel, $lines): void {
            partitionRun(partitionFiles(24), $lines, parts: 3, seen: $seen, cancelled: static function () use (&$cancel): bool {
                return $cancel;
            });
        })
            ->toThrow(RuntimeException::class, 'Query cancelled.')
            ->and($seen)->toHaveCount(1)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('a Kill while the lookup waits for its slots runs no lookup and gives the slots back', function (): void {
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw']],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/var/nfdump/profiles-data', 'profile' => 'live', 'max-processes' => 2],
            'log' => ['priority' => LOG_ERR],
        ]);
        $seen = [];
        $error = null;
        $cancel = false;
        $queued = 0;
        Coroutine::run(static function () use (&$seen, &$error, &$cancel, &$queued): void {
            // Another query asks for both slots while the first pass reads, and gets them first.
            Coroutine::create(static function () use (&$cancel, &$queued): void {
                Coroutine::usleep(50_000);
                NfdumpSlots::acquireMany(2, NfdumpSlots::INTERACTIVE, 5.0);
                for ($i = 0; $i < 200 && NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE) === 0; ++$i) {
                    Coroutine::usleep(5_000);
                }
                $queued = NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE);
                $cancel = true;
                NfdumpSlots::release(NfdumpSlots::INTERACTIVE, 2);
            });

            try {
                partitionRun(partitionFiles(24), partitionLookupLines(), parts: 2, seen: $seen, cancelled: static function () use (&$cancel): bool {
                    return $cancel;
                }, processor: static fn (): Processor => partitionProcessor(0.1), limit: 2);
            } catch (Throwable $e) {
                $error = $e;
            }
        });

        expect($queued)->toBe(1)
            ->and($error)->toBeInstanceOf(RuntimeException::class)
            ->and($error?->getMessage())->toBe('Query cancelled.')
            ->and($seen)->toHaveCount(2)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('a part stopped by a signal fails the query once the parts that started ended, and frees their slots', function (): void {
        $calls = 0;
        $processor = static function () use (&$calls): Processor {
            return partitionProcessor(0.0, ++$calls === 2 ? new NfdumpException('nfdump was stopped (signal 15)', 'nfdump', '', 15) : null);
        };
        $seen = [];

        expect(function () use (&$seen, $processor): void {
            partitionRun(partitionFiles(24), parts: 3, seen: $seen, processor: $processor);
        })
            ->toThrow(NfdumpException::class, 'nfdump was stopped (signal 15)')
            ->and($seen)->toHaveCount(1)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('in a coroutine the parts run side by side', function (): void {
        $result = null;
        $started = microtime(true);
        Coroutine::run(static function () use (&$result): void {
            $result = partitionRun(partitionFiles(24), parts: 4, processor: static fn (): Processor => partitionProcessor(0.2));
        });

        expect($result?->parts)->toBe(4)
            ->and(partitionProcessor()::$peak)->toBe(4)
            ->and(microtime(true) - $started)->toBeLessThan(0.6)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('Kill stops every part at once, since they all run under the query handle', function (): void {
        $dir = sys_get_temp_dir() . '/nfsen-p4-kill-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $stub = $dir . '/nfdump';
        file_put_contents($stub, "#!/bin/sh\n[ \"\$1\" = -V ] && { echo 'nfdump: Version: 1.7.10-release'; exit 0; }\nexec sleep 20\n");
        chmod($stub, 0o755);
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw']],
            'nfdump' => ['binary' => $stub, 'profiles-data' => $dir, 'profile' => 'live', 'max-processes' => 8],
            'log' => ['priority' => LOG_ERR],
        ]);
        $error = null;
        $running = [];
        $started = microtime(true);
        // proc_open and the pipe reads have to yield, as they do in the server.
        $hooks = Runtime::getHookFlags();
        Runtime::setHookFlags(SWOOLE_HOOK_ALL);

        try {
            Coroutine::run(static function () use (&$error, &$running): void {
                Coroutine::create(static function () use (&$running): void {
                    for ($i = 0; $i < 250 && count(NfdumpSlots::running()['ctx-p4'] ?? []) < 3; ++$i) {
                        Coroutine::usleep(20_000);
                    }
                    $running = NfdumpSlots::running()['ctx-p4'] ?? [];
                    NfdumpSlots::kill('ctx-p4');
                });

                try {
                    partitionRun(partitionFiles(24), parts: 3, processor: static function (): Processor {
                        $nfdump = new Nfdump();
                        $nfdump->setQueryHandle('ctx-p4');

                        return $nfdump;
                    });
                } catch (Throwable $e) {
                    $error = $e;
                }
            });
        } finally {
            Runtime::setHookFlags($hooks);
            unlink($stub);
            rmdir($dir);
        }

        expect($running)->toHaveCount(3)
            ->and($error)->toBeInstanceOf(NfdumpException::class)
            ->and($error?->wasStopped())->toBeTrue()
            ->and(microtime(true) - $started)->toBeLessThan(10.0)
            ->and(NfdumpSlots::running())->not->toHaveKey('ctx-p4')
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });
});

describe('estimates of a split kind', function (): void {
    test('count the processes the query would split into with the slots free now and divide the time by their speed-up', function (): void {
        $root = sys_get_temp_dir() . '/nfsen-p4-est-' . bin2hex(random_bytes(4));
        $dir = $root . '/live/gw/' . gmdate('Y/m/d', PARTITION_BASE);
        mkdir($dir, 0o777, true);
        for ($i = 0; $i < 48; ++$i) {
            $file = $dir . '/nfcapd.' . gmdate('YmdHi', PARTITION_BASE + $i * 300);
            touch($file);
            // Sparse: 175 MB each, 24 s for all 48 at the default 350 MB/s.
            partitionTruncate($file, 175_000_000);
        }
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw']],
            'nfdump' => ['profiles-data' => $root, 'profile' => 'live', 'max-processes' => 4],
            'log' => ['priority' => LOG_ERR],
        ]);
        QueryEstimator::resetCache();
        $window = TimeWindow::raw(PARTITION_BASE, PARTITION_BASE + 48 * 300 - 1);

        try {
            $stats = QueryEstimator::singlePass('stats', $window, ['gw'], 'live');
            $rate = QueryEstimator::singlePass('stats', $window, ['gw'], 'live', splittable: false);
            $flows = QueryEstimator::singlePass('flows-summary', $window, ['gw'], 'live');
            NfdumpSlots::acquireMany(3, NfdumpSlots::INTERACTIVE, 0.0);
            $busy = QueryEstimator::singlePass('stats', $window, ['gw'], 'live');
        } finally {
            QueryEstimator::resetCache();
            array_map(unlink(...), glob($dir . '/*') ?: []);
            for ($path = $dir; $path !== $root; $path = dirname($path)) {
                rmdir($path);
            }
            rmdir($root);
        }

        // 4 slots: a split takes 3 and leaves one free.
        expect($stats->runs)->toBe(3)
            ->and($stats->seconds)->toBe((int) ceil(24 / 1.75))
            ->and($rate->runs)->toBe(1)
            ->and($rate->seconds)->toBe(24)
            ->and($busy->runs)->toBe(1)
            ->and($flows->runs)->toBe(1)
            ->and($flows->seconds)->toBe((int) ceil(8_400_000_000 / 350_000_000))
        ;
    });

    test('a filtered series counts on its bins running side by side', function (): void {
        expect(QueryEstimator::binSpeedup(1))->toBe(1.0)
            ->and(QueryEstimator::binSpeedup(2))->toBe(2.0)
            ->and(QueryEstimator::binSpeedup(4))->toBe(2.6)
            ->and(QueryEstimator::binSpeedup(8))->toBe(3.0)
            ->and(QueryEstimator::binSpeedup(0))->toBe(1.0)
        ;
    });
});

/**
 * @template T
 *
 * @param T                $value
 * @param Closure(T): void $with
 *
 * @return T
 */
function partitionTap(mixed $value, Closure $with): mixed {
    $with($value);

    return $value;
}

function partitionTruncate(string $path, int $size): void {
    $handle = fopen($path, 'r+');
    ftruncate($handle, $size);
    fclose($handle);
}
