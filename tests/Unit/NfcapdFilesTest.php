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
