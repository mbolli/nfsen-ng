<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSummary;

/** Minimal config for Nfdump, with the binary it should run. */
function nfdumpTestSettings(string $binary = '/usr/bin/nfdump'): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80, 443],
            'sources' => ['gateway'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => $binary,
            'profiles-data' => '/tmp/test-profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ]);
}

/**
 * Runs Nfdump::execute() against tests/Support/bin/nfdump-canned, which prints the given
 * stdout and stderr and exits with the given code.
 *
 * @param array<string, mixed> $options
 *
 * @return array<string, mixed>
 */
function runCannedNfdump(string $stdout, string $stderr = '', int $exit = 0, array $options = ['-o' => 'csv'], string $filter = ''): array {
    putenv('NFDUMP_STUB_STDOUT=' . $stdout);
    putenv('NFDUMP_STUB_STDERR=' . $stderr);
    putenv('NFDUMP_STUB_EXIT=' . $exit);

    $nfdump = new Nfdump();
    foreach ($options as $option => $value) {
        // A numeric flag such as -6 comes back from the array as an int key.
        $nfdump->setOption((string) $option, $value);
    }
    $nfdump->setFilter($filter);

    return $nfdump->execute();
}

beforeAll(function (): void {
    nfdumpTestSettings();
});

describe('Nfdump', function (): void {
    describe('get_output_format', function (): void {
        test('returns line format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('line');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('td')
                ->toContain('pr')
                ->toContain('sa')
                ->toContain('sp')
                ->toContain('da')
                ->toContain('dp')
            ;
        });

        test('returns long format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('long');

            expect($result)
                ->toBeArray()
                ->toContain('flg')
                ->toContain('stos')
                ->toContain('dtos')
            ;
        });

        test('returns extended format fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('extended');

            expect($result)
                ->toBeArray()
                ->toContain('ibps')
                ->toContain('ipps')
                ->toContain('ibpp')
            ;
        });

        test('returns full format with all fields', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('full');

            expect($result)
                ->toBeArray()
                ->toHaveCount(48)
            ;
        });

        test('parses custom format string', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('fmt:%ts %sa %da');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('sa')
                ->toContain('da')
            ;
        });

        test('handles format with percent signs', function (): void {
            $nfdump = new Nfdump();
            $result = $nfdump->get_output_format('%ts %td %pr');

            expect($result)
                ->toBeArray()
                ->toContain('ts')
                ->toContain('td')
                ->toContain('pr')
            ;
        });
    });

    describe('parseVersion', function (): void {
        // Verbatim `nfdump -V` first lines. Note the capitalisation of "Options"/"options"
        // and the wording of the date field differ between releases.
        test('extracts the numeric version, dropping the -release suffix', function (): void {
            expect(Nfdump::parseVersion('nfdump: Version: 1.7.3-release Options: NSEL-NEL Date: Mon Apr 1 09:36:05 2024'))->toBe('1.7.3');
            expect(Nfdump::parseVersion('nfdump: Version: 1.7.5-release Options: NSEL-NEL ZSTD BZIP2 Date: Wed Oct 23 19:53:03 CEST 2024'))->toBe('1.7.5');
            expect(Nfdump::parseVersion('/usr/local/nfdump/bin/nfdump: Version: 1.7.8-release options: lz4 ZSTD BZIP2 date: Fri Apr 18 15:22:34 CEST 2025'))->toBe('1.7.8');
        });

        test('returns an empty string when no version can be read', function (): void {
            expect(Nfdump::parseVersion(''))->toBe('');
            expect(Nfdump::parseVersion('nfdump: command not found'))->toBe('');
        });
    });

    describe('parseBidirectionalOutput', function (): void {
        // Real nfdump 1.7.8 output for `-s record/bytes -B -N`.
        $output = [
            'Top 2 flows ordered by bytes:',
            'Date first seen         Duration         Proto      Src IP Addr:Port           Dst IP Addr:Port   Out Pkt   In Pkt Out Byte  In Byte Flows',
            '2026-08-29 05:17:53.000            1.000 6            10.0.37.1:10037 <->        10.1.37.2:443          0     2880        0   288000   288',
            '2026-08-29 05:17:53.000            1.000 1            10.0.69.1:0     <->        10.1.69.2:85.39        0       88        0     4400    44',
            'Summary: total flows: 21600, total bytes: 14774400, total packets: 151200, avg bps: 118195200, avg pps: 151200, avg bpp: 97',
            'Time window: 2026-08-29 05:17:53.000 - 2026-09-17 18:25:00.000, Duration:19d 13:07:07.000',
            'Total records processed: 21600, passed: 21600, Blocks skipped: 0, Bytes read: 2264832',
        ];

        test('reads the merged-flow rows and skips the header and summary', function () use ($output): void {
            $rows = Nfdump::parseBidirectionalOutput($output);

            expect($rows)->toHaveCount(2)
                ->and($rows[0])->toBe([
                    'firstSeen' => '2026-08-29 05:17:53.000',
                    'duration' => '1.000',
                    'proto' => '6',
                    'srcAddr' => '10.0.37.1',
                    'srcPort' => '10037',
                    'dstAddr' => '10.1.37.2',
                    'dstPort' => '443',
                    'outPackets' => '0',
                    'inPackets' => '2880',
                    'outBytes' => '0',
                    'inBytes' => '288000',
                    'flows' => '288',
                ])
            ;
        });

        // ICMP puts type.code where the port goes, which must not be mistaken for a column.
        test('keeps an ICMP type.code in the port field', function () use ($output): void {
            $rows = Nfdump::parseBidirectionalOutput($output);

            expect($rows[1]['dstPort'])->toBe('85.39')
                ->and($rows[1]['dstAddr'])->toBe('10.1.69.2')
            ;
        });

        // nfdump writes an IPv6 endpoint as address.port, not address:port (output_fmt.c,
        // String_SrcAddrPort), so splitting on the last colon would eat part of the address.
        test('splits an IPv6 endpoint on the dot, not the colon', function (): void {
            $rows = Nfdump::parseBidirectionalOutput([
                '2026-08-29 05:17:53.000            1.000 6      2001:db8::1.10037 <->     2001:db8::2.443          0     2880        0   288000   288',
            ]);

            expect($rows[0]['srcAddr'])->toBe('2001:db8::1')
                ->and($rows[0]['srcPort'])->toBe('10037')
                ->and($rows[0]['dstAddr'])->toBe('2001:db8::2')
                ->and($rows[0]['dstPort'])->toBe('443')
            ;
        });

        // Anything longer than 16 characters is printed condensed, middle replaced by '..',
        // and the tail still contains colons.
        test('splits a condensed IPv6 endpoint', function (): void {
            $rows = Nfdump::parseBidirectionalOutput([
                '2026-08-29 05:17:53.000            1.000 6      2001:62..e0:fed5.10037 <->     2001:62..e0:fed6.443          0     2880        0   288000   288',
            ]);

            expect($rows[0]['srcAddr'])->toBe('2001:62..e0:fed5')
                ->and($rows[0]['srcPort'])->toBe('10037')
                ->and($rows[0]['dstAddr'])->toBe('2001:62..e0:fed6')
                ->and($rows[0]['dstPort'])->toBe('443')
            ;
        });

        // The caller renders the raw text when this returns nothing, so an output shape the
        // parser does not recognise has to be reported rather than half-read.
        test('gives up on a row it cannot account for', function (): void {
            expect(Nfdump::parseBidirectionalOutput([
                '2026-08-29 05:17:53.000            1.000 6            10.0.37.1:10037 <->        10.1.37.2:443    288',
            ]))->toBe([]);
        });

        test('has nothing to say about output with no rows', function (): void {
            expect(Nfdump::parseBidirectionalOutput(['No matching flows', '']))->toBe([]);
        });
    });

    describe('withoutBenignStderr', function (): void {
        function benignFiltered(string $stderr): string {
            $m = (new ReflectionClass(Nfdump::class))->getMethod('withoutBenignStderr');
            $m->setAccessible(true);

            return $m->invoke(null, $stderr);
        }

        // nfdump prints this for every aggregated statistic and honours the -A spec anyway,
        // so surfacing it would put a warning on a query that worked (#174).
        test('drops the note that -s takes precedence over -a', function (): void {
            expect(benignFiltered('Command line switch -s overwrites -a'))->toBe('');
        });

        // A capture file still open in nfcapd.
        test('drops the read() error that reports Success', function (): void {
            expect(benignFiltered('read() error: Success'))->toBe('');
        });

        test('keeps a message that says something about the result', function (): void {
            expect(benignFiltered('Unknown filter token'))->toBe('Unknown filter token');
        });

        // The panels show what survives, so one real problem must not be hidden by the
        // benign line nfdump printed beside it.
        test('keeps the real message out of a mixed block', function (): void {
            $filtered = benignFiltered("Command line switch -s overwrites -a\nUnknown filter token\n");

            expect($filtered)->toBe('Unknown filter token');
        });
    });

    describe('needsAggregatedCsv', function (): void {
        // 1.7.5 is the only release that refuses a custom fmt: format with -A aggregation:
        // 1.7.2-1.7.4 accept it, and 1.7.6 added user formats for custom aggregation. See #159.
        test('is true for 1.7.5 only', function (): void {
            expect(Nfdump::needsAggregatedCsv('1.7.5'))->toBeTrue();
            expect(Nfdump::needsAggregatedCsv('1.7.5.1'))->toBeTrue(); // hypothetical patch release
        });

        test('is false for releases that accept a custom fmt: with aggregation', function (): void {
            foreach (['1.7.2', '1.7.3', '1.7.4', '1.7.6', '1.7.7', '1.7.8', '1.8.0'] as $version) {
                expect(Nfdump::needsAggregatedCsv($version))->toBeFalse();
            }
        });

        test('falls back to the fmt: default when the version is unknown', function (): void {
            expect(Nfdump::needsAggregatedCsv(''))->toBeFalse();
        });
    });

    describe('parseWhitespaceDelimitedAggregation', function (): void {
        // Sample lines captured from a real `nfdump -a -A srcip,dstip -O bytes -n 5 -N
        // -o 'fmt:%sa %da %ibyt %ipkt %fl'` run, including the header row and footer/summary
        // lines that must be skipped rather than mis-parsed as data rows.
        $headers = ['sa', 'da', 'ibyt', 'ipkt', 'fl'];

        test('parses data rows into structured associative arrays', function () use ($headers): void {
            $lines = [
                '  172.24.154.108     149.126.4.47 1658542255   114225  1290',
                '  172.24.154.108    140.82.113.22 1189726929   763701  6050',
            ];

            $result = Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers);

            expect($result)->toHaveCount(2);
            expect($result[0])->toBe([
                'sa' => '172.24.154.108',
                'da' => '149.126.4.47',
                'ibyt' => '1658542255',
                'ipkt' => '114225',
                'fl' => '1290',
            ]);
            expect($result[1]['da'])->toBe('140.82.113.22');
        });

        test('skips the human-readable header row', function () use ($headers): void {
            $lines = ['     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows'];

            expect(Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers))->toBe([]);
        });

        test('skips summary, time window, and footer lines', function () use ($headers): void {
            $lines = [
                'Summary: total flows: 518178, total bytes: 27890469207, total packets: 35111524, avg bps: 25467, avg pps: 4, avg bpp: 794',
                'Time window: 2025-11-24 15:34:44 - 2026-03-06 01:12:34',
                'Total flows processed: 518178, passed: 518178, Blocks skipped: 0, Bytes read: 55015208',
                'Sys: 1.9041s User: 0.3358s Wall: 2.4564s flows/second: 210953.4 Runtime: 2.4566s',
            ];

            expect(Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers))->toBe([]);
        });

        test('skips blank lines', function () use ($headers): void {
            $lines = ['', '   ', '  172.24.154.108     149.126.4.47 1658542255   114225  1290'];

            expect(Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers))->toHaveCount(1);
        });

        // The token count alone let a footer line through whenever it happened to match.
        test('keeps a line only when its address columns hold addresses', function (): void {
            $lines = [
                'Time window: <unknown>',
                'No matching flows',
                '  10.0.37.1   10.1.37.2   288000',
            ];

            expect(Nfdump::parseWhitespaceDelimitedAggregation($lines, ['sa', 'da', 'ibyt']))->toBe([
                ['sa' => '10.0.37.1', 'da' => '10.1.37.2', 'ibyt' => '288000'],
            ])->and(Nfdump::parseWhitespaceDelimitedAggregation(['Time window: <unknown>'], ['sa', 'ibyt', 'fl']))->toBe([]);
        });

        // With -6 nfdump prints full IPv6 addresses; the condensed form without it is no address.
        test('reads full IPv6 addresses and drops condensed ones', function () use ($headers): void {
            $lines = [
                '2001:db8:0:0:0:0:0:1   2001:db8::2   500   5   1',
                '2001:62..e0:fed5   2001:db8::2   500   5   1',
            ];

            expect(Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers))->toBe([
                ['sa' => '2001:db8:0:0:0:0:0:1', 'da' => '2001:db8::2', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
            ]);
        });

        test('leaves formats without address columns to the token count', function (): void {
            expect(Nfdump::parseWhitespaceDelimitedAggregation(['443   500'], ['dp', 'ibyt']))->toBe([['dp' => '443', 'ibyt' => '500']]);
        });

        test('handles a full realistic multi-line output block', function () use ($headers): void {
            $lines = [
                '     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows',
                '  172.24.154.108     149.126.4.47 1658542255   114225  1290',
                '  172.24.154.108    140.82.113.22 1189726929   763701  6050',
                '    3.165.190.89   172.24.154.108 1058946029    33241     2',
                '  172.24.154.108    140.82.112.21 1020774220   686645  5761',
                ' 192.178.170.207   172.24.154.108  981611937   103935    77',
                'Summary: total flows: 518178, total bytes: 27890469207, total packets: 35111524, avg bps: 25467, avg pps: 4, avg bpp: 794',
                'Time window: 2025-11-24 15:34:44 - 2026-03-06 01:12:34',
                'Total flows processed: 518178, passed: 518178, Blocks skipped: 0, Bytes read: 55015208',
                'Sys: 1.9041s User: 0.3358s Wall: 2.4564s flows/second: 210953.4 Runtime: 2.4566s',
            ];

            $result = Nfdump::parseWhitespaceDelimitedAggregation($lines, $headers);

            expect($result)->toHaveCount(5);
            expect($result[2])->toBe([
                'sa' => '3.165.190.89',
                'da' => '172.24.154.108',
                'ibyt' => '1058946029',
                'ipkt' => '33241',
                'fl' => '2',
            ]);
        });
    });

    describe('normalizeAddressFamilyKeys', function (): void {
        // Record shapes captured from a real `nfdump -r <file> -o json` run (1.7.3): an IPv4
        // TCP record and an IPv6 ICMP record, which nfdump emits with different key names.
        $v4 = [
            'type' => 'FLOW',
            'proto' => 17,
            'src_port' => 123,
            'dst_port' => 46707,
            'src4_addr' => '185.125.190.56',
            'dst4_addr' => '192.168.2.3',
            'ip4_router' => '127.0.0.1',
        ];
        $v6 = [
            'type' => 'FLOW',
            'proto' => 58,
            'icmp_type' => 0,
            'src6_addr' => 'fe80::642:1aff:fecf:210',
            'dst6_addr' => 'fe80::50e8:8425:7304:e23d',
            'ip4_router' => '127.0.0.1',
        ];

        test('renames IPv4 address keys to family-agnostic ones', function () use ($v4): void {
            $result = Nfdump::normalizeAddressFamilyKeys([$v4]);

            expect($result[0])->toHaveKeys(['src_addr', 'dst_addr', 'ip_router']);
            expect($result[0])->not->toHaveKey('src4_addr');
            expect($result[0]['src_addr'])->toBe('185.125.190.56');
        });

        test('renames IPv6 address keys to the same family-agnostic ones', function () use ($v6): void {
            $result = Nfdump::normalizeAddressFamilyKeys([$v6]);

            expect($result[0])->not->toHaveKey('src6_addr');
            expect($result[0]['src_addr'])->toBe('fe80::642:1aff:fecf:210');
            expect($result[0]['dst_addr'])->toBe('fe80::50e8:8425:7304:e23d');
        });

        test('gives mixed v4/v6 result sets one common address schema', function () use ($v4, $v6): void {
            $result = Nfdump::normalizeAddressFamilyKeys([$v4, $v6]);

            expect(array_keys($result[0]))->toContain('src_addr', 'dst_addr');
            expect(array_keys($result[1]))->toContain('src_addr', 'dst_addr');
        });

        test('preserves key order and untouched fields', function () use ($v4): void {
            $result = Nfdump::normalizeAddressFamilyKeys([$v4]);

            expect(array_keys($result[0]))->toBe([
                'type', 'proto', 'src_port', 'dst_port', 'src_addr', 'dst_addr', 'ip_router',
            ]);
        });

        test('renames tunnel, translated and next-hop address keys', function (): void {
            $result = Nfdump::normalizeAddressFamilyKeys([[
                'src4_tun_ip' => '10.0.0.1',
                'dst6_tun_ip' => '2001:db8::1',
                'src4_xlt_ip' => '10.0.0.2',
                'dst6_xlt_ip' => '2001:db8::2',
                'ip6_next_hop' => '2001:db8::3',
                'bgp4_next_hop' => '10.0.0.3',
            ]]);

            expect(array_keys($result[0]))->toBe([
                'src_tun_ip', 'dst_tun_ip', 'src_xlt_ip', 'dst_xlt_ip', 'ip_next_hop', 'bgp_next_hop',
            ]);
        });

        test('leaves non-address keys alone', function (): void {
            $record = ['in_packets' => 1, 'in_bytes' => 64, 'src_geo' => '', 'mpls1' => '0-0-0'];

            expect(Nfdump::normalizeAddressFamilyKeys([$record])[0])->toBe($record);
        });

        test('keeps the filled value when both family variants are present', function (): void {
            $result = Nfdump::normalizeAddressFamilyKeys([[
                'src4_addr' => '',
                'src6_addr' => '2001:db8::1',
            ]]);

            expect($result[0]['src_addr'])->toBe('2001:db8::1');
        });

        test('returns an empty array unchanged', function (): void {
            expect(Nfdump::normalizeAddressFamilyKeys([]))->toBe([]);
        });
    });

    describe('setFilter', function (): void {
        test('accepts filter string', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('src ip 192.168.1.1');

            // No exception means success
            expect(true)->toBeTrue();
        });

        test('accepts empty filter', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('');

            expect(true)->toBeTrue();
        });

        test('accepts complex filter', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('src ip 192.168.1.0/24 and dst port 443 and proto tcp');

            expect(true)->toBeTrue();
        });
    });

    describe('reset', function (): void {
        test('resets configuration', function (): void {
            $nfdump = new Nfdump();
            $nfdump->setFilter('test filter');
            $nfdump->reset();

            // After reset, filter should be empty
            // We can't directly test private state, but no exception means success
            expect(true)->toBeTrue();
        });
    });

    describe('convert_date_to_path', function (): void {
        // Base path mirrors the Config set in beforeAll
        $base = '/tmp/test-profiles-data/live/gateway';

        // Helper: create a directory and touch a fake nfcapd file inside it.
        $mkfile = static function (string $base, string $yyyymmddhhi): string {
            $day = substr($yyyymmddhhi, 0, 4) . '/' . substr($yyyymmddhhi, 4, 2) . '/' . substr($yyyymmddhhi, 6, 2);
            $dir = $base . '/' . $day;
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            touch($dir . '/nfcapd.' . $yyyymmddhhi);

            return $day . '/nfcapd.' . $yyyymmddhhi;
        };

        // Helper: remove a directory tree created by $mkfile.
        $rmdir = static function (string $path): void {
            if (!is_dir($path)) {
                return;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
            }
            rmdir($path);
        };

        test('finds start and end file when data covers the full range', function () use ($base, $mkfile, $rmdir): void {
            $startFile = $mkfile($base, '202401010000');
            $endFile = $mkfile($base, '202401311200');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-01-01 00:00'))->getTimestamp(),
                (new DateTime('2024-01-31 12:00'))->getTimestamp()
            );

            expect($result)->toBe($startFile . PATH_SEPARATOR . $endFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });

        test('finds start file when data starts months into a year range (year-range bug)', function () use ($base, $mkfile, $rmdir): void {
            // Simulate: year range requested (Apr 2024 → Apr 2025),
            // but actual data only starts in October 2024.
            $firstFile = $mkfile($base, '202410010000');
            $lastFile = $mkfile($base, '202501150000');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-04-29 00:00'))->getTimestamp(), // 6 months before any data
                (new DateTime('2025-01-15 00:00'))->getTimestamp()
            );

            expect($result)->toBe($firstFile . PATH_SEPARATOR . $lastFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
            $rmdir('/tmp/test-profiles-data/live/gateway/2025');
        });

        test('picks earliest start file and latest end file on a day with multiple files', function () use ($base, $mkfile, $rmdir): void {
            $mkfile($base, '202406010000');
            $mkfile($base, '202406010500'); // later on same day
            $mkfile($base, '202406011000');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-06-01 00:00'))->getTimestamp(),
                (new DateTime('2024-06-01 10:00'))->getTimestamp()
            );

            [$start, $end] = explode(PATH_SEPARATOR, $result);
            expect($start)->toEndWith('nfcapd.202406010000');
            expect($end)->toEndWith('nfcapd.202406011000');

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });

        test('throws when no data exists anywhere in range', function () use ($rmdir): void {
            // Ensure no stale files exist
            $rmdir('/tmp/test-profiles-data/live/gateway/2020');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            expect(fn () => $nfdump->convert_date_to_path(
                (new DateTime('2020-01-01 00:00'))->getTimestamp(),
                (new DateTime('2020-01-07 00:00'))->getTimestamp()
            ))->toThrow(Exception::class, 'No nfcapd data files found for the requested time range.');
        });

        test('ignores files outside the requested time window', function () use ($base, $mkfile, $rmdir): void {
            // Only file on this day is BEFORE datestart, so it should skip forward to next day
            $mkfile($base, '202407010000'); // 00:00, before the 12:00 start
            $nextFile = $mkfile($base, '202407021200'); // next day, first valid file

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');

            $result = $nfdump->convert_date_to_path(
                (new DateTime('2024-07-01 12:00'))->getTimestamp(),
                (new DateTime('2024-07-02 23:00'))->getTimestamp()
            );

            [$start] = explode(PATH_SEPARATOR, $result);
            expect($start)->toBe($nextFile);

            $rmdir('/tmp/test-profiles-data/live/gateway/2024');
        });

        // Uses a dedicated year (2023) so these tests can't collide with the shared
        // '2024' directory used by other tests in this describe block.
        test('setOption(-R) finds no files when called before setOption(-M), even if matching files exist', function () use ($base, $mkfile, $rmdir): void {
            // setOption('-R', ...) calls convert_date_to_path() immediately, which reads
            // sources recorded by the '-M' handler. If '-R' is set first, sources are still
            // empty and no files are found. This is the trap AlertManager::fetchFilteredSlot()
            // hit (it set -R before -M, so filtered alert checks always evaluated to zero).
            $mkfile($base, '202306010000');
            $mkfile($base, '202306011000');

            $nfdump = new Nfdump();

            expect(fn () => $nfdump->setOption('-R', [
                (new DateTime('2023-06-01 00:00'))->getTimestamp(),
                (new DateTime('2023-06-01 10:00'))->getTimestamp(),
            ]))->toThrow(Exception::class, 'No nfcapd data files found for the requested time range.');

            $rmdir('/tmp/test-profiles-data/live/gateway/2023');
        });

        test('setOption(-M) then setOption(-R) finds files (correct order)', function () use ($base, $mkfile, $rmdir): void {
            $mkfile($base, '202306010000');
            $mkfile($base, '202306011000');

            $nfdump = new Nfdump();
            $nfdump->setOption('-M', 'gateway');
            $nfdump->setOption('-R', [
                (new DateTime('2023-06-01 00:00'))->getTimestamp(),
                (new DateTime('2023-06-01 10:00'))->getTimestamp(),
            ]);

            expect(true)->toBeTrue(); // no exception thrown

            $rmdir('/tmp/test-profiles-data/live/gateway/2023');
        });
    });

    describe('singleton pattern', function (): void {
        beforeEach(function (): void {
            // Reset singleton
            $reflection = new ReflectionClass(Nfdump::class);
            $property = $reflection->getProperty('_instance');
            $property->setAccessible(true);
            $property->setValue(null, null);
        });

        test('getInstance returns Nfdump instance', function (): void {
            $instance = Nfdump::getInstance();

            expect($instance)->toBeInstanceOf(Nfdump::class);
        });

        test('getInstance returns same instance', function (): void {
            $instance1 = Nfdump::getInstance();
            $instance2 = Nfdump::getInstance();

            expect($instance1)->toBe($instance2);
        });
    });
});

