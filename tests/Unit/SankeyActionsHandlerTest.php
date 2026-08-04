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

describe('SankeyActions::buildSankeyPayload (ports mode)', function (): void {
    test('builds a three-column src -> port -> dst payload', function (): void {
        $rows = [
            ['sa' => '1.1.1.1', 'da' => '2.2.2.2', 'dp' => '443', 'ibyt' => '1000', 'ipkt' => '10', 'fl' => '5'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes', true);

        expect(array_column($result['nodes'], 'name'))->toBe(['src:1.1.1.1', 'port:443', 'dst:2.2.2.2']);
        expect($result['links'])->toBe([
            ['source' => 'src:1.1.1.1', 'target' => 'port:443', 'value' => 1000, 'flows' => 5],
            ['source' => 'port:443', 'target' => 'dst:2.2.2.2', 'value' => 1000, 'flows' => 5],
        ]);
    });

    test('aggregates the shared src -> port link across multiple destinations', function (): void {
        $rows = [
            ['sa' => '1.1.1.1', 'da' => '2.2.2.2', 'dp' => '443', 'ibyt' => '100', 'ipkt' => '1', 'fl' => '1'],
            ['sa' => '1.1.1.1', 'da' => '3.3.3.3', 'dp' => '443', 'ibyt' => '200', 'ipkt' => '2', 'fl' => '2'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes', true);

        // src:1.1.1.1, port:443, dst:2.2.2.2, dst:3.3.3.3
        expect($result['nodes'])->toHaveCount(4);
        // src -> port summed (300/3), plus one distinct port -> dst per destination
        expect($result['links'])->toBe([
            ['source' => 'src:1.1.1.1', 'target' => 'port:443', 'value' => 300, 'flows' => 3],
            ['source' => 'port:443', 'target' => 'dst:2.2.2.2', 'value' => 100, 'flows' => 1],
            ['source' => 'port:443', 'target' => 'dst:3.3.3.3', 'value' => 200, 'flows' => 2],
        ]);
    });

    test('aggregates the shared port -> dst link across multiple sources', function (): void {
        $rows = [
            ['sa' => '1.1.1.1', 'da' => '9.9.9.9', 'dp' => '53', 'ibyt' => '100', 'ipkt' => '1', 'fl' => '1'],
            ['sa' => '2.2.2.2', 'da' => '9.9.9.9', 'dp' => '53', 'ibyt' => '400', 'ipkt' => '4', 'fl' => '3'],
        ];

        $links = SankeyActions::buildSankeyPayload($rows, 'bytes', true)['links'];
        $portToDst = array_values(array_filter($links, fn ($l) => $l['source'] === 'port:53' && $l['target'] === 'dst:9.9.9.9'));

        expect($portToDst)->toHaveCount(1);
        expect($portToDst[0]['value'])->toBe(500);
        expect($portToDst[0]['flows'])->toBe(4);
    });

    test('uses the packets field when metric is packets', function (): void {
        $rows = [
            ['sa' => '1.1.1.1', 'da' => '2.2.2.2', 'dp' => '80', 'ibyt' => '1000', 'ipkt' => '42', 'fl' => '3'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'packets', true);

        expect($result['links'][0]['value'])->toBe(42);
    });

    test('drops rows missing a destination port', function (): void {
        $rows = [
            ['sa' => '1.1.1.1', 'da' => '2.2.2.2', 'dp' => '', 'ibyt' => '100', 'ipkt' => '1', 'fl' => '1'],
            ['sa' => '1.1.1.1', 'da' => '2.2.2.2', 'ibyt' => '100', 'ipkt' => '1', 'fl' => '1'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'bytes', true))->toBe(['nodes' => [], 'links' => []]);
    });

    test('drops self-loop rows where source equals destination', function (): void {
        $rows = [
            ['sa' => '10.0.0.1', 'da' => '10.0.0.1', 'dp' => '443', 'ibyt' => '500', 'ipkt' => '5', 'fl' => '1'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'bytes', true))->toBe(['nodes' => [], 'links' => []]);
    });
});

/*
 * On nfdump 1.7.5 the Sankey falls back to `-o csv`, whose aggregated output names the same
 * fields differently (srcAddr/dstAddr/dstPort/bytes/packets/flows). Verbatim headers:
 *   firstSeen,duration,srcAddr,dstPort,dstAddr,packets,bytes,bps,bpp,flows
 *   firstSeen,duration,srcAddr,dstAddr,packets,bytes,bps,bpp,flows
 * See #159.
 */
describe('SankeyActions::buildSankeyPayload (aggregated -o csv column names)', function (): void {
    test('reads csv column names as aliases of the fmt: tokens', function (): void {
        $rows = [
            ['firstSeen' => '2025-12-04 15:39:13.500', 'duration' => '36.860', 'srcAddr' => '143.204.55.29', 'dstAddr' => '172.24.154.108', 'packets' => '747', 'bytes' => '17836325', 'bps' => '3871150', 'bpp' => '23877', 'flows' => '20'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes');

        expect($result['links'])->toBe([
            ['source' => 'src:143.204.55.29', 'target' => 'dst:172.24.154.108', 'value' => 17836325, 'flows' => 20],
        ]);
    });

    test('uses the packets column when metric is packets', function (): void {
        $rows = [
            ['srcAddr' => '1.2.3.4', 'dstAddr' => '5.6.7.8', 'packets' => '747', 'bytes' => '17836325', 'flows' => '20'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'packets')['links'][0]['value'])->toBe(747);
    });

    test('reads the dstPort column in ports mode', function (): void {
        $rows = [
            ['srcAddr' => '143.204.55.29', 'dstPort' => '39930', 'dstAddr' => '172.24.154.108', 'packets' => '480', 'bytes' => '15634462', 'flows' => '1'],
        ];

        $result = SankeyActions::buildSankeyPayload($rows, 'bytes', true);

        expect(array_column($result['nodes'], 'name'))->toBe(['src:143.204.55.29', 'port:39930', 'dst:172.24.154.108']);
        expect($result['links'])->toBe([
            ['source' => 'src:143.204.55.29', 'target' => 'port:39930', 'value' => 15634462, 'flows' => 1],
            ['source' => 'port:39930', 'target' => 'dst:172.24.154.108', 'value' => 15634462, 'flows' => 1],
        ]);
    });

    test('applies the same self-loop and missing-field rules to csv rows', function (): void {
        $rows = [
            ['srcAddr' => '10.0.0.1', 'dstAddr' => '10.0.0.1', 'bytes' => '500', 'packets' => '5', 'flows' => '1'],
            ['srcAddr' => '', 'dstAddr' => '10.0.0.2', 'bytes' => '500', 'packets' => '5', 'flows' => '1'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'bytes'))->toBe(['nodes' => [], 'links' => []]);
    });

    test('defaults value and flows to 0 when neither name is present', function (): void {
        $rows = [
            ['srcAddr' => '1.2.3.4', 'dstAddr' => '5.6.7.8'],
        ];

        expect(SankeyActions::buildSankeyPayload($rows, 'bytes')['links'][0])->toBe([
            'source' => 'src:1.2.3.4', 'target' => 'dst:5.6.7.8', 'value' => 0, 'flows' => 0,
        ]);
    });
});
