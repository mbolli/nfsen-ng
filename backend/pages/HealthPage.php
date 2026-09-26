<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\ImportActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\ImportDaemon;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Health (was Settings > Health and Import): checks, capture sources and import controls (4.7).
 *
 * @phpstan-import-type HealthCheck from HealthChecker
 * @phpstan-import-type DaemonInfo from HealthChecker
 */
final class HealthPage implements Page {
    /** App-global cache of the checks, shared by every tab. */
    public const string CACHE = 'health_cache';

    /** Age at which the checks are recomputed while Health is open. */
    public const int CACHE_TTL = 30;

    /** Age at which the sidebar's level recomputes them while Health is closed. */
    public const int IDLE_TTL = 300;

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
        $fatal = Shell::fatal($app);
        $checks = $fatal ? [] : self::checks($app, true, time());
        $primary = $app->globalState('daemon', null);

        return [
            'level' => $fatal ? 'unknown' : self::levelOf($checks),
            Shell::LEGACY => [
                'healthChecks' => $checks,
                'hasPorts' => Config::$settings->ports !== [],
                // Whether Import offers a non-destructive backfill or the rebuild RRD needs (#171).
                'datasourceAcceptsHistoricWrites' => !$fatal && Config::$db->acceptsHistoricWrites(),
                'importLog' => $app->globalState('import_log', []),
                'importActiveProfile' => $app->globalState('import_active_profile', ''),
                'daemonDisabled' => (bool) $app->globalState('daemon_disabled', false),
                'daemonsInfo' => self::daemonsInfo($app),
                'daemonInfo' => $primary instanceof ImportDaemon ? self::daemonInfo($primary) : null,
            ],
        ];
    }

    /**
     * The checks from the shared cache, recomputed when older than CACHE_TTL while Health
     * is open and IDLE_TTL otherwise.
     *
     * @return list<HealthCheck>
     */
    public static function checks(Via $app, bool $active, int $now): array {
        return self::refreshed($app, $active, $now)['checks'];
    }

    /** 'ok', 'warning' or 'error' for the sidebar; 'unknown' when the configuration failed to load. */
    public static function level(Via $app, int $now): string {
        return Shell::fatal($app) ? 'unknown' : self::refreshed($app, false, $now)['level'];
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

    /** @return array{ts: int, checks: list<HealthCheck>, level: string} */
    private static function refreshed(Via $app, bool $active, int $now): array {
        $cache = $app->globalState(self::CACHE, null);
        if (\is_array($cache) && \is_int($cache['ts'] ?? null) && $now - $cache['ts'] < ($active ? self::CACHE_TTL : self::IDLE_TTL)) {
            /** @var array{ts: int, checks: list<HealthCheck>, level: string} */
            return $cache;
        }

        $checks = HealthChecker::run((bool) $app->globalState('daemon_disabled', false), self::daemonsInfo($app));
        $cache = ['ts' => $now, 'checks' => $checks, 'level' => self::levelOf($checks)];
        $app->setGlobalState(self::CACHE, $cache);

        return $cache;
    }
}
