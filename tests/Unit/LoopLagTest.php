<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\LoopLag;
use OpenSwoole\Coroutine;

/** Holds the event loop for $ms without yielding, as a synchronous render or file scan does. */
function loopLagBusy(int $ms): void {
    $end = hrtime(true) + $ms * 1_000_000;
    while (hrtime(true) < $end);
}

beforeEach(function (): void {
    LoopLag::stop();
    LoopLag::reset();
});

afterEach(function (): void {
    LoopLag::stop();
    LoopLag::reset();
});

describe('LoopLag::summary', function (): void {
    $now = 1_790_000_000.0;

    test('is empty before the first tick', function () use ($now): void {
        expect(LoopLag::summary($now))->toBe(['samples' => 0, 'window' => 60, 'p50' => null, 'p95' => null, 'max' => null]);
    });

    test('takes nearest-rank percentiles and the max', function () use ($now): void {
        foreach (range(1, 20) as $ms) {
            LoopLag::record((float) $ms, $now - 1);
        }

        expect(LoopLag::summary($now))->toBe(['samples' => 20, 'window' => 60, 'p50' => 10.0, 'p95' => 19.0, 'max' => 20.0]);
    });

    test('a single tick is every percentile', function () use ($now): void {
        LoopLag::record(7.5, $now);

        expect(LoopLag::summary($now))->toMatchArray(['samples' => 1, 'p50' => 7.5, 'p95' => 7.5, 'max' => 7.5]);
    });

    test('covers the last 60 seconds only', function () use ($now): void {
        LoopLag::record(90.0, $now - 61);
        LoopLag::record(80.0, $now - 60);
        LoopLag::record(3.0, $now - 59);
        LoopLag::record(2.0, $now);

        expect(LoopLag::summary($now))->toMatchArray(['samples' => 2, 'max' => 3.0])
            ->and(LoopLag::summary($now - 30))->toMatchArray(['samples' => 3, 'max' => 90.0])
        ;
    });

    test('a timer that fired early counts as on time', function () use ($now): void {
        LoopLag::record(-0.4, $now);

        expect(LoopLag::summary($now)['max'])->toBe(0.0);
    });
});

describe('LoopLag::record', function (): void {
    $now = 1_790_000_000.0;

    // A 350 ms stall swallowed the ticks due 100 and 200 ms into it, which would have waited 250 and 150 ms.
    test('adds the ticks a stall swallowed, one interval apart', function () use ($now): void {
        LoopLag::record(350.0, $now);
        LoopLag::record(150.0, $now);
        LoopLag::record(99.0, $now);

        expect(LoopLag::summary($now))->toMatchArray(['samples' => 5, 'max' => 350.0])
            ->and(LoopLag::percentile([99.0, 150.0, 150.0, 250.0, 350.0], 50))->toBe(150.0)
            ->and(LoopLag::summary($now)['p50'])->toBe(150.0)
        ;
    });

    test('a stall weighs by its length: one 2 s stall in 10 s moves the p95', function () use ($now): void {
        foreach (range(1, 80) as $i) {
            LoopLag::record(1.0, $now - 10 + $i / 10);
        }
        LoopLag::record(2000.0, $now);

        $lag = LoopLag::summary($now);

        expect($lag['samples'])->toBe(100)
            ->and($lag['p50'])->toBe(1.0)
            ->and($lag['p95'])->toBe(1500.0)
            ->and($lag['max'])->toBe(2000.0)
        ;
    });

    test('keeps one window of ticks, the newest', function () use ($now): void {
        foreach (range(1, 10) as $i) {
            LoopLag::record(500.0, $now - 2);
        }
        foreach (range(1, LoopLag::CAPACITY) as $i) {
            LoopLag::record(1.0, $now - 1);
        }

        expect(LoopLag::summary($now))->toMatchArray(['samples' => LoopLag::CAPACITY, 'max' => 1.0]);
    });

    test('a stall longer than the window fills it once and keeps its own lag as the max', function () use ($now): void {
        LoopLag::record(1.0, $now - 1);
        LoopLag::record(600_000.0, $now);

        $lag = LoopLag::summary($now);

        expect($lag['samples'])->toBe(LoopLag::CAPACITY)
            ->and($lag['max'])->toBe(600_000.0)
            ->and($lag['p50'])->toBeGreaterThan(500_000.0)
        ;
    });
});

describe('LoopLag timer', function (): void {
    test('does not start outside an event loop', function (): void {
        expect(LoopLag::start())->toBeFalse()
            ->and(LoopLag::running())->toBeFalse()
        ;
    });

    test('measures how long the loop was held, and stops', function (): void {
        $idle = $held = null;
        $second = true;
        Coroutine::run(static function () use (&$idle, &$held, &$second): void {
            LoopLag::start();
            $second = LoopLag::start();
            Coroutine::usleep(450_000);
            $idle = LoopLag::summary();

            loopLagBusy(400);
            Coroutine::usleep(150_000);
            $held = LoopLag::summary();
            LoopLag::stop();
        });

        expect($second)->toBeFalse()
            ->and(LoopLag::running())->toBeFalse()
            ->and($idle['samples'])->toBeGreaterThanOrEqual(3)
            ->and($idle['max'])->toBeLessThan(80.0)
            ->and($held['max'])->toBeGreaterThanOrEqual(250.0)
            ->and($held['max'])->toBeLessThan(1000.0)
            ->and($held['samples'])->toBeGreaterThan($idle['samples'])
        ;
    });
});
