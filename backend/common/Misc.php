<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

class Misc {
    /**
     * Check if daemon process is running.
     *
     * @param int|string $pid Process ID to check
     *
     * @return bool True if process is running, false otherwise
     */
    public static function daemonIsRunning(int|string $pid): bool {
        $pid = (int) $pid;

        // Method 1: Use posix_kill with signal 0 (doesn't actually send signal, just checks if process exists)
        if (\function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        // Method 2: Check /proc filesystem (Linux)
        if (file_exists('/proc/' . $pid)) {
            return true;
        }

        // Method 3: Fall back to ps command if available
        exec('ps -p ' . $pid . ' 2>/dev/null', $op, $exitCode);

        return $exitCode === 0 && isset($op[1]);
    }

    /**
     * Count running processes by binary name
     * Uses pgrep first (preferred, especially in containers), then falls back to ps.
     *
     * @param string $binaryName The name of the binary/process to count
     *
     * @return int Number of running processes with that name
     */
    public static function countProcessesByName(string $binaryName): int {
        // Method 1: Try pgrep first (more likely available in containers and more efficient)
        exec("command -v pgrep > /dev/null 2>&1 && pgrep -c '^{$binaryName}$' 2>/dev/null || echo '0'", $pgrep_output);
        if (!empty($pgrep_output[0]) && is_numeric($pgrep_output[0])) {
            return (int) $pgrep_output[0];
        }

        // Method 2: Fallback to ps if pgrep is not available
        exec("command -v ps > /dev/null 2>&1 && ps -eo comm | grep -c '^{$binaryName}$' 2>/dev/null || echo '0'", $ps_output);
        if (!empty($ps_output[0]) && is_numeric($ps_output[0])) {
            return (int) $ps_output[0];
        }

        // If neither method works, return 0
        return 0;
    }

    /**
     * Bytes a running process has read so far, from /proc/<pid>/io, or null when that
     * cannot be answered (no procfs, process gone, or the kernel denies access).
     *
     * Reads `rchar` rather than `read_bytes` deliberately: `read_bytes` counts actual
     * block-device traffic and stays at 0 for files already in the page cache, which is
     * the common case for a freshly written nfcapd file. `rchar` counts bytes returned
     * by read() regardless of where they came from, so it tracks nfdump's real progress
     * through its input.
     *
     * procfs is Linux-only; on FreeBSD and macOS this returns null and callers fall back
     * to an indeterminate progress indicator.
     */
    public static function processReadBytes(int $pid): ?int {
        if ($pid <= 0) {
            return null;
        }

        // is_file() first: @ suppresses the warning for PHP, but a test runner's error
        // handler still promotes it to a failure, and a missing pid is an expected case.
        $path = '/proc/' . $pid . '/io';
        if (!is_file($path)) {
            return null;
        }

        $io = @file_get_contents($path);   // still races process exit
        if ($io === false) {
            return null;
        }

        if (preg_match('/^rchar:\s*(\d+)/m', $io, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Whether a process-inspection tool (pgrep or ps) is available.
     * countProcessesByName() silently returns 0 without either, surfaced as a
     * health check so a missing procps package doesn't masquerade as
     * "no other nfdump processes running".
     */
    public static function hasProcessInspectionTool(): bool {
        exec('command -v pgrep 2>/dev/null', $pgrepOutput);
        if (!empty($pgrepOutput)) {
            return true;
        }

        exec('command -v ps 2>/dev/null', $psOutput);

        return !empty($psOutput);
    }

    /**
     * A byte volume in the display unit (D5): bytes in base 1024 ("24.8 GiB"), bits in base
     * 1000 ("2.49 Tb"), so both read the same as the graph's axis.
     */
    public static function formatVolume(float $bytes, string $unit = 'bytes'): string {
        if ($unit === 'bits') {
            return self::scaled($bytes * 8, 1000, ['b', 'kb', 'Mb', 'Gb', 'Tb', 'Pb', 'Eb']);
        }

        return self::scaled($bytes, 1024, ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB']);
    }

    /** A packet or flow count in base 1000: "482", "987 k", "1.2 M". */
    public static function formatCount(float $count): string {
        return self::scaled($count, 1000, ['', 'k', 'M', 'G', 'T', 'P', 'E']);
    }

    /**
     * Three significant digits and the prefix that keeps the number below the base.
     *
     * @param non-empty-list<string> $units
     */
    private static function scaled(float $value, int $base, array $units): string {
        if (!is_finite($value) || $value <= 0) {
            return trim('0 ' . $units[0]);
        }

        $i = 0;
        while ($value >= $base && $i < \count($units) - 1) {
            $value /= $base;
            ++$i;
        }
        // Rounding can carry into the next prefix (1023.9 KiB reads "1024 KiB" otherwise).
        $decimals = $i === 0 ? 0 : ($value < 9.995 ? 2 : ($value < 99.95 ? 1 : 0));
        if (round($value, $decimals) >= $base && $i < \count($units) - 1) {
            $value /= $base;
            ++$i;
            $decimals = 2;
        }

        return trim(number_format($value, $decimals, '.', '') . ' ' . $units[$i]);
    }
}
