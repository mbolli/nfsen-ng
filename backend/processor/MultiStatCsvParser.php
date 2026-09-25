<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

/**
 * The CSV of an nfdump run with several `-s`: one header-led block per statistic, in order. Pass
 * rawOutput, the processor's CSV decoding only reads the first header.
 *
 * @phpstan-type StatRow array{key: string, proto: string, flows: int, packets: int, bytes: int, bytesPct: ?float}
 */
final class MultiStatCsvParser {
    /** Every statistic block starts with a header line that begins like this. */
    public const string HEADER_PREFIX = 'ts,te,td,pr,val,';

    /** In a `:p` statistic, other protocols' rows carry something else in `val` (ICMP type and code). */
    public const array PORT_PROTOCOLS = ['tcp', 'udp', 'sctp'];

    /**
     * One list of rows per element, in order. A block with only its header, or a block nfdump
     * did not print, is an empty list; lines that are not rows of a known block are ignored.
     *
     * @param list<string> $elements the `-s` elements as passed, e.g. 'srcip' or 'dstport:p/bytes'
     *
     * @return list<list<StatRow>>
     */
    public static function parse(string $raw, array $elements): array {
        /** @var list<list<StatRow>> $blocks */
        $blocks = [];
        $ports = [];
        foreach ($elements as $element) {
            $blocks[] = [];
            $ports[] = str_ends_with(explode('/', $element, 2)[0], ':p');
        }

        $block = -1;

        /** @var array<string, int> $columns */
        $columns = [];
        foreach (explode("\n", $raw) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, self::HEADER_PREFIX)) {
                ++$block;
                $columns = array_flip(array_map(static fn (?string $name): string => trim((string) $name), str_getcsv($line, ',', '"', '')));

                continue;
            }
            if (!isset($blocks[$block], $ports[$block])) {
                continue;
            }

            $row = self::row($line, $columns, $ports[$block]);
            if ($row !== null) {
                $blocks[$block][] = $row;
            }
        }

        return $blocks;
    }

    /** How many statistic blocks the output holds, to tell a short run from empty statistics. */
    public static function blockCount(string $raw): int {
        $count = 0;
        foreach (explode("\n", $raw) as $line) {
            if (str_starts_with($line, self::HEADER_PREFIX)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, int> $columns header name => position
     *
     * @return null|StatRow
     */
    private static function row(string $line, array $columns, bool $port): ?array {
        $fields = str_getcsv($line, ',', '"', '');
        if (\count($fields) !== \count($columns)) {
            return null;
        }

        $field = static function (string ...$names) use ($fields, $columns): ?string {
            foreach ($names as $name) {
                if (isset($columns[$name], $fields[$columns[$name]])) {
                    return trim((string) $fields[$columns[$name]]);
                }
            }

            return null;
        };

        $val = $field('val');
        $proto = $field('pr') ?? '';
        $flows = $field('fl');
        $packets = $field('pkt', 'ipkt');
        $bytes = $field('byt', 'ibyt');
        if ($val === null || $val === '' || !self::isCount($flows) || !self::isCount($packets) || !self::isCount($bytes)) {
            return null;
        }

        $key = $val;
        if ($port) {
            $name = strtolower($proto);
            if (!\in_array($name, self::PORT_PROTOCOLS, true)) {
                return null;
            }
            $key = $val . '/' . $name;
        }

        $pct = $field('bytP', 'ibytP');

        return [
            'key' => $key,
            'proto' => $proto,
            'flows' => (int) $flows,
            'packets' => (int) $packets,
            'bytes' => (int) $bytes,
            'bytesPct' => $pct !== null && is_numeric($pct) ? (float) $pct : null,
        ];
    }

    /** @phpstan-assert-if-true string $value */
    private static function isCount(?string $value): bool {
        return $value !== null && ctype_digit($value);
    }
}
