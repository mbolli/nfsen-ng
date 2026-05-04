<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportDaemon;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Import-related action registrations: trigger-import, force-rescan, cancel-import.
 */
final class ImportActions {
    /** Register trigger-import, force-rescan, and cancel-import actions. */
    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c) use ($app): void {
            $adminTargetProfile = $c->getSignal('admin_target_profile');
            $importRunning = $c->getSignal('import_running');
            $importScanPorts = $c->getSignal('import_scan_ports');
            \assert($adminTargetProfile !== null && $importRunning !== null && $importScanPorts !== null);

            /** @var array<string, ImportDaemon> $daemons */
            $daemons = $app->globalState('daemons', []);
            $targetProfile = $adminTargetProfile->string();
            $targetDaemon = $daemons[$targetProfile] ?? null;

            if ($targetDaemon === null || $targetDaemon->isLocked()) {
                return;
            }

            $targetDaemon->lock();
            $importRunning->setValue(true, broadcast: false);
            $app->setGlobalState('import_active_profile', $targetProfile);
            $app->setGlobalState('import_progress', 0);
            $app->setGlobalState('import_current_file', '');
            $app->setGlobalState('import_status_text', 'Counting files…');
            $app->setGlobalState('import_eta', '');
            $app->setGlobalState('import_log', []);
            Debug::drainBuffer();
            $c->sync();
            if (!empty($app->getClients())) {
                $app->broadcast('admin:import');
            }

            $scanPorts = $importScanPorts->bool();
            Coroutine::create(function () use ($app, $targetDaemon, $targetProfile, $scanPorts): void {
                $app->setGlobalState('import_cancel', false);

                $flushLog = static function () use ($app): void {
                    $new = Debug::drainBuffer();
                    if ($new !== []) {
                        $log = $app->globalState('import_log', []);
                        $app->setGlobalState('import_log', array_merge($log, $new));
                        if (!empty($app->getClients())) {
                            $app->broadcast('admin:import');
                        }
                    }
                };

                try {
                    $importYears = Config::$settings->importYears();
                    $start = (new \DateTime())->modify('-' . $importYears . ' years');

                    $importer = new Import();
                    $importer->setQuiet(true);
                    $importer->setProcessPorts($scanPorts);
                    $importer->setProcessPortsBySource($scanPorts);
                    $importer->setCheckLastUpdate(true);
                    $importer->setProfile($targetProfile);

                    $importer->start(
                        $start,
                        function (array $progress) use ($app, $flushLog): void {
                            $flushLog();
                            $app->setGlobalState('import_progress', $progress['pct']);
                            $app->setGlobalState('import_current_file', $progress['file']);
                            $app->setGlobalState(
                                'import_status_text',
                                'Scanning ' . number_format($progress['processed'])
                                . ' / ' . number_format($progress['total']) . ' files'
                            );
                            $app->setGlobalState('import_eta', $progress['eta']);
                            if (!empty($app->getClients())) {
                                $app->broadcast('admin:import');
                            }
                        },
                        $flushLog,
                        static fn (): bool => (bool) $app->globalState('import_cancel', false)
                    );

                    $cancelled = (bool) $app->globalState('import_cancel', false);
                    $app->setGlobalState('import_status_text', $cancelled ? 'Import cancelled.' : 'Import complete.');
                    $app->setGlobalState('import_current_file', '');
                    $app->setGlobalState('import_eta', '');
                } catch (\Throwable $e) {
                    Debug::getInstance()->log('Import failed: ' . $e->getMessage(), LOG_ERR);
                    $app->setGlobalState('import_status_text', 'Import failed: ' . $e->getMessage());
                } finally {
                    $flushLog();
                    $app->setGlobalState('import_cancel', false);
                    $targetDaemon->unlock();
                    $app->setGlobalState('import_progress', 100);
                    if (!empty($app->getClients())) {
                        $app->broadcast('admin:import');
                        $app->broadcast('rrd:live');
                    }
                }
            });
        }, 'trigger-import');

        $c->action(static function (Context $c) use ($app): void {
            $adminTargetProfile = $c->getSignal('admin_target_profile');
            $importRunning = $c->getSignal('import_running');
            $confirmRescan = $c->getSignal('confirm_rescan');
            $importScanPorts = $c->getSignal('import_scan_ports');
            \assert($adminTargetProfile !== null && $importRunning !== null && $confirmRescan !== null && $importScanPorts !== null);

            /** @var array<string, ImportDaemon> $daemons */
            $daemons = $app->globalState('daemons', []);
            $confirmRescan->setValue(false);

            $targetProfile = $adminTargetProfile->string();
            $targetDaemon = $daemons[$targetProfile] ?? null;

            if ($targetDaemon === null || $targetDaemon->isLocked()) {
                return;
            }

            $targetDaemon->lock();
            $importRunning->setValue(true, broadcast: false);
            $app->setGlobalState('import_active_profile', $targetProfile);
            $app->setGlobalState('import_progress', 0);
            $app->setGlobalState('import_current_file', '');
            $app->setGlobalState('import_status_text', 'Resetting RRD data…');
            $app->setGlobalState('import_eta', '');
            $app->setGlobalState('import_log', []);
            Debug::drainBuffer();
            $c->sync();
            if (!empty($app->getClients())) {
                $app->broadcast('admin:import');
            }

            $scanPorts = $importScanPorts->bool();
            Coroutine::create(function () use ($app, $targetDaemon, $targetProfile, $scanPorts): void {
                $app->setGlobalState('import_cancel', false);

                $flushLog = static function () use ($app): void {
                    $new = Debug::drainBuffer();
                    if ($new !== []) {
                        $log = $app->globalState('import_log', []);
                        $app->setGlobalState('import_log', array_merge($log, $new));
                        if (!empty($app->getClients())) {
                            $app->broadcast('admin:import');
                        }
                    }
                };

                try {
                    $importYears = Config::$settings->importYears();
                    $start = (new \DateTime())->modify('-' . $importYears . ' years');

                    $importer = new Import();
                    $importer->setQuiet(true);
                    $importer->setForce(true);
                    $importer->setProcessPorts($scanPorts);
                    $importer->setProcessPortsBySource($scanPorts);
                    $importer->setProfile($targetProfile);

                    $importer->start(
                        $start,
                        function (array $progress) use ($app, $flushLog): void {
                            $flushLog();
                            $app->setGlobalState('import_progress', $progress['pct']);
                            $app->setGlobalState('import_current_file', $progress['file']);
                            $app->setGlobalState(
                                'import_status_text',
                                'Scanning ' . number_format($progress['processed'])
                                . ' / ' . number_format($progress['total']) . ' files'
                            );
                            $app->setGlobalState('import_eta', $progress['eta']);
                            if (!empty($app->getClients())) {
                                $app->broadcast('admin:import');
                            }
                        },
                        $flushLog,
                        static fn (): bool => (bool) $app->globalState('import_cancel', false)
                    );

                    $cancelled = (bool) $app->globalState('import_cancel', false);
                    $app->setGlobalState('import_status_text', $cancelled ? 'Rescan cancelled.' : 'Rescan complete.');
                    $app->setGlobalState('import_current_file', '');
                    $app->setGlobalState('import_eta', '');
                } catch (\Throwable $e) {
                    Debug::getInstance()->log('Rescan failed: ' . $e->getMessage(), LOG_ERR);
                    $app->setGlobalState('import_status_text', 'Rescan failed: ' . $e->getMessage());
                } finally {
                    $flushLog();
                    $app->setGlobalState('import_cancel', false);
                    $targetDaemon->unlock();
                    $app->setGlobalState('import_progress', 100);
                    if (!empty($app->getClients())) {
                        $app->broadcast('admin:import');
                        $app->broadcast('rrd:live');
                    }
                }
            });
        }, 'force-rescan');

        $c->action(static function (Context $c) use ($app): void {
            $app->setGlobalState('import_cancel', true);
            $app->setGlobalState('import_status_text', 'Cancelling…');
            if (!empty($app->getClients())) {
                $app->broadcast('admin:import');
            }
            $c->sync();
        }, 'cancel-import');
    }
}