describe('Nfdump::flatten()', function (): void {
    function flattenOptions(array $options): string {
        $m = (new ReflectionClass(Nfdump::class))->getMethod('flatten');

        return $m->invoke(new Nfdump(), $options);
    }

    // The collector asks for eight statistics in one run, which is one -s per statistic.
    test('emits a list value as the flag once per element, in order', function (): void {
        expect(flattenOptions(['-s' => ['srcip/bytes', 'dstip/bytes', 'proto/bytes']]))
            ->toBe("-s 'srcip/bytes' -s 'dstip/bytes' -s 'proto/bytes'")
        ;
    });

    test('keeps flag order across scalar, bare and list values', function (): void {
        expect(flattenOptions(['-o' => 'csv', '-N' => null, '-s' => ['a', 'b'], '-n' => 50]))
            ->toBe("-o 'csv' -N -s 'a' -s 'b' -n '50'")
        ;
    });

    test('writes an empty value as a bare flag', function (): void {
        expect(flattenOptions(['-B' => '']))->toBe('-B');
    });

    test('an empty list emits nothing', function (): void {
        expect(flattenOptions(['-s' => []]))->toBe('');
    });

    test('quotes every value for the shell', function (): void {
        expect(flattenOptions(['-M' => "/data/it's here"]))->toBe("-M '/data/it'\\''s here'");
    });
});

