<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;

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
