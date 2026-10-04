<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AppStartup;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Tests\Support\captures\FixtureCaptures;

/**
 * A process standing in for an nfdump run, registered under $handle like Nfdump::execute() does.
 *
 * @return resource
 */
function appStartupFakeRun(string $handle): mixed {
    $process = proc_open(['sleep', '30'], [], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start sleep');
    }
    NfdumpSlots::register($handle, proc_get_status($process)['pid']);

    return $process;
}

/** @param resource $process */
function appStartupRunning(mixed $process): bool {
    for ($i = 0; $i < 50 && proc_get_status($process)['running']; ++$i) {
        usleep(10_000);
    }

    return proc_get_status($process)['running'];
}

/**
 * A Via that records each broadcast with the import state a tab would render, instead of
 * rendering; one tab is connected.
 */
function appStartupVia(): Via {
    return new class(new ViaConfig()->withLogLevel('error')) extends Via {
        /** @var list<array{scope: string, at: float, status: string, progress: int, log: int}> */
        public array $sent = [];

        public function broadcast(string $scope): void {
            $this->sent[] = [
                'scope' => $scope,
                'at' => microtime(true),
                'status' => (string) $this->globalState('import_status_text', ''),
                'progress' => (int) $this->globalState('import_progress', 0),
                'log' => count((array) $this->globalState('import_log', [])),
            ];
        }

        public function getClients(): array {
            return ['tab' => ['id' => 'tab', 'identicon' => '', 'connected_at' => 0, 'ip' => '', 'context_id' => 'ctx']];
        }
    };
}

/**
 * @param list<array{scope: string, at: float, status: string, progress: int, log: int}> $sent
 *
 * @return list<array{scope: string, at: float, status: string, progress: int, log: int}>
 */
function appStartupSent(array $sent, string $scope): array {
    return array_values(array_filter($sent, static fn (array $b): bool => $b['scope'] === $scope));
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => sys_get_temp_dir(), 'profile' => 'live', 'max-processes' => 4],
        'log' => ['priority' => LOG_ERR],
    ]);
    $this->app = new Via(new ViaConfig()->withLogLevel('error'));
    $this->processes = [];
});

