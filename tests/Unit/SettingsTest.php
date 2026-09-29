<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\CpuBudget;
use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\common\Settings;

// Start every test from a clean environment. The registry-backed factories read
// env vars as a fallback baseline, and the dev container sets several NFSEN_*
// vars (NFSEN_SOURCES, NFSEN_LOG_LEVEL, …) that would otherwise leak into the
// "defaults" assertions. Tests that exercise a specific var putenv() it in-body.
// TZ / NFCAPD_TZ are OS-level and left untouched.
beforeEach(function (): void {
    foreach ([...EnvRegistry::names(), ...array_keys(EnvRegistry::aliasMap())] as $name) {
        if ($name === 'TZ' || $name === 'NFCAPD_TZ') {
            continue;
        }
        putenv($name);
    }
});

describe('Settings::fromArray()', function (): void {
    test('parses all typed fields correctly', function (): void {
        $raw = [
            'general' => [
                'sources' => ['gw1', 'gw2'],
                'ports' => ['80', '443'],
                'filters' => ['proto tcp', 'dst port 53'],
                'db' => 'VictoriaMetrics',
                'processor' => 'NfDump',
            ],
            'frontend' => [
                'defaults' => [
                    'view' => 'flows',
                    'graphs' => [
                        'display' => 'protocols',
                        'datatype' => 'packets',
                        'protocols' => ['tcp', 'udp'],
                    ],
                    'flows' => ['limit' => 100],
                    'statistics' => ['order_by' => 'packets'],
                ],
            ],
            'nfdump' => [
                'binary' => '/usr/local/bin/nfdump',
                'profiles-data' => '/data/profiles',
                'profile' => 'prod',
                'max-processes' => 2,
            ],
            'log' => ['priority' => LOG_DEBUG],
            'db' => [
                'VictoriaMetrics' => ['host' => 'vm-host', 'import_years' => 5],
            ],
        ];

        $s = Settings::fromArray($raw);

        expect($s->sources)->toBe(['gw1', 'gw2'])
            ->and($s->ports)->toBe([80, 443])
            ->and($s->filters)->toBe(['proto tcp', 'dst port 53'])
            ->and($s->datasourceName)->toBe('VictoriaMetrics')
            ->and($s->processorName)->toBe('NfDump')
            ->and($s->defaultView)->toBe('flows')
            ->and($s->defaultGraphDisplay)->toBe('protocols')
            ->and($s->defaultGraphDatatype)->toBe('packets')
            ->and($s->defaultGraphProtocols)->toBe(['tcp', 'udp'])
            ->and($s->defaultFlowLimit)->toBe(100)
            ->and($s->defaultStatsOrderBy)->toBe('packets')
            ->and($s->nfdumpBinary)->toBe('/usr/local/bin/nfdump')
            ->and($s->nfdumpProfilesData)->toBe('/data/profiles')
            ->and($s->nfdumpProfile)->toBe('prod')
            ->and($s->nfdumpMaxProcesses)->toBe(2)
            ->and($s->nfdumpMaxProcessesAuto)->toBeFalse()
            ->and($s->logPriority)->toBe(LOG_DEBUG)
        ;
    });

    test('applies safe defaults for entirely missing config', function (): void {
        $s = Settings::fromArray([]);

        expect($s->sources)->toBe([])
            ->and($s->ports)->toBe([])
            ->and($s->filters)->toBe([])
            ->and($s->datasourceName)->toBe('RRD')
            ->and($s->processorName)->toBe('NfDump')
            ->and($s->defaultView)->toBe('overview')
            ->and($s->defaultGraphDisplay)->toBe('sources')
            ->and($s->defaultGraphDatatype)->toBe('traffic')
            ->and($s->defaultGraphProtocols)->toBe(['any'])
            ->and($s->defaultFlowLimit)->toBe(50)
            ->and($s->defaultStatsOrderBy)->toBe('bytes')
            ->and($s->nfdumpBinary)->toBe('/usr/local/nfdump/bin/nfdump')
            ->and($s->nfdumpProfilesData)->toBe('/var/nfdump/profiles-data')
            ->and($s->nfdumpProfile)->toBe('live')
            // Auto: a third of the cores, never below two, since the import daemon takes a slot
            // per nfdump run and a cap of one makes browsing queue behind an import.
            ->and($s->nfdumpMaxProcesses)->toBe(CpuBudget::autoProcesses(CpuBudget::cores()))
            ->and($s->nfdumpMaxProcesses)->toBeGreaterThanOrEqual(2)
            ->and($s->nfdumpMaxProcessesAuto)->toBeTrue()
            ->and($s->nfdumpWorkers)->toBe(2)
            ->and($s->logPriority)->toBe(LOG_INFO)
            ->and($s->defaultEmailSubjectTemplate)->toBe('')
            ->and($s->defaultEmailBodyTemplate)->toBe('')
            ->and($s->defaultWebhookTitleTemplate)->toBe('')
            ->and($s->defaultWebhookMessageTemplate)->toBe('')
            ->and($s->defaultRange)->toBe('24h')
            ->and($s->defaultUnit)->toBe('bits')
            ->and($s->compactTables)->toBeFalse()
            ->and($s->rdnsEnabled)->toBeTrue()
            ->and($s->topnRetentionDays)->toBe(31)
            ->and($s->geoipDb)->toBe('')
        ;
    });

    test('a max-processes of 0 or auto derives the limit from the CPU cores', function (mixed $configured): void {
        $s = Settings::fromArray(['nfdump' => ['max-processes' => $configured]]);

        expect($s->nfdumpMaxProcesses)->toBe(CpuBudget::autoProcesses(CpuBudget::cores()))
            ->and($s->nfdumpMaxProcessesAuto)->toBeTrue()
        ;
    })->with([0, 'auto', 'AUTO', -3, 'many']);

    test('an explicit max-processes is kept, also as a string', function (): void {
        expect(Settings::fromArray(['nfdump' => ['max-processes' => 1]])->nfdumpMaxProcesses)->toBe(1)
            ->and(Settings::fromArray(['nfdump' => ['max-processes' => '12']])->nfdumpMaxProcesses)->toBe(12)
            ->and(Settings::fromArray(['nfdump' => ['max-processes' => '12']])->nfdumpMaxProcessesAuto)->toBeFalse()
        ;
    });

    test('settings.php workers wins over NFSEN_NFDUMP_WORKERS and is clamped to 0 to 16', function (): void {
        putenv('NFSEN_NFDUMP_WORKERS=4');

        expect(Settings::fromArray([])->nfdumpWorkers)->toBe(4)
            ->and(Settings::fromArray(['nfdump' => ['workers' => 1]])->nfdumpWorkers)->toBe(1)
            ->and(Settings::fromArray(['nfdump' => ['workers' => 64]])->nfdumpWorkers)->toBe(16)
            ->and(Settings::fromArray(['nfdump' => ['workers' => -1]])->nfdumpWorkers)->toBe(0)
        ;
    });
});

