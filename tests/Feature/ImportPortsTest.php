<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\ImportDaemon;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Rrd;

function importPortsSettings(string $profilesData): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [443], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => $profilesData,
            'profile' => 'live',
            'max-processes' => 1,
        ],
        'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
        'log' => ['priority' => LOG_WARNING],
    ]);
    Config::$path = sys_get_temp_dir();
}

describe('Port processing on the live import path (#173)', function (): void {
    test('the daemon\'s ongoing importer has port processing enabled', function (): void {
        importPortsSettings('/tmp');

        $factory = new ReflectionMethod(ImportDaemon::class, 'newOngoingImporter');
        $factory->setAccessible(true);
        $importer = $factory->invoke(new ImportDaemon('live'));

        // Without these, importFile() writes source.rrd and the all-sources port.rrd but
        // never source_port.rrd — the file the ports graph reads.
        foreach (['processPorts', 'processPortsBySource'] as $flag) {
            $property = new ReflectionProperty(Import::class, $flag);
            $property->setAccessible(true);
            expect($property->getValue($importer))->toBeTrue("{$flag} must be set on the ongoing importer");
        }
    });

    test('the all-sources aggregate skips sources whose capture has not arrived yet', function (): void {
        $dir = sys_get_temp_dir() . '/import_ports_' . uniqid();
        mkdir($dir . '/live/gw1/2026/09/15', 0o755, true);
        mkdir($dir . '/live/gw2/2026/09/15', 0o755, true);
        importPortsSettings($dir);
        Config::$db = new Rrd();

        $statsPath = '2026/09/15/nfcapd.202609151105';
        $writePortData = new ReflectionMethod(Import::class, 'writePortData');
        $writePortData->setAccessible(true);

        // Neither source has rotated the file into place: nfdump would only be able to
        // answer with "stat() error ...: File not found!", so it is not asked at all.
        expect($writePortData->invoke(new Import(), 443, $statsPath, ''))->toBeFalse();

        foreach (['gw1', 'gw2'] as $source) {
            rmdir($dir . '/live/' . $source . '/2026/09/15');
            rmdir($dir . '/live/' . $source . '/2026/09');
            rmdir($dir . '/live/' . $source . '/2026');
            rmdir($dir . '/live/' . $source);
        }
        rmdir($dir . '/live');
        rmdir($dir);
    });
});

describe('Protocol bucketing of port statistics (#173)', function (): void {
    test('every protocol maps onto a data source the RRD actually has', function (string $nfdumpName, string $expected): void {
        $bucket = new ReflectionMethod(Import::class, 'protocolBucket');
        $bucket->setAccessible(true);

        expect($bucket->invoke(null, $nfdumpName))->toBe($expected);
    })->with([
        ['TCP', 'tcp'],
        ['UDP', 'udp'],
        ['ICMP', 'icmp'],
        ['IPv6-ICMP', 'icmp'],
        // Anything else has no data source of its own — writing flows_gre made
        // RRDUpdater throw "unknown DS name" and cost the capture file its port data.
        ['GRE', 'other'],
        ['ESP', 'other'],
        ['SCTP', 'other'],
    ]);
});
