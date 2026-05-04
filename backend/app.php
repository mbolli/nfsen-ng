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

use mbolli\nfsen_ng\actions\AlertActions;
use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\actions\Helpers;
use mbolli\nfsen_ng\actions\ImportActions;
use mbolli\nfsen_ng\actions\SettingsActions;
use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\actions\UtilityActions;
use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AppStartup;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

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
    ->withStaticDir(__DIR__ . '/..')
    ->withLogLevel($logLevel)
    ->withH2c()
    ->withBrotli()
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

$app->onStart(static fn () => AppStartup::boot($app));

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
    // nfcapd file count — updated by count-files action and on initial render
    $nfcapdFileCount = $c->signal(0, 'nfcapd_file_count');

    // Admin / import signals
    /** @var null|ImportDaemon $daemon */
    $daemon = $app->globalState('daemon', null);

    /** @var array<string, ImportDaemon> $daemons */
    $daemons = $app->globalState('daemons', []);

    // Profiles: detect available profiles and restore selected profile from prefs.
    $availableProfilesList = Config::detectProfiles();
    $loadedPrefs = UserPreferences::load(Config::$prefsFile);
    $defaultSelectedProfile = $loadedPrefs !== null
        ? $loadedPrefs->selectedProfile
        : Config::$settings->nfdumpProfile;
    if (!in_array($defaultSelectedProfile, $availableProfilesList, true)) {
        $defaultSelectedProfile = $availableProfilesList[0] ?? 'live';
    }
    $selectedProfile = $c->signal($defaultSelectedProfile, 'selected_profile', clientWritable: true);
    $availableProfilesSignal = $c->signal($availableProfilesList, 'available_profiles');

    // adminTargetProfile: which profile the admin action buttons target
    $firstProfile = array_key_first($daemons) ?? Config::$settings->nfdumpProfile;
    $adminTargetProfile = $c->signal($firstProfile, 'admin_target_profile', clientWritable: true);

    // importRunning: true when any daemon is locked
    $anyLocked = array_reduce($daemons, fn ($carry, $d) => $carry || $d->isLocked(), false);
    $importRunning = $c->signal($anyLocked, 'import_running');
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

    // Alert rule form signals — used to create/edit individual alert rules in Settings
    $alertFormId = $c->signal('', 'alert_form_id', clientWritable: true);
    $alertFormName = $c->signal('', 'alert_form_name', clientWritable: true);
    $alertFormEnabled = $c->signal(true, 'alert_form_enabled', clientWritable: true);
    $alertFormProfile = $c->signal(Config::$settings->nfdumpProfile, 'alert_form_profile', clientWritable: true);
    $alertFormSources = $c->signal(Config::$settings->sources, 'alert_form_sources', clientWritable: true);
    $alertFormMetric = $c->signal('bytes', 'alert_form_metric', clientWritable: true);
    $alertFormOperator = $c->signal('>', 'alert_form_operator', clientWritable: true);
    $alertFormThresholdType = $c->signal('absolute', 'alert_form_thresholdType', clientWritable: true);
    $alertFormThresholdValue = $c->signal(0, 'alert_form_thresholdValue', clientWritable: true);
    $alertFormAvgWindow = $c->signal('1h', 'alert_form_avgWindow', clientWritable: true);
    $alertFormCooldownSlots = $c->signal(3, 'alert_form_cooldownSlots', clientWritable: true);
    $alertFormNotifyEmail = $c->signal('', 'alert_form_notifyEmail', clientWritable: true);
    $alertFormNotifyWebhook = $c->signal('', 'alert_form_notifyWebhook', clientWritable: true);

    // ── State containers (plain PHP — NOT signals, not sent to browser) ──────
    // These carry large result sets between the action and the view closure.
    $flowTableHtml = '';
    $statsTableHtml = '';
    // Notifications persist across broadcasts until explicitly dismissed server-side.
    // Each entry: ['id' => hex_string, 'type' => 'success'|'warning'|'error', 'message' => html_string]
    $flowNotifications = [];
    $statsNotifications = [];

    // ── Subscribe to import and RRD broadcasts ──────────────────────────────
    // admin:import — live progress pushed from the import coroutine to all admin tabs
    // rrd:live     — graph refresh after any import completes
    // settings:saved — preferences updated; all tabs re-render with new defaults
    $c->addScope('admin:import');
    $c->addScope('rrd:live');
    $c->addScope('settings:saved');
    $c->addScope('alerts:fired');

    // ── Actions ──────────────────────────────────────────────────────────────
    GraphActions::register($c);
    FlowActions::register($c, $flowNotifications, $flowTableHtml);
    StatsActions::register($c, $flowNotifications, $statsNotifications, $statsTableHtml);
    ImportActions::register($c, $app);
    SettingsActions::register($c, $app);
    AlertActions::register($c, $app);
    UtilityActions::register($c, $flowNotifications, $statsNotifications);

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

    // Alert toast: track the last alert_fired ts shown by this tab to avoid duplicate toasts.
    $lastAlertShown = 0;

    $c->view(function (bool $isUpdate) use (
        $c,
        $app,
        &$flowNotifications,
        &$statsNotifications,
        &$flowTableHtml,
        &$statsTableHtml,
        &$lastGraphFetch,
        &$cachedGraphData,
        &$cachedImportSources,
        &$lastHealthFetch,
        &$cachedHealthChecks,
        &$lastAlertShown
    ): string {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphSources = $c->getSignal('graph_sources');
        $importRunning = $c->getSignal('import_running');
        $selectedProfile = $c->getSignal('selected_profile');
        $nfcapdFileCount = $c->getSignal('nfcapd_file_count');
        $settingsMessage = $c->getSignal('settings_message');
        assert(
            $datestart !== null
            && $dateend !== null
            && $graphSources !== null
            && $importRunning !== null
            && $selectedProfile !== null
            && $nfcapdFileCount !== null
            && $settingsMessage !== null
        );

        // Sync importRunning signal to daemon lock state so all tabs reflect the
        // correct value when re-rendered by an admin:import or rrd:live broadcast.
        /** @var array<string, ImportDaemon> $allDaemons */
        $allDaemons = $app->globalState('daemons', []);
        $isImporting = array_reduce($allDaemons, fn ($carry, $d) => $carry || $d->isLocked(), false);
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
                Helpers::countNfcapdFiles($datestart->int(), $dateend->int(), $srcs, $selectedProfile->string()),
                broadcast: false
            );
        }

        // During import, throttle fetchGraphData() to at most once every 10 seconds
        // per tab — avoids N×files×RRD-reads while still refreshing the graph live.
        // Between intervals, reuse the last cached result so chart and timestamps stay visible.
        $now = time();
        if (!$hasFatalError && (!$isImporting || ($now - $lastGraphFetch) >= 10)) {
            GraphActions::updateDataRange($c);

            // Auto-advance the selected window when the user is in "live" mode
            // (dateend within 10 min of now). This runs on every rrd:live broadcast
            // so the selection tracks new data even when the graph refresh interval
            // has stopped (graphIsLive=false after the 5-min SSE liveness window).
            $de = $dateend->int();
            if ($now - $de < 600) {
                $window = $de - $datestart->int();
                $dateend->setValue($now, broadcast: false);
                $datestart->setValue($now - $window, broadcast: false);
            }

            $cachedGraphData = json_encode(GraphActions::fetchGraphData($c), JSON_THROW_ON_ERROR);
            $lastGraphFetch = $now;

            $cachedImportSources = [];
            foreach (Config::$settings->sources as $source) {
                $cachedImportSources[] = [
                    'name' => $source,
                    'last_update' => Config::$db->last_update($source, 0, $selectedProfile->string()),
                ];
            }
        }
        $graphData = $cachedGraphData;
        $importSources = $cachedImportSources;

        // Health checks — throttled to at most once every 30 s per tab
        if (!$hasFatalError && (!$isImporting || ($now - $lastHealthFetch) >= 30)) {
            $hcDaemonsInfo = [];
            foreach ($allDaemons as $prof => $d) {
                $hcDaemonsInfo[$prof] = [
                    'ready' => $d->isDaemonReady(),
                    'watchCount' => $d->getWatchCount(),
                    'lastAutoImport' => $d->getLastAutoImportTime(),
                ];
            }
            $cachedHealthChecks = HealthChecker::run(
                (bool) $app->globalState('daemon_disabled', false),
                $hcDaemonsInfo,
            );
            $lastHealthFetch = $now;
        }
        $healthChecks = $cachedHealthChecks;

        // Build daemonsInfo map: profile → status array (for admin panel)
        $daemonsInfo = [];
        foreach ($allDaemons as $profile => $d) {
            $daemonsInfo[$profile] = [
                'ready' => $d->isDaemonReady(),
                'watchCount' => $d->getWatchCount(),
                'lastAutoImport' => $d->getLastAutoImportTime(),
            ];
        }

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

        // Daemon health — aggregate across all daemons
        $d2 = $app->globalState('daemon', null);
        if ((bool) $app->globalState('daemon_disabled', false) || empty($allDaemons)) {
            $daemonStatus = 'danger';
            $daemonLabel = 'Import daemon: disabled (NFSEN_SKIP_DAEMON)';
        } else {
            $allReady = array_reduce($allDaemons, fn ($carry, $d) => $carry && $d->isDaemonReady(), true);
            $totalWatches = array_sum(array_map(fn ($d) => $d->getWatchCount(), $allDaemons));
            $profileCount = count($allDaemons);
            if (!$allReady) {
                $daemonStatus = 'warning';
                $daemonLabel = 'Import daemon: initializing…';
            } else {
                $daemonStatus = 'success';
                $daemonLabel = "Import daemon: {$profileCount} profile" . ($profileCount !== 1 ? 's' : '') . ", watching {$totalWatches} dir" . ($totalWatches !== 1 ? 's' : '');
            }
        }

        // ── Alert toast (via execScript — bypasses data-ignore-morph) ─────
        // Only show once per tab per fired event.
        $alertFiredHtml = ''; // kept for template variable; delivery is via execScript
        $alertFiredInfo = $app->globalState('alert_fired', null);
        if (
            $alertFiredInfo !== null
            && is_array($alertFiredInfo)
            && ($alertFiredTs = (int) ($alertFiredInfo['ts'] ?? 0)) > $lastAlertShown
            && (time() - $alertFiredTs) < 300
        ) {
            foreach ((array) ($alertFiredInfo['names'] ?? []) as $firedName) {
                $msgJs = json_encode('Alert fired: ' . (string) $firedName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $c->execScript("window.showMessage('warning', {$msgJs}, true)");
            }
            $lastAlertShown = $alertFiredTs;
        }

        /** @var null|AlertManager $alertMgrView */
        $alertMgrView = $app->globalState('alertManager', null);
        $alertLog = $alertMgrView !== null ? $alertMgrView->getRecentLog(10) : [];

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

            // ── Settings message (plain string, not a Signal) ─────────────
            'settingsMessageHtml' => $settingsMessage->string(),

            // ── Deployment config (read-only display in Settings tab) ─────
            'deployDatasource' => Config::$settings->datasourceName,
            'deployImportYears' => Config::$settings->importYears,
            'deployNfdumpBinary' => Config::$settings->nfdumpBinary,
            'deployNfdumpProfiles' => Config::$settings->nfdumpProfilesData,
            'deployPrefsFile' => Config::$prefsFile,

            // ── Computed / pre-rendered data ──────────────────────────────
            'graphData' => $graphData,
            'flowTableHtml' => $flowTableHtml,
            'flowNotifications' => $flowNotifications,
            'statsTableHtml' => $statsTableHtml,
            'statsNotifications' => $statsNotifications,

            // ── Admin / import ────────────────────────────────────────────
            'hasPorts' => !empty(Config::$settings->ports),
            'importSources' => $importSources,
            'importProgress' => $app->globalState('import_progress', 0),
            'importCurrentFile' => $app->globalState('import_current_file', ''),
            'importStatusText' => $app->globalState('import_status_text', ''),
            'importEta' => $app->globalState('import_eta', ''),
            'importLog' => $app->globalState('import_log', []),
            'importActiveProfile' => $app->globalState('import_active_profile', ''),
            'daemonDisabled' => (bool) $app->globalState('daemon_disabled', false),
            'daemonsInfo' => $daemonsInfo,
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

            // ── Alerts ───────────────────────────────────────────────────
            'alerts' => Config::$settings->alerts,
            'alertLog' => $alertLog,
            'alertFiredHtml' => $alertFiredHtml,
            'alertEmailEnabled' => Config::$settings->alertEmailFrom !== '',
        ]);
    }, cacheUpdates: false);
});

// ─── Start ────────────────────────────────────────────────────────────────────

$app->start();