afterEach(function (): void {
    foreach ($this->processes as $process) {
        proc_terminate($process, SIGKILL);
        proc_close($process);
    }
    foreach (NfdumpSlots::running() as $handle => $pids) {
        foreach ($pids as $pid) {
            NfdumpSlots::unregister($handle, $pid);
        }
    }
    NfdumpSlots::close(false);
    NfcapdFiles::stop(false);
    QueryCancel::clearAll();
    TopNCollector::reset();
    ImportDaemon::resetBroadcasts();
    Database::resetShared();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('AppStartup::shutdown()', function (): void {
    test('stops the daemons, the collector, alert checks and file walks, refuses new queries and kills the tabs\' and MCP calls\' runs only', function (): void {
        $daemon = new ImportDaemon('live');
        $this->app->setGlobalState('daemons', ['live' => $daemon]);
        $state = sys_get_temp_dir() . '/app-startup-alerts-' . bin2hex(random_bytes(4)) . '.json';
        $alerts = new AlertManager($this->createStub(Datasource::class), $state, $state . '.log', '');
        $this->app->setGlobalState('alertManager', $alerts);
        TopNCollector::start(new TopNRepository(Database::open(':memory:')), 31, time(), ['live']);
        $this->processes = [
            'tab' => appStartupFakeRun('ctx-tab'),
            'mcp' => appStartupFakeRun(Guard::handle()),
            'collector' => appStartupFakeRun(TopNCollector::HANDLE),
            'import' => appStartupFakeRun(NfdumpSlots::SHARED_HANDLE),
        ];

        AppStartup::shutdown($this->app);

        expect($daemon->isStopped())->toBeTrue()
            ->and($alerts->isStopped())->toBeTrue()
            ->and(TopNCollector::booted())->toBeFalse()
            ->and(NfdumpSlots::isClosed())->toBeTrue()
            ->and(fn () => NfcapdFiles::names(0, 300, 'gw', 'live'))->toThrow(RuntimeException::class, 'stopping')
            ->and(QueryCancel::isRequested('ctx-tab'))->toBeTrue()
            ->and(QueryCancel::isRequested(NfdumpSlots::SHARED_HANDLE))->toBeFalse()
            ->and(appStartupRunning($this->processes['tab']))->toBeFalse()
            ->and(appStartupRunning($this->processes['mcp']))->toBeFalse()
            ->and(appStartupRunning($this->processes['collector']))->toBeFalse()
            ->and(appStartupRunning($this->processes['import']))->toBeTrue()
        ;
    });

    test('waits for the work it ended, but not past its budget', function (): void {
        $waited = [];

        Coroutine::run(function () use (&$waited): void {
            Coroutine::create(static fn () => Coroutine::usleep(200_000));
            $started = microtime(true);
            AppStartup::shutdown($this->app);
            $waited[] = microtime(true) - $started;

            $stuck = new Channel(1);
            Coroutine::create(static fn () => $stuck->pop(10.0));
            $started = microtime(true);
            AppStartup::shutdown($this->app);
            $waited[] = microtime(true) - $started;
            $stuck->close();
        });

        expect($waited[0])->toBeGreaterThan(0.15)->toBeLessThan(0.5)
            ->and($waited[1])->toBeGreaterThanOrEqual(AppStartup::SHUTDOWN_WAIT_SECONDS)->toBeLessThan(AppStartup::SHUTDOWN_WAIT_SECONDS + 0.5)
        ;
    });

    test('drops an import broadcast the throttle deferred, so no timer outlives the stop', function (): void {
        $app = appStartupVia();
        $deferred = null;
        $started = microtime(true);

        Coroutine::run(static function () use ($app, &$deferred): void {
            ImportDaemon::broadcast($app, 'admin:import');
            ImportDaemon::broadcast($app, 'admin:import');
            $deferred = ImportDaemon::broadcastDeferred('admin:import');
            AppStartup::shutdown($app);
        });

        expect($deferred)->toBeTrue()
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
            ->and(count($app->sent))->toBe(1)
            ->and(microtime(true) - $started)->toBeLessThan(ImportDaemon::BROADCAST_EVERY_MS / 1000)
        ;
    });
});

describe('AppStartup::catchUp()', function (): void {
    beforeEach(function (): void {
        if (!FixtureCaptures::available()) {
            $this->markTestSkipped('needs nfcapd and nfdump');
        }
        $this->root = sys_get_temp_dir() . '/nfsen-ng-catch-up-' . bin2hex(random_bytes(6));
        // One small capture, linked under consecutive 5-minute names of yesterday.
        $from = (int) (strtotime('yesterday 00:00 UTC') ?: 0);
        FixtureCaptures::build($this->root . '/spool', 'live', ['gw'], $from, 1, 50);
        $capture = $this->root . '/spool/live/gw/' . gmdate('Y/m/d', $from) . '/nfcapd.' . gmdate('YmdHi', $from);
        mkdir($this->root . '/live/gw/' . gmdate('Y/m/d', $from), 0o777, true);
        for ($i = 0; $i < 120; ++$i) {
            $ts = $from + $i * 300;
            link($capture, $this->root . '/live/gw/' . gmdate('Y/m/d', $ts) . '/nfcapd.' . gmdate('YmdHi', $ts));
        }

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => FixtureCaptures::BIN . '/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 4],
            'log' => ['priority' => LOG_ERR],
        ]);
        $this->dbBefore = isset(Config::$db) ? Config::$db : null;
        $this->written = 0;
        $db = $this->createStub(Datasource::class);
        $db->method('last_update')->willReturn(0);
        // A write as slow as a small RRD update, so the catch-up spans several windows on any host.
        $db->method('write')->willReturnCallback(function (): bool {
            ++$this->written;
            Coroutine::usleep(5_000);

            return true;
        });
        Config::$db = $db;
        $this->daemon = new ImportDaemon('live');
        Debug::drainBuffer();
    });

    afterEach(function (): void {
        if (isset($this->daemon)) {
            $this->daemon->stop();
        }
        if (isset($this->root)) {
            FixtureCaptures::remove($this->root);
        }
        if (isset($this->dbBefore)) {
            Config::$db = $this->dbBefore;
        }
    });

    test('broadcasts its progress at most once per window and always ends on the final state', function (): void {
        $app = appStartupVia();
        $daemon = $this->daemon;
        $took = 0.0;
        $ended = 0.0;

        Coroutine::run(static function () use ($app, $daemon, &$took, &$ended): void {
            // The catch-up prints a progress bar; each coroutine has its own output buffer.
            ob_start();
            $started = microtime(true);
            AppStartup::catchUp($app, ['live' => $daemon]);
            $ended = microtime(true);
            $took = $ended - $started;
            ob_end_clean();
            // A broadcast deferred past the end would arrive in this time.
            Coroutine::usleep(ImportDaemon::BROADCAST_EVERY_MS * 1000 + 100_000);
        });

        $import = appStartupSent($app->sent, 'admin:import');
        $gaps = [];
        // The start and the end go out at once; every broadcast between waits for its window.
        for ($i = 1; $i < count($import) - 1; ++$i) {
            $gaps[] = $import[$i]['at'] - $import[$i - 1]['at'];
        }

        expect($this->written)->toBe(120)
            ->and($took)->toBeGreaterThan(2 * ImportDaemon::BROADCAST_EVERY_MS / 1000)
            ->and(count($import))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual((int) ceil($took * 1000 / ImportDaemon::BROADCAST_EVERY_MS) + 2)
            ->and($import[0]['status'])->toBe('[live] Catching up on missed files…')
            ->and($gaps === [] ? INF : min($gaps))->toBeGreaterThanOrEqual(ImportDaemon::BROADCAST_EVERY_MS / 1000 - 0.002)
            ->and(end($import)['status'])->toBe('[live] Up to date')
            ->and(end($import)['progress'])->toBe(100)
            ->and(end($import)['at'])->toBeLessThanOrEqual($ended)
            ->and(appStartupSent($app->sent, 'rrd:live'))->toBe([])
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
        ;
    });
});

