<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\QueryProgress;

/**
 * Collects emitted ticks and drives a fake clock, so throttling is asserted
 * on exact timings rather than on wall-clock luck.
 *
 * Returned as an object rather than a list: a by-reference array element does not
 * survive list-destructuring, so the collected ticks would always read as empty.
 */
function progressHarness(float $minInterval = 0.25, int $minDelta = 5): object {
    return new class($minInterval, $minDelta) {
        /** @var list<array{int, string, int, int}> */
        public array $ticks = [];
        public QueryProgress $progress;
        private float $time = 1000.0;

        public function __construct(float $minInterval, int $minDelta) {
            $this->progress = new QueryProgress(
                emit: function (int $permille, string $eta, int $done, int $total): void {
                    $this->ticks[] = [$permille, $eta, $done, $total];
                },
                minInterval: $minInterval,
                minDelta: $minDelta,
                clock: fn (): float => $this->time,
            );
        }

        public function advance(float $seconds): void {
            $this->time += $seconds;
        }
    };
}

describe('QueryProgress throttling', function (): void {
    test('always emits the first tick', function (): void {
        $h = progressHarness();
        $h->progress->update(1, 100);

        expect($h->ticks)->toHaveCount(1)
            ->and($h->ticks[0][0])->toBe(10)
        ;
    });

    test('suppresses ticks that are both too soon and too small', function (): void {
        $h = progressHarness();
        $h->progress->update(1, 1000);   // 1‰  — emitted (first)
        $h->progress->update(2, 1000);   // 2‰  — +1‰, no time passed
        $h->progress->update(3, 1000);   // 3‰  — still under both thresholds

        expect($h->ticks)->toHaveCount(1);
    });

    test('emits once the per-mille delta is large enough, even with no time elapsed', function (): void {
        $h = progressHarness();
        $h->progress->update(1, 1000);
        $h->progress->update(60, 1000);  // +59‰

        expect($h->ticks)->toHaveCount(2)
            ->and($h->ticks[1][0])->toBe(60)
        ;
    });

    test('emits once the interval has elapsed, even for a tiny delta', function (): void {
        $h = progressHarness();
        $h->progress->update(1, 1000);
        $h->advance(0.3);
        $h->progress->update(2, 1000);   // +1‰ but past the 0.25 s window

        expect($h->ticks)->toHaveCount(2);
    });

    test('never reports 1000 before finish', function (): void {
        $h = progressHarness();
        $h->progress->update(100, 100);

        expect($h->ticks[0][0])->toBe(999);
    });

    test('finish emits a final full tick', function (): void {
        $h = progressHarness();
        $h->progress->update(50, 100);
        $h->progress->finish();

        expect($h->ticks[count($h->ticks) - 1])->toBe([1000, '', 0, 0]);
    });

    test('finish is idempotent and freezes later updates', function (): void {
        $h = progressHarness();
        $h->progress->finish();
        $h->progress->finish();
        $h->progress->update(1, 100);

        expect($h->ticks)->toHaveCount(1);
    });

    test('handles a zero total without dividing by zero', function (): void {
        $h = progressHarness();
        $h->progress->update(0, 0);

        expect($h->ticks[0][0])->toBe(0);
    });
});

describe('QueryProgress ETA', function (): void {
    test('stays empty until there is a second of history', function (): void {
        $h = progressHarness();
        $h->advance(0.5);
        $h->progress->update(10, 100);

        expect($h->ticks[0][1])->toBe('');
    });

    test('extrapolates from the observed rate', function (): void {
        $h = progressHarness();
        $h->advance(10.0);
        $h->progress->update(10, 100);   // 10 units in 10 s => 90 left => ~90 s

        expect($h->ticks[0][1])->toBe('~1m 30s');
    });

    test('is empty on the last unit of work', function (): void {
        $h = progressHarness();
        $h->advance(5.0);
        $h->progress->update(100, 100);

        expect($h->ticks[0][1])->toBe('');
    });

    test('elapsed tracks the injected clock', function (): void {
        $h = progressHarness();
        $h->advance(7.5);

        expect($h->progress->elapsed())->toBe(7.5);
    });
});

describe('QueryProgress::formatEta', function (): void {
    test('formats seconds', fn () => expect(QueryProgress::formatEta(45))->toBe('~45s'));
    test('formats minutes and seconds', fn () => expect(QueryProgress::formatEta(125))->toBe('~2m 5s'));
    test('formats hours and minutes', fn () => expect(QueryProgress::formatEta(3780))->toBe('~1h 3m'));
    test('formats zero', fn () => expect(QueryProgress::formatEta(0))->toBe('~0s'));
});

describe('QueryProgress emit payload', function (): void {
    // The emitter receives the raw counts so a status line ("N / M intervals") does not
    // have to smuggle them in through a by-reference capture.
    test('carries the done and total counts', function (): void {
        $h = progressHarness();
        $h->progress->update(3, 12);

        expect($h->ticks[0][2])->toBe(3)
            ->and($h->ticks[0][3])->toBe(12)
        ;
    });

    test('finish echoes the total back on both counts', function (): void {
        $h = progressHarness();
        $h->progress->finish(42);

        expect($h->ticks[0])->toBe([1000, '', 42, 42]);
    });
});
