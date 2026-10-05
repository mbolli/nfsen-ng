<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use OpenSwoole\Util;

/**
 * The CPU cores this process may use, and the nfdump process limit derived from them.
 *
 * Cores are the smaller of the affinity mask (what `nproc` counts) and the cgroup CPU quota
 * rounded up, so a container limited to 4 CPUs on a 20-core host counts 4. With neither
 * readable, the online CPU count (sysconf). Detected once per process: neither changes
 * without a restart in practice.
 *
 * @phpstan-type Cores array{cores: int, source: 'cgroup'|'affinity'|'online', origin: string, detail: string}
 */
final class CpuBudget {
    /** One nfdump process keeps 2 to 3 cores busy: the reader, the main thread and a filter worker. */
    public const int CORES_PER_PROCESS = 3;

    public const int AUTO_MIN = 2;

    public const int AUTO_MAX = 8;

    /** nfdump 1.7.3 to 1.7.7 reject a -W above their MAXWORKERS of 16. */
    public const int MAX_WORKERS = 16;

    /** @var null|Cores */
    private static ?array $detected = null;

    /** @return Cores */
    public static function detect(): array {
        return self::$detected ??= self::detectFrom('');
    }

    public static function cores(): int {
        return self::detect()['cores'];
    }

    /** The process limit `auto` stands for: a third of the cores, between 2 and 8. */
    public static function autoProcesses(int $cores): int {
        return max(self::AUTO_MIN, min(self::AUTO_MAX, intdiv(max(0, $cores), self::CORES_PER_PROCESS)));
    }

    /** "the CPU affinity" and so on, for a sentence that names where the core count came from. */
    public static function sourceLabel(string $source): string {
        return match ($source) {
            'cgroup' => "the container's CPU limit",
            'affinity' => 'the CPU affinity',
            default => 'the online CPU count',
        };
    }

    /** Forgets the cached detection, so the next detect() reads the files again. */
    public static function forget(): void {
        self::$detected = null;
    }

    /**
     * Detection against a file system rooted at $root ('' is the real one), so tests can hand
     * it fixture /proc and /sys files.
     *
     * @param null|int $online the online CPU count; null asks the system
     *
     * @return Cores
     */
    public static function detectFrom(string $root, ?int $online = null): array {
        $affinity = self::affinity($root);
        $quota = self::quota($root);

        if ($affinity !== null) {
            $base = ['cores' => $affinity['cpus'], 'source' => 'affinity', 'origin' => 'nproc', 'detail' => 'Cpus_allowed_list ' . $affinity['list']];
        } else {
            $base = ['cores' => max(1, $online ?? self::onlineCpus()), 'source' => 'online', 'origin' => 'sysconf', 'detail' => 'online CPUs'];
        }

        if ($quota !== null) {
            $limited = max(1, (int) ceil($quota['cpus'] - 1e-9));
            if ($limited < $base['cores']) {
                return ['cores' => $limited, 'source' => 'cgroup', 'origin' => $quota['origin'], 'detail' => $quota['detail']];
            }
        }

        return $base;
    }

    /** Number of CPUs in a list such as "0-3,8-11,15"; null when it is not one. */
    public static function countCpuList(string $list): ?int {
        $list = trim($list);
        if ($list === '') {
            return null;
        }

        $count = 0;
        foreach (explode(',', $list) as $part) {
            if (preg_match('/^\s*(\d+)(?:-(\d+))?\s*$/', $part, $m) !== 1) {
                return null;
            }
            $last = isset($m[2]) ? (int) $m[2] : (int) $m[1];
            if ($last < (int) $m[1]) {
                return null;
            }
            $count += $last - (int) $m[1] + 1;
        }

        return $count > 0 ? $count : null;
    }

    /** @return null|array{cpus: int, list: string} */
    private static function affinity(string $root): ?array {
        $status = self::read($root . '/proc/self/status');
        if ($status === null || preg_match('/^Cpus_allowed_list:\s*(\S+)\s*$/m', $status, $m) !== 1) {
            return null;
        }
        $cpus = self::countCpuList($m[1]);

        return $cpus === null ? null : ['cpus' => $cpus, 'list' => $m[1]];
    }

