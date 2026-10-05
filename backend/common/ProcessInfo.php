<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\processor\Nfdump;

/**
 * Facts about the running worker process: when it started and what it runs on.
 */
final class ProcessInfo {
    private static ?int $startedAt = null;

    private static ?string $sqliteVersion = null;

    /**
     * Worker start time: /proc/self/stat field 22 (clock ticks since boot) / CLK_TCK plus the
     * boot time from /proc/stat. Falls back to the first call's time() where /proc is missing.
     */
    public static function startedAt(): int {
        if (self::$startedAt !== null) {
            return self::$startedAt;
        }

        $selfStat = @file_get_contents('/proc/self/stat');
        $procStat = @file_get_contents('/proc/stat');
        $fromProc = \is_string($selfStat) && \is_string($procStat)
            ? self::parseStartTime($selfStat, $procStat, self::clockTicks())
            : null;

        return self::$startedAt = $fromProc ?? time();
    }

    public static function uptime(int $now): int {
        return max(0, $now - self::startedAt());
    }

    /**
     * Empty string for anything that cannot be determined (extension or binary missing).
     *
     * @return array{php: string, openswoole: string, sqlite: string, nfdump: string}
     */
    public static function versions(): array {
        $nfdump = '';
        if (isset(Config::$settings) && Config::$settings->nfdumpBinary !== '' && is_executable(Config::$settings->nfdumpBinary)) {
            $nfdump = Nfdump::parseVersion(Nfdump::versionString(Config::$settings->nfdumpBinary));
        }

        return [
            'php' => PHP_VERSION,
            'openswoole' => phpversion('openswoole') ?: '',
            'sqlite' => self::sqliteVersion(),
            'nfdump' => $nfdump,
        ];
    }

    /** The SQLite library version pdo_sqlite links against, or '' without the driver. */
    public static function sqliteVersion(): string {
        if (self::$sqliteVersion !== null) {
            return self::$sqliteVersion;
        }

        $version = '';
        if (\extension_loaded('pdo_sqlite')) {
            try {
                $version = (string) (new \PDO('sqlite::memory:'))->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\PDOException) {
                $version = '';
            }
        }

        return self::$sqliteVersion = $version;
    }

    /**
     * Unix start time from the contents of /proc/self/stat and /proc/stat, or null when either
     * cannot be parsed.
     */
    public static function parseStartTime(string $selfStat, string $procStat, int $clockTicks): ?int {
        // Field 2 (comm) is parenthesised and may contain spaces or ')', so count from the last ')'.
        $close = strrpos($selfStat, ')');
        if ($close === false || $clockTicks < 1) {
            return null;
        }

        // The remainder starts at field 3, so field 22 is index 19.
        $fields = preg_split('/\s+/', trim(substr($selfStat, $close + 1))) ?: [];
        $ticks = $fields[19] ?? '';
        if (!ctype_digit($ticks) || preg_match('/^btime\s+(\d+)$/m', $procStat, $m) !== 1) {
            return null;
        }

        return (int) $m[1] + intdiv((int) $ticks, $clockTicks);
    }

    private static function clockTicks(): int {
        if (\function_exists('posix_sysconf') && \defined('POSIX_SC_CLK_TCK')) {
            $ticks = posix_sysconf(POSIX_SC_CLK_TCK);
            if ($ticks > 0) {
                return $ticks;
            }
        }

        return 100;
    }
}
