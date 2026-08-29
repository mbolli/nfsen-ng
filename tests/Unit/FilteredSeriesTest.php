<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\FilteredSeries;
use Tests\Support\FakeProcessor;

/** One nfdump `-s proto` CSV row, as Nfdump::execute() decodes it. */
function statRow(string $proto, int $flows, int $packets, int $bytes): array {
    return [
        'ts' => '2024-01-01 00:00:00',
        'te' => '2024-01-01 00:05:00',
        'td' => '300.000',
        'pr' => $proto,
        'val' => $proto,
        'fl' => (string) $flows,
        'flP' => '100.0',
        'ipkt' => (string) $packets,
        'ipktP' => '100.0',
        'ibyt' => (string) $bytes,
        'ibytP' => '100.0',
    ];
}

/** Capture tree + fake processor, wired into Config. */
function withFakeNfdump(array $sources, array $timestamps): string {
    $root = makeCaptureTree($sources, $timestamps);
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80],
            'sources' => $sources,
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => $root,
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$processorClass = new FakeProcessor();
    FakeProcessor::reset();

    return $root;
}

describe('FilteredSeries::binWidth', function (): void {
    test('never goes below the 5-minute nfcapd rotation', function (): void {
        expect(FilteredSeries::binWidth(0, 600, 1000))->toBe(300);
    });

    test('always lands on a whole multiple of the rotation interval', function (): void {
        $step = FilteredSeries::binWidth(0, 86400, 137);
        expect($step % 300)->toBe(0);
    });

    test('widens to honour the requested point count', function (): void {
        // 24 h across 48 points => 1800 s bins
        expect(FilteredSeries::binWidth(0, 86400, 48))->toBe(1800);
    });

    test('caps the projected nfdump run count', function (): void {
        // 30 days at 5-minute resolution would be 8640 runs; must be widened.
        $span = 30 * 86400;
        $step = FilteredSeries::binWidth(0, $span, 10000);
        expect((int) ceil($span / $step))->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
    });

    test('accounts for per-source runs when display multiplies the work', function (): void {
        $span = 7 * 86400;
        $step = FilteredSeries::binWidth(0, $span, 10000, groupCount: 4);
        expect((int) ceil($span / $step) * 4)->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
    });
});

