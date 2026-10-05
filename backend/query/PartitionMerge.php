<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * Merges the time slices of one query into what a single nfdump run prints, or refuses when the
 * parts cannot prove the top exact.
 *
 * @phpstan-type Totals array{flows: int, packets: int, bytes: int}
 * @phpstan-type Ranked array{values: array<string, int>, truncated: bool, known: array<string, true>}
 * @phpstan-type Proof array{top: ?list<string>, lookups: ?array<int, list<string>>}
 * @phpstan-type Bounds array{totals: array<string, int>, covered: array<string, int>, coveredBy: array<string, int>, cutoffs: int, truncated: int}
 * @phpstan-type StatLine array{int, int, int, string, string, ?string}
 * @phpstan-type StatPart array{name: string, lines: array<string, StatLine>, totals: ?Totals, empty: bool, truncated: bool, known: array<string, true>}
 * @phpstan-type RecordLine array{int, int, int, int, int, int, int, string}
 * @phpstan-type RecordPart array{header: list<string>, lines: array<string, RecordLine>, empty: bool, truncated: bool, known: array<string, true>}
 * @phpstan-type PairLine array{int, int, int, int, int, string}
 * @phpstan-type PairPart array{header: string, lines: array<string, PairLine>, totals: Totals, window: ?array{string, string}, records: array{int, int, int, int}, empty: bool, truncated: bool, known: array<string, true>}
 */
final class PartitionMerge {
    /** @var list<string> the counters nfdump sums to rank, the only orders a merge can prove */
    public const array SUMMED_ORDERS = ['flows', 'packets', 'bytes'];

    /** The most keys one lookup asks for: beyond that the bounds are too loose to be worth it. */
    public const int MAX_LOOKUP_KEYS = 2000;

    public const string NO_MATCH = 'No matching flows';

    /** nfdump's own names for protocol numbers (userio.c protoList), for the pr column of `-s proto`. */
    private const array PROTOCOLS = [
        '0', 'ICMP', 'IGMP', 'GGP', 'IPIP', 'ST', 'TCP', 'CBT', 'EGP', 'IGP', 'BBN', 'NVPII', 'PUP', 'ARGUS', 'ENCOM',
        'XNET', 'CHAOS', 'UDP', 'MUX', 'DCN', 'HMP', 'PRM', 'XNS', 'Trnk1', 'Trnk2', 'Leaf1', 'Leaf2', 'RDP', 'IRTP',
        'ISO-4', 'NETBK', 'MFESP', 'MEINP', 'DCCP', '3PC', 'IDPR', 'XTP', 'DDP', 'IDPR', 'TP++', 'IL', 'IPv6', 'SDRP',
        'Rte6', 'Frag6', 'IDRP', 'RSVP', 'GRE', 'MHRP', 'BNA', 'ESP', 'AH', 'INLSP', 'SWIPE', 'NARP', 'MOBIL', 'TLSP',
        'SKIP', 'ICMP6', 'NOHE6', 'OPTS6', 'HOST', 'CFTP', 'NET', 'SATNT', 'KLAN', 'RVD', 'IPPC', 'FS', 'SATM', 'VISA',
        'IPCV', 'CPNX', 'CPHB', 'WSN', 'PVP', 'BSATM', 'SUNND', 'WBMON', 'WBEXP', 'ISOIP', 'VMTP', 'SVMTP', 'VINES',
        'TTP', 'NSIGP', 'DGP', 'TCF', 'EIGRP', 'OSPF', 'S-RPC', 'LARP', 'MTP', 'AX.25', 'OS', 'MICP', 'SCCSP', 'ETHIP',
        'ENCAP', '99', 'GMTP', 'IFMP', 'PNNI', 'PIM', 'ARIS', 'SCPS', 'QNX', 'A/N', 'IPcmp', 'SNP', 'CpqPP', 'IPXIP',
        'VRRP', 'PGM', '0hop', 'L2TP', 'DDX', 'IATP', 'STP', 'SRP', 'UTI', 'SMP', 'SM', 'PTP', 'ISIS4', 'FIRE', 'CRTP',
        'CRUDP', '128', 'IPLT', 'SPS', 'PIPE', 'SCTP', 'FC', '134', 'MHEAD', 'UDP-L', 'MPLS',
    ];

