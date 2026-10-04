<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\pages\TalkersPage;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatisticCatalog;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** @return array<string, mixed> StatsParams with the given changes */
function talkersPageTestParams(array $overrides = []): array {
    return [...[
        'element' => 'srcip', 'order' => 'bytes', 'count' => 10, 'filter' => '', 'lower' => '', 'upper' => '',
        'aggregation' => [], 'sources' => ['gw'], 'profile' => 'live', 'protocol' => 'any',
        'start' => 86_400, 'end' => 172_800, 'live' => false,
    ], ...$overrides];
}

function talkersPageTestResult(int $rows = 2, string $command = 'nfdump -M /data -s srcip/bytes', string $stderr = ''): QueryResult {
    return new QueryResult(
        rows: array_fill(0, $rows, ['srcip' => '10.0.0.1', 'bytes' => 1000]),
        command: $command,
        stderr: $stderr,
        elapsed: 0.2,
        window: TimeWindow::raw(86_400, 172_800),
        notes: ['No matching flows', 'Execution time: 0.2 seconds'],
    );
}

/** The page handler against the real templates, in the fatal state so no datasource is read. */
function talkersPageTestCompose(): array {
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-talkers', '/', $app);
    $states = new PageStates();

    Shell::signals($c);
    foreach (PageRegistry::MODULES as $module) {
        $module::signals($c);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::signals($c);
    }
    Shell::register($c, $app, $states);
    foreach (PageRegistry::MODULES as $module) {
        $module::register($c, $app, $states);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::register($c, $app, $states);
    }
    $c->getSignal('page')?->setValue('talkers');

    return [$app, $c, $states];
}

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $prefs = new ReflectionProperty(Config::class, 'prefsFile');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [80], 'max_stats_window' => 0],
        'nfdump' => [
            'binary' => dirname(__DIR__) . '/Support/bin/nfdump-no-nel',
            'profiles-data' => sys_get_temp_dir() . '/nfsen-talkers-test-missing',
            'profile' => 'live',
        ],
        'frontend' => ['defaults' => ['view' => 'statistics']],
    ]);
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-talkers-test-missing.json';
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->prefsBefore !== null) {
        Config::$prefsFile = $this->prefsBefore;
    }
});

describe('inputs and fingerprints', function (): void {
    test('params() reads the page and the global controls, normalised', function (): void {
        $c = new Context('ctx-params', '/', new Via(new ViaConfig()));
        TalkersPage::signals($c);
        $c->signal(1_000, 'datestart');
        $c->signal(4_600, 'dateend');
        $c->signal(true, 'range_live');
        $c->signal(['gw2', 'any'], 'graph_sources');
        $c->signal('live', 'selected_profile');
        $c->signal('TCP ', 'protocol');
        $c->getSignal('stats_count')?->setValue('20');
        $c->getSignal('stats_for')?->setValue(' dstport ');
        $c->getSignal('stats_filter')?->setValue("  port 53\n");
        $c->getSignal('stats_agg_srcip_prefix')?->setValue(' 24 ');

        $p = StatsActions::params($c);

        expect($p['element'])->toBe('dstport')
            ->and($p['count'])->toBe(20)
            ->and($p['filter'])->toBe('port 53')
            ->and($p['sources'])->toBe(['gw'])
            ->and($p['protocol'])->toBe('tcp')
            ->and($p['live'])->toBeTrue()
            ->and([$p['start'], $p['end']])->toBe([1_000, 4_600])
            ->and($p['aggregation']['srcipPrefix'])->toBe('24')
            ->and($p['aggregation']['bidirectional'])->toBeFalse()
        ;

        $c->getSignal('stats_count')?->setValue(7);
        expect(StatsActions::params($c)['count'])->toBe(10);
    });

    test('a live window is keyed by its width, a fixed one by its 5 minute slots', function (): void {
        expect(StatsActions::windowKey(1_000, 4_600, true))->toBe('live:3600')
            ->and(StatsActions::windowKey(1_000, 4_600, false))->toBe('900-4500')
            ->and(StatsActions::windowKey(1_199, 4_799, false))->toBe('900-4500')
        ;
    });

    test('the fingerprint covers every input, the scope only the global controls (4.2.3)', function (): void {
        $base = talkersPageTestParams();
        $print = StatsActions::fingerprint($base);
        $scope = StatsActions::scope($base);

        foreach ([
            ['filter' => 'port 53'], ['lower' => '1M'], ['upper' => '1G'], ['count' => 20], ['order' => 'flows'],
            ['element' => 'dstip'], ['sources' => ['gw', 'gw2']], ['profile' => 'test'], ['protocol' => 'udp'],
            ['start' => 90_000], ['live' => true],
        ] as $change) {
            expect(StatsActions::fingerprint([...$base, ...$change]))->not->toBe($print, json_encode($change));
        }
        foreach ([['filter' => 'port 53'], ['count' => 20], ['order' => 'flows'], ['element' => 'dstip']] as $change) {
            expect(StatsActions::scope([...$base, ...$change]))->toBe($scope);
        }

        // Aggregation counts for Flow Records only, the statistic nfdump applies it to.
        expect(StatsActions::fingerprint([...$base, 'aggregation' => ['proto' => true]]))->toBe($print)
            ->and(StatsActions::fingerprint(talkersPageTestParams(['element' => 'record', 'aggregation' => ['proto' => true]])))
            ->not->toBe(StatsActions::fingerprint(talkersPageTestParams(['element' => 'record'])))
        ;

        // A sliding live window keeps its fingerprint (1.7).
        $live = talkersPageTestParams(['live' => true]);
        expect(StatsActions::fingerprint([...$live, 'start' => 90_000, 'end' => 176_400]))->toBe(StatsActions::fingerprint($live));
    });
});

