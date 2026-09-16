<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;

/**
 * The aggregated series behind the Graphs tab, straight from the datasource.
 *
 * The cheap half of the two tiers: the datasource already holds five-minute aggregates, so
 * this answers when something started and how big it is without reading a capture file. Any
 * caller wanting *who* and *what* has to pay for nfdump instead.
 */
final readonly class TimelineQuery {
    /**
     * @param list<string> $sources
     * @param list<string> $protocols empty means every protocol
     * @param list<int>    $ports     empty means the configured ports
     * @param string       $unit      flows|packets|bytes|bits
     * @param string       $display   sources|protocols|ports
     */
    public function __construct(
        public TimeWindow $window,
        public array $sources,
        public array $protocols = [],
        public array $ports = [],
        public string $unit = 'flows',
        public string $display = 'sources',
        public int $resolution = 500,
        public string $profile = '',
    ) {}

    /**
     * @return array{data: array<int, list<null|float|int>>, start: int, end: int, step: int, legend: list<string>}
     *
     * @throws \RuntimeException when the datasource reports a problem instead of a series
     */
    public function run(): array {
        $data = Config::$db->get_graph_data(
            $this->window->start,
            $this->window->end,
            $this->sources,
            $this->protocols,
            $this->ports,
            $this->unit,
            $this->display,
            $this->resolution,
            $this->profile,
        );

        // RRD answers with an error string rather than throwing; treat both the same way.
        if (\is_string($data)) {
            throw new \RuntimeException($data);
        }

        return $data;
    }

    /**
     * When the datasource was last written for these sources, which is a truer "now" for a
     * graph than wall clock: the most recent slot may not be imported yet.
     */
    public function lastWrite(): int {
        $sources = array_values(array_filter($this->sources, static fn (string $s): bool => $s !== 'any'))
            ?: Config::$settings->sources;

        if ($sources === []) {
            return 0;
        }

        return max(array_map(
            fn (string $s): int => Config::$db->last_update($s, 0, $this->profile),
            $sources
        ));
    }
}