describe('nfdump process budget from the environment', function (): void {
    test('NFSEN_NFDUMP_MAX_PROCESSES=auto derives the limit', function (): void {
        putenv('NFSEN_NFDUMP_MAX_PROCESSES=auto');
        $s = Settings::fromEnv();

        expect($s->nfdumpMaxProcesses)->toBe(CpuBudget::autoProcesses(CpuBudget::cores()))
            ->and($s->nfdumpMaxProcessesAuto)->toBeTrue()
        ;
    });

    // Existing explicit values keep working.
    test('an explicit NFSEN_NFDUMP_MAX_PROCESSES is used as it is', function (): void {
        putenv('NFSEN_NFDUMP_MAX_PROCESSES=3');
        $s = Settings::fromEnv();

        expect($s->nfdumpMaxProcesses)->toBe(3)
            ->and($s->nfdumpMaxProcessesAuto)->toBeFalse()
            ->and(Settings::fromArray([])->nfdumpMaxProcesses)->toBe(3)
        ;
    });

    test('NFSEN_NFDUMP_WORKERS reaches the settings', function (): void {
        putenv('NFSEN_NFDUMP_WORKERS=0');
        expect(Settings::fromEnv()->nfdumpWorkers)->toBe(0);

        putenv('NFSEN_NFDUMP_WORKERS=99');
        expect(Settings::fromEnv()->nfdumpWorkers)->toBe(16);
    });
});