    private const string STAT_LINE = '/^\{ "first" : "([^"]*)", "last" : "([^"]*)", "proto" : (\d+), "([^"]+)" : "([^"]*)", (?:"geo" : "([^"]*)",)?"flows" : (\d+), "packets" : (\d+), "bytes" : (\d+), "pps" : \d+, "bps" : \d+, "bpp" : \d+\}\r?$/';

    /** A StatLine holds flows, packets, bytes, first and last seen and geo: lists, as a split holds thousands. */
    private const int FL = 0;

    private const int PKT = 1;

    private const int BYT = 2;

    /**
     * A RecordLine or PairLine holds in and out bytes and packets and flows, then a record's first
     * and last seen in ms and its first seen as printed, a pair's key columns as printed.
     */
    private const int IBYT = 0;

    private const int IPKT = 1;

    private const int OBYT = 2;

    private const int OPKT = 3;

    private const int FLOWS = 4;

    private const string NO_MATCH_LINE = '/^No matching flows\r?$/m';

    private const int DAY = 86_400;

    private static ?\DateTimeZone $zone = null;

    /**
     * The top $n keys of the parts, highest total first (equal totals by key), or null when the
     * parts cannot prove them.
     *
     * @param list<Ranked> $parts each part's key => value as nfdump listed them, and the keys a
     *                            lookup found or ruled out there
     *
     * @return null|list<string>
     */
    public static function exactTop(array $parts, int $n): ?array {
        return self::top(self::bounds($parts), $n);
    }

    /**
     * By part index, the keys a truncated part lacks that could still reach the N-th total; null
     * when more than $max could, where lookups cost more than they save.
     *
     * @param list<Ranked> $parts
     *
     * @return null|array<int, list<string>>
     */
    public static function lookups(array $parts, int $n, int $max = self::MAX_LOOKUP_KEYS): ?array {
        return self::wanted($parts, self::bounds($parts), $n, $max);
    }

    /**
     * exactTop() and, when it fails, lookups(), from one pass over the parts.
     *
     * @param list<Ranked> $parts
     *
     * @return Proof
     */
    public static function prove(array $parts, int $n, int $max = self::MAX_LOOKUP_KEYS): array {
        $bounds = self::bounds($parts);
        $top = self::top($bounds, $n);

        return ['top' => $top, 'lookups' => $top === null ? self::wanted($parts, $bounds, $n, $max) : []];
    }

    /**
     * $part with what a lookup found for $keys: their lines, and every one of them known there.
     *
     * @template P of array{lines: array<string, mixed>, known: array<string, true>}
     *
     * @param P                                  $part
     * @param array{lines: array<string, mixed>} $lookup
     * @param list<string>                       $keys
     *
     * @return P
     */
    public static function absorb(array $part, array $lookup, array $keys): array {
        foreach ($keys as $key) {
            if (isset($lookup['lines'][$key])) {
                $part['lines'][$key] = $lookup['lines'][$key];
            }
            $part['known'][$key] = true;
        }

        return $part;
    }

    /**
     * Whether nfdump said it found nothing: in what it printed, or in the notes the processor
     * took that line into, since a json run hands back no output then.
     *
     * @param list<string> $notes
     */
    public static function saysNoMatch(string $raw, array $notes = []): bool {
        return \in_array(self::NO_MATCH, $notes, true) || (str_contains($raw, self::NO_MATCH) && preg_match(self::NO_MATCH_LINE, $raw) === 1);
    }

    /**
     * One part of an element statistic in json; the rows of `-s <aux>/bytes` count every flow
     * once and add up to its totals, unknown when -n ($limit) may have cut them.
     *
     * @return StatPart
     */
    public static function parseStats(string $raw, string $auxName = '', int $limit = 0): array {
        $lines = [];
        $name = '';
        $totals = ['flows' => 0, 'packets' => 0, 'bytes' => 0];
        $auxRows = 0;
        // Line by line: matching the whole output at once held three times its size in match arrays.
        for ($text = strtok($raw, "\n"); $text !== false; $text = strtok("\n")) {
            if (preg_match(self::STAT_LINE, $text, $m) !== 1) {
                continue;
            }
            if ($auxName !== '' && $m[4] === $auxName) {
                ++$auxRows;
                $totals['flows'] += (int) $m[7];
                $totals['packets'] += (int) $m[8];
                $totals['bytes'] += (int) $m[9];

                continue;
            }
            $name = $m[4];
            $lines[$m[3] . "\0" . $m[5]] = [(int) $m[7], (int) $m[8], (int) $m[9], $m[1], $m[2], $m[6] !== '' ? $m[6] : null];
        }

        $known = $auxName === '' || $limit <= 0 || $auxRows < $limit;

        return ['name' => $name, 'lines' => $lines, 'totals' => $known ? $totals : null, 'empty' => self::saysNoMatch($raw), 'truncated' => false, 'known' => []];
    }

