<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowGraphActions;

describe('FlowGraphActions::effectiveFilter()', function (): void {
    test('passes a plain filter through', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto tcp and dst port 443', '', ''))
            ->toBe('proto tcp and dst port 443')
        ;
    });

    // The table prepends its byte thresholds to the query it runs, so the graph has to plot
    // the same expression or it draws more traffic than the table lists.
    test('includes the byte thresholds the table applies', function (): void {
        $filter = FlowGraphActions::effectiveFilter('dst port 22', '1M', '');

        expect($filter)->toContain('dst port 22')
            ->and($filter)->toContain('bytes > 1M')
            ->and($filter)->toContain('and')
        ;
    });

    test('uses the thresholds alone when there is no filter text', function (): void {
        $filter = FlowGraphActions::effectiveFilter('', '1M', '100M');

        expect($filter)->toContain('bytes > 1M')
            ->and($filter)->toContain('bytes < 100M')
            ->and($filter)->not->toStartWith('and')
        ;
    });

    test('ignores a malformed threshold rather than passing it to nfdump', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto udp', 'not-a-size', ''))->toBe('proto udp');
    });

    test('trims stray whitespace from the filter box', function (): void {
        expect(FlowGraphActions::effectiveFilter('   host 10.0.0.1   ', '', ''))->toBe('host 10.0.0.1');
    });
});
