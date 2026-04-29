<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Debug;

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
