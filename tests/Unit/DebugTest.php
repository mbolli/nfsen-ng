<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\LogRing;
use mbolli\nfsen_ng\common\Settings;

/** Debug with terminal echo off and the given log level, and an empty recent-log ring. */
function quietDebugAt(int $priority): Debug {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/tmp', 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => $priority],
    ]);
    (new ReflectionProperty(Debug::class, 'recent'))->setValue(null, new LogRing());
    Debug::drainBuffer();

    $debug = new Debug();
    $debug->setDebug(false);

    return $debug;
}

describe('Debug', function (): void {
    beforeEach(function (): void {
        // Reset the singleton instance before each test
        $reflection = new ReflectionClass(Debug::class);
        $property = $reflection->getProperty('_instance');
        $property->setAccessible(true);
        $property->setValue(null, null);
    });

    describe('singleton pattern', function (): void {
        test('getInstance returns Debug instance', function (): void {
            $instance = Debug::getInstance();

            expect($instance)->toBeInstanceOf(Debug::class);
        });

        test('getInstance returns same instance on multiple calls', function (): void {
            $instance1 = Debug::getInstance();
            $instance2 = Debug::getInstance();

            expect($instance1)->toBe($instance2);
        });

        test('can create new instance directly', function (): void {
            $instance = new Debug();

            expect($instance)->toBeInstanceOf(Debug::class);
        });
    });

    describe('stopWatch', function (): void {
        test('returns elapsed time as float', function (): void {
            $debug = new Debug();

            usleep(10000); // 10ms
            $elapsed = $debug->stopWatch();

            expect($elapsed)
                ->toBeFloat()
                ->toBeGreaterThan(0)
            ;
        });

        test('returns rounded time by default', function (): void {
            $debug = new Debug();

            $elapsed = $debug->stopWatch();

            // Should have at most 4 decimal places
            expect($elapsed)->toBeFloat();
            $parts = explode('.', (string) $elapsed);
            if (isset($parts[1])) {
                expect(strlen($parts[1]))->toBeLessThanOrEqual(4);
            } else {
                // Integer result is also valid (0 decimal places)
                expect(true)->toBeTrue();
            }
        });

        test('returns precise time when requested', function (): void {
            $debug = new Debug();

            usleep(10000); // 10ms
            $elapsed = $debug->stopWatch(true);

            expect($elapsed)->toBeFloat();
        });

        test('time increases between calls', function (): void {
            $debug = new Debug();

            $time1 = $debug->stopWatch();
            usleep(5000); // 5ms
            $time2 = $debug->stopWatch();

            expect($time2)->toBeGreaterThan($time1);
        });
    });

    describe('recent log', function (): void {
        beforeEach(function (): void {
            $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        });

        afterEach(function (): void {
            if ($this->settingsBefore instanceof Settings) {
                Config::$settings = $this->settingsBefore;
            }
            Debug::drainBuffer();
        });

        test('recent() returns what passed the level filter, newest first', function (): void {
            $debug = quietDebugAt(LOG_WARNING);
            $debug->log('first warning', LOG_WARNING);
            $debug->log('an info line', LOG_INFO);
            $debug->log('an error', LOG_ERR);
            $debug->log('a debug line', LOG_DEBUG);

            $recent = Debug::recent();

            expect(array_column($recent, 'message'))->toBe(['an error', 'first warning'])
                ->and(array_column($recent, 'levelName'))->toBe(['error', 'warning'])
            ;
        });

        test('DEBUG lines appear when the level is DEBUG', function (): void {
            $debug = quietDebugAt(LOG_DEBUG);
            $debug->log('nfdump -M /data -R 2024/01/01', LOG_DEBUG);

            expect(Debug::recent()[0])->toMatchArray(['level' => LOG_DEBUG, 'message' => 'nfdump -M /data -R 2024/01/01']);
        });

        test('recent() filters by priority and limit', function (): void {
            $debug = quietDebugAt(LOG_DEBUG);
            foreach ([LOG_DEBUG, LOG_ERR, LOG_INFO, LOG_WARNING, LOG_ERR] as $i => $priority) {
                $debug->log("line {$i}", $priority);
            }

            expect(array_column(Debug::recent(200, LOG_WARNING), 'message'))->toBe(['line 4', 'line 3', 'line 1'])
                ->and(array_column(Debug::recent(2), 'message'))->toBe(['line 4', 'line 3'])
            ;
        });

        test('recentSince() returns the lines after a sequence, oldest first', function (): void {
            $debug = quietDebugAt(LOG_INFO);
            $debug->log('before', LOG_INFO);
            $seen = Debug::recent(1)[0]['seq'];
            $debug->log('after one', LOG_INFO);
            $debug->log('after two', LOG_NOTICE);

            expect(array_column(Debug::recentSince($seen), 'message'))->toBe(['after one', 'after two']);
        });

        test('drainBuffer() still collects warnings below the configured level and empties on read', function (): void {
            $debug = quietDebugAt(LOG_ERR);
            $debug->log('filtered warning', LOG_WARNING);
            $debug->log('not buffered', LOG_INFO);

            $drained = Debug::drainBuffer();

            expect($drained)->toHaveCount(1)
                ->and($drained[0])->toMatchArray(['level' => LOG_WARNING, 'msg' => 'filtered warning'])
                ->and(array_keys($drained[0]))->toBe(['ts', 'level', 'msg'])
                ->and(Debug::drainBuffer())->toBe([])
                ->and(Debug::recent())->toBe([])
            ;
        });

        test('draining the buffer leaves the recent log intact', function (): void {
            $debug = quietDebugAt(LOG_WARNING);
            $debug->log('kept', LOG_ERR);
            Debug::drainBuffer();

            expect(array_column(Debug::recent(), 'message'))->toBe(['kept']);
        });
    });

    describe('setDebug', function (): void {
        test('can enable debug mode', function (): void {
            $debug = new Debug();
            $debug->setDebug(true);

            // No exception means success
            expect(true)->toBeTrue();
        });

        test('can disable debug mode', function (): void {
            $debug = new Debug();
            $debug->setDebug(false);

            // No exception means success
            expect(true)->toBeTrue();
        });
    });
});
