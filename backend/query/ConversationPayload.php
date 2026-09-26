<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * The ranked pairs of a Conversations run (4.4.3), shared by the Sankey, the Matrix and the IP
 * pairs table; the charts derive their nodes and links from `pairs` in the browser.
 *
 * A port is an int, or for ICMP nfdump's `type.code` string (`8.0`).
 *
 * @phpstan-type Figures array{bytes: int, packets: int, flows: int}
 * @phpstan-type Pair array{rank: int, src: string, dst: string, port: null|int|string, bytes: int, packets: int, flows: int,
 *                          share: ?float, reverse: null|Figures, series: ?int}
 * @phpstan-type Directed array{src: string, dst: string, port: null|int|string, bytes: int, packets: int, flows: int, reverse: null|Figures}
 * @phpstan-type Payload array{meta: array{metric: string, groupBy: string, direction: string, topN: int, approximate: bool, command: string},
 *                             totals: null|array{flows: int, packets: int, bytes: int},
 *                             pairs: list<Pair>,
 *                             others: null|array{bytes: int, packets: int, flows: int, share: float}}
 */
final class ConversationPayload {
    /** Series slots for source nodes, in order of first appearance (2.3); later ones are neutral. */
    public const int SLOTS = 8;

    /** A row's fields by nfdump's `fmt:` token, then by the 1.7.5 aggregated csv column. */
    private const array FIELDS = [
        'src' => ['sa', 'srcAddr'],
        'dst' => ['da', 'dstAddr'],
        'port' => ['dp', 'dstPort'],
        'bytes' => ['ibyt', 'bytes'],
        'packets' => ['ipkt', 'packets'],
        'flows' => ['fl', 'flows'],
    ];

    /**
     * @param list<array<string, mixed>>                       $rows   raw rows (fmt or 1.7.5 csv keys)
     * @param null|array{flows: int, packets: int, bytes: int} $totals every flow the filter matched, from nfdump's summary
     *
     * @return Payload
     */
    public static function build(array $rows, string $metric, string $groupBy, string $direction, int $topN, ?array $totals, string $command): array {
        $metric = $metric === 'packets' ? 'packets' : 'bytes';
        $groupBy = \in_array($groupBy, MatrixQuery::GROUPS, true) ? $groupBy : 'ip';
        $both = $direction === 'both' && $groupBy !== 'port';
        $topN = max(1, min(MatrixQuery::MAX_TOP_N, $topN));

        $directed = [];
        foreach ($rows as $row) {
            $pair = self::pairOf($row, $groupBy);
            if ($pair !== null) {
                $directed[] = $pair;
            }
        }

        $pairs = $both ? self::merge($directed, $metric) : $directed;
        // usort is stable, so equal pairs keep nfdump's order.
        usort($pairs, static fn (array $a, array $b): int => $b[$metric] <=> $a[$metric]);
        $pairs = \array_slice($pairs, 0, $topN);

        $slots = [];
        $ranked = [];
        foreach ($pairs as $i => $pair) {
            if (!isset($slots[$pair['src']])) {
                $slots[$pair['src']] = \count($slots) < self::SLOTS ? \count($slots) + 1 : null;
            }
            $ranked[] = [
                'rank' => $i + 1,
                'src' => $pair['src'],
                'dst' => $pair['dst'],
                'port' => $pair['port'],
                'bytes' => $pair['bytes'],
                'packets' => $pair['packets'],
                'flows' => $pair['flows'],
                'share' => self::share($pair[$metric], $totals[$metric] ?? null),
                'reverse' => $pair['reverse'],
                'series' => $slots[$pair['src']],
            ];
        }

        return [
            'meta' => [
                'metric' => $metric,
                'groupBy' => $groupBy,
                'direction' => $both ? 'both' : 'forward',
                'topN' => $topN,
                // A pair just outside the fetched rows may be missing one of its directions.
                'approximate' => $both && \count($rows) >= MatrixQuery::fetchLimitFor('both', $topN),
                'command' => $command,
            ],
            'totals' => $totals,
            'pairs' => $ranked,
            'others' => self::others($ranked, $totals, $metric),
        ];
    }

    /**
     * The totals of nfdump's text footer, or null without one (the 1.7.5 csv path prints none).
     *
     * @param null|array<string, mixed> $summary NfdumpSummary::fromTextFooter()
     *
     * @return null|array{flows: int, packets: int, bytes: int}
     */
    public static function totalsFrom(?array $summary): ?array {
        if ($summary === null) {
            return null;
        }

        return [
            'flows' => self::int($summary['flows'] ?? 0),
            'packets' => self::int($summary['packets'] ?? 0),
            'bytes' => self::int($summary['bytes'] ?? 0),
        ];
    }

