<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\query\TimeWindow;

function makeWindowSettings(int $maxStatsWindow): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'sources' => ['gw'],
            'ports' => [],
            'db' => 'RRD',
            'processor' => 'Nfdump',
            'max_stats_window' => $maxStatsWindow,
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile' => 'live',
            'max-processes' => 1,
        ],
        'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
        'log' => ['priority' => LOG_WARNING],
    ]);
}

describe('TimeWindow::clamped()', function (): void {
    test('leaves a window inside the maximum untouched', function (): void {
        makeWindowSettings(86400);
        $w = TimeWindow::clamped(1000, 1000 + 3600);

        expect($w->start)->toBe(1000)
            ->and($w->end)->toBe(1000 + 3600)
            ->and($w->clamped)->toBeFalse()
        ;
    });

    // The end is what someone is looking at, so a long range loses its old start, not its end.
    test('keeps the end and pulls the start forward', function (): void {
        makeWindowSettings(86400);
        $w = TimeWindow::clamped(0, 86400 * 30);

        expect($w->end)->toBe(86400 * 30)
            ->and($w->start)->toBe(86400 * 29)
            ->and($w->clamped)->toBeTrue()
            ->and($w->duration())->toBe(86400)
        ;
    });

    test('a maximum of zero disables the bound', function (): void {
        makeWindowSettings(0);
        $w = TimeWindow::clamped(0, 86400 * 365);

        expect($w->clamped)->toBeFalse()
            ->and($w->duration())->toBe(86400 * 365)
        ;
    });

    test('an explicit maximum overrides the configured one', function (): void {
        makeWindowSettings(86400 * 30);
        $w = TimeWindow::clamped(0, 86400 * 10, 3600);

        expect($w->clamped)->toBeTrue()
            ->and($w->duration())->toBe(3600)
        ;
    });

    test('raw() bypasses clamping for callers that bounded it themselves', function (): void {
        makeWindowSettings(3600);
        $w = TimeWindow::raw(0, 86400);

        expect($w->clamped)->toBeFalse()
            ->and($w->duration())->toBe(86400)
        ;
    });

    test('exposes the range as an nfdump -R pair', function (): void {
        makeWindowSettings(0);

        expect(TimeWindow::raw(100, 200)->toRangeOption())->toBe([100, 200]);
    });

    test('a negative range reports zero duration rather than a negative one', function (): void {
        makeWindowSettings(0);

        expect(TimeWindow::raw(200, 100)->duration())->toBe(0);
    });
});
