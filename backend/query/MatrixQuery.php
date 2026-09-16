<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Source-to-destination pairs, aggregated: the query behind the Sankey diagram.
 *
 * With ports enabled the destination port joins the aggregation key, which is what gives the
 * diagram its middle column (source address, destination port, destination address).
 */
final readonly class MatrixQuery {
    /**
     * @param list<string> $sources
     * @param string       $metric  'bytes' or 'packets'; anything else is read as bytes
     */
    public function __construct(
        public TimeWindow $window,
        public array $sources,
        public string $profile,
        public string $metric,
        public int $topN,
        public bool $showPorts = false,
        public string $filter = '',
        public string $lowerLimit = '',
        public string $upperLimit = '',
        /** Names this query's nfdump runs, so a kill can target it. */
        public string $handle = 'default',
    ) {}

    public function metric(): string {
        return $this->metric === 'packets' ? 'packets' : 'bytes';
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregation(): array {
        return $this->showPorts
            ? ['srcip' => 'srcip', 'dstport' => true, 'dstip' => 'dstip']
            : ['srcip' => 'srcip', 'dstip' => 'dstip'];
    }

    public function effectiveFilter(): string {
        $threshold = Nfdump::buildThresholdFilter(trim($this->lowerLimit), trim($this->upperLimit));
        $filter = trim($this->filter);

        if ($threshold === '') {
            return $filter;
        }

        return $threshold . ($filter !== '' ? ' and ' . $filter : '');
    }

    /**
     * nfdump 1.7.5 rejects a custom `fmt:` format alongside `-A` aggregation, so there it gets
     * the plain aggregated csv instead: same fields under different names, which the payload
     * builder reads as aliases (see Nfdump::needsAggregatedCsv() and #159).
     */
    public function outputFormat(?string $version = null): string {
        $version ??= Nfdump::version();

        if (Nfdump::needsAggregatedCsv($version)) {
            return 'csv';
        }

        return $this->showPorts
            ? 'fmt:%sa %da %dp %ibyt %ipkt %fl'
            : 'fmt:%sa %da %ibyt %ipkt %fl';
    }

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
        $processor->setOption('-a', '-A' . Nfdump::buildAggregationString($this->aggregation()));
        $processor->setOption('-O', $this->metric());
        $processor->setOption('-n', $this->topN);
        $processor->setOption('-N', null);

        $version = Nfdump::version();
        $format = $this->outputFormat($version);
        if ($format === 'csv') {
            Debug::getInstance()->log('nfdump ' . $version . ' cannot combine a custom fmt: format with aggregation — using -o csv for the Sankey', LOG_DEBUG);
        }
        $processor->setOption('-o', $format);
        $processor->setFilter($this->effectiveFilter());

        return $processor;
    }

    /**
     * @throws \Exception        when nfdump cannot be run
     * @throws \RuntimeException when the processor answers with something that is not a table
     */
    public function run(?Processor $processor = null): QueryResult {
        $processor ??= $this->processor();
        $start = microtime(true);
        $result = $processor->execute();

        $rows = $result['decoded'] ?? [];
        if (!\is_array($rows)) {
            throw new \RuntimeException('Invalid data from nfdump processor');
        }

        return new QueryResult(
            rows: array_values($rows),
            command: (string) ($result['command'] ?? ''),
            stderr: (string) ($result['stderr'] ?? ''),
            elapsed: round(microtime(true) - $start, 3),
            window: $this->window,
            rawOutput: $result['rawOutput'] ?? null,
        );
    }
}
