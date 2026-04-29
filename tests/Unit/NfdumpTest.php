<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;

// Nfdump requires Config to be initialized, so we set up minimal config
beforeAll(function (): void {
    // Set up minimal config for Nfdump to work
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80, 443],
            'sources' => ['gateway'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/test-profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ]);
});

describe('Nfdump', function (): void {
    describe('get_output_format', function (): void {
        test('returns line format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('line');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('td')
                ->toContain('pr')
                ->toContain('sa')
                ->toContain('sp')
                ->toContain('da')
                ->toContain('dp')
            ;
        });

        test('returns long format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('long');

            expect($result)
                ->toBeArray()
                ->toContain('flg')
                ->toContain('stos')
                ->toContain('dtos')
            ;
        });

        test('returns extended format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('extended');

            expect($result)
                ->toBeArray()
                ->toContain('ibps')
                ->toContain('ipps')
                ->toContain('ibpp')
            ;
        });

        test('returns full format with all fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('full');

            expect($result)
                ->toBeArray()
                ->toHaveCount(48)
            ;
        });

        test('parses custom format string', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('fmt:%ts %sa %da');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('sa')
                ->toContain('da')
            ;
        });

        test('handles format with percent signs', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('%ts %td %pr');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('td')
                ->toContain('pr')
            ;
        });
    });

    describe('setFilter', function (): void {
        test('accepts filter string', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('src ip 192.168.1.1');

            // No exception means success
            expect(true)->toBeTrue();
        });

        test('accepts empty filter', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('');

            expect(true)->toBeTrue();
        });

        test('accepts complex filter', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('src ip 192.168.1.0/24 and dst port 443 and proto tcp');

            expect(true)->toBeTrue();
        });
    });

    describe('reset', function (): void {
        test('resets configuration', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('test filter');
            $nfdump->reset();

            // After reset, filter should be empty
            // We can't directly test private state, but no exception means success
            expect(true)->toBeTrue();
        });
    });

    describe('convert_date_to_path', function (): void {
        // Base path mirrors the Config set in beforeAll
        $base = '/tmp/test-profiles-data/live/gateway';

        // Helper: create a directory and touch a fake nfcapd file inside it.
        $mkfile = static function (string $base, string $yyyymmddhhi): string {
            $day = substr($yyyymmddhhi, 0, 4) . '/' . substr($yyyymmddhhi, 4, 2) . '/' . substr($yyyymmddhhi, 6, 2);
            $dir = $base . '/' . $day;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            touch($dir . '/nfcapd.' . $yyyymmddhhi);

            return $day . '/nfcapd.' . $yyyymmddhhi;
        };

        // Helper: remove a directory tree created by $mkfile.
        $rmdir = static function (string $path): void {
            if (!is_dir($path)) {
                return;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($path);
        };

        test('finds start and end file when data covers the full range', function () use ($base, $mkfile, $rmdir): void {
            $startFile = $mkfile($base, '202401010000');
            $endFile = $mkfile($base, '202401311200');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-01-01 00:00'))->getTimestamp(),
                (new DateTime('2024-01-31 12:00'))->getTimestamp()
            );

            expect($result)->toBe($startFile . PATH_SEPARATOR . $endFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });

        test('finds start file when data starts months into a year range (year-range bug)', function () use ($base, $mkfile, $rmdir): void {
            // Simulate: year range requested (Apr 2024 → Apr 2025),
            // but actual data only starts in October 2024.
            $firstFile = $mkfile($base, '202410010000');
            $lastFile = $mkfile($base, '202501150000');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-04-29 00:00'))->getTimestamp(), // 6 months before any data
                (new DateTime('2025-01-15 00:00'))->getTimestamp()
            );

            expect($result)->toBe($firstFile . PATH_SEPARATOR . $lastFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
            $rmdir('/tmp/test-profiles-data/live/gateway/2025');
        });

        test('picks earliest start file and latest end file on a day with multiple files', function () use ($base, $mkfile, $rmdir): void {
            $mkfile($base, '202406010000');
            $mkfile($base, '202406010500'); // later on same day
            $mkfile($base, '202406011000');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-06-01 00:00'))->getTimestamp(),
                (new DateTime('2024-06-01 10:00'))->getTimestamp()
            );

            [$start, $end] = explode(PATH_SEPARATOR, $result);
            expect($start)->toEndWith('nfcapd.202406010000');
            expect($end)->toEndWith('nfcapd.202406011000');

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });

        test('throws when no data exists anywhere in range', function () use ($rmdir): void {
            // Ensure no stale files exist
            $rmdir('/tmp/test-profiles-data/live/gateway/2020');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            expect(fn () => $nfdump->convert_date_to_path(
                (new DateTime('2020-01-01 00:00'))->getTimestamp(),
                (new DateTime('2020-01-07 00:00'))->getTimestamp()
            ))->toThrow(Exception::class, 'No nfcapd data files found for the requested time range.');
        });

        test('ignores files outside the requested time window', function () use ($base, $mkfile, $rmdir): void {
            // Only file on this day is BEFORE datestart — should skip forward to next day
            $mkfile($base, '202407010000'); // 00:00 — before the 12:00 start
            $nextFile = $mkfile($base, '202407021200'); // next day — first valid file

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-07-01 12:00'))->getTimestamp(),
                (new DateTime('2024-07-02 23:00'))->getTimestamp()
            );

            [$start] = explode(PATH_SEPARATOR, $result);
            expect($start)->toBe($nextFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });
    });

    describe('singleton pattern', function (): void {
        beforeEach(function (): void {
            // Reset singleton
            $reflection = new ReflectionClass(Nfdump::class);
            $property = $reflection->getProperty('_instance');
            $property->setAccessible(true);
            $property->setValue(null, null);
        });

        test('getInstance returns Nfdump instance', function (): void {
            $instance = Nfdump::getInstance();

            expect($instance)->toBeInstanceOf(Nfdump::class);
        });

        test('getInstance returns same instance', function (): void {
            $instance1 = Nfdump::getInstance();
            $instance2 = Nfdump::getInstance();

            expect($instance1)->toBe($instance2);
        });
    });
});
