<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

function slotSettings(int $maxProcesses, string $binary = '/usr/bin/nfdump'): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => [
            'binary' => $binary,
            'profiles-data' => '/tmp',
            'profile' => 'live',
            'max-processes' => $maxProcesses,
        ],
        'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
        'log' => ['priority' => LOG_WARNING],
    ]);
}

function releaseAllSlots(): void {
    foreach (NfdumpSlots::CLASSES as $class) {
        NfdumpSlots::release($class, NfdumpSlots::inUse($class));
    }
    foreach (NfdumpSlots::running() as $handle => $pids) {
        foreach ($pids as $pid) {
            NfdumpSlots::unregister($handle, $pid);
        }
    }
}

/** Runs nfdump-canned through Nfdump::execute(), which takes a slot unless the scope holds one. */
function cannedRun(): array {
    $nfdump = new Nfdump();
    $nfdump->setOption('-o', 'csv');

    return $nfdump->execute();
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
        expect(NfdumpSlots::inUse())->toBe(1)
            ->and(NfdumpSlots::waiting())->toBe(0)
        ;
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
        NfdumpSlots::release(NfdumpSlots::BACKGROUND, 3);

        expect(NfdumpSlots::inUse())->toBe(0);
    });

    // 0 is auto now: a third of the cores, never fewer than two.
    test('a configured maximum of 0 derives the limit, at least two', function (): void {
        slotSettings(0);

        expect(NfdumpSlots::max())->toBe(Config::$settings->nfdumpMaxProcesses)
            ->and(NfdumpSlots::max())->toBeGreaterThanOrEqual(2)
            ->and(Config::$settings->nfdumpMaxProcessesAuto)->toBeTrue()
        ;
    });

    test('an unknown class is a programming error', function (): void {
        slotSettings(2);

        expect(fn () => NfdumpSlots::acquire(0.0, 'urgent'))->toThrow(InvalidArgumentException::class);
    });
});

describe('NfdumpSlots classes', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    test('interactive queries may use every free slot', function (): void {
        slotSettings(4);

        expect(NfdumpSlots::acquireMany(8))->toBe(4)
            ->and(NfdumpSlots::inUse(NfdumpSlots::INTERACTIVE))->toBe(4)
            ->and(NfdumpSlots::grantable(NfdumpSlots::INTERACTIVE))->toBe(0)
        ;
    });

    test('background work holds at most half the slots, and one stays free', function (int $max, int $background): void {
        slotSettings($max);

        expect(NfdumpSlots::backgroundMax())->toBe(max(1, intdiv($max, 2)))
            ->and(NfdumpSlots::acquireMany(16, NfdumpSlots::BACKGROUND, 0.0))->toBe($background)
            ->and(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND))->toBe(0)
            ->and(NfdumpSlots::grantable(NfdumpSlots::INTERACTIVE))->toBe($max - $background)
        ;
    })->with([
        'one slot' => [1, 1],
        'two slots' => [2, 1],
        'three slots' => [3, 1],
        'four slots' => [4, 2],
        'six slots (auto on 20 cores)' => [6, 3],
        'eight slots' => [8, 4],
    ]);

    test('background work leaves the last free slot to user queries', function (): void {
        slotSettings(4);
        NfdumpSlots::acquireMany(2);

        expect(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND))->toBe(1);

        NfdumpSlots::acquire();

        expect(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND))->toBe(0)
            ->and(fn () => NfdumpSlots::acquire(0.1, NfdumpSlots::BACKGROUND))->toThrow(RuntimeException::class)
            ->and(NfdumpSlots::grantable(NfdumpSlots::INTERACTIVE))->toBe(1)
        ;
    });

    test('with one slot, background work runs only while nothing else does', function (): void {
        slotSettings(1);
        NfdumpSlots::acquire();

        expect(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND))->toBe(0);

        NfdumpSlots::release();

        expect(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND))->toBe(1);
    });

    test('keepsOneFree() is the rule the top-N collector always used', function (): void {
        expect(NfdumpSlots::keepsOneFree(0, 1))->toBeTrue()
            ->and(NfdumpSlots::keepsOneFree(1, 1))->toBeFalse()
            ->and(NfdumpSlots::keepsOneFree(0, 2))->toBeTrue()
            ->and(NfdumpSlots::keepsOneFree(1, 2))->toBeFalse()
            ->and(NfdumpSlots::keepsOneFree(2, 4))->toBeTrue()
            ->and(NfdumpSlots::keepsOneFree(3, 4))->toBeFalse()
        ;
    });

    test('a background slot is grantable exactly when keepsOneFree() holds', function (int $max): void {
        slotSettings($max);

        for ($held = 0; $held <= $max; ++$held) {
            releaseAllSlots();
            if ($held > 0) {
                NfdumpSlots::acquireMany($held);
            }

            expect(NfdumpSlots::grantable(NfdumpSlots::BACKGROUND) > 0)->toBe(NfdumpSlots::keepsOneFree($held, $max));
        }
    })->with([1, 2, 3, 4, 6, 8]);

    test('a timeout is told apart from an nfdump failure', function (): void {
        slotSettings(1);
        NfdumpSlots::acquire();

        try {
            NfdumpSlots::acquire(0.0);
            $thrown = null;
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()
            ->and(NfdumpSlots::timedOut($thrown))->toBeTrue()
            ->and(NfdumpSlots::timedOut(new NfdumpException('bad', 'nfdump', '', NfdumpSlots::TIMED_OUT)))->toBeFalse()
        ;
    });

    test('background callers wait longer by default', function (): void {
        expect(NfdumpSlots::defaultWait(NfdumpSlots::INTERACTIVE))->toBe(NfdumpSlots::DEFAULT_WAIT_SECONDS)
            ->and(NfdumpSlots::defaultWait(NfdumpSlots::BACKGROUND))->toBe(NfdumpSlots::BACKGROUND_WAIT_SECONDS)
            ->and(NfdumpSlots::BACKGROUND_WAIT_SECONDS)->toBeGreaterThan(NfdumpSlots::DEFAULT_WAIT_SECONDS)
        ;
    });
});

