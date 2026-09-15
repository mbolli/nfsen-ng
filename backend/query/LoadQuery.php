<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

use mbolli\nfsen_ng\common\Config;

/**
 * Current traffic against its recent average, per source.
 *
 * The question "is this still happening, and how far above normal" answered from the
 * datasource alone. The alert engine already compares these two numbers; this exposes the
 * same comparison to anything else that needs it.
 */
final readonly class LoadQuery {
    public const DEFAULT_WINDOW_SECONDS = 3600;

    /**
     * @param list<string> $sources
     */
    public function __construct(
        public array $sources,
        public string $profile = '',
        public int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {}

    /**
     * @return array{
     *     latest: array{flows: float, packets: float, bytes: float},
     *     average: array{flows: float, packets: float, bytes: float},
     *     ratio: array{flows: float, packets: float, bytes: float},
     *     window_seconds: int,
     * }
     */
    public function run(): array {
        $sources = $this->sources ?: Config::$settings->sources;

        $latest = Config::$db->fetchLatestSlot($sources, $this->profile);
        $average = Config::$db->fetchRollingAverage($sources, $this->profile, $this->windowSeconds);

        return [
            'latest' => $latest,
            'average' => $average,
            'ratio' => [
                'flows' => self::ratio($latest['flows'] ?? 0.0, $average['flows'] ?? 0.0),
                'packets' => self::ratio($latest['packets'] ?? 0.0, $average['packets'] ?? 0.0),
                'bytes' => self::ratio($latest['bytes'] ?? 0.0, $average['bytes'] ?? 0.0),
            ],
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * How many times the average the current slot is. A zero average has no meaningful
     * multiple, so it reports 0.0 rather than infinity.
     */
    private static function ratio(float $latest, float $average): float {
        if ($average <= 0.0) {
            return 0.0;
        }

        return round($latest / $average, 3);
    }
}
