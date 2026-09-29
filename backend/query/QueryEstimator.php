<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;
use mbolli\nfsen_ng\store\StoreUnavailableException;

/**
 * Files, bytes and seconds a query would take, before it runs. Walks the capture tree, so it
 * belongs in an action's coroutine, never in a render. Server worker only: it reads recorded
 * runs from the SQLite store, which the MCP process never opens (MCP uses CostEstimate).
 */
final class QueryEstimator {
    /** Default throughput, bytes of on-disk capture per second (measured on the dev host, 20 cores, LZO). */
    public const array DEFAULT_THROUGHPUT = [
        'flows' => 380_000_000, 'flows-summary' => 350_000_000, 'stats' => 350_000_000,
        'talkers-panel' => 350_000_000, 'overview-topn' => 350_000_000,
        'conversations' => 230_000_000, 'flowsgraph' => 350_000_000, 'graph' => 350_000_000,
    ];

    public const int CACHE_SIZE = 32;

    public const int CACHE_TTL_SECONDS = 300;

    /** The cache key rounds the window down to one capture interval. */
    public const int CACHE_ROUNDING_SECONDS = 300;

    /** Start-up cost of each nfdump run of a filtered series. */
    public const float SECONDS_PER_RUN = 0.05;

    /** @var list<string> kinds PartitionPlanner may split into parallel nfdump processes */
    public const array SPLIT_KINDS = ['stats', 'talkers-panel', 'overview-topn', 'conversations'];

    /**
     * Speed-up of a filtered series building its bins in that many slots at once: measured at 2,
     * 4 and 6 (7 days of 5-minute bins), interpolated between and held flat above.
     */
    public const array BIN_SPEEDUP = [1 => 1.0, 2 => 2.0, 3 => 2.3, 4 => 2.6, 5 => 2.8, 6 => 3.0];

    /** @var array<string, array{at: int, files: int, bytes: int}> oldest first */
    private static array $cache = [];

    /** @var null|\Closure(): int */
    private static ?\Closure $clock = null;

    /**
     * One nfdump pass over the window: Flows, Top Talkers, Conversations, the exact top-N, as the
     * processes PartitionPlanner would split it into now. $splittable null: the kind decides.
     *
     * @param list<string> $sources
     *
     * @throws \InvalidArgumentException for a kind without a default throughput
     */
    public static function singlePass(string $kind, TimeWindow $window, array $sources, string $profile, ?bool $splittable = null): Estimate {
        $throughput = self::throughput($kind);
        [$files, $bytes] = self::cost($kind, $window, $sources, $profile);
        $seconds = $bytes / $throughput['bytesPerSecond'];
        $splittable ??= $kind !== 'conversations' || !Nfdump::needsAggregatedCsv(Nfdump::version());
        $parts = $splittable && \in_array($kind, self::SPLIT_KINDS, true)
            ? PartitionPlanner::parts($files, $seconds, PartitionPlanner::freeSlots(NfdumpSlots::INTERACTIVE))
            : 1;

        return new Estimate(
            files: $files,
            bytes: $bytes,
            runs: $parts,
            seconds: $files > 0 && $bytes > 0 ? (int) ceil($seconds / PartitionPlanner::speedup($parts)) : null,
            measured: $throughput['measured'],
            clamped: $window->clamped,
            window: TimeWindow::humanize($window->duration()),
        );
    }

    /**
     * Filtered series and flows graph: one nfdump per bin and group, each reading its own slice,
     * so the files are read once in total and every run adds its start-up cost. The bins run
     * side by side in the slots free now (FilteredSeries).
     *
     * @param list<string> $sources
     *
     * @throws \InvalidArgumentException for a kind without a default throughput
     */
    public static function filteredSeries(string $kind, TimeWindow $window, array $sources, string $profile, int $targetPoints, int $groups): Estimate {
        $throughput = self::throughput($kind);
        [$files, $bytes] = self::cost($kind, $window, $sources, $profile);
        $runs = CostEstimate::runsForFilteredSeries($window, $targetPoints, $groups);

        return new Estimate(
            files: $files,
            bytes: $bytes,
            runs: $runs,
            seconds: $files > 0 && $bytes > 0
                ? (int) ceil(($bytes / $throughput['bytesPerSecond'] + $runs * self::SECONDS_PER_RUN) / self::binSpeedup(min(max(1, NfdumpSlots::available(NfdumpSlots::INTERACTIVE)), $runs)))
                : null,
            measured: $throughput['measured'],
            clamped: $window->clamped,
            window: TimeWindow::humanize($window->duration()),
        );
    }

    /**
     * The median of recorded runs once there are enough of them, else the default. A store that
     * is unavailable or fails reads as "no recorded runs".
     *
     * @return array{bytesPerSecond: float, measured: bool}
     *
     * @throws \InvalidArgumentException for a kind without a default throughput
     */
    public static function throughput(string $kind): array {
        if (!\array_key_exists($kind, self::DEFAULT_THROUGHPUT)) {
            throw new \InvalidArgumentException('Unknown query kind.');
        }

        $median = null;

        try {
            $median = new QueryRunRepository(Database::shared())->medianThroughput($kind, PartitionPlanner::speedup(...));
        } catch (StoreUnavailableException) {
            // Database::shared() logs the reason once per retry interval.
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Recorded query throughput unavailable: ' . $e->getMessage(), LOG_WARNING);
        }

        return $median !== null && $median > 0
            ? ['bytesPerSecond' => $median, 'measured' => true]
            : ['bytesPerSecond' => (float) self::DEFAULT_THROUGHPUT[$kind], 'measured' => false];
    }

    /**
     * Kind, profile, sorted sources and the window rounded down to CACHE_ROUNDING_SECONDS, so
     * the live window's second-by-second advance keeps hitting the same entry.
     *
     * @param list<string> $sources
     */
    public static function cacheKey(string $kind, TimeWindow $window, array $sources, string $profile): string {
        sort($sources);
        $step = self::CACHE_ROUNDING_SECONDS;

        return json_encode(
            [$kind, $profile, $sources, intdiv($window->start, $step) * $step, intdiv($window->end, $step) * $step],
            JSON_THROW_ON_ERROR,
        );
    }

    /** How much faster a filtered series builds in $slots slots than in one. */
    public static function binSpeedup(int $slots): float {
        return self::BIN_SPEEDUP[max(1, min(6, $slots))];
    }

    /** Tests. */
    public static function resetCache(): void {
        self::$cache = [];
    }

    /**
     * Tests: a fixed clock for the cache TTL; null restores time().
     *
     * @param null|\Closure(): int $clock
     */
    public static function useClock(?\Closure $clock): void {
        self::$clock = $clock;
    }

    /**
     * @param list<string> $sources
     *
     * @return array{0: int, 1: int} files and bytes
     */
    private static function cost(string $kind, TimeWindow $window, array $sources, string $profile): array {
        $key = self::cacheKey($kind, $window, $sources, $profile);
        $now = self::$clock !== null ? (self::$clock)() : time();

        $entry = self::$cache[$key] ?? null;
        unset(self::$cache[$key]);
        if ($entry === null || $now - $entry['at'] >= self::CACHE_TTL_SECONDS) {
            $cost = CostEstimate::forSinglePass($window, $sources, $profile);
            $entry = ['at' => $now, 'files' => $cost->files, 'bytes' => $cost->bytes];
        }

        // Re-inserted at the end, so the first key is always the least recently used.
        self::$cache[$key] = $entry;
        while (\count(self::$cache) > self::CACHE_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }

        return [$entry['files'], $entry['bytes']];
    }
}
