<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/** Cell values of result tables as HTML: units, names for numbers, status as [data-level]. */
class TableFormatter {
    /**
     * Regex patterns for field type detection.
     */
    private const PATTERN_DATE_FIELDS = '/^(first|last|received|t_first|t_last|time|timestamp|firstseen|lastseen)$/i';
    private const PATTERN_DATE_SUFFIX = '/_time$|_date$/';
    private const PATTERN_DURATION_FIELDS = '/^(duration|td)$/i';
    private const PATTERN_BYTES_FIELDS = '/^(bytes|ibyt|obyt|octets|in_bytes|out_bytes)$/i';
    private const PATTERN_BYTES_SUFFIX = '/bytes$/';
    private const PATTERN_PACKETS_FIELDS = '/^(packets|ipkt|opkt|pkts|in_pkts|out_pkts)$/i';
    private const PATTERN_PACKETS_SUFFIX = '/packets?$/';
    private const PATTERN_FLOWS_FIELDS = '/^(flows|records)$/i';
    private const PATTERN_BITRATE_FIELDS = '/^(bps|pps|bpp)$/i';
    private const PATTERN_BITRATE_SUFFIX = '/(bitrate|bandwidth)/';
    private const PATTERN_FLAGS_FIELDS = '/^(flags|tcp_flags|flg)$/i';
    private const PATTERN_TOS_FIELDS = '/^(tos|src_tos|dst_tos|dscp)$/i';
    private const PATTERN_ICMP_FIELDS = '/^(icmp_type|icmptype)$/i';
    private const PATTERN_FWD_STATUS_FIELDS = '/^(fwd_status|fwdstatus|forwarding_status)$/i';
    private const PATTERN_PROTO_FIELDS = '/^(proto|protocol)$/i';
    private const PATTERN_PORT_FIELDS = '/^(srcport|dstport|src_port|dst_port|sp|dp|port|natsrcport|natdstport|natport|nsrcport|ndstport|xlate_src_port|xlate_dst_port|nat_src_port|nat_dst_port)$/i';
    private const PATTERN_NAT_EVENT_FIELDS = '/^(event|xevent|nevent|nsel_event|nat_event)$/i';
    private const PATTERN_PERCENTAGE_SUFFIX = '/(percent|pct|ratio)$/i';
    private const PATTERN_IP_SUFFIX = '/(?:ip|addr)$/i';

    /** nfdump's NSEL/NAT event names and codes as [label, badge level]. */
    private const array EVENT_NAMES = [
        'ignore' => ['ignore', ''],
        'create' => ['create', 'success'],
        'delete' => ['delete', 'error'],
        'term' => ['term', 'error'],
        'deny' => ['deny', 'warning'],
        'keepalive' => ['keepalive', ''],
        'add' => ['add', 'success'],
        '<no-evt>' => ['no-event', ''],
    ];

    private const array EVENT_CODES = [
        0 => ['ignore', ''],
        1 => ['create', 'success'],
        2 => ['delete', 'error'],
        3 => ['keepalive', ''],
        4 => ['deny', 'warning'],
        5 => ['quota exceeded', 'warning'],
    ];

    /**
     * How a column's cells are set (2.5): 'num' right aligned in tabular figures, 'time' and
     * 'address' by their own rules, '' as text.
     */
    public static function cellKind(string $fieldName): string {
        $field = strtolower($fieldName);

        return match (true) {
            self::isDate($field) => 'time',
            preg_match(self::PATTERN_BYTES_FIELDS, $field) === 1,
            preg_match(self::PATTERN_BYTES_SUFFIX, $field) === 1,
            preg_match(self::PATTERN_PACKETS_FIELDS, $field) === 1,
            preg_match(self::PATTERN_PACKETS_SUFFIX, $field) === 1 && !str_contains($field, 'port'),
            preg_match(self::PATTERN_FLOWS_FIELDS, $field) === 1,
            preg_match(self::PATTERN_BITRATE_FIELDS, $field) === 1,
            preg_match(self::PATTERN_DURATION_FIELDS, $field) === 1,
            preg_match(self::PATTERN_PERCENTAGE_SUFFIX, $field) === 1 => 'num',
            preg_match(self::PATTERN_IP_SUFFIX, $field) === 1 => 'address',
            default => '',
        };
    }

