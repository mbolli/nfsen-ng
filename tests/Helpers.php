<?php

declare(strict_types=1);

// Loaded by Pest before every test file, so a file that uses these also runs on its own.

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

/** Removes a directory tree a test created; a symlink inside it goes, not what it points to. */
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
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}
