<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;

// GraphActions::normalizeSources() reads Config::$settings->sources for the fallback.
beforeAll(function (): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80, 443],
            'sources' => ['gateway', 'swi6', 'core'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/test-profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ]);
});

describe('GraphActions::normalizeSources', function (): void {
    test('honours an explicit selection', function (): void {
        expect(GraphActions::normalizeSources(['swi6'], 'sources'))->toBe(['swi6']);
        expect(GraphActions::normalizeSources(['gateway', 'core'], 'sources'))->toBe(['gateway', 'core']);
    });

    // Regression test for issue #160: a malformed client value must never reach
    // the RRD path builder, where '' turns into a bare "<profile>/.rrd" filename.
    test('drops empty entries', function (): void {
        expect(GraphActions::normalizeSources(['', 'swi6'], 'sources'))->toBe(['swi6']);
        expect(GraphActions::normalizeSources([' '], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    test('falls back to all configured sources for an empty selection', function (): void {
        expect(GraphActions::normalizeSources([], 'sources'))->toBe(['gateway', 'swi6', 'core']);
        // (array) null / (array) '' — what Signal::array() yields for a scalar signal
        expect(GraphActions::normalizeSources([''], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    test('expands the "any" sentinel outside the ports view', function (): void {
        expect(GraphActions::normalizeSources(['any'], 'sources'))->toBe(['gateway', 'swi6', 'core']);
        expect(GraphActions::normalizeSources(['any'], 'protocols'))->toBe(['gateway', 'swi6', 'core']);
        expect(GraphActions::normalizeSources(['any', 'core'], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    // In the ports view "any" is a real selection: it maps to the cross-source
    // aggregate RRD (<profile>/<port>.rrd), so it must be preserved.
    test('keeps the "any" sentinel in the ports view', function (): void {
        expect(GraphActions::normalizeSources(['any'], 'ports'))->toBe(['any']);
    });

    test('coerces non-string entries', function (): void {
        expect(GraphActions::normalizeSources([1, 'core'], 'sources'))->toBe(['1', 'core']);
    });
});