describe('default theme (NFSEN_DEFAULT_THEME, issue #156)', function (): void {
    test('defaults to auto when nothing is configured', function (): void {
        putenv('NFSEN_DEFAULT_THEME');

        expect(Settings::fromArray([])->defaultTheme)->toBe('auto')
            ->and(Settings::fromEnv()->defaultTheme)->toBe('auto')
        ;
    });

    test('reads and lower-cases NFSEN_DEFAULT_THEME in fromEnv', function (): void {
        putenv('NFSEN_DEFAULT_THEME=Dark');
        $s = Settings::fromEnv();
        putenv('NFSEN_DEFAULT_THEME');

        expect($s->defaultTheme)->toBe('dark');
    });

    test('reads frontend.defaults.theme in fromArray', function (): void {
        putenv('NFSEN_DEFAULT_THEME');
        $s = Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'light']]]);

        expect($s->defaultTheme)->toBe('light');
    });

    test('settings.php theme key wins over the env var in fromArray', function (): void {
        putenv('NFSEN_DEFAULT_THEME=dark');
        $s = Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'light']]]);
        putenv('NFSEN_DEFAULT_THEME');

        expect($s->defaultTheme)->toBe('light');
    });

    test('falls back to env var when settings.php omits the theme key', function (): void {
        putenv('NFSEN_DEFAULT_THEME=light');
        $s = Settings::fromArray(['frontend' => ['defaults' => ['view' => 'flows']]]);
        putenv('NFSEN_DEFAULT_THEME');

        expect($s->defaultTheme)->toBe('light');
    });

    test('unknown/empty values normalize to auto', function (): void {
        putenv('NFSEN_DEFAULT_THEME');

        expect(Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'neon']]])->defaultTheme)->toBe('auto')
            ->and(Settings::normalizeTheme('  LIGHT '))->toBe('light')
            ->and(Settings::normalizeTheme(''))->toBe('auto')
        ;
    });

    test('withDefaultTheme clones and normalizes', function (): void {
        expect(Settings::fromArray([])->withDefaultTheme('DARK')->defaultTheme)->toBe('dark')
            ->and(Settings::fromArray([])->withDefaultTheme('bogus')->defaultTheme)->toBe('auto')
        ;
    });

    test('deploymentTheme keeps the configured theme through withDefaultTheme', function (): void {
        putenv('NFSEN_DEFAULT_THEME=light');
        $fromEnv = Settings::fromEnv();
        $fromArray = Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'dark']]]);
        putenv('NFSEN_DEFAULT_THEME');

        expect($fromEnv->deploymentTheme)->toBe('light')
            ->and($fromEnv->withDefaultTheme('dark')->deploymentTheme)->toBe('light')
            ->and($fromArray->deploymentTheme)->toBe('dark')
            ->and($fromArray->withDefaultTheme('auto')->deploymentTheme)->toBe('dark')
        ;
    });
});