    /** The key an element statistic merges a line by: its protocol (for `:p`) and value. */
    public static function statKey(int $proto, string $value): string {
        return $proto . "\0" . $value;
    }

    /**
     * The value of a statKey().
     */
    public static function statValue(string $key): string {
        return substr($key, (int) strpos($key, "\0") + 1);
    }

    /**
     * Keys ranked by an element statistic's order.
     *
     * @param list<StatPart> $parts
     *
     * @return list<Ranked>
     */
    public static function rankStats(array $parts, string $order): array {
        $field = self::orderField($order);

        return array_map(static fn (array $part): array => [
            'values' => array_map(static fn (array $line): int => $line[$field], $part['lines']),
            'truncated' => $part['truncated'],
            'known' => $part['known'],
        ], $parts);
    }

    /**
     * The merged $top of an element statistic as nfdump prints it in $output (json or csv), or
     * null when the shares of a csv cannot be known.
     *
     * @param list<StatPart> $parts
     * @param list<string>   $top   proven by exactTop() or prove()
     */
    public static function stats(array $parts, array $top, string $element, string $order, string $output): ?string {
        $merged = [];
        $name = '';
        foreach ($parts as $part) {
            $name = $name === '' ? $part['name'] : $name;
        }
        foreach ($top as $key) {
            foreach ($parts as $part) {
                $line = $part['lines'][$key] ?? null;
                if ($line === null) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $line;

                    continue;
                }
                $merged[$key][self::FL] += $line[self::FL];
                $merged[$key][self::PKT] += $line[self::PKT];
                $merged[$key][self::BYT] += $line[self::BYT];
                $merged[$key][3] = min($merged[$key][3], $line[3]);
                $merged[$key][4] = max($merged[$key][4], $line[4]);
            }
        }

        $out = self::allEmpty($parts) ? self::NO_MATCH . "\n" : '';
        if ($output !== 'csv') {
            foreach ($top as $key) {
                $out .= self::statJson($key, $name, $merged[$key]) . "\n";
            }

            return $out;
        }

        $totals = ['flows' => 0, 'packets' => 0, 'bytes' => 0];
        foreach ($parts as $part) {
            if ($part['totals'] === null) {
                return null;
            }
            $totals['flows'] += $part['totals']['flows'];
            $totals['packets'] += $part['totals']['packets'];
            $totals['bytes'] += $part['totals']['bytes'];
        }
        $out .= ($order === 'flows'
            ? 'ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ibps,ibpp'
            : 'ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp') . "\n";
        foreach ($top as $key) {
            $out .= self::statCsv($key, $merged[$key], $element, $totals) . "\n";
        }

