<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Encapsulates the server-startup logic that runs once in the onStart coroutine:
 * Config/DB initialisation, AlertManager, ImportDaemon setup, gap-fill import, and the
 * inotify poll interval.
 */
class AppStartup {
    /**
     * Run everything that must happen once when the OpenSwoole worker starts.
     * Pass the Via application instance so global state and intervals can be registered.
     */
    public static function boot(Via $app): void {
        try {
            Config::initialize(true);
        } catch (\Throwable $e) {
            $app->setGlobalState('_fatalError', $e->getMessage());
            error_log('[nfsen-ng] FATAL: ' . $e->getMessage());

            return;
        }

        $debug = Debug::getInstance();
        $debug->log('nfsen-ng started (php-via)', LOG_INFO);

        // Instantiate AlertManager — persists across all requests for the lifetime of the worker.
        $alertManager = new AlertManager(
            Config::$db,
            __DIR__ . '/../settings/alerts-state.json',
            __DIR__ . '/../settings/alerts-log.json',
            Config::$settings->alertEmailFrom
        );
        $app->setGlobalState('alertManager', $alertManager);

        if (getenv('NFSEN_SKIP_DAEMON') === '1' || getenv('NFSEN_SKIP_DAEMON') === 'true') {
            $debug->log('ImportDaemon skipped (NFSEN_SKIP_DAEMON)', LOG_INFO);
            $app->setGlobalState('daemon_disabled', true);

            return;
        }

        $profiles = Config::detectProfiles();

        /** @var array<string, ImportDaemon> $daemons */
        $daemons = [];
        foreach ($profiles as $profile) {
            $daemons[$profile] = new ImportDaemon($profile);
        }
        $app->setGlobalState('daemons', $daemons);
        // Keep 'daemon' pointing to the primary daemon for backward-compat health checks.
        $primaryDaemon = !empty($daemons) ? reset($daemons) : null;
        $app->setGlobalState('daemon', $primaryDaemon);
        $app->setGlobalState('import_active_profile', array_key_first($daemons) ?? Config::$settings->nfdumpProfile);

        // Shared import progress — written by the import coroutine, read by every admin tab.
        $app->setGlobalState('import_progress', 0);
        $app->setGlobalState('import_current_file', '');
        $app->setGlobalState('import_status_text', '');
        $app->setGlobalState('import_eta', '');
        $app->setGlobalState('import_log', []);
        $app->setGlobalState('import_cancel', false);

        // Startup import logic — only runs a gap fill when the database already has
        // data. A fresh install skips the import entirely so the user can configure
        // and trigger "Initial Import" manually from the Admin panel.
        //
        // NFSEN_SKIP_INITIAL_IMPORT=true  → always skip; only set up inotify watches.
        // Existing data (any source last_update > 0) → gap fill (catch up missed files).
        // No data at all                  → skip; user must run Initial Import manually.
        Coroutine::create(function () use ($app, $daemons, $debug): void {
            /** @var array<string, ImportDaemon> $daemons */
            /** Append new Debug WARNING+ entries to the global log state. */
            $flushLog = static function () use ($app): void {
                $new = Debug::drainBuffer();
                if ($new !== []) {
                    $log = $app->globalState('import_log', []);
                    $app->setGlobalState('import_log', array_merge($log, $new));
                }
            };

            // NFSEN_SKIP_INITIAL_IMPORT: skip gap fill, just set up inotify watches.
            $skipEnv = getenv('NFSEN_SKIP_INITIAL_IMPORT');
            if ($skipEnv === '1' || $skipEnv === 'true') {
                $debug->log('ImportDaemon: startup import skipped (NFSEN_SKIP_INITIAL_IMPORT)', LOG_INFO);
                foreach ($daemons as $daemon) {
                    $daemon->setupWatchesOnly();
                }

                return;
            }

            // Detect which profiles already have data — profiles with no data are skipped
            // (fresh install for that profile; user triggers Initial Import manually).
            $daemonsToRun = [];
            foreach ($daemons as $profile => $daemon) {
                $hasData = false;
                foreach (Config::$settings->sources as $source) {
                    if (Config::$db->last_update($source, 0, $profile) > 0) {
                        $hasData = true;

                        break;
                    }
                }
                if ($hasData) {
                    $daemonsToRun[$profile] = $daemon;
                } else {
                    $debug->log("ImportDaemon [{$profile}]: no existing data — startup gap-fill skipped; use Admin panel to run Initial Import", LOG_INFO);
                    $daemon->setupWatchesOnly();
                }
            }

            if (empty($daemonsToRun)) {
                return;
            }

            // Run initial (catch-up) import sequentially for each profile that has existing data.
            foreach ($daemonsToRun as $profile => $daemon) {
                $app->setGlobalState('import_active_profile', $profile);
                $app->setGlobalState('import_status_text', "[{$profile}] Catching up on missed files…");
                if (!empty($app->getClients())) {
                    $app->broadcast('admin:import');
                }

                try {
                    $daemon->initialImport(
                        function (array $progress) use ($app, $flushLog, $profile): void {
                            $app->setGlobalState('import_progress', $progress['pct']);
                            $app->setGlobalState('import_current_file', $progress['file']);
                            $app->setGlobalState('import_status_text', "[{$profile}] Catching up: " . $progress['processed'] . ' / ' . $progress['total'] . ' files');
                            $app->setGlobalState('import_eta', $progress['eta']);
                            $flushLog();
                            if (!empty($app->getClients())) {
                                $app->broadcast('admin:import');
                            }
                        },
                        static fn (): bool => (bool) $app->globalState('import_cancel', false)
                    );
                    $flushLog();
                    $app->setGlobalState('import_status_text', "[{$profile}] Up to date");
                    $app->setGlobalState('import_progress', 100);
                    $app->setGlobalState('import_current_file', '');
                    $app->setGlobalState('import_eta', '');
                } catch (\Throwable $e) {
                    $debug->log("ImportDaemon [{$profile}]: catch-up import failed: " . $e->getMessage(), LOG_ERR);
                    $app->setGlobalState('import_status_text', "[{$profile}] Catch-up failed: " . $e->getMessage());
                }

                if (!empty($app->getClients())) {
                    $app->broadcast('admin:import');
                }
            }
        });

        // Ongoing inotify poll every 1 s — setInterval runs in the event loop, not
        // in a child coroutine, which is safe per Import class restrictions.
        $app->setInterval(function () use ($app, $daemons, $debug): void {
            /** @var array<string, ImportDaemon> $daemons */
            foreach ($daemons as $_profile => $daemon) {
                try {
                    $daemon->pollOnce(function () use ($app, $debug, $_profile): void {
                        $debug->log('ImportDaemon: file imported → broadcasting rrd:live', LOG_DEBUG);

                        // Surface any RRD write warnings from this inotify-triggered import
                        $new = Debug::drainBuffer();
                        if ($new !== []) {
                            $log = $app->globalState('import_log', []);
                            $merged = array_merge($log, $new);
                            if (\count($merged) > 100) {
                                $merged = \array_slice($merged, -100);
                            }
                            $app->setGlobalState('import_log', $merged);
                            if (!empty($app->getClients())) {
                                $app->broadcast('admin:import');
                            }
                        }

                        if (!empty($app->getClients())) {
                            $app->broadcast('rrd:live');
                        }

                        // Evaluate alert rules for this profile after each successful import
                        /** @var null|AlertManager $alertMgr */
                        $alertMgr = $app->globalState('alertManager', null);
                        if ($alertMgr !== null && !empty(Config::$settings->alerts)) {
                            $fired = $alertMgr->runPeriodic(Config::$settings->alerts, $_profile);
                            if (!empty($fired)) {
                                $app->setGlobalState('alert_fired', ['names' => $fired, 'ts' => time()]);
                                if (!empty($app->getClients())) {
                                    $app->broadcast('alerts:fired');
                                }
                            }
                        }
                    });
                } catch (\Throwable $e) {
                    $debug->log('ImportDaemon: poll error: ' . $e->getMessage(), LOG_ERR);
                }
            }
        }, 1000);
    }
}
