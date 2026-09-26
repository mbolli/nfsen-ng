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

    describe('hasProcessInspectionTool', function (): void {
        test('returns true when ps or pgrep is on PATH', function (): void {
            expect(Misc::hasProcessInspectionTool())->toBeTrue();
        });

        test('returns false when neither ps nor pgrep is on PATH', function (): void {
            $originalPath = getenv('PATH');
            putenv('PATH=/nonexistent-empty-dir');

            $result = Misc::hasProcessInspectionTool();

            putenv('PATH=' . $originalPath);

            expect($result)->toBeFalse();
        });
    });
});

describe('Misc::processReadBytes', function (): void {
    test('reports a growing byte count for the running process', function (): void {
        $pid = getmypid();
        $before = Misc::processReadBytes($pid);

        if ($before === null) {
            expect(true)->toBeTrue(); // no procfs on this platform, nothing to assert

            return;
        }

        // Force some read() traffic.
        for ($i = 0; $i < 20; ++$i) {
            @file_get_contents('/proc/self/status');
        }

        expect(Misc::processReadBytes($pid))->toBeGreaterThan($before);
    });

    test('returns null for a pid that does not exist', function (): void {
        expect(Misc::processReadBytes(0x7FFFFFFF))->toBeNull();
    });

    test('returns null for a non-positive pid', function (): void {
        expect(Misc::processReadBytes(0))->toBeNull()
            ->and(Misc::processReadBytes(-1))->toBeNull()
        ;
    });
});

describe('Misc::formatVolume', function (): void {
    // D5: bytes read in base 1024 like the graph's byte axis, bits in base 1000 like its bit axis.
    test('bytes use base 1024 and IEC prefixes, with three significant digits', function (): void {
        expect(Misc::formatVolume(0.0))->toBe('0 B')
            ->and(Misc::formatVolume(812.0))->toBe('812 B')
            ->and(Misc::formatVolume(1536.0))->toBe('1.50 KiB')
            ->and(Misc::formatVolume(24.8 * 1024 ** 3))->toBe('24.8 GiB')
            ->and(Misc::formatVolume(812 * 1024 ** 2))->toBe('812 MiB')
        ;
    });

    test('bits multiply by eight and use base 1000', function (): void {
        expect(Misc::formatVolume(1000.0, 'bits'))->toBe('8.00 kb')
            ->and(Misc::formatVolume(2.49e12 / 8, 'bits'))->toBe('2.49 Tb')
            ->and(Misc::formatVolume(100.0, 'bits'))->toBe('800 b')
        ;
    });

    test('rounding that reaches the base carries into the next prefix', function (): void {
        expect(Misc::formatVolume(1023.9 * 1024))->toBe('1.00 MiB')
            ->and(Misc::formatVolume(999.96e3 / 8, 'bits'))->toBe('1.00 Mb')
        ;
    });

    test('nothing to show reads as zero, not as an error', function (): void {
        expect(Misc::formatVolume(-5.0))->toBe('0 B')
            ->and(Misc::formatVolume(NAN, 'bits'))->toBe('0 b')
            ->and(Misc::formatVolume(INF))->toBe('0 B')
        ;
    });
});

describe('Misc::formatCount', function (): void {
    test('counts use base 1000 with k, M and G', function (): void {
        expect(Misc::formatCount(482.0))->toBe('482')
            ->and(Misc::formatCount(987_000.0))->toBe('987 k')
            ->and(Misc::formatCount(1_200_000.0))->toBe('1.20 M')
            ->and(Misc::formatCount(28_800.0))->toBe('28.8 k')
            ->and(Misc::formatCount(0.0))->toBe('0')
        ;
    });
});
