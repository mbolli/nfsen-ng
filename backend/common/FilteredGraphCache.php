<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Datasource;

/**
 * Memoises filtered-graph series keyed by the full query that produced them.
 *
 * This is what keeps a filtered graph off the render path. fetchGraphData() runs on
 * every re-render — every SSE push, every filter change, every live tick — so a
 * filtered series must be looked up, never recomputed, outside the explicit Apply
 * action. A miss renders an empty graph prompting the user to press Apply rather
 * than silently forking several hundred nfdump processes.
 *
 * Single-worker OpenSwoole makes a plain static store safe, and sharing it across
 * contexts is a feature: two tabs on the same query reuse one build.
 *
 * @phpstan-import-type GraphData from Datasource
 */
final class FilteredGraphCache {
    /** Entries are evicted oldest-first past this many. */
    public const MAX_ENTRIES = 10;

    /**
     * Seconds an entry stays usable *since it was last read*, not since it was built.
     *
     * Expiring on build time would empty a graph the user is still looking at: the page
     * re-renders on every rrd:live broadcast (roughly every import), so a tab left open
     * would drop back to "press Apply" ten minutes after the build with nothing having
     * changed. Touch-on-read makes this "discard if nobody has looked at it" instead,
     * which is safe here because the window is part of the key — in filtered mode it no
     * longer auto-advances, so a given key's data cannot go stale, only unwanted.
     */
    public const TTL = 600;

    /** @var array<string, array{at: int, data: GraphData}> */
    private static array $entries = [];

    /**
     * Stable key for one filtered query. Every input that changes the resulting series
     * must appear here — a key that ignores one silently serves the wrong graph.
     *
     * @param list<string> $sources
     * @param list<string> $protocols
     */
    public static function key(
        int $start,
        int $end,
        array $sources,
        string $filter,
        string $unit,
        string $display,
        int $targetPoints,
        string $profile,
        array $protocols = ['any'],
    ): string {
        return hash('xxh128', implode("\x1f", [
            $start, $end, implode(',', $sources), $filter, $unit, $display, $targetPoints, $profile,
            implode(',', $protocols),
        ]));
    }

    /**
     * @return null|GraphData
     */
    public static function get(string $key, ?int $now = null): ?array {
        $now ??= time();
        $entry = self::$entries[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if (($now - $entry['at']) > self::TTL) {
            unset(self::$entries[$key]);

            return null;
        }

        // Touch: refresh recency so an actively displayed series survives, and re-insert
        // at the end so eviction order tracks use rather than write time.
        unset(self::$entries[$key]);
        self::$entries[$key] = ['at' => $now, 'data' => $entry['data']];

        return $entry['data'];
    }

    /**
     * @param GraphData $data
     */
    public static function put(string $key, array $data, ?int $now = null): void {
        // Re-insert at the end so eviction order tracks recency.
        unset(self::$entries[$key]);
        self::$entries[$key] = ['at' => $now ?? time(), 'data' => $data];

        while (\count(self::$entries) > self::MAX_ENTRIES) {
            array_shift(self::$entries);
        }
    }

    public static function has(string $key, ?int $now = null): bool {
        return self::get($key, $now) !== null;
    }

    public static function clear(): void {
        self::$entries = [];
    }

    public static function count(): int {
        return \count(self::$entries);
    }
}
