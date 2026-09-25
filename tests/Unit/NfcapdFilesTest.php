<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\Settings;

/**
 * Builds a throwaway nfcapd tree: <root>/live/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII.
 *
 * @param list<string> $sources
 * @param list<int>    $timestamps
 */
function makeCaptureTree(array $sources, array $timestamps, int $bytesPerFile = 100): string {
    $root = sys_get_temp_dir() . '/nfsen-ng-test-' . bin2hex(random_bytes(6));

    foreach ($sources as $source) {
        foreach ($timestamps as $ts) {
            $dt = (new DateTime('', new DateTimeZone('UTC')))->setTimestamp($ts);
            $dir = implode('/', [$root, 'live', $source, $dt->format('Y'), $dt->format('m'), $dt->format('d')]);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            file_put_contents($dir . '/nfcapd.' . $dt->format('YmdHi'), str_repeat('x', $bytesPerFile));
        }
    }

    return $root;
}

function removeTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/** Point Config at a capture tree. Returns the root so the caller can clean it up. */
function useCaptureTree(string $root): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80],
            'sources' => ['gateway', 'swi6'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => $root,
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => ['priority' => LOG_ERR],
    ]);
}

describe('NfcapdFiles::list', function (): void {
    // 2024-01-01 00:00, 00:05, 00:10 UTC
    $base = 1704067200;

    test('finds every file in range, ascending by timestamp', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base, $base + 300, $base + 600]);
        useCaptureTree($root);

        $files = NfcapdFiles::list($base, $base + 600, ['gateway']);

        expect($files)->toHaveCount(3)
            ->and(array_column($files, 'ts'))->toBe([$base, $base + 300, $base + 600])
        ;

        removeTree($root);
    });

    test('builds a relPath nfdump can resolve against -M', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base]);
        useCaptureTree($root);

        $files = NfcapdFiles::list($base, $base, ['gateway']);

        expect($files[0]['relPath'])->toBe('2024/01/01/nfcapd.202401010000')
            ->and($files[0]['path'])->toEndWith('/live/gateway/2024/01/01/nfcapd.202401010000')
        ;

        removeTree($root);
    });

    test('excludes files outside the requested window', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base, $base + 300, $base + 600]);
        useCaptureTree($root);

        $files = NfcapdFiles::list($base + 300, $base + 300, ['gateway']);

        expect($files)->toHaveCount(1)
            ->and($files[0]['ts'])->toBe($base + 300)
        ;

        removeTree($root);
    });

    test('interleaves multiple sources by timestamp', function () use ($base): void {
        $root = makeCaptureTree(['gateway', 'swi6'], [$base, $base + 300]);
        useCaptureTree($root);

        $files = NfcapdFiles::list($base, $base + 300, ['gateway', 'swi6']);

        expect($files)->toHaveCount(4)
            ->and(array_column($files, 'ts'))->toBe([$base, $base, $base + 300, $base + 300])
            ->and(array_column($files, 'source'))->toBe(['gateway', 'swi6', 'gateway', 'swi6'])
        ;

        removeTree($root);
    });

    test('spans a day boundary', function (): void {
        $lateNight = 1704153300;  // 2024-01-01 23:55 UTC
        $root = makeCaptureTree(['gateway'], [$lateNight, $lateNight + 300]);
        useCaptureTree($root);

        $files = NfcapdFiles::list($lateNight, $lateNight + 300, ['gateway']);

        expect($files)->toHaveCount(2)
            ->and($files[0]['relPath'])->toBe('2024/01/01/nfcapd.202401012355')
            ->and($files[1]['relPath'])->toBe('2024/01/02/nfcapd.202401020000')
        ;

        removeTree($root);
    });

    test('returns an empty list when the source directory does not exist', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base]);
        useCaptureTree($root);

        expect(NfcapdFiles::list($base, $base, ['nonexistent']))->toBe([]);

        removeTree($root);
    });

    test('ignores files that do not match the nfcapd naming pattern', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base]);
        useCaptureTree($root);
        file_put_contents($root . '/live/gateway/2024/01/01/nfcapd.current', 'x');
        file_put_contents($root . '/live/gateway/2024/01/01/README', 'x');

        expect(NfcapdFiles::list($base, $base, ['gateway']))->toHaveCount(1);

        removeTree($root);
    });

    test('totalSize sums the listed files', function () use ($base): void {
        $root = makeCaptureTree(['gateway'], [$base, $base + 300], bytesPerFile: 512);
        useCaptureTree($root);

        $files = NfcapdFiles::list($base, $base + 300, ['gateway']);

        expect(NfcapdFiles::totalSize($files))->toBe(1024);

        removeTree($root);
    });

    test('totalSize of an empty list is zero', function (): void {
        expect(NfcapdFiles::totalSize([]))->toBe(0);
    });
});

