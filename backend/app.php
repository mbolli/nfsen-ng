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

use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Table;

// ─── Via configuration ───────────────────────────────────────────────────────

$isDev = (bool) (getenv('NFSEN_DEV_MODE') ?: false);
$workerNum = (int) (getenv('SWOOLE_WORKER_NUM') ?: 1);  // php-via is single-worker
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
        'worker_num'    => $workerNum,
        'max_coroutine' => $maxCoroutine,
        'hook_flags'    => SWOOLE_HOOK_ALL,
        'max_conn'      => 10000,
        'send_yield'    => true,
        'log_file'      => '/tmp/swoole.log',
        'reload_async'  => true,
        'max_wait_time' => 3,
    ]);

$app = new Via($viaConfig);

// ─── Startup: config + import daemon ─────────────────────────────────────────

$app->onStart(function () use ($app): void {
    try {
        Config::initialize(true);
    } catch (\Throwable $e) {
        $app->setGlobalState('_fatalError', $e->getMessage());
        error_log('[nfsen-ng] FATAL: ' . $e->getMessage());

        return;
    }

    $debug = Debug::getInstance();
    $debug->log('nfsen-ng started (php-via)', LOG_INFO);

    if (getenv('NFSEN_SKIP_DAEMON') === '1' || getenv('NFSEN_SKIP_DAEMON') === 'true') {
        $debug->log('ImportDaemon skipped (NFSEN_SKIP_DAEMON)', LOG_INFO);

        return;
    }

    $daemon = new ImportDaemon();

    // Initial bulk import runs in a coroutine — does not block the event loop.
    // NOTE: Import::start() is not coroutine-safe per the original comment in
    // listen.php. If problems arise, move this call before $app->start() so it
    // runs synchronously before the server accepts connections.
    \OpenSwoole\Coroutine::create(function () use ($daemon, $debug): void {
        try {
            $daemon->initialImport();
        } catch (\Throwable $e) {
            $debug->log('ImportDaemon: initial import failed: ' . $e->getMessage(), LOG_ERR);
        }
    });

    // Ongoing inotify poll every 1 s — setInterval runs in the event loop, not
    // in a child coroutine, which is safe per Import class restrictions.
    $app->setInterval(function () use ($app, $daemon, $debug): void {
        try {
            $daemon->pollOnce(function () use ($app, $debug): void {
                $debug->log('ImportDaemon: file imported → broadcasting rrd:live', LOG_DEBUG);
                if (!empty($app->getClients())) {
                    $app->broadcast('rrd:live');
                }
            });
        } catch (\Throwable $e) {
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
    $dateend   = $c->signal(time(), 'dateend',   clientWritable: true);
    $error     = $c->signal($fatalError, '_error');

    // Graph filter signals (client-writable — filter UI updates these)
    $graphDisplay     = $c->signal(
        Config::$cfg['frontend']['defaults']['graphs']['display'] ?? 'sources',
        'graph_display',
        clientWritable: true
    );
    $graphSources     = $c->signal(Config::$cfg['general']['sources'] ?? [], 'graph_sources', clientWritable: true);
    $graphPorts       = $c->signal(Config::$cfg['general']['ports'] ?? [], 'graph_ports', clientWritable: true);
    $graphProtocols   = $c->signal(
        Config::$cfg['frontend']['defaults']['graphs']['protocols'] ?? ['any'],
        'graph_protocols',
        clientWritable: true
    );
    $graphDatatype    = $c->signal(
        Config::$cfg['frontend']['defaults']['graphs']['datatype'] ?? 'traffic',
        'graph_datatype',
        clientWritable: true
    );
    $graphTrafficUnit = $c->signal('bits', 'graph_trafficUnit', clientWritable: true);
    $graphResolution  = $c->signal(500,    'graph_resolution',  clientWritable: true);
    // Server-side graph metadata (read-only for browser)
    $graphIsLive      = $c->signal(false, 'graph_isLive');
    $graphActualRes   = $c->signal(0,     'graph_actualResolution');
    $graphLastUpdate  = $c->signal(0,     'graph_lastUpdate');

    // Flow signals
    $flowFilter      = $c->signal('', 'flows_filter', clientWritable: true);
    $flowLimit       = $c->signal(
        Config::$cfg['frontend']['defaults']['flows']['limit'] ?? 50,
        'flows_limit',
        clientWritable: true
    );
    $flowAggBidirectional  = $c->signal(false,  'flows_agg_bidirectional',  clientWritable: true);
    $flowAggProto          = $c->signal(false,  'flows_agg_proto',          clientWritable: true);
    $flowAggSrcPort        = $c->signal(false,  'flows_agg_srcport',        clientWritable: true);
    $flowAggDstPort        = $c->signal(false,  'flows_agg_dstport',        clientWritable: true);
    $flowAggSrcIp          = $c->signal('none', 'flows_agg_srcip',          clientWritable: true);
    $flowAggSrcIpPrefix    = $c->signal('',     'flows_agg_srcip_prefix',   clientWritable: true);
    $flowAggDstIp          = $c->signal('none', 'flows_agg_dstip',          clientWritable: true);
    $flowAggDstIpPrefix    = $c->signal('',     'flows_agg_dstip_prefix',   clientWritable: true);
    $flowOrderByTstart = $c->signal(false, 'flows_orderByTstart', clientWritable: true);
    $flowCount       = $c->signal(0, 'flows_count');
    $flowMessage     = $c->signal('', 'flowMessage');

    // Stats signals
    $statsFilter  = $c->signal('', 'stats_filter', clientWritable: true);
    $statsCount   = $c->signal(10, 'stats_count', clientWritable: true);
    $statsFor     = $c->signal('record', 'stats_for', clientWritable: true);
    $statsOrderBy = $c->signal(
        Config::$cfg['frontend']['defaults']['statistics']['order_by'] ?? 'bytes',
        'stats_orderBy',
        clientWritable: true
    );
    $statsMessage  = $c->signal('', 'statsMessage');
    $statsSources  = $c->signal(Config::$cfg['general']['sources'] ?? [], 'stats_sources', clientWritable: true);

    // Host modal
    $hostResult = $c->signal('', '_hostResult');

    // ── State containers (plain PHP — NOT signals, not sent to browser) ──────
    // These carry large result sets between the action and the view closure.
    $flowTableHtml  = '';
    $statsTableHtml = '';

    // ── Subscribe to RRD import broadcasts ───────────────────────────────────
    $c->addScope('rrd:live');

    // ── Helper: fetch graph data from datasource ─────────────────────────────
    $fetchGraphData = function () use (
        $datestart, $dateend,
        $graphDisplay, $graphSources, $graphPorts, $graphProtocols,
        $graphDatatype, $graphTrafficUnit, $graphResolution,
        $graphIsLive, $graphActualRes, $graphLastUpdate, $error
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
        } catch (\Throwable $e) {
            $error->setValue('Graph error: ' . $e->getMessage(), broadcast: false);

            return [];
        }

        $windowSize = max(1, $de - $ds);
        $pointCount = count($data[array_key_first($data) ?? ''] ?? $data);
        $actualRes  = $pointCount > 0 ? (int) ($windowSize / $pointCount) : 0;
        $graphActualRes->setValue($actualRes, broadcast: false);
        $graphLastUpdate->setValue(time(), broadcast: false);

        return $data;
    };

    // ── Helper: build an nfsen-toast HTML snippet ────────────────────────────
    $makeToast = static function (string $type, string $message, bool $autoDismiss = false): string {
        return sprintf(
            '<nfsen-toast data-type="%s" data-message="%s"%s></nfsen-toast>',
            htmlspecialchars($type, ENT_QUOTES),
            htmlspecialchars($message, ENT_QUOTES),
            $autoDismiss ? ' data-auto-dismiss="true"' : ''
        );
    };

    // ── Actions ──────────────────────────────────────────────────────────────

    // Refresh graphs (called by the filter UI after any filter change)
    $refreshGraphsAction = $c->action(function (Context $c) use ($fetchGraphData): void {
        $fetchGraphData(); // signals updated in-place; view closure will read them
        $c->sync();
    }, 'refresh-graphs');

    // Flow actions — run nfdump, render result table into $flowTableHtml
    $flowAction = $c->action(function (Context $c) use (
        $debug, $makeToast,
        $datestart, $dateend, $flowFilter, $flowLimit,
        $flowAggBidirectional, $flowAggProto, $flowAggSrcPort, $flowAggDstPort,
        $flowAggSrcIp, $flowAggSrcIpPrefix, $flowAggDstIp, $flowAggDstIpPrefix,
        $flowOrderByTstart,
        $flowCount, $flowMessage,
        &$flowTableHtml
    ): void {
        $time = microtime(true);

        // Build aggregation string from individual signals
        $aggregate = '';
        if ($flowAggBidirectional->bool()) {
            $aggregate = 'bidirectional';
        } else {
            $parts = [];
            if ($flowAggProto->bool())   { $parts[] = 'proto'; }
            if ($flowAggSrcPort->bool()) { $parts[] = 'srcport'; }
            if ($flowAggDstPort->bool()) { $parts[] = 'dstport'; }
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
            $sources = implode(':', Config::$cfg['general']['sources']);
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
            $processor->setFilter($flowFilter->string());
            $result = $processor->execute();

            $flowData = $result['decoded'] ?? [];
            if (!\is_array($flowData)) {
                throw new \RuntimeException('Invalid data from nfdump processor');
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
                'hiddenFields'    => [],
                'linkIpAddresses' => true,
                'originalData'    => $result['rawOutput'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $debug->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
            $flowMessage->setValue($makeToast('error', 'Error: ' . $e->getMessage()), broadcast: false);
            $flowTableHtml = '';
        }

        $c->sync();
    }, 'flow-actions');

    // Stats actions
    $statsAction = $c->action(function (Context $c) use (
        $debug, $makeToast,
        $datestart, $dateend, $statsFilter, $statsCount,
        $statsFor, $statsOrderBy, $statsSources,
        $statsMessage,
        &$statsTableHtml
    ): void {
        $time = microtime(true);
        $forParam = $statsFor->string() . '/' . $statsOrderBy->string();

        try {
            $srcs = $statsSources->array() ?: Config::$cfg['general']['sources'];
            $processor = new Config::$processorClass();
            $processor->setOption('-M', implode(':', $srcs));
            $processor->setOption('-R', [$datestart->int(), $dateend->int()]);
            $processor->setOption('-n', $statsCount->int());
            $processor->setOption('-o', 'json');
            $processor->setOption('-s', $forParam);
            $processor->setFilter($statsFilter->string());
            $result = $processor->execute();

            $statsData = $result['decoded'] ?? [];
            if (!\is_array($statsData)) {
                throw new \RuntimeException('Invalid data from nfdump processor');
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
                'hiddenFields'    => [],
                'linkIpAddresses' => true,
                'originalData'    => $result['rawOutput'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $debug->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
            $statsMessage->setValue($makeToast('error', 'Error: ' . $e->getMessage()), broadcast: false);
            $statsTableHtml = '';
        }

        $c->sync();
    }, 'stats-actions');

    // Host / IP resolution
    $hostAction = $c->action(function (Context $c) use ($hostResult): void {
        $ip = $c->input('ip') ?? '';

        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $hostResult->setValue('<div class="alert alert-danger">Invalid IP address</div>', broadcast: false);
            $c->sync();

            return;
        }

        $hostname = @gethostbyaddr($ip);
        if ($hostname === $ip || $hostname === false) {
            $esc = escapeshellarg((string) $ip);
            exec("host -W 5 {$esc} 2>&1", $out, $ret);
            if ($ret !== 0) {
                $hostname = 'Error: ' . (!empty($out) ? implode(' ', $out) : 'could not be resolved');
            } else {
                $domains = [];
                foreach ($out as $line) {
                    if (preg_match('/domain name pointer (.*)\./', $line, $m)) {
                        $domains[] = $m[1];
                    }
                }
                $hostname = $domains ? implode(', ', $domains) : 'could not be resolved';
            }
        }

        // Store plain text — template wraps it in the heading element.
        // _hostResult is underscore-prefixed: server pushes it, browser never sends it back.
        $hostResult->setValue(htmlspecialchars((string) $hostname, ENT_QUOTES), broadcast: false);
        $c->sync();
    }, 'host');

    // ── View ─────────────────────────────────────────────────────────────────
    // Re-renders the full page on every $c->sync() (actions) and every
    // $app->broadcast('rrd:live') (import daemon). Brotli + Datastar DOM morphing
    // keeps the wire cost low.
    //
    // cacheUpdates: false — each tab has independent filter state, no sharing.

    $c->view(function (bool $isUpdate) use (
        $c, $app, $fetchGraphData,
        $datestart, $dateend, $error,
        $graphDisplay, $graphSources, $graphPorts, $graphProtocols,
        $graphDatatype, $graphTrafficUnit, $graphResolution,
        $graphIsLive, $graphActualRes, $graphLastUpdate,
        $flowFilter, $flowLimit,
        $flowAggBidirectional, $flowAggProto, $flowAggSrcPort, $flowAggDstPort,
        $flowAggSrcIp, $flowAggSrcIpPrefix, $flowAggDstIp, $flowAggDstIpPrefix,
        $flowOrderByTstart, $flowCount, $flowMessage,
        $statsSources, $statsFilter, $statsCount, $statsFor, $statsOrderBy, $statsMessage,
        $hostResult,
        $refreshGraphsAction, $flowAction, $statsAction, $hostAction,
        &$flowTableHtml, &$statsTableHtml
    ): string {
        // Always fetch fresh graph data (on broadcast the RRD file is already updated)
        $graphData = $fetchGraphData();

        return $c->render('layout.html.twig', [
            // ── App metadata ──────────────────────────────────────────────
            'version'     => Config::VERSION,
            'fatalError'  => $app->globalState('_fatalError', null),
            'connections' => count($app->getClients()),
            'importYears' => (int) (getenv('NFSEN_IMPORT_YEARS') ?: 3),

            // ── Static config (non-reactive, drives option lists) ─────────
            'sources' => Config::$cfg['general']['sources'] ?? [],
            'ports'   => Config::$cfg['general']['ports'] ?? [],
            'filters' => Config::$cfg['general']['filters'] ?? [],
            'defaults' => Config::$cfg['frontend']['defaults'] ?? [],

            // ── Signal objects — templates use bind(signal) and signal.id() ──
            // Date range
            'datestart'        => $datestart,
            'dateend'          => $dateend,
            // Error
            'error'            => $error,
            // Graph filters
            'graphDisplay'     => $graphDisplay,
            'graphSources'     => $graphSources,
            'graphPorts'       => $graphPorts,
            'graphProtocols'   => $graphProtocols,
            'graphDatatype'    => $graphDatatype,
            'graphTrafficUnit' => $graphTrafficUnit,
            'graphResolution'  => $graphResolution,
            // Graph metadata (server-pushed, never sent back by browser — _-prefixed ID)
            'graphIsLive'      => $graphIsLive,
            'graphActualRes'   => $graphActualRes,
            'graphLastUpdate'  => $graphLastUpdate,
            // Flow filter signals
            'flowFilter'            => $flowFilter,
            'flowLimit'             => $flowLimit,
            'flowAggBidirectional'  => $flowAggBidirectional,
            'flowAggProto'          => $flowAggProto,
            'flowAggSrcPort'        => $flowAggSrcPort,
            'flowAggDstPort'        => $flowAggDstPort,
            'flowAggSrcIp'          => $flowAggSrcIp,
            'flowAggSrcIpPrefix'    => $flowAggSrcIpPrefix,
            'flowAggDstIp'          => $flowAggDstIp,
            'flowAggDstIpPrefix'    => $flowAggDstIpPrefix,
            'flowOrderByTstart'     => $flowOrderByTstart,
            'flowCount'         => $flowCount,
            'flowMessage'       => $flowMessage,
            // Stats filter signals
            'statsFilter'  => $statsFilter,
            'statsCount'   => $statsCount,
            'statsFor'     => $statsFor,
            'statsOrderBy' => $statsOrderBy,
            'statsSources' => $statsSources,
            'statsMessage' => $statsMessage,
            // Host modal
            'hostResult' => $hostResult,

            // ── Action URLs ────────────────────────────────────────────────
            'action_refreshGraphs' => $refreshGraphsAction->url(),
            'action_flowActions'   => $flowAction->url(),
            'action_statsActions'  => $statsAction->url(),
            'action_host'          => $hostAction->url(),

            // ── Computed / pre-rendered data ──────────────────────────────
            'graphData'        => json_encode($graphData, JSON_THROW_ON_ERROR),
            'flowTableHtml'    => $flowTableHtml,
            'flowMessageHtml'  => $flowMessage->string(),
            'statsTableHtml'   => $statsTableHtml,
            'statsMessageHtml' => $statsMessage->string(),
        ]);
    }, cacheUpdates: false);
});

// ─── Start ────────────────────────────────────────────────────────────────────

$app->start();
