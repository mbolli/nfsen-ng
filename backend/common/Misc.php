<?php

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
}
