<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\MultiStatCsvParser;

// nfdump 1.7.8 on the dev captures: -n 3 -o csv with the collector's eight statistics.
const MULTI_STAT_EIGHT = <<<'CSV'
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.24.1,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.20.1,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.19.1,1,1.3,10,1.9,1000,1.9,10,8000,100
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.1.16.2,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.1.18.2,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.1.19.2,1,1.3,10,1.9,1000,1.9,10,8000,100
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,10004,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,10007,1,1.3,10,1.9,1000,1.9,10,8000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,10033,1,1.3,10,1.9,1000,1.9,10,8000,100
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,443,40,53.3,400,76.2,40000,78.0,400,320000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,53,20,26.7,100,19.0,10000,19.5,100,80000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,GRE,53,5,6.7,5,1.0,300,0.6,5,2400,60
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,40,53.3,400,76.2,40000,78.0,400,320000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,17,20,26.7,100,19.0,10000,19.5,100,80000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,1,10,13.3,20,3.8,1000,1.9,20,8000,50
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,0,75,100.0,525,100.0,51300,100.0,525,410400,97
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,0,75,100.0,525,100.0,51300,100.0,525,410400,97
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,1,75,100.0,525,100.0,51300,100.0,525,410400,97

    CSV;

// The same file, a filter that matches nothing: nfdump names the empty result once, then prints every header.
const MULTI_STAT_EMPTY = <<<'CSV'
    No matching flows
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp

    CSV;

// The same file, -s dstport:p/bytes -n 6: ICMP rows carry type and code in `val`.
const MULTI_STAT_PORTS = <<<'CSV'
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,443,40,53.3,400,76.2,40000,78.0,400,320000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,53,20,26.7,100,19.0,10000,19.5,100,80000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,GRE,53,5,6.7,5,1.0,300,0.6,5,2400,60
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,20263,1,1.3,2,0.4,100,0.2,2,800,50
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,20007,1,1.3,2,0.4,100,0.2,2,800,50
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,19751,1,1.3,2,0.4,100,0.2,2,800,50

    CSV;

const MULTI_STAT_ELEMENTS = ['srcip', 'dstip', 'srcport:p', 'dstport:p', 'proto', 'srcas', 'dstas', 'inif'];

