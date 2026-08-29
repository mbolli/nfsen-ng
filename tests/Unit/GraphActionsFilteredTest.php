<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;

function settingsWithMaxWindow(int $maxWindow): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80],
            'sources' => ['gateway'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
            'max_stats_window' => $maxWindow,
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/none',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => ['priority' => LOG_ERR],
    ]);
}

describe('GraphActions::clampFilteredWindow', function (): void {
    // A filtered build re-reads every capture in the range, so an unbounded window is the
    // same open-ended cost Statistics and Sankey already clamp with this setting.
    test('leaves the window alone when no maximum is configured', function (): void {
        settingsWithMaxWindow(0);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 86400 * 400))
            ->toBe([1000, 1000 + 86400 * 400, false])
        ;
    });

    test('leaves a window that already fits', function (): void {
        settingsWithMaxWindow(86400);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 3600))->toBe([1000, 4600, false]);
    });

    test('leaves a window exactly at the limit', function (): void {
        settingsWithMaxWindow(86400);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 86400))->toBe([1000, 87400, false]);
    });

    // Keep the end and pull the start forward: the recent end of the range is the part
    // someone looking at a graph almost always means.
    test('shortens an over-long window from the start and reports it', function (): void {
        settingsWithMaxWindow(3600);

        expect(GraphActions::clampFilteredWindow(0, 86400))->toBe([86400 - 3600, 86400, true]);
    });

    test('a clamped window is exactly the maximum length', function (): void {
        settingsWithMaxWindow(7200);
        [$start, $end, $clamped] = GraphActions::clampFilteredWindow(0, 999999);

        expect($end - $start)->toBe(7200)
            ->and($clamped)->toBeTrue()
        ;
    });
});
