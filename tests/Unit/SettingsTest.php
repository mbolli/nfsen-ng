<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Settings;

describe('Settings::fromArray()', function () {
    test('parses all typed fields correctly', function () {
        $raw = [
            'general' => [
                'sources'   => ['gw1', 'gw2'],
                'ports'     => ['80', '443'],
                'filters'   => ['proto tcp', 'dst port 53'],
                'db'        => 'VictoriaMetrics',
                'processor' => 'NfDump',
            ],
            'frontend' => [
                'defaults' => [
                    'view'  => 'flows',
                    'graphs' => [
                        'display'   => 'protocols',
                        'datatype'  => 'packets',
                        'protocols' => ['tcp', 'udp'],
                    ],
                    'flows'      => ['limit' => 100],
                    'statistics' => ['order_by' => 'packets'],
                ],
            ],
            'nfdump' => [
                'binary'        => '/usr/local/bin/nfdump',
                'profiles-data' => '/data/profiles',
                'profile'       => 'prod',
                'max-processes' => 2,
            ],
            'log' => ['priority' => LOG_DEBUG],
            'db'  => [
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
            ->and($s->logPriority)->toBe(LOG_DEBUG);
    });

    test('applies safe defaults for entirely missing config', function () {
        $s = Settings::fromArray([]);

        expect($s->sources)->toBe([])
            ->and($s->ports)->toBe([])
            ->and($s->filters)->toBe([])
            ->and($s->datasourceName)->toBe('RRD')
            ->and($s->processorName)->toBe('NfDump')
            ->and($s->defaultView)->toBe('graphs')
            ->and($s->defaultGraphDisplay)->toBe('sources')
            ->and($s->defaultGraphDatatype)->toBe('traffic')
            ->and($s->defaultGraphProtocols)->toBe(['any'])
            ->and($s->defaultFlowLimit)->toBe(50)
            ->and($s->defaultStatsOrderBy)->toBe('bytes')
            ->and($s->nfdumpBinary)->toBe('/usr/bin/nfdump')
            ->and($s->nfdumpProfilesData)->toBe('/var/nfdump/profiles-data')
            ->and($s->nfdumpProfile)->toBe('live')
            ->and($s->nfdumpMaxProcesses)->toBe(1)
            ->and($s->logPriority)->toBe(LOG_INFO);
    });

    test('clamps nfdumpMaxProcesses to minimum 1', function () {
        $s = Settings::fromArray(['nfdump' => ['max-processes' => 0]]);
        expect($s->nfdumpMaxProcesses)->toBe(1);
    });
});

describe('Settings::fromEnv()', function () {
    test('returns instance with defaults when no env vars set', function () {
        $s = Settings::fromEnv();

        expect($s->datasourceName)->toBe('RRD')
            ->and($s->processorName)->toBe('NfDump')
            ->and($s->nfdumpBinary)->toBe('/usr/local/nfdump/bin/nfdump')
            ->and($s->nfdumpMaxProcesses)->toBeGreaterThanOrEqual(1)
            ->and($s->sources)->toBe([])
            ->and($s->ports)->toBe([]);
    });

    test('parses NFSEN_SOURCES as comma-separated list', function () {
        putenv('NFSEN_SOURCES=gw1,gw2, mailserver ');
        $s = Settings::fromEnv();
        putenv('NFSEN_SOURCES');

        expect($s->sources)->toBe(['gw1', 'gw2', 'mailserver']);
    });

    test('parses NFSEN_PORTS as comma-separated integers', function () {
        putenv('NFSEN_PORTS=80,443,22');
        $s = Settings::fromEnv();
        putenv('NFSEN_PORTS');

        expect($s->ports)->toBe([80, 443, 22]);
    });

    test('parses NFSEN_FILTERS as a JSON array', function () {
        putenv('NFSEN_FILTERS=["proto tcp","dst port 53"]');
        $s = Settings::fromEnv();
        putenv('NFSEN_FILTERS');

        expect($s->filters)->toBe(['proto tcp', 'dst port 53']);
    });

    test('ignores malformed NFSEN_FILTERS and returns empty array', function () {
        putenv('NFSEN_FILTERS=not-json');
        $s = Settings::fromEnv();
        putenv('NFSEN_FILTERS');

        expect($s->filters)->toBe([]);
    });

    test('populates RRD datasource config from env vars', function () {
        putenv('NFSEN_IMPORT_YEARS=7');
        putenv('NFSEN_RRD_PATH=/rrd/data');
        $s = Settings::fromEnv();
        putenv('NFSEN_IMPORT_YEARS');
        putenv('NFSEN_RRD_PATH');

        expect($s->importYears)->toBe(7)
            ->and($s->importYears())->toBe(7)
            ->and($s->datasourceConfig('RRD'))->toBe(['data_path' => '/rrd/data']);
    });

    test('RRD datasource config omits data_path when NFSEN_RRD_PATH not set', function () {
        putenv('NFSEN_RRD_PATH');
        $s = Settings::fromEnv();

        expect($s->datasourceConfig('RRD'))->toBe([]);
    });

    test('populates VictoriaMetrics datasource config from env vars', function () {
        putenv('VM_HOST=vm.local');
        putenv('VM_PORT=9428');
        $s = Settings::fromEnv();
        putenv('VM_HOST');
        putenv('VM_PORT');

        expect($s->datasourceConfig('VictoriaMetrics')['host'])->toBe('vm.local')
            ->and($s->datasourceConfig('VictoriaMetrics')['port'])->toBe(9428)
            ->and($s->datasourceConfig('VictoriaMetrics'))->not->toHaveKey('import_years');
    });
});

describe('Settings with…() fluent mutators', function () {
    test('with…() returns new instance without mutating original', function () {
        $original = Settings::fromArray([]);
        $modified = $original->withSources(['src1'])->withPorts([22, 80]);

        expect($original->sources)->toBe([])
            ->and($original->ports)->toBe([])
            ->and($modified->sources)->toBe(['src1'])
            ->and($modified->ports)->toBe([22, 80]);
    });

    test('withNfdumpMaxProcesses clamps to minimum 1', function () {
        $s = Settings::fromArray([])->withNfdumpMaxProcesses(0);
        expect($s->nfdumpMaxProcesses)->toBe(1);
    });

    test('withDatasourceConfig stores and returns config sub-array', function () {
        $s = Settings::fromArray([])
            ->withDatasourceName('RRD')
            ->withDatasourceConfig('RRD', ['import_years' => 7, 'data_path' => '/rrd']);

        expect($s->datasourceConfig('RRD'))->toBe(['import_years' => 7, 'data_path' => '/rrd']);
    });

    test('each with…() method is fluent and chainable', function () {
        $s = Settings::fromArray([])
            ->withSources(['a'])
            ->withFilters(['proto tcp'])
            ->withLogPriority(LOG_WARNING)
            ->withNfdumpProfile('live2');

        expect($s->sources)->toBe(['a'])
            ->and($s->filters)->toBe(['proto tcp'])
            ->and($s->logPriority)->toBe(LOG_WARNING)
            ->and($s->nfdumpProfile)->toBe('live2');
    });
});

describe('Settings computed methods', function () {
    test('datasourceClass() returns correct FQN for known datasources', function () {
        expect(Settings::fromArray(['general' => ['db' => 'RRD']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\Rrd');

        expect(Settings::fromArray(['general' => ['db' => 'VictoriaMetrics']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\VictoriaMetrics');

        expect(Settings::fromArray(['general' => ['db' => 'Akumuli']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\Akumuli');
    });

    test('datasourceClass() is case-insensitive', function () {
        expect(Settings::fromArray(['general' => ['db' => 'rrd']])->datasourceClass())
            ->toBe('mbolli\\nfsen_ng\\datasources\\Rrd');
    });

    test('datasourceClass() throws for unknown datasource', function () {
        expect(fn () => Settings::fromArray(['general' => ['db' => 'MySQL']])->datasourceClass())
            ->toThrow(\InvalidArgumentException::class);
    });

    test('processorClass() returns correct FQN', function () {
        expect(Settings::fromArray([])->processorClass())
            ->toBe('mbolli\\nfsen_ng\\processor\\Nfdump');
    });

    test('processorClass() throws for unknown processor', function () {
        expect(fn () => Settings::fromArray(['general' => ['processor' => 'Unknown']])->processorClass())
            ->toThrow(\InvalidArgumentException::class);
    });

    test('importYears() returns configured value for active datasource', function () {
        $s = Settings::fromArray([
            'general' => ['db' => 'RRD'],
            'db'      => ['RRD' => ['import_years' => 7]],
        ]);

        expect($s->importYears)->toBe(7)
            ->and($s->importYears())->toBe(7);
    });

    test('importYears() returns default 3 when not configured', function () {
        $s = Settings::fromArray(['general' => ['db' => 'RRD']]);
        expect($s->importYears)->toBe(3);
    });

    test('importYears is clamped to minimum 1', function () {
        $s = Settings::fromArray([
            'general' => ['db' => 'RRD'],
            'db'      => ['RRD' => ['import_years' => 0]],
        ]);
        expect($s->importYears)->toBe(1);
    });

    test('datasourceConfig() returns empty array for unknown datasource', function () {
        $s = Settings::fromArray([]);
        expect($s->datasourceConfig('Nonexistent'))->toBe([]);
    });
});

describe('Settings static helpers', function () {
    test('logLevelFromString() converts known names', function () {
        expect(Settings::logLevelFromString('DEBUG'))->toBe(LOG_DEBUG)
            ->and(Settings::logLevelFromString('info'))->toBe(LOG_INFO)
            ->and(Settings::logLevelFromString('LOG_WARNING'))->toBe(LOG_WARNING)
            ->and(Settings::logLevelFromString('ERROR'))->toBe(LOG_ERR);
    });

    test('logLevelFromString() returns LOG_INFO for unknown name', function () {
        expect(Settings::logLevelFromString('NONSENSE'))->toBe(LOG_INFO);
    });

    test('logLevelToString() converts known constants', function () {
        expect(Settings::logLevelToString(LOG_DEBUG))->toBe('debug')
            ->and(Settings::logLevelToString(LOG_INFO))->toBe('info')
            ->and(Settings::logLevelToString(LOG_WARNING))->toBe('warning')
            ->and(Settings::logLevelToString(LOG_ERR))->toBe('error');
    });

    test('logLevelToString() returns "info" for unknown value', function () {
        expect(Settings::logLevelToString(999))->toBe('info');
    });
});
