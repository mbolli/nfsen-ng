<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;

// The factories fall back to env vars, which the dev container sets.
beforeEach(function (): void {
    foreach ([...EnvRegistry::names(), ...array_keys(EnvRegistry::aliasMap())] as $name) {
        if ($name === 'TZ' || $name === 'NFCAPD_TZ') {
            continue;
        }
        putenv($name);
    }
});

/** A preferences.json as written before the redesign: none of the new keys. */
function userPreferencesTestLegacy(): array {
    return [
        'defaultView' => 'flows',
        'defaultGraphDisplay' => 'protocols',
        'defaultGraphDatatype' => 'packets',
        'defaultGraphProtocols' => ['tcp'],
        'defaultFlowLimit' => 100,
        'defaultStatsOrderBy' => 'packets',
        'filters' => ['proto tcp'],
        'logPriority' => LOG_DEBUG,
        'selectedProfile' => 'work',
        'alerts' => [],
        'displayTimezone' => 'server',
        'defaultEmailSubjectTemplate' => 'Subject {rule}',
        'defaultEmailBodyTemplate' => '',
        'defaultWebhookTitleTemplate' => '',
        'defaultWebhookMessageTemplate' => '',
    ];
}

describe('new preference fields', function (): void {
    test('have their defaults when constructed or read from an empty array', function (): void {
        foreach ([UserPreferences::fromArray([]), new UserPreferences('graphs', 'sources', 'traffic', ['any'], 50, 'bytes', [], LOG_INFO)] as $prefs) {
            expect($prefs->theme)->toBe('')
                ->and($prefs->defaultRange)->toBe('24h')
                ->and($prefs->defaultUnit)->toBe('bits')
                ->and($prefs->compactTables)->toBeFalse()
                ->and($prefs->rdnsEnabled)->toBeTrue()
            ;
        }
    });

    test('round-trip through toArray and fromArray', function (): void {
        $prefs = UserPreferences::fromArray([
            ...userPreferencesTestLegacy(),
            'theme' => 'dark',
            'defaultRange' => '7d',
            'defaultUnit' => 'bytes',
            'compactTables' => true,
            'rdnsEnabled' => false,
        ]);
        $again = UserPreferences::fromArray($prefs->toArray());

        expect($again->toArray())->toBe($prefs->toArray())
            ->and($again->theme)->toBe('dark')
            ->and($again->defaultRange)->toBe('7d')
            ->and($again->defaultUnit)->toBe('bytes')
            ->and($again->compactTables)->toBeTrue()
            ->and($again->rdnsEnabled)->toBeFalse()
        ;
    });

    test('round-trip through a preferences.json file', function (): void {
        $path = sys_get_temp_dir() . '/nfsen-prefs-' . bin2hex(random_bytes(6)) . '/preferences.json';
        $prefs = UserPreferences::fromArray(['theme' => 'system', 'defaultRange' => '1y', 'compactTables' => true]);

        try {
            $prefs->save($path);
            $loaded = UserPreferences::load($path);
            $json = json_decode((string) file_get_contents($path), true);
        } finally {
            @unlink($path);
            @rmdir(dirname($path));
        }

        expect($loaded?->toArray())->toBe($prefs->toArray())
            ->and($json)->toMatchArray(['theme' => 'system', 'defaultRange' => '1y', 'defaultUnit' => 'bits', 'compactTables' => true, 'rdnsEnabled' => true])
        ;
    });

    test('an old preferences.json loads with the defaults and keeps its values', function (): void {
        $prefs = UserPreferences::fromArray(userPreferencesTestLegacy());

        expect($prefs->theme)->toBe('')
            ->and($prefs->defaultRange)->toBe('24h')
            ->and($prefs->defaultUnit)->toBe('bits')
            ->and($prefs->compactTables)->toBeFalse()
            ->and($prefs->rdnsEnabled)->toBeTrue()
            ->and($prefs->defaultView)->toBe('flows')
            ->and($prefs->defaultGraphDatatype)->toBe('packets')
            ->and($prefs->selectedProfile)->toBe('work')
            ->and($prefs->filters)->toBe(['proto tcp'])
            ->and($prefs->displayTimezone)->toBe('server')
            ->and($prefs->defaultEmailSubjectTemplate)->toBe('Subject {rule}')
        ;
    });

    test('invalid values fall back to the defaults', function (): void {
        $prefs = UserPreferences::fromArray([
            'theme' => 'neon',
            'defaultRange' => '2w',
            'defaultUnit' => 'nibbles',
            'compactTables' => 'sometimes',
            'rdnsEnabled' => ['yes'],
        ]);

        expect($prefs->theme)->toBe('')
            ->and($prefs->defaultRange)->toBe('24h')
            ->and($prefs->defaultUnit)->toBe('bits')
            ->and($prefs->compactTables)->toBeFalse()
            ->and($prefs->rdnsEnabled)->toBeTrue()
        ;
    });

    test('hand-edited values are normalised', function (): void {
        $prefs = UserPreferences::fromArray([
            'theme' => ' Light ',
            'defaultRange' => '30D',
            'defaultUnit' => 'BYTES',
            'compactTables' => 'on',
            'rdnsEnabled' => '0',
        ]);

        expect($prefs->theme)->toBe('light')
            ->and($prefs->defaultRange)->toBe('30d')
            ->and($prefs->defaultUnit)->toBe('bytes')
            ->and($prefs->compactTables)->toBeTrue()
            ->and($prefs->rdnsEnabled)->toBeFalse()
            ->and(UserPreferences::normalizeTheme('auto'))->toBe('system')
            ->and(UserPreferences::normalizeTheme(null))->toBe('')
        ;
    });
});

