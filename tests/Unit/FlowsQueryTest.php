<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Tests\Support\FakeProcessor;

/** nfdump 1.7.8's answer to `-o csv -n 0 -s proto/bytes`, from the dev captures. */
const FLOWS_QUERY_PROTO_CSV = <<<'CSV'
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,17,20,26.7,100,19.0,10000,19.5,100,80000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,40,53.3,400,76.2,40000,78.0,400,320000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,1,10,13.3,20,3.8,1000,1.9,20,8000,50
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,GRE,47,5,6.7,5,1.0,300,0.6,5,2400,60
    CSV;

function flowsQueryTestQuery(array $overrides = []): FlowsQuery {
    return new FlowsQuery(
        window: TimeWindow::raw(1_000, 2_000),
        sources: ['gw', 'dmz'],
        profile: 'live',
        limit: $overrides['limit'] ?? 100,
        filter: $overrides['filter'] ?? 'dst port 443',
        lowerLimit: $overrides['lowerLimit'] ?? '1k',
        aggregation: $overrides['aggregation'] ?? ['srcport' => true],
        orderByStart: $overrides['orderByStart'] ?? true,
        handle: 'ctx-1',
        protocol: $overrides['protocol'] ?? 'tcp',
    );
}

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $processor = new ReflectionProperty(Config::class, 'processorClass');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    $this->processorBefore = $processor->isInitialized() ? Config::$processorClass : null;

    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'dmz'], 'ports' => []],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/var/nfdump/profiles-data', 'profile' => 'live'],
    ]);
    Config::$processorClass = new FakeProcessor();
    FakeProcessor::reset();
});

afterEach(function (): void {
    FakeProcessor::reset();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->processorBefore !== null) {
        Config::$processorClass = $this->processorBefore;
    }
});

describe('the filtered totals run (D13)', function (): void {
    test('reads every flow the filter matches: one row per protocol, no limit, order or aggregation', function (): void {
        $query = flowsQueryTestQuery();
        FakeProcessor::queueRaw(FLOWS_QUERY_PROTO_CSV);

        $result = $query->runSummary();
        $call = FakeProcessor::$calls[0];

        expect($call['options'])->toBe([
            '-M' => 'gw:dmz',
            '-R' => [1_000, 2_000],
            '-o' => 'csv',
            '-n' => 0,
            '-s' => 'proto/bytes',
        ])
            ->and($call['filter'])->toBe('(bytes > 1k) and (proto tcp) and (dst port 443)')
            ->and($call['filter'])->toBe($query->effectiveFilter())
            ->and($call['profile'])->toBe('live')
            ->and($call['handle'])->toBe('ctx-1')
            ->and($result->rawOutput)->toBe(FLOWS_QUERY_PROTO_CSV)
        ;
    });

    test('the listing itself still asks for json with the limit, order and aggregation', function (): void {
        $query = flowsQueryTestQuery();
        $query->run();

        expect(FakeProcessor::callOptions())->toMatchArray(['-c' => 100, '-o' => 'json', '-O' => 'tstart', '-a' => '-Asrcport']);
    });

    test('an unbalanced filter is refused before anything runs', function (): void {
        expect(fn () => flowsQueryTestQuery(['filter' => 'port 1) or (port 2'])->summaryProcessor())
            ->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.')
            ->and(FakeProcessor::$calls)->toBe([])
        ;
    });

    test('sums the protocol rows and keeps them, largest first', function (): void {
        expect(FlowsQuery::summaryFromOutput(FLOWS_QUERY_PROTO_CSV))->toBe([
            'flows' => 75,
            'packets' => 525,
            'bytes' => 51_300,
            'protocols' => [
                ['proto' => 'TCP', 'number' => '6', 'flows' => 40, 'packets' => 400, 'bytes' => 40_000],
                ['proto' => 'UDP', 'number' => '17', 'flows' => 20, 'packets' => 100, 'bytes' => 10_000],
                ['proto' => 'ICMP', 'number' => '1', 'flows' => 10, 'packets' => 20, 'bytes' => 1_000],
                ['proto' => 'GRE', 'number' => '47', 'flows' => 5, 'packets' => 5, 'bytes' => 300],
            ],
        ]);
    });

    test('no matching flows sums to zero', function (): void {
        $empty = "No matching flows\nts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n";

        expect(FlowsQuery::summaryFromOutput($empty))->toBe(['flows' => 0, 'packets' => 0, 'bytes' => 0, 'protocols' => []])
            ->and(FlowsQuery::summaryFromOutput(''))->toBe(['flows' => 0, 'packets' => 0, 'bytes' => 0, 'protocols' => []])
        ;
    });
});

describe('the returned rows summary', function (): void {
    beforeEach(function (): void {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    });

    afterEach(function (): void {
        date_default_timezone_set($this->tz);
    });

    test('adds up json records, one flow each, in and out counters', function (): void {
        $rows = [
            ['first' => '2026-08-29T05:17:53.000', 'last' => '2026-08-29T05:17:54.000', 'in_packets' => 10, 'in_bytes' => 1000],
            ['first' => '2026-08-29T05:17:50.500', 'last' => '2026-08-29T05:18:00.500', 'in_packets' => 5, 'in_bytes' => 500, 'out_packets' => 5, 'out_bytes' => 300],
        ];

        $s = FlowsQuery::returnedSummary($rows);

        expect($s)->toMatchArray([
            'records' => 2,
            'flows' => 2,
            'aggregated' => false,
            'packets' => 20,
            'bytes' => 1_800,
            'duration' => 10.0,
            'bpp' => 90.0,
        ])
            ->and($s['first'])->toBe(1_787_980_670.5)
            ->and($s['last'])->toBe(1_787_980_680.5)
            ->and($s['bps'])->toBe(1_440.0)
            ->and($s['pps'])->toBe(2.0)
        ;
    });

    test('reads the flow count and duration of aggregated csv rows', function (): void {
        $rows = [
            ['firstSeen' => '2026-08-29 05:17:53.000', 'duration' => '1.000', 'proto' => '6', 'packets' => '10', 'bytes' => '1000', 'flows' => '3'],
            ['firstSeen' => '2026-08-29 05:17:55.000', 'duration' => '2.000', 'proto' => '6', 'packets' => '10', 'bytes' => '1000', 'flows' => '2'],
        ];

        expect(FlowsQuery::returnedSummary($rows))->toMatchArray(['records' => 2, 'flows' => 5, 'aggregated' => true, 'packets' => 20, 'bytes' => 2_000, 'duration' => 4.0]);
    });

    test('reads both directions of the biflow table', function (): void {
        $rows = [['firstSeen' => '2026-08-29 05:17:53.000', 'duration' => '1.000', 'outPackets' => '2', 'inPackets' => '10', 'outBytes' => '100', 'inBytes' => '1000', 'flows' => '1']];

        expect(FlowsQuery::returnedSummary($rows))->toMatchArray(['flows' => 1, 'aggregated' => true, 'packets' => 12, 'bytes' => 1_100, 'duration' => 1.0]);
    });

    test('no rows, or rows without times, have no rates', function (): void {
        expect(FlowsQuery::returnedSummary([]))->toBe([
            'records' => 0, 'flows' => 0, 'aggregated' => false, 'packets' => 0, 'bytes' => 0,
            'first' => null, 'last' => null, 'duration' => null, 'bps' => null, 'pps' => null, 'bpp' => null,
        ])
            ->and(FlowsQuery::returnedSummary([['in_bytes' => 'n/a', 'first' => 'soon']]))->toMatchArray(['bytes' => 0, 'first' => null, 'bps' => null])
        ;
    });
});
