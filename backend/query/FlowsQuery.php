<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;

/**
 * Individual flow records over the capture files, optionally aggregated.
 *
 * The Flows panel's query, expressed without signals. Unlike StatsQuery the window is not
 * clamped here: listing flows is bounded by the record limit rather than by the range.
 *
 * @phpstan-type ReturnedSummary array{records: int, flows: int, aggregated: bool, packets: int, bytes: int, first: ?float, last: ?float, duration: ?float, bps: ?float, pps: ?float, bpp: ?float}
 * @phpstan-type ProtocolRow array{proto: string, number: string, flows: int, packets: int, bytes: int}
 * @phpstan-type FilteredTotals array{flows: int, packets: int, bytes: int, protocols: list<ProtocolRow>}
 */
final readonly class FlowsQuery {
    /** The statistic of the filtered totals run: one row per protocol, every protocol. */
    public const string SUMMARY_STATISTIC = 'proto/bytes';

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
        /** The global protocol, one of ProtocolFilter::PROTOCOLS. */
        public string $protocol = 'any',
    ) {
        ProtocolFilter::assertValid($protocol);
    }

    public function aggregationString(): string {
        return Nfdump::buildAggregationString($this->aggregation);
    }

    /**
     * Byte thresholds, the global protocol and the user's expression, each parenthesised so
     * an `or` in one cannot swallow the others.
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
     * Bytes of capture data this query will read. Walks the range, so callers defer it.
     */
    public function totalBytes(): int {
        return NfcapdFiles::totalSize(
            NfcapdFiles::list($this->window->start, $this->window->end, $this->sources, $this->profile)
        );
    }

    public function processor(): Processor {
        $processor = $this->baseProcessor();
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
     * The filtered totals of the Summary tab (D13): every flow the filter matches in the window,
     * one row per protocol. The row limit, ordering and aggregation do not apply.
     *
     * @throws \InvalidArgumentException for a user filter with unbalanced parentheses
     */
    public function summaryProcessor(): Processor {
        $processor = $this->baseProcessor();
        $processor->setOption('-o', 'csv');
        $processor->setOption('-n', 0);
        $processor->setOption('-s', self::SUMMARY_STATISTIC);
        $processor->setFilter($this->effectiveFilter());

        return $processor;
    }

    /**
     * @throws \Exception when nfdump cannot be run
     */
    public function run(?Processor $processor = null): QueryResult {
        $processor ??= $this->processor();

        return $this->execute($processor);
    }

    /**
     * Runs the filtered totals; the parsed totals come from summaryFromOutput() on rawOutput.
     *
     * @throws \Exception when nfdump cannot be run
     */
    public function runSummary(?Processor $processor = null): QueryResult {
        return $this->execute($processor ?? $this->summaryProcessor());
    }

    /**
     * Sums the per-protocol rows of a summary run, largest first.
     *
     * @return FilteredTotals
     */
    public static function summaryFromOutput(string $rawOutput): array {
        $rows = MultiStatCsvParser::parse($rawOutput, [self::SUMMARY_STATISTIC])[0] ?? [];
        usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $protocols = [];
        $flows = $packets = $bytes = 0;
        foreach ($rows as $row) {
            $flows += $row['flows'];
            $packets += $row['packets'];
            $bytes += $row['bytes'];
            $protocols[] = [
                'proto' => $row['proto'] !== '' ? $row['proto'] : $row['key'],
                'number' => $row['key'],
                'flows' => $row['flows'],
                'packets' => $row['packets'],
                'bytes' => $row['bytes'],
            ];
        }

        return ['flows' => $flows, 'packets' => $packets, 'bytes' => $bytes, 'protocols' => $protocols];
    }

    /**
     * Figures of the rows a run returned: JSON records, aggregated CSV or the biflow table. -c
     * limits the flows nfdump reads, which aggregated rows count in their flows column.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return ReturnedSummary
     */
    public static function returnedSummary(array $rows): array {
        $flows = $packets = $bytes = 0;
        $first = $last = null;
        $aggregated = false;

        foreach ($rows as $row) {
            $aggregated = $aggregated || isset($row['flows']);
            $flows += isset($row['flows']) ? self::count($row['flows']) : 1;
            $packets += self::sum($row, ['packets', 'in_packets', 'out_packets', 'inPackets', 'outPackets']);
            $bytes += self::sum($row, ['bytes', 'in_bytes', 'out_bytes', 'inBytes', 'outBytes']);

            $start = self::epoch($row['first'] ?? $row['firstSeen'] ?? $row['ts'] ?? null);
            $end = self::epoch($row['last'] ?? $row['lastSeen'] ?? $row['te'] ?? null);
            $duration = $row['duration'] ?? $row['td'] ?? null;
            if ($end === null && $start !== null && is_numeric($duration)) {
                $end = $start + (float) $duration;
            }
            if ($start !== null) {
                $first = $first === null ? $start : min($first, $start);
            }
            $end ??= $start;
            if ($end !== null) {
                $last = $last === null ? $end : max($last, $end);
            }
        }

        $span = $first !== null && $last !== null ? max(0.0, $last - $first) : null;
        $perSecond = static fn (float $value): ?float => $span !== null && $span > 0 ? $value / $span : null;

        return [
            'records' => \count($rows),
            'flows' => $flows,
            'aggregated' => $aggregated,
            'packets' => $packets,
            'bytes' => $bytes,
            'first' => $first,
            'last' => $last,
            'duration' => $span,
            'bps' => $perSecond($bytes * 8.0),
            'pps' => $perSecond((float) $packets),
            'bpp' => $packets > 0 ? $bytes / $packets : null,
        ];
    }

    /** Profile, sources and window, which every run of this query shares. */
    private function baseProcessor(): Processor {
        $processor = new Config::$processorClass();
        $processor->setQueryHandle($this->handle);
        $processor->setProfile($this->profile);
        $processor->setOption('-M', implode(':', $this->sources));
        $processor->setOption('-R', $this->window->toRangeOption());

        return $processor;
    }

    private function execute(Processor $processor): QueryResult {
        $start = microtime(true);
        $result = $processor->execute();

        return new QueryResult(
            rows: array_values((array) ($result['decoded'] ?? [])),
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
     * @param array<string, mixed> $row
     * @param list<string>         $keys
     */
    private static function sum(array $row, array $keys): int {
        $total = 0;
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                $total += self::count($row[$key]);
            }
        }

        return $total;
    }

    private static function count(mixed $value): int {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** nfdump prints its times in the server's timezone, the same one PHP runs in. */
    private static function epoch(mixed $value): ?float {
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (float) new \DateTimeImmutable($value)->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
