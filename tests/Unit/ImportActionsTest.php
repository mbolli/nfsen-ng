<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\ImportActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\captures\FixtureCaptures;

/** Capture files the import pass reads: enough that it takes several broadcast windows. */
const IMPORT_ACTIONS_FILES = 120;

/**
 * A Via that records each broadcast with the import state a tab would render, instead of
 * rendering; one tab is connected.
 */
function importActionsVia(): Via {
    return new class(new ViaConfig()->withLogLevel('error')) extends Via {
        /** @var list<array{scope: string, at: float, status: string, progress: int}> */
        public array $sent = [];

        public function broadcast(string $scope): void {
            $this->sent[] = [
                'scope' => $scope,
                'at' => microtime(true),
                'status' => (string) $this->globalState('import_status_text', ''),
                'progress' => (int) $this->globalState('import_progress', 0),
            ];
        }

        public function getClients(): array {
            return ['tab' => ['id' => 'tab', 'identicon' => '', 'connected_at' => 0, 'ip' => '', 'context_id' => 'ctx']];
        }
    };
}

beforeEach(function (): void {
    if (!FixtureCaptures::available()) {
        $this->markTestSkipped('needs nfcapd and nfdump');
    }
    $this->root = sys_get_temp_dir() . '/nfsen-ng-import-actions-' . bin2hex(random_bytes(6));
    // One small capture, linked under consecutive 5-minute names of yesterday.
    $from = (int) (strtotime('yesterday 00:00 UTC') ?: 0);
    FixtureCaptures::build($this->root . '/spool', 'live', ['gw'], $from, 1, 50);
    $capture = $this->root . '/spool/live/gw/' . gmdate('Y/m/d', $from) . '/nfcapd.' . gmdate('YmdHi', $from);
    mkdir($this->root . '/live/gw/' . gmdate('Y/m/d', $from), 0o777, true);
    for ($i = 0; $i < IMPORT_ACTIONS_FILES; ++$i) {
        $ts = $from + $i * 300;
        link($capture, $this->root . '/live/gw/' . gmdate('Y/m/d', $ts) . '/nfcapd.' . gmdate('YmdHi', $ts));
    }

    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->dbBefore = isset(Config::$db) ? Config::$db : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => FixtureCaptures::BIN . '/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 4],
        'log' => ['priority' => LOG_ERR],
    ]);
    $this->written = 0;
    $db = $this->createStub(Datasource::class);
    $db->method('last_update')->willReturn(0);
    // A write as slow as a small RRD update, so the pass spans several windows on any host.
    $db->method('write')->willReturnCallback(function (): bool {
        ++$this->written;
        Coroutine::usleep(5_000);

        return true;
    });
    Config::$db = $db;
    NfdumpSlots::close(false);
    ImportDaemon::resetBroadcasts();
});

afterEach(function (): void {
    ImportDaemon::resetBroadcasts();
    if (isset($this->root)) {
        FixtureCaptures::remove($this->root);
    }
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->dbBefore !== null) {
        Config::$db = $this->dbBefore;
    }
});

describe('the Trigger import pass', function (): void {
    // p8t6n: one admin:import broadcast per file re-rendered every tab 20 to 30 times a second
    // until the worker ran out of memory.
    test('broadcasts its progress at most once per window and always ends on the final state', function (): void {
        $app = importActionsVia();
        $daemon = new ImportDaemon('live');
        $app->setGlobalState('daemons', ['live' => $daemon]);
        $c = new Context('ctx', '/', $app);
        $c->signal('live', 'admin_target_profile', clientWritable: true);
        $c->signal(false, 'import_running', clientWritable: false);
        $c->signal(false, 'import_scan_ports', clientWritable: true);
        $c->signal(false, 'confirm_rescan', clientWritable: true);
        ImportActions::register($c, $app);
        $ran = [];

        Coroutine::run(static function () use ($c, $daemon, &$ran): void {
            $started = microtime(true);
            $c->executeAction((string) $c->getAction('trigger-import')?->id());
            while ($daemon->isLocked() && microtime(true) - $started < 60) {
                Coroutine::usleep(20_000);
            }
            $ran = [microtime(true) - $started, $daemon->isLocked()];
            // A broadcast deferred past the end would arrive in this time.
            Coroutine::usleep(ImportDaemon::BROADCAST_EVERY_MS * 1000 + 100_000);
        });

        $import = array_values(array_filter($app->sent, static fn (array $b): bool => $b['scope'] === 'admin:import'));
        $live = array_values(array_filter($app->sent, static fn (array $b): bool => $b['scope'] === 'rrd:live'));
        $last = end($import);
        $gaps = [];
        // The start and the end go out at once; every broadcast between waits for its window.
        for ($i = 1; $i < count($import) - 1; ++$i) {
            $gaps[] = $import[$i]['at'] - $import[$i - 1]['at'];
        }

        expect($ran[1])->toBeFalse()
            ->and($this->written)->toBe(IMPORT_ACTIONS_FILES)
            ->and($ran[0])->toBeGreaterThan(2 * ImportDaemon::BROADCAST_EVERY_MS / 1000)
            ->and(count($import))->toBeLessThanOrEqual((int) ceil($ran[0] * 1000 / ImportDaemon::BROADCAST_EVERY_MS) + 2)
            ->and(count($import))->toBeGreaterThanOrEqual(3)
            ->and($import[0]['status'])->toBe('Counting files…')
            ->and($gaps === [] ? INF : min($gaps))->toBeGreaterThanOrEqual(ImportDaemon::BROADCAST_EVERY_MS / 1000 - 0.002)
            ->and($last['status'])->toBe('Import complete.')
            ->and($last['progress'])->toBe(100)
            ->and(count($live))->toBe(1)
            ->and($live[0]['status'])->toBe('Import complete.')
            ->and(ImportDaemon::broadcastDeferred('admin:import'))->toBeFalse()
        ;
    });
});
