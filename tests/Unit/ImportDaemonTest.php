<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

function importDaemonRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/nfsen-ng-daemon-' . bin2hex(random_bytes(6));
    $this->today = $this->root . '/live/gw/' . date('Y/m/d');
    mkdir($this->today, 0o777, true);
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $this->root, 'profile' => 'live'],
        'log' => ['priority' => LOG_ERR],
    ]);
});

afterEach(function (): void {
    importDaemonRemoveTree($this->root);
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('ImportDaemon::stop()', function (): void {
    test('closes the watches, and a capture file that arrives afterwards is not imported', function (): void {
        $daemon = new ImportDaemon('live');
        $daemon->setupWatchesOnly();
        $watched = [$daemon->isDaemonReady(), $daemon->getWatchCount()];

        $daemon->stop();
        touch($this->today . '/nfcapd.' . date('YmdHi', time() - time() % 300));
        $imported = [];
        $daemon->pollOnce(static function (string $source, int $ts) use (&$imported): void {
            $imported[] = [$source, $ts];
        });

        expect($watched)->toBe([true, 1])
            ->and($daemon->isStopped())->toBeTrue()
            ->and($daemon->isDaemonReady())->toBeFalse()
            ->and($daemon->getWatchCount())->toBe(0)
            ->and($imported)->toBe([])
        ;
    });

    // A stop during the startup import: the setup that follows it must not watch again.
    test('a daemon stopped before its watches were set up never sets them up', function (): void {
        $daemon = new ImportDaemon('live');
        $daemon->stop();
        $daemon->setupWatchesOnly();

        expect($daemon->isDaemonReady())->toBeFalse()
            ->and($daemon->getWatchCount())->toBe(0)
        ;
    });
});

/**
 * A Via that records each fan-out with the progress state it would render, instead of rendering
 * for $renderMs; one tab is connected unless $tabs is false. Like php-via, a broadcast during a
 * fan-out of its scope runs it once more when it ends.
 */
function importDaemonVia(bool $tabs = true, int $renderMs = 0): Via {
    return new class(new ViaConfig()->withLogLevel('error'), $tabs, $renderMs) extends Via {
        /** @var list<array{scope: string, at: float, end: float, progress: mixed}> */
        public array $sent = [];

        public bool $stopping = false;

        /** @var array<string, bool> scope → whether a broadcast arrived during its fan-out */
        private array $rendering = [];

        public function __construct(ViaConfig $config, private readonly bool $tabs, private readonly int $renderMs) {
            parent::__construct($config);
        }

        public function broadcast(string $scope): void {
            if (isset($this->rendering[$scope])) {
                $this->rendering[$scope] = true;

                return;
            }
            do {
                $this->rendering[$scope] = false;
                $at = microtime(true);
                $progress = $this->globalState('import_progress');
                if ($this->renderMs > 0) {
                    Coroutine::usleep($this->renderMs * 1000);
                }
                $this->sent[] = ['scope' => $scope, 'at' => $at, 'end' => microtime(true), 'progress' => $progress];
            } while ($this->rendering[$scope]);
            unset($this->rendering[$scope]);
        }

        public function isShuttingDown(): bool {
            return $this->stopping;
        }

        public function getClients(): array {
            return $this->tabs ? ['tab' => ['id' => 'tab', 'identicon' => '', 'connected_at' => 0, 'ip' => '', 'context_id' => 'ctx']] : [];
        }
    };
}

describe('ImportDaemon::broadcast()', function (): void {
    beforeEach(function (): void {
        ImportDaemon::resetBroadcasts();
    });

    afterEach(function (): void {
        ImportDaemon::resetBroadcasts();
    });

    // p8t6n: a broadcast per imported file re-rendered every tab 20 to 30 times a second.
    test('sends at most one broadcast per window, the last one with the state as it is at its end', function (): void {
        $app = importDaemonVia();
        $started = microtime(true);

        Coroutine::run(static function () use ($app): void {
            for ($file = 1; $file <= 60; ++$file) {
                $app->setGlobalState('import_progress', $file);
                ImportDaemon::broadcast($app, 'admin:import');
                Coroutine::usleep(10_000);
            }
        });

        $gaps = [];
        for ($i = 1; $i < count($app->sent); ++$i) {
            $gaps[] = $app->sent[$i]['at'] - $app->sent[$i - 1]['at'];
        }
        expect(count($app->sent))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual((int) ceil((microtime(true) - $started) * 1000 / ImportDaemon::BROADCAST_EVERY_MS))
            ->and($app->sent[0]['progress'])->toBe(1)
            ->and(end($app->sent)['progress'])->toBe(60)
            ->and(min($gaps))->toBeGreaterThanOrEqual(ImportDaemon::BROADCAST_EVERY_MS / 1000 - 0.002)
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
        ;
    });

    // A render can suspend (a first Twig compile, hooked file I/O), so a fan-out can outlast the window.
    test('waits a whole window after a fan-out that renders longer than the window', function (): void {
        $app = importDaemonVia(renderMs: ImportDaemon::BROADCAST_EVERY_MS + 50);

        Coroutine::run(static function () use ($app): void {
            // The first fan-out renders in a coroutine of its own, as a deferred one does, while the import goes on.
            Coroutine::create(static fn () => ImportDaemon::broadcast($app, 'admin:import'));
            for ($file = 1; $file <= 100; ++$file) {
                $app->setGlobalState('import_progress', $file);
                ImportDaemon::broadcast($app, 'admin:import');
                Coroutine::usleep(10_000);
            }
        });

        $gaps = [];
        for ($i = 1; $i < count($app->sent); ++$i) {
            $gaps[] = $app->sent[$i]['at'] - $app->sent[$i - 1]['end'];
        }
        expect(count($app->sent))->toBeGreaterThanOrEqual(3)
            ->and(min($gaps))->toBeGreaterThanOrEqual(ImportDaemon::BROADCAST_EVERY_MS / 1000 - 0.002)
            ->and(end($app->sent)['progress'])->toBe(100)
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
        ;
    });

    test('now: during a fan-out renders once more when it ends, and leaves nothing waiting', function (): void {
        $app = importDaemonVia(renderMs: 50);

        Coroutine::run(static function () use ($app): void {
            Coroutine::create(static fn () => ImportDaemon::broadcast($app, 'admin:import'));
            ImportDaemon::broadcast($app, 'admin:import');
            $app->setGlobalState('import_progress', 100);
            ImportDaemon::broadcast($app, 'admin:import', now: true);
        });

        expect(array_column($app->sent, 'progress'))->toBe([null, 100])
            ->and($app->sent[1]['at'])->toBeGreaterThanOrEqual($app->sent[0]['end'])
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
        ;
    });

    test('now: sends at once and drops the deferred broadcast, so nothing is left waiting', function (): void {
        $app = importDaemonVia();
        $deferred = null;
        $started = microtime(true);

        Coroutine::run(static function () use ($app, &$deferred): void {
            $app->setGlobalState('import_progress', 50);
            ImportDaemon::broadcast($app, 'admin:import');
            ImportDaemon::broadcast($app, 'admin:import');
            $deferred = ImportDaemon::broadcastDeferred('admin:import');
            $app->setGlobalState('import_progress', 100);
            ImportDaemon::broadcast($app, 'admin:import', now: true);
        });

        expect($deferred)->toBeTrue()
            ->and(array_column($app->sent, 'progress'))->toBe([50, 100])
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
            ->and(microtime(true) - $started)->toBeLessThan(ImportDaemon::BROADCAST_EVERY_MS / 1000)
        ;
    });

    test('keeps a window per scope', function (): void {
        $app = importDaemonVia();

        Coroutine::run(static function () use ($app): void {
            ImportDaemon::broadcast($app, 'admin:import');
            ImportDaemon::broadcast($app, 'rrd:live');
        });

        expect(array_column($app->sent, 'scope'))->toBe(['admin:import', 'rrd:live']);
    });

    test('sends nothing without a connected tab', function (): void {
        $app = importDaemonVia(tabs: false);

        Coroutine::run(static function () use ($app): void {
            ImportDaemon::broadcast($app, 'rrd:live');
            ImportDaemon::broadcast($app, 'rrd:live', now: true);
        });

        expect($app->sent)->toBe([]);
    });

    test('sends no deferred broadcast once the worker stops, and defers none after', function (): void {
        $app = importDaemonVia();

        Coroutine::run(static function () use ($app): void {
            ImportDaemon::broadcast($app, 'rrd:live');
            ImportDaemon::broadcast($app, 'rrd:live');
            $app->stopping = true;
            Coroutine::usleep(ImportDaemon::BROADCAST_EVERY_MS * 1000 + 50_000);
            ImportDaemon::broadcast($app, 'rrd:live');
            Coroutine::usleep(10_000);
            ImportDaemon::broadcast($app, 'rrd:live');
        });

        expect(count($app->sent))->toBe(2)
            ->and(ImportDaemon::broadcastDeferred('rrd:live'))->toBeFalse()
        ;
    });

    // AppStartup::shutdown() resets, so no timer is left for the worker's exit to wait on.
    test('a reset drops the deferred broadcast', function (): void {
        $app = importDaemonVia();
        $started = microtime(true);

        Coroutine::run(static function () use ($app): void {
            ImportDaemon::broadcast($app, 'admin:import');
            ImportDaemon::broadcast($app, 'admin:import');
            ImportDaemon::resetBroadcasts();
        });

        expect(count($app->sent))->toBe(1)
            ->and(microtime(true) - $started)->toBeLessThan(ImportDaemon::BROADCAST_EVERY_MS / 1000)
        ;
    });

    // The CLI and the tests have no event loop to send a deferred broadcast from.
    test('without an event loop, sends every call at once', function (): void {
        $app = importDaemonVia();

        ImportDaemon::broadcast($app, 'rrd:live');
        ImportDaemon::broadcast($app, 'rrd:live');

        expect(count($app->sent))->toBe(2);
    });
});