    /** A node's label: the address, with the prefix length for a subnet. */
    public static function label(string $address, string $groupBy): string {
        return match ($groupBy) {
            'net24' => $address . '/24',
            'net16' => $address . '/16',
            default => $address,
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return null|Directed
     */
    private static function pairOf(array $row, string $groupBy): ?array {
        $src = self::field($row, 'src');
        $dst = self::field($row, 'dst');
        if (filter_var($src, FILTER_VALIDATE_IP) === false || filter_var($dst, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        // A host talking to itself has no place in a source/destination layout; two hosts of
        // one subnet do, as distinct source and destination nodes.
        if ($groupBy === 'ip' && $src === $dst) {
            return null;
        }

        $port = null;
        if ($groupBy === 'port') {
            $raw = self::field($row, 'port');
            if (preg_match('/^\d+(\.\d+)?$/', $raw, $m) !== 1) {
                return null;
            }
            $port = isset($m[1]) ? $raw : (int) $raw;
        }

        return [
            'src' => self::label($src, $groupBy),
            'dst' => self::label($dst, $groupBy),
            'port' => $port,
            'bytes' => self::int(self::field($row, 'bytes')),
            'packets' => self::int(self::field($row, 'packets')),
            'flows' => self::int(self::field($row, 'flows')),
            'reverse' => null,
        ];
    }

    /**
     * Folds a->b and b->a into one pair: summed, oriented with the heavier sender as the
     * source, and the lighter direction kept as `reverse`.
     *
     * @param list<Directed> $directed
     *
     * @return list<Directed>
     */
    private static function merge(array $directed, string $metric): array {
        /** @var array<string, array{a: string, b: string, port: null|int|string, ab: Figures, ba: Figures, seen: array{ab: bool, ba: bool}}> $groups */
        $groups = [];
        foreach ($directed as $pair) {
            // Two hosts of one subnet (a == b) have only the one direction.
            $side = strcmp($pair['src'], $pair['dst']) <= 0 ? 'ab' : 'ba';
            [$a, $b] = $side === 'ab' ? [$pair['src'], $pair['dst']] : [$pair['dst'], $pair['src']];
            $key = $a . "\0" . $b . "\0" . ($pair['port'] ?? '');
            $groups[$key] ??= ['a' => $a, 'b' => $b, 'port' => $pair['port'], 'ab' => self::zero(), 'ba' => self::zero(), 'seen' => ['ab' => false, 'ba' => false]];
            foreach (['bytes', 'packets', 'flows'] as $field) {
                $groups[$key][$side][$field] += $pair[$field];
            }
            $groups[$key]['seen'][$side] = true;
        }

        $merged = [];
        foreach ($groups as $group) {
            $abFirst = $group['ab'][$metric] >= $group['ba'][$metric];
            [$src, $dst] = $abFirst ? [$group['a'], $group['b']] : [$group['b'], $group['a']];
            [$heavy, $light] = $abFirst ? [$group['ab'], $group['ba']] : [$group['ba'], $group['ab']];
            $merged[] = [
                'src' => $src,
                'dst' => $dst,
                'port' => $group['port'],
                'bytes' => $heavy['bytes'] + $light['bytes'],
                'packets' => $heavy['packets'] + $light['packets'],
                'flows' => $heavy['flows'] + $light['flows'],
                'reverse' => $group['seen']['ab'] && $group['seen']['ba'] ? $light : null,
            ];
        }

        return $merged;
    }

    /**
     * What the listed pairs leave of the totals.
     *
     * @param list<Pair>                                       $pairs
     * @param null|array{flows: int, packets: int, bytes: int} $totals
     *
     * @return null|array{bytes: int, packets: int, flows: int, share: float}
     */
    private static function others(array $pairs, ?array $totals, string $metric): ?array {
        if ($totals === null) {
            return null;
        }

        $rest = [];
        foreach (['bytes', 'packets', 'flows'] as $field) {
            $rest[$field] = max(0, $totals[$field] - array_sum(array_column($pairs, $field)));
        }

        return [...$rest, 'share' => self::share($rest[$metric], $totals[$metric]) ?? 0.0];
    }

    /** A fraction of the total, 0..1; null when the total is unknown or zero. */
    private static function share(int $value, ?int $total): ?float {
        if ($total === null || $total <= 0) {
            return null;
        }

        return round(min(1.0, $value / $total), 6);
    }

    /** @return Figures */
    private static function zero(): array {
        return ['bytes' => 0, 'packets' => 0, 'flows' => 0];
    }

    /** @param array<string, mixed> $row */
    private static function field(array $row, string $name): string {
        foreach (self::FIELDS[$name] as $key) {
            if (isset($row[$key]) && \is_scalar($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }

    private static function int(mixed $value): int {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