describe('FilteredSeries::build', function (): void {
    $base = 1704067200; // 2024-01-01 00:00 UTC

    test('throws when the range holds no capture files', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);

        expect(fn () => FilteredSeries::build($base + 86400, $base + 90000, ['gateway'], 'proto tcp'))
            ->toThrow(Exception::class, 'No nfcapd files found')
        ;

        removeTree($root);
    });

    test('invokes nfdump with -s proto and an explicit -n 0', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 10, 100, 1000)];

        FilteredSeries::build($base, $base + 299, ['gateway'], 'proto tcp', profile: 'live');

        $options = FakeProcessor::callOptions();
        expect($options['-s'])->toBe('proto')
            // nfdump defaults -n to 10 for statistics; without this the bin undercounts.
            ->and($options['-n'])->toBe(0)
            ->and(FakeProcessor::$calls[0]['filter'])->toBe('proto tcp')
        ;

        removeTree($root);
    });

    test('passes the bin file range as an already-resolved -R pair', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [];

        // One 900 s bin covering all three files.
        FilteredSeries::build($base, $base + 899, ['gateway'], '', targetPoints: 1);

        expect(FakeProcessor::callOptions()['-R'])
            ->toBe('2024/01/01/nfcapd.202401010000:2024/01/01/nfcapd.202401010010')
        ;

        removeTree($root);
    });

    // nfdump reads a single-path -R as a prefix match, so a one-file bin uses -r instead.
    test('uses -r, not -R, when a bin holds exactly one file', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [];

        FilteredSeries::build($base, $base + 299, ['gateway'], '');

        expect(FakeProcessor::callOptions()['-r'])->toBe('2024/01/01/nfcapd.202401010000')
            ->and(FakeProcessor::callOptions())->not->toHaveKey('-R')
        ;

        removeTree($root);
    });

    test('converts per-bin totals into per-second rates', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 600, 3000, 30000)];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        // 600 flows over a 300 s bin => 2 flows/s, in the tcp series (index 0).
        expect($series['step'])->toBe(300)
            ->and($series['data'][$base][0])->toBe(2.0)
        ;

        removeTree($root);
    });

    test('multiplies bytes by eight for the bits unit', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 1, 1, 30000)];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'bits');

        // 30000 bytes * 8 / 300 s = 800 bits/s
        expect($series['data'][$base][0])->toBe(800.0);

        removeTree($root);
    });

    test('splits counters across the tcp/udp/icmp/other series', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
            statRow('ICMP', 900, 0, 0),
            statRow('GRE', 1200, 0, 0),
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['legend'])->toBe([
            'tcp_flows_gateway', 'udp_flows_gateway', 'icmp_flows_gateway', 'other_flows_gateway',
        ])->and($series['data'][$base])->toBe([1.0, 2.0, 3.0, 4.0]);

        removeTree($root);
    });

    test('folds every unrecognised protocol into "other"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('GRE', 300, 0, 0),
            statRow('ESP', 600, 0, 0),
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['data'][$base][3])->toBe(3.0);   // (300 + 600) / 300

        removeTree($root);
    });

    test('emits one series per source for the sources display', function () use ($base): void {
        $root = withFakeNfdump(['gateway', 'swi6'], [$base]);
        FakeProcessor::$responses = [
            [statRow('TCP', 300, 0, 0)],   // gateway
            [statRow('TCP', 900, 0, 0)],   // swi6
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway', 'swi6'],
            '',
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_any', 'swi6_flows_any'])
            ->and($series['data'][$base])->toBe([1.0, 3.0])
            // one nfdump run per source, each scoped to that source alone
            ->and(FakeProcessor::$calls)->toHaveCount(2)
            ->and(FakeProcessor::callOptions(0)['-M'])->toBe('gateway')
            ->and(FakeProcessor::callOptions(1)['-M'])->toBe('swi6')
        ;

        removeTree($root);
    });

    test('merges sources in a single nfdump run for the protocols display', function () use ($base): void {
        $root = withFakeNfdump(['gateway', 'swi6'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        FilteredSeries::build($base, $base + 299, ['gateway', 'swi6'], '', display: 'protocols');

        expect(FakeProcessor::$calls)->toHaveCount(1)
            ->and(FakeProcessor::callOptions()['-M'])->toBe('gateway:swi6')
        ;

        removeTree($root);
    });

    // A bin with no captures must still occupy a row: omitting it leaves no point at that
    // timestamp and ECharts draws straight across, so a collection outage looked like
    // steady traffic. Stored mode shows a real gap for the same window.
    test('emits bins without capture files as null rows, not as missing rows', function () use ($base): void {
        // Files at 00:00 and 00:10 — the 00:05 bin has none.
        $root = withFakeNfdump(['gateway'], [$base, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build($base, $base + 899, ['gateway'], '', unit: 'flows');

        expect(array_keys($series['data']))->toBe([$base, $base + 300, $base + 600])
            ->and($series['data'][$base + 300])->toBe([null, null, null, null])
            ->and($series['data'][$base][0])->toBe(1.0)
            // nfdump is still not run for a bin with no files at all
            ->and(FakeProcessor::$calls)->toHaveCount(2)
        ;

        removeTree($root);
    });

    // nfdump ran and matched nothing: that is a real zero, not absence of data.
    test('reports zeros when nfdump ran and matched nothing', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], 'proto tcp', unit: 'flows');

        expect($series['data'][$base])->toBe([0.0, 0.0, 0.0, 0.0]);

        removeTree($root);
    });

    // A failed invocation is a gap, not a zero — reporting 0 would render a truncated
    // capture or an nfdumpMaxProcesses rejection as "no traffic here".
    test('keeps going when a bin fails, and leaves it a gap rather than a zero', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300]);
        FakeProcessor::$throw = new Exception('nfdump exploded');

        $series = FilteredSeries::build($base, $base + 599, ['gateway'], '', unit: 'flows');

        expect($series['data'])->toHaveCount(2)
            ->and($series['data'][$base])->toBe([null, null, null, null])
            ->and($series['data'][$base + 300])->toBe([null, null, null, null])
        ;

        removeTree($root);
    });

    // The first bin starts at the floored window start, so the capture covering it must be
    // included — listing from the raw start dropped it and under-reported that bin.
    test('includes the capture covering the first partial bin', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        // Window starts 2 minutes after the capture at $base; the bin still starts at $base.
        $series = FilteredSeries::build($base + 120, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['data'][$base][0])->toBe(1.0)
            ->and(FakeProcessor::$calls)->toHaveCount(1)
        ;

        removeTree($root);
    });

    test('reports progress up to the total run count', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 1, 1, 1)];

        $seen = [];
        FilteredSeries::build(
            $base,
            $base + 899,
            ['gateway'],
            '',
            targetPoints: 3,
            onProgress: function (int $done, int $total) use (&$seen): void { $seen[] = [$done, $total]; }
        );

        expect($seen)->toBe([[1, 3], [2, 3], [3, 3]]);

        removeTree($root);
    });

    test('stops early and returns partial data when cancelled', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $calls = 0;
        $series = FilteredSeries::build(
            $base,
            $base + 899,
            ['gateway'],
            '',
            targetPoints: 3,
            shouldCancel: function () use (&$calls): bool { return ++$calls > 2; }
        );

        // Cancelled before the third bin ran: two complete bins survive.
        expect(FakeProcessor::$calls)->toHaveCount(2)
            ->and($series['data'])->toHaveCount(2)
        ;

        removeTree($root);
    });
});