describe('Settings::fromEnv()', function (): void {
    test('returns instance with defaults when no env vars set', function (): void {
        // The ambient environment (e.g. this app's own docker-compose.dev.yml)
        // may already export NFSEN_SOURCES/NFSEN_PORTS for the running app, so
        // clear them so this test genuinely exercises the "unset" defaults.
        putenv('NFSEN_SOURCES');
        putenv('NFSEN_PORTS');

        $s = Settings::fromEnv();

        expect($s->datasourceName)->toBe('RRD')
            ->and($s->processorName)->toBe('NfDump')
            ->and($s->nfdumpBinary)->toBe('/usr/local/nfdump/bin/nfdump')
            ->and($s->nfdumpMaxProcesses)->toBeGreaterThanOrEqual(1)
            ->and($s->sources)->toBe([])
            ->and($s->ports)->toBe([])
            ->and($s->defaultView)->toBe('overview')
            ->and($s->defaultGraphDatatype)->toBe('traffic')
            ->and($s->defaultRange)->toBe('24h')
            ->and($s->defaultUnit)->toBe('bits')
            ->and($s->compactTables)->toBeFalse()
            ->and($s->rdnsEnabled)->toBeTrue()
            ->and($s->topnRetentionDays)->toBe(31)
            ->and($s->geoipDb)->toBe('')
        ;
    });

    test('parses NFSEN_SOURCES as comma-separated list', function (): void {
        putenv('NFSEN_SOURCES=gw1,gw2, mailserver ');
        $s = Settings::fromEnv();
        putenv('NFSEN_SOURCES');

        expect($s->sources)->toBe(['gw1', 'gw2', 'mailserver']);
    });

    test('parses NFSEN_PORTS as comma-separated integers', function (): void {
        putenv('NFSEN_PORTS=80,443,22');
        $s = Settings::fromEnv();
        putenv('NFSEN_PORTS');

        expect($s->ports)->toBe([80, 443, 22]);
    });

    test('parses NFSEN_FILTERS as a JSON array', function (): void {
        putenv('NFSEN_FILTERS=["proto tcp","dst port 53"]');
        $s = Settings::fromEnv();
        putenv('NFSEN_FILTERS');

        expect($s->filters)->toBe(['proto tcp', 'dst port 53']);
    });

    test('ignores malformed NFSEN_FILTERS and returns empty array', function (): void {
        putenv('NFSEN_FILTERS=not-json');
        $s = Settings::fromEnv();
        putenv('NFSEN_FILTERS');

        expect($s->filters)->toBe([]);
    });

    test('populates RRD datasource config from env vars', function (): void {
        putenv('NFSEN_IMPORT_YEARS=7');
        putenv('NFSEN_RRD_PATH=/rrd/data');
        $s = Settings::fromEnv();
        putenv('NFSEN_IMPORT_YEARS');
        putenv('NFSEN_RRD_PATH');

        expect($s->importYears)->toBe(7)
            ->and($s->importYears())->toBe(7)
            ->and($s->datasourceConfig('RRD'))->toBe(['data_path' => '/rrd/data'])
        ;
    });

    test('RRD datasource config omits data_path when NFSEN_RRD_PATH not set', function (): void {
        putenv('NFSEN_RRD_PATH');
        $s = Settings::fromEnv();

        expect($s->datasourceConfig('RRD'))->toBe([]);
    });

    test('populates VictoriaMetrics datasource config from env vars', function (): void {
        putenv('VM_HOST=vm.local');
        putenv('VM_PORT=9428');
        $s = Settings::fromEnv();
        putenv('VM_HOST');
        putenv('VM_PORT');

        expect($s->datasourceConfig('VictoriaMetrics')['host'])->toBe('vm.local')
            ->and($s->datasourceConfig('VictoriaMetrics')['port'])->toBe(9428)
            ->and($s->datasourceConfig('VictoriaMetrics'))->not->toHaveKey('import_years')
        ;
    });
});

