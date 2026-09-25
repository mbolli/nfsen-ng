<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\ProcessInfo;
use mbolli\nfsen_ng\common\Settings;

/** Settings pointing nfdump at $binary; everything else is irrelevant to ProcessInfo. */
function processInfoSettings(string $binary): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => $binary, 'profiles-data' => '/tmp', 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => LOG_ERR],
    ]);
}

describe('ProcessInfo::parseStartTime', function (): void {
    $procStat = "cpu  1 2 3 4\nintr 5\nctxt 6\nbtime 1700000000\nprocesses 7\n";

    test('adds field 22 in clock ticks to the boot time', function () use ($procStat): void {
        $selfStat = '42 (php) S 1 42 42 0 -1 4194560 3644 0 93 0 2 4 0 0 20 0 1 0 12345 81301504 8527';

        expect(ProcessInfo::parseStartTime($selfStat, $procStat, 100))->toBe(1700000000 + 123);
    });

    test('counts fields from the last parenthesis, so a command name with spaces cannot shift them', function () use ($procStat): void {
        $selfStat = '42 (php: worker (1) x) S 1 42 42 0 -1 4194560 3644 0 93 0 2 4 0 0 20 0 1 0 50000 81301504 8527';

        expect(ProcessInfo::parseStartTime($selfStat, $procStat, 250))->toBe(1700000000 + 200);
    });

    test('is null when either file cannot be parsed', function () use ($procStat): void {
        $selfStat = '42 (php) S 1 42 42 0 -1 4194560 3644 0 93 0 2 4 0 0 20 0 1 0 12345 81301504 8527';

        expect(ProcessInfo::parseStartTime('garbage', $procStat, 100))->toBeNull()
            ->and(ProcessInfo::parseStartTime('42 (php) S 1 2', $procStat, 100))->toBeNull()
            ->and(ProcessInfo::parseStartTime($selfStat, "cpu 1 2 3\n", 100))->toBeNull()
            ->and(ProcessInfo::parseStartTime($selfStat, $procStat, 0))->toBeNull()
        ;
    });
});

describe('ProcessInfo', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    });

    afterEach(function (): void {
        if ($this->settingsBefore instanceof Settings) {
            Config::$settings = $this->settingsBefore;
        }
    });

    test('startedAt() reads this process from /proc and stays fixed', function (): void {
        (new ReflectionProperty(ProcessInfo::class, 'startedAt'))->setValue(null, null);

        $startedAt = ProcessInfo::startedAt();
        preg_match('/^btime\s+(\d+)$/m', (string) file_get_contents('/proc/stat'), $m);

        expect($startedAt)->toBeLessThanOrEqual(time())
            ->and($startedAt)->toBeGreaterThanOrEqual((int) $m[1])
            ->and(ProcessInfo::startedAt())->toBe($startedAt)
        ;
    })->skip(!is_readable('/proc/self/stat'), 'needs Linux /proc');

    test('uptime() counts from the start and is never negative', function (): void {
        (new ReflectionProperty(ProcessInfo::class, 'startedAt'))->setValue(null, 1_700_000_000);

        expect(ProcessInfo::uptime(1_700_000_600))->toBe(600)
            ->and(ProcessInfo::uptime(1_600_000_000))->toBe(0)
        ;

        (new ReflectionProperty(ProcessInfo::class, 'startedAt'))->setValue(null, null);
    });

    test('versions() reports PHP, OpenSwoole, SQLite and nfdump', function (): void {
        $binary = sys_get_temp_dir() . '/nfsen-ng-fake-nfdump-' . bin2hex(random_bytes(4));
        file_put_contents($binary, "#!/bin/sh\necho '{$binary}: Version: 1.7.8-release Options: ZSTD BZIP2 Date: 2025-01-01'\n");
        chmod($binary, 0o755);
        processInfoSettings($binary);

        $versions = ProcessInfo::versions();
        unlink($binary);

        expect(array_keys($versions))->toBe(['php', 'openswoole', 'sqlite', 'nfdump'])
            ->and($versions['php'])->toBe(PHP_VERSION)
            ->and($versions['openswoole'])->toBe(phpversion('openswoole') ?: '')
            ->and($versions['nfdump'])->toBe('1.7.8')
        ;
    });

    test('versions() leaves nfdump empty when the binary is missing', function (): void {
        processInfoSettings('/nonexistent/nfdump');

        expect(ProcessInfo::versions()['nfdump'])->toBe('');
    });

    test('sqliteVersion() reads the library version through pdo_sqlite', function (): void {
        expect(ProcessInfo::sqliteVersion())->toMatch('/^3\.\d+\.\d+/');
    })->skip(!extension_loaded('pdo_sqlite'), 'needs pdo_sqlite');
});
