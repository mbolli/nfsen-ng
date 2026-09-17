<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Top-N statistics over the capture files: nfdump -s <for>/<orderBy>.
 *
 * Built from plain values rather than signals, so the same query can be driven by the
 * Statistics panel, a test, or any other caller without a php-via Context in scope.
 */
final readonly class StatsQuery {
    /**
     * @param list<string>         $sources
     * @param string               $for         what to aggregate by, e.g. 'srcip', 'dstport'
     * @param string               $orderBy     which counter to sort on, e.g. 'bytes', 'flows'
     * @param array<string, mixed> $aggregation keys accepted by Nfdump::buildAggregationString()
     */
    public function __construct(
        public TimeWindow $window,
        public array $sources,
        public string $profile,
        public string $for,
        public string $orderBy,
        public int $limit,
        public string $filter = '',
        public string $lowerLimit = '',
        public string $upperLimit = '',
        public array $aggregation = [],
        /** Names this query's nfdump runs, so a kill can target it. */
        public string $handle = 'default',
    ) {}

    /**
     * The -A spec, empty when this statistic cannot use one.
     *
     * nfdump applies an aggregation to `-s record` only. Every other statistic aggregates by
     * its own element already and answers "Warning: Aggregation ignored for element
     * statistics", so the spec is dropped here rather than passed on to be ignored.
     */
    public function aggregationString(): string {
        return $this->for === 'record' ? Nfdump::buildAggregationString($this->aggregation) : '';
    }

    /**
     * The filter nfdump actually receives: byte thresholds are prepended to the user's
     * expression, because -l/-L apply to line output and not to -s statistics mode.
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
     * Bytes of capture data this query will read, for a progress denominator or a cost
     * estimate. Walks and stat()s the range, so callers defer it off the request path.
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
        $processor->setOption('-n', $this->limit);
        $processor->setOption('-o', 'json');
        $processor->setOption('-s', $this->for . '/' . $this->orderBy);

        // Same shape as the Flows panel: bidirectional is its own flag, everything else is a
        // spec. Setting -a is also what makes the processor swap json for csv, which nfdump
        // requires: it rejects `-o json` outright once records are aggregated.
        $aggregate = $this->aggregationString();
        if ($aggregate !== '') {
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

        // nfdump can answer with a JSON object rather than a list, and consumers index the
        // first row positionally, so re-key.
        $rows = array_values((array) ($result['decoded'] ?? []));

        return new QueryResult(
            rows: $rows,
            command: (string) ($result['command'] ?? ''),
            stderr: (string) ($result['stderr'] ?? ''),
            elapsed: round(microtime(true) - $start, 3),
            window: $this->window,
            rawOutput: $result['rawOutput'] ?? null,
        );
    }
}
