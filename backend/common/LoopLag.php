<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

/**
 * Event-loop lag of this worker: a timer that measures how late it fires. Whatever holds the
 * loop (a render, a synchronous file scan, a SQLite write) delays it by as long.
 *
 * @phpstan-type LagSummary array{samples: int, window: int, p50: ?float, p95: ?float, max: ?float}
 */
final class LoopLag {
    public const int INTERVAL_MS = 100;

    /** Seconds the percentiles cover. */
    public const int WINDOW = 60;

    /** Ticks in one window, since a tick is due INTERVAL_MS after the previous one fired. */
    public const int CAPACITY = self::WINDOW * 1000 / self::INTERVAL_MS;

    private static ?int $timer = null;

    /** hrtime in ns at which the pending tick is due. */
    private static float $due = 0.0;

    /** @var array<int, float> ring of lags in ms */
    private static array $lags = [];

    /** @var array<int, float> ring of the microtime each lag was measured at */
    private static array $at = [];

    private static int $next = 0;

    /** Starts the probe in this worker; false when it already runs or no event loop does. */
    public static function start(): bool {
        if (self::$timer !== null || Coroutine::getCid() <= 0) {
            return false;
        }
        self::arm();

        return self::$timer !== null;
    }

    public static function stop(): void {
        if (self::$timer !== null) {
            Timer::clear(self::$timer); // @phpstan-ignore arguments.count (OpenSwoole 26.2's arginfo leaves out the timer id)
            self::$timer = null;
        }
    }

    public static function running(): bool {
        return self::$timer !== null;
    }

    /**
     * One tick's lag, plus the ticks a longer stall swallowed (lag minus one interval, two, ...)
     * as HdrHistogram corrects coordinated omission, so a stall weighs by its length.
     */
    public static function record(float $lagMs, ?float $now = null): void {
        $now ??= microtime(true);
        $lagMs = max(0.0, $lagMs);
        $missed = $lagMs - self::INTERVAL_MS;
        for ($i = 1; $i < self::CAPACITY && $missed >= self::INTERVAL_MS; ++$i) {
            self::put($missed, $now);
            $missed -= self::INTERVAL_MS;
        }
        self::put($lagMs, $now);
    }

    /**
     * p50, p95 and max over the last WINDOW seconds; null before the first tick.
     *
     * @return LagSummary
     */
    public static function summary(?float $now = null): array {
        $now ??= microtime(true);
        $lags = [];
        foreach (self::$at as $i => $at) {
            if ($at > $now - self::WINDOW && $at <= $now) {
                $lags[] = self::$lags[$i];
            }
        }
        sort($lags);
        $n = \count($lags);
        if ($n === 0) {
            return ['samples' => 0, 'window' => self::WINDOW, 'p50' => null, 'p95' => null, 'max' => null];
        }

        return [
            'samples' => $n,
            'window' => self::WINDOW,
            'p50' => self::percentile($lags, 50),
            'p95' => self::percentile($lags, 95),
            'max' => $lags[$n - 1],
        ];
    }

    /**
     * Nearest-rank percentile.
     *
     * @param non-empty-list<float> $sorted ascending
     */
    public static function percentile(array $sorted, float $p): float {
        $rank = (int) ceil($p / 100 * \count($sorted));

        return $sorted[max(0, min(\count($sorted), $rank) - 1)];
    }

    /** Tests: forget every tick. */
    public static function reset(): void {
        self::$lags = [];
        self::$at = [];
        self::$next = 0;
    }

    private static function put(float $lagMs, float $at): void {
        self::$lags[self::$next] = $lagMs;
        self::$at[self::$next] = $at;
        self::$next = (self::$next + 1) % self::CAPACITY;
    }

    /** Each tick arms the next one, so a late tick delays the whole chain rather than bunching up. */
    private static function arm(): void {
        self::$due = (float) hrtime(true) + self::INTERVAL_MS * 1e6;
        $id = Timer::after(self::INTERVAL_MS, static function (): void {
            self::record(((float) hrtime(true) - self::$due) / 1e6);
            if (self::$timer !== null) {
                self::arm();
            }
        });
        self::$timer = \is_int($id) ? $id : null;
    }
}
