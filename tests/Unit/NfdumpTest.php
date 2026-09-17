<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Nfdump;

// Nfdump requires Config to be initialized, so we set up minimal config
beforeAll(function (): void {
    // Set up minimal config for Nfdump to work
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80, 443],
            'sources' => ['gateway'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/test-profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ]);
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
        // Record shapes captured from a real `nfdump -r <file> -o json` run (1.7.3) — an IPv4
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
            // Only file on this day is BEFORE datestart — should skip forward to next day
            $mkfile($base, '202407010000'); // 00:00 — before the 12:00 start
            $nextFile = $mkfile($base, '202407021200'); // next day — first valid file

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
            // empty and no files are found — this is the trap AlertManager::fetchFilteredSlot()
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
