<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * `share` is a percentage of the window's collected bytes; `intervals` (intervals times sources
 * whose top 50 held the key) is only meaningful while `exactIntervals` is true.
 */
final readonly class TopNResult {
    /**
     * @param list<array{key: string, source: string, flows: int, packets: int, bytes: int, share: float, intervals: int}> $rows
     * @param array{flows: int, packets: int, bytes: int, intervals: int}                                                  $totals
     * @param float                                                                                                        $coverage collected intervals / expected intervals, 0..1
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public float $coverage,
        public bool $outOfRetention,
        public bool $exactIntervals,
        public int $retentionDays,
    ) {}
}
