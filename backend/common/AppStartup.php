<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Rrd;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Encapsulates the server-startup logic that runs once in the onStart coroutine: the
 * event-loop lag probe, Config/DB initialisation, the SQLite store, AlertManager,
 * ImportDaemon setup, gap-fill import, the inotify poll interval and the top-N collector.
 * shutdown() ends them again.
 */
class AppStartup {
    /** How long shutdown() waits for the work it ended; php-via's whole stop budget is about 2 s. */
    public const float SHUTDOWN_WAIT_SECONDS = 1.0;

    /**
     * Run everything that must happen once when the OpenSwoole worker starts.
     * Pass the Via application instance so global state and intervals can be registered.
     */
    public static function boot(Via $app): void {
        LoopLag::start();

        try {
            Config::initialize(true);
        } catch (\Throwable $e) {
            $app->setGlobalState('_fatalError', $e->getMessage());
            error_log('[nfsen-ng] FATAL: ' . $e->getMessage());

            return;
        }

        $debug = Debug::getInstance();
        $debug->log('nfsen-ng started (php-via)', LOG_INFO);

        // Only the server worker migrates. shared() logs a broken store; every consumer degrades.
        try {
            Database::shared();
        } catch (StoreUnavailableException) {
        }

        // Instantiate AlertManager: persists across all requests for the lifetime of the worker.
        $alertManager = new AlertManager(
            Config::$db,
            Config::$stateDir . \DIRECTORY_SEPARATOR . 'alerts-state.json',
            Config::$stateDir . \DIRECTORY_SEPARATOR . 'alerts-log.json',
            Config::$settings->alertEmailFrom
        );
        $app->setGlobalState('alertManager', $alertManager);

        try {
            $migrated = $alertManager->migrateLegacyLog();
            if ($migrated > 0) {
                $debug->log("Alerts: moved {$migrated} entries from alerts-log.json into the SQLite store", LOG_INFO);
            }
        } catch (\Throwable $e) {
            $debug->log('Alerts: alerts-log.json was not migrated, retrying on the next start: ' . $e->getMessage(), LOG_WARNING);
        }

        // The first long range after a start then reads only its partial days.
        Coroutine::create(static function () use ($app, $debug): void {
            if (!Config::$db instanceof Rrd) {
                return;
            }
            foreach (Config::$settings->sources as $source) {
                if ($app->isShuttingDown()) {
                    return;
                }

                try {
                    Config::$db->warmTotals($source);
                } catch (\Throwable $e) {
                    $debug->log("Stored totals of {$source} not read ahead: " . $e->getMessage(), LOG_WARNING);
                }
                Coroutine::usleep(1000);
            }
        });

        if ((bool) EnvRegistry::value('NFSEN_SKIP_DAEMON')) {
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

        // Shared import progress, written by the import coroutine, read by every admin tab.
        $app->setGlobalState('import_progress', 0);
        $app->setGlobalState('import_current_file', '');
        $app->setGlobalState('import_status_text', '');
        $app->setGlobalState('import_eta', '');
        ImportDaemon::clearLog($app);
        $app->setGlobalState('import_cancel', false);

        try {
            TopNCollector::boot($app);
        } catch (\Throwable $e) {
            $debug->log('TopN collector off: ' . $e->getMessage(), LOG_ERR);
        }

        // Startup import: a gap fill only when the store already has data. A fresh install
        // imports nothing until someone presses Trigger in the Health page's Import card.
        // NFSEN_SKIP_INITIAL_IMPORT=true skips the gap fill and only sets up the watches.
        Coroutine::create(static function () use ($app, $daemons, $debug): void {
            // NFSEN_SKIP_INITIAL_IMPORT: skip gap fill, just set up inotify watches.
            if ((bool) EnvRegistry::value('NFSEN_SKIP_INITIAL_IMPORT')) {
                $debug->log('ImportDaemon: startup import skipped (NFSEN_SKIP_INITIAL_IMPORT)', LOG_INFO);
                foreach ($daemons as $daemon) {
                    $daemon->setupWatchesOnly();
                }

                return;
            }

            // Profiles without data are skipped (a fresh install for that profile).
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
                    $debug->log("ImportDaemon [{$profile}]: no existing data, startup gap-fill skipped; press Trigger on the Health page to import", LOG_INFO);
                    $daemon->setupWatchesOnly();
                }
            }

            if (empty($daemonsToRun)) {
                return;
            }

            self::catchUp($app, $daemonsToRun);
        });

