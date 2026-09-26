<?php

/**
 * Tests for the aggregation string building logic in Nfdump::buildAggregationString(), and
 * for how the query actions store their results and failures in the page states.
 */

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\QueryRunner;
use mbolli\nfsen_ng\actions\ShellActions;
use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\actions\UtilityActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** @param list<array<string, mixed>> $rows */
function flowActionsTestResult(array $rows = [], string $stderr = ''): QueryResult {
    return new QueryResult(
        rows: $rows,
        command: 'nfdump -M /data/live/gw1 -R 2026/09/25/nfcapd.202609250000:nfcapd.202609251200 -o json -c 20',
        stderr: $stderr,
        elapsed: 0.2,
        window: TimeWindow::raw(1_000, 2_000),
    );
}

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

describe('storing a flows run', function (): void {
    test('a finished run stores the table, the row count and the command', function (): void {
        $state = new FlowsState();
        $state->notify('warning', 'nfdump process (PID 3) was killed.');
        FlowActions::storeResult($state, flowActionsTestResult([['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => 42]]), 0.123, '/_action/ip-info-x');

        expect($state->tableHtml)->toContain('id="flowTable"', '10.0.0.1')
            ->and($state->count)->toBe(1)
            ->and($state->resultId)->toMatch('/^[0-9a-f]{8}$/')
            ->and($state->notifications)->toHaveCount(1)
            ->and($state->notifications[0]['message'])->toBe('nfdump: done in 0.123s.')
            ->and($state->notifications[0]['code'])->toStartWith('nfdump -M /data/live/gw1')
        ;
    });

    test('a failed run clears the table and shows nfdump\'s message as text with the command apart', function (): void {
        $state = new FlowsState();
        $state->setResult('<table id="flowTable"></table>', 5);
        FlowActions::storeFailure($state, new NfdumpException('Unknown protocol: <b>x</b>', 'nfdump -- proto <b>x</b>', exitCode: 254), false);

        expect($state->tableHtml)->toBe('')
            ->and($state->count)->toBe(0)
            ->and($state->notifications)->toBe([[
                'id' => $state->notifications[0]['id'],
                'type' => 'error',
                'message' => 'Error: Unknown protocol: <b>x</b>',
                'code' => 'nfdump -- proto <b>x</b>',
            ]])
        ;
    });

    test('a cancelled run keeps the Kill notice instead of a red error', function (): void {
        $state = new FlowsState();
        $state->setResult('<table id="flowTable"></table>', 5);
        $state->notify('warning', 'nfdump process (PID 3) was killed.');
        $stopped = new NfdumpException('nfdump was stopped (signal 15)', 'nfdump -M /data', exitCode: 15);

        FlowActions::storeFailure($state, $stopped, QueryRunner::wasCancelled($stopped, false));

        expect($state->tableHtml)->toBe('')
            ->and(array_column($state->notifications, 'message'))->toBe(['nfdump process (PID 3) was killed.'])
        ;
    });

    test('a statistics run keeps the clamp notice on top of the command', function (): void {
        $state = new TalkersState();
        StatsActions::storeResult($state, flowActionsTestResult(stderr: 'Skipped 1 file'), 0.5, '', 'Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).');

        expect(array_column($state->notifications, 'type'))->toBe(['warning', 'success', 'warning'])
            ->and($state->notifications[0]['message'])->toStartWith('Time window clamped')
            ->and($state->notifications[2]['code'])->toBe('Skipped 1 file')
            ->and($state->tableHtml)->toContain('id="statsTable"')
        ;

        StatsActions::storeFailure($state, new RuntimeException('nfdump was stopped (signal 15)'), true);
        expect($state->tableHtml)->toBe('')
            ->and($state->notifications)->toHaveCount(3)
        ;
    });
});

describe('notices across pages', function (): void {
    beforeEach(function (): void {
        $settings = new ReflectionProperty(Config::class, 'settings');
        $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
        Config::$settings = Settings::fromArray(['frontend' => ['defaults' => ['view' => 'sankey']]]);
        $this->c = new Context('ctx-notices', '/', new Via(new ViaConfig()));
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    test('a Kill notice goes to the page whose query kind was running, else to the active page', function (): void {
        $kind = $this->c->signal('flows', 'query_kind');
        $page = $this->c->signal('talkers', 'page');

        expect(UtilityActions::killNoticePage($this->c))->toBe('flows');

        $kind->setValue('graph');
        expect(UtilityActions::killNoticePage($this->c))->toBe('overview');

        $kind->setValue('');
        expect(UtilityActions::killNoticePage($this->c))->toBe('talkers');

        $page->setValue('nonsense');
        expect(UtilityActions::killNoticePage($this->c))->toBe('conversations');
    });

    test('the old layout shows no Overview notices, so a Kill there lands in Flows and Top Talkers', function (): void {
        $kind = $this->c->signal('graph', 'query_kind');
        $this->c->signal('overview', 'page');

        expect(UtilityActions::killNoticePages($this->c))->toBe(PageRegistry::lazy() ? ['overview'] : ['flows', 'talkers']);

        $kind->setValue('stats');
        expect(UtilityActions::killNoticePages($this->c))->toBe(['talkers']);

        $kind->setValue('conversations');
        expect(UtilityActions::killNoticePages($this->c))->toBe(['conversations']);
    });

    test('dismiss-notification removes the id from the named page, or from every page', function (): void {
        $states = new PageStates();
        $states->flows->notify('info', 'flows');
        $states->talkers->notify('info', 'talkers');
        $flowsId = $states->flows->notifications[0]['id'];

        ShellActions::dismiss($states, 'talkers', $flowsId);
        expect($states->flows->notifications)->toHaveCount(1);

        ShellActions::dismiss($states, 'flows', $flowsId);
        expect($states->flows->notifications)->toBe([]);

        ShellActions::dismiss($states, '', $states->talkers->notifications[0]['id']);
        expect($states->talkers->notifications)->toBe([]);
    });

    test('navigate resets an unknown page to the default one and keeps a known one', function (): void {
        $page = $this->c->signal('flows', 'page');
        ShellActions::navigate($this->c);
        expect($page->string())->toBe('flows');

        $page->setValue('graphs');
        ShellActions::navigate($this->c);
        expect($page->string())->toBe('conversations');
    });
});
