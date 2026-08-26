<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\NfdumpProgressWatcher;
use mbolli\nfsen_ng\common\QueryProgress;

/**
 * Watcher wired to scripted pid/byte sequences and a collecting QueryProgress,
 * so sampling behaviour is asserted without spawning nfdump.
 */
function watcherHarness(int $totalBytes, array $pids, array $reads): object {
    return new class($totalBytes, $pids, $reads) {
        /** @var list<array{int, string, int, int}> */
        public array $ticks = [];
        public NfdumpProgressWatcher $watcher;

        public function __construct(int $totalBytes, private array $pids, private array $reads) {
            $progress = new QueryProgress(
                emit: function (int $permille, string $eta, int $done, int $total): void {
                    $this->ticks[] = [$permille, $eta, $done, $total];
                },
                minInterval: 0.0,
                minDelta: 0,
                clock: fn (): float => 1000.0,
            );

            $this->watcher = new NfdumpProgressWatcher(
                $progress,
                $totalBytes,
                fn (): ?int => array_shift($this->pids),
                fn (int $pid): ?int => array_shift($this->reads),
            );
        }
    };
}

describe('NfdumpProgressWatcher', function (): void {
    test('reports the read fraction as per-mille', function (): void {
        $h = watcherHarness(1000, [42], [250]);

        expect($h->watcher->tick())->toBeTrue()
            ->and($h->ticks[0][0])->toBe(250)
        ;
    });

    // rchar counts every read the process makes, not just capture files, so it can
    // exceed the total. Saturating beats reporting more than 100%.
    test('clamps a byte count that overshoots the total', function (): void {
        $h = watcherHarness(1000, [42], [5000]);
        $h->watcher->tick();

        expect($h->ticks[0][0])->toBe(999)   // capped at 999 until finish()
            ->and($h->ticks[0][2])->toBe(1000)
        ;
    });

    test('keeps polling while nfdump has not started yet', function (): void {
        $h = watcherHarness(1000, [null, 42], [250]);

        expect($h->watcher->tick())->toBeTrue()   // no pid — nothing sampled
            ->and($h->ticks)->toBeEmpty()
            ->and($h->watcher->tick())->toBeTrue()
            ->and($h->ticks)->toHaveCount(1)
        ;
    });

    // No procfs (FreeBSD, macOS — cf. #143): stop polling, caller falls back to a spinner.
    test('stops permanently when the platform cannot report bytes', function (): void {
        $h = watcherHarness(1000, [42, 42], [null, 500]);

        expect($h->watcher->tick())->toBeFalse()
            ->and($h->watcher->isTrackable())->toBeFalse()
            ->and($h->watcher->tick())->toBeFalse()
            ->and($h->ticks)->toBeEmpty()
        ;
    });

    test('is untrackable when the total size is unknown', function (): void {
        $h = watcherHarness(0, [42], [100]);

        expect($h->watcher->isTrackable())->toBeFalse()
            ->and($h->watcher->tick())->toBeFalse()
            ->and($h->ticks)->toBeEmpty()
        ;
    });

    test('tracks a rising byte count across several samples', function (): void {
        $h = watcherHarness(1000, [42, 42, 42], [100, 400, 900]);
        $h->watcher->tick();
        $h->watcher->tick();
        $h->watcher->tick();

        expect(array_column($h->ticks, 0))->toBe([100, 400, 900]);
    });

    test('forRunningNfdump builds a watcher bound to the live process', function (): void {
        $watcher = NfdumpProgressWatcher::forRunningNfdump(
            new QueryProgress(emit: static function (): void {}),
            4096
        );

        // No nfdump running, so nothing is sampled — but it stays trackable.
        expect($watcher->tick())->toBeTrue()
            ->and($watcher->isTrackable())->toBeTrue();
    });
});
