<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;

/**
 * What data actually exists: per source, the first and last sample and the last import.
 *
 * Exists so callers stop asking for ranges that were never imported. A gap at the end of the
 * data is not a quiet network, it is an import that has not caught up, and the two look
 * identical on a graph.
 */
final readonly class CoverageQuery {
    /**
     * @param list<string> $sources empty means every configured source
     */
    public function __construct(
        public array $sources = [],
        public string $profile = '',
        /**
         * Ask each source when it was last imported. Off for callers that only want the data
         * range: on VictoriaMetrics every one of these is an HTTP round trip, and the date
         * picker recomputes its bounds on every render.
         */
        public bool $withLastUpdate = true,
    ) {}

    /**
     * @return array{
     *     sources: list<array{source: string, first: int, last: int, last_update: int}>,
     *     first: int,
     *     last: int,
     * }
     */
    public function run(): array {
        $sources = $this->sources ?: Config::$settings->sources;

        $perSource = [];
        $firsts = [];
        $lasts = [];

        foreach ($sources as $source) {
            try {
                [$first, $last] = Config::$db->date_boundaries($source, $this->profile);
            } catch (\Throwable) {
                // A source configured but never imported has no database yet.
                $first = 0;
                $last = 0;
            }

            $perSource[] = [
                'source' => $source,
                'first' => $first,
                'last' => $last,
                'last_update' => $this->withLastUpdate ? Config::$db->last_update($source, 0, $this->profile) : 0,
            ];

            if ($first > 0) {
                $firsts[] = $first;
            }
            if ($last > 0) {
                $lasts[] = $last;
            }
        }

        return [
            'sources' => $perSource,
            'first' => $firsts === [] ? 0 : min($firsts),
            'last' => $lasts === [] ? 0 : max($lasts),
        ];
    }

    /**
     * The lower bound a UI should offer when nothing has been imported: as far back as the
     * configured retention would reach.
     */
    public static function fallbackFirst(): int {
        return time() - Config::$settings->importYears * 365 * 86400;
    }
}
