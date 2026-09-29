<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Top-N statistics over the capture files: nfdump -s <for>/<orderBy>.
 *
 * Built from plain values rather than signals, so the same query can be driven by the
 * Top Talkers page, a test, or any other caller without a php-via Context in scope.
 *
 * @phpstan-import-type StatRow from MultiStatCsvParser
 * @phpstan-import-type Part from PartitionPlanner
 * @phpstan-import-type Meta from PartitionPlanner
 */
final readonly class StatsQuery {
    /** @var list<string> csv carries nfdump's share columns (bytP), which the Top Talkers bars show */
    public const array OUTPUTS = ['json', 'csv'];

    /** @var array<string, string> element => the field a lookup of its keys filters by */
    private const array LOOKUP_ELEMENTS = [
        'srcip' => 'srcip', 'dstip' => 'dstip', 'ip' => 'ip', 'srcport' => 'srcport', 'dstport' => 'dstport', 'port' => 'port',
        'srcas' => 'srcas', 'dstas' => 'dstas', 'as' => 'as', 'proto' => 'proto',
    ];

    /** @var array<string, string> fmt token => the field a lookup of its values filters by */
    private const array TOKEN_FIELDS = ['%pr' => 'proto', '%sp' => 'srcport', '%dp' => 'dstport', '%sa' => 'srcip', '%da' => 'dstip'];

    /** @var array<string, string> -A element => the fmt token nfdump prints it with */
    private const array RECORD_TOKENS = [
        'proto' => '%pr', 'srcport' => '%sp', 'dstport' => '%dp',
        'srcip' => '%sa', 'srcip4' => '%sa', 'srcip6' => '%sa', 'dstip' => '%da', 'dstip4' => '%da', 'dstip6' => '%da',
    ];

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
        /** nfdump's -o, one of OUTPUTS; an aggregated record statistic answers csv whatever this says. */
        public string $output = 'json',
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
        if (!\in_array($output, self::OUTPUTS, true)) {
            throw new \InvalidArgumentException('Unknown output, expected one of ' . implode(', ', self::OUTPUTS) . '.');
        }
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
        $processor->setOption('-o', $this->output);
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

    /**
     * Whether time slices can merge exactly: a summed order, and Flow Records only with -A, since
     * `-s record` prints per-record fields no sum rebuilds and -B pairs across a slice boundary.
     */
    public function splittable(): bool {
        if (!\in_array($this->orderBy, PartitionMerge::SUMMED_ORDERS, true)) {
            return false;
        }

        return $this->for !== 'record' || $this->recordTokens() !== [];
    }

    /**
     * Runs the query as parallel time slices when that pays (PartitionPlanner), else as one
     * process; the rows are the same either way.
     *
     * @param string                                                            $kind      the query_kind whose throughput sizes the estimate
     * @param null|\Closure(int, \Closure(): array{int, int}, bool, bool): void $onSplit   see PartitionPlanner::run()
     * @param null|\Closure(): bool                                             $cancelled whether a Kill arrived
     * @param null|int                                                          $parts     tests: this many parts at most
     *
     * @throws \Exception when nfdump cannot be run
     */
    public function runPartitioned(string $kind, ?Processor $processor = null, ?\Closure $onSplit = null, ?\Closure $cancelled = null, ?int $parts = null): QueryResult {
        $processor ??= $this->processor();
        if (!$this->splittable()) {
            return $this->run($processor);
        }

        $startedAt = microtime(true);
        $files = PartitionPlanner::files($this->window, $this->sources, $this->profile);
        $single = fn (): QueryResult => $this->run($processor);

        if ($this->for === 'record') {
            $tokens = $this->recordTokens();

            return PartitionPlanner::run(
                kind: $kind,
                files: $files,
                n: $this->limit,
                handle: $this->handle,
                single: $single,
                build: fn (array $part, int $limit, ?string $filter): Processor => $this->partProcessor($part, $limit, PartitionMerge::recordFormat($tokens), '', $filter),
                parse: static function (array $part, array $output, int $limit, ?array $only = null) use ($tokens): array {
                    $data = PartitionMerge::parseRecords($output['rawOutput'], \count($tokens), $only);
                    $data['truncated'] = $limit > 0 && \count($data['lines']) >= $limit;
                    $data['empty'] = $data['empty'] || PartitionMerge::saysNoMatch('', $output['notes'] ?? []);

                    return $data;
                },
                rank: fn (array $data): array => PartitionMerge::rankFlows(array_values($data), $this->orderBy),
                render: fn (array $data, array $top, array $meta): ?QueryResult => $this->merged(PartitionMerge::records(array_values($data), $top), $meta, $processor, $startedAt),
                keyFilter: fn (array $keys): string => FilterComposer::and($this->effectiveFilter(), $this->recordKeyFilter($keys)),
                onSplit: $onSplit,
                cancelled: $cancelled,
                parts: $parts,
            );
        }

        $aux = $this->output === 'csv' ? ($this->for === 'proto' ? 'dir' : 'proto') : '';

        return PartitionPlanner::run(
            kind: $kind,
            files: $files,
            n: $this->limit,
            handle: $this->handle,
            single: $single,
            build: fn (array $part, int $limit, ?string $filter): Processor => $this->partProcessor($part, $limit, 'json', $filter === null ? $aux : '', $filter),
            parse: static function (array $part, array $output, int $limit) use ($aux): array {
                $data = PartitionMerge::parseStats($output['rawOutput'], $aux, $limit);
                $data['truncated'] = $limit > 0 && \count($data['lines']) >= $limit;
                $data['empty'] = $data['empty'] || PartitionMerge::saysNoMatch('', $output['notes'] ?? []);

                return $data;
            },
            rank: fn (array $data): array => PartitionMerge::rankStats(array_values($data), $this->orderBy),
            render: fn (array $data, array $top, array $meta): ?QueryResult => $this->merged(PartitionMerge::stats(array_values($data), $top, $this->for, $this->orderBy, $this->output), $meta, $processor, $startedAt),
            keyFilter: fn (array $keys): ?string => isset(self::LOOKUP_ELEMENTS[$this->for])
                ? FilterComposer::and($this->effectiveFilter(), PartitionMerge::inList(self::LOOKUP_ELEMENTS[$this->for], array_map(PartitionMerge::statValue(...), $keys)))
                : null,
            onSplit: $onSplit,
            cancelled: $cancelled,
            parts: $parts,
        );
    }

    /**
     * The rows of a csv run with nfdump's share of bytes (bytP), read from the raw output: the
     * processor's own csv decoding keeps the columns but not their meaning.
     *
     * @return list<StatRow>
     */
    public function statRows(QueryResult $result): array {
        $raw = \is_string($result->rawOutput) ? $result->rawOutput : '';

        return MultiStatCsvParser::parse($raw, [$this->for])[0] ?? [];
    }

    /**
     * The fmt tokens of the -A spec, in its order; empty when it has none, is bidirectional or
     * holds an element outside RECORD_TOKENS.
     *
     * @return list<string>
     */
    private function recordTokens(): array {
        $spec = $this->aggregationString();
        if ($this->for !== 'record' || $spec === '' || $spec === 'bidirectional') {
            return [];
        }

        $tokens = [];
        foreach (explode(',', $spec) as $element) {
            $token = self::RECORD_TOKENS[explode('/', $element, 2)[0]] ?? null;
            if ($token === null) {
                return [];
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * One time slice listing $limit keys (0: all) in $format, with the $aux statistic whose rows
     * add up to its totals, under a lookup's $filter or else the query's own.
     *
     * @param Part $part
     */
    private function partProcessor(array $part, int $limit, string $format, string $aux, ?string $filter = null): Processor {
        $processor = new Config::$processorClass();
        if ($processor instanceof Nfdump) {
            $processor->keepRawOutput();
        }
        $processor->setQueryHandle($this->handle);
        $processor->setProfile($this->profile);
        $processor->setOption('-M', implode(':', $this->sources));
        $processor->setOption('-R', $part['range']);
        $processor->setOption('-n', $limit);
        $processor->setOption('-o', $format);
        $stat = $this->for . '/' . $this->orderBy;
        $processor->setOption('-s', $aux === '' ? $stat : [$stat, $aux . '/bytes']);
        $aggregate = $this->aggregationString();
        if ($aggregate !== '') {
            $processor->setOption('-a', '-A' . $aggregate);
        }
        $processor->setFilter($filter ?? $this->effectiveFilter());

        return $processor;
    }

    /**
     * A filter matching the flows of aggregated Flow Records $keys: every field of the key in
     * the values the keys hold there, with the -A netmask on addresses.
     *
     * @param list<string> $keys
     */
    private function recordKeyFilter(array $keys): string {
        $elements = explode(',', $this->aggregationString());
        $terms = [];
        foreach (PartitionMerge::keyFields($keys) as $i => $values) {
            [$element, $mask] = array_pad(explode('/', $elements[$i] ?? '', 2), 2, '');
            $terms[] = PartitionMerge::inList(self::TOKEN_FIELDS[self::RECORD_TOKENS[$element] ?? ''] ?? '', $values, $mask);
        }

        return implode(' and ', $terms);
    }

    /**
     * What the merged parts print ($raw) decoded the way the processor decodes one run; null
     * when the shares of a csv could not be known.
     *
     * @param Meta $meta
     */
    private function merged(?string $raw, array $meta, Processor $processor, float $startedAt): ?QueryResult {
        if ($raw === null) {
            return null;
        }

        $decoder = new Nfdump();
        $aggregate = $this->aggregationString();
        if ($aggregate !== '') {
            $decoder->setOption('-a', '-A' . $aggregate);
        }
        $decoder->setOption('-o', $aggregate !== '' ? 'csv' : $this->output);
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
