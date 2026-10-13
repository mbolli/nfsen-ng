<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/** The range presets, in menu order: the controls bar, the default range in Settings and its normalizer share them. */
final class RangePresets {
    /** Width in seconds. */
    public const array SECONDS = [
        '30m' => 1800,
        '1h' => 3600,
        '3h' => 10800,
        '6h' => 21600,
        '12h' => 43200,
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
        '1y' => 31536000,
    ];

    public const array LABELS = [
        '30m' => 'Last 30 minutes',
        '1h' => 'Last 1 hour',
        '3h' => 'Last 3 hours',
        '6h' => 'Last 6 hours',
        '12h' => 'Last 12 hours',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '1y' => 'Last year',
    ];
}
