<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Misc;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

/**
 * Runs one statistic as parallel nfdump processes over time slices, since nfdump aggregates on
 * one thread whatever -W says; a merge that cannot be proven exact runs as one process.
 *
 * @phpstan-import-type NfcapdFile from NfcapdFiles
 * @phpstan-import-type ProcessorResult from Processor
 * @phpstan-import-type Ranked from PartitionMerge
 *
 * @phpstan-type Part array{index: int, range: string, files: int, bytes: int, from: int, to: int}
 * @phpstan-type Meta array{commands: list<string>, stderr: list<string>, exitCode: int}
 * @phpstan-type Passes array{reading: array<int, Processor>, finished: array<int, true>, commands: list<string>, errors: array<int, string>, exitCode: int}
 */
final class PartitionPlanner {
    /** Below this estimate a split saves less than it costs to start. */
    public const float MIN_SECONDS = 1.0;

    /** Fewer files are one process's work. */
    public const int MIN_FILES = 12;

    public const int MIN_FILES_PER_PART = 6;

    /** More processes add almost nothing: 4 give 1.9 times, 8 give 2.2 times. */
    public const int MAX_PARTS = 8;

    /** Speed-up of P processes over one: measured at 2, 4 and 8 (-s srcip, 24 x 1M flows), interpolated between. */
    public const array SPEEDUP = [1 => 1.0, 2 => 1.6, 3 => 1.75, 4 => 1.9, 5 => 1.975, 6 => 2.05, 7 => 2.125, 8 => 2.2];

    /** The longest filter a lookup passes: one argument, and Linux refuses one of 128 KiB or more. */
    public const int MAX_FILTER_BYTES = 65_536;

    /** Keys the parts of a split list together: a parsed key takes about 300 bytes of a worker's 128 MB. */
    public const int LINE_BUDGET = 40_000;

    /** Keys one part lists at most: it parses them in one go, about 15 ms that hold the event loop. */
    public const int MAX_PART_LINES = 10_000;

    private static ?int $fetchLimit = null;

    /** How many parts a read of $files files and $seconds seconds splits into: 1 is one process. */
    public static function parts(int $files, float $seconds, int $slots): int {
        if ($files < self::MIN_FILES || $seconds <= self::MIN_SECONDS) {
            return 1;
        }

        return max(1, min($slots, intdiv($files, self::MIN_FILES_PER_PART), self::MAX_PARTS));
    }

    /** The slots a split may take now: once there are three, one stays free for another query. */
    public static function freeSlots(string $class = NfdumpSlots::INTERACTIVE): int {
        $free = NfdumpSlots::available($class);

        return NfdumpSlots::max() >= 3 ? min($free, max(2, $free - 1)) : $free;
    }

    /** Keys each of $parts parts lists in the first pass of a top $n: its share of LINE_BUDGET. */
    public static function listLimit(int $n, int $parts): int {
        return self::$fetchLimit ?? max(2 * $n, min(self::MAX_PART_LINES, intdiv(self::LINE_BUDGET, max(1, $parts))));
    }

    /** Tests: parts list this many keys, so a check can be made to fail; null restores the rule. */
    public static function useFetchLimit(?int $limit): void {
        self::$fetchLimit = $limit;
    }

    public static function speedup(int $parts): float {
        return self::SPEEDUP[max(1, min(self::MAX_PARTS, $parts))];
    }

    /**
     * The capture files nfdump's -R reads for $window, from the file of its floored start.
     *
     * @param list<string> $sources
     *
     * @return list<NfcapdFile>
     */
    public static function files(TimeWindow $window, array $sources, string $profile): array {
        return NfcapdFiles::list($window->start - $window->start % 300, $window->end, $sources, $profile);
    }

