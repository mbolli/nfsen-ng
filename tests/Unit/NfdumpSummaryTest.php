<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\NfdumpSummary;

describe('NfdumpSummary::fromStatDump()', function (): void {
    // Verbatim `nfdump -r <file> -I` from the dev captures (1.7.8).
    $statDump = <<<'TXT'
        Ident: none
        Flows: 75
        Flows_tcp: 40
        Flows_udp: 20
        Flows_icmp: 10
        Flows_other: 5
        Packets: 525
        Packets_tcp: 400
        Packets_udp: 100
        Packets_icmp: 20
        Packets_other: 5
        Bytes: 51300
        Bytes_tcp: 40000
        Bytes_udp: 10000
        Bytes_icmp: 1000
        Bytes_other: 300
        First: 1787980673
        Last: 1787980674
        msec_first: 0
        msec_last: 0
        Sequence failures: 0
        TXT;

    $expected = [
        'flows' => 75, 'flows_tcp' => 40, 'flows_udp' => 20, 'flows_icmp' => 10, 'flows_other' => 5,
        'packets' => 525, 'packets_tcp' => 400, 'packets_udp' => 100, 'packets_icmp' => 20, 'packets_other' => 5,
        'bytes' => 51300, 'bytes_tcp' => 40000, 'bytes_udp' => 10000, 'bytes_icmp' => 1000, 'bytes_other' => 300,
        'first' => 1787980673, 'last' => 1787980674,
    ];

    test('parses the -I text', function () use ($statDump, $expected): void {
        expect(NfdumpSummary::fromStatDump($statDump))->toBe($expected);
    });

    // What Nfdump::execute() decodes the same output into.
    test('parses the decoded metric rows', function () use ($statDump, $expected): void {
        $rows = [];
        foreach (explode("\n", $statDump) as $line) {
            [$metric, $value] = explode(':', $line, 2);
            $rows[] = ['metric' => trim($metric), 'value' => trim($value)];
        }

        expect(NfdumpSummary::fromStatDump($rows))->toBe($expected);
    });

    test('reports a key nfdump did not print as zero', function (): void {
        $summary = NfdumpSummary::fromStatDump("Flows: 3\nBytes: 120\n");

        expect($summary['flows'])->toBe(3)
            ->and($summary['bytes'])->toBe(120)
            ->and($summary['packets'])->toBe(0)
            ->and($summary['first'])->toBe(0)
        ;
    });

    test('ignores rows that are not metrics', function (): void {
        expect(NfdumpSummary::fromStatDump([['metric' => 'Flows', 'value' => 'lots'], 'garbage', ['x' => 1]])['flows'])->toBe(0);
    });
});

describe('NfdumpSummary::fromTextFooter()', function (): void {
    // Verbatim tail of `nfdump -r <file> -c 2` on 1.7.8.
    $footer = <<<'TXT'
        2026-08-29 05:17:53.000     00:00:01.000 TCP           10.0.1.1:10001 ->         10.1.1.2:443         10     1000     1
        Summary: total flows: 2, total bytes: 2000, total packets: 20, avg bps: 16000, avg pps: 20, avg bpp: 100
        Time window: 2026-08-29 05:17:53.000 - 2026-08-29 05:17:54.000, Duration:    00:00:01.000
        Total records processed: 2, passed: 75, Blocks skipped: 0, Bytes read: 7864
        Sys: 0.0044s User: 0.0013s Wall: 0.0017s flows/second: 1187.0 Runtime: 0.0017s
        TXT;

    test('reads every footer line', function () use ($footer): void {
        expect(NfdumpSummary::fromTextFooter($footer))->toBe([
            'flows' => 2,
            'bytes' => 2000,
            'packets' => 20,
            'avgBps' => 16000,
            'avgPps' => 20,
            'avgBpp' => 100,
            'windowStart' => '2026-08-29 05:17:53.000',
            'windowEnd' => '2026-08-29 05:17:54.000',
            'duration' => '00:00:01.000',
            'processed' => 2,
            'passed' => 75,
            'blocksSkipped' => 0,
            'bytesRead' => 7864,
            'sysSeconds' => 0.0044,
            'userSeconds' => 0.0013,
            'wallSeconds' => 0.0017,
        ]);
    });

    // Older releases say "flows processed" and print no duration.
    test('reads the older wording', function (): void {
        $summary = NfdumpSummary::fromTextFooter(implode("\n", [
            'Summary: total flows: 518178, total bytes: 27890469207, total packets: 35111524, avg bps: 25467, avg pps: 4, avg bpp: 794',
            'Time window: 2025-11-24 15:34:44 - 2026-03-06 01:12:34',
            'Total flows processed: 518178, passed: 518178, Blocks skipped: 0, Bytes read: 55015208',
        ]));

        expect($summary)->not->toBeNull()
            ->and($summary['bytes'])->toBe(27890469207)
            ->and($summary['windowStart'])->toBe('2025-11-24 15:34:44')
            ->and($summary['windowEnd'])->toBe('2026-03-06 01:12:34')
            ->and($summary['duration'])->toBeNull()
            ->and($summary['processed'])->toBe(518178)
            ->and($summary['wallSeconds'])->toBeNull()
        ;
    });

    // Without -N nfdump scales large counters.
    test('reads scaled counters', function (): void {
        $summary = NfdumpSummary::fromTextFooter('Summary: total flows: 21.6 k, total bytes: 14.8 M, total packets: 151200, avg bps: 1.2 G, avg pps: 151200, avg bpp: 97');

        expect($summary['flows'])->toBe(21600)
            ->and($summary['bytes'])->toBe(14800000)
            ->and($summary['avgBps'])->toBe(1200000000)
        ;
    });

    test('is null without a Summary line', function (): void {
        expect(NfdumpSummary::fromTextFooter("ts,te,td,pr,val\n2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,443\n"))->toBeNull()
            ->and(NfdumpSummary::fromTextFooter(''))->toBeNull()
        ;
    });
});
