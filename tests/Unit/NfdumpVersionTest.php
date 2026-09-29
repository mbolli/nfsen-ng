<?php

declare(strict_types=1);

use mbolli\nfsen_ng\processor\Nfdump;

/** The NFDUMP_VERSION build argument a Dockerfile declares, relative to the repository root. */
function nfdumpVersionBuildArg(string $dockerfile): string {
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $dockerfile);
    preg_match_all('/^ARG NFDUMP_VERSION=(\S+)$/m', $text, $m);
    expect($m[1])->toHaveCount(1);

    return $m[1][0];
}

describe('nfdump build version', function (): void {
    test('both images build the same nfdump', function (): void {
        expect(nfdumpVersionBuildArg('deploy/Dockerfile.dev'))->toBe(nfdumpVersionBuildArg('deploy/Dockerfile'));
    });

    // 1.7.9 fixed remotely triggerable collector crashes; gcc builds before 1.7.10 leave -b/-B unpaired (#690).
    test('builds a release with the 1.7.9 security fixes and the -B pairing fix', function (): void {
        expect(version_compare(nfdumpVersionBuildArg('deploy/Dockerfile'), '1.7.10', '>='))->toBeTrue();
    });

    test('the download, the source directory and the version check follow the build argument', function (string $dockerfile): void {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $dockerfile);

        expect($text)->not->toMatch('/nfdump-\d|v\d+\.\d+\.\d+\.zip/')
            ->toContain('refs/tags/v${NFDUMP_VERSION}.zip')
            ->toContain('nfdump -V | grep -q "Version: ${NFDUMP_VERSION}-"')
        ;
    })->with(['deploy/Dockerfile', 'deploy/Dockerfile.dev']);

    test('the bare-metal instructions build the version the image ships', function (): void {
        $text = (string) file_get_contents(dirname(__DIR__, 2) . '/book/src/deployment/installation.md');
        preg_match_all('/refs\/tags\/v([\d.]+)\.zip|unzip v([\d.]+)\.zip|cd nfdump-([\d.]+)|nfdump ([\d.]+) from source|source-compiled nfdump ([\d.]+)/', $text, $m);
        $named = array_values(array_unique(array_filter(array_merge(...array_slice($m, 1)))));

        expect(count($m[0]))->toBe(5)
            ->and($named)->toBe([nfdumpVersionBuildArg('deploy/Dockerfile')])
        ;
    });

    test('the collector unit the bare-metal instructions install runs the nfcapd they build', function (): void {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents($root . '/book/src/deployment/installation.md');
        $unit = (string) file_get_contents($root . '/deploy/systemd/nfcapd.service');
        preg_match_all("/^sed -i 's\\|([^|]+)\\|([^|]*)\\|' deploy\\/systemd\\/nfcapd\\.service$/m", $page, $edits, PREG_SET_ORDER);
        foreach ($edits as [, $from, $to]) {
            $unit = str_replace($from, $to, $unit);
        }

        expect($page)->toContain('--prefix=/usr/local/nfdump ')
            ->and($unit)->toMatch('/^ExecStart=\/usr\/local\/nfdump\/bin\/nfcapd /m')
        ;
    });
});

