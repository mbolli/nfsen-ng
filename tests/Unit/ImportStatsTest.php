<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\ImportStats;

beforeEach(fn () => ImportStats::reset());
afterEach(fn () => ImportStats::reset());

describe('ImportStats', function (): void {
    test('an empty ring reports nothing', function (): void {
        expect(ImportStats::summary(1_000_000))->toBe(['filesPerMinute' => 0.0, 'avgMs' => 0.0, 'lastTs' => 0, 'samples' => 0]);
    });

    test('rate and average over the last 15 minutes', function (): void {
        $now = 1_000_000;
        // One file per source every 5 minutes for an hour: the last 15 minutes hold 3 per source.
        for ($t = $now - 3600 + 300; $t <= $now; $t += 300) {
            ImportStats::record('live', 'gw', 'nfcapd', 40, $t);
            ImportStats::record('live', 'core', 'nfcapd', 60, $t);
        }

        expect(ImportStats::summary($now))->toBe(['filesPerMinute' => 0.4, 'avgMs' => 50.0, 'lastTs' => $now, 'samples' => 6]);
    });

    test('samples older than 15 minutes do not count, the last import time still shows', function (): void {
        ImportStats::record('live', 'gw', 'nfcapd', 100, 1000);

        expect(ImportStats::summary(1000 + 900))->toBe(['filesPerMinute' => 0.0, 'avgMs' => 0.0, 'lastTs' => 1000, 'samples' => 0])
            ->and(ImportStats::summary(1000 + 899)['samples'])->toBe(1)
        ;
    });

    test('keeps the last 100 imports', function (): void {
        for ($i = 1; $i <= 150; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", $i, 1000);
        }

        $summary = ImportStats::summary(1000);

        // Imports 51 to 150 remain, so their average is 100.5 ms.
        expect($summary['samples'])->toBe(ImportStats::CAPACITY)
            ->and($summary['avgMs'])->toBe(100.5)
        ;
    });

    test('a bulk import is rated over its own span, whether or not it filled the ring', function (int $files, int $perSecond, float $rate): void {
        $now = 10_000;
        for ($i = 0; $i < $files; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", 5, $now - 4 + intdiv($i, $perSecond));
        }

        expect(ImportStats::summary($now)['filesPerMinute'])->toBe($rate);
    })->with([
        '100 files in 5 s' => [100, 20, 1200.0],
        '99 files in 5 s' => [99, 20, 1188.0],
        '50 files in 5 s' => [50, 10, 600.0],
    ]);

    test('the rate falls off once a bulk import ends', function (): void {
        $now = 10_000;
        for ($i = 0; $i < 100; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", 5, $now - 4 + intdiv($i, 20));
        }

        // The ring is full, so the oldest second is left out: 80 files since $now - 3.
        expect(ImportStats::summary($now + 300)['filesPerMinute'])->toBe(15.84)
            ->and(ImportStats::summary($now + 895)['filesPerMinute'])->toBe(5.35)
        ;
    });

    test('a bulk import within one second reads as a burst', function (): void {
        for ($i = 0; $i < 100; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", 1, 10_000);
        }
        $oneSecond = ImportStats::summary(10_000)['filesPerMinute'];

        ImportStats::reset();
        for ($i = 0; $i < 100; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", 1, 10_000 + intdiv($i, 50));
        }

        // Whole-second stamps only bound the span; the two readings differ by at most that second.
        expect($oneSecond)->toBe(6000.0)
            ->and(ImportStats::summary(10_001)['filesPerMinute'])->toBe(3000.0)
        ;
    });

    test('a sub-second burst reads the same whether or not it crosses a second', function (float $start, int $now): void {
        for ($i = 0; $i < 100; ++$i) {
            ImportStats::record('live', 'gw', "f{$i}", 1, $start + $i * 0.0018);
        }

        expect(ImportStats::summary($now)['filesPerMinute'])->toBe(33333.33);
    })->with([
        'within a second' => [10_000.10, 10_000],
        'across a second' => [10_000.95, 10_001],
    ]);

    test('files recorded with the default clock count in the current second', function (): void {
        ImportStats::record('live', 'gw', 'f', 5);

        expect(ImportStats::summary(time())['samples'])->toBe(1);
    });

    test('a single file reads as one file per rotation', function (): void {
        ImportStats::record('live', 'gw', 'f', 5, 10_000);

        expect(ImportStats::summary(10_000)['filesPerMinute'])->toBe(0.2)
            ->and(ImportStats::summary(10_299)['filesPerMinute'])->toBe(0.2)
            ->and(ImportStats::summary(10_600)['filesPerMinute'])->toBe(0.1)
        ;
    });

    test('sources rotating together count as one arrival', function (int $sources, float $rate): void {
        // Each source delivers its file one second after the previous one, every 5 minutes for 2 hours.
        $start = 100_000;
        for ($t = $start; $t < $start + 7200; $t += 300) {
            for ($i = 0; $i < $sources; ++$i) {
                ImportStats::record('live', "s{$i}", 'nfcapd', 5, $t + $i);
            }
        }
        $last = $start + 7200 - 300;

        foreach ([$sources - 1, 2 + $sources, 60, 150, 250, 299] as $after) {
            expect(ImportStats::summary($last + $after)['filesPerMinute'])->toBe($rate);
        }
    })->with([
        '2 sources' => [2, 0.4],
        '5 sources' => [5, 1.0],
    ]);

    test('the first interval after a restart reads the arrival rate', function (int $sources, float $rate): void {
        $start = 100_000;
        for ($i = 0; $i < $sources; ++$i) {
            ImportStats::record('live', "s{$i}", 'nfcapd', 5, $start + $i);
        }

        foreach ([$sources - 1, 30, 60, 120, 299] as $after) {
            expect(ImportStats::summary($start + $after)['filesPerMinute'])->toBe($rate);
        }

        for ($i = 0; $i < $sources; ++$i) {
            ImportStats::record('live', "s{$i}", 'nfcapd', 5, $start + 300 + $i);
        }

        foreach ([300 + $sources - 1, 400, 599] as $after) {
            expect(ImportStats::summary($start + $after)['filesPerMinute'])->toBe($rate);
        }
    })->with([
        '2 sources' => [2, 0.4],
        '5 sources' => [5, 1.0],
    ]);

    test('an interval whose files land over two seconds reads flat', function (): void {
        $start = 100_000;
        for ($k = 0; $k < 12; ++$k) {
            foreach ([0, 0, 0, 1, 1] as $source => $offset) {
                ImportStats::record('live', "s{$source}", 'nfcapd', 5, $start + $k * 300 + $offset);
            }
        }
        $last = $start + 11 * 300;

        foreach ([2, 60, 150, 250] as $after) {
            expect(ImportStats::summary($last + $after)['filesPerMinute'])->toBe(1.0);
        }
    });

    test('slow imports of one rotation still count as one arrival', function (): void {
        // Three sources, each import takes 40 s and starts when the previous one ends.
        foreach ([40, 80, 120] as $source => $end) {
            ImportStats::record('live', "s{$source}", 'nfcapd', 40_000, 100_000 + $end);
        }

        expect(ImportStats::summary(100_120)['filesPerMinute'])->toBe(0.6);
    });

    test('more sources than the ring holds still read the arrival rate', function (): void {
        // 40 sources: the ring keeps 20 files of the oldest interval in the window and leaves them out.
        $start = 100_000;
        for ($k = 0; $k < 12; ++$k) {
            for ($i = 0; $i < 40; ++$i) {
                ImportStats::record('live', "s{$i}", 'nfcapd', 5, $start + $k * 300);
            }
        }
        $last = $start + 11 * 300;

        expect(ImportStats::summary($last + 10)['filesPerMinute'])->toBe(8.0)
            ->and(ImportStats::summary($last + 299)['filesPerMinute'])->toBe(8.0)
        ;
    });

    test('the rate falls off once files stop arriving', function (): void {
        $start = 100_000;
        for ($t = $start; $t <= $start + 3000; $t += 300) {
            ImportStats::record('live', 'gw', 'nfcapd', 5, $t);
            ImportStats::record('live', 'core', 'nfcapd', 5, $t + 1);
        }
        $last = $start + 3000;

        expect(ImportStats::summary($last + 299)['filesPerMinute'])->toBe(0.4)
            ->and(ImportStats::summary($last + 450)['filesPerMinute'])->toBe(0.32)
            ->and(ImportStats::summary($last + 800)['filesPerMinute'])->toBe(0.15)
            ->and(ImportStats::summary($last + 901)['filesPerMinute'])->toBe(0.0)
        ;
    });

    test('a negative duration is recorded as 0', function (): void {
        ImportStats::record('live', 'gw', 'f', -5, 100);

        expect(ImportStats::summary(100)['avgMs'])->toBe(0.0);
    });
});
