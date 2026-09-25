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
     * @param string               $for         what to aggregate by, a StatisticCatalog element such as 'srcip'
     * @param string               $orderBy     which counter to sort on, one of StatisticCatalog::ORDER_BY
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
        /** The global protocol, one of ProtocolFilter::PROTOCOLS. */
        public string $protocol = 'any',
    ) {
        // These reach nfdump's options, and the messages leave the value out because the
        // panels render them as markup.
        if (!StatisticCatalog::isValid($for)) {
            throw new \InvalidArgumentException('Unknown statistic.');
        }
        if (!\in_array($orderBy, StatisticCatalog::ORDER_BY, true)) {
            throw new \InvalidArgumentException('Unknown order, expected one of ' . implode(', ', StatisticCatalog::ORDER_BY) . '.');
        }
        ProtocolFilter::assertValid($protocol);
    }

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
     * The filter nfdump actually receives: byte thresholds (-l/-L apply to line output, not to
     * -s statistics mode), the global protocol and the user's expression, each parenthesised.
     *
     * @throws \InvalidArgumentException for a user filter with unbalanced parentheses
     */
    public function effectiveFilter(): string {
        return FilterComposer::and(
            Nfdump::buildThresholdFilter(trim($this->lowerLimit), trim($this->upperLimit)),
            ProtocolFilter::term($this->protocol),
            $this->filter,
        );
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
            notes: $result['notes'] ?? [],
            exitCode: $result['exitCode'] ?? 0,
        );
    }
}
