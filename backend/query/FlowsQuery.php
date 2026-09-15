<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Individual flow records over the capture files, optionally aggregated.
 *
 * The Flows panel's query, expressed without signals. Unlike StatsQuery the window is not
 * clamped here: listing flows is bounded by the record limit rather than by the range.
 */
final readonly class FlowsQuery {
    /**
     * @param list<string>         $sources
     * @param array<string, mixed> $aggregation keys accepted by Nfdump::buildAggregationString()
     */
    public function __construct(
        public TimeWindow $window,
        public array $sources,
        public string $profile,
        public int $limit,
        public string $filter = '',
        public string $lowerLimit = '',
        public string $upperLimit = '',
        public array $aggregation = [],
        public bool $orderByStart = false,
        /** Names this query's nfdump runs, so a kill can target it. */
        public string $handle = 'default',
    ) {}

    public function aggregationString(): string {
        return Nfdump::buildAggregationString($this->aggregation);
    }

    /**
     * Byte thresholds prepend the user's expression, the same rule the statistics query uses.
     */
    public function effectiveFilter(): string {
        $threshold = Nfdump::buildThresholdFilter(trim($this->lowerLimit), trim($this->upperLimit));
        $filter = trim($this->filter);

        if ($threshold === '') {
            return $filter;
        }

        return $threshold . ($filter !== '' ? ' and ' . $filter : '');
    }

    /**
     * Bytes of capture data this query will read. Walks the range, so callers defer it.
     */
    public function totalBytes(): int {
        return NfcapdFiles::totalSize(
            NfcapdFiles::list($this->window->start, $this->window->end, $this->sources, $this->profile)
        );
    }

    public function processor(): Processor {
        $processor = new Config::$processorClass();
        $processor->setQueryHandle($this->handle);
        $processor->setProfile($this->profile);
        $processor->setOption('-M', implode(':', $this->sources));
        $processor->setOption('-R', $this->window->toRangeOption());
        $processor->setOption('-c', $this->limit);
        $processor->setOption('-o', 'json');

        if ($this->orderByStart) {
            $processor->setOption('-O', 'tstart');
        }

        $aggregate = $this->aggregationString();
        if ($aggregate !== '') {
            // Bidirectional is its own flag; everything else is an aggregation spec.
            $processor->setOption(
                $aggregate === 'bidirectional' ? '-B' : '-a',
                $aggregate === 'bidirectional' ? '' : '-A' . $aggregate
            );
        }

        $processor->setFilter($this->effectiveFilter());

        return $processor;
    }

    /**
     * @throws \Exception when nfdump cannot be run
     */
    public function run(?Processor $processor = null): QueryResult {
        $processor ??= $this->processor();
        $start = microtime(true);
        $result = $processor->execute();

        return new QueryResult(
            rows: array_values((array) ($result['decoded'] ?? [])),
            command: (string) ($result['command'] ?? ''),
            stderr: (string) ($result['stderr'] ?? ''),
            elapsed: round(microtime(true) - $start, 3),
            window: $this->window,
            rawOutput: $result['rawOutput'] ?? null,
        );
    }
}