describe('Settings with…() fluent mutators', function (): void {
    test('with…() returns new instance without mutating original', function (): void {
        $original = Settings::fromArray([]);
        $modified = $original->withSources(['src1'])->withPorts([22, 80]);

        expect($original->sources)->toBe([])
            ->and($original->ports)->toBe([])
            ->and($modified->sources)->toBe(['src1'])
            ->and($modified->ports)->toBe([22, 80])
        ;
    });

    test('withNfdumpMaxProcesses(0) is auto, a positive value is kept', function (): void {
        $auto = Settings::fromArray(['nfdump' => ['max-processes' => 3]])->withNfdumpMaxProcesses(0);
        $fixed = Settings::fromArray([])->withNfdumpMaxProcesses(5);

        expect($auto->nfdumpMaxProcesses)->toBe(CpuBudget::autoProcesses(CpuBudget::cores()))
            ->and($auto->nfdumpMaxProcessesAuto)->toBeTrue()
            ->and($fixed->nfdumpMaxProcesses)->toBe(5)
            ->and($fixed->nfdumpMaxProcessesAuto)->toBeFalse()
        ;
    });

    test('withNfdumpWorkers clamps to 0 to 16', function (): void {
        expect(Settings::fromArray([])->withNfdumpWorkers(3)->nfdumpWorkers)->toBe(3)
            ->and(Settings::fromArray([])->withNfdumpWorkers(17)->nfdumpWorkers)->toBe(16)
            ->and(Settings::fromArray([])->withNfdumpWorkers(-2)->nfdumpWorkers)->toBe(0)
        ;
    });

    test('withDatasourceConfig stores and returns config sub-array', function (): void {
        $s = Settings::fromArray([])
            ->withDatasourceName('RRD')
            ->withDatasourceConfig('RRD', ['import_years' => 7, 'data_path' => '/rrd'])
        ;

        expect($s->datasourceConfig('RRD'))->toBe(['import_years' => 7, 'data_path' => '/rrd']);
    });

    test('withDefaultWebhookTitleTemplate/withDefaultEmailSubjectTemplate store the given template', function (): void {
        $s = Settings::fromArray([])
            ->withDefaultEmailSubjectTemplate('Custom {rule}')
            ->withDefaultEmailBodyTemplate('Body {value}')
            ->withDefaultWebhookTitleTemplate('Title {rule}')
            ->withDefaultWebhookMessageTemplate('Message {value}')
        ;

        expect($s->defaultEmailSubjectTemplate)->toBe('Custom {rule}')
            ->and($s->defaultEmailBodyTemplate)->toBe('Body {value}')
            ->and($s->defaultWebhookTitleTemplate)->toBe('Title {rule}')
            ->and($s->defaultWebhookMessageTemplate)->toBe('Message {value}')
        ;
    });

    test('each with…() method is fluent and chainable', function (): void {
        $s = Settings::fromArray([])
            ->withSources(['a'])
            ->withFilters(['proto tcp'])
            ->withLogPriority(LOG_WARNING)
            ->withNfdumpProfile('live2')
        ;

        expect($s->sources)->toBe(['a'])
            ->and($s->filters)->toBe(['proto tcp'])
            ->and($s->logPriority)->toBe(LOG_WARNING)
            ->and($s->nfdumpProfile)->toBe('live2')
        ;
    });
});

