<?php

/**
 * Tests for the aggregation string building logic used in the flow-actions handler
 * (now inlined in backend/app.php — the old FlowActionsHandler class was removed).
 */

declare(strict_types=1);

/**
 * Replicates the aggregation-string builder from app.php's flow-actions closure.
 * Kept here so the logic can be unit-tested independently.
 *
 * @param array<string, mixed> $aggregation
 */
function buildAggregationString(array $aggregation): string {
    if (!empty($aggregation['bidirectional'])) {
        return 'bidirectional';
    }

    $parts = [];
    foreach (['proto', 'srcport', 'dstport'] as $k) {
        if (!empty($aggregation[$k])) {
            $parts[] = $k;
        }
    }
    foreach (['srcip', 'dstip'] as $k) {
        if (!empty($aggregation[$k]) && $aggregation[$k] !== 'none') {
            $v = $aggregation[$k];
            if (\in_array($v, ['srcip4', 'srcip6', 'dstip4', 'dstip6'], true) && !empty($aggregation[$k . 'Prefix'])) {
                $parts[] = $v . '/' . $aggregation[$k . 'Prefix'];
            } else {
                $parts[] = $v;
            }
        }
    }

    return implode(',', $parts);
}

describe('Aggregation string building', function () {
    test('returns empty string for empty aggregation', function () {
        expect(buildAggregationString([]))->toBe('');
    });

    test('bidirectional aggregation returns correct string', function () {
        expect(buildAggregationString(['bidirectional' => true]))->toBe('bidirectional');
    });

    test('protocol aggregation adds proto', function () {
        expect(buildAggregationString(['proto' => true]))->toContain('proto');
    });

    test('source and destination ports build correctly', function () {
        expect(buildAggregationString(['srcport' => true, 'dstport' => true]))->toBe('srcport,dstport');
    });

    test('IP with prefix builds correctly', function () {
        $result = buildAggregationString(['srcip' => 'srcip4', 'srcipPrefix' => '24']);
        expect($result)->toBe('srcip4/24');
    });

    test('plain IP without prefix builds correctly', function () {
        expect(buildAggregationString(['srcip' => 'srcip']))->toBe('srcip');
    });

    test('combined aggregation builds comma-separated string', function () {
        $result = buildAggregationString(['proto' => true, 'srcport' => true, 'srcip' => 'srcip']);
        expect($result)->toBe('proto,srcport,srcip');
    });

    test('none srcip is excluded', function () {
        expect(buildAggregationString(['srcip' => 'none', 'proto' => true]))->toBe('proto');
    });
});