describe('legacy graph datatype', function (): void {
    test("'bytes' becomes traffic in bytes when no unit was saved", function (): void {
        $prefs = UserPreferences::fromArray(['defaultGraphDatatype' => 'bytes']);

        expect($prefs->defaultGraphDatatype)->toBe('traffic')
            ->and($prefs->defaultUnit)->toBe('bytes')
        ;
    });

    test('a saved unit wins over the legacy mapping', function (): void {
        $prefs = UserPreferences::fromArray(['defaultGraphDatatype' => 'bytes', 'defaultUnit' => 'bits']);

        expect($prefs->defaultGraphDatatype)->toBe('traffic')
            ->and($prefs->defaultUnit)->toBe('bits')
        ;
    });

    test('other datatypes keep the bits default', function (): void {
        expect(UserPreferences::fromArray(['defaultGraphDatatype' => 'flows'])->defaultUnit)->toBe('bits')
            ->and(UserPreferences::fromArray(['defaultGraphDatatype' => 'flows'])->defaultGraphDatatype)->toBe('flows')
        ;
    });
});

describe('UserPreferences::applyTo()', function (): void {
    test("theme '' keeps the deployment default", function (): void {
        putenv('NFSEN_DEFAULT_THEME=dark');
        $base = Settings::fromEnv();
        putenv('NFSEN_DEFAULT_THEME');

        expect(UserPreferences::fromArray(['theme' => ''])->applyTo($base)->defaultTheme)->toBe('dark');
    });

    test("theme '' restores the deployment default on already overlaid settings", function (): void {
        // The save actions apply the new preferences on top of Config::$settings, not a fresh base.
        putenv('NFSEN_DEFAULT_THEME=light');
        $settings = Settings::fromEnv();
        putenv('NFSEN_DEFAULT_THEME');

        $settings = UserPreferences::fromArray(['theme' => 'dark'])->applyTo($settings);
        expect($settings->defaultTheme)->toBe('dark');

        $settings = UserPreferences::fromArray(['theme' => ''])->applyTo($settings);
        expect($settings->defaultTheme)->toBe('light')
            ->and($settings->deploymentTheme)->toBe('light')
        ;

        $settings = UserPreferences::fromArray(['theme' => 'system'])->applyTo($settings);
        expect(UserPreferences::fromArray(['theme' => ''])->applyTo($settings)->defaultTheme)->toBe('light');
    });

    test('theme system maps to auto, light and dark apply as they are', function (): void {
        putenv('NFSEN_DEFAULT_THEME=dark');
        $base = Settings::fromEnv();
        putenv('NFSEN_DEFAULT_THEME');

        expect(UserPreferences::fromArray(['theme' => 'system'])->applyTo($base)->defaultTheme)->toBe('auto')
            ->and(UserPreferences::fromArray(['theme' => 'light'])->applyTo($base)->defaultTheme)->toBe('light')
            ->and(UserPreferences::fromArray(['theme' => 'dark'])->applyTo(Settings::fromEnv())->defaultTheme)->toBe('dark')
        ;
    });

    test('applies the new fields to Settings', function (): void {
        $settings = UserPreferences::fromArray([
            'defaultRange' => '1h',
            'defaultUnit' => 'bytes',
            'compactTables' => true,
            'rdnsEnabled' => false,
        ])->applyTo(Settings::fromEnv());

        expect($settings->defaultRange)->toBe('1h')
            ->and($settings->defaultUnit)->toBe('bytes')
            ->and($settings->compactTables)->toBeTrue()
            ->and($settings->rdnsEnabled)->toBeFalse()
        ;
    });

    test('still applies the filters and the existing fields', function (): void {
        $settings = UserPreferences::fromArray(userPreferencesTestLegacy())->applyTo(Settings::fromEnv());

        expect($settings->filters)->toBe(['proto tcp'])
            ->and($settings->defaultView)->toBe('flows')
            ->and($settings->defaultGraphDisplay)->toBe('protocols')
            ->and($settings->defaultGraphDatatype)->toBe('packets')
            ->and($settings->defaultFlowLimit)->toBe(100)
            ->and($settings->logPriority)->toBe(LOG_DEBUG)
            ->and($settings->displayTimezone)->toBe('server')
            ->and($settings->defaultEmailSubjectTemplate)->toBe('Subject {rule}')
        ;
    });

    test('leaves the environment-only settings alone', function (): void {
        putenv('NFSEN_TOPN_RETENTION_DAYS=7');
        putenv('NFSEN_GEOIP_DB=/data/GeoLite2-City.mmdb');
        $settings = UserPreferences::fromArray(userPreferencesTestLegacy())->applyTo(Settings::fromEnv());
        putenv('NFSEN_TOPN_RETENTION_DAYS');
        putenv('NFSEN_GEOIP_DB');

        expect($settings->topnRetentionDays)->toBe(7)
            ->and($settings->geoipDb)->toBe('/data/GeoLite2-City.mmdb')
        ;
    });
});

