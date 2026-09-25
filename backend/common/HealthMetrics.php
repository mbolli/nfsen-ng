<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\NfdumpSlots;

/**
 * Live figures for the Health page: capture sources, disks and nfdump slots.
 *
 * @phpstan-type SourceHealth array{profile: string, source: string, newestFile: ?string, dataUntil: ?int, written: ?int, imported: int, pending: int, state: 'healthy'|'stale'|'missing'|'nodata'}
 * @phpstan-type DiskUsage array{label: string, path: string, free: ?int, total: ?int, usedPct: ?float}
 */
final class HealthMetrics {
    /** Seconds a capture file covers; also nfcapd's default rotation interval. */
    public const INTERVAL = 300;

    /** Seconds since the newest file was written after which a source counts as stale. */
    public const STALE_AFTER = 720;

    /** How far back the newest file and the pending files are looked for. */
    public const WINDOW_DAYS = 7;

    public const PENDING_CAP = 10_000;

    /**
     * One row per profile and configured source.
     *
     * @return list<SourceHealth>
     */
    public static function sources(int $now): array {
        $rows = [];
        foreach (Config::detectProfiles() as $profile) {
            foreach (Config::$settings->sources as $source) {
                $rows[] = self::source($profile, $source, $now);
            }
        }

        return $rows;
    }

    /**
     * Capture root, data directory and state directory; paths on the same filesystem are
     * merged into one entry whose label names all of them.
     *
     * @return list<DiskUsage>
     */
    public static function disks(): array {
        $candidates = [['Capture', Config::$settings->nfdumpProfilesData]];
        $candidates[] = strtolower(Config::$settings->datasourceName) === 'victoriametrics'
            ? ['Data', 'remote']
            : ['Data', self::rrdDataPath()];
        if (isset(Config::$stateDir) && Config::$stateDir !== '') {
            $candidates[] = ['State', Config::$stateDir];
        }

        /** @var list<DiskUsage> $disks */
        $disks = [];

        /** @var array<int, int> $byDevice device id => index in $disks */
        $byDevice = [];
        foreach ($candidates as [$label, $path]) {
            $stat = ($path !== 'remote' && $path !== '' && is_dir($path)) ? @stat($path) : false;
            if ($stat === false) {
                $disks[] = ['label' => $label, 'path' => $path, 'free' => null, 'total' => null, 'usedPct' => null];

                continue;
            }

            $device = (int) $stat['dev'];
            if (isset($byDevice[$device])) {
                $merged = $disks[$byDevice[$device]];
                $disks[$byDevice[$device]] = ['label' => $merged['label'] . ', ' . $label] + $merged;

                continue;
            }

            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            $free = $free === false ? null : (int) $free;
            $total = $total === false ? null : (int) $total;

            $byDevice[$device] = \count($disks);
            $disks[] = [
                'label' => $label,
                'path' => $path,
                'free' => $free,
                'total' => $total,
                'usedPct' => ($free !== null && $total !== null && $total > 0)
                    ? round(($total - $free) / $total * 100, 1)
                    : null,
            ];
        }

        return $disks;
    }

    /**
     * nfdump slots in use against the configured maximum. Processes under the shared `default`
     * handle (import daemon, MCP) and the top-N collector's `topn` count as import.
     *
     * @return array{inUse: int, max: int, byOwner: array{user: int, import: int}}
     */
    public static function activeQueries(): array {
        $byOwner = ['user' => 0, 'import' => 0];
        foreach (NfdumpSlots::running() as $handle => $pids) {
            $byOwner[\in_array($handle, ['default', 'topn'], true) ? 'import' : 'user'] += \count($pids);
        }

        return [
            'inUse' => NfdumpSlots::inUse(),
            'max' => max(1, Config::$settings->nfdumpMaxProcesses),
            'byOwner' => $byOwner,
        ];
    }

    /**
     * @return SourceHealth
     */
    private static function source(string $profile, string $source, int $now): array {
        $row = [
            'profile' => $profile,
            'source' => $source,
            'newestFile' => null,
            'dataUntil' => null,
            'written' => null,
            'imported' => 0,
            'pending' => 0,
            'state' => 'missing',
        ];

        if (!is_dir(NfcapdFiles::sourcePath($profile, $source))) {
            return $row;
        }

        $row['imported'] = self::imported($profile, $source);
        $windowStart = $now - self::WINDOW_DAYS * 86400;

        // Never imported means imported = 0, so the window start keeps the scan to 8 day directories.
        $pending = array_filter(
            NfcapdFiles::names(max($row['imported'], $windowStart), $now, $source, $profile),
            static fn (int $ts): bool => $ts > $row['imported'],
        );
        $row['pending'] = min(\count($pending), self::PENDING_CAP);

        // newest() scans whole day directories, so trim its hit to the window pending uses.
        $newest = NfcapdFiles::newest($profile, $source, self::WINDOW_DAYS, $now);
        if ($newest === null || $newest['ts'] < $windowStart) {
            $row['state'] = 'nodata';

            return $row;
        }

        $mtime = @filemtime($newest['path']);
        $row['newestFile'] = $newest['name'];
        $row['dataUntil'] = $newest['ts'] + self::INTERVAL;
        $row['written'] = $mtime === false ? null : $mtime;
        $row['state'] = $now - ($row['written'] ?? $row['dataUntil']) > self::STALE_AFTER ? 'stale' : 'healthy';

        return $row;
    }

    private static function imported(string $profile, string $source): int {
        if (!isset(Config::$db)) {
            return 0;
        }

        // Rrd::get_data_path() logs each missing file at INFO, which would flood the log ring on every poll.
        if (Config::$db instanceof Rrd && !is_file(self::rrdFile($profile, $source))) {
            return 0;
        }

        try {
            return max(0, Config::$db->last_update($source, 0, $profile));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Where Rrd::get_data_path() puts the source's port 0 file, built without its log line. */
    private static function rrdFile(string $profile, string $source): string {
        $profileDir = str_replace('/', \DIRECTORY_SEPARATOR, $profile !== '' ? $profile : Config::$settings->nfdumpProfile);

        return self::rrdDataPath() . \DIRECTORY_SEPARATOR . $profileDir . \DIRECTORY_SEPARATOR . $source . '.rrd';
    }

    /** The data_path Rrd resolves, including its fallback. */
    private static function rrdDataPath(): string {
        $configured = Config::$settings->datasourceConfig('RRD')['data_path'] ?? null;

        return \is_string($configured)
            ? $configured
            : \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data';
    }
}
