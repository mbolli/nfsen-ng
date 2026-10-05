<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * The vendored Starbase modules the layout loads: every entry of frontend/js/starbase/starbase.lock.json
 * with `"load": true`. The folder names carry Starbase's content hash, so the URLs need no `?v=`.
 */
final class StarbaseAssets {
    public const string LOCK = 'js/starbase/starbase.lock.json';

    /** @var null|list<string> */
    private static ?array $modules = null;

    /**
     * Paths relative to the frontend directory, in lock order. Memoized per worker for the real
     * frontend directory; a given directory (tests) is read on every call.
     *
     * @return list<string>
     */
    public static function modules(?string $frontendDir = null): array {
        if ($frontendDir !== null) {
            return self::read($frontendDir);
        }

        return self::$modules ??= self::read(\dirname(__DIR__, 2) . '/frontend');
    }

    /** @return list<string> */
    private static function read(string $frontendDir): array {
        $lockFile = $frontendDir . '/' . self::LOCK;
        $raw = is_file($lockFile) ? file_get_contents($lockFile) : false;
        $lock = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($lock) || !\is_array($lock['components'] ?? null)) {
            self::warn('missing or invalid ' . self::LOCK . ', no Starbase module loads');

            return [];
        }

        $modules = [];
        foreach ($lock['components'] as $slug => $component) {
            if (!\is_array($component) || ($component['load'] ?? false) !== true) {
                continue;
            }
            $version = $component['version'] ?? null;
            $entry = $component['entry'] ?? null;
            if (!\is_string($slug) || !preg_match('/^[a-z0-9-]+\z/', $slug)
                || !\is_string($version) || !preg_match('/^[0-9a-f]{12}\z/', $version)
                || !\is_string($entry) || !preg_match('/^[\w.-]+\.m?js\z/', $entry)) {
                self::warn('skipping an invalid entry in ' . self::LOCK . ': ' . json_encode($slug));

                continue;
            }
            $path = "js/starbase/{$slug}@{$version}/{$entry}";
            if (!is_file($frontendDir . '/' . $path)) {
                self::warn("skipping {$slug}: {$path} does not exist");

                continue;
            }
            $modules[] = $path;
        }

        return $modules;
    }

    private static function warn(string $message): void {
        Debug::getInstance()->log('StarbaseAssets: ' . $message, LOG_WARNING);
    }
}
