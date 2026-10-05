<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\store\TopNRepository;

/**
 * A range question to the precomputed top-N tables. The window is rounded down to 5 minute
 * boundaries and read as [start, end), which is also what the cache is keyed on.
 */
final readonly class TopNQuery {
    /**
     * @param list<string> $sources
     * @param string       $orderBy one of TopNRepository::ORDER_BY
     */
    public function __construct(
        public TimeWindow $window,
        public array $sources,
        public string $profile,
        public TopNStat $stat,
        public int $limit = 10,
        public string $orderBy = 'bytes',
    ) {
        if (!\in_array($orderBy, TopNRepository::ORDER_BY, true)) {
            throw new \InvalidArgumentException('Unknown order, expected one of ' . implode(', ', TopNRepository::ORDER_BY) . '.');
        }
        if ($limit < 1 || $limit > TopNRepository::TOP) {
            throw new \InvalidArgumentException('The limit must be between 1 and ' . TopNRepository::TOP . '.');
        }
    }

    /**
     * Reads the cache first; on a miss runs rangeTop() and rangeTotals() and stores them. The
     * cached top 50 is shared by every limit.
     *
     * @param null|\Closure():void $yield called between chunks of the range query
     */
    public function run(TopNRepository $repo, int $retentionDays, int $now, ?\Closure $yield = null): TopNResult {
        $start = $this->window->start - $this->window->start % TopNRepository::INTERVAL;
        $end = $this->window->end - $this->window->end % TopNRepository::INTERVAL;
        $sources = array_values(array_unique($this->sources));
        sort($sources);

        $generation = TopNCollector::generation($this->profile);
        $key = TopNRepository::cacheKey($this->profile, $this->stat, $sources, $start, $end, $this->orderBy);
        $hit = TopNRepository::cached($key, $generation, $now);
        if ($hit === null) {
            $hit = [
                'rows' => $repo->rangeTop($this->profile, $this->stat, $sources, $start, $end, $this->orderBy, $yield),
                'totals' => $repo->rangeTotals($this->profile, $sources, $start, $end),
            ];
            TopNRepository::remember($key, $generation, $now, $hit['rows'], $hit['totals']);
        }

        $totals = $hit['totals'];
        $rows = [];
        foreach (\array_slice($hit['rows'], 0, $this->limit) as $row) {
            $rows[] = [
                'key' => $row['key'],
                'source' => $row['source'],
                'flows' => $row['flows'],
                'packets' => $row['packets'],
                'bytes' => $row['bytes'],
                'share' => $totals['bytes'] > 0 ? (float) ($row['bytes'] * 100 / $totals['bytes']) : 0.0,
                'intervals' => $row['intervals'],
            ];
        }

        $expected = intdiv(max(0, $end - $start), TopNRepository::INTERVAL) * \count($sources);

        return new TopNResult(
            rows: $rows,
            totals: $totals,
            coverage: $expected > 0 ? min(1.0, $totals['intervals'] / $expected) : 0.0,
            outOfRetention: $retentionDays <= 0 || $this->window->start < $now - $retentionDays * 86400,
            exactIntervals: $end - $start <= TopNRepository::EXACT_MAX,
            retentionDays: $retentionDays,
        );
    }
}
