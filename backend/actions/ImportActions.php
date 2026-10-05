<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\pages\HealthPage;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * The Health page's actions: trigger-import, backfill-import, force-rescan, cancel-import,
 * topn-fill and health-refresh.
 */
final class ImportActions {
    /**
     * The three import passes: the status line while one starts, and the word its outcome
     * starts with ("Backfill complete.").
     */
    private const array PASSES = [
        'trigger' => ['starting' => 'Counting files…', 'noun' => 'Import'],
        // Re-reads files older than the newest sample too; offered only where the datasource
        // accepts historic writes, RRD needs the rescan's rebuild instead (#171).
        'backfill' => ['starting' => 'Counting files…', 'noun' => 'Backfill'],
        'rescan' => ['starting' => 'Resetting RRD data…', 'noun' => 'Rescan'],
    ];

    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c) use ($app): void {
            self::start($c, $app, 'trigger');
        }, 'trigger-import');

        $c->action(static function (Context $c) use ($app): void {
            self::start($c, $app, 'backfill');
        }, 'backfill-import');

        $c->action(static function (Context $c) use ($app): void {
            $c->getSignal('confirm_rescan')?->setValue(false);
            self::start($c, $app, 'rescan');
        }, 'force-rescan');

        $c->action(static function (Context $c) use ($app): void {
            // A pass that ended before the click leaves nothing to cancel, and no stale "Cancelling…".
            $daemons = $app->globalState('daemons', []);
            if (!\is_array($daemons) || !array_any($daemons, static fn (mixed $d): bool => $d instanceof ImportDaemon && $d->isLocked())) {
                $c->sync();

                return;
            }
            $app->setGlobalState('import_cancel', true);
            $app->setGlobalState('import_status_text', 'Cancelling…');
            ImportDaemon::broadcast($app, 'admin:import', now: true);
            $c->sync();
        }, 'cancel-import');

        // One newest-first pass over every profile and source with the gap filler's budget.
        $c->action(static function (Context $c) use ($app): void {
            // An unbooted collector queues nothing and would report "Nothing missing".
            if (HealthPage::topnOff($app, Shell::fatal($app)) !== '') {
                $c->sync();

                return;
            }

            try {
                $queued = TopNCollector::fillAllGaps();
                $app->setGlobalState(HealthPage::TOPN_FILL, ['ts' => time(), 'queued' => $queued, 'error' => '']);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('TopN: manual gap fill failed: ' . $e->getMessage(), LOG_WARNING);
                $app->setGlobalState(HealthPage::TOPN_FILL, ['ts' => time(), 'queued' => 0, 'error' => $e->getMessage()]);
            }
            $c->sync();
        }, 'topn-fill');

        // The 10 s tick of an open Health page; the render reads the 30 s cache.
        $c->action(static function (Context $c): void {
            try {
                $c->sync();
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Health refresh failed: ' . $e->getMessage(), LOG_WARNING);
            }
        }, 'health-refresh');
    }

    /** Locks the target profile's daemon and runs one import pass in a coroutine. */
    private static function start(Context $c, Via $app, string $pass): void {
        $adminTargetProfile = $c->getSignal('admin_target_profile');
        $importRunning = $c->getSignal('import_running');
        $importScanPorts = $c->getSignal('import_scan_ports');
        \assert($adminTargetProfile !== null && $importRunning !== null && $importScanPorts !== null);

        /** @var array<string, ImportDaemon> $daemons */
        $daemons = $app->globalState('daemons', []);
        $targetProfile = $adminTargetProfile->string();
        $targetDaemon = $daemons[$targetProfile] ?? null;

        // Nothing starts, but a rescan's confirmation still closes and the running pass shows.
        if ($targetDaemon === null || $targetDaemon->isLocked()) {
            $c->sync();

            return;
        }

        $noun = self::PASSES[$pass]['noun'];
        $targetDaemon->lock();
        $importRunning->setValue(true);
        $app->setGlobalState('import_active_profile', $targetProfile);
        $app->setGlobalState('import_progress', 0);
        $app->setGlobalState('import_current_file', '');
        $app->setGlobalState('import_status_text', self::PASSES[$pass]['starting']);
        $app->setGlobalState('import_eta', '');
        ImportDaemon::clearLog($app);
        $app->setGlobalState(HealthPage::IMPORT_OUTCOME, '');
        Debug::drainBuffer();
        $c->sync();
        ImportDaemon::broadcast($app, 'admin:import', now: true);

        $scanPorts = $importScanPorts->bool();
        Coroutine::create(static function () use ($app, $targetDaemon, $targetProfile, $scanPorts, $pass, $noun): void {
            $app->setGlobalState('import_cancel', false);

            $flushLog = static function () use ($app): void {
                $new = Debug::drainBuffer();
                if ($new !== []) {
                    ImportDaemon::appendLog($app, $new);
                    ImportDaemon::broadcast($app, 'admin:import');
                }
            };

            try {
                $importYears = Config::$settings->importYears();
                $start = (new \DateTime())->modify('-' . $importYears . ' years');

                $importer = new Import();
                $importer->setQuiet(true);
                $importer->setProcessPorts($scanPorts);
                $importer->setProcessPortsBySource($scanPorts);
                if ($pass === 'rescan') {
                    $importer->setForce(true);
                } else {
                    $importer->setCheckLastUpdate(true);
                    $importer->setRescan($pass === 'backfill');
                }
                $importer->setProfile($targetProfile);

                $importer->start(
                    $start,
                    static function (array $progress) use ($app, $flushLog): void {
                        $flushLog();
                        $app->setGlobalState('import_progress', $progress['pct']);
                        $app->setGlobalState('import_current_file', $progress['file']);
                        $app->setGlobalState(
                            'import_status_text',
                            'Scanning ' . number_format($progress['processed'])
                            . ' / ' . number_format($progress['total']) . ' files'
                        );
                        $app->setGlobalState('import_eta', $progress['eta']);
                        ImportDaemon::broadcast($app, 'admin:import');
                    },
                    $flushLog,
                    static fn (): bool => $app->isShuttingDown() || (bool) $app->globalState('import_cancel', false)
                );

                $cancelled = $app->isShuttingDown() || (bool) $app->globalState('import_cancel', false);
                $app->setGlobalState('import_status_text', $noun . ($cancelled ? ' cancelled.' : ' complete.'));
                $app->setGlobalState(HealthPage::IMPORT_OUTCOME, $cancelled ? 'cancelled' : 'complete');
                $app->setGlobalState('import_current_file', '');
                $app->setGlobalState('import_eta', '');
            } catch (\Throwable $e) {
                Debug::getInstance()->log($noun . ' failed: ' . $e->getMessage(), LOG_ERR);
                $app->setGlobalState('import_status_text', $noun . ' failed: ' . $e->getMessage());
                $app->setGlobalState(HealthPage::IMPORT_OUTCOME, 'failed');
            } finally {
                $flushLog();
                $app->setGlobalState('import_cancel', false);
                $targetDaemon->unlock();
                $app->setGlobalState('import_progress', 100);
                // The checks and sources table describe the data before this pass.
                HealthPage::invalidate($app);
                ImportDaemon::broadcast($app, 'admin:import', now: true);
                ImportDaemon::broadcast($app, 'rrd:live', now: true);
            }
        });
    }
}
