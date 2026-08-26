<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Throttled progress reporting for a long-running query.
 *
 * A filtered-graph build runs one nfdump per time bin and can easily produce several
 * hundred progress ticks. Each tick reaches the browser as a Datastar signal patch, so
 * emitting all of them would be wasteful — this collapses them to at most one every
 * $minInterval seconds, plus one whenever the per-mille figure has moved far enough to
 * be worth showing. The first and last tick always get through, so a bar never starts
 * late or stops short of its end.
 *
 * The clock and the emitter are both injected, which keeps the throttling logic
 * testable without a running server or a real timer.
 */
final class QueryProgress {
    private float $startedAt;
    private float $lastEmit = 0.0;
    private int $lastPermille = -1;
    private bool $finished = false;

    /**
     * @param \Closure(int, string): void $emit        receives (permille, eta) when a tick survives throttling
     * @param float                       $minInterval seconds between emissions
     * @param int                         $minDelta    per-mille change that forces an emission
     * @param null|\Closure(): float      $clock       defaults to microtime(true)
     */
    public function __construct(
        private readonly \Closure $emit,
        private readonly float $minInterval = 0.25,
        private readonly int $minDelta = 5,
        private readonly ?\Closure $clock = null,
    ) {
        $this->startedAt = $this->now();
    }

    /**
     * Record progress. Emits at most once per throttle window.
     *
     * Per-mille is capped at 999 until finish() runs, so the bar never reads "done"
     * while work is still outstanding.
     */
    public function update(int $done, int $total): void {
        if ($this->finished) {
            return;
        }

        $permille = ($total > 0) ? min(999, (int) floor(($done / $total) * 1000)) : 0;
        $now = $this->now();
        $isFirst = $this->lastPermille === -1;

        if (!$isFirst
            && abs($permille - $this->lastPermille) < $this->minDelta
            && ($now - $this->lastEmit) < $this->minInterval) {
            return;
        }

        $this->lastPermille = $permille;
        $this->lastEmit = $now;
        ($this->emit)($permille, $this->eta($done, $total, $now));
    }

    /** Force a final 1000‰ tick. Idempotent — later update() calls are ignored. */
    public function finish(): void {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $this->lastPermille = 1000;
        ($this->emit)(1000, '');
    }

    /** Seconds elapsed since construction. */
    public function elapsed(): float {
        return $this->now() - $this->startedAt;
    }

    /** Compact duration for display, e.g. "~45s", "~2m 5s", "~1h 3m". */
    public static function formatEta(int $seconds): string {
        if ($seconds < 60) {
            return "~{$seconds}s";
        }
        if ($seconds < 3600) {
            return '~' . (int) ($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }

        return '~' . (int) ($seconds / 3600) . 'h ' . (int) (($seconds % 3600) / 60) . 'm';
    }

    /**
     * Human-readable estimate of the time left, or '' when there isn't enough
     * history to extrapolate from (under a second of work, or nothing done yet).
     */
    private function eta(int $done, int $total, float $now): string {
        $elapsed = $now - $this->startedAt;
        if ($elapsed < 1.0 || $done <= 0 || $done >= $total) {
            return '';
        }

        return self::formatEta((int) (($total - $done) / ($done / $elapsed)));
    }

    private function now(): float {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