    /**
     * Get the raw sort value for a cell (unformatted).
     *
     * @param mixed  $value     The cell value
     * @param string $fieldName The field name
     *
     * @return mixed Raw sort value (number, string, etc.)
     */
    public static function getSortValue($value, string $fieldName) {
        if ($value === null || $value === '' || !\is_scalar($value)) {
            return '';
        }

        $fieldLower = strtolower($fieldName);

        // Dates sort by their timestamp.
        if (self::isDate($fieldLower)) {
            return self::epoch($value) ?? $value;
        }

        // For IP address fields (e.g. srcip, dstip, src_addr, ip), convert to sortable format
        if (preg_match(self::PATTERN_IP_SUFFIX, strtolower($fieldName))) {
            // If already a dotted IP string, use it
            if (\is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return \sprintf('%010u', ip2long($value));
            }

            // If value is numeric (nfdump may produce integer IPs), convert
            if (is_numeric($value)) {
                $int = (int) $value;
                if ($int >= 0 && $int <= 0xFFFFFFFF) {
                    return \sprintf('%010u', $int);
                }
            }

            // IPv6 shares its column with IPv4 once nfdump's src4_addr/src6_addr keys are
            // merged, so it needs a key the client's numeric sort can order too: the four
            // 32-bit words as fixed-width decimals, behind a '6' that keeps every IPv6
            // address above every IPv4 one (whose key is at most 10 digits).
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $words = unpack('N4', (string) inet_pton((string) $value));

                return $words === false
                    ? $value
                    : \sprintf('6%010u%010u%010u%010u', $words[1], $words[2], $words[3], $words[4]);
            }

            // Fallback: return as-is
            return $value;
        }

        // For numeric fields, return the raw number
        if (is_numeric($value)) {
            return $value;
        }

