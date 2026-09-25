<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

/**
 * Totals nfdump prints about a run: the `-I` statistics block and the text footer below a
 * listing or aggregation.
 *
 * @phpstan-type StatDump array{
 *     flows: int, flows_tcp: int, flows_udp: int, flows_icmp: int, flows_other: int,
 *     packets: int, packets_tcp: int, packets_udp: int, packets_icmp: int, packets_other: int,
 *     bytes: int, bytes_tcp: int, bytes_udp: int, bytes_icmp: int, bytes_other: int,
 *     first: int, last: int,
 * }
 * @phpstan-type TextFooter array{
 *     flows: int, bytes: int, packets: int, avgBps: int, avgPps: int, avgBpp: int,
 *     windowStart: ?string, windowEnd: ?string, duration: ?string,
 *     processed: ?int, passed: ?int, blocksSkipped: ?int, bytesRead: ?int,
 *     sysSeconds: ?float, userSeconds: ?float, wallSeconds: ?float,
 * }
 */
final class NfdumpSummary {
    /** @var list<string> */
    private const array STAT_KEYS = [
        'flows', 'flows_tcp', 'flows_udp', 'flows_icmp', 'flows_other',
        'packets', 'packets_tcp', 'packets_udp', 'packets_icmp', 'packets_other',
        'bytes', 'bytes_tcp', 'bytes_udp', 'bytes_icmp', 'bytes_other',
        'first', 'last',
    ];

    /** nfdump scales unless run with -N, in powers of 1000 ("14.8 M"). */
    private const array SCALE = ['' => 1, 'K' => 1_000, 'M' => 1_000_000, 'G' => 1_000_000_000, 'T' => 1_000_000_000_000];

    /**
     * Parses `nfdump -I` output lines or the decoded [{metric, value}] rows. A key nfdump did
     * not print is 0.
     *
     * @param array<mixed>|string $input
     *
     * @return StatDump
     */
    public static function fromStatDump(array|string $input): array {
        $values = array_fill_keys(self::STAT_KEYS, 0);

        $pairs = [];
        if (\is_string($input)) {
            $input = explode("\n", $input);
        }
        foreach ($input as $item) {
            if (\is_array($item) && isset($item['metric'], $item['value']) && \is_scalar($item['metric']) && \is_scalar($item['value'])) {
                $pairs[] = [(string) $item['metric'], (string) $item['value']];
            } elseif (\is_string($item) && str_contains($item, ':')) {
                [$key, $value] = explode(':', $item, 2);
                $pairs[] = [$key, $value];
            }
        }

        foreach ($pairs as [$key, $value]) {
            $key = strtolower(trim($key));
            if (\array_key_exists($key, $values) && is_numeric(trim($value))) {
                $values[$key] = (int) trim($value);
            }
        }

        /** @var StatDump $values */
        return $values;
    }

    /**
     * Parses the text footer ('Summary: total flows: N, total bytes: N, ...', 'Time window:',
     * 'Total records processed:', 'Sys: ... Wall: ...'). Null when there is no Summary line.
     *
     * @return null|TextFooter
     */
    public static function fromTextFooter(string $raw): ?array {
        $summary = null;
        $footer = [
            'windowStart' => null, 'windowEnd' => null, 'duration' => null,
            'processed' => null, 'passed' => null, 'blocksSkipped' => null, 'bytesRead' => null,
            'sysSeconds' => null, 'userSeconds' => null, 'wallSeconds' => null,
        ];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'Summary:')) {
                $fields = self::fields(substr($line, \strlen('Summary:')));
                $summary = [
                    'flows' => self::count($fields['total flows'] ?? ''),
                    'bytes' => self::count($fields['total bytes'] ?? ''),
                    'packets' => self::count($fields['total packets'] ?? ''),
                    'avgBps' => self::count($fields['avg bps'] ?? ''),
                    'avgPps' => self::count($fields['avg pps'] ?? ''),
                    'avgBpp' => self::count($fields['avg bpp'] ?? ''),
                ];
            } elseif (preg_match('/^Time window:\s*(.+?)\s+-\s+(.+?)(?:,\s*Duration:\s*(.*))?$/', $line, $m) === 1) {
                $footer['windowStart'] = $m[1];
                $footer['windowEnd'] = $m[2];
                $footer['duration'] = isset($m[3]) && trim($m[3]) !== '' ? trim($m[3]) : null;
            } elseif (preg_match('/^Total (?:records|flows) processed:/', $line) === 1) {
                $fields = self::fields((string) preg_replace('/^Total (?:records|flows) processed/', 'processed', $line));
                $footer['processed'] = isset($fields['processed']) ? self::count($fields['processed']) : null;
                $footer['passed'] = isset($fields['passed']) ? self::count($fields['passed']) : null;
                $footer['blocksSkipped'] = isset($fields['blocks skipped']) ? self::count($fields['blocks skipped']) : null;
                $footer['bytesRead'] = isset($fields['bytes read']) ? self::count($fields['bytes read']) : null;
            } elseif (str_starts_with($line, 'Sys:')) {
                foreach (['sysSeconds' => 'Sys', 'userSeconds' => 'User', 'wallSeconds' => 'Wall'] as $key => $label) {
                    if (preg_match('/\b' . $label . ':\s*([\d.]+)s\b/', $line, $m) === 1) {
                        $footer[$key] = (float) $m[1];
                    }
                }
            }
        }

        return $summary === null ? null : $summary + $footer;
    }

    /**
     * "total flows: 2, total bytes: 2000" into lower-case label => value.
     *
     * @return array<string, string>
     */
    private static function fields(string $text): array {
        $fields = [];
        foreach (explode(',', $text) as $part) {
            if (str_contains($part, ':')) {
                [$label, $value] = explode(':', $part, 2);
                $fields[strtolower(trim($label))] = trim($value);
            }
        }

        return $fields;
    }

    /** A counter as nfdump prints it, plain ("2000") or scaled ("14.8 M"). */
    private static function count(string $value): int {
        if (preg_match('/^([\d.]+)\s*([KMGT]?)$/i', trim($value), $m) !== 1) {
            return 0;
        }

        return (int) round((float) $m[1] * self::SCALE[strtoupper($m[2])]);
    }
}
