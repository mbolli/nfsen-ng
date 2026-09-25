<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\common\IpLookup;

// Every test starts from a clean environment; the dev container sets several
// NFSEN_* vars that would otherwise bleed into these assertions.
beforeEach(function (): void {
    foreach ([...EnvRegistry::names(), ...array_keys(EnvRegistry::aliasMap())] as $name) {
        if ($name === 'TZ' || $name === 'NFCAPD_TZ') {
            continue;
        }
        putenv($name);
    }
});

describe('EnvRegistry::value()', function (): void {
    test('returns the typed default when unset', function (): void {
        expect(EnvRegistry::value('NFSEN_IMPORT_YEARS'))->toBe(3)
            ->and(EnvRegistry::value('NFSEN_DEFAULT_THEME'))->toBe('auto')
            ->and(EnvRegistry::value('NFSEN_SKIP_DAEMON'))->toBeFalse()
            ->and(EnvRegistry::value('NFSEN_SOURCES'))->toBe([])
            ->and(EnvRegistry::value('NFSEN_VM_HOST'))->toBe('victoriametrics')
            ->and(EnvRegistry::value('NFSEN_IPINFO_URL'))->toBe(IpLookup::DEFAULT_GEO_URL)
        ;
    });

    test('parses each type from the raw string', function (): void {
        putenv('NFSEN_SOURCES=gw1, gw2 ,, mailserver');
        putenv('NFSEN_PORTS=80, 443 ,22');
        putenv('NFSEN_FILTERS=["proto tcp","dst port 53"]');
        putenv('NFSEN_SKIP_DAEMON=yes');
        putenv('NFSEN_VM_PORT=9428');

        expect(EnvRegistry::value('NFSEN_SOURCES'))->toBe(['gw1', 'gw2', 'mailserver'])
            ->and(EnvRegistry::value('NFSEN_PORTS'))->toBe([80, 443, 22])
            ->and(EnvRegistry::value('NFSEN_FILTERS'))->toBe(['proto tcp', 'dst port 53'])
            ->and(EnvRegistry::value('NFSEN_SKIP_DAEMON'))->toBeTrue()
            ->and(EnvRegistry::value('NFSEN_VM_PORT'))->toBe(9428)
        ;
    });

    test('falls back to default on invalid input, and clamps to min', function (): void {
        putenv('NFSEN_IMPORT_YEARS=not-a-number');
        putenv('NFSEN_DEFAULT_THEME=neon');
        putenv('NFSEN_NFDUMP_MAX_PROCESSES=0');
        putenv('NFSEN_FILTERS=not-json');

        expect(EnvRegistry::value('NFSEN_IMPORT_YEARS'))->toBe(3)
            ->and(EnvRegistry::value('NFSEN_DEFAULT_THEME'))->toBe('auto')
            ->and(EnvRegistry::value('NFSEN_NFDUMP_MAX_PROCESSES'))->toBe(1)
            ->and(EnvRegistry::value('NFSEN_FILTERS'))->toBe([])
        ;
    });

    test('enum match is case-insensitive and returns the canonical form', function (): void {
        putenv('NFSEN_DATASOURCE=victoriametrics');
        putenv('NFSEN_DEFAULT_THEME=DARK');

        expect(EnvRegistry::value('NFSEN_DATASOURCE'))->toBe('VictoriaMetrics')
            ->and(EnvRegistry::value('NFSEN_DEFAULT_THEME'))->toBe('dark')
        ;
    });

    test('resolves a deprecated alias when the canonical name is unset', function (): void {
        putenv('VM_HOST=legacy.example');
        putenv('VM_PORT=7000');

        expect(EnvRegistry::value('NFSEN_VM_HOST'))->toBe('legacy.example')
            ->and(EnvRegistry::value('NFSEN_VM_PORT'))->toBe(7000)
            ->and(EnvRegistry::isSet('NFSEN_VM_HOST'))->toBeTrue()
        ;
    });

    test('canonical name wins over its alias', function (): void {
        putenv('NFSEN_VM_HOST=canonical.example');
        putenv('VM_HOST=legacy.example');

        expect(EnvRegistry::value('NFSEN_VM_HOST'))->toBe('canonical.example');
    });

    test('throws for an unregistered variable', function (): void {
        expect(fn () => EnvRegistry::value('NFSEN_NOPE'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });
});

describe('EnvRegistry::issues()', function (): void {
    test('is empty for a clean environment', function (): void {
        expect(EnvRegistry::issues())->toBe([]);
    });

    test('flags an invalid value', function (): void {
        putenv('NFSEN_DEFAULT_THEME=neon');
        $messages = array_column(EnvRegistry::issues(), 'message');
        $theme = array_values(array_filter($messages, fn (string $m) => str_starts_with($m, 'NFSEN_DEFAULT_THEME: ')));

        expect($theme)->toHaveCount(1)
            ->and($theme[0])->toContain("invalid value 'neon' (expected one of auto, dark, light)")
            ->and($theme[0])->toContain("falling back to default 'auto'")
        ;
    });

    test('flags a deprecated alias in use', function (): void {
        putenv('VM_HOST=legacy.example');
        $issues = array_values(array_filter(EnvRegistry::issues(), fn (array $i) => $i['name'] === 'VM_HOST'));

        expect($issues)->toHaveCount(1)
            ->and($issues[0]['message'])->toBe('VM_HOST is deprecated. Rename it to NFSEN_VM_HOST (still honoured for now).')
        ;
    });

    test('flags an unknown NFSEN_ variable as a typo but ignores other namespaces', function (): void {
        putenv('NFSEN_TYPOED=1');
        putenv('SOME_OTHER_VAR=1');
        $names = array_column(EnvRegistry::issues(), 'name');
        expect($names)->toContain('NFSEN_TYPOED')
            ->and($names)->not->toContain('SOME_OTHER_VAR')
        ;
        putenv('SOME_OTHER_VAR');
    });

    test('an out-of-range retention is clamped silently, a non-number is flagged', function (): void {
        putenv('NFSEN_TOPN_RETENTION_DAYS=-3');
        expect(EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS'))->toBe(0)
            ->and(array_column(EnvRegistry::issues(), 'name'))->not->toContain('NFSEN_TOPN_RETENTION_DAYS')
        ;

        putenv('NFSEN_TOPN_RETENTION_DAYS=a month');
        expect(EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS'))->toBe(31)
            ->and(array_column(EnvRegistry::issues(), 'name'))->toContain('NFSEN_TOPN_RETENTION_DAYS')
        ;
    });
});

describe('redesign variables', function (): void {
    test('NFSEN_TOPN_RETENTION_DAYS is a daemon int, min 0, default 31', function (): void {
        $var = EnvRegistry::table()['NFSEN_TOPN_RETENTION_DAYS'];

        expect($var->group)->toBe('daemon')
            ->and($var->type)->toBe('int')
            ->and($var->min)->toBe(0)
            ->and($var->default)->toBe(31)
            ->and($var->doc)->toContain('0 disables collection')
            ->and(EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS'))->toBe(31)
        ;

        putenv('NFSEN_TOPN_RETENTION_DAYS=90');
        expect(EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS'))->toBe(90);
    });

    test('NFSEN_GEOIP_DB is an integrations path, empty by default', function (): void {
        $var = EnvRegistry::table()['NFSEN_GEOIP_DB'];

        expect($var->group)->toBe('integrations')
            ->and($var->type)->toBe('string')
            ->and($var->format)->toBe('path')
            ->and($var->default)->toBe('')
            ->and($var->doc)->toContain('.mmdb')
            ->and(EnvRegistry::value('NFSEN_GEOIP_DB'))->toBe('')
        ;

        putenv('NFSEN_GEOIP_DB=/data/GeoLite2-Country.mmdb');
        expect(EnvRegistry::value('NFSEN_GEOIP_DB'))->toBe('/data/GeoLite2-Country.mmdb');
    });

    test('the NFSEN_STATE_DIR description mentions the SQLite store', function (): void {
        expect(EnvRegistry::table()['NFSEN_STATE_DIR']->doc)
            ->toContain('nfsen-ng.sqlite')
            ->toContain('preferences.json')
        ;
    });

    test('no registered description or registry message uses a long dash', function (): void {
        putenv('VM_HOST=legacy.example');
        putenv('NFSEN_TYPOED=1');
        $texts = [
            ...array_map(fn ($var) => $var->doc, array_values(EnvRegistry::table())),
            ...array_column(EnvRegistry::issues(), 'message'),
        ];
        putenv('NFSEN_TYPOED');
        putenv('VM_HOST');

        expect(preg_grep('/[\x{2013}\x{2014}]/u', $texts))->toBe([]);
    });
});