describe('results per statistic (D12)', function (): void {
    test('a run is stored under its statistic, and another statistic keeps its own', function (): void {
        $state = new TalkersState();
        StatsActions::storeResult($state, talkersPageTestResult(2), 0.5, '/ip', '', talkersPageTestParams());
        StatsActions::storeResult($state, talkersPageTestResult(3, 'nfdump -s dstip/bytes'), 0.4, '/ip', '', talkersPageTestParams(['element' => 'dstip']));

        expect(array_keys($state->results))->toBe(['srcip', 'dstip'])
            ->and($state->lastElement)->toBe('dstip')
            ->and($state->result('srcip')['rows'])->toBe(2)
            ->and($state->result('srcip')['html'])->toContain('id="statsTable"')
            ->and($state->result('dstip')['command'])->toBe('nfdump -s dstip/bytes')
            ->and($state->result('dstip')['notes'])->toBe(['No matching flows', 'Execution time: 0.2 seconds'])
            ->and($state->result('dstip')['fingerprint'])->toBe(StatsActions::fingerprint(talkersPageTestParams(['element' => 'dstip'])))
            ->and($state->resultId)->toBe($state->result('dstip')['id'])
        ;

        $srcip = TalkersPage::view($state, talkersPageTestParams(), [], false, 200_000)['result'];
        $ports = TalkersPage::view($state, talkersPageTestParams(['element' => 'srcport']), [], false, 200_000)['result'];

        expect($srcip['element'])->toBe('srcip')
            ->and($srcip['rows'])->toBe(2)
            ->and($srcip['title'])->toBe('Src IP address, ordered by bytes · 2 rows')
            ->and($srcip['notes'])->toBe(['No matching flows'])
            ->and($ports)->toBeNull()
        ;
    });

    test('the oldest statistic goes once MAX_RESULTS are kept, and a rerun moves to the end', function (): void {
        $state = new TalkersState();
        $elements = array_slice(array_column(StatisticCatalog::all(), 'value'), 0, TalkersState::MAX_RESULTS + 1);
        foreach ($elements as $element) {
            $state->setResult("<table>{$element}</table>", $element);
        }

        expect($state->results)->toHaveCount(TalkersState::MAX_RESULTS)
            ->and($state->result($elements[0]))->toBeNull()
        ;

        $state->setResult('<table>again</table>', $elements[1]);
        expect(array_key_last($state->results))->toBe($elements[1]);
    });

    test('a cancelled rerun keeps the earlier run and says so (4.2.3); a failure clears only its statistic', function (): void {
        $state = new TalkersState();
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', talkersPageTestParams());
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', talkersPageTestParams(['element' => 'dstip']));
        $dstip = $state->result('dstip');
        $state->clearNotifications();
        $state->notify('warning', 'nfdump process (PID 3) was killed.');

        StatsActions::storeFailure($state, new NfdumpException('nfdump was stopped (signal 15)', 'nfdump -M /data', exitCode: 15), true, 'srcip');

        expect($state->result('dstip'))->toBe($dstip)
            ->and($state->result('srcip'))->not->toBeNull()
            ->and($state->lastElement)->toBe('srcip')
            ->and($state->result('srcip')['html'])->toContain('id="statsTable"')
            ->and(array_column($state->notifications, 'message'))->toBe(['Query cancelled.', 'nfdump process (PID 3) was killed.'])
            ->and(array_column($state->notifications, 'type'))->toBe(['info', 'warning'])
        ;

        StatsActions::storeFailure($state, new NfdumpException('Filter syntax error', 'nfdump -M /data'), false, 'dstip');
        expect($state->result('dstip'))->toBeNull()
            ->and($state->result('srcip'))->not->toBeNull()
            ->and($state->notifications[0]['type'])->toBe('error')
        ;

        // A statistic outside the catalog (or none) forgets no run, and its notice shows for any.
        foreach (['bogus', ''] as $element) {
            StatsActions::storeFailure($state, new InvalidArgumentException('Unknown statistic.'), false, $element);
            expect($state->notifications)->toHaveCount(1)
                ->and($state->notifications[0])->toMatchArray(['type' => 'error', 'message' => 'Error: Unknown statistic.'])
                ->and($state->lastElement)->toBe('')
                ->and($state->result('srcip'))->not->toBeNull()
            ;
        }
    });

    test('the clamp and nfdump\'s stderr stay with their result; a run leaves no page notice', function (): void {
        $state = new TalkersState();
        $state->notify('error', 'Error: an earlier failure');
        $clamp = 'Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).';
        StatsActions::storeResult($state, talkersPageTestResult(stderr: 'Skipped a file'), 0.5, '', $clamp, talkersPageTestParams());
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', talkersPageTestParams(['element' => 'dstip']));

        $srcip = TalkersPage::view($state, talkersPageTestParams(), [], false, 200_000);
        $dstip = TalkersPage::view($state, talkersPageTestParams(['element' => 'dstip']), [], false, 200_000);

        expect($state->notifications)->toBe([])
            ->and($srcip['notifications'])->toBe([])
            ->and($srcip['noticesFor'])->toBe('dstip')
            ->and(array_column($srcip['result']['warnings'], 'message'))->toBe([$clamp, 'nfdump warning:'])
            ->and(array_column($srcip['result']['warnings'], 'code'))->toBe(['', 'Skipped a file'])
            ->and(array_column($srcip['result']['warnings'], 'type'))->toBe(['warning', 'warning'])
            ->and($dstip['result']['warnings'])->toBe([])
        ;

        $copy = new TalkersState();
        $copy->restore($state->snapshot());
        expect($copy->result('srcip')['warnings'])->toBe($state->result('srcip')['warnings']);

        // They share the notices' dismiss action.
        $state->dismiss($state->result('srcip')['warnings'][0]['id']);
        expect(array_column($state->result('srcip')['warnings'], 'code'))->toBe(['Skipped a file']);
    });

    test('stale, scope and the live window that moved on (1.7)', function (): void {
        $state = new TalkersState();
        $live = talkersPageTestParams(['live' => true]);
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', $live);
        $ranAt = $state->result('srcip')['ts'];

        $fresh = TalkersPage::view($state, $live, [], false, $ranAt + 60)['result'];
        $slid = TalkersPage::view($state, [...$live, 'start' => 90_000, 'end' => 176_400], [], true, $ranAt + 301)['result'];
        $count = TalkersPage::view($state, [...$live, 'count' => 20], [], true, $ranAt + 60)['result'];
        $sources = TalkersPage::view($state, [...$live, 'sources' => ['gw2']], [], true, $ranAt + 301)['result'];

        expect([$fresh['stale'], $fresh['scopeStale'], $fresh['moved']])->toBe([false, false, false])
            ->and([$slid['stale'], $slid['scopeStale'], $slid['moved']])->toBe([false, false, true])
            ->and([$count['stale'], $count['scopeStale'], $count['moved']])->toBe([true, false, false])
            ->and([$sources['stale'], $sources['scopeStale'], $sources['moved']])->toBe([true, true, false])
            ->and($fresh['inputs'])->toBe(StatsActions::inputs($live))
            ->and($fresh['aggregation'])->toBeNull()
        ;

        // Flow Records compares the -A it ran with: a prefix without an IPv4/IPv6 choice changes nothing.
        $agg = ['bidirectional' => false, 'proto' => true, 'srcport' => false, 'dstport' => false, 'srcip' => 'none', 'srcipPrefix' => '', 'dstip' => 'dstip4', 'dstipPrefix' => '24'];
        $record = talkersPageTestParams(['element' => 'record', 'aggregation' => $agg]);
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', $record);
        $prefix = TalkersPage::view($state, [...$record, 'aggregation' => [...$agg, 'srcipPrefix' => '16']], [], true, 200_000)['result'];
        $dst = TalkersPage::view($state, [...$record, 'aggregation' => [...$agg, 'dstipPrefix' => '16']], [], true, 200_000)['result'];

        expect($prefix['aggregation'])->toBe('proto,dstip4/24')
            ->and($prefix['stale'])->toBeFalse()
            ->and($dst['stale'])->toBeTrue()
        ;
    });

    test('the table is sent once per result id (D26)', function (): void {
        $state = new TalkersState();
        StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', talkersPageTestParams());

        $first = TalkersPage::view($state, talkersPageTestParams(), [], false, 200_000)['result'];
        $state->markRendered(true);
        $second = TalkersPage::view($state, talkersPageTestParams(), [], true, 200_000)['result'];
        $state->markRendered(true);

        expect($first['send'])->toBeTrue()
            ->and($first['html'])->toContain('statsTable')
            ->and($second['send'])->toBeFalse()
            ->and($second['html'])->toBe('')
            ->and($second['id'])->toBe($first['id'])
        ;
    });

    test('a panel over a clamped window says so, and one over the full window does not', function (): void {
        $state = new TalkersState();
        $clamped = new QueryResult(rows: [], command: 'nfdump -s proto/bytes', stderr: '', elapsed: 0.1, window: TimeWindow::clamped(0, 90 * 86_400, 31 * 86_400));
        StatsActions::storePanel($state, 'proto', [['key' => '6', 'proto' => 'TCP', 'flows' => 1, 'packets' => 2, 'bytes' => 3, 'bytesPct' => 100.0]], $clamped, 0.1, talkersPageTestParams());
        StatsActions::storePanel($state, 'as', [], talkersPageTestResult(0), 0.1, talkersPageTestParams());

        $copy = new TalkersState();
        $copy->restore($state->snapshot());

        expect($state->panel('proto')['clamp'] ?? null)->toBe('Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).')
            ->and($state->panel('as')['clamp'] ?? null)->toBe('')
            ->and($copy->panel('proto')['clamp'] ?? null)->toBe($state->panel('proto')['clamp'] ?? null)
        ;
    });

    test('a snapshot keeps the newest runs and both panels, and restores to the same', function (): void {
        $state = new TalkersState();
        foreach (['srcip', 'dstip', 'srcport', 'dstport'] as $element) {
            StatsActions::storeResult($state, talkersPageTestResult(), 0.5, '', '', talkersPageTestParams(['element' => $element]));
        }
        StatsActions::storePanel($state, 'proto', [['key' => '6', 'proto' => 'TCP', 'flows' => 1, 'packets' => 2, 'bytes' => 3, 'bytesPct' => 100.0]], talkersPageTestResult(0), 0.1, talkersPageTestParams());

        $snapshot = $state->snapshot();
        $copy = new TalkersState();
        $copy->restore($snapshot);

        expect(array_keys($snapshot['results']))->toBe(['dstip', 'srcport', 'dstport'])
            ->and($copy->snapshot())->toBe($snapshot)
            ->and($copy->panel('proto')['bars'][0]['series'])->toBe(1)
            ->and((new TalkersState())->snapshot())->toBe([])
        ;

        $copy->restore(['results' => ['x' => ['id' => 3]], 'panels' => ['geo' => ['bars' => []]], 'lastElement' => ['no']]);
        expect($copy->isEmpty())->toBeTrue()
            ->and($copy->lastElement)->toBe('')
        ;
    });
});

