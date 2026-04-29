#!/usr/bin/env php
<?php

/**
 * HTTP Server for nfsen-ng — built on mbolli/php-via (OpenSwoole).
 *
 * Architecture: single page + single SSE connection per tab (CQRS / immediate mode).
 *
 *  Browser  ──GET /──────────────────► page handler  (create signals, actions, view)
 *  Browser  ──GET /_sse─────────────► SseHandler     (one persistent SSE per tab)
 *  Browser  ──POST /_action/:id─────► ActionHandler  (inject signals → run handler → sync)
 *                                          │
 *                                     $c->sync()     (re-render full page → push via SSE)
 *
 * RRD import broadcasts hit ALL tabs subscribed to the 'rrd:live' scope and
 * trigger the same view re-render, so the graph updates automatically without
 * any extra streaming infrastructure.
 *
 * The import daemon (formerly listen.php) runs as a startup coroutine +
 * $app->setInterval() poll — SWOOLE_HOOK_ALL makes sleep() non-blocking.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\common\UserPreferences;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

// ─── Via configuration ───────────────────────────────────────────────────────

$isDev = (bool) (getenv('NFSEN_DEV_MODE') ?: false);
$workerNum = (int) (getenv('SWOOLE_WORKER_NUM') ?: 1);  // php-via is single-worker
$maxRequest = (int) (getenv('SWOOLE_MAX_REQUEST') ?: 0);    // 0 = unlimited (default for long-lived SSE servers)
$maxCoroutine = (int) (getenv('SWOOLE_MAX_COROUTINE') ?: 10000);
$logLevel = strtolower((string) (getenv('NFSEN_LOG_LEVEL') ?: 'info'));
$logLevel = preg_replace('/^log_/i', '', $logLevel) ?: 'info';

$viaConfig = (new ViaConfig())
    ->withHost('0.0.0.0')
    ->withPort(9000)
    ->withDevMode($isDev)
    ->withTemplateDir(__DIR__ . '/templates')
    ->withLogLevel($logLevel)
    ->withH2c()
    ->withBrotli()
    ->withTrustProxy(true)
    ->withSwooleSettings([
        'worker_num' => $workerNum,
        'max_request' => $maxRequest,
        'max_coroutine' => $maxCoroutine,
        'hook_flags' => SWOOLE_HOOK_ALL,
        'max_conn' => 10000,
        'send_yield' => true,
        'log_file' => '/tmp/swoole.log',
        'reload_async' => true,
        'max_wait_time' => 3,
    ])
;

$app = new Via($viaConfig);

// ─── Startup: config + import daemon ─────────────────────────────────────────

$app->onStart(function () use ($app): void {
    try {
        Config::initialize(true);
    } catch (Throwable $e) {
        $app->setGlobalState('_fatalError', $e->getMessage());
        error_log('[nfsen-ng] FATAL: ' . $e->getMessage());

        return;
    }

    $debug = Debug::getInstance();
    $debug->log('nfsen-ng started (php-via)', LOG_INFO);

    if (getenv('NFSEN_SKIP_DAEMON') === '1' || getenv('NFSEN_SKIP_DAEMON') === 'true') {
        $debug->log('ImportDaemon skipped (NFSEN_SKIP_DAEMON)', LOG_INFO);
        $app->setGlobalState('daemon_disabled', true);

        return;
    }

    $daemon = new ImportDaemon();
    $app->setGlobalState('daemon', $daemon);

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
    //
    // NOTE: Import::start() is not coroutine-safe per the original comment in
    // listen.php. If problems arise, move this call before $app->start() so it
    // runs synchronously before the server accepts connections.
    Coroutine::create(function () use ($app, $daemon, $debug): void {
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
            $daemon->setupWatchesOnly();

            return;
        }

        // Detect whether any source already has data in the database.
        $hasExistingData = false;
        foreach (Config::$settings->sources as $source) {
            if (Config::$db->last_update($source) > 0) {
                $hasExistingData = true;

                break;
            }
        }

        if (!$hasExistingData) {
            // Fresh install — no data yet. Skip the import; user triggers it manually.
            $debug->log('ImportDaemon: no existing data — startup import skipped; use Admin panel to run Initial Import', LOG_INFO);
            $daemon->setupWatchesOnly();

            return;
        }

        // Existing database — run a gap fill to catch up on any missed files.
        $app->setGlobalState('import_status_text', 'Catching up on missed files…');
        if (!empty($app->getClients())) {
            $app->broadcast('admin:import');
        }

        try {
            $daemon->initialImport(
                function (array $progress) use ($app, $flushLog): void {
                    $app->setGlobalState('import_progress', $progress['pct']);
                    $app->setGlobalState('import_current_file', $progress['file']);
                    $app->setGlobalState('import_status_text', 'Catching up: ' . $progress['processed'] . ' / ' . $progress['total'] . ' files');
                    $app->setGlobalState('import_eta', $progress['eta']);
                    $flushLog();
                    if (!empty($app->getClients())) {
                        $app->broadcast('admin:import');
                    }
                },
                static fn (): bool => (bool) $app->globalState('import_cancel', false)
            );
            $flushLog();
            $app->setGlobalState('import_status_text', 'Up to date');
            $app->setGlobalState('import_progress', 100);
            $app->setGlobalState('import_current_file', '');
            $app->setGlobalState('import_eta', '');
        } catch (Throwable $e) {
            $debug->log('ImportDaemon: catch-up import failed: ' . $e->getMessage(), LOG_ERR);
            $app->setGlobalState('import_status_text', 'Catch-up failed: ' . $e->getMessage());
        }

        if (!empty($app->getClients())) {
            $app->broadcast('admin:import');
        }
    });

    // Ongoing inotify poll every 1 s — setInterval runs in the event loop, not
    // in a child coroutine, which is safe per Import class restrictions.
    $app->setInterval(function () use ($app, $daemon, $debug): void {
        try {
            $daemon->pollOnce(function () use ($app, $debug): void {
                $debug->log('ImportDaemon: file imported → broadcasting rrd:live', LOG_DEBUG);

                // Surface any RRD write warnings from this inotify-triggered import
                $new = Debug::drainBuffer();
                if ($new !== []) {
                    $log = $app->globalState('import_log', []);
                    $merged = array_merge($log, $new);
                    if (count($merged) > 100) {
                        $merged = array_slice($merged, -100);
                    }
                    $app->setGlobalState('import_log', $merged);
                    if (!empty($app->getClients())) {
                        $app->broadcast('admin:import');
                    }
                }

                if (!empty($app->getClients())) {
                    $app->broadcast('rrd:live');
                }
            });
        } catch (Throwable $e) {
            $debug->log('ImportDaemon: poll error: ' . $e->getMessage(), LOG_ERR);
        }
    }, 1000);
});

// ─── Single-page application ──────────────────────────────────────────────────
//
// Everything lives on '/'. All former /api/* routes become $c->action() calls.
// The browser registers one SSE connection. Every action calls $c->sync() which
// re-renders the full page and pushes it over the SSE channel. Brotli + DOM
// morphing keeps the payload and diff cost low.
//
// Signal naming mirrors the existing Datastar store keys used in the frontend
// templates so Phase-4 template migration can happen incrementally.

$app->page('/', function (Context $c) use ($app): void {
    $debug = Debug::getInstance();
    $fatalError = $app->globalState('_fatalError', null);

    // ── Signals ─────────────────────────────────────────────────────────────
    // clientWritable: true  → browser may update this signal via data-bind / data-on
    // clientWritable: false → server-owned; browser reads but never writes

    // _currentView is a browser-local signal (underscore prefix = never posted to server).
    // It is initialised client-side via data-signals in the template; no server registration.
    $datestart = $c->signal(time() - 86400, 'datestart', clientWritable: true);
    $dateend = $c->signal(time(), 'dateend', clientWritable: true);
    // Actual data range — derived from RRD first/last timestamps, updated on every render.
    // Server-owned (never client-writable), used to bound the date-range slider.
    $dataRangeMin = $c->signal(time() - Config::$settings->importYears * 365 * 86400, 'data_range_min');
    $dataRangeMax = $c->signal(time(), 'data_range_max');
    $error = $c->signal($fatalError, '_error');

    // Graph filter signals (client-writable — filter UI updates these)
    $graphDisplay = $c->signal(
        Config::$settings->defaultGraphDisplay,
        'graph_display',
        clientWritable: true
    );
    $graphSources = $c->signal(Config::$settings->sources, 'graph_sources', clientWritable: true);
    $graphPorts = $c->signal(Config::$settings->ports, 'graph_ports', clientWritable: true);
    $graphProtocols = $c->signal(
        Config::$settings->defaultGraphProtocols,
        'graph_protocols',
        clientWritable: true
    );
    $graphDatatype = $c->signal(
        Config::$settings->defaultGraphDatatype,
        'graph_datatype',
        clientWritable: true
    );
    $graphTrafficUnit = $c->signal('bits', 'graph_trafficUnit', clientWritable: true);
    $graphResolution = $c->signal(500, 'graph_resolution', clientWritable: true);
    // Server-side graph metadata (read-only for browser)
    $graphIsLive = $c->signal(false, 'graph_isLive');
    $graphActualRes = $c->signal(0, 'graph_actualResolution');
    $graphLastUpdate = $c->signal(0, 'graph_lastUpdate');

    // Flow signals
    $flowFilter = $c->signal('', 'flows_filter', clientWritable: true);
    $flowLimit = $c->signal(
        Config::$settings->defaultFlowLimit,
        'flows_limit',
        clientWritable: true
    );
    $flowAggBidirectional = $c->signal(false, 'flows_agg_bidirectional', clientWritable: true);
    $flowAggProto = $c->signal(false, 'flows_agg_proto', clientWritable: true);
    $flowAggSrcPort = $c->signal(false, 'flows_agg_srcport', clientWritable: true);
    $flowAggDstPort = $c->signal(false, 'flows_agg_dstport', clientWritable: true);
    $flowAggSrcIp = $c->signal('none', 'flows_agg_srcip', clientWritable: true);
    $flowAggSrcIpPrefix = $c->signal('', 'flows_agg_srcip_prefix', clientWritable: true);
    $flowAggDstIp = $c->signal('none', 'flows_agg_dstip', clientWritable: true);
    $flowAggDstIpPrefix = $c->signal('', 'flows_agg_dstip_prefix', clientWritable: true);
    $flowOrderByTstart = $c->signal(false, 'flows_orderByTstart', clientWritable: true);
    // Byte thresholds for flows — prepended as filter expressions (bytes > / bytes <)
    $flowLowerLimit = $c->signal('', 'flows_lower_limit', clientWritable: true);
    $flowUpperLimit = $c->signal('', 'flows_upper_limit', clientWritable: true);
    $flowCount = $c->signal(0, 'flows_count');
    $flowMessage = $c->signal('', 'flowMessage');

    // Stats signals
    $statsFilter = $c->signal('', 'stats_filter', clientWritable: true);
    $statsCount = $c->signal(10, 'stats_count', clientWritable: true);
    $statsFor = $c->signal('record', 'stats_for', clientWritable: true);
    $statsOrderBy = $c->signal(
        Config::$settings->defaultStatsOrderBy,
        'stats_orderBy',
        clientWritable: true
    );
    // Byte thresholds applied as nfdump filter expressions prepended to $statsFilter.
    // nfdump -l/-L flags are only valid for line/packed output, not for -s statistics mode.
    $statsLowerLimit = $c->signal('', 'stats_lower_limit', clientWritable: true);
    $statsUpperLimit = $c->signal('', 'stats_upper_limit', clientWritable: true);
    $statsMessage = $c->signal('', 'statsMessage');
    // nfcapd file count — updated by count-files action and on initial render
    $nfcapdFileCount = $c->signal(0, 'nfcapd_file_count');

    // Admin / import signals
    /** @var null|ImportDaemon $daemon */
    $daemon = $app->globalState('daemon', null);
    $importRunning = $c->signal($daemon !== null && $daemon->isLocked(), 'import_running');
    // Client-writable: browser toggles confirm panel without a round-trip
    $confirmRescan = $c->signal(false, 'confirm_rescan', clientWritable: true);
    // Whether ports should be scanned during import (combined and per-source)
    $importScanPorts = $c->signal(true, 'import_scan_ports', clientWritable: true);

    // Settings signals — user preferences editable in the Settings tab
    $settingsDefaultView = $c->signal(Config::$settings->defaultView, 'settings_defaultView', clientWritable: true);
    $settingsGraphDisplay = $c->signal(Config::$settings->defaultGraphDisplay, 'settings_graphDisplay', clientWritable: true);
    $settingsGraphDatatype = $c->signal(Config::$settings->defaultGraphDatatype, 'settings_graphDatatype', clientWritable: true);
    $settingsGraphProtocols = $c->signal(Config::$settings->defaultGraphProtocols, 'settings_graphProtocols', clientWritable: true);
    $settingsFlowLimit = $c->signal(Config::$settings->defaultFlowLimit, 'settings_flowLimit', clientWritable: true);
    $settingsStatsOrderBy = $c->signal(Config::$settings->defaultStatsOrderBy, 'settings_statsOrderBy', clientWritable: true);
    // Filters stored as newline-separated text (easier for a textarea; parsed on save)
    $settingsFiltersText = $c->signal(
        implode("\n", Config::$settings->filters),
        'settings_filtersText',
        clientWritable: true
    );
    $settingsLogPriority = $c->signal(
        Settings::logLevelToString(Config::$settings->logPriority),
        'settings_logPriority',
        clientWritable: true
    );
    $settingsMessage = $c->signal('', 'settings_message');

    // ── State containers (plain PHP — NOT signals, not sent to browser) ──────
    // These carry large result sets between the action and the view closure.
    $flowTableHtml = '';
    $statsTableHtml = '';

    // ── Subscribe to import and RRD broadcasts ──────────────────────────────
    // admin:import — live progress pushed from the import coroutine to all admin tabs
    // rrd:live     — graph refresh after any import completes
    // settings:saved — preferences updated; all tabs re-render with new defaults
    $c->addScope('admin:import');
    $c->addScope('rrd:live');
    $c->addScope('settings:saved');

    // ── Helper: fetch graph data from datasource ─────────────────────────────
    $fetchGraphData = function () use (
        $datestart,
        $dateend,
        $graphDisplay,
        $graphSources,
        $graphPorts,
        $graphProtocols,
        $graphDatatype,
        $graphTrafficUnit,
        $graphResolution,
        $graphIsLive,
        $graphActualRes,
        $graphLastUpdate,
        $error
    ): array {
        $ds = $datestart->int();
        $de = $dateend->int();

        $isLive = (time() - $de) < 300;
        $graphIsLive->setValue($isLive, broadcast: false);

        $dt = $graphDatatype->string();
        $unit = ($dt !== 'traffic') ? $dt : $graphTrafficUnit->string();

        try {
            $data = Config::$db->get_graph_data(
                $ds,
                $de,
                $graphSources->array(),
                $graphProtocols->array(),
                $graphPorts->array(),
                $unit,
                $graphDisplay->string(),
                $graphResolution->int()
            );
        } catch (Throwable $e) {
            $error->setValue('Graph error: ' . $e->getMessage(), broadcast: false);

            return [];
        }

        $pointCount = count($data[array_key_first($data) ?? ''] ?? $data);
        $graphActualRes->setValue($pointCount, broadcast: false);

        // Use the actual RRD last-write time rather than wall-clock "now"
        $activeSources = $graphSources->array() ?: Config::$settings->sources;
        $lastWrite = empty($activeSources) ? 0 : max(array_map(
            fn ($s) => Config::$db->last_update($s),
            $activeSources
        ));
        $graphLastUpdate->setValue($lastWrite > 0 ? $lastWrite : time(), broadcast: false);

        return $data;
    };

    // ── Helper: count actual nfcapd files in a date range for given sources ─────
    // Scans the filesystem path structure: profiles-data/profile/source/YYYY/MM/DD/
    $countNfcapdFiles = static function (int $ds, int $de, array $sources): int {
        $sourcePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . Config::$settings->nfdumpProfile;
        $count = 0;

        foreach ($sources as $source) {
            $cur = (new DateTime())->setTimestamp($ds);
            $end = (new DateTime())->setTimestamp($de);

            while ($cur->format('Ymd') <= $end->format('Ymd')) {
                $dayPath = $sourcePath
                    . \DIRECTORY_SEPARATOR . (string) $source
                    . \DIRECTORY_SEPARATOR . $cur->format('Y')
                    . \DIRECTORY_SEPARATOR . $cur->format('m')
                    . \DIRECTORY_SEPARATOR . $cur->format('d');
                $cur->modify('+1 day');

                if (!is_dir($dayPath)) {
                    continue;
                }

                foreach (scandir($dayPath) ?: [] as $file) {
                    if (!preg_match('/^nfcapd\.(\d{12})$/', (string) $file, $m)) {
                        continue;
                    }

                    try {
                        $ft = (new DateTime($m[1]))->getTimestamp();
                    } catch (Exception) {
                        continue;
                    }
                    if ($ft >= $ds && $ft <= $de) {
                        ++$count;
                    }
                }
            }
        }

        return $count;
    };

    // ── Helper: update $dataRangeMin / $dataRangeMax from actual RRD boundaries ──
    $updateDataRange = static function () use ($dataRangeMin, $dataRangeMax): void {
        $sources = Config::$settings->sources;
        if (empty($sources)) {
            return;
        }

        $fallbackMin = time() - Config::$settings->importYears * 365 * 86400;
        $firsts = [];
        $lasts = [];

        foreach ($sources as $source) {
            try {
                [$first, $last] = Config::$db->date_boundaries($source);
                if ($first > 0) {
                    $firsts[] = $first;
                }
                if ($last > 0) {
                    $lasts[] = $last;
                }
            } catch (Throwable) {
                // RRD may not exist yet — skip
            }
        }

        $dataRangeMin->setValue(empty($firsts) ? $fallbackMin : min($firsts), broadcast: false);
        $dataRangeMax->setValue(empty($lasts) ? time() : max($lasts), broadcast: false);
    };

    // ── Helper: build an nfsen-toast HTML snippet ────────────────────────────
    $makeToast = static fn (string $type, string $message, bool $autoDismiss = false): string => sprintf(
        '<nfsen-toast data-type="%s" data-message="%s"%s></nfsen-toast>',
        htmlspecialchars($type, ENT_QUOTES),
        htmlspecialchars($message, ENT_QUOTES),
        $autoDismiss ? ' data-auto-dismiss="true"' : ''
    );

    // ── Actions ──────────────────────────────────────────────────────────────

    // Refresh graphs (called by the filter UI after any filter change)
    $refreshGraphsAction = $c->action(function (Context $c) use ($fetchGraphData, $datestart, $dateend): void {
        // If the end date is within the live window (< 10 min old), slide the range
        // forward to keep the "LIVE" badge active across repeated 15-second refreshes.
        $now = time();
        $de = $dateend->int();
        if ($now - $de < 600) {
            $window = $de - $datestart->int();   // preserve the user's chosen window width
            $dateend->setValue($now, broadcast: false);
            $datestart->setValue($now - $window, broadcast: false);
        }

        $fetchGraphData(); // signals updated in-place; view closure will read them
        $c->sync();
    }, 'refresh-graphs');

    // Flow actions — run nfdump, render result table into $flowTableHtml
    $flowAction = $c->action(function (Context $c) use (
        $debug,
        $makeToast,
        $datestart,
        $dateend,
        $flowFilter,
        $flowLowerLimit,
        $flowUpperLimit,
        $flowLimit,
        $flowAggBidirectional,
        $flowAggProto,
        $flowAggSrcPort,
        $flowAggDstPort,
        $flowAggSrcIp,
        $flowAggSrcIpPrefix,
        $flowAggDstIp,
        $flowAggDstIpPrefix,
        $flowOrderByTstart,
        $flowCount,
        $flowMessage,
        &$flowTableHtml,
        &$ipInfoAction
    ): void {
        $time = microtime(true);

        // Build aggregation string from individual signals
        $aggregate = '';
        if ($flowAggBidirectional->bool()) {
            $aggregate = 'bidirectional';
        } else {
            $parts = [];
            if ($flowAggProto->bool()) {
                $parts[] = 'proto';
            }
            if ($flowAggSrcPort->bool()) {
                $parts[] = 'srcport';
            }
            if ($flowAggDstPort->bool()) {
                $parts[] = 'dstport';
            }
            $srcIp = $flowAggSrcIp->string();
            if ($srcIp !== 'none' && $srcIp !== '') {
                if (in_array($srcIp, ['srcip4', 'srcip6'], true) && ($p = trim($flowAggSrcIpPrefix->string())) !== '') {
                    $parts[] = $srcIp . '/' . $p;
                } else {
                    $parts[] = $srcIp;
                }
            }
            $dstIp = $flowAggDstIp->string();
            if ($dstIp !== 'none' && $dstIp !== '') {
                if (in_array($dstIp, ['dstip4', 'dstip6'], true) && ($p = trim($flowAggDstIpPrefix->string())) !== '') {
                    $parts[] = $dstIp . '/' . $p;
                } else {
                    $parts[] = $dstIp;
                }
            }
            $aggregate = implode(',', $parts);
        }

        try {
            $sources = implode(':', Config::$settings->sources);
            $processor = new Config::$processorClass();
            $processor->setOption('-M', $sources);
            $processor->setOption('-R', [$datestart->int(), $dateend->int()]);
            $processor->setOption('-c', $flowLimit->int());
            $processor->setOption('-o', 'json');
            if ($flowOrderByTstart->bool()) {
                $processor->setOption('-O', 'tstart');
            }
            if (!empty($aggregate)) {
                $processor->setOption(
                    $aggregate === 'bidirectional' ? '-B' : '-a',
                    $aggregate === 'bidirectional' ? '' : '-A' . $aggregate
                );
            }
            $thresholdFilter = '';
            if (($fll = trim($flowLowerLimit->string())) !== '' && preg_match('/^\d+[kMG]?$/i', $fll)) {
                $thresholdFilter .= 'bytes > ' . $fll;
            }
            if (($ful = trim($flowUpperLimit->string())) !== '' && preg_match('/^\d+[kMG]?$/i', $ful)) {
                $thresholdFilter .= ($thresholdFilter !== '' ? ' and ' : '') . 'bytes < ' . $ful;
            }
            $combinedFlowFilter = trim($flowFilter->string());
            if ($thresholdFilter !== '') {
                $combinedFlowFilter = $thresholdFilter . ($combinedFlowFilter !== '' ? ' and ' . $combinedFlowFilter : '');
            }
            $processor->setFilter($combinedFlowFilter);
            $result = $processor->execute();

            $flowData = $result['decoded'] ?? [];
            if (!is_array($flowData)) {
                throw new RuntimeException('Invalid data from nfdump processor');
            }

            $flowCount->setValue(count($flowData), broadcast: false);
            $elapsed = round(microtime(true) - $time, 3);
            $cmd = $result['command'] ?? '';
            $msg = $cmd
                ? $makeToast('success', "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)")
                : $makeToast('success', "Flows processed in {$elapsed}s.", autoDismiss: true);

            if (!empty($result['stderr'])) {
                $msg .= $makeToast('warning', '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr'], ENT_QUOTES));
            }
            $flowMessage->setValue($msg, broadcast: false);

            $flowTableHtml = Table::generate($flowData, 'flowTable', [
                'hiddenFields' => [],
                'linkIpAddresses' => true,
                // @phpstan-ignore notIdentical.alwaysFalse ($ipInfoAction is set by reference later in the same page closure)
                'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                'originalData' => $result['rawOutput'] ?? null,
            ]);
        } catch (Throwable $e) {
            $debug->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
            $flowMessage->setValue($makeToast('error', 'Error: ' . $e->getMessage()), broadcast: false);
            $flowTableHtml = '';
        }

        $c->sync();
    }, 'flow-actions');

    // Stats actions
    $statsAction = $c->action(function (Context $c) use (
        $debug,
        $makeToast,
        $datestart,
        $dateend,
        $statsFilter,
        $statsCount,
        $statsFor,
        $statsOrderBy,
        $statsLowerLimit,
        $statsUpperLimit,
        $graphSources,
        $statsMessage,
        &$statsTableHtml,
        &$ipInfoAction
    ): void {
        $time = microtime(true);
        $forParam = $statsFor->string() . '/' . $statsOrderBy->string();

        try {
            $srcs = $graphSources->array() ?: Config::$settings->sources;
            $processor = new Config::$processorClass();
            $processor->setOption('-M', implode(':', $srcs));
            $processor->setOption('-R', [$datestart->int(), $dateend->int()]);
            $processor->setOption('-n', $statsCount->int());
            $processor->setOption('-o', 'json');
            $processor->setOption('-s', $forParam);
            // Byte thresholds are prepended to the filter expression.
            // nfdump -l/-L only work for line/packed output, not for -s stats mode.
            // setFilter() wraps the whole expression in escapeshellarg(), so values must NOT be pre-quoted.
            $thresholdFilter = '';
            if (($ll = trim($statsLowerLimit->string())) !== '' && preg_match('/^\d+[kMG]?$/i', $ll)) {
                $thresholdFilter .= 'bytes > ' . $ll;
            }
            if (($ul = trim($statsUpperLimit->string())) !== '' && preg_match('/^\d+[kMG]?$/i', $ul)) {
                $thresholdFilter .= ($thresholdFilter !== '' ? ' and ' : '') . 'bytes < ' . $ul;
            }
            $combinedFilter = trim($statsFilter->string());
            if ($thresholdFilter !== '') {
                $combinedFilter = $thresholdFilter . ($combinedFilter !== '' ? ' and ' . $combinedFilter : '');
            }
            $processor->setFilter($combinedFilter);
            $result = $processor->execute();

            $statsData = $result['decoded'] ?? [];
            if (!is_array($statsData)) {
                throw new RuntimeException('Invalid data from nfdump processor');
            }

            $elapsed = round(microtime(true) - $time, 3);
            $cmd = $result['command'] ?? '';
            $msg = $cmd
                ? $makeToast('success', "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)")
                : $makeToast('success', "Statistics processed in {$elapsed}s.", autoDismiss: true);

            if (!empty($result['stderr'])) {
                $msg .= $makeToast('warning', '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr'], ENT_QUOTES));
            }
            $statsMessage->setValue($msg, broadcast: false);

            $statsTableHtml = Table::generate($statsData, 'statsTable', [
                'hiddenFields' => [],
                'linkIpAddresses' => true,
                // @phpstan-ignore notIdentical.alwaysFalse ($ipInfoAction is set by reference later in the same page closure)
                'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                'originalData' => $result['rawOutput'] ?? null,
            ]);
        } catch (Throwable $e) {
            $debug->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
            $statsMessage->setValue($makeToast('error', 'Error: ' . $e->getMessage()), broadcast: false);
            $statsTableHtml = '';
        }

        $c->sync();
    }, 'stats-actions');

    // Count nfcapd files — lightweight scan triggered by date/source filter changes
    // in the Flows and Statistics tabs. Only updates $nfcapdFileCount; no heavy work.
    $countFilesAction = $c->action(function (Context $c) use (
        $datestart,
        $dateend,
        $graphSources,
        $nfcapdFileCount,
        $countNfcapdFiles
    ): void {
        $srcs = $graphSources->array();
        if (in_array('any', $srcs, true) || empty($srcs)) {
            $srcs = Config::$settings->sources;
        }
        $nfcapdFileCount->setValue(
            $countNfcapdFiles($datestart->int(), $dateend->int(), $srcs),
            broadcast: false
        );
        $c->sync();
    }, 'count-files');

    // Trigger import — runs a catch-up Import::start() in a coroutine.
    // Progress is stored in global state and broadcast to the admin:import scope so
    // ALL admin tabs see live updates. The coroutine never calls $c->sync(), so a
    // dropped SSE connection on the triggering tab cannot abort the import.
    $triggerImportAction = $c->action(function (Context $c) use (
        $app,
        $debug,
        $daemon,
        $importRunning,
        $importScanPorts
    ): void {
        if ($daemon === null || $daemon->isLocked()) {
            return;
        }

        $daemon->lock();
        $importRunning->setValue(true, broadcast: false);
        $app->setGlobalState('import_progress', 0);
        $app->setGlobalState('import_current_file', '');
        $app->setGlobalState('import_status_text', 'Counting files…');
        $app->setGlobalState('import_eta', '');
        $app->setGlobalState('import_log', []);
        Debug::drainBuffer(); // clear any stale entries from before this run
        $c->sync();
        if (!empty($app->getClients())) {
            $app->broadcast('admin:import');
        }

        $scanPorts = $importScanPorts->bool();
        Coroutine::create(function () use ($app, $debug, $daemon, $scanPorts): void {
            $app->setGlobalState('import_cancel', false);

            /** Append new Debug WARNING+ entries to the global log state. */
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
                $start = (new DateTime())->modify('-' . $importYears . ' years');

                $importer = new Import();
                $importer->setQuiet(true);
                $importer->setProcessPorts($scanPorts);
                $importer->setProcessPortsBySource($scanPorts);
                $importer->setCheckLastUpdate(true);

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
            } catch (Throwable $e) {
                $debug->log('Import failed: ' . $e->getMessage(), LOG_ERR);
                $app->setGlobalState('import_status_text', 'Import failed: ' . $e->getMessage());
            } finally {
                $flushLog();
                $app->setGlobalState('import_cancel', false);
                $daemon->unlock();
                $app->setGlobalState('import_progress', 100);
                if (!empty($app->getClients())) {
                    $app->broadcast('admin:import');
                    $app->broadcast('rrd:live');
                }
            }
        });
    }, 'trigger-import');

    // Force rescan — same as triggerImport but resets all datasource data first.
    $forceRescanAction = $c->action(function (Context $c) use (
        $app,
        $debug,
        $daemon,
        $importRunning,
        $confirmRescan,
        $importScanPorts
    ): void {
        $confirmRescan->setValue(false);

        if ($daemon === null || $daemon->isLocked()) {
            return;
        }

        $daemon->lock();
        $importRunning->setValue(true, broadcast: false);
        $app->setGlobalState('import_progress', 0);
        $app->setGlobalState('import_current_file', '');
        $app->setGlobalState('import_status_text', 'Resetting RRD data…');
        $app->setGlobalState('import_eta', '');
        $app->setGlobalState('import_log', []);
        Debug::drainBuffer(); // clear any stale entries from before this run
        $c->sync();
        if (!empty($app->getClients())) {
            $app->broadcast('admin:import');
        }

        $scanPorts = $importScanPorts->bool();
        Coroutine::create(function () use ($app, $debug, $daemon, $scanPorts): void {
            $app->setGlobalState('import_cancel', false);

            /** Append new Debug WARNING+ entries to the global log state. */
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
                $start = (new DateTime())->modify('-' . $importYears . ' years');

                $importer = new Import();
                $importer->setQuiet(true);
                $importer->setForce(true);
                $importer->setProcessPorts($scanPorts);
                $importer->setProcessPortsBySource($scanPorts);

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
            } catch (Throwable $e) {
                $debug->log('Rescan failed: ' . $e->getMessage(), LOG_ERR);
                $app->setGlobalState('import_status_text', 'Rescan failed: ' . $e->getMessage());
            } finally {
                $flushLog();
                $app->setGlobalState('import_cancel', false);
                $daemon->unlock();
                $app->setGlobalState('import_progress', 100);
                if (!empty($app->getClients())) {
                    $app->broadcast('admin:import');
                    $app->broadcast('rrd:live');
                }
            }
        });
    }, 'force-rescan');

    // Cancel import — sets the global cancel flag; the running import coroutine will
    // detect it before the next file and exit cleanly.
    $cancelImportAction = $c->action(function (Context $c) use ($app): void {
        $app->setGlobalState('import_cancel', true);
        $app->setGlobalState('import_status_text', 'Cancelling…');
        if (!empty($app->getClients())) {
            $app->broadcast('admin:import');
        }
        $c->sync();
    }, 'cancel-import');

    // Save user preferences — persists to preferences.json and reloads defaults on all tabs.
    $saveSettingsAction = $c->action(function (Context $c) use (
        $app,
        $makeToast,
        $settingsDefaultView,
        $settingsGraphDisplay,
        $settingsGraphDatatype,
        $settingsGraphProtocols,
        $settingsFlowLimit,
        $settingsStatsOrderBy,
        $settingsFiltersText,
        $settingsLogPriority,
        $settingsMessage
    ): void {
        // Parse filters: one per line, trim, drop blank lines
        $rawFilters = array_values(array_filter(
            array_map('trim', explode("\n", $settingsFiltersText->string()))
        ));

        try {
            $prefs = UserPreferences::fromArray([
                'defaultView' => $settingsDefaultView->string(),
                'defaultGraphDisplay' => $settingsGraphDisplay->string(),
                'defaultGraphDatatype' => $settingsGraphDatatype->string(),
                'defaultGraphProtocols' => $settingsGraphProtocols->array(),
                'defaultFlowLimit' => $settingsFlowLimit->int(),
                'defaultStatsOrderBy' => $settingsStatsOrderBy->string(),
                'filters' => $rawFilters,
                'logPriority' => Settings::logLevelFromString($settingsLogPriority->string()),
            ]);

            $prefs->save(Config::$prefsFile);
            Config::$settings = $prefs->applyTo(Config::$settings);

            // Update filter textarea to reflect normalised values (removes blank lines)
            $settingsFiltersText->setValue(implode("\n", $prefs->filters), broadcast: false);

            // Success toast is shown client-side immediately on click (no SSE needed)
        } catch (Throwable $e) {
            $settingsMessage->setValue(
                $makeToast('error', 'Failed to save: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
                broadcast: false
            );
        }

        $c->sync();
        // Reset message so subsequent re-renders don't re-show the error toast
        $settingsMessage->setValue('', broadcast: false);
        // Broadcast to all other tabs so their defaults refresh too
        if (!empty($app->getClients())) {
            $app->broadcast('settings:saved');
        }
    }, 'save-settings');

    // IP info: geo lookup + hostname resolution — pushes a rendered modal fragment,    // no full page re-render. The browser JS triggers this action; Datastar patches
    // the fragment into #ip-modal-placeholder and JS calls Bootstrap .show().
    $ipInfoAction = $c->action(function (Context $c): void {
        $ip = $c->input('ip') ?? '';

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        // 1. Geo lookup (server-side — no CORS issue)
        $geoData = [];
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'nfsen-ng']]);
        $json = @file_get_contents('https://ipapi.co/' . rawurlencode($ip) . '/json/', false, $ctx);
        if ($json !== false) {
            $geoData = json_decode($json, true) ?? [];
        }

        // 2. Hostname resolution
        $hostname = @gethostbyaddr($ip);
        if ($hostname === $ip || $hostname === false) {
            $esc = escapeshellarg((string) $ip);
            exec("host -W 5 {$esc} 2>&1", $out, $ret);
            if ($ret === 0) {
                $domains = [];
                foreach ($out as $line) {
                    if (preg_match('/domain name pointer (.*)\./', $line, $m)) {
                        $domains[] = $m[1];
                    }
                }
                $hostname = $domains ? implode(', ', $domains) : 'could not be resolved';
            } else {
                $hostname = 'could not be resolved';
            }
        }

        // 3. Render modal HTML and push as a fragment — no full page re-render
        $modalHtml = $c->render('partials/ip-info-modal.html.twig', [
            'ip' => htmlspecialchars($ip, ENT_QUOTES),
            'hostname' => htmlspecialchars((string) $hostname, ENT_QUOTES),
            'geoData' => $geoData,
        ]);

        $c->getPatchManager()->queuePatch([
            'type' => 'elements',
            'content' => $modalHtml,
        ]);

        // 4. Show modal via JS after the fragment lands
        $c->execScript('document.getElementById("ip-modal-inner").showModal()');
    }, 'ip-info');

    // ── View ─────────────────────────────────────────────────────────────────
    // Re-renders the full page on every $c->sync() (actions) and every
    // $app->broadcast('rrd:live') (import daemon). Brotli + Datastar DOM morphing
    // keeps the wire cost low.
    //
    // cacheUpdates: false — each tab has independent filter state, no sharing.

    // Throttle: last timestamp fetchGraphData() was actually called for this tab.
    // Allows graph refreshes every 10s during import instead of per-file.
    // Cached values are reused between refreshes so chart and timestamps stay visible.
    $lastGraphFetch = 0;
    $cachedGraphData = '[]';

    /** @var array<array{name: string, last_update: int}> $cachedImportSources */
    $cachedImportSources = [];

    // Health checks: cached per-tab; re-run at most every 30 s (or when not importing).
    $lastHealthFetch = 0;

    /** @var list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}> */
    $cachedHealthChecks = [];

    $c->view(function (bool $isUpdate) use (
        $c,
        $app,
        $fetchGraphData,
        $datestart,
        $dateend,
        $dataRangeMin,
        $dataRangeMax,
        $updateDataRange,
        $error,
        $graphDisplay,
        $graphSources,
        $graphPorts,
        $graphProtocols,
        $graphDatatype,
        $graphTrafficUnit,
        $graphResolution,
        $graphIsLive,
        $graphActualRes,
        $graphLastUpdate,
        $flowFilter,
        $flowLimit,
        $flowAggBidirectional,
        $flowAggProto,
        $flowAggSrcPort,
        $flowAggDstPort,
        $flowAggSrcIp,
        $flowAggSrcIpPrefix,
        $flowAggDstIp,
        $flowAggDstIpPrefix,
        $flowOrderByTstart,
        $flowCount,
        $flowLowerLimit,
        $flowUpperLimit,
        $flowMessage,
        $statsFilter,
        $statsCount,
        $statsFor,
        $statsOrderBy,
        $statsLowerLimit,
        $statsUpperLimit,
        $statsMessage,
        $importRunning,
        $confirmRescan,
        $importScanPorts,
        $refreshGraphsAction,
        $flowAction,
        $statsAction,
        $triggerImportAction,
        $forceRescanAction,
        $cancelImportAction,
        $countNfcapdFiles,
        $nfcapdFileCount,
        $countFilesAction,
        $settingsDefaultView,
        $settingsGraphDisplay,
        $settingsGraphDatatype,
        $settingsGraphProtocols,
        $settingsFlowLimit,
        $settingsStatsOrderBy,
        $settingsFiltersText,
        $settingsLogPriority,
        $settingsMessage,
        $saveSettingsAction,
        &$flowTableHtml,
        &$statsTableHtml,
        &$lastGraphFetch,
        &$cachedGraphData,
        &$cachedImportSources,
        &$lastHealthFetch,
        &$cachedHealthChecks
    ): string {
        // Sync importRunning signal to daemon lock state so all tabs reflect the
        // correct value when re-rendered by an admin:import or rrd:live broadcast.
        $isImporting = $app->globalState('daemon', null)?->isLocked() ?? false;
        $importRunning->setValue($isImporting, broadcast: false);

        // Skip all data fetches when Config failed to initialize (fatal error path).
        // The template will render the error banner and nothing else needs DB access.
        $hasFatalError = $app->globalState('_fatalError', null) !== null;

        // On the initial render (not an SSE update), compute the nfcapd file count
        // so the Flows/Statistics tabs show it without needing user interaction.
        // Subsequent changes are handled by countFilesAction triggered from the browser.
        if (!$hasFatalError && !$isUpdate) {
            $srcs = $graphSources->array();
            if (in_array('any', $srcs, true) || empty($srcs)) {
                $srcs = Config::$settings->sources;
            }
            $nfcapdFileCount->setValue(
                $countNfcapdFiles($datestart->int(), $dateend->int(), $srcs),
                broadcast: false
            );
        }

        // During import, throttle fetchGraphData() to at most once every 10 seconds
        // per tab — avoids N×files×RRD-reads while still refreshing the graph live.
        // Between intervals, reuse the last cached result so chart and timestamps stay visible.
        $now = time();
        if (!$hasFatalError && (!$isImporting || ($now - $lastGraphFetch) >= 10)) {
            $updateDataRange();
            $cachedGraphData = json_encode($fetchGraphData(), JSON_THROW_ON_ERROR);
            $lastGraphFetch = $now;

            $cachedImportSources = [];
            foreach (Config::$settings->sources as $source) {
                $cachedImportSources[] = [
                    'name' => $source,
                    'last_update' => Config::$db->last_update($source),
                ];
            }
        }
        $graphData = $cachedGraphData;
        $importSources = $cachedImportSources;

        // Health checks — throttled to at most once every 30 s per tab
        if (!$hasFatalError && (!$isImporting || ($now - $lastHealthFetch) >= 30)) {
            $hcDaemon = $app->globalState('daemon', null);
            $cachedHealthChecks = HealthChecker::run(
                (bool) $app->globalState('daemon_disabled', false),
                $hcDaemon !== null ? [
                    'ready' => $hcDaemon->isDaemonReady(),
                    'watchCount' => $hcDaemon->getWatchCount(),
                    'lastAutoImport' => $hcDaemon->getLastAutoImportTime(),
                ] : null,
            );
            $lastHealthFetch = $now;
        }
        $healthChecks = $cachedHealthChecks;

        // ── Status indicator computation ──────────────────────────────────
        // Capture health: infer nfcapd activity from most recent RRD last_update
        $maxLastUpdate = empty($importSources) ? 0 : max(array_column($importSources, 'last_update'));
        $captureAge = $maxLastUpdate > 0 ? (time() - $maxLastUpdate) : PHP_INT_MAX;
        if ($maxLastUpdate === 0) {
            $captureStatus = 'secondary';
            $captureLabel = 'nfcapd: no data yet';
        } elseif ($captureAge < 600) {
            $captureStatus = 'success';
            $captureLabel = 'nfcapd: last capture ' . HealthChecker::ageStr($captureAge) . ' ago';
        } elseif ($captureAge < 3600) {
            $captureStatus = 'warning';
            $captureLabel = 'nfcapd: no capture in ' . HealthChecker::ageStr($captureAge);
        } else {
            $captureStatus = 'danger';
            $captureLabel = 'nfcapd: no capture in ' . HealthChecker::ageStr($captureAge);
        }

        // Daemon health
        $d2 = $app->globalState('daemon', null);
        if ((bool) $app->globalState('daemon_disabled', false) || $d2 === null) {
            $daemonStatus = 'danger';
            $daemonLabel = 'Import daemon: disabled (NFSEN_SKIP_DAEMON)';
        } elseif (!$d2->isDaemonReady()) {
            $daemonStatus = 'warning';
            $daemonLabel = 'Import daemon: initializing…';
        } else {
            $n = $d2->getWatchCount();
            $daemonStatus = 'success';
            $daemonLabel = "Import daemon: watching {$n} dir" . ($n !== 1 ? 's' : '');
        }

        return $c->render('layout.html.twig', [
            // ── App metadata ──────────────────────────────────────────────
            'version' => Config::VERSION,
            'fatalError' => $app->globalState('_fatalError', null),
            'connections' => count($app->getClients()),
            'importYears' => Config::$settings->importYears,

            // ── Static config (non-reactive, drives option lists) ─────────
            'sources' => Config::$settings->sources,
            'ports' => Config::$settings->ports,
            'filters' => Config::$settings->filters,
            'defaults' => ['view' => Config::$settings->defaultView],

            // ── Signal objects — templates use bind(signal) and signal.id() ──
            // Date range
            'datestart' => $datestart,
            'dateend' => $dateend,
            'dataRangeMin' => $dataRangeMin,
            'dataRangeMax' => $dataRangeMax,
            // Error
            'error' => $error,
            // Graph filters
            'graphDisplay' => $graphDisplay,
            'graphSources' => $graphSources,
            'graphPorts' => $graphPorts,
            'graphProtocols' => $graphProtocols,
            'graphDatatype' => $graphDatatype,
            'graphTrafficUnit' => $graphTrafficUnit,
            'graphResolution' => $graphResolution,
            // Graph metadata (server-pushed, never sent back by browser — _-prefixed ID)
            'graphIsLive' => $graphIsLive,
            'graphActualRes' => $graphActualRes,
            'graphLastUpdate' => $graphLastUpdate,
            // Flow filter signals
            'flowFilter' => $flowFilter,
            'flowLimit' => $flowLimit,
            'flowAggBidirectional' => $flowAggBidirectional,
            'flowAggProto' => $flowAggProto,
            'flowAggSrcPort' => $flowAggSrcPort,
            'flowAggDstPort' => $flowAggDstPort,
            'flowAggSrcIp' => $flowAggSrcIp,
            'flowAggSrcIpPrefix' => $flowAggSrcIpPrefix,
            'flowAggDstIp' => $flowAggDstIp,
            'flowAggDstIpPrefix' => $flowAggDstIpPrefix,
            'flowOrderByTstart' => $flowOrderByTstart,
            'flowCount' => $flowCount,
            'flowLowerLimit' => $flowLowerLimit,
            'flowUpperLimit' => $flowUpperLimit,
            'flowMessage' => $flowMessage,
            // Stats filter signals
            'statsFilter' => $statsFilter,
            'statsCount' => $statsCount,
            'statsFor' => $statsFor,
            'statsOrderBy' => $statsOrderBy,
            'statsLowerLimit' => $statsLowerLimit,
            'statsUpperLimit' => $statsUpperLimit,
            'statsMessage' => $statsMessage,
            // nfcapd file count (for Flows/Statistics tabs)
            'nfcapdFileCount' => $nfcapdFileCount,
            // ── Action URLs ──────────────────────────────────────────────────────────────────
            'action_refreshGraphs' => $refreshGraphsAction->url(),
            'action_flowActions' => $flowAction->url(),
            'action_statsActions' => $statsAction->url(),
            'action_countFiles' => $countFilesAction->url(),
            'action_triggerImport' => $triggerImportAction->url(),
            'action_forceRescan' => $forceRescanAction->url(),
            'action_cancelImport' => $cancelImportAction->url(),
            // ── Settings ─────────────────────────────────────────────────
            'settingsDefaultView' => $settingsDefaultView,
            'settingsGraphDisplay' => $settingsGraphDisplay,
            'settingsGraphDatatype' => $settingsGraphDatatype,
            'settingsGraphProtocols' => $settingsGraphProtocols,
            'settingsFlowLimit' => $settingsFlowLimit,
            'settingsStatsOrderBy' => $settingsStatsOrderBy,
            'settingsFiltersText' => $settingsFiltersText,
            'settingsLogPriority' => $settingsLogPriority,
            'settingsMessageHtml' => $settingsMessage->string(),
            'action_saveSettings' => $saveSettingsAction->url(),
            // ── Deployment config (read-only display in Settings tab) ─────
            'deployDatasource' => Config::$settings->datasourceName,
            'deployImportYears' => Config::$settings->importYears,
            'deployNfdumpBinary' => Config::$settings->nfdumpBinary,
            'deployNfdumpProfiles' => Config::$settings->nfdumpProfilesData,
            'deployPrefsFile' => Config::$prefsFile,
            // ── Computed / pre-rendered data ──────────────────────────────
            'graphData' => $graphData,
            'flowTableHtml' => $flowTableHtml,
            'flowMessageHtml' => $flowMessage->string(),
            'statsTableHtml' => $statsTableHtml,
            'statsMessageHtml' => $statsMessage->string(),
            // ── Admin / import ────────────────────────────────────────────
            'importRunning' => $importRunning,
            'confirmRescan' => $confirmRescan,
            'importScanPorts' => $importScanPorts,
            'hasPorts' => !empty(Config::$settings->ports),
            'importSources' => $importSources,
            'importProgress' => $app->globalState('import_progress', 0),
            'importCurrentFile' => $app->globalState('import_current_file', ''),
            'importStatusText' => $app->globalState('import_status_text', ''),
            'importEta' => $app->globalState('import_eta', ''),
            'importLog' => $app->globalState('import_log', []),
            'daemonDisabled' => (bool) $app->globalState('daemon_disabled', false),
            'daemonInfo' => ($d = $app->globalState('daemon', null)) !== null ? [
                'ready' => $d->isDaemonReady(),
                'watchCount' => $d->getWatchCount(),
                'lastAutoImport' => $d->getLastAutoImportTime(),
            ] : null,
            // ── Status indicators ─────────────────────────────────────────
            'captureStatus' => $captureStatus,
            'captureLabel' => $captureLabel,
            'daemonStatus' => $daemonStatus,
            'daemonLabel' => $daemonLabel,
            // ── Health checks ─────────────────────────────────────────────
            'healthChecks' => $healthChecks,
        ]);
    }, cacheUpdates: false);
});

// ─── Start ────────────────────────────────────────────────────────────────────

$app->start();