describe('NfdumpSlots::acquireMany()', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    test('takes what is free now rather than waiting for all it asked for', function (): void {
        slotSettings(4);
        NfdumpSlots::acquire();

        expect(NfdumpSlots::acquireMany(8, NfdumpSlots::INTERACTIVE, 0.0))->toBe(3)
            ->and(NfdumpSlots::inUse())->toBe(4)
        ;
    });

    test('asks for at least one', function (): void {
        slotSettings(4);

        expect(NfdumpSlots::acquireMany(0))->toBe(1);
    });

    test('waits only for the first slot', function (): void {
        slotSettings(4);
        NfdumpSlots::acquireMany(4);
        $granted = null;
        $waited = 0.0;

        Coroutine::run(static function () use (&$granted, &$waited): void {
            Coroutine::create(static function (): void {
                Coroutine::usleep(100_000);
                NfdumpSlots::release();
            });
            $started = microtime(true);
            $granted = NfdumpSlots::acquireMany(3, NfdumpSlots::INTERACTIVE, 2.0);
            $waited = microtime(true) - $started;
        });

        expect($granted)->toBe(1)
            ->and($waited)->toBeLessThan(1.0)
        ;
    });

    test('slots taken together are given back together', function (): void {
        slotSettings(6);
        $granted = NfdumpSlots::acquireMany(3, NfdumpSlots::BACKGROUND);
        NfdumpSlots::release(NfdumpSlots::BACKGROUND, $granted);

        expect($granted)->toBe(3)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });
});