describe('the run action', function (): void {
    test('a Run posted while a query of the tab runs keeps the page notices', function (): void {
        [, $c, $states] = talkersPageTestCompose();
        $states->talkers->notify('warning', 'Query cancelled.');
        $c->getSignal('query_running')?->setValue(true);

        $c->executeAction((string) $c->getAction('stats-actions')?->id());

        expect(array_column($states->talkers->notifications, 'message'))->toBe(['Query cancelled.']);
    });
});

describe('side panels', function (): void {
    test('protocol bars take the picker graph\'s slots for TCP, UDP and ICMP, and nothing else (2.3)', function (): void {
        $row = static fn (string $key, string $proto, ?float $pct): array => ['key' => $key, 'proto' => $proto, 'flows' => 1, 'packets' => 1, 'bytes' => 1000, 'bytesPct' => $pct];
        $bars = StatsActions::bars('proto', [$row('6', 'TCP', 60.0), $row('17', 'UDP', 20.0), $row('1', 'ICMP', 10.0), $row('58', 'ICMP6', 5.0), $row('47', 'GRE', 4.0), $row('50', '', 1.0)]);

        expect(array_column($bars, 'series'))->toBe([1, 2, 3, 3, null, null])
            ->and(array_column($bars, 'label'))->toBe(['TCP', 'UDP', 'ICMP', 'ICMP6', 'GRE', 'Protocol 50'])
            ->and(array_column(StatsActions::bars('as', [$row('0', 'any', 200.0), $row('64512', 'any', 38.2)]), 'label'))->toBe(['AS0 (not exported)', 'AS64512'])
            ->and(array_column(StatsActions::bars('as', [$row('0', 'any', 200.0)]), 'series'))->toBe([null])
        ;
    });

    test('a panel shows the share of bytes, an AS half of each flow it is an end of with all its bytes, and its own staleness', function (): void {
        $state = new TalkersState();
        $params = talkersPageTestParams();
        // Both ends of every flow in AS0: nfdump's `-s as` says 200 %.
        StatsActions::storePanel($state, 'as', [
            ['key' => '0', 'proto' => 'any', 'flows' => 2, 'packets' => 2, 'bytes' => 4_096, 'bytesPct' => 200.0],
            ['key' => '64512', 'proto' => 'any', 'flows' => 1, 'packets' => 1, 'bytes' => 2_048, 'bytesPct' => null],
        ], talkersPageTestResult(0), 0.1, $params);

        $view = TalkersPage::view($state, $params, [], false, 200_000);
        [$proto, $as] = $view['panels'];

        expect($proto)->toMatchArray(['id' => 'proto', 'title' => 'Protocol share', 'ran' => false, 'stale' => false, 'bars' => []])
            ->and($as['ran'])->toBeTrue()
            ->and([$as['stale'], $as['scopeStale']])->toBe([false, false])
            ->and(TalkersPage::view($state, [...$params, 'filter' => 'port 53'], [], false, 200_000)['panels'][1]['stale'])->toBeTrue()
            ->and(TalkersPage::view($state, [...$params, 'count' => 50], [], false, 200_000)['panels'][1]['stale'])->toBeFalse()
            ->and(array_column($as['bars'], 'width'))->toBe([100.0, 50.0])
            ->and(array_column($as['bars'], 'value'))->toBe(['100.0%', '2 KiB'])
            ->and(array_column($as['bars'], 'bytes'))->toBe(['4 KiB', '2 KiB'])
            ->and(array_column(StatsActions::bars('proto', [['key' => '6', 'proto' => 'TCP', 'flows' => 1, 'packets' => 1, 'bytes' => 780, 'bytesPct' => 78.0]]), 'share'))->toBe([78.0])
            ->and(TalkersPage::view($state, [...$params, 'protocol' => 'udp'], [], false, 200_000)['panels'][1]['scopeStale'])->toBeTrue()
            ->and(TalkersPage::view($state, [...$params, 'protocol' => 'udp'], [], false, 200_000)['protocolHint'])->toBe('Filtered to UDP, so the share is trivial.')
            ->and(TalkersPage::view($state, [...$params, 'protocol' => 'other'], [], false, 200_000)['protocolHint'])->toBe('Filtered to protocols other than TCP, UDP and ICMP.')
            ->and($view['protocolHint'])->toBe('')
        ;
    });

    test('a failed panel says why; a cancelled one keeps its bars and the results card\'s notices', function (): void {
        $state = new TalkersState();
        $bar = [['key' => '6', 'proto' => 'TCP', 'flows' => 1, 'packets' => 1, 'bytes' => 1, 'bytesPct' => 100.0]];
        StatsActions::storePanel($state, 'proto', $bar, talkersPageTestResult(0), 0.1, talkersPageTestParams());
        $state->notify('warning', 'Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).');
        $before = $state->notifications;
        // What kill-nfdump does to the page notices while the panel runs.
        $state->clearNotifications();
        $state->notify('warning', 'nfdump process (PID 3) was killed.');

        StatsActions::storePanelFailure($state, 'proto', new RuntimeException('stopped'), true, talkersPageTestParams(), $before);
        expect($state->panel('proto')['bars'])->toHaveCount(1)
            ->and($state->notifications)->toBe($before)
        ;
        $state->clearNotifications();

        StatsActions::storePanelFailure($state, 'proto', new NfdumpException('Filter syntax error: <b>x</b>', 'nfdump -s proto/bytes'), false, talkersPageTestParams());
        expect($state->panel('proto'))->toMatchArray(['bars' => [], 'error' => 'Filter syntax error: <b>x</b>', 'command' => 'nfdump -s proto/bytes'])
            ->and($state->notifications)->toBe([])
        ;
    });
});

