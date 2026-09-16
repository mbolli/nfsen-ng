<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;

/**
 * A query's time range, already clamped to NFSEN_MAX_STATS_WINDOW.
 *
 * Every surface that reads capture files has to apply the same bound, and each one used to
 * implement it again: Statistics, Sankey and the filtered graph all had their own copy, with
 * their own way of saying so. Clamping keeps the end and pulls the start forward, because the
 * recent end of a range is the part someone is actually looking at.
 */
final readonly class TimeWindow {
    private function __construct(
        public int $start,
        public int $end,
        public bool $clamped,
        /** The bound actually applied, which is not always the configured one. */
        public int $max = 0,
    ) {}

    /**
     * Clamps to the configured maximum. A max of 0 disables the bound entirely.
     */
    public static function clamped(int $start, int $end, ?int $max = null): self {
        $max ??= Config::$settings->maxStatsWindow;

        if ($max > 0 && ($end - $start) > $max) {
            return new self($end - $max, $end, true, $max);
        }

        return new self($start, $end, false, $max);
    }

    /**
     * The range as given, for callers that have already bounded it themselves.
     */
    public static function raw(int $start, int $end): self {
        return new self($start, $end, false);
    }

    public function duration(): int {
        return max(0, $this->end - $this->start);
    }

    /**
     * The message the UI shows when a range was shortened, and the same text MCP returns.
     */
    public function clampNotice(): string {
        // The bound this window was built with, not the configured one: a caller may pass its
        // own (the MCP tools do), and quoting the setting then names a number nobody applied.
        $max = $this->max > 0 ? $this->max : Config::$settings->maxStatsWindow;

        return 'Time window clamped to ' . self::humanize($max) . ' (NFSEN_MAX_STATS_WINDOW).';
    }

    /**
     * A duration in whatever unit reads naturally.
     *
     * Days alone are not enough: a one-hour cap formatted as days rounds to "0 days", which
     * tells the reader nothing and looks broken.
     */
    public static function humanize(int $seconds): string {
        if ($seconds >= 86400) {
            return self::plural(round($seconds / 86400, 1), 'day');
        }

        if ($seconds >= 3600) {
            return self::plural(round($seconds / 3600, 1), 'hour');
        }

        return self::plural(max(1, (int) round($seconds / 60)), 'minute');
    }

    /**
     * nfdump's -R takes the range as a pair.
     *
     * @return array{int, int}
     */
    public function toRangeOption(): array {
        return [$this->start, $this->end];
    }

    private static function plural(float|int $value, string $unit): string {
        $rendered = (float) $value === floor((float) $value) ? (string) (int) $value : (string) $value;

        return $rendered . ' ' . $unit . ((float) $value === 1.0 ? '' : 's');
    }
}
