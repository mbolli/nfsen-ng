<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\query\StatisticCatalog;

beforeAll(function (): void {
    Config::$settings = Settings::fromArray(mockSettings());
});

describe('StatisticCatalog', function (): void {
    $bin = static fn (string $name): string => dirname(__DIR__) . '/Support/bin/' . $name;

    // The order of today's statistic select in stats-filters.html.twig.
    test('lists all 58 statistics in the picker order', function (): void {
        $mpls = array_map(static fn (int $i): string => 'mpls' . $i, range(1, 10));

        expect(array_column(StatisticCatalog::all(), 'value'))->toBe([
            'record', 'ip', 'srcip', 'dstip', 'port', 'srcport', 'dstport', 'if', 'inif', 'outif',
            'as', 'srcas', 'dstas', 'nhip', 'nhbip', 'router', 'proto', 'dir', 'srctos', 'dsttos',
            'tos', 'mask', 'srcmask', 'dstmask', 'vlan', 'srcvlan', 'dstvlan', 'srcmac', 'dstmac', 'inmac',
            'outmac', 'insrcmac', 'outdstmac', 'indstmac', 'outsrcmac', ...$mpls,
            'event', 'xevent', 'natsrcip', 'natdstip', 'natip', 'natsrcport', 'natdstport', 'natport',
            'nevent', 'nsrcip', 'ndstip', 'nsrcport', 'ndstport',
        ]);
    });

    test('labels and groups every entry', function (): void {
        $all = StatisticCatalog::all();

        expect($all[0])->toBe(['value' => 'record', 'label' => 'Flow Records', 'group' => 'Flow records'])
            ->and($all[2])->toBe(['value' => 'srcip', 'label' => 'Src IP address', 'group' => 'Addresses'])
            ->and($all[44])->toBe(['value' => 'mpls10', 'label' => 'MPLS Label 10', 'group' => 'MPLS'])
            ->and(array_values(array_unique(array_column($all, 'group'))))->toBe([
                'Flow records', 'Addresses', 'Ports and protocols', 'Interfaces', 'AS', 'ToS', 'Masks',
                'VLAN', 'MAC', 'MPLS', 'NSEL / Cisco ASA', 'NEL / NAT',
            ])
        ;

        foreach ($all as $entry) {
            expect($entry['label'])->not->toBe('');
        }
    });

    test('knows its own elements and nothing else', function (): void {
        foreach (StatisticCatalog::all() as $entry) {
            expect(StatisticCatalog::isValid($entry['value']))->toBeTrue();
        }

        expect(StatisticCatalog::isValid('srcport:p'))->toBeFalse()
            ->and(StatisticCatalog::isValid('SRCIP'))->toBeFalse()
            ->and(StatisticCatalog::isValid(''))->toBeFalse()
            ->and(StatisticCatalog::isValid('srcip/bytes'))->toBeFalse()
        ;
    });

    test('labels an element, or echoes one it does not know', function (): void {
        expect(StatisticCatalog::label('dstport'))->toBe('Dst port')
            ->and(StatisticCatalog::label('bogus'))->toBe('bogus')
        ;
    });

    test('every tab and family names catalog elements', function (): void {
        foreach ([...array_values(StatisticCatalog::TABS), ...array_values(StatisticCatalog::FAMILIES)] as $triple) {
            foreach (['any', 'src', 'dst'] as $direction) {
                if ($triple[$direction] !== null) {
                    expect(StatisticCatalog::isValid($triple[$direction]))->toBeTrue();
                }
            }
        }
    });

    test('maps an element to its tab or to more', function (): void {
        expect(StatisticCatalog::tabOf('ip'))->toBe('talkers')
            ->and(StatisticCatalog::tabOf('srcip'))->toBe('talkers')
            ->and(StatisticCatalog::tabOf('dstport'))->toBe('ports')
            ->and(StatisticCatalog::tabOf('proto'))->toBe('protocols')
            ->and(StatisticCatalog::tabOf('srcas'))->toBe('asns')
            ->and(StatisticCatalog::tabOf('outif'))->toBe('interfaces')
            ->and(StatisticCatalog::tabOf('record'))->toBe('more')
            ->and(StatisticCatalog::tabOf('srctos'))->toBe('more')
            ->and(StatisticCatalog::tabOf('bogus'))->toBe('more')
        ;
    });

    test('names the direction of an element', function (): void {
        expect(StatisticCatalog::directionOf('ip'))->toBe('any')
            ->and(StatisticCatalog::directionOf('srcip'))->toBe('src')
            ->and(StatisticCatalog::directionOf('dstport'))->toBe('dst')
            ->and(StatisticCatalog::directionOf('inif'))->toBe('src')
            ->and(StatisticCatalog::directionOf('srctos'))->toBe('src')
            ->and(StatisticCatalog::directionOf('natport'))->toBe('any')
        ;
    });

    test('has no direction for protocols and for elements outside a triple', function (): void {
        foreach (['proto', 'record', 'srcmac', 'nsrcip', 'mpls1', 'dir', 'bogus'] as $element) {
            expect(StatisticCatalog::directionOf($element))->toBeNull();
        }
    });

    test('resolves a tab or family and a direction to an element', function (): void {
        expect(StatisticCatalog::resolve('talkers', 'src'))->toBe('srcip')
            ->and(StatisticCatalog::resolve('talkers', 'any'))->toBe('ip')
            ->and(StatisticCatalog::resolve('ports', 'dst'))->toBe('dstport')
            ->and(StatisticCatalog::resolve('interfaces', 'dst'))->toBe('outif')
            ->and(StatisticCatalog::resolve('srcip', 'dst'))->toBe('dstip')
            ->and(StatisticCatalog::resolve('dstas', 'any'))->toBe('as')
            ->and(StatisticCatalog::resolve('tos', 'src'))->toBe('srctos')
            ->and(StatisticCatalog::resolve('natdstip', 'any'))->toBe('natip')
        ;
    });

    test('falls back to any when the family has no such direction', function (): void {
        expect(StatisticCatalog::resolve('protocols', 'src'))->toBe('proto')
            ->and(StatisticCatalog::resolve('proto', 'dst'))->toBe('proto')
            ->and(StatisticCatalog::resolve('talkers', 'sideways'))->toBe('ip')
        ;
    });

    test('leaves an element outside every family alone', function (): void {
        expect(StatisticCatalog::resolve('record', 'src'))->toBe('record')
            ->and(StatisticCatalog::resolve('nhip', 'dst'))->toBe('nhip')
        ;
    });

    test('round-trips every element that has a direction', function (): void {
        foreach (StatisticCatalog::all() as ['value' => $element]) {
            $direction = StatisticCatalog::directionOf($element);
            if ($direction === null) {
                continue;
            }

            expect(StatisticCatalog::resolve($element, $direction))->toBe($element);
            $tab = StatisticCatalog::tabOf($element);
            if ($tab !== 'more') {
                expect(StatisticCatalog::resolve($tab, $direction))->toBe($element);
            }
        }
    });

    test('marks the NEL statistics a build without NEL rejects', function () use ($bin): void {
        $unsupported = StatisticCatalog::unsupported($bin('nfdump-no-nel'));

        expect(array_keys($unsupported))->toBe(StatisticCatalog::PROBED)
            ->and($unsupported['nevent'])->toBe('Unknown statistic: nevent')
            ->and($unsupported['ndstport'])->toBe('Unknown statistic: ndstport')
        ;
    });

    test('marks nothing when every probe passes', function () use ($bin): void {
        expect(StatisticCatalog::unsupported($bin('nfdump-z-valid')))->toBe([]);
    });

    // A missing binary rejects srcip as well, which says nothing about the statistics.
    test('marks nothing when the binary cannot answer at all', function (): void {
        expect(StatisticCatalog::unsupported('/nonexistent/nfdump'))->toBe([]);
    });

    test('asks again after a probe timed out', function () use ($bin): void {
        $slow = $bin('nfdump-z-slow');
        $cache = new ReflectionProperty(StatisticCatalog::class, 'unsupported');

        expect(StatisticCatalog::unsupported($slow))->toBe([])
            ->and($cache->getValue())->not->toHaveKey($slow)
        ;
    });

    test('probes once per binary', function () use ($bin): void {
        $argsFile = (string) tempnam(sys_get_temp_dir(), 'nfdump-args');
        putenv('NFDUMP_STUB_ARGS=' . $argsFile);

        try {
            StatisticCatalog::unsupported($bin('nfdump-canned'));
            expect(file_get_contents($argsFile))->toContain('ndstport/bytes');

            unlink($argsFile);
            StatisticCatalog::unsupported($bin('nfdump-canned'));
            expect(file_exists($argsFile))->toBeFalse();
        } finally {
            putenv('NFDUMP_STUB_ARGS');
            if (is_file($argsFile)) {
                unlink($argsFile);
            }
        }
    });

    test('uses the configured binary by default', function (): void {
        Config::$settings = Settings::fromArray(array_replace_recursive(mockSettings(), ['nfdump' => ['binary' => dirname(__DIR__) . '/Support/bin/nfdump-no-nel']]));

        expect(array_keys(StatisticCatalog::unsupported()))->toBe(StatisticCatalog::PROBED);

        Config::$settings = Settings::fromArray(mockSettings());
    });

    // Verified on 1.7.8: the release build has no NEL statistics.
    test('probes the real binary', function (): void {
        $binary = '/usr/local/nfdump/bin/nfdump';
        if (!is_executable($binary)) {
            $this->markTestSkipped('nfdump is not installed here');
        }

        $unsupported = StatisticCatalog::unsupported($binary);

        expect(array_diff(array_keys($unsupported), StatisticCatalog::PROBED))->toBe([]);
        if (Nfdump::version($binary) === '1.7.8') {
            expect(array_keys($unsupported))->toBe(StatisticCatalog::PROBED)
                ->and($unsupported['nevent'])->toBe('Unknown statistic: nevent')
            ;
        }
    });
});
