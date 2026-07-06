<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\SankeyActions;

describe('SankeyActions::buildSankeyPayload', function (): void {
    test('builds nodes and links from valid rows using bytes by default', function (): void {
        $rows = [
            ['sa' => '172.24.154.108', 'da' => '149.126.4.47', 'ibyt' => '1658542255', 'ipkt' => '114225', 'fl' => '1290'],
            ['sa' => '3.165.190.89', 'da' => '172.24.154.108', 'ibyt' => '1058946029', 'ipkt' => '33241', 'fl' => '2'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes');

        expect($result['nodes'])->toHaveCount(4);
        expect($result['links'])->toHaveCount(2);
        expect($result['links'][0])->toBe([
            'source' => 'src:172.24.154.108',
            'target' => 'dst:149.126.4.47',
            'value' => 1658542255,
            'flows' => 1290,
        ]);
    });

    test('uses the packets field when metric is packets', function (): void {
        $rows = [
            ['sa' => '1.2.3.4', 'da' => '5.6.7.8', 'ibyt' => '1000', 'ipkt' => '42', 'fl' => '3'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'packets');

        expect($result['links'][0]['value'])->toBe(42);
    });

    test('drops self-loop rows where source equals destination', function (): void {
        $rows = [
            ['sa' => '10.0.0.1', 'da' => '10.0.0.1', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
            ['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes');

        expect($result['links'])->toHaveCount(1);
        expect($result['links'][0]['target'])->toBe('dst:10.0.0.2');
    });

    test('skips rows with empty source or destination', function (): void {
        $rows = [
            ['sa' => '', 'da' => '10.0.0.2', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
            ['sa' => '10.0.0.1', 'da' => '', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'bytes')['links'])->toBe([]);
    });

    test('gives an IP appearing as both source and destination two distinct nodes', function (): void {
        $rows = [
            ['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
            ['sa' => '10.0.0.3', 'da' => '10.0.0.1', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes');

        $nodeNames = array_column($result['nodes'], 'name');
        expect($nodeNames)->toContain('src:10.0.0.1');
        expect($nodeNames)->toContain('dst:10.0.0.1');
        expect($result['nodes'])->toHaveCount(4);
    });

    test('deduplicates repeated nodes across multiple links', function (): void {
        $rows = [
            ['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
            ['sa' => '10.0.0.1', 'da' => '10.0.0.3', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes');

        expect($result['nodes'])->toHaveCount(3);
        expect($result['links'])->toHaveCount(2);
    });

    test('returns empty nodes and links for empty input', function (): void {
        expect(SankeyActions::buildSankeyPayload([], 'bytes'))->toBe(['nodes' => [], 'links' => []]);
    });
});