describe('the picker', function (): void {
    test('the unsupported statistics are probed at most once per PROBE_TTL, per binary', function (): void {
        $calls = 0;
        $probe = static function (string $binary) use (&$calls): array {
            ++$calls;

            return $calls === 1 ? [] : ['nevent' => 'Unknown statistic: nevent'];
        };
        $binary = '/opt/nfdump-' . bin2hex(random_bytes(4));

        expect(TalkersPage::unsupported(1_000, $binary, $probe))->toBe([])
            ->and(TalkersPage::unsupported(1_000 + TalkersPage::PROBE_TTL - 1, $binary, $probe))->toBe([])
            ->and($calls)->toBe(1)
            ->and(TalkersPage::unsupported(1_000 + TalkersPage::PROBE_TTL, $binary, $probe))->toBe(['nevent' => 'Unknown statistic: nevent'])
            ->and($calls)->toBe(2)
            ->and(TalkersPage::unsupported(1_000 + TalkersPage::PROBE_TTL, $binary . '-other', $probe))->toBe(['nevent' => 'Unknown statistic: nevent'])
            ->and($calls)->toBe(3)
        ;
    });

    test('the catalog: five tabs, the More groups in order, NEL disabled with the reason (D23)', function (): void {
        $catalog = TalkersPage::catalog(['nevent' => 'Unknown statistic: nevent']);
        $options = array_merge(...array_column($catalog['groups'], 'options'));

        expect(array_column($catalog['tabs'], 'id'))->toBe(['talkers', 'ports', 'protocols', 'asns', 'interfaces'])
            ->and(array_column($catalog['groups'], 'label'))->toBe(TalkersPage::GROUPS)
            ->and($options)->toHaveCount(58)
            ->and(array_column($options, 'reason', 'value')['nevent'])->toBe('Unknown statistic: nevent')
            ->and(array_column($options, 'reason', 'value')['srcip'])->toBe('')
            ->and($catalog['client']['tabOf'])->toMatchArray(['srcip' => 'talkers', 'proto' => 'protocols', 'outif' => 'interfaces'])
            ->and($catalog['client']['tabOf'])->not->toHaveKey('record')
            ->and($catalog['client']['dirOf'])->toMatchArray(['ip' => 'any', 'dstport' => 'dst', 'srctos' => 'src'])
            ->and($catalog['client']['dirOf'])->not->toHaveKey('proto')
            ->and($catalog['client']['family']['talkers'])->toBe(['any' => 'ip', 'src' => 'srcip', 'dst' => 'dstip'])
            ->and($catalog['client']['family']['protocols'])->toBe(['any' => 'proto', 'src' => 'proto', 'dst' => 'proto'])
            ->and($catalog['client']['family']['dsttos'])->toBe(['any' => 'tos', 'src' => 'srctos', 'dst' => 'dsttos'])
            ->and($catalog['client']['unsupported'])->toBe(['nevent' => 'Unknown statistic: nevent'])
        ;
    });

    test('the view names the tab and the direction of the chosen statistic', function (): void {
        $state = new TalkersState();
        $at = static fn (string $element): array => TalkersPage::view($state, talkersPageTestParams(['element' => $element]), ['nevent' => 'no'], false, 0);

        expect([$at('dstip')['tab'], $at('dstip')['hasDirection']])->toBe(['talkers', true])
            ->and([$at('proto')['tab'], $at('proto')['hasDirection']])->toBe(['protocols', false])
            ->and([$at('srctos')['tab'], $at('srctos')['hasDirection']])->toBe(['more', true])
            ->and([$at('record')['tab'], $at('record')['hasDirection']])->toBe(['more', false])
            ->and($at('nevent')['unsupported'])->toBe('no')
            ->and($at('<script>')['element'])->toBe('record')
            ->and(TalkersPage::resultTitle('proto', 'bps', 1))->toBe('Proto, ordered by bits per second · 1 row')
        ;
    });
});

