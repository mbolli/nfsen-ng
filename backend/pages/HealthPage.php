<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\ImportActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\HealthMetrics;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\ImportStats;
use mbolli\nfsen_ng\common\ProcessInfo;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Health (was Settings > Health and Import): import controls, capture sources, disks, this
 * instance, the grouped checks and the recent log (4.7).
 *
 * @phpstan-import-type HealthCheck from HealthChecker
 * @phpstan-import-type DaemonInfo from HealthChecker
 * @phpstan-import-type SourceHealth from HealthMetrics
 * @phpstan-import-type DiskUsage from HealthMetrics
 * @phpstan-import-type ActiveQueries from HealthMetrics
 * @phpstan-import-type StoreFacts from Database
 *
 * @phpstan-type Metrics array{ts: int, sources: list<SourceHealth>, disks: list<DiskUsage>, versions: array{php: string, openswoole: string, sqlite: string, nfdump: string}, journalMode: string}
 * @phpstan-type HealthCache array{ts: int, checks: list<HealthCheck>, level: string, metrics: null|Metrics}
 */
final class HealthPage implements Page {
    /** App-global cache of the checks and metrics, shared by every tab. */
    public const string CACHE = 'health_cache';

    /** Age at which the checks and metrics are recomputed while Health is open. */
    public const int CACHE_TTL = 30;

    /** Age at which the sidebar's level recomputes the checks while Health is closed. */
    public const int IDLE_TTL = 300;

    /** App-global result of the last "Collect missing top-N now": {ts, queued, error}. */
    public const string TOPN_FILL = 'topn_fill';

    /** App-global outcome of the last import pass: complete, cancelled or failed. */
    public const string IMPORT_OUTCOME = ImportDaemon::OUTCOME_STATE;

    public const int LOG_LINES = 200;

    public const int IMPORT_LOG_LINES = 100;

    /** Whether a refresh coroutine is running, so concurrent renders start only one. */
    private static bool $refreshing = false;

    /** @var array<string, Context> tabs that saw a stale cache, re-rendered when the refresh lands */
    private static array $waiting = [];

    public static function id(): string {
        return 'health';
    }

    public static function title(): string {
        return 'Health';
    }

    public static function lede(): string {
        return 'Import, capture sources and this instance.';
    }

    public static function icon(): string {
        return 'heart-pulse';
    }

    public static function group(): string {
        return 'monitor';
    }

