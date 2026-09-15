<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\NfdumpSlots;

function slotSettings(int $maxProcesses): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp',
            'profile' => 'live',
            'max-processes' => $maxProcesses,
        ],
        'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
        'log' => ['priority' => LOG_WARNING],
    ]);
}

function releaseAllSlots(): void {
    while (NfdumpSlots::inUse() > 0) {
        NfdumpSlots::release();
    }
    foreach (array_keys(NfdumpSlots::running()) as $handle) {
        NfdumpSlots::unregister($handle);
    }
}

describe('NfdumpSlots capacity', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    test('hands out up to the configured number of slots', function (): void {
        slotSettings(2);

        NfdumpSlots::acquire();
        NfdumpSlots::acquire();

        expect(NfdumpSlots::inUse())->toBe(2);
    });

    // The limit is the point: without a free slot the caller waits, and gives up loudly
    // rather than spawning an unbounded number of nfdump processes.
    test('times out rather than exceeding the limit', function (): void {
        slotSettings(1);
        NfdumpSlots::acquire();

        expect(fn () => NfdumpSlots::acquire(0.2))
            ->toThrow(RuntimeException::class, 'Timed out waiting for a free nfdump slot')
        ;
        expect(NfdumpSlots::inUse())->toBe(1);
    });

    test('a released slot is handed to the next caller', function (): void {
        slotSettings(1);
        NfdumpSlots::acquire();
        NfdumpSlots::release();
        NfdumpSlots::acquire();

        expect(NfdumpSlots::inUse())->toBe(1);
    });

    test('releasing more than acquired never goes negative', function (): void {
        slotSettings(1);
        NfdumpSlots::release();
        NfdumpSlots::release();

        expect(NfdumpSlots::inUse())->toBe(0);
    });

    test('a configured maximum below one still allows a single query', function (): void {
        slotSettings(0);

        NfdumpSlots::acquire(0.2);

        expect(NfdumpSlots::inUse())->toBe(1);
    });
});

describe('NfdumpSlots ownership', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    // The reason this exists: with two queries running, one static process id killed
    // whichever started last rather than the one the user asked to stop.
    test('each query owns its own process', function (): void {
        NfdumpSlots::register('tab-a', 111);
        NfdumpSlots::register('tab-b', 222);

        expect(NfdumpSlots::pidFor('tab-a'))->toBe(111)
            ->and(NfdumpSlots::pidFor('tab-b'))->toBe(222)
        ;
    });

    test('a query that owns nothing reports nothing', function (): void {
        expect(NfdumpSlots::pidFor('never-ran'))->toBeNull()
            ->and(NfdumpSlots::kill('never-ran'))->toBeNull()
        ;
    });

    test('unregistering clears only that query', function (): void {
        NfdumpSlots::register('tab-a', 111);
        NfdumpSlots::register('tab-b', 222);
        NfdumpSlots::unregister('tab-a');

        expect(NfdumpSlots::pidFor('tab-a'))->toBeNull()
            ->and(NfdumpSlots::pidFor('tab-b'))->toBe(222)
        ;
    });

    test('running() lists every owned process', function (): void {
        NfdumpSlots::register('tab-a', 111);
        NfdumpSlots::register('tab-b', 222);

        expect(NfdumpSlots::running())->toBe(['tab-a' => 111, 'tab-b' => 222]);
    });
});
