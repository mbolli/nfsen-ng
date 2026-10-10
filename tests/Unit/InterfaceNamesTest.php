<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\InterfaceNames;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TableFormatter;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\InterfaceNameRepository;
use OpenSwoole\Coroutine;

// The capture file a real nfcapd wrote from NetFlow v9 with Cisco's interface-name option records:
// interfaces 1 to 3 are GigabitEthernet0/0/0, GigabitEthernet0/0/1 and Tunnel10.
const IFNAMES_CAPTURES = __DIR__ . '/../Support/captures/ifnames';
const IFNAMES_FILE = '2026/10/10/nfcapd.202610100847';

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->processorBefore = isset(Config::$processorClass) ? Config::$processorClass : null;
    Config::$settings = Settings::fromArray([
        'general' => [
            'sources' => ['gw1', 'gw2'],
            'interfaces' => ['gw1' => [3 => 'Gi0/0/1'], 'gw2' => [3 => 'Tunnel10']],
        ],
        'nfdump' => ['binary' => '/usr/local/nfdump/bin/nfdump', 'profiles-data' => IFNAMES_CAPTURES, 'profile' => 'live'],
    ]);
    $this->db = Database::open(':memory:');
    Database::useShared($this->db);
    $this->repo = new InterfaceNameRepository($this->db);
    InterfaceNames::reset();
});

afterEach(function (): void {
    Database::resetShared();
    InterfaceNames::reset();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->processorBefore !== null) {
        Config::$processorClass = $this->processorBefore;
    }
});

describe('Settings interface names', function (): void {
    test('come from source:index:name in NFSEN_INTERFACES and from a map in settings.php', function (): void {
        putenv('NFSEN_INTERFACES=gw1:3:Gi0/0/1, gw1:4:Port: uplink ,gw2:x:bad,gw2:0:zero,:5:nosource,gw2:6:');

        try {
            expect(Settings::fromEnv()->interfaceNames)->toBe(['gw1' => [3 => 'Gi0/0/1', 4 => 'Port: uplink']]);
        } finally {
            putenv('NFSEN_INTERFACES');
        }
        expect(Settings::fromArray(['general' => ['interfaces' => ['gw1' => [1 => 'Gi0/0/0', '2' => ' Tunnel10 ', 3 => '']]]])->interfaceNames)
            ->toBe(['gw1' => [1 => 'Gi0/0/0', 2 => 'Tunnel10']])
        ;
    });
});

describe('InterfaceNameRepository', function (): void {
    test('keeps the name of the newest file that named an index', function (): void {
        $this->repo->remember('gw1', [1 => 'old', 2 => 'two'], 100);
        $this->repo->remember('gw1', [1 => 'new'], 200);
        $this->repo->remember('gw1', [1 => 'older', 2 => 'two again'], 150);

        expect($this->repo->all())->toBe(['gw1' => [1 => 'new', 2 => 'two again']]);
    });
});

describe('InterfaceNames', function (): void {
    test('parse() reads the names nfdump prints and skips the placeholders', function (): void {
        $raw = "     2| GigabitEthernet0/0/1|     1| GigabitEthernet0/0/0\n     3| Tunnel10|     9| <no if name>\n"
            . "     7| <ingress not found>|     0| \nSummary: total flows: 9\n";

        expect(InterfaceNames::parse($raw))->toBe([2 => 'GigabitEthernet0/0/1', 1 => 'GigabitEthernet0/0/0', 3 => 'Tunnel10']);
    });

    test('a configured name wins over a learned one; a name across sources needs them to agree', function (): void {
        $this->repo->remember('gw1', [3 => 'learned three', 4 => 'Gi0/0/2'], 100);

        expect(InterfaceNames::name('gw1', 3))->toBe('Gi0/0/1')
            ->and(InterfaceNames::name('gw1', '4'))->toBe('Gi0/0/2')
            ->and(InterfaceNames::name('gw2', 4))->toBeNull()
            ->and(InterfaceNames::nameIn(['gw1'], 3))->toBe('Gi0/0/1')
            ->and(InterfaceNames::nameIn(['gw1', 'gw2'], 3))->toBeNull()
            ->and(InterfaceNames::nameIn(['gw1', 'gw2'], 4))->toBe('Gi0/0/2')
            ->and(InterfaceNames::nameIn([], 4))->toBe('Gi0/0/2')
            ->and(InterfaceNames::nameIn(['gw1'], 0))->toBeNull()
            ->and(InterfaceNames::all())->toBe([
                'gw1' => [3 => ['name' => 'Gi0/0/1', 'learned' => false], 4 => ['name' => 'Gi0/0/2', 'learned' => true]],
                'gw2' => [3 => ['name' => 'Tunnel10', 'learned' => false]],
            ])
        ;
    });

    test('a table cell shows the name after the index, and sorts by the index', function (): void {
        expect(TableFormatter::formatCellValue(3, 'input_snmp', ['sources' => ['gw1']]))->toBe('3 <small>(Gi0/0/1)</small>')
            ->and(TableFormatter::formatCellValue('3', 'inif', ['sources' => ['gw2']]))->toBe('3 <small>(Tunnel10)</small>')
            ->and(TableFormatter::formatCellValue(3, 'if', ['sources' => ['gw1', 'gw2']]))->toBe('3')
            ->and(TableFormatter::formatCellValue(5, 'output_snmp', ['sources' => ['gw1']]))->toBe('5')
            ->and(TableFormatter::getSortValue(3, 'input_snmp'))->toBe(3)
        ;
    });

    test("Overview names an interface by its row's source", function (): void {
        expect(OverviewPage::keyLabel('interfaces', '3', 'gw1'))->toBe('gw1 · if 3 (Gi0/0/1)')
            ->and(OverviewPage::keyLabel('interfaces', '3'))->toBe('if 3')
            ->and(OverviewPage::keyLabel('interfaces', '9', 'gw1'))->toBe('gw1 · if 9')
        ;
    });

    test('learnFrom() reads the names a capture file holds, once per source within LEARN_EVERY', function (): void {
        if (!is_executable('/usr/local/nfdump/bin/nfdump')) {
            $this->markTestSkipped('nfdump is not installed here');
        }
        Config::$processorClass = new Nfdump();

        Coroutine::run(static function (): void {
            InterfaceNames::learnFrom('live', 'gw1', IFNAMES_FILE, 1_760_086_020);
            // Within the hour nothing is read again, whatever the file.
            InterfaceNames::learnFrom('live', 'gw1', 'no/such/file', 1_760_086_320);
        });

        expect($this->repo->all())->toBe(['gw1' => [1 => 'GigabitEthernet0/0/0', 2 => 'GigabitEthernet0/0/1', 3 => 'Tunnel10']]);
    });

    test('learnFrom() does nothing outside a coroutine', function (): void {
        InterfaceNames::learnFrom('live', 'gw1', IFNAMES_FILE, 1_760_086_020);

        expect($this->repo->all())->toBe([]);
    });
});