describe('redesign settings (preferences and environment)', function (): void {
    test('both factories read NFSEN_TOPN_RETENTION_DAYS and NFSEN_GEOIP_DB', function (): void {
        putenv('NFSEN_TOPN_RETENTION_DAYS=7');
        putenv('NFSEN_GEOIP_DB=/data/GeoLite2-City.mmdb');
        $fromEnv = Settings::fromEnv();
        $fromArray = Settings::fromArray(['general' => ['sources' => ['gw1']]]);
        putenv('NFSEN_TOPN_RETENTION_DAYS');
        putenv('NFSEN_GEOIP_DB');

        expect($fromEnv->topnRetentionDays)->toBe(7)
            ->and($fromEnv->geoipDb)->toBe('/data/GeoLite2-City.mmdb')
            ->and($fromArray->topnRetentionDays)->toBe(7)
            ->and($fromArray->geoipDb)->toBe('/data/GeoLite2-City.mmdb')
        ;
    });

    test('a retention of 0 disables collection and a negative one clamps to 0', function (): void {
        putenv('NFSEN_TOPN_RETENTION_DAYS=0');
        $off = Settings::fromEnv();
        putenv('NFSEN_TOPN_RETENTION_DAYS=-5');
        $negative = Settings::fromEnv();
        putenv('NFSEN_TOPN_RETENTION_DAYS');

        expect($off->topnRetentionDays)->toBe(0)
            ->and($negative->topnRetentionDays)->toBe(0)
            ->and(Settings::fromEnv()->withTopnRetentionDays(-1)->topnRetentionDays)->toBe(0)
        ;
    });

    test('with...() mutators set and normalise the new fields', function (): void {
        $original = Settings::fromEnv();
        $s = $original
            ->withDefaultRange('7D')
            ->withDefaultUnit('Bytes')
            ->withCompactTables(true)
            ->withRdnsEnabled(false)
            ->withTopnRetentionDays(14)
            ->withGeoipDb('/geo.mmdb')
        ;

        expect($s->defaultRange)->toBe('7d')
            ->and($s->defaultUnit)->toBe('bytes')
            ->and($s->compactTables)->toBeTrue()
            ->and($s->rdnsEnabled)->toBeFalse()
            ->and($s->topnRetentionDays)->toBe(14)
            ->and($s->geoipDb)->toBe('/geo.mmdb')
            ->and($original->defaultRange)->toBe('24h')
            ->and($original->rdnsEnabled)->toBeTrue()
            ->and($original->withDefaultRange('2w')->defaultRange)->toBe('24h')
            ->and($original->withDefaultUnit('nibbles')->defaultUnit)->toBe('bits')
        ;
    });

    test('the enum normalisers', function (): void {
        expect(array_map(Settings::normalizeRange(...), ['1h', '24h', '7d', '30d', '1y', ' 1Y ', '2h', '', null]))
            ->toBe(['1h', '24h', '7d', '30d', '1y', '1y', '24h', '24h', '24h'])
            ->and(array_map(Settings::normalizeUnit(...), ['bits', 'bytes', 'BYTES', 'octets', 42]))
            ->toBe(['bits', 'bytes', 'bytes', 'bits', 'bits'])
            ->and(array_map(Settings::normalizeGraphDatatype(...), ['traffic', 'packets', 'flows', 'bytes', 'Packets', 'nonsense']))
            ->toBe(['traffic', 'packets', 'flows', 'traffic', 'packets', 'traffic'])
            ->and(Settings::legacyUnit('bytes'))->toBe('bytes')
            ->and(Settings::legacyUnit('traffic'))->toBe('bits')
            ->and(Settings::legacyUnit(null))->toBe('bits')
        ;
    });

    test("the legacy 'bytes' datatype in settings.php becomes traffic in bytes", function (): void {
        $s = Settings::fromArray(['frontend' => ['defaults' => ['graphs' => ['datatype' => 'bytes']]]]);

        expect($s->defaultGraphDatatype)->toBe('traffic')
            ->and($s->defaultUnit)->toBe('bytes')
            ->and(Settings::fromEnv()->withDefaultGraphDatatype('bytes')->defaultGraphDatatype)->toBe('traffic')
            ->and(Settings::fromEnv()->withDefaultGraphDatatype('flows')->defaultGraphDatatype)->toBe('flows')
        ;
    });

    test('defaultView holds a page id, mapped from the legacy view ids (D2)', function (): void {
        expect(Settings::fromArray(['frontend' => ['defaults' => ['view' => 'statistics']]])->defaultView)->toBe('talkers')
            ->and(Settings::fromEnv()->withDefaultView('sankey')->defaultView)->toBe('conversations')
            ->and(Settings::fromEnv()->withDefaultView('graphs')->defaultView)->toBe('overview')
            ->and(Settings::fromEnv()->withDefaultView('investigate')->defaultView)->toBe('flows')
            ->and(Settings::fromEnv()->withDefaultView('Health')->defaultView)->toBe('health')
            ->and(Settings::fromEnv()->withDefaultView('dashboard')->defaultView)->toBe('overview')
            ->and(Settings::fromEnv()->defaultView)->toBe('overview')
        ;
    });

    test('normalizeView maps every view id and rejects anything else', function (): void {
        expect(array_map(Settings::normalizeView(...), ['graphs', 'flows', 'statistics', 'sankey', 'settings', 'investigate']))
            ->toBe(['overview', 'flows', 'talkers', 'conversations', 'settings', 'flows'])
            ->and(array_map(Settings::normalizeView(...), ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings']))
            ->toBe(['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'])
            ->and(Settings::normalizeView(null))->toBe('overview')
            ->and(Settings::normalizeView(['graphs']))->toBe('overview')
        ;
    });
});

describe('Settings computed methods', function (): void {
    test('datasourceClass() returns correct FQN for known datasources', function (): void {
        expect(Settings::fromArray(['general' => ['db' => 'RRD']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\Rrd')
        ;

        expect(Settings::fromArray(['general' => ['db' => 'VictoriaMetrics']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\VictoriaMetrics')
        ;
    });

    test('datasourceClass() is case-insensitive', function (): void {
        expect(Settings::fromArray(['general' => ['db' => 'rrd']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\Rrd')
        ;
    });

    test('datasourceClass() throws for unknown datasource', function (): void {
        expect(fn () => Settings::fromArray(['general' => ['db' => 'MySQL']])->datasourceClass())
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    test('processorClass() returns correct FQN', function (): void {
        expect(Settings::fromArray([])->processorClass())
            ->toBe('mbolli\\nfsen_ng\\processor\\Nfdump')
        ;
    });

    test('processorClass() throws for unknown processor', function (): void {
        expect(fn () => Settings::fromArray(['general' => ['processor' => 'Unknown']])->processorClass())
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    test('importYears() returns configured value for active datasource', function (): void {
        $s = Settings::fromArray([
            'general' => ['db' => 'RRD'],
            'db' => ['RRD' => ['import_years' => 7]],
        ]);

        expect($s->importYears)->toBe(7)
            ->and($s->importYears())->toBe(7)
        ;
    });

    test('importYears() returns default 3 when not configured', function (): void {
        $s = Settings::fromArray(['general' => ['db' => 'RRD']]);
        expect($s->importYears)->toBe(3);
    });

    test('importYears is clamped to minimum 1', function (): void {
        $s = Settings::fromArray([
            'general' => ['db' => 'RRD'],
            'db' => ['RRD' => ['import_years' => 0]],
        ]);
        expect($s->importYears)->toBe(1);
    });

    test('datasourceConfig() returns empty array for unknown datasource', function (): void {
        $s = Settings::fromArray([]);
        expect($s->datasourceConfig('Nonexistent'))->toBe([]);
    });
});

describe('Settings static helpers', function (): void {
    test('logLevelFromString() converts known names', function (): void {
        expect(Settings::logLevelFromString('DEBUG'))->toBe(LOG_DEBUG)
            ->and(Settings::logLevelFromString('info'))->toBe(LOG_INFO)
            ->and(Settings::logLevelFromString('LOG_WARNING'))->toBe(LOG_WARNING)
            ->and(Settings::logLevelFromString('ERROR'))->toBe(LOG_ERR)
        ;
    });

    test('logLevelFromString() returns LOG_INFO for unknown name', function (): void {
        expect(Settings::logLevelFromString('NONSENSE'))->toBe(LOG_INFO);
    });

    test('logLevelToString() converts known constants', function (): void {
        expect(Settings::logLevelToString(LOG_DEBUG))->toBe('debug')
            ->and(Settings::logLevelToString(LOG_INFO))->toBe('info')
            ->and(Settings::logLevelToString(LOG_WARNING))->toBe('warning')
            ->and(Settings::logLevelToString(LOG_ERR))->toBe('error')
        ;
    });

    test('logLevelToString() returns "info" for unknown value', function (): void {
        expect(Settings::logLevelToString(999))->toBe('info');
    });
});