describe('MultiStatCsvParser::parse()', function (): void {
    test('splits an eight statistic run into one list per -s, in order', function (): void {
        $blocks = MultiStatCsvParser::parse(MULTI_STAT_EIGHT, MULTI_STAT_ELEMENTS);

        expect($blocks)->toHaveCount(8)
            ->and(array_column($blocks[0], 'key'))->toBe(['10.0.24.1', '10.0.20.1', '10.0.19.1'])
            ->and(array_column($blocks[1], 'key'))->toBe(['10.1.16.2', '10.1.18.2', '10.1.19.2'])
            ->and(array_column($blocks[4], 'key'))->toBe(['6', '17', '1'])
            ->and(array_column($blocks[4], 'proto'))->toBe(['TCP', 'UDP', 'ICMP'])
            ->and($blocks[5])->toBe([['key' => '0', 'proto' => 'any', 'flows' => 75, 'packets' => 525, 'bytes' => 51300, 'bytesPct' => 100.0]])
            ->and(array_column($blocks[7], 'key'))->toBe(['1'])
        ;
    });

    test('reads fl, pkt and byt as integers and bytP as the byte share', function (): void {
        $row = MultiStatCsvParser::parse(MULTI_STAT_EIGHT, MULTI_STAT_ELEMENTS)[3][0];

        expect($row)->toBe(['key' => '443/tcp', 'proto' => 'TCP', 'flows' => 40, 'packets' => 400, 'bytes' => 40000, 'bytesPct' => 78.0]);
    });

    test('keys :p ports by port and lower-case protocol and keeps only tcp, udp and sctp rows', function (): void {
        [$ports] = MultiStatCsvParser::parse(MULTI_STAT_PORTS, ['dstport:p/bytes']);

        expect(array_column($ports, 'key'))->toBe(['443/tcp', '53/udp']);
    });

    test('keeps ICMP type and code values for a plain port statistic', function (): void {
        [$ports] = MultiStatCsvParser::parse(MULTI_STAT_PORTS, ['dstport']);

        expect(array_column($ports, 'key'))->toBe(['443', '53', '53', '20263', '20007', '19751']);
    });

    test('an SCTP port row is a port', function (): void {
        $raw = "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,SCTP,2905,3,10.0,30,10.0,3000,10.0,30,24000,100\n";

        expect(MultiStatCsvParser::parse($raw, ['srcport:p'])[0][0]['key'])->toBe('2905/sctp');
    });

    test('header-only blocks are empty lists and the leading note is ignored', function (): void {
        expect(MultiStatCsvParser::parse(MULTI_STAT_EMPTY, ['srcip', 'dstport:p', 'proto']))->toBe([[], [], []]);
    });

    test('an empty block between two full ones keeps the order', function (): void {
        $raw = "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.0.1,1,50.0,2,50.0,200,50.0,2,1600,100\n"
            . "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,1,50.0,2,50.0,200,50.0,2,1600,100\n";

        $blocks = MultiStatCsvParser::parse($raw, ['srcip', 'dstip', 'proto']);

        expect(array_column($blocks[0], 'key'))->toBe(['10.0.0.1'])
            ->and($blocks[1])->toBe([])
            ->and(array_column($blocks[2], 'key'))->toBe(['6'])
        ;
    });

    test('reads the older ipkt/ibyt header by name', function (): void {
        $raw = "ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ipbs,ibpp\n"
            . "2024-01-01 00:00:00,2024-01-01 00:04:59,299.000,any,2001:db8::1,12,40.0,120,30.0,90000,62.5,0,2408,750\n"
            . "ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ipbs,ibpp\n"
            . "2024-01-01 00:00:00,2024-01-01 00:04:59,299.000,UDP,53,4,13.3,8,2.0,800,0.6,0,21,100\n";

        $blocks = MultiStatCsvParser::parse($raw, ['srcip', 'dstport:p']);

        expect($blocks[0])->toBe([['key' => '2001:db8::1', 'proto' => 'any', 'flows' => 12, 'packets' => 120, 'bytes' => 90000, 'bytesPct' => 62.5]])
            ->and($blocks[1][0]['key'])->toBe('53/udp')
            ->and($blocks[1][0]['bytesPct'])->toBe(0.6)
        ;
    });

    test('a header without a percentage column gives a null bytesPct', function (): void {
        $raw = "ts,te,td,pr,val,fl,pkt,byt\n2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.0.1,1,2,200\n";

        expect(MultiStatCsvParser::parse($raw, ['srcip'])[0][0]['bytesPct'])->toBeNull();
    });

    test('ignores lines that are not rows: notes, short lines, scaled numbers, blocks beyond the elements', function (): void {
        $raw = "Aggregated flows 3\n"
            . "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.0.1,1,50.0,2,50.0,200,50.0,2,1600,100\n"
            . "Summary: total flows: 2, total bytes: 400\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.0.2,1,50.0,2,50.0,1.2 M,50.0,2,1600,100\n"
            . "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,any,10.0.0.3,1,50.0,2,50.0,200,50.0,2,1600,100\n";

        $blocks = MultiStatCsvParser::parse($raw, ['srcip']);

        expect($blocks)->toHaveCount(1)
            ->and(array_column($blocks[0], 'key'))->toBe(['10.0.0.1'])
        ;
    });

    test('a block nfdump did not print is an empty list', function (): void {
        expect(MultiStatCsvParser::parse('', ['srcip', 'outif']))->toBe([[], []])
            ->and(MultiStatCsvParser::parse("ts,te,td,pr,val,fl\r\n", ['srcip', 'outif']))->toBe([[], []])
        ;
    });

    test('blockCount() counts the statistic headers', function (): void {
        expect(MultiStatCsvParser::blockCount(MULTI_STAT_EIGHT))->toBe(8)
            ->and(MultiStatCsvParser::blockCount(MULTI_STAT_EMPTY))->toBe(3)
            ->and(MultiStatCsvParser::blockCount("No matching flows\n"))->toBe(0)
        ;
    });
});
