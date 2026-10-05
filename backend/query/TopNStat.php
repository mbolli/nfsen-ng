<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * The statistics the top-N collector precomputes per capture file. The value is what the
 * `stat` column of the topn tables stores, so the cases must never be renumbered.
 */
enum TopNStat: int {
    case SrcIp = 1;
    case DstIp = 2;
    case SrcPort = 3;
    case DstPort = 4;
    case Proto = 5;
    case SrcAs = 6;
    case DstAs = 7;
    case InIf = 8;
    case OutIf = 9;

    /** The `-s` element; ports use the per-protocol form, so a key reads "443/tcp". */
    public function nfdumpElement(): string {
        return match ($this) {
            self::SrcIp => 'srcip',
            self::DstIp => 'dstip',
            self::SrcPort => 'srcport:p',
            self::DstPort => 'dstport:p',
            self::Proto => 'proto',
            self::SrcAs => 'srcas',
            self::DstAs => 'dstas',
            self::InIf => 'inif',
            self::OutIf => 'outif',
        };
    }

    /** An ifIndex only means something on the exporter that reported it. */
    public function perSource(): bool {
        return $this === self::InIf || $this === self::OutIf;
    }

    public function label(): string {
        return match ($this) {
            self::SrcIp => 'Source IP',
            self::DstIp => 'Destination IP',
            self::SrcPort => 'Source port',
            self::DstPort => 'Destination port',
            self::Proto => 'Protocol',
            self::SrcAs => 'Source AS',
            self::DstAs => 'Destination AS',
            self::InIf => 'Input interface',
            self::OutIf => 'Output interface',
        };
    }

    /**
     * Every stored value, ascending.
     *
     * @return list<int>
     */
    public static function values(): array {
        return array_map(static fn (self $stat): int => $stat->value, self::cases());
    }
}