        // For everything else, return as-is
        return $value;
    }

    /**
     * Format a cell value for display.
     *
     * @param mixed                $value     The cell value
     * @param string               $fieldName The field name
     * @param array<string, mixed> $options   Display options
     *
     * @return string Formatted HTML
     */
    public static function formatCellValue($value, string $fieldName, array $options): string {
        if ($value === null || $value === '') {
            return '';
        }
        if (!\is_scalar($value)) {
            return htmlspecialchars((string) json_encode($value), ENT_QUOTES | ENT_HTML5);
        }

        $fieldLower = strtolower($fieldName);

        if (self::isDate($fieldLower)) {
            return self::formatDate($value);
        }

        // Format duration fields
        if (preg_match(self::PATTERN_DURATION_FIELDS, $fieldLower)) {
            return self::formatDuration($value);
        }

        // Format bytes (ibyt, obyt, bytes, octets)
        if (preg_match(self::PATTERN_BYTES_FIELDS, $fieldLower)
            || preg_match(self::PATTERN_BYTES_SUFFIX, $fieldLower)) {
            return self::formatBytes($value);
        }

        // Format packets (ipkt, opkt, packets, but NOT port fields)
        if (preg_match(self::PATTERN_PACKETS_FIELDS, $fieldLower)
            || (preg_match(self::PATTERN_PACKETS_SUFFIX, $fieldLower) && !preg_match('/port/', $fieldLower))) {
            return self::formatNumber($value);
        }

        // Format flows
        if (preg_match(self::PATTERN_FLOWS_FIELDS, $fieldLower)) {
            return self::formatNumber($value);
        }

        // Bytes per packet is a size, not a rate.
        if ($fieldLower === 'bpp') {
            return is_numeric($value) ? self::formatNumber($value) . ' B' : (string) $value;
        }

        // Format bitrate/bandwidth (bps, pps)
        if (preg_match(self::PATTERN_BITRATE_FIELDS, $fieldLower)
            || preg_match(self::PATTERN_BITRATE_SUFFIX, $fieldLower)) {
            return self::formatBitrate($value, $fieldLower);
        }

        // Format TCP flags
        if (preg_match(self::PATTERN_FLAGS_FIELDS, $fieldLower)) {
            return self::formatTcpFlags($value);
        }

        // Format ToS (Type of Service / DSCP)
        if (preg_match(self::PATTERN_TOS_FIELDS, $fieldLower) && is_numeric($value)) {
            return self::formatToS($value);
        }

        // Format ICMP type
        if (preg_match(self::PATTERN_ICMP_FIELDS, $fieldLower) && is_numeric($value)) {
            return self::formatIcmpType($value);
        }

        // Format Forwarding Status
        if (preg_match(self::PATTERN_FWD_STATUS_FIELDS, $fieldLower) && is_numeric($value)) {
            return self::formatForwardingStatus($value);
        }

        // Format protocol numbers to names
        if (preg_match(self::PATTERN_PROTO_FIELDS, $fieldLower) && is_numeric($value)) {
            return self::formatProtocol($value);
        }

        // Format NSEL/NAT event type
        if (preg_match(self::PATTERN_NAT_EVENT_FIELDS, $fieldLower)) {
            return self::formatNatEvent($value);
        }

        // Format port numbers with service names
        if (preg_match(self::PATTERN_PORT_FIELDS, $fieldLower) && is_numeric($value)) {
            return self::formatPort($value);
        }

        // Format percentage values
        if (preg_match(self::PATTERN_PERCENTAGE_SUFFIX, $fieldLower)) {
            return self::formatPercentage($value);
        }

        // Check if this is an IP address field and linking is enabled
        if ($options['linkIpAddresses'] && preg_match(self::PATTERN_IP_SUFFIX, strtolower($fieldName))) {
            // If it's a numeric IPv4 value, convert to dotted notation
            if (!filter_var($value, FILTER_VALIDATE_IP) && is_numeric($value)) {
                $int = (int) $value;
                if ($int >= 0 && $int <= 0xFFFFFFFF) {
                    $value = long2ip($int);
                }
            }

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                // The table element posts ip-info for any .ip-link clicked inside it (Table::generate).
                return '<a href="#" class="ip-link">' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5) . '</a>';
            }
        }

        // Escape HTML for unknown/unhandled field types to prevent XSS
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * A time as <time data-epoch>: the server's rendering until nfsen-table.js localises it in
     * the display timezone. A value that is not a date stays as it came.
     */
    private static function formatDate(bool|float|int|string $value): string {
        $epoch = self::epoch($value);
        if ($epoch === null || $epoch <= 0) {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5);
        }

        return \sprintf('<time data-epoch="%d">%s</time>', $epoch, date('Y-m-d H:i:s', $epoch));
    }

    /** Seconds since the epoch of a timestamp or a date string nfdump printed in the server's timezone. */
    private static function epoch(bool|float|int|string|null $value): ?int {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    private static function isDate(string $fieldLower): bool {
        return preg_match(self::PATTERN_DATE_FIELDS, $fieldLower) === 1 || preg_match(self::PATTERN_DATE_SUFFIX, $fieldLower) === 1;
    }

    /**
     * Format a duration value (in seconds or milliseconds).
     *
     * @param mixed $value
     */
    private static function formatDuration($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $seconds = (float) $value;

        // If value is very large, it might be milliseconds
        if ($seconds > 1000000) {
            $seconds /= 1000;
        }

        if ($seconds < 1) {
            return \sprintf('%d ms', $seconds * 1000);
        }
        if ($seconds < 60) {
            return \sprintf('%.2f s', $seconds);
        }
        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;

            return \sprintf('%d:%05.2f', $minutes, $secs);
        }
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return \sprintf('%d:%02d:%05.2f', $hours, $minutes, $secs);
    }

    /**
     * Format bytes with a binary unit (B, KiB, MiB, GiB, TiB, PiB).
     *
     * @param mixed $value
     */
    private static function formatBytes($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $bytes = (float) $value;
        // Powers of 1024, so binary prefixes: the charts, Sankey and Matrix print the same.
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

        if ($bytes <= 0) {
            return '0 B';
        }

        $power = max(0, min((int) floor(log($bytes, 1024)), \count($units) - 1));

        $formattedValue = $bytes / 1024 ** $power;

        if ($formattedValue >= 100) {
            return \sprintf('%.1f %s', $formattedValue, $units[$power]);
        }
        if ($formattedValue >= 10) {
            return \sprintf('%.2f %s', $formattedValue, $units[$power]);
        }

        return \sprintf('%.3f %s', $formattedValue, $units[$power]);
    }

    /**
     * Format a number with thousand separators.
     *
     * @param mixed $value
     */
    private static function formatNumber($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        return number_format((float) $value, 0, '.', ',');
    }

    /**
     * Format bitrate (bps or pps).
     *
     * @param mixed $value
     */
    private static function formatBitrate($value, string $fieldName): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $rate = (float) $value;

        // Packets per second
        if (preg_match('/pps/', $fieldName)) {
            if ($rate < 1000) {
                return \sprintf('%.0f pps', $rate);
            }
            if ($rate < 1000000) {
                return \sprintf('%.2f Kpps', $rate / 1000);
            }

            return \sprintf('%.2f Mpps', $rate / 1000000);
        }

        // Bits per second
        $units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];

        if ($rate <= 0) {
            return '0 bps';
        }

        $power = max(0, min((int) floor(log($rate, 1000)), \count($units) - 1));

        $formattedValue = $rate / 1000 ** $power;

        if ($units[$power] === 'bps') {
            return \sprintf('%.0f %s', $formattedValue, $units[$power]);
        }

        return \sprintf('%.2f %s', $formattedValue, $units[$power]);
    }

    /**
     * Format TCP flags into human-readable string.
     *
     * @param mixed $value
     */
    private static function formatTcpFlags($value): string {
        if (empty($value)) {
            return \is_scalar($value) ? (string) $value : '';
        }

        if ($value === '........') {
            return '<small>-</small>';
        }

        // Handle string format like "......S." (from nfdump)
        if (\is_string($value) && preg_match('/^[\.FSRPAUEC]+$/', $value)) {
            $flagNames = [];
            $flagMap = [
                'F' => 'FIN',
                'S' => 'SYN',
                'R' => 'RST',
                'P' => 'PSH',
                'A' => 'ACK',
                'U' => 'URG',
                'E' => 'ECE',
                'C' => 'CWR',
            ];

            foreach (str_split($value) as $char) {
                if ($char !== '.' && isset($flagMap[$char])) {
                    $flagNames[] = $flagMap[$char];
                }
            }

            if (empty($flagNames)) {
                return (string) $value;
            }

            return implode(', ', $flagNames)
                   . \sprintf(' <small>(%s)</small>', $value);
        }

        // Handle numeric format (hex or decimal)
        if (is_numeric($value) || preg_match('/^0x/', (string) $value)) {
            $flags = is_numeric($value) ? (int) $value : hexdec((string) $value);
            $flagNames = [];

            // TCP flag bit positions
            if ($flags & 0x01) {
                $flagNames[] = 'FIN';
            }
            if ($flags & 0x02) {
                $flagNames[] = 'SYN';
            }
            if ($flags & 0x04) {
                $flagNames[] = 'RST';
            }
            if ($flags & 0x08) {
                $flagNames[] = 'PSH';
            }
            if ($flags & 0x10) {
                $flagNames[] = 'ACK';
            }
            if ($flags & 0x20) {
                $flagNames[] = 'URG';
            }
            if ($flags & 0x40) {
                $flagNames[] = 'ECE';
            }
            if ($flags & 0x80) {
                $flagNames[] = 'CWR';
            }

            if (empty($flagNames)) {
                return (string) $value;
            }

            return implode(', ', $flagNames)
                   . \sprintf(' <small>(0x%02X)</small>', $flags);
        }

        return (string) $value;
    }

    /**
     * Format ToS (Type of Service) / DSCP value.
     *
     * @param mixed $value
     */
    private static function formatToS($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $tos = (int) $value;

        // Common DSCP values (most significant 6 bits of ToS byte)
        $dscp = $tos >> 2; // Extract DSCP from ToS

        $dscpNames = [
            // Class Selector (CS)
            0 => 'Best Effort (CS0)',
            8 => 'CS1',
            16 => 'CS2',
            24 => 'CS3',
            32 => 'CS4',
            40 => 'CS5',
            48 => 'CS6',
            56 => 'CS7 (Network Control)',

            // Assured Forwarding (AF)
            10 => 'AF11 (High Priority, Low Drop)',
            12 => 'AF12 (High Priority, Med Drop)',
            14 => 'AF13 (High Priority, High Drop)',
            18 => 'AF21 (Med-High Priority, Low Drop)',
            20 => 'AF22 (Med-High Priority, Med Drop)',
            22 => 'AF23 (Med-High Priority, High Drop)',
            26 => 'AF31 (Med-Low Priority, Low Drop)',
            28 => 'AF32 (Med-Low Priority, Med Drop)',
            30 => 'AF33 (Med-Low Priority, High Drop)',
            34 => 'AF41 (Low Priority, Low Drop)',
            36 => 'AF42 (Low Priority, Med Drop)',
            38 => 'AF43 (Low Priority, High Drop)',

            // Expedited Forwarding (EF) - VoIP, real-time
            46 => 'EF (Expedited Forwarding)',

            // Voice Admit
            44 => 'Voice Admit',
        ];

        if (isset($dscpNames[$dscp])) {
            return \sprintf(
                '%s <small>(ToS:%d/DSCP:%d)</small>',
                $dscpNames[$dscp],
                $tos,
                $dscp
            );
        }

        // If not a common value, just show ToS and DSCP values
        if ($dscp > 0) {
            return \sprintf('DSCP %d <small>(ToS:%d)</small>', $dscp, $tos);
        }

        return (string) $value;
    }

    /**
     * Format ICMP type to human-readable name.
     *
     * @param mixed $value
     */
    private static function formatIcmpType($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $icmpTypes = [
            0 => 'Echo Reply',
            3 => 'Destination Unreachable',
            4 => 'Source Quench',
            5 => 'Redirect',
            8 => 'Echo Request',
            9 => 'Router Advertisement',
            10 => 'Router Solicitation',
            11 => 'Time Exceeded',
            12 => 'Parameter Problem',
            13 => 'Timestamp',
            14 => 'Timestamp Reply',
            15 => 'Information Request',
            16 => 'Information Reply',
            17 => 'Address Mask Request',
            18 => 'Address Mask Reply',
            30 => 'Traceroute',
            // ICMPv6 types (commonly seen in modern networks)
            128 => 'Echo Request (IPv6)',
            129 => 'Echo Reply (IPv6)',
            130 => 'Multicast Listener Query',
            131 => 'Multicast Listener Report',
            132 => 'Multicast Listener Done',
            133 => 'Router Solicitation (IPv6)',
            134 => 'Router Advertisement (IPv6)',
            135 => 'Neighbor Solicitation',
            136 => 'Neighbor Advertisement',
            137 => 'Redirect Message (IPv6)',
        ];

        $typeNum = (int) $value;

        if (isset($icmpTypes[$typeNum])) {
            return \sprintf(
                '%s <small>(%d)</small>',
                $icmpTypes[$typeNum],
                $typeNum
            );
        }

        return (string) $value;
    }

    /**
     * Format Forwarding Status (NetFlow v9/IPFIX field).
     *
     * @param mixed $value
     */
    private static function formatForwardingStatus($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $status = (int) $value;

        $statusNames = [
            // Unknown
            0 => 'Unknown',
            64 => 'Unknown',

            // Forwarded
            65 => 'Forwarded',
            66 => 'Forwarded (Fragmented)',
            67 => 'Forwarded (Not Fragmented)',

            // Dropped
            128 => 'Dropped',
            129 => 'Dropped (ACL Deny)',
            130 => 'Dropped (ACL Drop)',
            131 => 'Dropped (Unroutable)',
            132 => 'Dropped (Adjacency)',
            133 => 'Dropped (Fragmentation & DF)',
            134 => 'Dropped (Bad Header Checksum)',
            135 => 'Dropped (Bad Total Length)',
            136 => 'Dropped (Bad Header Length)',
            137 => 'Dropped (Bad TTL)',
            138 => 'Dropped (Policer)',
            139 => 'Dropped (WRED)',
            140 => 'Dropped (RPF Failure)',
            141 => 'Dropped (For Us)',
            142 => 'Dropped (Bad Output Interface)',
            143 => 'Dropped (Hardware)',

            // Consumed
            192 => 'Consumed',
            193 => 'Consumed (Punt Adjacency)',
            194 => 'Consumed (Incomplete Adjacency)',
            195 => 'Consumed (For Us)',
        ];

        if (isset($statusNames[$status])) {
            $level = match (true) {
                $status >= 192 => 'info',
                $status >= 128 => 'error',
                $status >= 65 => 'success',
                default => '',
            };

            return \sprintf(
                '<span class="status-text"%s>%s</span> <small>(%d)</small>',
                $level !== '' ? ' data-level="' . $level . '"' : '',
                htmlspecialchars($statusNames[$status], ENT_QUOTES | ENT_HTML5),
                $status
            );
        }

        return (string) $value;
    }

    /**
     * Format protocol number to name.
     *
     * @param mixed $value
     */
    private static function formatProtocol($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $protoNum = (int) $value;
        $protoName = getprotobynumber($protoNum);

        if ($protoName !== false) {
            return \sprintf(
                '%s <small>(%d)</small>',
                strtoupper($protoName),
                $protoNum
            );
        }

        return (string) $value;
    }

    /**
     * Format port number with service name annotation.
     *
     * @param mixed $value
     */
    private static function formatPort($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $port = (int) $value;
        $service = getservbyport($port, 'tcp') ?: getservbyport($port, 'udp');

        if ($service !== false) {
            return \sprintf(
                '%d <small>(%s)</small>',
                $port,
                htmlspecialchars($service, ENT_QUOTES | ENT_HTML5)
            );
        }

        return (string) $port;
    }

    /**
     * A NSEL/NAT event as a badge, levelled when it says something happened to a connection.
     * NSEL/ASA codes: 0 ignore, 1 create, 2 delete, 3 keepalive, 4 deny; NEL: 1 add, 2 delete.
     *
     * @param mixed $value
     */
    private static function formatNatEvent($value): string {
        $event = self::EVENT_NAMES[strtolower(trim(\is_scalar($value) ? (string) $value : ''))] ?? null;
        if ($event === null && is_numeric($value)) {
            $event = self::EVENT_CODES[(int) $value] ?? ['event ' . (int) $value, ''];
        }
        if ($event === null) {
            return htmlspecialchars(\is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_HTML5);
        }

        [$label, $level] = $event;

        return \sprintf(
            '<span class="badge"%s>%s</span>',
            $level !== '' ? ' data-level="' . $level . '"' : '',
            htmlspecialchars($label, ENT_QUOTES | ENT_HTML5)
        );
    }

    /**
     * Format percentage values.
     *
     * @param mixed $value
     */
    private static function formatPercentage($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $percent = (float) $value;

        // If value is between 0-1, assume it's a decimal representation
        if ($percent > 0 && $percent <= 1) {
            $percent *= 100;
        }

        return \sprintf('%.2f%%', $percent);
    }
}
