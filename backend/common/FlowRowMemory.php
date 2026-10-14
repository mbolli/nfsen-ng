<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\store\Database;

/**
 * The heap one Flows row takes at the peak of its run, learned from the large listings this server ran (#182). A row
 * holds every field of its exporter's template, so the size differs per network; a fixed guess fits none of them.
 */
final class FlowRowMemory {
    public const string META_KEY = 'flows.row_bytes';

    /** The newest samples kept. */
    public const int KEEP = 20;

    /** Below this many samples the caller keeps its own estimate. */
    public const int MIN_SAMPLES = 3;

    /** Head room on the median: other tabs share the worker's heap while a listing runs. */
    public const float MARGIN = 1.2;

    /** @var null|list<int> */
    private static ?array $samples = null;

    /** Records a run of $rows that took the heap $bytes above where it started. */
    public static function record(int $rows, int $bytes): void {
        if ($rows <= 0 || $bytes <= 0) {
            return;
        }
        $samples = [...self::samples(), intdiv($bytes, $rows)];
        self::$samples = \array_slice($samples, -self::KEEP);

        try {
            Database::shared()->metaSet(self::META_KEY, json_encode(self::$samples, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Flows: the row size was not stored: ' . $e->getMessage(), LOG_DEBUG);
        }
    }

    /** Bytes to plan per row, the median with its margin, or null while there are too few samples. */
    public static function perRow(): ?int {
        $samples = self::samples();
        if (\count($samples) < self::MIN_SAMPLES) {
            return null;
        }
        sort($samples);
        $n = \count($samples);
        $median = $n % 2 === 1 ? $samples[intdiv($n, 2)] : intdiv($samples[$n / 2 - 1] + $samples[$n / 2], 2);

        return (int) ceil($median * self::MARGIN);
    }

    /** @return list<int> */
    public static function samples(): array {
        if (self::$samples === null) {
            try {
                $stored = json_decode(Database::shared()->metaGet(self::META_KEY) ?? '[]', true);
            } catch (\Throwable) {
                $stored = [];
            }
            self::$samples = \is_array($stored) ? array_values(array_filter(array_map('intval', $stored), static fn (int $v): bool => $v > 0)) : [];
        }

        return self::$samples;
    }

    /** Forgets the cached samples (tests). */
    public static function reset(): void {
        self::$samples = null;
    }
}
