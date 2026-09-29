<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AppStartup;
use mbolli\nfsen_ng\common\Config;
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
});
