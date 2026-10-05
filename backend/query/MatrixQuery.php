<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Source-to-destination pairs, aggregated: the query behind the Conversations page (4.4.2).
 *
 * Grouping by destination port puts the port into the aggregation key, which gives the Sankey
 * its middle column (source, destination port, destination).
 *
 * @phpstan-import-type Part from PartitionPlanner
 * @phpstan-import-type Meta from PartitionPlanner
 */
final readonly class MatrixQuery {
    /** @var list<string> */
    public const array GROUPS = ['ip', 'net24', 'net16', 'port'];

    /** @var list<string> */
    public const array DIRECTIONS = ['forward', 'both'];

    /** The most directed pairs a "both" run fetches before merging them (D15). */
    public const int MAX_FETCH = 2000;

    /** The most pairs a run returns. */
    public const int MAX_TOP_N = 500;

    /**
     * @param list<string> $sources
     * @param string       $metric    'bytes' or 'packets'; anything else is read as bytes
     * @param bool         $showPorts the MCP tool's name for grouping by destination port
     * @param string       $groupBy   one of GROUPS
     * @param string       $direction one of DIRECTIONS; 'both' cannot be combined with the port grouping
     *
     * @throws \InvalidArgumentException for an unknown protocol, grouping or direction
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
        /** The global protocol, one of ProtocolFilter::PROTOCOLS. */
        public string $protocol = 'any',
        public string $groupBy = 'ip',
        public string $direction = 'forward',
    ) {
        ProtocolFilter::assertValid($protocol);
        if (!\in_array($groupBy, self::GROUPS, true)) {
            throw new \InvalidArgumentException('Unknown grouping, expected one of ' . implode(', ', self::GROUPS) . '.');
        }
        if (!\in_array($direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException('Unknown direction, expected one of ' . implode(', ', self::DIRECTIONS) . '.');
        }
        if ($direction === 'both' && $this->groupBy() === 'port') {
            throw new \InvalidArgumentException('Both directions cannot be merged when grouping by destination port.');
        }
    }

    public function metric(): string {
        return $this->metric === 'packets' ? 'packets' : 'bytes';
    }

    /** One of GROUPS. */
    public function groupBy(): string {
        return $this->showPorts ? 'port' : $this->groupBy;
    }

    public function isSubnet(): bool {
        return \in_array($this->groupBy(), ['net24', 'net16'], true);
    }

    /** nfdump's `-n`: the pairs asked for, or four times as many directed pairs to merge (D15). */
    public function fetchLimit(): int {
        return self::fetchLimitFor($this->direction, $this->topN);
    }

    public static function fetchLimitFor(string $direction, int $topN): int {
        $topN = max(1, min(self::MAX_TOP_N, $topN));

        return $direction === 'both' ? min(self::MAX_FETCH, 4 * $topN) : $topN;
    }

    /** The `-A` aggregation spec of the grouping. */
    public function aggregation(): string {
        return match ($this->groupBy()) {
            'net24' => 'srcip4/24,dstip4/24',
            'net16' => 'srcip4/16,dstip4/16',
            'port' => 'srcip,dstport,dstip',
            default => 'srcip,dstip',
        };
    }

    /**
     * Byte thresholds, the global protocol, `ipv4` for a subnet grouping (the `/24` and `/16`
     * masks are IPv4 only) and the user's expression, each parenthesised.
     *
     * @throws \InvalidArgumentException for a user filter with unbalanced parentheses
     */
    public function effectiveFilter(): string {
        return FilterComposer::and(
            Nfdump::buildThresholdFilter(trim($this->lowerLimit), trim($this->upperLimit)),
            ProtocolFilter::term($this->protocol),
            $this->isSubnet() ? 'ipv4' : '',
            $this->filter,
        );
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

        return $this->groupBy() === 'port'
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
        $processor->setOption('-a', '-A' . $this->aggregation());
        $processor->setOption('-O', $this->metric());
        $processor->setOption('-n', $this->fetchLimit());
        $processor->setOption('-N', null);
        // Full IPv6 addresses: the condensed `2001:db8..e0:1` form is not an address.
        $processor->setOption('-6', null);

        $version = Nfdump::version();
        $format = $this->outputFormat($version);
        if ($format === 'csv') {
            Debug::getInstance()->log('nfdump ' . $version . ' cannot combine a custom fmt: format with aggregation, using -o csv for Conversations', LOG_DEBUG);
        }
        $processor->setOption('-o', $format);
        $processor->setFilter($this->effectiveFilter());

        return $processor;
    }

    /**
     * Runs the query as parallel time slices when that pays (PartitionPlanner), else as one
     * process, as always on nfdump 1.7.5, whose plain csv lacks the out counters.
     *
     * @param null|\Closure(int, \Closure(): array{int, int}, bool, bool): void $onSplit   see PartitionPlanner::run()
     * @param null|\Closure(): bool                                             $cancelled whether a Kill arrived
     * @param null|int                                                          $parts     tests: this many parts at most
     *
     * @throws \Exception        when nfdump cannot be run
     * @throws \RuntimeException when the processor answers with something that is not a table
     */
    public function runPartitioned(?Processor $processor = null, ?\Closure $onSplit = null, ?\Closure $cancelled = null, ?int $parts = null): QueryResult {
        $processor ??= $this->processor();
        $format = $this->outputFormat();
        if (!self::splits($format)) {
            return $this->run($processor);
        }

        $startedAt = microtime(true);
        $keyFields = $this->groupBy() === 'port' ? 3 : 2;

        return PartitionPlanner::run(
            kind: 'conversations',
            files: PartitionPlanner::files($this->window, $this->sources, $this->profile),
            n: $this->fetchLimit(),
            handle: $this->handle,
            single: fn (): QueryResult => $this->run($processor),
            build: fn (array $part, int $limit, ?string $filter): Processor => $this->partProcessor($part, $limit, PartitionMerge::pairFormat($format), $filter),
            parse: static function (array $part, array $output, int $limit, ?array $only = null) use ($keyFields): array {
                $data = PartitionMerge::parsePairs($output['rawOutput'], $keyFields, $only);
                $data['truncated'] = $limit > 0 && \count($data['lines']) >= $limit;
                $data['empty'] = $data['empty'] || PartitionMerge::saysNoMatch('', $output['notes'] ?? []);

                return $data;
            },
            rank: fn (array $data): array => PartitionMerge::rankFlows(array_values($data), $this->metric()),
            render: fn (array $data, array $top, array $meta): QueryResult => $this->merged(PartitionMerge::pairs(array_values($data), $top), $meta, $processor, $format, $startedAt),
            keyFilter: fn (array $keys): string => FilterComposer::and($this->effectiveFilter(), $this->pairFilter($keys)),
            onSplit: $onSplit,
            cancelled: $cancelled,
            parts: $parts,
        );
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
            notes: $result['notes'] ?? [],
            exitCode: $result['exitCode'] ?? 0,
        );
    }

    /** Whether a run in the output $format can be split: a `fmt:` that prints the out counters. */
    private static function splits(string $format): bool {
        return str_starts_with($format, 'fmt:');
    }

    /**
     * One time slice listing $limit pairs (0: all) in $format, under a lookup's $filter or else
     * the query's own.
     *
     * @param Part $part
     */
    private function partProcessor(array $part, int $limit, string $format, ?string $filter = null): Processor {
        $processor = new Config::$processorClass();
        if ($processor instanceof Nfdump) {
            $processor->keepRawOutput();
        }
        $processor->setQueryHandle($this->handle);
        $processor->setProfile($this->profile);
        $processor->setOption('-M', implode(':', $this->sources));
        $processor->setOption('-R', $part['range']);
        $processor->setOption('-a', '-A' . $this->aggregation());
        $processor->setOption('-O', $this->metric());
        $processor->setOption('-n', $limit);
        $processor->setOption('-N', null);
        $processor->setOption('-6', null);
        $processor->setOption('-o', $format);
        $processor->setFilter($filter ?? $this->effectiveFilter());

        return $processor;
    }

    /**
     * A filter matching the flows of the pairs $keys, and of more pairs, which a lookup ignores.
     *
     * @param list<string> $keys
     */
    private function pairFilter(array $keys): string {
        $mask = match ($this->groupBy()) {
            'net24' => '24',
            'net16' => '16',
            default => '',
        };
        $fields = PartitionMerge::keyFields($keys);
        $terms = [PartitionMerge::inList('srcip', $fields[0] ?? [], $mask), PartitionMerge::inList('dstip', $fields[1] ?? [], $mask)];
        if (isset($fields[2])) {
            $terms[] = PartitionMerge::inList('dstport', $fields[2]);
        }

        return implode(' and ', $terms);
    }

    /**
     * What the merged parts print ($raw) decoded the way the processor decodes one run.
     *
     * @param Meta $meta
     */
    private function merged(string $raw, array $meta, Processor $processor, string $format, float $startedAt): QueryResult {
        $decoder = new Nfdump();
        $decoder->setOption('-a', '-A' . $this->aggregation());
        $decoder->setOption('-o', $format);
        $command = $processor instanceof Nfdump ? $processor->commandLine() : implode("\n", $meta['commands']);
        $result = $decoder->decodeOutput($command, $raw, $startedAt);

        return new QueryResult(
            rows: array_values($result['decoded']),
            command: $command,
            stderr: implode("\n", $meta['stderr']),
            elapsed: round(microtime(true) - $startedAt, 3),
            window: $this->window,
            rawOutput: $result['rawOutput'],
            notes: $result['notes'],
            exitCode: $meta['exitCode'],
            parts: \count($meta['commands']),
            partCommands: $meta['commands'],
        );
    }
}
