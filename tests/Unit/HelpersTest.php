<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\Helpers;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;

// Helpers::resolveSources() reads Config::$settings->sources for the fallback,
// so a minimal config with several sources is required.
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

describe('Helpers::resolveSources', function (): void {
    // Regression test for issue #155: selecting a single source must query only
    // that source, not every configured source.
    test('honours a single explicitly selected source', function (): void {
        expect(Helpers::resolveSources(['swi6']))->toBe(['swi6']);
    });

    test('honours multiple explicitly selected sources verbatim', function (): void {
        expect(Helpers::resolveSources(['gateway', 'core']))->toBe(['gateway', 'core']);
    });

    test('falls back to all configured sources when nothing is selected', function (): void {
        expect(Helpers::resolveSources([]))->toBe(['gateway', 'swi6', 'core']);
    });

    test('falls back to all configured sources for the "any" sentinel', function (): void {
        expect(Helpers::resolveSources(['any']))->toBe(['gateway', 'swi6', 'core']);
    });

    test('"any" wins even when combined with explicit sources', function (): void {
        expect(Helpers::resolveSources(['any', 'gateway']))->toBe(['gateway', 'swi6', 'core']);
    });
});