describe('Nfdump::commandLine()', function (): void {
    test('ends the options before the filter', function (): void {
        $nfdump = new Nfdump();
        $nfdump->setOption('-o', 'csv');
        $nfdump->setOption('-s', ['srcip/bytes', 'dstip/bytes']);
        $nfdump->setFilter('proto tcp');

        expect($nfdump->commandLine())->toBe("/usr/bin/nfdump -o 'csv' -s 'srcip/bytes' -s 'dstip/bytes' -- 'proto tcp'");
    });

    test('has no end-of-options marker without a filter', function (): void {
        $nfdump = new Nfdump();
        $nfdump->setOption('-I', null);

        expect($nfdump->commandLine())->toBe("/usr/bin/nfdump -I -o 'csv'");
    });
});

/**
 * A stand-in nfdump that answers -V with $version and writes any other call's arguments, one per
 * line, to the returned args file.
 *
 * @return array{bin: string, args: string, dir: string}
 */
function versionedNfdump(string $version): array {
    $dir = sys_get_temp_dir() . '/nfsen-nfdump-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $bin = $dir . '/nfdump';
    $args = $dir . '/args';
    file_put_contents($bin, "#!/bin/sh\nif [ \"\$1\" = \"-V\" ]; then echo 'nfdump: Version: {$version}-release options: lz4 ZSTD'; exit 0; fi\nprintf '%s\\n' \"\$@\" > '{$args}'\n");
    chmod($bin, 0o755);

    return ['bin' => $bin, 'args' => $args, 'dir' => $dir];
}

