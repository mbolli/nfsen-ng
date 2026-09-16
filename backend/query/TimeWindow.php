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
    ) {}

    /**
     * Clamps to the configured maximum. A max of 0 disables the bound entirely.
     */
    public static function clamped(int $start, int $end, ?int $max = null): self {
        $max ??= Config::$settings->maxStatsWindow;

        if ($max > 0 && ($end - $start) > $max) {
            return new self($end - $max, $end, true);
        }

        return new self($start, $end, false);
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
        return 'Time window clamped to ' . round(Config::$settings->maxStatsWindow / 86400, 1)
            . ' days (NFSEN_MAX_STATS_WINDOW).';
    }

    /**
     * nfdump's -R takes the range as a pair.
     *
     * @return array{int, int}
     */
    public function toRangeOption(): array {
        return [$this->start, $this->end];
    }
}