/** Writes one rotated capture file under the day directory of $ts in the nfcapd timezone. */
function writeCaptureFile(string $root, string $source, int $ts, string $name = ''): string {
    $dt = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
    $dir = implode('/', [$root, 'live', $source, $dt->format('Y'), $dt->format('m'), $dt->format('d')]);
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    $path = $dir . '/' . ($name !== '' ? $name : 'nfcapd.' . $dt->format('YmdHi'));
    file_put_contents($path, 'x');

    return $path;
}

describe('NfcapdFiles::newest', function (): void {
    // 2024-01-03 12:00 UTC
    $now = 1704283200;

    beforeEach(function (): void {
        $this->root = sys_get_temp_dir() . '/nfsen-ng-test-' . bin2hex(random_bytes(6));
        useCaptureTree($this->root);
    });

    afterEach(function (): void {
        removeTree($this->root);
    });

    test('takes the newest file of today', function () use ($now): void {
        writeCaptureFile($this->root, 'gateway', $now - 900);
        $path = writeCaptureFile($this->root, 'gateway', $now - 300);
        writeCaptureFile($this->root, 'gateway', $now - 86400);

        expect(NfcapdFiles::newest('live', 'gateway', 7, $now))
            ->toBe(['ts' => $now - 300, 'name' => basename($path), 'path' => $path])
        ;
    });

    test('falls back to earlier day directories in the nfcapd timezone', function () use ($now): void {
        writeCaptureFile($this->root, 'gateway', $now - 3 * 86400);
        writeCaptureFile($this->root, 'gateway', $now - 2 * 86400 - 600);

        expect(NfcapdFiles::newest('live', 'gateway', 7, $now)['ts'] ?? null)->toBe($now - 2 * 86400 - 600);
    });

    test('ignores nfcapd.current.* and other names', function () use ($now): void {
        $rotated = writeCaptureFile($this->root, 'gateway', $now - 600);
        file_put_contents(dirname($rotated) . '/nfcapd.current.12345', 'x');
        file_put_contents(dirname($rotated) . '/nfcapd.209912312355.tmp', 'x');

        expect(NfcapdFiles::newest('live', 'gateway', 7, $now)['ts'] ?? null)->toBe($now - 600);
    });

    test('looks back no further than maxDaysBack', function () use ($now): void {
        writeCaptureFile($this->root, 'gateway', $now - 3 * 86400);

        expect(NfcapdFiles::newest('live', 'gateway', 2, $now))->toBeNull()
            ->and(NfcapdFiles::newest('live', 'gateway', 3, $now))->not->toBeNull()
            ->and(NfcapdFiles::newest('live', 'gateway', 0, $now + 3 * 86400))->toBeNull()
        ;
    });

    test('is null for a source without a directory', function () use ($now): void {
        expect(NfcapdFiles::newest('live', 'nonexistent', 7, $now))->toBeNull();
    });
});

describe('NfcapdFiles::names', function (): void {
    $base = 1704067200; // 2024-01-01 00:00 UTC

    beforeEach(function (): void {
        $this->root = sys_get_temp_dir() . '/nfsen-ng-test-' . bin2hex(random_bytes(6));
        useCaptureTree($this->root);
    });

    afterEach(function (): void {
        removeTree($this->root);
    });

    test('returns the timestamps within the window, ascending, across days', function () use ($base): void {
        foreach ([$base + 86400 + 300, $base - 300, $base, $base + 300, $base + 2 * 86400] as $ts) {
            writeCaptureFile($this->root, 'gateway', $ts);
        }

        expect(NfcapdFiles::names($base, $base + 86400 + 300, 'gateway', 'live'))
            ->toBe([$base, $base + 300, $base + 86400 + 300])
        ;
    });

    test('reads timestamps from the names alone', function () use ($base): void {
        $file = writeCaptureFile($this->root, 'gateway', $base);
        // A dangling link has no size to stat; the name is still a capture interval.
        $name = 'nfcapd.' . (new DateTimeImmutable('@' . ($base + 300)))->setTimezone(Config::nfcapdTimezone())->format('YmdHi');
        symlink($this->root . '/does-not-exist', dirname($file) . '/' . $name);
        file_put_contents(dirname($file) . '/nfcapd.current.999', 'x');

        expect(NfcapdFiles::names($base, $base + 600, 'gateway', 'live'))->toBe([$base, $base + 300]);
    });

    test('is empty for an inverted window or a missing source', function () use ($base): void {
        writeCaptureFile($this->root, 'gateway', $base);

        expect(NfcapdFiles::names($base + 600, $base, 'gateway', 'live'))->toBe([])
            ->and(NfcapdFiles::names($base, $base + 600, 'nonexistent', 'live'))->toBe([])
        ;
    });

    test('an empty profile means the configured default', function () use ($base): void {
        writeCaptureFile($this->root, 'gateway', $base);

        expect(NfcapdFiles::names($base, $base, 'gateway', ''))->toBe([$base]);
    });
});
