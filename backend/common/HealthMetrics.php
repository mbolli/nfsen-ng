<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpSlots;

/**
 * Live figures for the Health page: capture sources, disks, the nfdump process budget and slots,
 * and the event-loop lag.
 *
 * @phpstan-type SourceHealth array{profile: string, source: string, newestFile: ?string, dataUntil: ?int, written: ?int, imported: int, pending: int, state: 'healthy'|'stale'|'missing'|'nodata'}
 * @phpstan-type DiskUsage array{label: string, path: string, free: ?int, total: ?int, usedPct: ?float}
 * @phpstan-type SlotCounts array{interactive: int, background: int}
 * @phpstan-type ActiveQueries array{inUse: int, max: int, backgroundMax: int, byClass: SlotCounts, waiting: SlotCounts}
 * @phpstan-type ProcessBudget array{cores: int, coresSource: string, coresOrigin: string, coresFrom: string, coresDetail: string, processes: int, auto: bool, workers: int, workersPassed: int, version: string}
 * @phpstan-type LagView array{samples: int, window: int, p50: string, p95: string, max: string, level: ''|'warning'|'error'}
 */
final class HealthMetrics {
    /** Seconds a capture file covers; also nfcapd's default rotation interval. */
    public const INTERVAL = 300;

    /** Seconds since the newest file was written after which a source counts as stale. */
    public const STALE_AFTER = 720;

    /** How far back the newest file and the pending files are looked for. */
    public const WINDOW_DAYS = 7;

    public const PENDING_CAP = 10_000;

    /** Event-loop lag p95 in ms from which the System card warns; also P6b's threshold (PERF-SPEC). */
    public const float LAG_WARNING = 100.0;

    public const float LAG_ERROR = 1000.0;

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
     * nfdump slots in use and callers waiting for one, by class, against the process limit.
     *
     * @return ActiveQueries
     */
    public static function activeQueries(): array {
        return [
            'inUse' => NfdumpSlots::inUse(),
            'max' => NfdumpSlots::max(),
            'backgroundMax' => NfdumpSlots::backgroundMax(),
            'byClass' => [
                'interactive' => NfdumpSlots::inUse(NfdumpSlots::INTERACTIVE),
                'background' => NfdumpSlots::inUse(NfdumpSlots::BACKGROUND),
            ],
            'waiting' => [
                'interactive' => NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE),
                'background' => NfdumpSlots::waiting(NfdumpSlots::BACKGROUND),
            ],
        ];
    }

    /**
     * What nfdump may use: the cores and where that number came from, the process limit, the
     * configured -W and the -W actually passed (0 when this nfdump predates it).
     *
     * @return ProcessBudget
     */
    public static function processBudget(): array {
        $cpu = CpuBudget::detect();
        $settings = Config::$settings;
        $version = Nfdump::version($settings->nfdumpBinary);

        return [
            'cores' => $cpu['cores'],
            'coresSource' => $cpu['source'],
            'coresOrigin' => $cpu['origin'],
            'coresFrom' => CpuBudget::sourceLabel($cpu['source']),
            'coresDetail' => $cpu['detail'],
            'processes' => NfdumpSlots::max(),
            'auto' => $settings->nfdumpMaxProcessesAuto,
            'workers' => $settings->nfdumpWorkers,
            'workersPassed' => Nfdump::workerThreads($version, $settings->nfdumpWorkers),
            'version' => $version,
        ];
    }

    /**
     * What happens to -W: passed with its count, or why it is not.
     *
     * @param ProcessBudget $budget
     */
    public static function workersText(array $budget): string {
        return match (true) {
            $budget['workersPassed'] > 0 => '-W ' . $budget['workersPassed'] . ' on every nfdump run',
            $budget['workers'] === 0 => "Not passed: nfdump's own default (NFSEN_NFDUMP_WORKERS=0)",
            $budget['version'] === '' => 'Not passed: the nfdump version is unknown',
            default => 'Not passed: nfdump ' . $budget['version'] . ' has no -W (added in ' . Nfdump::WORKERS_SINCE . ')',
        };
    }

    /**
     * "2 interactive, 1 background, 1 waiting": the slot split as one phrase.
     *
     * @param ActiveQueries $active
     */
    public static function slotSplit(array $active): string {
        $text = $active['byClass']['interactive'] . ' interactive, ' . $active['byClass']['background'] . ' background';
        $waiting = $active['waiting']['interactive'] + $active['waiting']['background'];

        return $waiting > 0 ? $text . ', ' . $waiting . ' waiting' : $text;
    }

    /** WINDOW_DAYS calendar days back in the nfcapd timezone, so the window spans 8 day directories across a DST change too. */
    public static function windowStart(int $now, ?\DateTimeZone $tz = null): int {
        return (new \DateTimeImmutable('@' . $now))->setTimezone($tz ?? Config::nfcapdTimezone())->modify('-' . self::WINDOW_DAYS . ' days')->getTimestamp();
    }

    /**
     * The worker's event-loop lag over the last minute, levelled by its p95 in whole ms, the
     * precision lagText() shows at both thresholds: a p95 that reads "100 ms" warns.
     *
     * @return LagView
     */
    public static function loopLag(?float $now = null): array {
        $lag = LoopLag::summary($now);
        $p95 = $lag['p95'] === null ? null : round($lag['p95']);

        return [
            'samples' => $lag['samples'],
            'window' => $lag['window'],
            'p50' => self::lagText($lag['p50']),
            'p95' => self::lagText($lag['p95']),
            'max' => self::lagText($lag['max']),
            'level' => match (true) {
                $p95 === null || $p95 < self::LAG_WARNING => '',
                $p95 < self::LAG_ERROR => 'warning',
                default => 'error',
            },
        ];
    }

    /** "0.4 ms", "85 ms", "2.3 s"; '' before the first tick. */
    public static function lagText(?float $ms): string {
        return match (true) {
            $ms === null => '',
            round($ms, 1) < 10 => number_format($ms, 1) . ' ms',
            round($ms) < 1000 => number_format($ms) . ' ms',
            default => number_format($ms / 1000, 1) . ' s',
        };
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
        $windowStart = self::windowStart($now);

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