    /**
     * At most $count consecutive slices of about equal size and MIN_FILES_PER_PART files each,
     * whole intervals only, since -M reads the same name from every source.
     *
     * @param list<NfcapdFile> $files ascending by time, as NfcapdFiles::list() returns them
     *
     * @return list<Part>
     */
    public static function slices(array $files, int $count): array {
        /** @var array<int, array{relPath: string, files: int, bytes: int}> $intervals */
        $intervals = [];
        foreach ($files as $file) {
            $intervals[$file['ts']] ??= ['relPath' => $file['relPath'], 'files' => 0, 'bytes' => 0];
            ++$intervals[$file['ts']]['files'];
            $intervals[$file['ts']]['bytes'] += max(1, $file['size']);
        }
        ksort($intervals);
        $count = max(1, min($count, \count($intervals), intdiv(\count($files), self::MIN_FILES_PER_PART)));
        $total = array_sum(array_column($intervals, 'bytes'));
        $filesLeft = \count($files);
        $next = array_values(array_column($intervals, 'files'));

        $parts = [];
        $current = null;
        $done = 0;
        $position = 0;
        foreach ($intervals as $ts => $interval) {
            $current ??= ['index' => \count($parts), 'first' => $interval['relPath'], 'last' => $interval['relPath'], 'files' => 0, 'bytes' => 0, 'from' => $ts, 'to' => $ts];
            $current['last'] = $interval['relPath'];
            $current['to'] = $ts;
            $current['files'] += $interval['files'];
            $current['bytes'] += $interval['bytes'];
            $done += $interval['bytes'];
            $filesLeft -= $interval['files'];
            ++$position;

            // Cut at this slice's share of the bytes, or before the rest gets too few files.
            $slicesLeft = $count - \count($parts) - 1;
            $room = $slicesLeft * self::MIN_FILES_PER_PART;
            if ($slicesLeft > 0 && $current['files'] >= self::MIN_FILES_PER_PART && $filesLeft >= $room
                && ($done * $count >= $total * (\count($parts) + 1) || $filesLeft - ($next[$position] ?? 0) < $room)) {
                $parts[] = self::part($current);
                $current = null;
            }
        }
        if ($current !== null) {
            $parts[] = self::part($current);
        }

        return $parts;
    }

