<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * The nfdump filter primitives the filter builder offers (4.5.3): fields that insert a
 * snippet at the cursor, examples, and the words autocomplete suggests. A `<name>` part of
 * a snippet is a placeholder the editor selects after inserting it.
 *
 * @phpstan-type Field array{group: 'advanced'|'basic', label: string, snippet: string, help: string}
 * @phpstan-type Example array{expression: string, description: string}
 */
final class FilterGrammar {
    /** A sample value per placeholder, so every snippet can be checked with `nfdump -Z`. */
    public const array PLACEHOLDERS = [
        'ip' => '192.0.2.10',
        'cidr' => '10.0.0.0/8',
        'n' => '10',
        'a' => '80',
        'b' => '443',
        'protocol' => 'tcp',
        'flags' => 'S',
        'expr' => 'proto udp',
        'asn' => '64500',
        'ms' => '1000',
        'mac' => '00:11:22:33:44:55',
    ];

    private const array FIELDS = [
        ['basic', 'Source host', 'src host <ip>', 'Flows sent by this address'],
        ['basic', 'Destination host', 'dst host <ip>', 'Flows sent to this address'],
        ['basic', 'Host', 'host <ip>', 'Flows from or to this address'],
        ['basic', 'Source network', 'src net <cidr>', 'Flows sent from this subnet, e.g. 10.0.0.0/8'],
        ['basic', 'Destination network', 'dst net <cidr>', 'Flows sent to this subnet'],
        ['basic', 'Network', 'net <cidr>', 'Flows from or to this subnet'],
        ['basic', 'Source port', 'src port <n>', 'Source port number'],
        ['basic', 'Destination port', 'dst port <n>', 'Destination port number'],
        ['basic', 'Port', 'port <n>', 'Source or destination port'],
        ['basic', 'Port list', 'port in [<a> <b>]', 'Any of the listed ports, separated by spaces; Tab selects the next placeholder'],
        ['basic', 'Protocol', 'proto <protocol>', 'tcp, udp, icmp, icmp6 or a protocol number'],
        ['basic', 'Bytes', 'bytes > <n>', 'Flows with more bytes; k, M and G multiply'],
        ['basic', 'Packets', 'packets > <n>', 'Flows with more packets'],
        ['basic', 'TCP flags', 'flags <flags>', 'S SYN, A ACK, F FIN, R RST, P PSH, U URG'],
        ['basic', 'Not', 'not <expr>', 'Everything the expression does not match'],
        ['basic', 'And', 'and <expr>', 'Both expressions match'],
        ['basic', 'Or', 'or <expr>', 'Either expression matches'],
        ['basic', 'Group', '( <expr> )', 'Groups an expression, e.g. before not'],
        ['advanced', 'Source AS', 'src as <asn>', 'Source autonomous system number'],
        ['advanced', 'Destination AS', 'dst as <asn>', 'Destination autonomous system number'],
        ['advanced', 'AS', 'as <asn>', 'Source or destination AS'],
        ['advanced', 'Input interface', 'in if <n>', 'SNMP index of the input interface'],
        ['advanced', 'Output interface', 'out if <n>', 'SNMP index of the output interface'],
        ['advanced', 'VLAN', 'vlan <n>', 'Source or destination VLAN id'],
        ['advanced', 'Type of service', 'tos <n>', 'IP type of service, 0 to 255'],
        ['advanced', 'Next hop', 'next ip <ip>', 'Next hop router address'],
        ['advanced', 'Exporter', 'router ip <ip>', 'Address of the router that exported the flow'],
        ['advanced', 'IPv4', 'ipv4', 'IPv4 flows only'],
        ['advanced', 'IPv6', 'ipv6', 'IPv6 flows only'],
        ['advanced', 'Duration', 'duration > <ms>', 'Flows lasting longer, in milliseconds'],
        ['advanced', 'Bits per second', 'bps > <n>', 'Average bit rate of the flow'],
        ['advanced', 'Packets per second', 'pps > <n>', 'Average packet rate of the flow'],
        ['advanced', 'Bytes per packet', 'bpp > <n>', 'Average packet size of the flow'],
        ['advanced', 'Source MAC', 'src mac <mac>', 'Source MAC address'],
        ['advanced', 'MPLS label', 'mpls label1 <n>', 'First MPLS label'],
    ];

    private const array EXAMPLES = [
        ['dst port in [80 443]', 'Web traffic'],
        ['port 53', 'DNS'],
        ['src net 10.0.0.0/8 and dst net 10.0.0.0/8', 'Internal only'],
        ['bytes > 100M', 'Large transfers'],
        ['proto tcp and flags S and not flags A', 'TCP SYN without ACK'],
        ['proto icmp or proto icmp6', 'ICMP'],
        ['host 192.0.2.10', 'One host, both directions'],
        ['not net 192.168.0.0/16', 'Exclude a subnet'],
    ];

    /** Keywords beyond the ones the snippets spell out. */
    private const array EXTRA_KEYWORDS = ['tcp', 'udp', 'icmp', 'icmp6', 'ip', 'any', 'mask', 'engine-type', 'icmp-type', 'icmp-code'];

    /** @return list<Field> */
    public static function fields(): array {
        return array_values(array_map(
            static fn (array $f): array => ['group' => $f[0], 'label' => $f[1], 'snippet' => $f[2], 'help' => $f[3]],
            self::FIELDS,
        ));
    }

    /** @return list<Example> */
    public static function examples(): array {
        return array_values(array_map(
            static fn (array $e): array => ['expression' => $e[0], 'description' => $e[1]],
            self::EXAMPLES,
        ));
    }

    /**
     * Words offered by autocomplete: every word of every snippet, plus protocol names.
     *
     * @return list<string>
     */
    public static function keywords(): array {
        $words = self::EXTRA_KEYWORDS;
        foreach (self::FIELDS as $field) {
            $stripped = preg_replace('/<[a-z]+>/', ' ', $field[2]) ?? '';
            foreach (preg_split('/[^a-z0-9-]+/', $stripped, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (preg_match('/^[a-z]/', $word) === 1) {
                    $words[] = $word;
                }
            }
        }
        $words = array_values(array_unique($words));
        sort($words);

        return $words;
    }

    /**
     * The snippet with each placeholder replaced by its sample value.
     */
    public static function fill(string $snippet): string {
        return preg_replace_callback(
            '/<([a-z]+)>/',
            static fn (array $m): string => self::PLACEHOLDERS[$m[1]] ?? $m[0],
            $snippet,
        ) ?? $snippet;
    }
}
