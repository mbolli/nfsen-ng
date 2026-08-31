<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Per-tab cancellation flags for long-running queries.
 *
 * Killing the current nfdump is not enough for a chunked run: FilteredSeries forks one
 * process per time bin, so SIGTERM only ends the bin in flight and the loop marches on
 * to the next. The loop therefore checks a flag between bins, and the Kill button sets it
 * alongside sending the signal.
 *
 * Keyed by context id so one tab's Kill cannot stop another tab's query. A static store
 * is safe here for the same reason Nfdump::$runningPid is — php-via runs a single worker.
 */
final class QueryCancel {
    /** @var array<string, true> */
    private static array $requested = [];

    public static function request(string $contextId): void {
        self::$requested[$contextId] = true;
    }

    public static function isRequested(string $contextId): bool {
        return isset(self::$requested[$contextId]);
    }

    /** Clear the flag before starting a run, so a stale Kill cannot abort the next query. */
    public static function clear(string $contextId): void {
        unset(self::$requested[$contextId]);
    }

    public static function clearAll(): void {
        self::$requested = [];
    }
}