    /**
     * Runs a query over $files split when it pays and slots are free, else as one process.
     *
     * @template T of array{lines: array<string, mixed>, known: array<string, true>, truncated: bool}
     *
     * @param list<NfcapdFile>                                                  $files
     * @param int                                                               $n         the top to prove
     * @param \Closure(): QueryResult                                           $single
     * @param \Closure(Part, int, ?string): Processor                           $build     a part listing $limit keys, under a lookup's filter or the query's (null)
     * @param \Closure(Part, ProcessorResult, int, ?array<string, true>): T     $parse     the last argument: a lookup's keys, the only lines worth keeping
     * @param \Closure(array<int, T>): list<Ranked>                             $rank      the parts' data in time order
     * @param \Closure(array<int, T>, list<string>, Meta): ?QueryResult         $render    the proven top, or null when it cannot be printed
     * @param \Closure(list<string>): ?string                                   $keyFilter the query's filter for those keys, null when none can name them
     * @param null|\Closure(int, \Closure(): array{int, int}, bool, bool): void $onSplit   processes, files read of files to read, a second pass, one process
     * @param null|\Closure(): bool                                             $cancelled whether a Kill arrived
     * @param null|int                                                          $parts     tests: this many parts at most, whatever the estimate
     * @param null|int                                                          $limit     tests: keys each part lists first, else listLimit()
     *
     * @throws \Throwable a Kill, a slot that did not free up in time, or what the one process threw
     */
    public static function run(
        string $kind,
        array $files,
        int $n,
        string $handle,
        \Closure $single,
        \Closure $build,
        \Closure $parse,
        \Closure $rank,
        \Closure $render,
        \Closure $keyFilter,
        ?\Closure $onSplit = null,
        ?\Closure $cancelled = null,
        ?int $parts = null,
        ?int $limit = null,
    ): QueryResult {
        $scope = NfdumpSlots::scope();
        $class = $scope['class'];
        if ($scope['held'] || \count($files) < self::MIN_FILES || $n < 1) {
            return $single();
        }
        $bytes = NfcapdFiles::totalSize($files);
        $free = self::freeSlots($class);
        $want = min($free, $parts ?? self::parts(\count($files), $bytes / QueryEstimator::throughput($kind)['bytesPerSecond'], $free));
        $times = array_column($files, 'ts');
        if ($want < 2 || PartitionMerge::repeatsLocalTime(min($times), max($times) + 300)) {
            return $single();
        }

        $held = NfdumpSlots::acquireMany($want, $class, NfdumpSlots::waitFor($scope));
        $slices = self::slices($files, $held);
        if (\count($slices) < 2) {
            NfdumpSlots::release($class, $held - 1);

            try {
                return NfdumpSlots::runInHeldSlot($class, $single);
            } finally {
                NfdumpSlots::release($class);
            }
        }
        NfdumpSlots::release($class, $held - \count($slices));
        $limit ??= self::listLimit($n, \count($slices));

        $state = ['reading' => [], 'finished' => [], 'commands' => array_fill(0, \count($slices), ''), 'errors' => [], 'exitCode' => 0];
        $read = static function () use (&$state, $slices): int {
            return self::filesRead($slices, $state);
        };
        $total = \count($files);

        try {
            $onSplit?->__invoke(\count($slices), static fn (): array => [$read(), $total], false, false);
            Debug::getInstance()->log('Split ' . $kind . ' into ' . \count($slices) . ' nfdump processes over ' . $total . ' files', LOG_DEBUG);

            $data = self::pass($slices, array_keys($slices), $limit, [], $class, \count($slices), $build, $parse, $state, $cancelled);
            $ranked = $rank($data);
            self::yieldLoop();
            $proof = PartitionMerge::prove($ranked, $n);
            $result = $proof['top'] === null ? null : $render($data, $proof['top'], self::meta($state));
            if ($result !== null) {
                return $result;
            }

            $lookups = self::lookupFilters($proof['lookups'], $keyFilter);
            if ($lookups !== null && $lookups !== []) {
                self::stopIfCancelled($cancelled);
                $indexes = array_keys($lookups);
                Debug::getInstance()->log('Split ' . $kind . ': looking up key totals in ' . \count($indexes) . ' parts', LOG_DEBUG);
                $again = self::fileCount($slices, $indexes);
                $state['finished'] = [];
                $filters = array_map(static fn (array $lookup): string => $lookup['filter'], $lookups);
                $slots = NfdumpSlots::acquireMany(\count($indexes), $class, NfdumpSlots::waitFor($scope));
                if ($cancelled !== null && $cancelled()) {
                    NfdumpSlots::release($class, $slots);
                    self::stopIfCancelled($cancelled);
                }
                $onSplit?->__invoke(min($slots, \count($indexes)), static fn (): array => [$total + $read(), $total + $again], true, false);
                $only = array_map(static fn (array $lookup): array => array_fill_keys($lookup['keys'], true), $lookups);
                foreach (self::pass($slices, $indexes, 0, $filters, $class, $slots, $build, $parse, $state, $cancelled, $only) as $i => $found) {
                    $data[$i] = PartitionMerge::absorb($data[$i], $found, $lookups[$i]['keys']);
                }
                $proof = PartitionMerge::prove($rank($data), $n);
                $result = $proof['top'] === null ? null : $render($data, $proof['top'], self::meta($state));
                if ($result !== null) {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            if (self::stops($e, $cancelled)) {
                throw $e;
            }
            Debug::getInstance()->log('Split ' . $kind . ' failed (' . $e->getMessage() . '), running it as one process', LOG_WARNING);
        }

        self::stopIfCancelled($cancelled);
        Debug::getInstance()->log('Split ' . $kind . ': the parts cannot prove the top ' . $n . ', running it as one process', LOG_INFO);
        $onSplit?->__invoke(1, static fn (): array => [$total + (int) floor($total * self::readShare(NfdumpSlots::pidFor($handle), $bytes)), 2 * $total], true, true);

        return $single();
    }

    /**
     * Each part's lookup keys with their filter, or null when a filter cannot name them or is too long.
     *
     * @param null|array<int, list<string>>   $keys
     * @param \Closure(list<string>): ?string $filter
     *
     * @return null|array<int, array{keys: list<string>, filter: string}>
     */
    public static function lookupFilters(?array $keys, \Closure $filter): ?array {
        if ($keys === null) {
            return null;
        }

        $lookups = [];
        foreach ($keys as $i => $wanted) {
            $expression = $filter($wanted);
            if ($expression === null || $expression === '' || \strlen($expression) > self::MAX_FILTER_BYTES) {
                return null;
            }
            $lookups[$i] = ['keys' => $wanted, 'filter' => $expression];
        }

        return $lookups;
    }

    /** How much of $bytes the nfdump $pid has read, 0 to 1. */
    public static function readShare(?int $pid, int $bytes): float {
        $read = $pid === null ? null : Misc::processReadBytes($pid);

        return $read === null || $bytes <= 0 ? 0.0 : min(1.0, $read / $bytes);
    }

    /**
     * Whether $e ends the query rather than the split: a Kill, a stop by signal, or a slot
     * timeout, which one process would wait for again.
     *
     * @param null|\Closure(): bool $cancelled
     */
    private static function stops(\Throwable $e, ?\Closure $cancelled): bool {
        return ($cancelled !== null && $cancelled())
            || ($e instanceof NfdumpException && $e->wasStopped())
            || NfdumpSlots::timedOut($e);
    }

    /**
     * Runs the parts $indexes in the $slots slots of $class the caller took, side by side in a
     * coroutine; each slot is given back once no part is left for it.
     *
     * @template T
     *
     * @param list<Part>                                                    $slices
     * @param list<int>                                                     $indexes
     * @param array<int, string>                                            $filters   a lookup's filter by part
     * @param \Closure(Part, int, ?string): Processor                       $build
     * @param \Closure(Part, ProcessorResult, int, ?array<string, true>): T $parse
     * @param Passes                                                        $state     the parts reading, for the progress, and what they printed
     * @param null|\Closure(): bool                                         $cancelled
     * @param array<int, array<string, true>>                               $only      a lookup's keys by part
     *
     * @return array<int, T>
     *
     * @throws \Throwable the first failure, once every part that started ended
     */
    private static function pass(array $slices, array $indexes, int $limit, array $filters, string $class, int $slots, \Closure $build, \Closure $parse, array &$state, ?\Closure $cancelled = null, array $only = []): array {
        $state['reading'] = [];
        $state['finished'] = [];
        $queue = $indexes;
        $results = [];
        $failures = [];
        $one = static function (int $i) use ($slices, $limit, $filters, $only, $class, $build, $parse, $cancelled, &$state, &$results): void {
            // A Kill between two parts finds no nfdump of this query to stop.
            self::stopIfCancelled($cancelled);

            try {
                $processor = $build($slices[$i], $limit, $filters[$i] ?? null);
                $state['reading'][$i] = $processor;
                $output = NfdumpSlots::runInHeldSlot($class, static fn (): array => $processor->execute());
                self::assertReadAll($output['stderr'] ?? '');
                if (!isset($filters[$i])) {
                    $state['commands'][$i] = $output['command'];
                }
                $state['errors'][$i] = trim($output['stderr'] ?? '');
                $state['exitCode'] = $state['exitCode'] !== 0 ? $state['exitCode'] : ($output['exitCode'] ?? 0);
                $results[$i] = $parse($slices[$i], $output, $limit, $only[$i] ?? null);
                self::yieldLoop();
            } finally {
                unset($state['reading'][$i]);
                $state['finished'][$i] = true;
            }
        };
        $worker = static function () use (&$queue, &$failures, $one, $class): void {
            try {
                while ($queue !== [] && $failures === []) {
                    $one((int) array_shift($queue));
                }
            } catch (\Throwable $e) {
                $failures[] = $e;
            } finally {
                NfdumpSlots::release($class);
            }
        };

        $inCoroutine = Coroutine::getCid() > 0;
        $workers = max(1, min($slots, \count($indexes)));
        NfdumpSlots::release($class, $slots - ($inCoroutine ? $workers : 1));
        if (!$inCoroutine || $workers < 2) {
            $worker();
        } else {
            // This coroutine is a worker too, so the pass goes on even when no coroutine can be started.
            $exits = new Channel($workers);
            $spawned = 0;
            for ($w = 1; $w < $workers; ++$w) {
                $started = Coroutine::create(static function () use ($worker, $exits): void {
                    try {
                        $worker();
                    } finally {
                        $exits->push(true);
                    }
                });
                if ($started === false) {
                    NfdumpSlots::release($class);
                } else {
                    ++$spawned;
                }
            }
            $worker();
            for (; $spawned > 0; --$spawned) {
                $exits->pop();
            }
        }

        if ($failures !== []) {
            throw $failures[0];
        }
        self::stopIfCancelled($cancelled);
        ksort($results);

        return $results;
    }

    /**
     * Fails a part whose nfdump skipped a file it was given: it still exits 0, having read less.
     *
     * @throws \RuntimeException
     */
    private static function assertReadAll(string $stderr): void {
        if (str_contains($stderr, 'File not found') || str_contains($stderr, 'stat() error')) {
            throw new \RuntimeException('A part did not read every file: ' . trim($stderr));
        }
    }

    /**
     * Files the current pass has read, a reading part's share by the bytes its nfdump read.
     *
     * @param list<Part> $slices
     * @param Passes     $state
     */
    private static function filesRead(array $slices, array $state): int {
        $read = 0.0;
        foreach (array_keys($state['finished']) as $i) {
            $read += $slices[$i]['files'];
        }
        foreach ($state['reading'] as $i => $processor) {
            $pid = $processor instanceof Nfdump ? $processor->pid() : null;
            $read += $slices[$i]['files'] * self::readShare($pid, $slices[$i]['bytes']);
        }

        return (int) floor($read);
    }

    /**
     * @param list<Part> $slices
     * @param list<int>  $indexes
     */
    private static function fileCount(array $slices, array $indexes): int {
        return array_sum(array_map(static fn (int $i): int => $slices[$i]['files'], $indexes));
    }

    /**
     * @param Passes $state
     *
     * @return Meta
     */
    private static function meta(array $state): array {
        return [
            'commands' => $state['commands'],
            'stderr' => array_values(array_unique(array_filter($state['errors'], static fn (string $error): bool => $error !== ''))),
            'exitCode' => $state['exitCode'],
        ];
    }

    /**
     * A slice's range names its first and last file even when they are one: nfdump reads a
     * lone -R file with -M from there to the end of its directory.
     *
     * @param array{index: int, first: string, last: string, files: int, bytes: int, from: int, to: int} $slice
     *
     * @return Part
     */
    private static function part(array $slice): array {
        return [
            'index' => $slice['index'],
            'range' => $slice['first'] . ':' . $slice['last'],
            'files' => $slice['files'],
            'bytes' => $slice['bytes'],
            'from' => $slice['from'],
            'to' => $slice['to'],
        ];
    }

    /** Lets the worker's other coroutines run between two stretches of parsing or merging. */
    private static function yieldLoop(): void {
        if (Coroutine::getCid() > 0) {
            Coroutine::usleep(1000);
        }
    }

    /** @param null|\Closure(): bool $cancelled */
    private static function stopIfCancelled(?\Closure $cancelled): void {
        if ($cancelled !== null && $cancelled()) {
            throw new \RuntimeException('Query cancelled.');
        }
    }
}
