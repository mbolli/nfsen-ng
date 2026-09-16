<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\query\TimeWindow;
use Mcp\Exception\ToolCallException;

/**
 * Bounds on what a caller may ask an expensive tool to do.
 *
 * An agent left to itself will loop: widen the window, raise the limit, try again. That is
 * fine against stored aggregates and ruinous against capture files, because every attempt
 * reads the disk and the box is often already under load when someone starts investigating.
 * These bounds are enforced here rather than described in a prompt.
 */
final class Guard {
    /** Rows a tool returns when the caller does not say. */
    public const DEFAULT_LIMIT = 20;

    /** Rows a tool returns at most, whatever the caller says. */
    public const MAX_LIMIT = 500;

    /**
     * Clamps the range to NFSEN_MAX_STATS_WINDOW, the same bound the Statistics and Sankey
     * panels apply. The result reports whether it shortened the range, so the answer can say so.
     */
    public static function window(int $start, int $end): TimeWindow {
        if ($end <= $start) {
            // A reversed or empty range would otherwise read every capture nfdump can find.
            $end = $start + 300;
        }

        return TimeWindow::clamped($start, $end);
    }

    public static function limit(int $requested): int {
        if ($requested <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($requested, self::MAX_LIMIT);
    }

    /**
     * The filter reaches nfdump as a single escaped positional argument, which is the actual
     * boundary. This only rejects the obviously malformed, so a mistake comes back as an error
     * instead of a query that reads every capture file and returns nothing.
     *
     * ToolCallException rather than a plain exception on purpose: the SDK turns that into an
     * error the caller can read, while anything else becomes an opaque internal error.
     *
     * @throws ToolCallException when the expression cannot be a filter
     */
    public static function filter(string $filter): string {
        $filter = trim($filter);

        if ($filter === '') {
            return '';
        }

        if (\strlen($filter) > 2000) {
            throw new ToolCallException('Filter expression is too long.');
        }

        if (substr_count($filter, '(') !== substr_count($filter, ')')) {
            throw new ToolCallException('Filter expression has unbalanced parentheses.');
        }

        return $filter;
    }

    /**
     * Refuses a query whose cost is beyond what a caller should commit to without asking.
     * The estimate is cheap; the query is not.
     *
     * @throws ToolCallException when the window would read more capture data than allowed
     */
    public static function assertAffordable(int $bytes): void {
        $max = Config::$settings->maxStatsWindow;

        // Without a configured window bound there is nothing to scale a byte ceiling against,
        // so the operator has opted out of this protection entirely.
        if ($max <= 0) {
            return;
        }

        if ($bytes > self::maxBytes()) {
            throw new ToolCallException(\sprintf(
                'This query would read %s of capture data, more than the %s ceiling. Narrow the time window or add a filter.',
                self::formatBytes($bytes),
                self::formatBytes(self::maxBytes()),
            ));
        }
    }

    /**
     * How much capture data one tool call may read. Deliberately generous: the point is to
     * stop a runaway loop, not to second-guess a legitimate investigation.
     */
    public static function maxBytes(): int {
        return 16 * 1024 * 1024 * 1024;
    }

    public static function formatBytes(int $bytes): string {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return round($value, 1) . ' ' . $units[$unit];
    }
}
