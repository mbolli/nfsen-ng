<?php

/**
 * Table Cell Formatter
 * Provides formatting functions for different data types in table cells.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

class TableFormatter {
    /**
     * Regex patterns for field type detection.
     */
    private const PATTERN_DATE_FIELDS = '/^(first|last|received|t_first|t_last|time|timestamp)$/i';
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
    private const PATTERN_PERCENTAGE_SUFFIX = '/(percent|pct|ratio)$/i';
    private const PATTERN_IP_SUFFIX = '/(?:ip|addr)$/i';

    /**
     * Get the raw sort value for a cell (unformatted).
     *
     * @param mixed  $value     The cell value
     * @param string $fieldName The field name
     *
     * @return mixed Raw sort value (number, string, etc.)
     */
    public static function getSortValue($value, string $fieldName) {
        // Handle null/empty values
        if ($value === null || $value === '') {
            return '';
        }

        $fieldLower = strtolower($fieldName);

        // For date/timestamp fields, convert to Unix timestamp for numeric sorting
        if (preg_match(self::PATTERN_DATE_FIELDS, $fieldLower)
            || preg_match(self::PATTERN_DATE_SUFFIX, $fieldLower)) {
            // If it's already a timestamp (numeric), return it
            if (is_numeric($value)) {
                return (int) $value;
            }

            // Try to parse as date string and convert to timestamp
            try {
                $date = new \DateTimeImmutable($value);

                return $date->getTimestamp();
            } catch (\Exception) {
                // If parsing fails, return original value
                return $value;
            }
        }

        // For IP address fields (e.g. srcip, dstip, src_addr, ip), convert to sortable format
        if (preg_match(self::PATTERN_IP_SUFFIX, strtolower($fieldName))) {
            // If already a dotted IP string, use it
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return \sprintf('%010u', ip2long($value));
            }

            // If value is numeric (nfdump may produce integer IPs), convert
            if (is_numeric($value)) {
                $int = (int) $value;
                if ($int >= 0 && $int <= 0xFFFFFFFF) {
                    return \sprintf('%010u', $int);
                }
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
     * @param mixed  $value     The cell value
     * @param string $fieldName The field name
     * @param array  $options   Display options
     *
     * @return string Formatted HTML
     */
    public static function formatCellValue($value, string $fieldName, array $options): string {
        // Handle null/empty values
        if ($value === null || $value === '') {
            return '';
        }

        $fieldLower = strtolower($fieldName);

        // Format dates (timestamps like first, last, received, t_first, t_last)
        if (preg_match(self::PATTERN_DATE_FIELDS, $fieldLower)
            || preg_match(self::PATTERN_DATE_SUFFIX, $fieldLower)) {
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
                return \sprintf(
                    '<nfsen-ip-info-modal data-ip="%s"><a href="#" class="ip-link">%s</a></nfsen-ip-info-modal>',
                    htmlspecialchars((string) $value),
                    htmlspecialchars((string) $value)
                );
            }
        }

        return (string) $value;
    }

    /**
     * Format a timestamp or date string.
     *
     * @param mixed $value
     */
    private static function formatDate($value): string {
        // If it's already a formatted date string, return it
        if (!is_numeric($value)) {
            $date = new \DateTimeImmutable($value);

            return $date->format('Y-m-d H:i:s');
        }

        // Convert Unix timestamp to readable format
        $timestamp = (int) $value;
        if ($timestamp > 0) {
            return date('Y-m-d H:i:s', $timestamp);
        }

        return (string) $value;
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
     * Format bytes with appropriate unit (B, KB, MB, GB, TB).
     *
     * @param mixed $value
     */
    private static function formatBytes($value): string {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $bytes = (float) $value;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        if ($bytes <= 0) {
            return '0 B';
        }

        $power = floor(log($bytes, 1024));
        $power = min($power, \count($units) - 1);

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

        $power = floor(log($rate, 1000));
        $power = min($power, \count($units) - 1);

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
            return (string) $value;
        }

        if ($value === '........') {
            return '<span class="text-muted small">-</span>';
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
                   . \sprintf(' <span class="text-muted small">(%s)</span>', $value);
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
                   . \sprintf(' <span class="text-muted small">(0x%02X)</span>', $flags);
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
                '%s <span class="text-muted small">(ToS:%d/DSCP:%d)</span>',
                $dscpNames[$dscp],
                $tos,
                $dscp
            );
        }

        // If not a common value, just show ToS and DSCP values
        if ($dscp > 0) {
            return \sprintf('DSCP %d <span class="text-muted small">(ToS:%d)</span>', $dscp, $tos);
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
                '%s <span class="text-muted small">(%d)</span>',
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
            // Color-code based on status type
            $class = 'text-muted';
            if ($status >= 65 && $status < 128) {
                $class = 'text-success'; // Forwarded = green
            } elseif ($status >= 128 && $status < 192) {
                $class = 'text-danger'; // Dropped = red
            } elseif ($status >= 192) {
                $class = 'text-info'; // Consumed = blue
            }

            return \sprintf(
                '<span class="%s">%s</span> <span class="text-muted small">(%d)</span>',
                $class,
                $statusNames[$status],
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
                '%s <span class="text-muted small">(%d)</span>',
                strtoupper($protoName),
                $protoNum
            );
        }

        return (string) $value;
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