describe('Nfdump filter threads (-W)', function (): void {
    afterEach(function (): void {
        foreach ($this->stubs ?? [] as $stub) {
            array_map('unlink', glob($stub['dir'] . '/*') ?: []);
            rmdir($stub['dir']);
        }
        nfdumpTestSettings();
    });

    test('workerThreads() passes the configured count to an nfdump that knows -W', function (): void {
        expect(Nfdump::workerThreads('1.7.8', 2))->toBe(2)
            ->and(Nfdump::workerThreads('1.7.3', 2))->toBe(2)
            ->and(Nfdump::workerThreads('1.7.10', 40))->toBe(16)
            ->and(Nfdump::workerThreads('1.8.0', 1))->toBe(1)
        ;
    });

    // 1.7.2 has no -W and answers it with its usage text, which would fail every query.
    test('workerThreads() passes nothing to an old or unknown nfdump, or when set to 0', function (): void {
        expect(Nfdump::workerThreads('1.7.2', 2))->toBe(0)
            ->and(Nfdump::workerThreads('', 2))->toBe(0)
            ->and(Nfdump::workerThreads('1.7.8', 0))->toBe(0)
        ;
    });

    test('every run passes -W 2 by default, after the query\'s own options', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.8');
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-o', 'csv');
        $nfdump->setOption('-s', 'srcip/bytes');
        $nfdump->setFilter('proto tcp');
        $command = $nfdump->commandLine();
        $nfdump->execute();

        expect($command)->toBe($stub['bin'] . " -o 'csv' -s 'srcip/bytes' -W 2 -- 'proto tcp'")
            ->and(file($stub['args'], FILE_IGNORE_NEW_LINES))->toBe(['-o', 'csv', '-s', 'srcip/bytes', '-W', '2', '--', 'proto tcp'])
        ;
    });

    // The worker announcement is the only stderr of a normal -W run: it must not become a warning.
    test('a run with -W reports no stderr', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.10');
        file_put_contents($stub['bin'], "#!/bin/sh\nif [ \"\$1\" = \"-V\" ]; then echo 'nfdump: Version: 1.7.10-release'; exit 0; fi\necho 'Using 2 worker threads (cores=20, requested=2, confMax=0)' >&2\nprintf 'ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\\n'\n");
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-o', 'csv');
        $nfdump->setOption('-s', 'srcip/bytes');
        $result = $nfdump->execute();

        expect($result['stderr'] ?? '')->toBe('')
            ->and($result['command'])->toContain(' -W 2')
        ;
    });

    // nfdump caps -W at the online cores and says so on every run, e.g. -W 2 on one CPU.
    test('the cap notice for more workers than cores is not stderr either', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.10');
        file_put_contents($stub['bin'], "#!/bin/sh\nif [ \"\$1\" = \"-V\" ]; then echo 'nfdump: Version: 1.7.10-release'; exit 0; fi\necho 'Limit requested workers: 2 to number of cores online 1.' >&2\necho 'Using 1 worker threads (cores=1, requested=2, confMax=0)' >&2\nprintf 'ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\\n'\n");
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-o', 'csv');
        $nfdump->setOption('-s', 'srcip/bytes');
        $result = $nfdump->execute();

        expect($result['stderr'] ?? '')->toBe('');
    });

    test('the import\'s -I run is capped too', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.10');
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-I', null);

        expect($nfdump->commandLine())->toBe($stub['bin'] . " -I -o 'csv' -W 2");
    });

    test('NFSEN_NFDUMP_WORKERS=0 leaves nfdump its own default', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.8');
        nfdumpTestSettings($stub['bin']);
        Config::$settings = Config::$settings->withNfdumpWorkers(0);

        $nfdump = new Nfdump();
        $nfdump->setOption('-I', null);

        expect($nfdump->commandLine())->toBe($stub['bin'] . " -I -o 'csv'");
    });

    test('nfdump 1.7.2 gets no -W', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.2');
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-I', null);

        expect($nfdump->commandLine())->not->toContain('-W');
    });

    test('a caller\'s own -W is not doubled', function (): void {
        $this->stubs[] = $stub = versionedNfdump('1.7.8');
        nfdumpTestSettings($stub['bin']);

        $nfdump = new Nfdump();
        $nfdump->setOption('-W', 1);

        expect(substr_count($nfdump->commandLine(), '-W'))->toBe(1)
            ->and($nfdump->commandLine())->toContain("-W '1'")
        ;
    });
});