describe('AppStartup::onFileImported()', function (): void {
    beforeEach(function (): void {
        Debug::drainBuffer();
    });

    // One live refresh per imported file re-rendered every tab, which a burst of late files turns into a storm.
    test('refreshes the live graph and the import log at most once per window, and after the last file', function (): void {
        $app = appStartupVia();
        $onImported = AppStartup::onFileImported($app, 'live');
        $last = 0.0;

        Coroutine::run(static function () use ($onImported, &$last): void {
            for ($file = 1; $file <= 40; ++$file) {
                Debug::getInstance()->log("RRD write of file {$file} failed", LOG_WARNING);
                $onImported('gw', 1_790_000_000 + $file * 300, true);
                $last = microtime(true);
                Coroutine::usleep(15_000);
            }
        });

        foreach (['rrd:live', 'admin:import'] as $scope) {
            $sent = appStartupSent($app->sent, $scope);
            $gaps = [];
            for ($i = 1; $i < count($sent); ++$i) {
                $gaps[] = $sent[$i]['at'] - $sent[$i - 1]['at'];
            }
            expect(count($sent))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5)
                ->and(min($gaps))->toBeGreaterThanOrEqual(ImportDaemon::BROADCAST_EVERY_MS / 1000 - 0.002)
                ->and(end($sent)['at'])->toBeGreaterThan($last)
                ->and(end($sent)['log'])->toBe(40)
                ->and(ImportDaemon::broadcastDeferred($scope))->toBeFalse()
            ;
        }
    });
});

describe('awaitBoot', function (): void {
    $setBooted = static function (bool $booted): void {
        (new ReflectionProperty(AppStartup::class, 'booted'))->setValue(null, $booted);
    };
    afterEach(fn () => $setBooted(true));

    // A page load during boot() read Config::$settings before it was set and answered 500.
    test('a request that arrives during boot waits for it to end', function () use ($setBooted): void {
        $setBooted(false);
        $ready = null;
        $waited = 0.0;
        Coroutine::run(static function () use ($setBooted, &$ready, &$waited): void {
            Coroutine::create(static function () use ($setBooted): void {
                Coroutine::usleep(50_000);
                $setBooted(true);
            });
            $start = microtime(true);
            $ready = AppStartup::awaitBoot();
            $waited = microtime(true) - $start;
        });

        expect($ready)->toBeTrue()->and($waited)->toBeGreaterThanOrEqual(0.04)->toBeLessThan(1.0);
    });

    test('it gives up after the timeout and outside a coroutine returns at once', function () use ($setBooted): void {
        $setBooted(false);
        $ready = null;
        Coroutine::run(static function () use (&$ready): void {
            $ready = AppStartup::awaitBoot(0.05);
        });

        $start = microtime(true);
        expect($ready)->toBeFalse()
            ->and(AppStartup::awaitBoot())->toBeFalse()
            ->and(microtime(true) - $start)->toBeLessThan(0.01)
        ;
    });
});