describe('the page template', function (): void {
    test('renders the picker, the panels and a stored result, escaping nfdump\'s messages (D21)', function (): void {
        [$app, $c, $states] = talkersPageTestCompose();
        $c->getSignal('stats_for')?->setValue('srcip');
        StatsActions::storePanel($states->talkers, 'proto', [
            ['key' => '6', 'proto' => 'TCP', 'flows' => 1, 'packets' => 1, 'bytes' => 780, 'bytesPct' => 78.0],
            ['key' => '47', 'proto' => 'GRE', 'flows' => 1, 'packets' => 1, 'bytes' => 6, 'bytesPct' => 0.6],
        ], talkersPageTestResult(0), 0.1, StatsActions::params($c));
        StatsActions::storeResult($states->talkers, talkersPageTestResult(2, stderr: 'Skipped <b>x</b>'), 0.5, '', 'Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).', StatsActions::params($c));
        $states->talkers->notifyFailure(new NfdumpException("Unknown protocol: <b>x</b> at '\"<b>x</b>\"'", "nfdump -M /data -- 'proto \"<b>x</b>\"'"));

        $html = $c->render('pages/talkers.html.twig', Shell::render($c, $app, $states, false));

        expect($html)->not->toContain('<b>x</b>')
            ->and($html)->toContain(
                'Error: Unknown protocol: &lt;b&gt;x&lt;/b&gt;',
                "?page=talkers&id={$states->talkers->notifications[0]['id']}",
                'Time window clamped to 31 days (NFSEN_MAX_STATS_WINDOW).',
                'nfdump warning: <code>Skipped &lt;b&gt;x&lt;/b&gt;</code>',
                "?page=talkers&id={$states->talkers->result('srcip')['warnings'][0]['id']}",
                'id="talkersTab-talkers" value="talkers"',
                'id="statsFilterForSelection"',
                '<optgroup label="NEL / NAT">',
                'title="Unknown statistic: nevent"',
                'NAT Event type (not supported by this nfdump)</option>',
                'id="filterStatsAggregation"',
                'data-run="talkers"',
                'data-run="talkers-proto"',
                'data-run="talkers-as"',
                'Src IP address, ordered by bytes · 2 rows',
                'id="statsMessage"',
                'id="statsTable"',
                'data-series="1"',
                'Pick a statistic and press Run.',
            )
            ->and(preg_match('~<li class="bar-row"[^>]*>\s*<span class="bar-label">GRE</span>\s*<span class="bar"\s+aria-hidden~', $html))->toBe(1)
            ->and('<!DOCTYPE html><title>Top Talkers</title>' . $html)->toBeValidHtml()
        ;
    });
});
