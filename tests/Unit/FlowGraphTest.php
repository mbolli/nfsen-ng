<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowGraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

describe('FlowGraphActions::effectiveFilter()', function (): void {
    test('passes a plain filter through', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto tcp and dst port 443', '', ''))
            ->toBe('proto tcp and dst port 443')
        ;
    });

    // The table prepends its byte thresholds to the query it runs, so the graph has to plot
    // the same expression or it draws more traffic than the table lists.
    test('includes the byte thresholds the table applies', function (): void {
        expect(FlowGraphActions::effectiveFilter('dst port 22', '1M', ''))->toBe('(bytes > 1M) and (dst port 22)');
    });

    test('uses the thresholds alone when there is no filter text', function (): void {
        expect(FlowGraphActions::effectiveFilter('', '1M', '100M'))->toBe('bytes > 1M and bytes < 100M');
    });

    test('ignores a malformed threshold rather than passing it to nfdump', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto udp', 'not-a-size', ''))->toBe('proto udp');
    });

    test('trims stray whitespace from the filter box', function (): void {
        expect(FlowGraphActions::effectiveFilter('   host 10.0.0.1   ', '', ''))->toBe('host 10.0.0.1');
    });

    // `bytes > 1M and dst port 80 or dst port 443` plotted every flow to 443, whatever its size.
    test('keeps an or in the filter from escaping the threshold', function (): void {
        expect(FlowGraphActions::effectiveFilter('dst port 80 or dst port 443', '1M', ''))
            ->toBe('(bytes > 1M) and (dst port 80 or dst port 443)')
        ;
    });

    test('adds the global protocol', function (): void {
        expect(FlowGraphActions::effectiveFilter('port 53', '', '', 'udp'))->toBe('(proto udp) and (port 53)')
            ->and(FlowGraphActions::effectiveFilter('', '', '', 'icmp'))->toBe('proto icmp or proto icmp6')
        ;
    });

    // The value comes from a client-writable signal and this runs on every render.
    test('reads an unknown protocol as any instead of failing', function (): void {
        expect(FlowGraphActions::effectiveFilter('port 53', '', '', 'bogus'))->toBe('port 53');
    });

    // params() catches this on render and the build refuses the filter.
    test('refuses a filter whose parenthesis would close the wrapper', function (): void {
        expect(fn () => FlowGraphActions::effectiveFilter('port 53) or (port 80', '1M', '', 'udp'))
            ->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.')
        ;
    });

    test('plots exactly the filter the flow table runs', function (): void {
        Config::$settings = Settings::fromArray(mockSettings());

        foreach (['any', 'tcp', 'other'] as $protocol) {
            $query = new FlowsQuery(
                window: TimeWindow::raw(0, 300),
                sources: ['gateway'],
                profile: 'live',
                limit: 10,
                filter: 'dst port 80 or dst port 443',
                lowerLimit: '1k',
                upperLimit: '1G',
                protocol: $protocol,
            );

            expect(FlowGraphActions::effectiveFilter('dst port 80 or dst port 443', '1k', '1G', $protocol))
                ->toBe($query->effectiveFilter())
            ;
        }
    });
});

describe('FlowGraphActions::cost()', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        Config::$settings = Settings::fromArray(mockSettings());
        $c = new Context('ctx-flow-graph-cost', '/', new Via(new ViaConfig()));
        $c->signal(1_700_000_000, 'datestart');
        $c->signal(1_700_086_400, 'dateend');
        $c->signal('live', 'selected_profile');
        $c->signal('', 'flows_filter');
        $c->signal('', 'flows_lower_limit');
        $c->signal('', 'flows_upper_limit');
        $c->signal(['gateway'], 'graph_sources');
        $c->signal('bytes', 'flows_graph_unit');
        $this->c = $c;
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    // A render must not walk the capture tree: the files and bytes are the Flows estimate's.
    test('reads the files and bytes from the Flows estimate, the runs from arithmetic', function (): void {
        $this->c->signal([...QueryKit::ESTIMATE_DEFAULT, 'pending' => false, 'files' => 288, 'bytes' => 3_000_000, 'bytesHuman' => '2.9 MiB', 'window' => '1 day'], QueryKit::estimateSignal('flows'));

        expect(FlowGraphActions::cost($this->c))->toMatchArray([
            'files' => 288,
            'bytes' => '2.9 MiB',
            'estimated' => true,
            'clamped' => false,
        ])
            ->and(FlowGraphActions::cost($this->c)['intervals'])->toBeGreaterThan(0)
        ;
    });

    test('says nothing about files before the estimate has answered', function (): void {
        $this->c->signal(QueryKit::ESTIMATE_DEFAULT, QueryKit::estimateSignal('flows'));

        expect(FlowGraphActions::cost($this->c))->toMatchArray(['files' => 0, 'bytes' => '', 'estimated' => false]);
    });
});
