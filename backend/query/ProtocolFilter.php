<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * The global protocol as a filter term, bucketed like the stored series: ICMPv6 counts as
 * ICMP, "other" is what the named buckets miss (Import::protocolBucket()).
 */
final class ProtocolFilter {
    /** @var list<string> */
    public const array PROTOCOLS = ['any', 'tcp', 'udp', 'icmp', 'other'];

    /** @var array<string, string> */
    private const array TERMS = [
        'any' => '',
        'tcp' => 'proto tcp',
        'udp' => 'proto udp',
        'icmp' => 'proto icmp or proto icmp6',
        'other' => 'not (proto tcp or proto udp or proto icmp or proto icmp6)',
    ];

    public static function isValid(string $protocol): bool {
        return isset(self::TERMS[$protocol]);
    }

    /**
     * The message leaves the value out: the panels render it as markup.
     *
     * @throws \InvalidArgumentException for a protocol outside PROTOCOLS
     */
    public static function assertValid(string $protocol): void {
        if (!self::isValid($protocol)) {
            throw new \InvalidArgumentException('Unknown protocol, expected one of ' . implode(', ', self::PROTOCOLS) . '.');
        }
    }

    /** A known protocol in its canonical spelling, 'any' for anything else. */
    public static function normalize(string $protocol): string {
        $protocol = strtolower(trim($protocol));

        return isset(self::TERMS[$protocol]) ? $protocol : 'any';
    }

    /**
     * The filter term for a protocol, '' for any.
     *
     * @throws \InvalidArgumentException for a protocol outside PROTOCOLS
     */
    public static function term(string $protocol): string {
        $key = strtolower(trim($protocol));
        self::assertValid($key);

        return self::TERMS[$key];
    }
}