describe('FilteredSeries::normalizeProtocolSelection', function (): void {
    test('an empty selection means no restriction', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection([]))->toBe(['any']);
    });

    test('"any" anywhere wins over explicit protocols', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['tcp', 'any']))->toBe(['any']);
    });

    test('unrecognised entries are dropped', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['tcp', 'sctp', 'nonsense']))->toBe(['tcp']);
    });

    test('a selection of only unrecognised entries means no restriction', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['sctp']))->toBe(['any']);
    });

    // The legend is built from this, so click order must not reorder the series.
    test('canonical order is restored regardless of input order', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['other', 'udp', 'tcp']))
            ->toBe(['tcp', 'udp', 'other'])
        ;
    });

    test('case is ignored', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['TCP', 'Udp']))->toBe(['tcp', 'udp']);
    });
});

describe('FilteredSeries protocol selection', function (): void {
    $base = 1704067200;

    // Regression: the Protocols buttons used to be inert in filtered mode — every
    // selection produced the same four series.
    test('protocols display emits only the selected series', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
            statRow('ICMP', 900, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['udp', 'icmp'],
            unit: 'flows',
            display: 'protocols'
        );

        expect($series['legend'])->toBe(['udp_flows_gateway', 'icmp_flows_gateway'])
            ->and($series['data'][$base])->toBe([2.0, 3.0])
        ;

        removeTree($root);
    });

    test('protocols display falls back to all four for "any"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'protocols'
        );

        expect($series['legend'])->toHaveCount(4);

        removeTree($root);
    });

    // Mirrors Rrd::get_graph_data(), which indexes $protocols[0] for the sources display.
    test('sources display narrows every series to the chosen protocol', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['udp'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_udp'])
            ->and($series['data'][$base])->toBe([2.0])   // udp only, not udp+tcp
        ;

        removeTree($root);
    });

    test('sources display sums every protocol for "any"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_any'])
            ->and($series['data'][$base])->toBe([3.0])   // 900 flows / 300 s
        ;

        removeTree($root);
    });
});

describe('FilteredSeries row guards', function (): void {
    $base = 1704067200;

    // nfdump before 1.7.8 prints a trailing Summary block in CSV stat output: it has a
    // value in the 'pr' column but no counters, and keying on 'pr' alone let its numbers
    // land in whichever series matched. Import::writePortData() checks all four keys.
    test('ignores a summary row that carries no counters', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            ['pr' => 'Summary: total flows', 'val' => '99999'],   // no fl/ipkt/ibyt
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', ['any'], unit: 'flows');

        // tcp only; nothing leaked into "other"
        expect($series['data'][$base])->toBe([1.0, 0.0, 0.0, 0.0]);

        removeTree($root);
    });

    test('a summary row does not inflate the sources-display total', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            ['pr' => 'Summary', 'val' => '1', 'fl' => '99999'],   // still missing ipkt/ibyt
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['data'][$base])->toBe([1.0]);

        removeTree($root);
    });
});

describe('FilteredSeries::binWidth termination', function (): void {
    // graph_sources is client-writable, and binWidth() is reached from filteredCost() on
    // the render path — a source list longer than MAX_RUNS used to spin the worker forever.
    test('terminates when the group count alone exceeds the run ceiling', function (): void {
        $step = FilteredSeries::binWidth(0, 86400, 500, FilteredSeries::MAX_RUNS + 1);

        expect($step)->toBeGreaterThanOrEqual(FilteredSeries::MIN_BIN);
    });

    test('terminates for an absurd group count', function (): void {
        expect(FilteredSeries::binWidth(0, 86400, 500, 100000))
            ->toBeGreaterThanOrEqual(FilteredSeries::MIN_BIN)
        ;
    });

    test('still respects the ceiling for reasonable group counts', function (): void {
        $span = 7 * 86400;
        foreach ([1, 2, 4, 8] as $groups) {
            $runs = (int) ceil($span / FilteredSeries::binWidth(0, $span, 10000, $groups)) * $groups;
            expect($runs)->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
        }
    });
});