describe('NfdumpSlots under a full backfill', function (): void {
    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
    });

    /**
     * $workers background coroutines take a slot, hold it $holdMs, give it back and go again
     * at once, the way a backfill runs one nfdump per file. After $arriveMs the user queries
     * arrive; each records how long it waited for its slot. Order logs every grant.
     *
     * @return array{waits: list<float>, order: list<string>, peakBackground: int}
     */
    $backfill = static function (int $workers, int $holdMs, int $arriveMs, int $queries, int $queryMs): array {
        $waits = [];
        $order = [];
        $peak = 0;
        $stop = false;

        Coroutine::run(static function () use ($workers, $holdMs, $arriveMs, $queries, $queryMs, &$waits, &$order, &$peak, &$stop): void {
            for ($i = 0; $i < $workers; ++$i) {
                Coroutine::create(static function () use ($holdMs, &$order, &$peak, &$stop): void {
                    while (!$stop) {
                        NfdumpSlots::acquire(5.0, NfdumpSlots::BACKGROUND);
                        $order[] = 'background';
                        $peak = max($peak, NfdumpSlots::inUse(NfdumpSlots::BACKGROUND));
                        Coroutine::usleep($holdMs * 1000);
                        NfdumpSlots::release(NfdumpSlots::BACKGROUND);
                    }
                });
            }

            Coroutine::usleep($arriveMs * 1000);
            $done = new Channel($queries);
            for ($q = 0; $q < $queries; ++$q) {
                Coroutine::create(static function () use ($queryMs, &$waits, &$order, $done): void {
                    $started = microtime(true);
                    NfdumpSlots::acquire(5.0);
                    $waits[] = microtime(true) - $started;
                    $order[] = 'interactive';
                    Coroutine::usleep($queryMs * 1000);
                    NfdumpSlots::release();
                    $done->push(true);
                });
            }
            for ($q = 0; $q < $queries; ++$q) {
                $done->pop();
            }
            $stop = true;
        });

        return ['waits' => $waits, 'order' => $order, 'peakBackground' => $peak];
    };

    // Two slots is the old default and the tightest case with room to leave one free.
    test('a user query gets a slot at once while two slots run a backfill', function () use ($backfill): void {
        slotSettings(2);
        $run = $backfill(4, 30, 200, 1, 50);

        expect($run['waits'])->toHaveCount(1)
            ->and($run['waits'][0])->toBeLessThan(0.02)
            ->and($run['peakBackground'])->toBe(1)
        ;
    });

    test('user queries on this host\'s six slots never wait behind background work', function () use ($backfill): void {
        slotSettings(6);
        // Three user queries: the backfill holds at most three slots, so three are always free.
        $run = $backfill(12, 30, 200, 3, 100);

        expect($run['peakBackground'])->toBe(3)
            ->and(max($run['waits']))->toBeLessThan(0.02)
        ;
    });

    // More user queries than the backfill leaves free: the extra ones wait, but only for the
    // next slot to free up, and every background worker then waits behind them.
    test('a waiting user query gets the next free slot before any queued background work', function () use ($backfill): void {
        slotSettings(4);
        $run = $backfill(8, 40, 200, 4, 300);

        $firstQuery = array_search('interactive', $run['order'], true);
        $grants = array_slice($run['order'], (int) $firstQuery);
        $lastQuery = max(array_keys($grants, 'interactive', true));

        expect($run['waits'])->toHaveCount(4)
            ->and(max($run['waits']))->toBeLessThan(0.2)
            ->and(array_slice($grants, 0, $lastQuery + 1))->not->toContain('background')
        ;
    });

    test('with a single slot a user query waits for one background run at most', function () use ($backfill): void {
        slotSettings(1);
        $run = $backfill(3, 40, 200, 1, 50);

        $firstQuery = array_search('interactive', $run['order'], true);

        expect($run['waits'][0])->toBeLessThan(0.15)
            ->and($run['order'][$firstQuery - 1] ?? null)->toBe('background')
        ;
    });

    test('queued background callers are served in arrival order', function (): void {
        slotSettings(2);
        NfdumpSlots::acquire(1.0, NfdumpSlots::BACKGROUND);
        $order = [];
        $queued = [];

        Coroutine::run(static function () use (&$order, &$queued): void {
            foreach (['first', 'second', 'third'] as $i => $name) {
                Coroutine::create(static function () use ($name, $i, &$order): void {
                    Coroutine::usleep($i * 10_000);
                    NfdumpSlots::acquire(2.0, NfdumpSlots::BACKGROUND);
                    $order[] = $name;
                    Coroutine::usleep(60_000);
                    NfdumpSlots::release(NfdumpSlots::BACKGROUND);
                });
            }
            Coroutine::usleep(100_000);
            $queued = [NfdumpSlots::waiting(NfdumpSlots::BACKGROUND), NfdumpSlots::available(NfdumpSlots::BACKGROUND)];
            NfdumpSlots::release(NfdumpSlots::BACKGROUND);
        });

        expect($queued)->toBe([3, 0])
            ->and($order)->toBe(['first', 'second', 'third'])
            ->and(NfdumpSlots::waiting())->toBe(0)
        ;
    });
});

