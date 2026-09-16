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
    foreach (NfdumpSlots::running() as $handle => $pids) {
        foreach ($pids as $pid) {
            NfdumpSlots::unregister($handle, $pid);
        }
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
        NfdumpSlots::unregister('tab-a', 111);

        expect(NfdumpSlots::pidFor('tab-a'))->toBeNull()
            ->and(NfdumpSlots::pidFor('tab-b'))->toBe(222)
        ;
    });

    test('running() lists every owned process', function (): void {
        NfdumpSlots::register('tab-a', 111);
        NfdumpSlots::register('tab-b', 222);

        expect(NfdumpSlots::running())->toBe(['tab-a' => [111], 'tab-b' => [222]]);
    });

    // The import daemon and every MCP call share the default handle, so two runs can own it at
    // once. A scalar meant the second overwrote the first and then erased it on exit, leaving a
    // live process that Kill could not find.
    test('one handle can own several concurrent processes', function (): void {
        NfdumpSlots::register('default', 111);
        NfdumpSlots::register('default', 222);

        expect(NfdumpSlots::running()['default'])->toBe([111, 222]);
    });

    test('a finished run leaves its sibling registered', function (): void {
        NfdumpSlots::register('default', 111);
        NfdumpSlots::register('default', 222);
        NfdumpSlots::unregister('default', 111);

        expect(NfdumpSlots::running()['default'])->toBe([222])
            ->and(NfdumpSlots::pidFor('default'))->toBe(222)
        ;
    });

    test('unregistering a pid the handle does not own changes nothing', function (): void {
        NfdumpSlots::register('tab-a', 111);
        NfdumpSlots::unregister('tab-a', 999);

        expect(NfdumpSlots::pidFor('tab-a'))->toBe(111);
    });
});

describe('NfdumpSlots leak safety', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    // Nfdump::execute() holds a slot across proc_open. That throw used to skip release(), and
    // two such failures wedged every later query behind the acquire timeout until a restart.
    test('a failure between acquire and release must not leak the slot', function (): void {
        slotSettings(1);

        try {
            NfdumpSlots::acquire();

            try {
                throw new RuntimeException('proc_open failed');
            } finally {
                NfdumpSlots::release();
            }
        } catch (RuntimeException) {
            // expected
        }

        expect(NfdumpSlots::inUse())->toBe(0);

        // Still usable afterwards, which is the point.
        NfdumpSlots::acquire(0.2);
        expect(NfdumpSlots::inUse())->toBe(1);
    });
});