        return $out;
    }

    /**
     * The `csv:` format a part of an aggregated Flow Records run prints: nfdump's own columns
     * plus both times raw and the out counters, which the ranking needs and the csv leaves out.
     *
     * @param list<string> $tokens the aggregation's fmt tokens, in -A order
     */
    public static function recordFormat(array $tokens): string {
        return 'csv:%ts,%tsr,%ter,' . implode(',', $tokens) . ',%ipkt,%ibyt,%opkt,%obyt,%fl';
    }

    /**
     * One part of an aggregated Flow Records run in recordFormat().
     *
     * @param null|array<string, true> $only a lookup's keys: other lines are skipped, not stored
     *
     * @return RecordPart
     */
    public static function parseRecords(string $raw, int $keyFields, ?array $only = null): array {
        $header = [];
        $lines = [];
        $width = 3 + $keyFields + 5;
        for ($text = strtok($raw, "\n"); $text !== false; $text = strtok("\n")) {
            $fields = explode(',', rtrim($text, "\r"));
            if (\count($fields) !== $width) {
                continue;
            }
            if ($header === []) {
                $header = $fields;

                continue;
            }
            $key = self::joinKey(\array_slice($fields, 3, $keyFields));
            if ($only !== null && !isset($only[$key])) {
                continue;
            }
            $lines[$key] = [
                (int) $fields[4 + $keyFields], (int) $fields[3 + $keyFields], (int) $fields[6 + $keyFields], (int) $fields[5 + $keyFields],
                (int) $fields[7 + $keyFields], self::rawMs($fields[1]), self::rawMs($fields[2]), $fields[0],
            ];
        }

        return ['header' => $header, 'lines' => $lines, 'empty' => self::saysNoMatch($raw), 'truncated' => false, 'known' => []];
    }

    /**
     * Keys ranked by an aggregated Flow Records order: nfdump ranks by in plus out.
     *
     * @param list<PairPart|RecordPart> $parts
     *
     * @return list<Ranked>
     */
    public static function rankFlows(array $parts, string $order): array {
        return array_map(static function (array $part) use ($order): array {
            $values = [];
            foreach ($part['lines'] as $key => $line) {
                $values[$key] = match ($order) {
                    'flows' => $line[self::FLOWS],
                    'packets' => $line[self::IPKT] + $line[self::OPKT],
                    default => $line[self::IBYT] + $line[self::OBYT],
                };
            }

            return ['values' => $values, 'truncated' => $part['truncated'], 'known' => $part['known']];
        }, $parts);
    }

    /**
     * The merged $top of an aggregated Flow Records run, in the csv nfdump prints for `-o csv`
     * with -A.
     *
     * @param list<RecordPart> $parts
     * @param list<string>     $top   proven by exactTop() or prove()
     */
    public static function records(array $parts, array $top): string {
        $merged = [];
        $header = [];
        foreach ($parts as $part) {
            $header = $header === [] ? $part['header'] : $header;
        }
        foreach ($top as $key) {
            foreach ($parts as $part) {
                $line = $part['lines'][$key] ?? null;
                if ($line === null) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $line;

                    continue;
                }
                if ($line[5] < $merged[$key][5]) {
                    $merged[$key][5] = $line[5];
                    $merged[$key][7] = $line[7];
                }
                $merged[$key][6] = max($merged[$key][6], $line[6]);
                for ($counter = self::IBYT; $counter <= self::FLOWS; ++$counter) {
                    $merged[$key][$counter] += $line[$counter];
                }
            }
        }

        // nfdump prints the header before it says it found nothing.
        $noMatch = self::allEmpty($parts) ? self::NO_MATCH . "\n" : '';
        if ($header === []) {
            return $noMatch;
        }
        $keyNames = \array_slice($header, 3, \count($header) - 8);
        $out = implode(',', ['firstSeen', 'duration', ...$keyNames, 'packets', 'bytes', 'bps', 'bpp', 'flows']) . "\n" . $noMatch;
        foreach ($top as $key) {
            [$ibyt, $ipkt, , , $flows, $first, $last, $ts] = $merged[$key];
            $duration = $first > 0 && $last >= $first ? ($last - $first) / 1000.0 : 0.0;
            $out .= implode(',', [
                $ts,
                \sprintf('%.3f', $duration),
                ...self::splitKey($key),
                (string) $ipkt,
                (string) $ibyt,
                (string) ($duration > 0 ? (int) (($ibyt << 3) / $duration) : 0),
                (string) self::bpp($ibyt, $ipkt),
                (string) $flows,
            ]) . "\n";
        }

        return $out;
    }

    /**
     * The `fmt:` format a part of a Conversations run prints: the query's own plus the out
     * counters, which -O ranks by and the query's format leaves out.
     */
    public static function pairFormat(string $format): string {
        return $format . ' %obyt %opkt';
    }

    /**
     * One part of a Conversations run in pairFormat(): its rows keyed by pair, and the footer.
     *
     * @param null|array<string, true> $only a lookup's keys: other lines are skipped, not stored
     *
     * @return PairPart
     */
    public static function parsePairs(string $raw, int $keyFields, ?array $only = null): array {
        $header = '';
        $totals = ['flows' => 0, 'packets' => 0, 'bytes' => 0];
        $window = null;
        $records = [0, 0, 0, 0];
        if (preg_match('/^.*Src IP Addr.*$/m', $raw, $m) === 1) {
            $header = rtrim($m[0], "\r");
        }
        if (preg_match('/^Summary: total flows: (\d+), total bytes: (\d+), total packets: (\d+)/m', $raw, $m) === 1) {
            $totals = ['flows' => (int) $m[1], 'bytes' => (int) $m[2], 'packets' => (int) $m[3]];
        }
        if (preg_match('/^Time window: (\S+ \S+) - (\S+ \S+),/m', $raw, $m) === 1) {
            $window = [$m[1], $m[2]];
        }
        if (preg_match('/^Total records processed: (\d+), passed: (\d+), Blocks skipped: (\d+), Bytes read: (\d+)/m', $raw, $m) === 1) {
            $records = [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        }

        // The key columns, then in bytes, in packets, flows, out bytes and out packets in the
        // widths nfdump prints them, so a merged line differs from a part's in its counters only.
        $keys = ' *(\S+) +(\S+)' . str_repeat(' +(\S+)', max(0, $keyFields - 2));
        $pattern = '/^' . $keys . ' +(\d+) +(\d+) +(\d+) +(\d+) +(\d+)$/';
        $lines = [];
        for ($text = strtok($raw, "\n"); $text !== false; $text = strtok("\n")) {
            $text = rtrim($text, "\r");
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }
            if (strpbrk($m[1], '.:') === false || strpbrk($m[2], '.:') === false) {
                continue;
            }
            $ibyt = (int) $m[$keyFields + 1];
            $ipkt = (int) $m[$keyFields + 2];
            $flows = (int) $m[$keyFields + 3];
            $obyt = (int) $m[$keyFields + 4];
            $opkt = (int) $m[$keyFields + 5];
            $counters = \sprintf(' %8d %8d %5d %8d %8d', $ibyt, $ipkt, $flows, $obyt, $opkt);
            if (!str_ends_with($text, $counters)) {
                continue;
            }
            $key = '';
            for ($i = 1; $i <= $keyFields; ++$i) {
                $key .= $m[$i] . "\0";
            }
            if ($only !== null && !isset($only[$key])) {
                continue;
            }
            $lines[$key] = [$ibyt, $ipkt, $obyt, $opkt, $flows, substr($text, 0, -\strlen($counters))];
        }

        return ['header' => $header, 'lines' => $lines, 'totals' => $totals, 'window' => $window, 'records' => $records, 'empty' => self::saysNoMatch($raw), 'truncated' => false, 'known' => []];
    }

    /**
     * The merged $top pairs in the query's `fmt:`, with the summed totals; the footer leaves out
     * nfdump's averages, which depend on flows no part prints.
     *
     * @param list<PairPart> $parts
     * @param list<string>   $top   proven by exactTop() or prove()
     */
    public static function pairs(array $parts, array $top): string {
        $merged = [];
        foreach ($top as $key) {
            foreach ($parts as $part) {
                $line = $part['lines'][$key] ?? null;
                if ($line === null) {
                    continue;
                }
                if (!isset($merged[$key])) {
                    $merged[$key] = $line;

                    continue;
                }
                for ($counter = self::IBYT; $counter <= self::FLOWS; ++$counter) {
                    $merged[$key][$counter] += $line[$counter];
                }
            }
        }

        $header = '';
        $totals = ['flows' => 0, 'packets' => 0, 'bytes' => 0];
        $from = $to = null;
        $records = [0, 0, 0, 0];
        foreach ($parts as $part) {
            $header = $header === '' ? $part['header'] : $header;
            foreach ($totals as $counter => $value) {
                $totals[$counter] = $value + $part['totals'][$counter];
            }
            if ($part['window'] !== null) {
                $from = $from === null ? $part['window'][0] : min($from, $part['window'][0]);
                $to = $to === null ? $part['window'][1] : max($to, $part['window'][1]);
            }
            foreach ($records as $i => $value) {
                $records[$i] = $value + $part['records'][$i];
            }
        }

        $suffix = ' Out Byte  Out Pkt';
        $out = (str_ends_with($header, $suffix) ? substr($header, 0, -\strlen($suffix)) : $header) . "\n";
        if (self::allEmpty($parts)) {
            $out .= self::NO_MATCH . "\n";
        }
        foreach ($top as $key) {
            [$ibyt, $ipkt, , , $flows, $prefix] = $merged[$key];
            $out .= $prefix . \sprintf(' %8d %8d %5d', $ibyt, $ipkt, $flows) . "\n";
        }
        $out .= \sprintf("Summary: total flows: %d, total bytes: %d, total packets: %d\n", $totals['flows'], $totals['bytes'], $totals['packets']);
        if ($from !== null && $to !== null) {
            $out .= "Time window: {$from} - {$to}\n";
        }

        return $out . \sprintf("Total records processed: %d, passed: %d, Blocks skipped: %d, Bytes read: %d\n", ...$records);
    }

    /**
     * The fields of the keys joined by joinKey(), one list per field.
     *
     * @param list<string> $keys
     *
     * @return list<list<string>>
     */
    public static function keyFields(array $keys): array {
        $fields = [];
        foreach ($keys as $key) {
            foreach (self::splitKey($key) as $i => $value) {
                $fields[$i][$value] = true;
            }
        }

        return array_values(array_map(static fn (array $values): array => array_map(strval(...), array_keys($values)), $fields));
    }

    /**
     * A filter term for the flows whose $field is one of $values; ICMP type.code ports read as
     * nfdump stores them, type * 256 + code.
     *
     * @param string       $field  'srcip', 'dstip', 'ip', 'srcport', 'dstport', 'port', 'srcas', 'dstas', 'as' or 'proto'
     * @param list<string> $values
     */
    public static function inList(string $field, array $values, string $mask = ''): string {
        if ($field === 'proto') {
            return '(' . implode(' or ', array_map(static fn (string $value): string => 'proto ' . (int) $value, $values)) . ')';
        }

        $direction = str_starts_with($field, 'src') ? 'src ' : (str_starts_with($field, 'dst') ? 'dst ' : '');
        $kind = substr($field, \strlen(trim($direction)));
        $items = array_map(static function (string $value) use ($kind, $mask): string {
            if ($kind === 'port' && preg_match('/^(\d+)\.(\d+)$/', $value, $m) === 1) {
                return (string) ((int) $m[1] * 256 + (int) $m[2]);
            }

            return $kind === 'ip' && $mask !== '' ? $value . '/' . $mask : $value;
        }, $values);

        return $direction . $kind . ' in [' . implode(' ', $items) . ']';
    }

    /** nfdump's name for a protocol number, as `-s proto` prints it without -N. */
    public static function protocolName(int $proto): string {
        return self::PROTOCOLS[$proto] ?? \sprintf('%-5d', $proto);
    }

    /**
     * Epoch milliseconds of a time nfdump printed in its local time ("2026-09-29T00:00:00.961"
     * or with a space), read in the zone nfdump ran in.
     */
    public static function epochMs(string $local, ?\DateTimeZone $zone = null): int {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d?H:i:s.v', $local, $zone ?? self::zone());

        return $time === false ? 0 : (int) $time->format('Uv');
    }

    /**
     * Whether a daylight saving change within a day of [$from, $to] repeats a local hour, which
     * leaves the merged first and last seen and the rates without an order.
     */
    public static function repeatsLocalTime(int $from, int $to, ?\DateTimeZone $zone = null): bool {
        $offset = null;
        foreach (($zone ?? self::zone())->getTransitions($from - self::DAY, $to + self::DAY) ?: [] as $transition) {
            if ($offset !== null && $transition['offset'] < $offset) {
                return true;
            }
            $offset = $transition['offset'];
        }

        return false;
    }

    /**
     * The zone nfdump formats its times in: the TZ it inherits, else the system's, else PHP's.
     */
    public static function zone(): \DateTimeZone {
        if (self::$zone !== null) {
            return self::$zone;
        }

        $link = is_link('/etc/localtime') ? (string) readlink('/etc/localtime') : '';
        $candidates = [
            ltrim((string) getenv('TZ'), ':'),
            is_readable('/etc/timezone') ? trim((string) file_get_contents('/etc/timezone')) : '',
            $link,
            date_default_timezone_get(),
        ];
        $known = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
        foreach ($candidates as $name) {
            $at = strpos($name, 'zoneinfo/');
            $name = $at === false ? $name : substr($name, $at + 9);
            if ($name !== '' && \in_array($name, $known, true)) {
                return self::$zone = new \DateTimeZone($name);
            }
        }

        return self::$zone = new \DateTimeZone('UTC');
    }

    /** Tests: pin the zone nfdump's times are read in, or null to detect it again. */
    public static function useZone(?\DateTimeZone $zone): void {
        self::$zone = $zone;
    }

    /**
     * What each key has in total, highest first, and what bounds the keys a truncated part did
     * not list: the sum of the cutoffs, less those of the parts that listed or looked it up.
     *
     * @param list<Ranked> $parts
     *
     * @return Bounds
     */
    private static function bounds(array $parts): array {
        $totals = [];
        $covered = [];
        $coveredBy = [];
        $cutoffs = 0;
        $truncated = 0;
        foreach ($parts as $part) {
            foreach ($part['values'] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
            if (!$part['truncated']) {
                continue;
            }
            ++$truncated;
            // What nfdump listed, not what a lookup added: an unlisted key is at most the last listed.
            $listed = $part['known'] === [] ? $part['values'] : array_diff_key($part['values'], $part['known']);
            $cutoff = $listed === [] ? 0 : min($listed);
            $cutoffs += $cutoff;
            foreach ($part['values'] + $part['known'] as $key => $_) {
                $covered[$key] = ($covered[$key] ?? 0) + $cutoff;
                $coveredBy[$key] = ($coveredBy[$key] ?? 0) + 1;
            }
        }
        arsort($totals, SORT_NUMERIC);

        /** @var array<string, int> $totals */
        return ['totals' => $totals, 'covered' => $covered, 'coveredBy' => $coveredBy, 'cutoffs' => $cutoffs, 'truncated' => $truncated];
    }

    /**
     * The first $n keys, highest total first and equal totals by key.
     *
     * @param array<string, int> $totals highest first
     *
     * @return list<string>
     */
    private static function lead(array $totals, int $n): array {
        if ($n < 1) {
            return [];
        }
        $keys = [];
        $values = [];
        $nth = null;
        foreach ($totals as $key => $total) {
            if ($nth !== null && $total < $nth) {
                break;
            }
            $keys[] = (string) $key;
            $values[] = $total;
            if (\count($keys) === $n) {
                $nth = $total;
            }
        }
        array_multisort($values, SORT_DESC, SORT_NUMERIC, $keys, SORT_ASC, SORT_STRING);

        return \array_slice($keys, 0, $n);
    }

    /**
     * @param Bounds $bounds
     *
     * @return null|list<string>
     */
    private static function top(array $bounds, int $n): ?array {
        $top = self::lead($bounds['totals'], $n);
        if ($bounds['truncated'] === 0) {
            return $top;
        }
        if (\count($top) < $n || $n < 1) {
            return null;
        }

        foreach ($top as $key) {
            if (($bounds['coveredBy'][$key] ?? 0) < $bounds['truncated'] || ($bounds['covered'][$key] ?? 0) < $bounds['cutoffs']) {
                return null;
            }
        }

        // No key outside the top may reach its last total; the totals come highest first.
        $last = $bounds['totals'][$top[$n - 1]];
        $inTop = array_flip($top);
        foreach ($bounds['totals'] as $key => $total) {
            if ($total + $bounds['cutoffs'] <= $last) {
                break;
            }
            if (!isset($inTop[$key]) && $total + $bounds['cutoffs'] - ($bounds['covered'][$key] ?? 0) > $last) {
                return null;
            }
        }

        return $bounds['cutoffs'] <= $last ? $top : null;
    }

    /**
     * @param list<Ranked> $parts
     * @param Bounds       $bounds
     *
     * @return null|array<int, list<string>>
     */
    private static function wanted(array $parts, array $bounds, int $n, int $max): ?array {
        if ($n < 1 || \count($bounds['totals']) < $n) {
            return null;
        }

        $floor = $bounds['totals'][self::lead($bounds['totals'], $n)[$n - 1]];
        $candidates = [];
        foreach ($bounds['totals'] as $key => $total) {
            if ($total + $bounds['cutoffs'] < $floor) {
                break;
            }
            if ($total + $bounds['cutoffs'] - ($bounds['covered'][$key] ?? 0) >= $floor) {
                $candidates[] = (string) $key;
                if (\count($candidates) > $max) {
                    return null;
                }
            }
        }

        $lookups = [];
        foreach ($parts as $i => $part) {
            if (!$part['truncated']) {
                continue;
            }
            $wanted = array_values(array_filter($candidates, static fn (string $key): bool => !isset($part['values'][$key]) && !isset($part['known'][$key])));
            if ($wanted !== []) {
                $lookups[$i] = $wanted;
            }
        }

        return $lookups;
    }

    /** @param list<array{empty: bool}> $parts */
    private static function allEmpty(array $parts): bool {
        return $parts !== [] && array_all($parts, static fn (array $part): bool => $part['empty']);
    }

    /** @param list<string> $fields */
    private static function joinKey(array $fields): string {
        return implode("\0", $fields) . "\0";
    }

    /** @return list<string> the fields of a joinKey() */
    private static function splitKey(string $key): array {
        return explode("\0", substr($key, 0, -1));
    }

    /** @param StatLine $line */
    private static function statJson(string $key, string $name, array $line): string {
        [$pps, $bps, $bpp] = self::rates($line);
        [$flows, $packets, $bytes, $first, $last, $geo] = $line;

        return \sprintf(
            '{ "first" : "%s", "last" : "%s", "proto" : %d, "%s" : "%s", %s"flows" : %d, "packets" : %d, "bytes" : %d, "pps" : %d, "bps" : %d, "bpp" : %d}',
            $first,
            $last,
            (int) $key,
            $name,
            self::statValue($key),
            $geo === null ? '' : '"geo" : "' . $geo . '",',
            $flows,
            $packets,
            $bytes,
            $pps,
            $bps,
            $bpp,
        );
    }

    /**
     * @param StatLine $line
     * @param Totals   $totals
     */
    private static function statCsv(string $key, array $line, string $element, array $totals): string {
        [$pps, $bps, $bpp, $duration] = self::rates($line);
        [$flows, $packets, $bytes, $first, $last] = $line;
        $share = static fn (int $count, int $total): float => $total > 0 ? ($count * 100) / $total : 0.0;

        return \sprintf(
            '%s,%s,%.3f,%s,%s,%d,%.1f,%d,%.1f,%d,%.1f,%d,%d,%d',
            str_replace('T', ' ', substr($first, 0, 19)),
            str_replace('T', ' ', substr($last, 0, 19)),
            $duration,
            $element === 'proto' ? self::protocolName((int) $key) : 'any',
            self::statValue($key),
            $flows,
            $share($flows, $totals['flows']),
            $packets,
            $share($packets, $totals['packets']),
            $bytes,
            $share($bytes, $totals['bytes']),
            $pps,
            $bps,
            $bpp,
        );
    }

    /**
     * pps, bps and bpp of a merged statistic row, and its duration in seconds.
     *
     * @param StatLine $line
     *
     * @return array{int, int, int, float}
     */
    private static function rates(array $line): array {
        [, $packets, $bytes, $first, $last] = $line;
        $firstMs = self::epochMs($first);
        $lastMs = self::epochMs($last);
        $duration = $firstMs > 0 && $lastMs > 0 ? ($lastMs - $firstMs) / 1000.0 : 0.0;
        $bpp = self::bpp($bytes, $packets);
        if ($duration === 0.0) {
            return [0, 0, $bpp, 0.0];
        }

        return [(int) ($packets / $duration), (int) ((8 * $bytes) / $duration), $bpp, $duration];
    }

    /** nfdump's uint32 bytes per packet. */
    private static function bpp(int $bytes, int $packets): int {
        return $packets > 0 ? intdiv($bytes, $packets) % 4_294_967_296 : 0;
    }

    /**
     * The field of a statistic line an order ranks by.
     *
     * @return 0|1|2
     */
    private static function orderField(string $order): int {
        return match ($order) {
            'flows' => self::FL,
            'packets' => self::PKT,
            'bytes' => self::BYT,
            default => throw new \InvalidArgumentException('A merge can only rank by flows, packets or bytes.'),
        };
    }

    /** "1790646901.203" (%tsr) in milliseconds. */
    private static function rawMs(string $raw): int {
        [$seconds, $millis] = array_pad(explode('.', trim($raw), 2), 2, '0');

        return (int) $seconds * 1000 + (int) $millis;
    }
}