describe('Nfdump::exitCodeFrom()', function (): void {
    // With OpenSwoole's process hook proc_close() returns the wait status: exit 254 is 65024.
    test('unpacks the wait status of a normal exit', function (): void {
        expect(Nfdump::exitCodeFrom(65024))->toBe(254)
            ->and(Nfdump::exitCodeFrom(256))->toBe(1)
            ->and(Nfdump::exitCodeFrom(31744))->toBe(124)
        ;
    });

    test('leaves a plain exit code and a signal alone', function (): void {
        expect(Nfdump::exitCodeFrom(0))->toBe(0)
            ->and(Nfdump::exitCodeFrom(254))->toBe(254)
            ->and(Nfdump::exitCodeFrom(15))->toBe(15)
            ->and(Nfdump::exitCodeFrom(-1))->toBe(-1)
        ;
    });
});

describe('Nfdump::polledExitCode()', function (): void {
    $spawn = static function (string $script) {
        $process = proc_open(['/bin/sh', '-c', $script], [], $pipes);
        expect($process)->toBeResource();

        return $process;
    };

    test('reads the exit code once the process has ended', function () use ($spawn): void {
        $process = $spawn('exit 254');

        expect(Nfdump::polledExitCode($process))->toBe(254);
        proc_close($process);
    });

    test('reads the signal that ended the process', function () use ($spawn): void {
        $process = $spawn('kill -TERM $$');

        expect(Nfdump::polledExitCode($process))->toBe(15);
        proc_close($process);
    });

    test('gives up on a process that does not end in time', function () use ($spawn): void {
        $process = $spawn('sleep 5');

        expect(Nfdump::polledExitCode($process, 0.05))->toBeNull();
        proc_terminate($process);
        proc_close($process);
    });
});

