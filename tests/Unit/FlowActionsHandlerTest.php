<?php

/**
 * Tests for the aggregation string building logic in Nfdump::buildAggregationString().
 */

declare(strict_types=1);

use mbolli\nfsen_ng\processor\Nfdump;

describe('Aggregation string building', function (): void {
    test('returns empty string for empty aggregation', function (): void {
        expect(Nfdump::buildAggregationString([]))->toBe('');
    });

    test('bidirectional aggregation returns correct string', function (): void {
        expect(Nfdump::buildAggregationString(['bidirectional' => true]))->toBe('bidirectional');
    });

    test('protocol aggregation adds proto', function (): void {
        expect(Nfdump::buildAggregationString(['proto' => true]))->toContain('proto');
    });

    test('source and destination ports build correctly', function (): void {
        expect(Nfdump::buildAggregationString(['srcport' => true, 'dstport' => true]))->toBe('srcport,dstport');
    });

    test('IP with prefix builds correctly', function (): void {
        $result = Nfdump::buildAggregationString(['srcip' => 'srcip4', 'srcipPrefix' => '24']);
        expect($result)->toBe('srcip4/24');
    });

    test('plain IP without prefix builds correctly', function (): void {
        expect(Nfdump::buildAggregationString(['srcip' => 'srcip']))->toBe('srcip');
    });

    test('combined aggregation builds comma-separated string', function (): void {
        $result = Nfdump::buildAggregationString(['proto' => true, 'srcport' => true, 'srcip' => 'srcip']);
        expect($result)->toBe('proto,srcport,srcip');
    });

    test('none srcip is excluded', function (): void {
        expect(Nfdump::buildAggregationString(['srcip' => 'none', 'proto' => true]))->toBe('proto');
    });
});

describe('Threshold filter building', function (): void {
    test('returns empty string for empty inputs', function (): void {
        expect(Nfdump::buildThresholdFilter('', ''))->toBe('');
    });

    test('lower limit only', function (): void {
        expect(Nfdump::buildThresholdFilter('1024', ''))->toBe('bytes > 1024');
    });

    test('upper limit only', function (): void {
        expect(Nfdump::buildThresholdFilter('', '1M'))->toBe('bytes < 1M');
    });

    test('both limits joined with and', function (): void {
        expect(Nfdump::buildThresholdFilter('100', '9999'))->toBe('bytes > 100 and bytes < 9999');
    });

    test('invalid limit is ignored', function (): void {
        expect(Nfdump::buildThresholdFilter('abc', '500'))->toBe('bytes < 500');
    });
});