        // Ongoing inotify poll every 1 s. The interval callback runs in a coroutine of its own,
        // so ImportDaemon::broadcast() can throttle what it sends.
        $onImported = [];
        foreach ($daemons as $profile => $daemon) {
            $onImported[$profile] = self::onFileImported($app, (string) $profile);
        }
        $app->setInterval(static function () use ($daemons, $onImported, $debug): void {
            foreach ($daemons as $profile => $daemon) {
                try {
                    $daemon->pollOnce($onImported[$profile]);
                } catch (\Throwable $e) {
                    $debug->log('ImportDaemon: poll error: ' . $e->getMessage(), LOG_ERR);
                }
            }
        }, 1000);
    }

    /**
     * The startup catch-up import of each profile that already has data, one after the other.
     *
     * @param array<string, ImportDaemon> $daemons
     */
    public static function catchUp(Via $app, array $daemons): void {
        $debug = Debug::getInstance();
        // New Debug WARNING+ entries go to the Import card's log.
        $flushLog = static function () use ($app): void {
            ImportDaemon::appendLog($app, Debug::drainBuffer());
        };

        foreach ($daemons as $profile => $daemon) {
            if ($app->isShuttingDown()) {
                return;
            }
            $app->setGlobalState('import_active_profile', $profile);
            $app->setGlobalState('import_status_text', "[{$profile}] Catching up on missed files…");
            ImportDaemon::broadcast($app, 'admin:import', now: true);

            try {
                $daemon->initialImport(
                    static function (array $progress) use ($app, $flushLog, $profile): void {
                        $app->setGlobalState('import_progress', $progress['pct']);
                        $app->setGlobalState('import_current_file', $progress['file']);
                        $app->setGlobalState('import_status_text', "[{$profile}] Catching up: " . $progress['processed'] . ' / ' . $progress['total'] . ' files');
                        $app->setGlobalState('import_eta', $progress['eta']);
                        $flushLog();
                        ImportDaemon::broadcast($app, 'admin:import');
                    },
                    static fn (): bool => $app->isShuttingDown() || (bool) $app->globalState('import_cancel', false)
                );
                $flushLog();
                $app->setGlobalState('import_status_text', "[{$profile}] Up to date");
                $app->setGlobalState('import_progress', 100);
                $app->setGlobalState('import_current_file', '');
                $app->setGlobalState('import_eta', '');
            } catch (\Throwable $e) {
                $debug->log("ImportDaemon [{$profile}]: catch-up import failed: " . $e->getMessage(), LOG_ERR);
                $flushLog();
                $app->setGlobalState('import_status_text', "[{$profile}] Catch-up failed: " . $e->getMessage());
                $app->setGlobalState(ImportDaemon::OUTCOME_STATE, 'failed');
            }

            ImportDaemon::broadcast($app, 'admin:import', now: true);
        }
    }

    /**
     * What the inotify poll runs after each capture file of $profile it imported.
     *
     * @return \Closure(string, int, bool): void
     */
    public static function onFileImported(Via $app, string $profile): \Closure {
        $debug = Debug::getInstance();

        return static function (string $source, int $fileTs, bool $isLastSource) use ($app, $debug, $profile): void {
            $debug->log('ImportDaemon: file imported → broadcasting rrd:live', LOG_DEBUG);

            // Surface any RRD write warnings from this inotify-triggered import
            $new = Debug::drainBuffer();
            if ($new !== []) {
                ImportDaemon::appendLog($app, $new);
                ImportDaemon::broadcast($app, 'admin:import');
            }

            ImportDaemon::broadcast($app, 'rrd:live');

            // Rules run once per interval, when every source's file of it is in (D17).
            /** @var null|AlertManager $alertMgr */
            $alertMgr = $app->globalState('alertManager', null);
            if ($alertMgr !== null && !$app->isShuttingDown()) {
                $fired = $alertMgr->onFileImported(Config::$settings->alerts, $profile, $fileTs, $isLastSource, $source);
                if (!empty($fired)) {
                    $app->setGlobalState('alert_fired', ['names' => $fired, 'ts' => time()]);
                    if (!empty($app->getClients())) {
                        $app->broadcast('alerts:fired');
                    }
                }
            }
        };
    }

    /**
     * From onShutdown: ends what boot() started so a stop takes a moment, not max_wait_time.
     * Queries and file walks are cut short; an import finishes the capture file it is on.
     */
    public static function shutdown(Via $app): void {
        $started = microtime(true);
        LoopLag::stop();
        ImportDaemon::resetBroadcasts();
        TopNCollector::stop();
        $alertMgr = $app->globalState('alertManager', null);
        if ($alertMgr instanceof AlertManager) {
            $alertMgr->stop();
        }
        $daemons = $app->globalState('daemons', []);
        foreach (\is_array($daemons) ? $daemons : [] as $daemon) {
            if ($daemon instanceof ImportDaemon) {
                $daemon->stop();
            }
        }

        NfdumpSlots::close();
        NfcapdFiles::stop();
        $killed = 0;
        foreach (NfdumpSlots::running() as $handle => $pids) {
            if ($handle !== NfdumpSlots::SHARED_HANDLE) {
                // A filtered graph checks the flag between bins; the kill ends the bin in flight.
                QueryCancel::request($handle);
                NfdumpSlots::kill($handle);
                $killed += \count($pids);
            }
        }

        $left = self::awaitCoroutines(self::SHUTDOWN_WAIT_SECONDS);
        $ms = (int) round((microtime(true) - $started) * 1000);
        // php-via's log reaches stdout; Debug stops echoing once an import has run.
        $app->log(
            $left === 0 ? 'info' : 'warn',
            "nfsen-ng shutdown: {$killed} nfdump process(es) stopped, " . ($left === 0
                ? "background work ended in {$ms} ms"
                : "{$left} coroutine(s) still running after {$ms} ms: " . implode('; ', self::parkedAt())),
        );
    }

    /** @return list<string> where each other coroutine waits, as `function (file:line)` */
    private static function parkedAt(): array {
        $at = [];
        foreach (Coroutine::list() as $cid) {
            if ($cid === Coroutine::getCid()) {
                continue;
            }
            $frames = Coroutine::getBackTrace($cid, DEBUG_BACKTRACE_IGNORE_ARGS, 4) ?: [];
            $at[] = implode(' < ', array_map(
                static fn (array $f): string => ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '?') . ' (' . basename($f['file'] ?? '?') . ':' . ($f['line'] ?? 0) . ')',
                $frames,
            ));
        }

        return $at;
    }

    /** Waits until this is the worker's last coroutine or $seconds pass; returns how many others are left. */
    private static function awaitCoroutines(float $seconds): int {
        if (Coroutine::getCid() <= 0) {
            return 0;
        }

        $deadline = microtime(true) + $seconds;
        while (($others = (int) (Coroutine::stats()['coroutine_num'] ?? 1) - 1) > 0 && microtime(true) < $deadline) {
            Coroutine::usleep(10_000);
        }

        return max(0, $others);
    }
}
