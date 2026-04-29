<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Misc;

describe('Misc', function (): void {
    describe('daemonIsRunning', function (): void {
        test('returns true for current process (self)', function (): void {
            $pid = getmypid();
            $result = Misc::daemonIsRunning($pid);

            expect($result)->toBeTrue();
        });

        test('returns false for non-existent process', function (): void {
            // Use a very high PID that is unlikely to exist
            $result = Misc::daemonIsRunning(999999999);

            expect($result)->toBeFalse();
        });

        test('accepts string PID', function (): void {
            $pid = (string) getmypid();
            $result = Misc::daemonIsRunning($pid);

            expect($result)->toBeTrue();
        });

        test('handles zero PID', function (): void {
            $result = Misc::daemonIsRunning(0);

            // PID 0 typically doesn't exist as a user process
            expect($result)->toBeBool();
        });
    });

    describe('countProcessesByName', function (): void {
        test('returns integer count', function (): void {
            $result = Misc::countProcessesByName('php');

            expect($result)->toBeInt()
                ->toBeGreaterThanOrEqual(0)
            ;
        });

        test('returns zero for non-existent process name', function (): void {
            $result = Misc::countProcessesByName('nonexistent_process_xyz_12345');

            expect($result)->toBe(0);
        });

        test('finds running php processes', function (): void {
            // PHP should be running since we're executing tests
            $result = Misc::countProcessesByName('php');

            expect($result)->toBeGreaterThanOrEqual(1);
        });
    });
});
