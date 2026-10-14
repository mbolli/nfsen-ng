<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\common\FlowRowMemory;
use mbolli\nfsen_ng\store\Database;

beforeEach(function (): void {
    $this->db = Database::open(':memory:');
    Database::useShared($this->db);
    FlowRowMemory::reset();
});

afterEach(function (): void {
    Database::resetShared();
    FlowRowMemory::reset();
});

describe('FlowRowMemory', function (): void {
    test('plans nothing until it has a few samples', function (): void {
        FlowRowMemory::record(10_000, 30_000_000);
        FlowRowMemory::record(10_000, 32_000_000);

        expect(FlowRowMemory::perRow())->toBeNull();
    });

    test('plans the median row size with its margin, from the store after a restart', function (): void {
        foreach ([3000, 2800, 9000, 3200] as $perRow) {
            FlowRowMemory::record(1000, 1000 * $perRow);
        }
        FlowRowMemory::reset();

        // Median of 2800, 3000, 3200 and 9000 is 3100; one odd run does not move it far.
        expect(FlowRowMemory::samples())->toBe([3000, 2800, 9000, 3200])
            ->and(FlowRowMemory::perRow())->toBe((int) ceil(3100 * FlowRowMemory::MARGIN))
        ;
    });

    test('keeps the newest samples and ignores empty runs', function (): void {
        foreach (range(1, FlowRowMemory::KEEP + 5) as $i) {
            FlowRowMemory::record(1000, 1000 * $i);
        }
        FlowRowMemory::record(0, 5_000_000);
        FlowRowMemory::record(1000, -1);

        expect(FlowRowMemory::samples())->toHaveCount(FlowRowMemory::KEEP)
            ->and(FlowRowMemory::samples()[0])->toBe(6)
        ;
    });

    test('a learned size replaces the fixed estimate of a listing\'s peak', function (): void {
        $guess = FlowActions::expectedPeak(100_000);
        foreach ([1000, 1000, 1000] as $perRow) {
            FlowRowMemory::record(1000, 1000 * $perRow);
        }

        expect(FlowActions::expectedPeak(100_000))->toBe(memory_get_usage(true) + 100_000 * (int) ceil(1000 * FlowRowMemory::MARGIN))
            ->and(FlowActions::expectedPeak(100_000))->toBeLessThan($guess)
        ;
    });
});
