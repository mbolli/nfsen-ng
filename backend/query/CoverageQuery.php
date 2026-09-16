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
                'last_update' => Config::$db->last_update($source, 0, $this->profile),
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