describe('NfdumpSlots scopes', function (): void {
    $stub = dirname(__DIR__) . '/Support/bin/nfdump-canned';

    beforeEach(function (): void {
        releaseAllSlots();
    });

    afterEach(function (): void {
        releaseAllSlots();
        slotSettings(4);
    });

    test('runs are interactive with their own slot unless a scope says otherwise', function (): void {
        expect(NfdumpSlots::scope())->toMatchArray(['class' => NfdumpSlots::INTERACTIVE, 'held' => false]);
    });

    test('runAs() sets the class for the runs inside and restores it afterwards', function (): void {
        $inner = NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => [
            NfdumpSlots::scope()['class'],
            NfdumpSlots::runAs(NfdumpSlots::INTERACTIVE, static fn (): string => NfdumpSlots::scope()['class']),
            NfdumpSlots::scope()['class'],
        ]);

        expect($inner)->toBe([NfdumpSlots::BACKGROUND, NfdumpSlots::INTERACTIVE, NfdumpSlots::BACKGROUND])
            ->and(NfdumpSlots::scope()['class'])->toBe(NfdumpSlots::INTERACTIVE)
        ;
    });

    test('a scope ends even when its work throws', function (): void {
        try {
            NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        expect(NfdumpSlots::scope()['class'])->toBe(NfdumpSlots::INTERACTIVE);
    });

    // Two slots with one user query running: the background rule leaves the other free, so a
    // run that asks for a background slot times out where an interactive one goes ahead.
    test('Nfdump::execute() takes a slot of the scope\'s class', function () use ($stub): void {
        slotSettings(2, $stub);
        NfdumpSlots::acquire();

        expect(fn () => NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => cannedRun(), 0.1))
            ->toThrow(RuntimeException::class, 'Timed out waiting for a free nfdump slot')
        ;
        expect(cannedRun()['exitCode'])->toBe(0)
            ->and(NfdumpSlots::inUse(NfdumpSlots::INTERACTIVE))->toBe(1)
            ->and(NfdumpSlots::inUse(NfdumpSlots::BACKGROUND))->toBe(0)
        ;
    });

    test('a background run gives its slot back to its own class', function () use ($stub): void {
        slotSettings(4, $stub);
        NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => cannedRun());

        expect(NfdumpSlots::inUse())->toBe(0);
    });

    test('runs in a held slot take none of their own', function () use ($stub): void {
        slotSettings(1, $stub);
        $granted = NfdumpSlots::acquireMany(1);

        $result = NfdumpSlots::runInHeldSlot(NfdumpSlots::INTERACTIVE, static fn (): array => [cannedRun(), cannedRun()]);

        expect($granted)->toBe(1)
            ->and($result)->toHaveCount(2)
            ->and(NfdumpSlots::inUse())->toBe(1)
        ;
        NfdumpSlots::release(NfdumpSlots::INTERACTIVE, $granted);
        expect(NfdumpSlots::inUse())->toBe(0);
    });

    test('a budget bounds the slot waits of all runs in the scope together', function () use ($stub): void {
        slotSettings(1, $stub);
        NfdumpSlots::acquire();
        $started = microtime(true);

        $outcomes = NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static function (): array {
            $outcomes = [];
            foreach (range(1, 3) as $_) {
                try {
                    $outcomes[] = cannedRun()['exitCode'];
                } catch (RuntimeException $e) {
                    $outcomes[] = NfdumpSlots::timedOut($e) ? 'timed out' : $e->getMessage();
                }
            }

            return $outcomes;
        }, budget: 0.2);
        $elapsed = microtime(true) - $started;

        // Three waits of 0.2 s each would take 0.6 s.
        expect($outcomes)->toBe(['timed out', 'timed out', 'timed out'])
            ->and($elapsed)->toBeGreaterThan(0.15)
            ->and($elapsed)->toBeLessThan(0.45)
        ;
    });

    test('a spent budget still lets a run take a free slot', function () use ($stub): void {
        slotSettings(2, $stub);

        expect(NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): int => cannedRun()['exitCode'], budget: 0.0))->toBe(0)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('waitFor() cuts the wait to what is left of the budget, also in an inner scope', function (): void {
        $inner = NfdumpSlots::runAs(
            NfdumpSlots::BACKGROUND,
            static fn (): float => NfdumpSlots::runAs(NfdumpSlots::INTERACTIVE, static fn (): float => NfdumpSlots::waitFor(NfdumpSlots::scope())),
            budget: 5.0,
        );

        expect(NfdumpSlots::waitFor(NfdumpSlots::scope()))->toBe(NfdumpSlots::DEFAULT_WAIT_SECONDS)
            ->and(NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): float => NfdumpSlots::waitFor(NfdumpSlots::scope()), 12.0))->toBe(12.0)
            ->and($inner)->toBeGreaterThan(4.0)
            ->and($inner)->toBeLessThanOrEqual(5.0)
        ;
    });

    test('runAs() reports the time its runs waited for a slot', function (): void {
        slotSettings(1);
        NfdumpSlots::acquire();
        $waited = null;

        Coroutine::run(static function () use (&$waited): void {
            Coroutine::create(static function (): void {
                Coroutine::usleep(150_000);
                NfdumpSlots::release();
            });
            NfdumpSlots::runAs(NfdumpSlots::INTERACTIVE, static function (): void {
                NfdumpSlots::acquire(2.0);
                NfdumpSlots::release();
            }, waited: $waited);
        });

        expect($waited)->toBeGreaterThan(0.1)
            ->and($waited)->toBeLessThan(1.0)
        ;
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
