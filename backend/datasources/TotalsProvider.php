<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\datasources;

/**
 * Stored traffic volume in [start, end): the capture files whose start timestamp lies
 * inside, the same set a `-R` over that window reads.
 *
 * @phpstan-type Totals array{flows: float, packets: float, bytes: float}
 * @phpstan-type ProtocolTotals array{any: Totals, tcp: Totals, udp: Totals, icmp: Totals, other: Totals}
 */
interface TotalsProvider {
    public const array PROTOCOLS = ['any', 'tcp', 'udp', 'icmp', 'other'];

    /**
     * Volume in [start, end) summed over sources.
     *
     * @param list<string> $sources  [] or ['any'] for every configured source
     * @param string       $protocol one of PROTOCOLS
     *
     * @return Totals
     *
     * @throws \InvalidArgumentException for a protocol outside PROTOCOLS
     */
    public function fetchTotals(array $sources, string $profile, int $start, int $end, string $protocol = 'any'): array;

    /**
     * The same in one pass for every protocol (Flows Summary, D13).
     *
     * @param list<string> $sources [] or ['any'] for every configured source
     *
     * @return ProtocolTotals
     */
    public function fetchProtocolTotals(array $sources, string $profile, int $start, int $end): array;
}