describe('the merge pattern', function (): void {
    test('array_merge over toArray keeps selectedProfile, the rules and the new fields', function (): void {
        $existing = UserPreferences::fromArray([
            ...userPreferencesTestLegacy(),
            'theme' => 'light',
            'rdnsEnabled' => false,
            'alerts' => [['id' => 'r1', 'name' => 'Spike', 'metric' => 'bytes', 'operator' => '>', 'thresholdValue' => 10]],
        ]);

        $updated = UserPreferences::fromArray(array_merge($existing->toArray(), [
            'defaultView' => 'graphs',
            'defaultRange' => '7d',
        ]));

        expect($updated->selectedProfile)->toBe('work')
            ->and($updated->theme)->toBe('light')
            ->and($updated->rdnsEnabled)->toBeFalse()
            ->and($updated->defaultView)->toBe('graphs')
            ->and($updated->defaultRange)->toBe('7d')
            ->and(array_map(fn (AlertRule $r) => $r->id, $updated->alerts))->toBe(['r1'])
        ;
    });

    test('withSelectedProfile keeps the new fields', function (): void {
        $prefs = UserPreferences::fromArray(['theme' => 'dark', 'compactTables' => true, 'defaultUnit' => 'bytes'])
            ->withSelectedProfile('other')
        ;

        expect($prefs->selectedProfile)->toBe('other')
            ->and($prefs->theme)->toBe('dark')
            ->and($prefs->compactTables)->toBeTrue()
            ->and($prefs->defaultUnit)->toBe('bytes')
        ;
    });
});