describe('bidirectional pairing, fixed in 1.7.10 (#690)', function (): void {
    // Verbatim 1.7.10 stdout of the app's `-s record/bytes -B -N` over 12 request/response pairs and
    // 3 one-way flows, timing line left out. The 1.7.8 build listed all 27 flows unpaired.
    $output = [
        'Top 30 flows ordered by bytes:',
        'Date first seen         Duration         Proto      Src IP Addr:Port           Dst IP Addr:Port   Out Pkt   In Pkt Out Byte  In Byte Flows',
        '2026-09-29 11:38:37.120            0.061 17       10.100.12.206:55012 <->       10.50.16.1:53         912     1012   133200   169200     2',
        '2026-09-29 11:38:37.110            0.061 6        10.100.11.206:55011 <->       10.50.16.4:445        911     1011   133100   169100     2',
        '2026-09-29 11:38:37.100            0.061 6        10.100.10.206:55010 <->       10.50.16.3:445        910     1010   133000   169000     2',
        '2026-09-29 11:38:37.090            0.061 17        10.100.9.206:55009 <->       10.50.16.2:53         909     1009   132900   168900     2',
        '2026-09-29 11:38:37.080            0.061 6         10.100.8.206:55008 <->       10.50.16.1:445        908     1008   132800   168800     2',
        '2026-09-29 11:38:37.070            0.061 6         10.100.7.206:55007 <->       10.50.16.4:445        907     1007   132700   168700     2',
        '2026-09-29 11:38:37.060            0.061 17        10.100.6.206:55006 <->       10.50.16.3:53         906     1006   132600   168600     2',
        '2026-09-29 11:38:37.050            0.061 6         10.100.5.206:55005 <->       10.50.16.2:445        905     1005   132500   168500     2',
        '2026-09-29 11:38:37.040            0.061 6         10.100.4.206:55004 <->       10.50.16.1:445        904     1004   132400   168400     2',
        '2026-09-29 11:38:37.030            0.061 17        10.100.3.206:55003 <->       10.50.16.4:53         903     1003   132300   168300     2',
        '2026-09-29 11:38:37.020            0.061 6         10.100.2.206:55002 <->       10.50.16.3:445        902     1002   132200   168200     2',
        '2026-09-29 11:38:37.010            0.061 6         10.100.1.206:55001 <->       10.50.16.2:445        901     1001   132100   168100     2',
        '2026-09-29 11:38:37.503            0.056 6          192.168.9.3:40003 <->      203.0.113.7:443          0       30        0     4500     1',
        '2026-09-29 11:38:37.502            0.056 6          192.168.9.2:40002 <->      203.0.113.7:443          0       20        0     3000     1',
        '2026-09-29 11:38:37.501            0.056 6          192.168.9.1:40001 <->      203.0.113.7:443          0       10        0     1500     1',
        'Summary: total flows: 27, total bytes: 3624600, total packets: 23016, avg bps: 52817486, avg pps: 41923, avg bpp: 157',
        'Time window: 2026-09-29 11:38:37.010 - 2026-09-29 11:38:37.559, Duration:    00:00:00.549',
        'Total records processed: 27, passed: 27, Blocks skipped: 0, Bytes read: 2872',
    ];

    test('reads a merged pair with both directions filled', function () use ($output): void {
        $rows = Nfdump::parseBidirectionalOutput($output);

        expect($rows)->toHaveCount(15)
            ->and($rows[0])->toBe([
                'firstSeen' => '2026-09-29 11:38:37.120',
                'duration' => '0.061',
                'proto' => '17',
                'srcAddr' => '10.100.12.206',
                'srcPort' => '55012',
                'dstAddr' => '10.50.16.1',
                'dstPort' => '53',
                'outPackets' => '912',
                'inPackets' => '1012',
                'outBytes' => '133200',
                'inBytes' => '169200',
                'flows' => '2',
            ])
        ;
    });

    test('keeps a flow without a reverse as a one-sided row', function () use ($output): void {
        $rows = Nfdump::parseBidirectionalOutput($output);
        $paired = array_filter($rows, static fn (array $row): bool => $row['flows'] === '2' && $row['outPackets'] !== '0' && $row['outBytes'] !== '0');

        expect($paired)->toHaveCount(12)
            ->and($rows[14])->toMatchArray([
                'srcAddr' => '192.168.9.1',
                'dstAddr' => '203.0.113.7',
                'outPackets' => '0',
                'inPackets' => '10',
                'outBytes' => '0',
                'inBytes' => '1500',
                'flows' => '1',
            ])
        ;
    });

    // Sends a TCP flow and its reverse to nfcapd as NetFlow v5, then reads the file back with -B.
    test('the installed nfdump merges both directions of a flow', function (): void {
        $bin = '/usr/local/nfdump/bin';
        if (!is_executable($bin . '/nfcapd') || !is_executable($bin . '/nfdump')) {
            $this->markTestSkipped('nfdump is not installed here');
        }
        $version = Nfdump::version($bin . '/nfdump');
        if (version_compare($version, '1.7.10', '<')) {
            $this->markTestSkipped("nfdump {$version} predates the -B pairing fix");
        }

        $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        expect($probe)->not->toBeFalse();
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $waitFor = static function (callable $ready): void {
            for ($i = 0; $i < 100 && !$ready(); ++$i) {
                usleep(20_000);
            }
        };
        $uptime = 600_000;
        // A NetFlow v5 record: addresses and next hop, interfaces, counters and uptimes, then ports, flags and proto.
        $record = static fn (string $src, string $dst, int $srcPort, int $dstPort, int $packets, int $bytes): string => pack('NNN', ip2long($src), ip2long($dst), 0)
            . pack('nnNNNN', 1, 2, $packets, $bytes, $uptime - 1000, $uptime - 900)
            . pack('nnCCCCnnCCn', $srcPort, $dstPort, 0, 0x1B, 6, 0, 0, 0, 24, 24, 0);

        $out = [];
        $dir = sys_get_temp_dir() . '/nfsen-bidir-' . bin2hex(random_bytes(4));
        $log = $dir . '.log';
        mkdir($dir);

        try {
            $nfcapd = proc_open(
                [$bin . '/nfcapd', '-w', $dir, '-b', '127.0.0.1', '-p', (string) $port],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']],
                $pipes,
            );
            expect($nfcapd)->not->toBeFalse();

            try {
                // nfcapd opens its temp files once the port is bound, and logs the exporter once it has read the packet.
                $waitFor(static fn (): bool => (glob($dir . '/*') ?: []) !== []);
                $sock = stream_socket_client("udp://127.0.0.1:{$port}");
                fwrite($sock, pack('nnNNNNCCn', 5, 2, $uptime, time(), 0, 0, 0, 0, 0)
                    . $record('10.0.0.1', '10.0.0.2', 50000, 445, 10, 1000)
                    . $record('10.0.0.2', '10.0.0.1', 445, 50000, 8, 800));
                fclose($sock);
                $waitFor(static fn (): bool => str_contains((string) file_get_contents($log), 'New exporter'));
            } finally {
                proc_terminate($nfcapd);
                $waitFor(static fn (): bool => !proc_get_status($nfcapd)['running']);
                if (proc_get_status($nfcapd)['running']) {
                    proc_terminate($nfcapd, 9);
                }
                proc_close($nfcapd);
            }

            // A 5-minute slot boundary during the run leaves an extra, empty file, so read the whole directory.
            exec(escapeshellarg($bin . '/nfdump') . ' -R ' . escapeshellarg($dir) . ' -B -N 2>/dev/null', $out);
        } finally {
            array_map(unlink(...), [...(glob($dir . '/*') ?: []), ...(is_file($log) ? [$log] : [])]);
            rmdir($dir);
        }

        $rows = Nfdump::parseBidirectionalOutput($out);

        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toMatchArray([
                'srcAddr' => '10.0.0.1',
                'srcPort' => '50000',
                'dstAddr' => '10.0.0.2',
                'dstPort' => '445',
                'outPackets' => '8',
                'inPackets' => '10',
                'outBytes' => '800',
                'inBytes' => '1000',
                'flows' => '2',
            ])
        ;
    });
});