describe('Nfdump::execute()', function (): void {
    $stub = dirname(__DIR__) . '/Support/bin/nfdump-canned';

    beforeEach(function () use ($stub): void {
        nfdumpTestSettings($stub);
    });

    afterEach(function (): void {
        foreach (['NFDUMP_STUB_STDOUT', 'NFDUMP_STUB_STDERR', 'NFDUMP_STUB_EXIT', 'NFDUMP_STUB_ARGS'] as $name) {
            putenv($name);
        }
        nfdumpTestSettings();
    });

    test('returns the command as plain text and what nfdump said beside the data as notes', function () use ($stub): void {
        // 1.7.8 for a statistic with no rows.
        $stdout = "No matching flows\nts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n";
        $result = runCannedNfdump($stdout, options: ['-o' => 'csv', '-s' => 'srcip/bytes'], filter: 'proto tcp');

        expect($result['command'])->toBe($stub . " -o 'csv' -s 'srcip/bytes' -- 'proto tcp'")
            ->and($result['command'])->not->toContain('<')
            ->and($result['decoded'])->toBe([])
            ->and($result['rawOutput'])->toBe($stdout)
            ->and($result['exitCode'])->toBe(0)
            ->and($result['notes'][0])->toBe('No matching flows')
            ->and($result['notes'][1])->toStartWith('Execution time: ')
            ->and($result['notes'])->toHaveCount(2)
        ;
    });

    // The delimiter used to be read off the first line, which made the header a note here.
    test('reads the header after a leading "No matching flows"', function (): void {
        $result = runCannedNfdump("No matching flows\nts,te,td,pr,val,fl\n");

        expect($result['notes'])->not->toContain('ts,te,td,pr,val,fl');
    });

    test('decodes CSV rows', function (): void {
        $result = runCannedNfdump("firstSeen,duration,proto,flows\n2026-08-29 05:17:53.000,1.000,6,1\n2026-08-29 05:17:53.000,1.000,17,2\n");

        expect($result['decoded'])->toBe([
            ['firstSeen' => '2026-08-29 05:17:53.000', 'duration' => '1.000', 'proto' => '6', 'flows' => '1'],
            ['firstSeen' => '2026-08-29 05:17:53.000', 'duration' => '1.000', 'proto' => '17', 'flows' => '2'],
        ]);
    });

    test('decodes a JSON listing into one address schema', function (): void {
        $stdout = "[\n{\"src4_addr\" : \"10.0.0.1\", \"proto\" : 6},\n{\"src6_addr\" : \"2001:db8::1\", \"proto\" : 17}\n]\n";
        $result = runCannedNfdump($stdout, options: ['-o' => 'json']);

        expect($result['decoded'])->toBe([
            ['src_addr' => '10.0.0.1', 'proto' => 6],
            ['src_addr' => '2001:db8::1', 'proto' => 17],
        ])->and($result['notes'][0])->toStartWith('Execution time: ');
    });

    test('a JSON listing without its closing bracket still decodes, and broken JSON is an error', function (): void {
        $result = runCannedNfdump("[\n{\"src4_addr\" : \"10.0.0.1\", \"proto\" : 6}\n", options: ['-o' => 'json']);
        expect($result['decoded'])->toBe([['src_addr' => '10.0.0.1', 'proto' => 6]]);

        expect(fn () => runCannedNfdump("[\n{\"src4_addr\" : \n]\n", options: ['-o' => 'json']))
            ->toThrow(NfdumpException::class, 'Invalid JSON from nfdump')
        ;
    });

    test('the usage text is no result, and an error when nfdump failed', function (): void {
        expect(runCannedNfdump("usage nfdump [options] [\"filter\"]\n-h this text\n")['decoded'])->toBe([])
            ->and(fn () => runCannedNfdump("usage nfdump [options]\n", "bad option\n", 1))->toThrow(NfdumpException::class, 'bad option')
        ;
    });

    test('an empty JSON listing is an empty result with a note', function (): void {
        $result = runCannedNfdump("[\nNo matching flows\n\n]\n", options: ['-o' => 'json']);

        expect($result['decoded'])->toBe([])
            ->and($result['notes'])->toContain('No matching flows')
        ;
    });

    // Import::writeSourceData() and the top-N collector read these rows.
    test('decodes the -I statistics into metric rows', function (): void {
        $result = runCannedNfdump("Ident: none\nFlows: 75\nFlows_tcp: 40\nBytes: 51300\nFirst: 1787980673\nSequence failures: 0\n", options: ['-I' => null]);

        expect($result['decoded'][1])->toBe(['metric' => 'Flows', 'value' => '75'])
            ->and(NfdumpSummary::fromStatDump($result['decoded'])['bytes'])->toBe(51300)
        ;
    });

    // AlertManager sums a filtered rule's totals from this rawOutput, so it must be what nfdump printed.
    test('a per-protocol statistic comes back as printed, the Summary block of nfdump before 1.7.8 included', function () use ($stub): void {
        $stdout = "ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ibps,ibpp\n"
            . "2026-09-21 14:15:02,2026-09-21 14:19:56,294.539,TCP,6,281,70.2,6253,82.9,7723482,88.7,21,209778,1235\n"
            . "2026-09-21 14:15:07,2026-09-21 14:19:16,249.725,ICMP,1,12,3.0,26,0.3,3004,0.0,0,96,115\n"
            . "Summary\nflows,bytes,packets,avg_bps,avg_pps,avg_bpp\n293,7726486,6279,209874,21,1230\n";
        $result = runCannedNfdump($stdout, options: ['-s' => 'proto', '-n' => 0, '-o' => 'csv'], filter: 'dst port 443');

        expect($result['command'])->toBe($stub . " -s 'proto' -o 'csv' -n '0' -- 'dst port 443'")
            ->and($result['rawOutput'])->toBe($stdout)
            ->and(array_column(array_slice($result['decoded'], 0, 2), 'ibyt'))->toBe(['7723482', '3004'])
        ;
    });

    test('a CSV header alone is an empty result, not an error', function (): void {
        expect(runCannedNfdump("ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n")['decoded'])->toBe([])
            ->and(runCannedNfdump("firstSeen,duration,proto,srcAddr,srcPort,dstAddr,dstPort,packets,bytes,flows\n")['decoded'])->toBe([])
        ;
    });

    test('a lone line that is not data is an error, with no markup added', function (): void {
        try {
            runCannedNfdump("Error: something went wrong\n");
            $this->fail('expected an NfdumpException');
        } catch (NfdumpException $e) {
            expect($e->getMessage())->toBe('Error: something went wrong')
                ->and($e->getMessage())->not->toContain('<')
                ->and($e->command)->toContain('nfdump-canned')
            ;
        }
    });

    // nfdump could not open the file, say: nothing on stdout, the reason on stderr.
    test('a non-zero exit with empty stdout throws with the first stderr line', function (): void {
        try {
            runCannedNfdump('', "\nstat() error '/data/gw/nfcapd.202608290800': No such file or directory\nmore\n", 1);
            $this->fail('expected an NfdumpException');
        } catch (NfdumpException $e) {
            expect($e->getMessage())->toBe("stat() error '/data/gw/nfcapd.202608290800': No such file or directory")
                ->and($e->exitCode)->toBe(1)
                ->and($e->stderr)->toContain('more')
                ->and($e)->toBeInstanceOf(RuntimeException::class)
            ;
        }
    });

    // The Kill button sends SIGTERM; a statistic that had printed nothing yet leaves no output.
    test('a killed run says it was stopped', function (): void {
        foreach ([15, 9] as $signal) {
            try {
                runCannedNfdump('', '', $signal);
                $this->fail('expected an NfdumpException');
            } catch (NfdumpException $e) {
                expect($e->getMessage())->toBe('nfdump was stopped (signal ' . $signal . ')')
                    ->and($e->wasStopped())->toBeTrue()
                ;
            }
        }
    });

    test('only a killed run counts as stopped', function (): void {
        expect((new NfdumpException('x', exitCode: 254))->wasStopped())->toBeFalse()
            ->and((new NfdumpException('x', exitCode: 1))->wasStopped())->toBeFalse()
            ->and((new NfdumpException('x'))->wasStopped())->toBeFalse()
        ;
    });

    test('a non-zero exit with no explanation says so', function (): void {
        expect(fn () => runCannedNfdump('', '', 3))->toThrow(NfdumpException::class, 'nfdump exited with code 3');
    });

    // 1.7.8 and 1.7.10 announce the -W every run passes on stderr, before anything else and on failed runs too.
    test('a failure is never explained by the worker count -W makes nfdump announce', function (int $exit, string $message): void {
        try {
            runCannedNfdump('', "Using 2 worker threads (cores=20, requested=2, confMax=0)\n", $exit);
            $this->fail('expected an NfdumpException');
        } catch (NfdumpException $e) {
            expect($e->getMessage())->toBe($message)
                ->and($e->stderr)->toBe('')
            ;
        }
    })->with([
        'a crash' => [139, 'nfdump exited with code 139'],
        'a plain failure' => [1, 'nfdump exited with code 1'],
        'init' => [255, 'nfdump initialisation failed'],
        'internal' => [250, 'nfdump internal error'],
    ]);

    test('the worker count is dropped beside a real message', function (): void {
        try {
            runCannedNfdump('', "Using 1 worker threads (cores=4, requested=1, confMax=0)\nError open file: No such file or directory\n", 255);
            $this->fail('expected an NfdumpException');
        } catch (NfdumpException $e) {
            expect($e->getMessage())->toBe('nfdump initialisation failed: Error open file: No such file or directory')
                ->and($e->stderr)->toBe('Error open file: No such file or directory')
            ;
        }
    });

    // 1.7.8 prints a filter error on stdout and exits 254.
    test('a filter syntax error names nfdump\'s explanation', function (): void {
        try {
            runCannedNfdump("Line 1: syntax error at ''\n", '', 254, filter: 'proto tcp and');
            $this->fail('expected an NfdumpException');
        } catch (NfdumpException $e) {
            expect($e->getMessage())->toBe("Filter syntax error: syntax error at ''")
                ->and($e->exitCode)->toBe(254)
                ->and($e->command)->toEndWith("-- 'proto tcp and'")
            ;
        }
    });

    test('a comment closed on its own line by the composer adds no line number', function (): void {
        expect(fn () => runCannedNfdump("Line 1: Unknown protocol: foo at 'foo'\n", '', 254, filter: "(proto foo # my note\n) and (bytes > 1)"))
            ->toThrow(NfdumpException::class, "Filter syntax error: Unknown protocol: foo at 'foo'")
        ;
    });

    test('a multi-line filter keeps the line number', function (): void {
        expect(fn () => runCannedNfdump("Line 2: syntax error at 'x'\n", '', 254, filter: "proto tcp\nand x"))
            ->toThrow(NfdumpException::class, "Filter syntax error: Line 2: syntax error at 'x'")
        ;
    });

    // nfdump quotes a quoted filter string whole (1.7.8 output), so the message is only safe escaped.
    test('keeps nfdump\'s text verbatim, markup from the filter included', function (): void {
        $cases = [
            'proto "<b>x</b>"' => [
                "Line 1: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'\nValid protocols:\n  0: 0\n  1: ICMP\n",
                '',
                "Filter syntax error: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'",
            ],
            'host "<img src=x onerror=alert(1)>"' => [
                "Resolving <img src=x onerror=alert(1)> ...\nLine 1: Can not parse/lookup <img src=x onerror=alert(1)> to an IP address at '\"<img src=x onerror=alert(1)>\"'\n",
                "Failed to resolve IP address for <img src=x onerror=alert(1)>: Success\n",
                "Filter syntax error: Can not parse/lookup <img src=x onerror=alert(1)> to an IP address at '\"<img src=x onerror=alert(1)>\"'",
            ],
        ];

        foreach ($cases as $filter => [$stdout, $stderr, $message]) {
            try {
                runCannedNfdump($stdout, $stderr, 254, filter: $filter);
                $this->fail('expected an NfdumpException');
            } catch (NfdumpException $e) {
                expect($e->getMessage())->toBe($message);
            }
        }
    });

    test('keeps the line number of an error in a multi-line filter', function (): void {
        expect(fn () => runCannedNfdump("Line 2: syntax error at ''\n", '', 254, filter: "proto tcp\nand"))
            ->toThrow(NfdumpException::class, "Filter syntax error: Line 2: syntax error at ''")
        ;
    });

    test('a filter error reported on stderr only still reads as one', function (): void {
        expect(fn () => runCannedNfdump('', "Failed to resolve IP address for nonexistent.invalid: Unknown error\n", 254, filter: 'host nonexistent.invalid'))
            ->toThrow(NfdumpException::class, 'Filter syntax error: Failed to resolve IP address for nonexistent.invalid: Unknown error')
        ;
    });

    test('a binary that cannot be run says where it was looked for', function () use ($stub): void {
        expect(fn () => runCannedNfdump('', "sh: 1: nfdump: not found\n", 127))
            ->toThrow(NfdumpException::class, 'nfdump could not be started: sh: 1: nfdump: not found. Is it installed at ' . $stub . '?')
        ;
    });

    // 1.7.8 answers an unknown statistic or order with exit 1 and its option table on stdout.
    test('a rejected statistic throws with nfdump\'s reason, not an empty result', function (): void {
        $listing = "Available element statistics:\n record     srcip      dstip      ip         srcgeo\n"
            . " natip      natsrcport  natdstport  natport    iacl\n evrf       minttl     maxttl    \n See also nfdump(1)\n";

        foreach (['csv', 'json'] as $format) {
            try {
                runCannedNfdump($listing, "Unknown statistic: nevent\nFailed to parse element stat option: nevent/bytes\n", 1, ['-o' => $format, '-s' => 'nevent/bytes']);
                $this->fail('expected an NfdumpException');
            } catch (NfdumpException $e) {
                expect($e->getMessage())->toBe('Unknown statistic: nevent')
                    ->and($e->exitCode)->toBe(1)
                ;
            }
        }
    });

    test('a rejected stat order throws with nfdump\'s reason', function (): void {
        $listing = "Available stat print order:\n flows     packets   ipkg      opkg      bytes\n"
            . "Optionally add direction - :a for ascending or :d for descending values\n See also nfdump(1)\n";

        expect(fn () => runCannedNfdump($listing, "Unknown order option /bogus\nFailed to parse element stat option: srcip/bogus\n", 1, ['-o' => 'json', '-s' => 'srcip/bogus']))
            ->toThrow(NfdumpException::class, 'Unknown order option /bogus')
        ;
    });

    test('a non-zero exit with only a header throws', function (): void {
        expect(fn () => runCannedNfdump("firstSeen,proto\n", "nfdump gave up\n", 1))->toThrow(NfdumpException::class, 'nfdump gave up');
    });

    test('keeps the data of a run that exited non-zero, and notes the code', function (): void {
        $result = runCannedNfdump("firstSeen,proto\n2026-08-29 05:17:53.000,6\n", '', 1);

        expect($result['decoded'])->toHaveCount(1)
            ->and($result['exitCode'])->toBe(1)
            ->and($result['notes'])->toContain('nfdump exited with code 1')
        ;
    });

    test('drops benign stderr and reports the rest', function (): void {
        $result = runCannedNfdump("firstSeen,proto\n2026-08-29 05:17:53.000,6\n", "read() error: Success\nsomething real\n");

        expect($result['stderr'])->toBe('something real');
    });

    // SPEC 3.4: rawOutput is nfdump's stdout; the text view escapes it, so no markup is added.
    test('returns an unparsed biflow table untouched, footer included', function (): void {
        $stdout = "Date first seen  Duration Proto  Src IP Addr:Port  Dst IP Addr:Port  Out Pkt  In Pkt Out Byte In Byte Flows\n"
            . "garbled row <-> that the parser does not know\n"
            . "Summary: total flows: 21600, total bytes: 14774400, total packets: 151200, avg bps: 118195200, avg pps: 151200, avg bpp: 97\n"
            . "Time window: <unknown>\n";
        $result = runCannedNfdump($stdout, options: ['-o' => 'csv', '-B' => '']);

        expect($result['decoded'])->toBe([])
            ->and($result['rawOutput'])->toBe($stdout)
            ->and($result['rawOutput'])->not->toContain('<b>')
            ->and(NfdumpSummary::fromTextFooter($result['rawOutput']))->toMatchArray(['flows' => 21600, 'bytes' => 14774400, 'packets' => 151200])
        ;
    });

    test('keeps the footer of an aggregated fmt run for the totals', function (): void {
        $stdout = "     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows\n"
            . "       10.0.37.1        10.1.37.2   288000     2880   288\n"
            . "Summary: total flows: 21600, total bytes: 14774400, total packets: 151200, avg bps: 1, avg pps: 1, avg bpp: 97\n";
        $result = runCannedNfdump($stdout, options: ['-a' => '-Asrcip,dstip', '-6' => null, '-o' => 'fmt:%sa %da %ibyt %ipkt %fl']);

        expect($result['decoded'])->toBe([['sa' => '10.0.37.1', 'da' => '10.1.37.2', 'ibyt' => '288000', 'ipkt' => '2880', 'fl' => '288']])
            ->and($result['rawOutput'])->toBe($stdout)
            ->and($result['command'])->toContain(" -6 -o 'fmt:%sa %da %ibyt %ipkt %fl'")
            ->and(NfdumpSummary::fromTextFooter($result['rawOutput'])['bytes'] ?? null)->toBe(14774400)
        ;
    });

    // A filter such as `-w/tmp/x` used to reach nfdump's option parser and write a file.
    test('passes a filter that starts with a dash as the filter', function (): void {
        $argsFile = tempnam(sys_get_temp_dir(), 'nfdump-args');
        putenv('NFDUMP_STUB_ARGS=' . $argsFile);

        runCannedNfdump("firstSeen,proto\n2026-08-29 05:17:53.000,6\n", options: ['-o' => 'csv', '-s' => ['srcip/bytes', 'dstip/bytes']], filter: '-w/tmp/x');
        $args = file((string) $argsFile, FILE_IGNORE_NEW_LINES);
        unlink((string) $argsFile);

        expect($args)->toBe(['-o', 'csv', '-s', 'srcip/bytes', '-s', 'dstip/bytes', '--', '-w/tmp/x']);
    });
});
