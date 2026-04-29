<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\TableFormatter;

describe('TableFormatter', function (): void {
    describe('formatBytes', function (): void {
        test('formats zero bytes', function (): void {
            $result = TableFormatter::formatCellValue(0, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toBe('0 B');
        });

        test('formats bytes under 1KB', function (): void {
            $result = TableFormatter::formatCellValue(512, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toContain('B');
        });

        test('formats kilobytes', function (): void {
            $result = TableFormatter::formatCellValue(1024, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toContain('KB');
        });

        test('formats megabytes', function (): void {
            $result = TableFormatter::formatCellValue(1048576, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toContain('MB');
        });

        test('formats gigabytes', function (): void {
            $result = TableFormatter::formatCellValue(1073741824, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toContain('GB');
        });

        test('formats terabytes', function (): void {
            $result = TableFormatter::formatCellValue(1099511627776, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toContain('TB');
        });

        test('formats bytes field variations', function (string $fieldName): void {
            $result = TableFormatter::formatCellValue(1024, $fieldName, ['linkIpAddresses' => false]);
            expect($result)->toContain('KB');
        })->with(['bytes', 'ibyt', 'obyt', 'in_bytes', 'out_bytes', 'octets']);
    });

    describe('formatDuration', function (): void {
        test('formats milliseconds', function (): void {
            $result = TableFormatter::formatCellValue(0.5, 'duration', ['linkIpAddresses' => false]);
            expect($result)->toContain('ms');
        });

        test('formats seconds', function (): void {
            $result = TableFormatter::formatCellValue(30.5, 'duration', ['linkIpAddresses' => false]);
            expect($result)->toContain('s');
        });

        test('formats minutes and seconds', function (): void {
            $result = TableFormatter::formatCellValue(125, 'duration', ['linkIpAddresses' => false]);
            expect($result)->toMatch('/\d+:\d+/');
        });

        test('formats hours minutes and seconds', function (): void {
            $result = TableFormatter::formatCellValue(3665, 'duration', ['linkIpAddresses' => false]);
            expect($result)->toMatch('/\d+:\d+:\d+/');
        });

        test('handles non-numeric duration', function (): void {
            $result = TableFormatter::formatCellValue('invalid', 'duration', ['linkIpAddresses' => false]);
            expect($result)->toBe('invalid');
        });
    });

    describe('formatDate', function (): void {
        test('formats unix timestamp', function (): void {
            $timestamp = 1700000000; // Nov 14, 2023
            $result = TableFormatter::formatCellValue($timestamp, 'first', ['linkIpAddresses' => false]);
            expect($result)->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
        });

        test('formats date string', function (): void {
            $result = TableFormatter::formatCellValue('2023-11-14 12:00:00', 't_first', ['linkIpAddresses' => false]);
            expect($result)->toMatch('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/');
        });

        test('recognizes date field variations', function (string $fieldName): void {
            $result = TableFormatter::formatCellValue(1700000000, $fieldName, ['linkIpAddresses' => false]);
            expect($result)->toMatch('/\d{4}-\d{2}-\d{2}/');
        })->with(['first', 'last', 'received', 't_first', 't_last', 'timestamp']);
    });

    describe('formatTcpFlags', function (): void {
        test('formats string flags with dots', function (): void {
            $result = TableFormatter::formatCellValue('....A...', 'flags', ['linkIpAddresses' => false]);
            expect($result)->toContain('ACK');
        });

        test('formats SYN flag', function (): void {
            $result = TableFormatter::formatCellValue('......S.', 'flags', ['linkIpAddresses' => false]);
            expect($result)->toContain('SYN');
        });

        test('formats multiple flags', function (): void {
            $result = TableFormatter::formatCellValue('....A.S.', 'flags', ['linkIpAddresses' => false]);
            expect($result)
                ->toContain('ACK')
                ->toContain('SYN')
            ;
        });

        test('formats numeric flags', function (): void {
            $result = TableFormatter::formatCellValue(0x12, 'tcp_flags', ['linkIpAddresses' => false]); // SYN + ACK
            expect($result)
                ->toContain('SYN')
                ->toContain('ACK')
            ;
        });

        test('formats hex string flags', function (): void {
            $result = TableFormatter::formatCellValue('0x02', 'flg', ['linkIpAddresses' => false]); // SYN
            expect($result)->toContain('SYN');
        });

        test('handles empty flags', function (): void {
            $result = TableFormatter::formatCellValue('........', 'flags', ['linkIpAddresses' => false]);
            expect($result)->toContain('-');
        });
    });

    describe('formatNumber', function (): void {
        test('formats large numbers with comma separators', function (): void {
            $result = TableFormatter::formatCellValue(1234567, 'flows', ['linkIpAddresses' => false]);
            expect($result)->toBe('1,234,567');
        });

        test('formats packets field', function (): void {
            $result = TableFormatter::formatCellValue(1000000, 'packets', ['linkIpAddresses' => false]);
            expect($result)->toContain(',');
        });

        test('formats flow records', function (): void {
            $result = TableFormatter::formatCellValue(500000, 'records', ['linkIpAddresses' => false]);
            expect($result)->toContain(',');
        });
    });

    describe('formatProtocol', function (): void {
        test('formats TCP protocol number', function (): void {
            $result = TableFormatter::formatCellValue(6, 'proto', ['linkIpAddresses' => false]);
            expect($result)->toContain('TCP');
        });

        test('formats UDP protocol number', function (): void {
            $result = TableFormatter::formatCellValue(17, 'proto', ['linkIpAddresses' => false]);
            expect($result)->toContain('UDP');
        });

        test('formats ICMP protocol number', function (): void {
            $result = TableFormatter::formatCellValue(1, 'protocol', ['linkIpAddresses' => false]);
            expect($result)->toContain('ICMP');
        });
    });

    describe('formatBitrate', function (): void {
        test('formats bits per second', function (): void {
            $result = TableFormatter::formatCellValue(1000000, 'bps', ['linkIpAddresses' => false]);
            expect($result)->toMatch('/Mbps|Kbps|bps/');
        });

        test('formats packets per second', function (): void {
            $result = TableFormatter::formatCellValue(1000, 'pps', ['linkIpAddresses' => false]);
            expect($result)->toContain('pps');
        });

        test('handles Gbps range', function (): void {
            $result = TableFormatter::formatCellValue(10000000000, 'bps', ['linkIpAddresses' => false]);
            expect($result)->toContain('Gbps');
        });
    });

    describe('IP address formatting', function (): void {
        test('creates link for valid IPv4', function (): void {
            $result = TableFormatter::formatCellValue('192.168.1.1', 'srcip', ['linkIpAddresses' => true]);
            expect($result)
                ->toContain('192.168.1.1')
                ->toContain('href')
                ->toContain('ip-link')
            ;
        });

        test('converts numeric IP to dotted notation with link', function (): void {
            $numericIp = ip2long('10.0.0.1');
            $result = TableFormatter::formatCellValue($numericIp, 'dstip', ['linkIpAddresses' => true]);
            expect($result)->toContain('10.0.0.1');
        });

        test('does not create link when disabled', function (): void {
            $result = TableFormatter::formatCellValue('192.168.1.1', 'srcip', ['linkIpAddresses' => false]);
            expect($result)->toBe('192.168.1.1');
        });

        test('handles IP field variations', function (string $fieldName): void {
            $result = TableFormatter::formatCellValue('8.8.8.8', $fieldName, ['linkIpAddresses' => true]);
            expect($result)->toContain('href');
        })->with(['srcip', 'dstip', 'ip', 'src_addr', 'dst_addr']);
    });

    describe('getSortValue', function (): void {
        test('returns timestamp for date fields', function (): void {
            $result = TableFormatter::getSortValue(1700000000, 'first');
            expect($result)->toBe(1700000000);
        });

        test('parses date string to timestamp', function (): void {
            $result = TableFormatter::getSortValue('2023-11-14 12:00:00', 't_first');
            expect($result)->toBeInt();
        });

        test('converts IP to sortable format', function (): void {
            $result = TableFormatter::getSortValue('10.0.0.1', 'srcip');
            expect($result)->toBeString();
            expect((int) $result)->toBeGreaterThan(0);
        });

        test('returns numeric values as-is', function (): void {
            $result = TableFormatter::getSortValue(12345, 'flows');
            expect($result)->toBe(12345);
        });

        test('handles empty values', function (): void {
            $result = TableFormatter::getSortValue('', 'any');
            expect($result)->toBe('');
        });

        test('handles null values', function (): void {
            $result = TableFormatter::getSortValue(null, 'any');
            expect($result)->toBe('');
        });
    });

    describe('edge cases', function (): void {
        test('handles null values gracefully', function (): void {
            $result = TableFormatter::formatCellValue(null, 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toBe('');
        });

        test('handles empty string', function (): void {
            $result = TableFormatter::formatCellValue('', 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toBe('');
        });

        test('handles non-numeric value for numeric field', function (): void {
            $result = TableFormatter::formatCellValue('not-a-number', 'bytes', ['linkIpAddresses' => false]);
            expect($result)->toBe('not-a-number');
        });

        test('passes through unknown field types', function (): void {
            $result = TableFormatter::formatCellValue('some-value', 'unknown_field', ['linkIpAddresses' => false]);
            expect($result)->toBe('some-value');
        });
    });
});