    public static function signals(Context $c): void {
        // The profile the import buttons act on; register() points it at the first daemon.
        $c->signal(Config::$settings->nfdumpProfile, 'admin_target_profile', clientWritable: true);
        // The browser opens the rescan confirmation without a round trip.
        $c->signal(false, 'confirm_rescan', clientWritable: true);
        // Whether an import also scans the port series.
        $c->signal(true, 'import_scan_ports', clientWritable: true);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        $first = array_key_first(self::daemons($app));
        if ($first !== null) {
            $c->getSignal('admin_target_profile')?->setValue($first, markChanged: false);
        }

        ImportActions::register($c, $app);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $now = time();
        $fatal = Shell::fatal($app);
        $cache = $fatal ? null : self::cached($app, true, $now, $c);
        $metrics = $cache['metrics'] ?? null;
        // Live, unlike the rest: an in-memory count that the cache would only make stale.
        $queries = $fatal ? null : HealthMetrics::activeQueries();
        $checks = $queries === null ? $cache['checks'] ?? [] : HealthChecker::withLiveSlots($cache['checks'] ?? [], $queries);
        $sources = $metrics['sources'] ?? [];

        return [
            'level' => $cache['level'] ?? 'unknown',
            'fatal' => $fatal,
            // The first refresh after a start or an import pass has not landed yet.
            'pending' => !$fatal && ($cache === null || $metrics === null),
            'datasource' => Config::$settings->datasourceName,
            'checks' => $checks,
            'counts' => [
                'error' => \count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'error')),
                'warning' => \count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warning')),
            ],
            'import' => self::importCard($app, $sources, $fatal, $now),
            'topn' => self::topnCard($app, $fatal),
            'sources' => $sources,
            'disks' => array_map(self::disk(...), $metrics['disks'] ?? []),
            'system' => self::systemCard($metrics, $queries, $fatal, $now),
            'log' => array_map(
                static fn (array $entry): array => [...$entry, 'band' => self::band($entry['level'], $entry['levelName'])],
                Debug::recent(self::LOG_LINES),
            ),
            // Lines below this level are never logged, so the log card names it.
            'logLevel' => Settings::logLevelToString(Config::$settings->logPriority),
        ];
    }

    /**
     * The checks from the shared cache, recomputed when older than CACHE_TTL while Health
     * is open and IDLE_TTL otherwise.
     *
     * @return list<HealthCheck>
     */
    public static function checks(Via $app, bool $active, int $now, ?Context $c = null): array {
        return self::cached($app, $active, $now, $c)['checks'] ?? [];
    }

    /** 'ok', 'warning' or 'error' for the sidebar; 'unknown' until the first check or when the configuration failed to load. */
    public static function level(Via $app, int $now, ?Context $c = null): string {
        return Shell::fatal($app) ? 'unknown' : self::cached($app, false, $now, $c)['level'] ?? 'unknown';
    }

    /** Marks the checks and metrics due, keeping them on screen until the refresh lands. */
    public static function invalidate(Via $app): void {
        $cache = self::stored($app);
        if ($cache !== null) {
            $app->setGlobalState(self::CACHE, [
                ...$cache,
                'ts' => 0,
                'metrics' => $cache['metrics'] === null ? null : [...$cache['metrics'], 'ts' => 0],
            ]);
        }
    }

    /** @param list<HealthCheck> $checks */
    public static function levelOf(array $checks): string {
        $statuses = array_column($checks, 'status');

        return match (true) {
            \in_array('error', $statuses, true) => 'error',
            \in_array('warning', $statuses, true) => 'warning',
            default => 'ok',
        };
    }

    /** Uptime in its two largest units, e.g. "12 days 4 hours", "5 minutes". */
    public static function duration(int $seconds): string {
        $parts = [];
        foreach (['day' => 86400, 'hour' => 3600, 'minute' => 60, 'second' => 1] as $name => $size) {
            if ($seconds < $size) {
                // "12 days", not "12 days 0 hours 5 minutes".
                if ($parts !== []) {
                    break;
                }

                continue;
            }
            $n = intdiv($seconds, $size);
            $seconds %= $size;
            $parts[] = $n . ' ' . $name . ($n === 1 ? '' : 's');
            if (\count($parts) === 2) {
                break;
            }
        }

        return $parts === [] ? '0 seconds' : implode(' ', $parts);
    }

    /** Why the top-N collector is not running, '' while it runs. */
    public static function topnOff(Via $app, bool $fatal): string {
        if ($fatal) {
            return 'Off: the configuration did not load.';
        }
        // AppStartup returns before TopNCollector::boot() when the daemon is skipped.
        if ((bool) $app->globalState('daemon_disabled', false)) {
            return 'Off: the import daemon is disabled (NFSEN_SKIP_DAEMON).';
        }
        if (Config::$settings->topnRetentionDays <= 0) {
            return 'Off: NFSEN_TOPN_RETENTION_DAYS is 0.';
        }

        try {
            if (Database::shared()->isReadOnly()) {
                return 'Off: the SQLite store is read-only.';
            }
        } catch (StoreUnavailableException $e) {
            return 'Off: ' . $e->reason . '.';
        }

        return TopNCollector::booted() ? '' : 'Off: the collector did not start, see the log.';
    }

    /** Log levels as the level filter groups them: error (and worse), warning, or the level's own name. */
    public static function band(int $level, string $levelName): string {
        return match (true) {
            $level <= LOG_ERR => 'error',
            $level === LOG_WARNING => 'warning',
            default => $levelName,
        };
    }

    /**
     * @param list<SourceHealth> $sources
     *
     * @return array<string, mixed>
     */
    private static function importCard(Via $app, array $sources, bool $fatal, int $now): array {
        $daemons = self::daemons($app);
        $disabled = (bool) $app->globalState('daemon_disabled', false);
        $running = array_any($daemons, static fn (ImportDaemon $d): bool => $d->isLocked());
        $state = match (true) {
            $disabled => 'disabled',
            $running => 'running',
            $daemons === [] || !array_all($daemons, static fn (ImportDaemon $d): bool => $d->isDaemonReady()) => 'starting',
            default => 'idle',
        };

        $log = ImportDaemon::log($app);
        $counts = ImportDaemon::logCounts($app);
        $outcome = (string) $app->globalState(self::IMPORT_OUTCOME, '');
        $pending = array_sum(array_column($sources, 'pending'));

        return [
            'state' => $state,
            'profile' => (string) $app->globalState('import_active_profile', ''),
            'daemons' => array_map(
                static fn (string $profile, ImportDaemon $d): array => ['profile' => $profile, ...self::daemonInfo($d)],
                array_map('strval', array_keys($daemons)),
                array_values($daemons),
            ),
            'rate' => ImportStats::summary($now),
            'pending' => $pending,
            'pendingCapped' => array_any($sources, static fn (array $s): bool => $s['pending'] >= HealthMetrics::PENDING_CAP),
            // Whether Import offers a non-destructive backfill or the rebuild RRD needs (#171).
            'historicWrites' => !$fatal && isset(Config::$db) && Config::$db->acceptsHistoricWrites(),
            'hasPorts' => Config::$settings->ports !== [],
            'log' => array_map(
                static fn (array $e): array => [
                    'ts' => (int) ($e['ts'] ?? 0),
                    'band' => (int) ($e['level'] ?? LOG_WARNING) <= LOG_ERR ? 'error' : 'warning',
                    'msg' => \is_scalar($e['msg'] ?? null) ? (string) $e['msg'] : '',
                ],
                array_reverse(\array_slice($log, -self::IMPORT_LOG_LINES)),
            ),
            'logTotal' => $counts['total'],
            'logErrors' => $counts['errors'],
            // Neutral unless a pass completed or failed; a catch-up that succeeded sets no outcome.
            'outcomeLevel' => match (true) {
                $outcome === 'failed' => 'error',
                $outcome !== 'complete' => '',
                $counts['errors'] > 0 => 'error',
                $counts['total'] > 0 => 'warning',
                default => 'success',
            },
        ];
    }

    /** @return array<string, mixed> */
    private static function topnCard(Via $app, bool $fatal): array {
        $fill = $app->globalState(self::TOPN_FILL, null);

        return [
            ...TopNCollector::stats(),
            'retentionDays' => Config::$settings->topnRetentionDays,
            'off' => self::topnOff($app, $fatal),
            'fill' => \is_array($fill) ? $fill : null,
        ];
    }

    /**
     * @param DiskUsage $disk
     *
     * @return array<string, mixed>
     */
    private static function disk(array $disk): array {
        return [
            ...$disk,
            'level' => HealthChecker::diskLevel($disk['usedPct']),
            'freeText' => $disk['free'] === null ? '' : Estimate::humanBytes($disk['free']),
            'totalText' => $disk['total'] === null ? '' : Estimate::humanBytes($disk['total']),
        ];
    }

    /**
     * @param null|Metrics       $metrics
     * @param null|ActiveQueries $queries
     *
     * @return array<string, mixed>
     */
    private static function systemCard(?array $metrics, ?array $queries, bool $fatal, int $now): array {
        $versions = $metrics['versions'] ?? ['php' => PHP_VERSION, 'openswoole' => '', 'sqlite' => '', 'nfdump' => ''];

        return [
            ...$versions,
            'queries' => $queries === null ? ['inUse' => 0, 'max' => 0, 'split' => ''] : [
                'inUse' => $queries['inUse'],
                'max' => $queries['max'],
                'split' => HealthMetrics::slotSplit($queries),
            ],
            'budget' => $fatal ? null : HealthMetrics::processBudget(),
            // Live and independent of the configuration: the probe starts before it loads.
            'lag' => HealthMetrics::loopLag(),
            'uptime' => self::duration(ProcessInfo::uptime($now)),
            'startedAt' => ProcessInfo::startedAt(),
            'datasource' => Config::$settings->datasourceName,
            'journalMode' => HealthChecker::journalLabel($metrics['journalMode'] ?? ''),
        ];
    }

    /** @return array<string, ImportDaemon> */
    private static function daemons(Via $app): array {
        $daemons = $app->globalState('daemons', []);

        return \is_array($daemons) ? array_filter($daemons, static fn (mixed $d): bool => $d instanceof ImportDaemon) : [];
    }

    /** @return array<string, DaemonInfo> */
    private static function daemonsInfo(Via $app): array {
        return array_map(self::daemonInfo(...), self::daemons($app));
    }

    /** @return DaemonInfo */
    private static function daemonInfo(ImportDaemon $daemon): array {
        return [
            'ready' => $daemon->isDaemonReady(),
            'watchCount' => $daemon->getWatchCount(),
            'lastAutoImport' => $daemon->getLastAutoImportTime(),
        ];
    }

    /**
     * The shared cache as a render reads it. The checks are due after CACHE_TTL while Health
     * is open and IDLE_TTL otherwise, the metrics only while it is open. A due part is
     * recomputed in one coroutine, so its file scans never hold up a render, and the tabs
     * that asked are re-rendered when it lands. Outside a coroutine (tests) it runs inline.
     *
     * @return null|HealthCache
     */
    private static function cached(Via $app, bool $active, int $now, ?Context $c): ?array {
        $cache = self::stored($app);
        $checksDue = $cache === null || $now - $cache['ts'] >= ($active ? self::CACHE_TTL : self::IDLE_TTL);
        $metricsDue = $active && ($cache === null || $cache['metrics'] === null || $now - $cache['metrics']['ts'] >= self::CACHE_TTL);
        if ((!$checksDue && !$metricsDue) || $app->isShuttingDown()) {
            return $cache;
        }

        if (Coroutine::getCid() <= 0) {
            return self::refresh($app, $checksDue, $metricsDue, $now);
        }

        if ($c !== null) {
            self::$waiting[$c->getId()] = $c;
        }
        if (!self::$refreshing) {
            self::$refreshing = true;
            Coroutine::create(static function () use ($app, $checksDue, $metricsDue): void {
                try {
                    self::refresh($app, $checksDue, $metricsDue, time());
                    $landed = true;
                } catch (\Throwable $e) {
                    Debug::getInstance()->log('Health checks not refreshed: ' . $e->getMessage(), LOG_WARNING);
                    $landed = false;
                } finally {
                    self::$refreshing = false;
                    $waiting = self::$waiting;
                    self::$waiting = [];
                }
                // After a failure the tabs retry on their next render instead of at once.
                foreach ($landed && !$app->isShuttingDown() ? $waiting : [] as $tab) {
                    try {
                        $tab->sync();
                    } catch (\Throwable) {
                        // The tab closed while the refresh ran.
                    }
                }
            });
        }

        return $cache;
    }

    /**
     * Recomputes the due parts and stores the result.
     *
     * @return HealthCache
     */
    private static function refresh(Via $app, bool $checksDue, bool $metricsDue, int $now): array {
        $cache = self::stored($app);
        $facts = self::storeFacts();

        if ($cache === null || $checksDue) {
            $checks = HealthChecker::run((bool) $app->globalState('daemon_disabled', false), self::daemonsInfo($app), $facts);
            $cache = ['ts' => $now, 'checks' => $checks, 'level' => self::levelOf($checks), 'metrics' => $cache['metrics'] ?? null];
        }
        if ($metricsDue) {
            $cache['metrics'] = self::metrics($now, $facts);
        }
        $app->setGlobalState(self::CACHE, $cache);

        return $cache;
    }

    /** @return null|HealthCache */
    private static function stored(Via $app): ?array {
        $stored = $app->globalState(self::CACHE, null);

        /** @var null|HealthCache */
        return \is_array($stored) && \is_int($stored['ts'] ?? null) && \is_array($stored['checks'] ?? null) && \is_string($stored['level'] ?? null)
            ? [...$stored, 'metrics' => \is_array($stored['metrics'] ?? null) ? $stored['metrics'] : null]
            : null;
    }

    /** @return null|StoreFacts null when no state directory is configured */
    private static function storeFacts(): ?array {
        return isset(Config::$stateDir) && Config::$stateDir !== ''
            ? Database::inspect(rtrim(Config::$stateDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . Database::FILENAME)
            : null;
    }

    /**
     * @param null|StoreFacts $facts
     *
     * @return Metrics
     */
    private static function metrics(int $now, ?array $facts): array {
        return [
            'ts' => $now,
            'sources' => HealthMetrics::sources($now),
            'disks' => HealthMetrics::disks(),
            'versions' => ProcessInfo::versions(),
            'journalMode' => $facts['journalMode'] ?? '',
        ];
    }
}
