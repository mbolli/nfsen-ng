<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Debug;
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

    /** @var array<string, array{at: int, files: int, bytes: int}> oldest first */
    private static array $cache = [];

    /** @var null|\Closure(): int */
    private static ?\Closure $clock = null;

    /**
     * One nfdump pass over the window: Flows, Top Talkers, Conversations, the exact top-N.
     *
     * @param list<string> $sources
     *
     * @throws \InvalidArgumentException for a kind without a default throughput
     */
    public static function singlePass(string $kind, TimeWindow $window, array $sources, string $profile): Estimate {
        $throughput = self::throughput($kind);
        [$files, $bytes] = self::cost($kind, $window, $sources, $profile);

        return new Estimate(
            files: $files,
            bytes: $bytes,
            runs: 1,
            seconds: $files > 0 && $bytes > 0 ? (int) ceil($bytes / $throughput['bytesPerSecond']) : null,
            measured: $throughput['measured'],
            clamped: $window->clamped,
            window: TimeWindow::humanize($window->duration()),
        );
    }

    /**
     * Filtered series and flows graph: one nfdump per bin and group, each reading its own slice,
     * so the files are read once in total and every run adds its start-up cost.
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
                ? (int) ceil($bytes / $throughput['bytesPerSecond'] + $runs * self::SECONDS_PER_RUN)
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
            $median = new QueryRunRepository(Database::shared())->medianThroughput($kind);
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