    /**
     * The tightest CPU quota on the way from this process's cgroup up to the root it can see,
     * in CPUs, for cgroup v2 (cpu.max) and v1 (cpu.cfs_quota_us).
     *
     * @return null|array{cpus: float, origin: string, detail: string}
     */
    private static function quota(string $root): ?array {
        $cgroups = self::read($root . '/proc/self/cgroup');
        if ($cgroups === null) {
            return null;
        }

        $best = null;
        foreach (explode("\n", $cgroups) as $line) {
            $fields = explode(':', trim($line), 3);
            if (\count($fields) !== 3) {
                continue;
            }
            [, $controllers, $path] = $fields;

            if ($controllers === '') {
                foreach (self::walk($root . '/sys/fs/cgroup', $path) as $dir) {
                    $best = self::tighter($best, self::cpuMax($dir));
                }
            } elseif (\in_array('cpu', explode(',', $controllers), true)) {
                foreach (['cpu,cpuacct', 'cpu', 'cpuacct,cpu'] as $mount) {
                    foreach (self::walk($root . '/sys/fs/cgroup/' . $mount, $path) as $dir) {
                        $best = self::tighter($best, self::cfsQuota($dir));
                    }
                }
            }
        }

        return $best;
    }

    /**
     * $mount joined with the cgroup path and each of its parents, ending at $mount itself. Inside
     * a cgroup namespace the path may not exist under the mount, which then stands for it.
     *
     * @return list<string>
     */
    private static function walk(string $mount, string $path): array {
        if (!is_dir($mount)) {
            return [];
        }
        $path = '/' . trim($path, '/');
        if (str_contains($path, '..')) {
            $path = '/';
        }

        $dirs = [];
        while (true) {
            $dir = rtrim($mount . $path, '/');
            if (is_dir($dir)) {
                $dirs[] = $dir;
            }
            if ($path === '/') {
                break;
            }
            $path = \dirname($path);
        }

        return $dirs;
    }

    /** @return null|array{cpus: float, origin: string, detail: string} */
    private static function cpuMax(string $dir): ?array {
        $raw = self::read($dir . '/cpu.max');
        if ($raw === null || preg_match('/^(\d+)\s+(\d+)$/', trim($raw), $m) !== 1 || (int) $m[2] <= 0) {
            return null;
        }

        return ['cpus' => (int) $m[1] / (int) $m[2], 'origin' => 'cpu.max', 'detail' => 'cgroup cpu.max ' . $m[1] . ' ' . $m[2]];
    }

    /** @return null|array{cpus: float, origin: string, detail: string} */
    private static function cfsQuota(string $dir): ?array {
        $quota = self::read($dir . '/cpu.cfs_quota_us');
        $period = self::read($dir . '/cpu.cfs_period_us');
        if ($quota === null || $period === null || !is_numeric(trim($quota)) || !is_numeric(trim($period))) {
            return null;
        }
        $q = (int) trim($quota);
        $p = (int) trim($period);
        if ($q <= 0 || $p <= 0) {
            return null;
        }

        return ['cpus' => $q / $p, 'origin' => 'cpu.cfs_quota_us', 'detail' => "cgroup cpu.cfs_quota_us {$q} / cpu.cfs_period_us {$p}"];
    }

    /**
     * @param null|array{cpus: float, origin: string, detail: string} $a
     * @param null|array{cpus: float, origin: string, detail: string} $b
     *
     * @return null|array{cpus: float, origin: string, detail: string}
     */
    private static function tighter(?array $a, ?array $b): ?array {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $b['cpus'] < $a['cpus'] ? $b : $a;
    }

    private static function onlineCpus(): int {
        if (class_exists(Util::class)) {
            $n = (int) Util::getCPUNum();
            if ($n > 0) {
                return $n;
            }
        }

        $cpuinfo = self::read('/proc/cpuinfo');
        $n = $cpuinfo === null ? 0 : preg_match_all('/^processor\s*:/m', $cpuinfo);

        return max(1, (int) $n);
    }

    private static function read(string $path): ?string {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);

        return $raw === false ? null : $raw;
    }
}
