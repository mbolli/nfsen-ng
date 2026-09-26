#!/usr/bin/env php
<?php

/**
 * HTTP server for nfsen-ng on mbolli/php-via (OpenSwoole): one page and one SSE stream per
 * tab, composed in backend/pages from the Shell, its modules and one class per page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use mbolli\nfsen_ng\common\AppStartup;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\mcp\HttpEndpoint;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

$isDev = (bool) EnvRegistry::value('NFSEN_DEV_MODE');
$logLevel = preg_replace('/^log_/i', '', (string) EnvRegistry::value('NFSEN_LOG_LEVEL')) ?: 'info';

$viaConfig = (new ViaConfig())
    ->withHost('0.0.0.0')
    ->withPort(9000)
    ->withDevMode($isDev)
    ->withTemplateDir(__DIR__ . '/templates')
    // frontend/ only: withStaticDir() has no extension allowlist, so the repo root would
    // serve composer.json, backend/**/*.php and .git/* over plain HTTP.
    ->withStaticDir(__DIR__ . '/../frontend')
    ->withStaticCacheControl(static function (string $filePath, string $mimeType) use ($isDev): string {
        if ($isDev) {
            return 'no-cache';
        }

        // Our own JS/CSS carry ?v={{ assetVersion }}, so a changed file gets a new URL; the
        // vendored libraries are not versioned in the template and revalidate weekly.
        if (basename($filePath) === 'echarts.min.js') {
            return 'public, max-age=604800, must-revalidate';
        }

        return 'public, max-age=31536000, immutable';
    })
    ->withLogLevel($logLevel)
    ->withH2c()
    ->withBrotli()
    ->withSwooleSettings([
        'worker_num' => (int) EnvRegistry::value('SWOOLE_WORKER_NUM'), // php-via is single-worker
        'max_request' => (int) EnvRegistry::value('SWOOLE_MAX_REQUEST'), // 0 = unlimited, for long-lived SSE
        'max_coroutine' => (int) EnvRegistry::value('SWOOLE_MAX_COROUTINE'),
        // openswoole 26.2's native-curl hook segfaults on any name lookup with libcurl >= 8.20.0
        // (curl#21558), so curl calls block instead; stream IO stays hooked.
        'hook_flags' => (curl_version()['version_number'] ?? 0) >= 0x08_14_00
            ? SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_NATIVE_CURL
            : SWOOLE_HOOK_ALL,
        'max_conn' => 10000,
        'send_yield' => true,
        'log_file' => '/tmp/swoole.log',
        'reload_async' => true,
        'max_wait_time' => 3,
    ])
;

$app = new Via($viaConfig);

$app->onStart(static fn () => AppStartup::boot($app));

// MCP over HTTP. Registered unconditionally because routes are built before settings load;
// the middleware answers 404 while NFSEN_MCP_HTTP is off and every request otherwise.
$app->page(
    HttpEndpoint::PATH,
    static fn (Context $c) => $c->renderString('MCP endpoint')
)->middleware(new HttpEndpoint(Config::VERSION));

$app->page('/', static function (Context $c) use ($app): void {
    $states = new PageStates();

    // Signals and actions all exist before the first render, which freezes Twig's auto-data.
    Shell::signals($c);
    foreach (PageRegistry::MODULES as $module) {
        $module::signals($c);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::signals($c);
    }

    foreach (['admin:import', 'rrd:live', 'settings:saved', 'alerts:fired'] as $scope) {
        $c->addScope($scope);
    }

    Shell::register($c, $app, $states);
    foreach (PageRegistry::MODULES as $module) {
        $module::register($c, $app, $states);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::register($c, $app, $states);
    }

    // Tabs keep independent state, so update renders are never shared.
    $c->view(static fn (bool $isUpdate): string => $c->render(
        'layout.html.twig',
        Shell::render($c, $app, $states, $isUpdate),
    ), cacheUpdates: false);
});

$app->start();
