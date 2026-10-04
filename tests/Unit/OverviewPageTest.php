<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\Misc;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\datasources\TotalsProvider;
use mbolli\nfsen_ng\pages\HealthPage;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\state\OverviewState;
use mbolli\nfsen_ng\pages\TrafficGraph;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

// Five days back at midnight UTC: inside the 31 day retention whenever the suite runs.
define('OVT_T0', intdiv(time(), 86400) * 86400 - 5 * 86400);

/**
 * The page handler against a Via with the real templates, in its fatal state so a render reads
 * no datasource.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function overviewTestCompose(): array {
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-overview', '/', $app);
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
    $c->getSignal('page')?->setValue('overview');

    return [$app, $c, $states];
}

/**
 * A datasource with stored totals that records what the traffic graph asks it for.
 */
function overviewTestDatasource(): Datasource&TotalsProvider {
    return new class implements Datasource, TotalsProvider {
        /** @var list<list<mixed>> get_graph_data arguments, one entry per call */
        public array $graphCalls = [];

        /** @var list<string> */
        public array $legend = ['gw_bits_any'];

        /** An error string answers instead of a series, as RRD does. */
        public string $graphError = '';

        public int $lastWrite = 0;

        public int $totalsReads = 0;

        public function write(array $data): bool {
            return true;
        }

        public function get_graph_data(int $start, int $end, array $sources, array $protocols, array $ports, string $type = 'flows', string $display = 'sources', ?int $maxrows = 500, string $profile = ''): array|string {
            $this->graphCalls[] = [$start, $end, $sources, $protocols, $ports, $type, $display, $maxrows, $profile];
            if ($this->graphError !== '') {
                return $this->graphError;
            }
            $row = array_fill(0, count($this->legend), 1.0);

            return ['start' => $start, 'end' => $end, 'step' => 300, 'legend' => $this->legend, 'data' => [$start => $row, $start + 300 => $row]];
        }

        public function reset(array $sources, string $profile = ''): bool {
            return true;
        }

        public function acceptsHistoricWrites(): bool {
            return true;
        }

        public function date_boundaries(string $source, string $profile = ''): array {
            return [OVT_T0 - 86400, OVT_T0 + 86400];
        }

        public function last_update(string $source, int $port = 0, string $profile = ''): int {
            return $this->lastWrite;
        }

        public function get_data_path(string $source = '', int $port = 0, string $profile = ''): string {
            return '';
        }

        public function healthChecks(string $group, array $sources): array {
            return [];
        }

        public function fetchLatestSlot(array $sources, string $profile): array {
            return ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        }

        public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds, ?int $end = null): array {
            return ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        }

        public function fetchTotals(array $sources, string $profile, int $start, int $end, string $protocol = 'any'): array {
            ++$this->totalsReads;

            return ['flows' => 10.0, 'packets' => 20.0, 'bytes' => 3000.0];
        }

        public function fetchProtocolTotals(array $sources, string $profile, int $start, int $end): array {
            $totals = $this->fetchTotals($sources, $profile, $start, $end);

            return ['any' => $totals, 'tcp' => $totals, 'udp' => $totals, 'icmp' => $totals, 'other' => $totals];
        }
    };
}

/**
 * Overview composed against a datasource: the graph and the totals are read for real.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function overviewTestComposeLive(Datasource $db): array {
    [$app, $c, $states] = overviewTestCompose();
    $app->setGlobalState('_fatalError', null);
    // Fresh health checks, so the sidebar dot runs none of its own.
    $app->setGlobalState(HealthPage::CACHE, ['ts' => time(), 'checks' => [], 'level' => 'ok']);
    Config::$db = $db;
    $c->getSignal('datestart')?->setValue(OVT_T0);
    $c->getSignal('dateend')?->setValue(OVT_T0 + 3600);
    $c->getSignal('range_live')?->setValue(false);

    return [$app, $c, $states];
}

/**
 * @param array<string, int>             $bytesByKey
 * @param array<int, array<string, int>> $extra      more statistics, keyed by TopNStat value
 */
function overviewTestStore(TopNRepository $repo, string $source, int $ts, array $bytesByKey, array $extra = []): void {
    $rows = static fn (array $keys): array => array_map(
        static fn (int|string $key, int $bytes): array => ['key' => (string) $key, 'flows' => 1, 'packets' => 2, 'bytes' => $bytes],
        array_keys($keys),
        $keys,
    );
    $byStat = [TopNStat::SrcIp->value => $rows($bytesByKey), TopNStat::DstIp->value => $rows(['10.9.0.1' => 500])];
    foreach ($extra as $stat => $keys) {
        $byStat[$stat] = $rows($keys);
    }
    $repo->storeInterval('live', $source, $ts, ['flows' => 5, 'packets' => 10, 'bytes' => 1000], TopNRepository::STATUS_OK, 1, $byStat);
}

/** @return array{start: int, end: int, live: bool, sources: list<string>, profile: string, protocol: string, tab: string, dir: string, limit: int, order: string} */
function overviewTestInputs(array $override = []): array {
    return [
        'start' => OVT_T0,
        'end' => OVT_T0 + 3600,
        'live' => false,
        'sources' => ['gw'],
        'profile' => 'live',
        'protocol' => 'any',
        'tab' => 'talkers',
        'dir' => 'src',
        'limit' => 10,
        'order' => 'bytes',
        ...$override,
    ];
}

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $prefs = new ReflectionProperty(Config::class, 'prefsFile');
    $db = new ReflectionProperty(Config::class, 'db');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;
    $this->dbBefore = $db->isInitialized() ? Config::$db : null;

    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'core'], 'ports' => [80, 443, 53], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => sys_get_temp_dir() . '/nfsen-overview-test-missing', 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => LOG_ERR],
    ])->withTopnRetentionDays(31);
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-overview-test-missing.json';

    $this->store = Database::open(':memory:');
    $this->repo = new TopNRepository($this->store);
    TopNRepository::clearCache();
    TopNCollector::reset();
    $this->now = OVT_T0 + 86400;
});

afterEach(function (): void {
    TopNRepository::clearCache();
    TopNCollector::reset();
    Database::resetShared();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->prefsBefore !== null) {
        Config::$prefsFile = $this->prefsBefore;
    }
    if ($this->dbBefore !== null) {
        Config::$db = $this->dbBefore;
    }
});

describe('signals and inputs', function (): void {
    test('the page declares the graph, top-N and pending signals of 4.1.1', function (): void {
        [, $c] = overviewTestCompose();

        expect($c->getSignal('graph_step')?->int())->toBe(0)
            ->and($c->getSignal('ov_tab')?->string())->toBe('talkers')
            ->and($c->getSignal('ov_dir')?->string())->toBe('src')
            ->and($c->getSignal('ov_limit')?->int())->toBe(10)
            ->and($c->getSignal('ov_order')?->string())->toBe('bytes')
            ->and($c->getSignal('_ov_topn_pending')?->bool())->toBeFalse()
            ->and(array_keys($c->getNamedActions()))->toContain('overview-topn', 'overview-topn-run', 'run-filtered-graph', 'refresh-graphs')
        ;
    });

    test('every client-written input is brought back to a known value', function (): void {
        [, $c] = overviewTestCompose();
        $c->getSignal('ov_tab')?->setValue('bogus');
        $c->getSignal('ov_dir')?->setValue('sideways');
        $c->getSignal('ov_limit')?->setValue(7);
        $c->getSignal('ov_order')?->setValue('bpp');
        $c->getSignal('graph_sources')?->setValue(['core', '../etc', 'gw']);
        $c->getSignal('selected_profile')?->setValue('not-listed');

        $in = OverviewPage::inputs($c);

        expect([$in['tab'], $in['dir'], $in['limit'], $in['order'], $in['sources'], $in['profile']])
            ->toBe(['talkers', 'src', 10, 'bytes', ['core', 'gw'], 'live'])
        ;
    });

    test('protocols have no direction, so it cannot change their answer', function (): void {
        [, $c] = overviewTestCompose();
        $c->getSignal('ov_tab')?->setValue('protocols');
        $c->getSignal('ov_dir')?->setValue('dst');

        expect(OverviewPage::inputs($c)['dir'])->toBe('src');
    });

    test('the fingerprints count the window in 5 minute intervals', function (): void {
        $a = overviewTestInputs(['start' => OVT_T0 + 10, 'end' => OVT_T0 + 3610]);
        $b = overviewTestInputs(['start' => OVT_T0 + 250, 'end' => OVT_T0 + 3850]);
        $c = overviewTestInputs(['start' => OVT_T0 + 310, 'end' => OVT_T0 + 3910]);

        expect(OverviewPage::fingerprint($a))->toBe(OverviewPage::fingerprint($b))
            ->and(OverviewPage::fingerprint($a))->not->toBe(OverviewPage::fingerprint($c))
            ->and(OverviewPage::kpiFingerprint(overviewTestInputs(['tab' => 'ports'])))->toBe(OverviewPage::kpiFingerprint(overviewTestInputs()))
            ->and(OverviewPage::fingerprint(overviewTestInputs(['tab' => 'ports'])))->not->toBe(OverviewPage::fingerprint(overviewTestInputs()))
        ;
    });

    // 1.7: a live result does not turn stale while the window follows the clock.
    test('the exact run keys a live window by its width', function (): void {
        $live = overviewTestInputs(['live' => true]);
        $later = overviewTestInputs(['live' => true, 'start' => OVT_T0 + 900, 'end' => OVT_T0 + 4500]);
        $fixed = overviewTestInputs(['start' => OVT_T0 + 900, 'end' => OVT_T0 + 4500]);

        expect(OverviewPage::exactFingerprint($live))->toBe(OverviewPage::exactFingerprint($later))
            ->and(OverviewPage::exactFingerprint($fixed))->not->toBe(OverviewPage::exactFingerprint(overviewTestInputs()))
            ->and(OverviewPage::exactFingerprint(overviewTestInputs(['protocol' => 'udp'])))->not->toBe(OverviewPage::exactFingerprint(overviewTestInputs()))
        ;
    });
});

describe('the precomputed answer', function (): void {
    test('ranks the tab statistic and fills the three KPI cards', function (): void {
        for ($i = 0; $i < 12; ++$i) {
            overviewTestStore($this->repo, 'gw', OVT_T0 + $i * 300, ['10.0.0.1' => 600, '10.0.0.2' => 300], [TopNStat::Proto->value => ['6' => 700, '17' => 200]]);
        }

        $answer = OverviewPage::computeTopN(overviewTestInputs(), $this->now, null, $this->repo);

        expect($answer['state'])->toBe('ok')
            ->and(array_column($answer['table']['rows'], 'key'))->toBe(['10.0.0.1', '10.0.0.2'])
            ->and($answer['table']['rows'][0]['share'])->toBe(60.0)
            ->and($answer['table']['exactIntervals'])->toBeTrue()
            ->and($answer['table']['expected'])->toBe(12)
            ->and($answer['table']['coverage'])->toBe(1.0)
            ->and($answer['kpi']['src']['key'] ?? null)->toBe('10.0.0.1')
            ->and($answer['kpi']['dst']['key'] ?? null)->toBe('10.9.0.1')
            ->and($answer['kpi']['proto']['key'] ?? null)->toBe('6')
            ->and($answer['fingerprint'])->toBe(OverviewPage::fingerprint(overviewTestInputs()))
        ;
    });

    test('the "In top 50" denominator is intervals times sources, per source for interfaces', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600], [TopNStat::InIf->value => ['3' => 900]]);
        $two = overviewTestInputs(['sources' => ['gw', 'core']]);

        expect(OverviewPage::computeTopN($two, $this->now, null, $this->repo)['table']['expected'])->toBe(24)
            ->and(OverviewPage::computeTopN([...$two, 'tab' => 'interfaces'], $this->now, null, $this->repo)['table']['expected'])->toBe(12)
        ;
    });

    test('nothing collected yet is Collecting; collected but empty intervals are Empty', function (): void {
        expect(OverviewPage::computeTopN(overviewTestInputs(), $this->now, null, $this->repo)['state'])->toBe('collecting');

        $this->repo->storeInterval('live', 'gw', OVT_T0, ['flows' => 0, 'packets' => 0, 'bytes' => 0], TopNRepository::STATUS_EMPTY, 1, []);
        // The collector bumps its generation after a store; here the cache is cleared by hand.
        TopNRepository::clearCache();

        expect(OverviewPage::computeTopN(overviewTestInputs(), $this->now, null, $this->repo)['state'])->toBe('empty');
    });

    test('outside the retention, or with collection off, nothing is read', function (): void {
        $old = overviewTestInputs(['start' => $this->now - 40 * 86400]);

        expect(OverviewPage::computeTopN($old, $this->now, null, $this->repo)['state'])->toBe('outside');

        Config::$settings = Config::$settings->withTopnRetentionDays(0);

        expect(OverviewPage::computeTopN(overviewTestInputs(), $this->now, null, $this->repo)['state'])->toBe('disabled');
    });

    test('an unavailable store is a state with its reason, not an exception', function (): void {
        Database::resetShared();
        $stateDir = new ReflectionProperty(Config::class, 'stateDir');
        $before = $stateDir->isInitialized() ? Config::$stateDir : null;
        Config::$stateDir = '';

        try {
            $answer = OverviewPage::computeTopN(overviewTestInputs(), $this->now);
        } finally {
            if ($before !== null) {
                Config::$stateDir = $before;
            }
        }

        expect($answer['state'])->toBe('unavailable')
            ->and($answer['reason'])->toContain('state directory')
        ;
    });

    test('the yield runs between the chunks of a long range', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600]);
        $yields = 0;

        OverviewPage::computeTopN(overviewTestInputs(['end' => OVT_T0 + 3 * 86400]), OVT_T0 + 4 * 86400, static function () use (&$yields): void {
            ++$yields;
        }, $this->repo);

        expect($yields)->toBeGreaterThan(0);
    });
});

describe('keys and rank chips', function (): void {
    test('each tab prints its key the way 4.1.6 shows it', function (): void {
        expect(OverviewPage::keyLabel('talkers', '203.0.113.45'))->toBe('203.0.113.45')
            ->and(OverviewPage::keyLabel('ports', '443/tcp'))->toBe(getservbyport(443, 'tcp') !== false ? '443/tcp (' . getservbyport(443, 'tcp') . ')' : '443/tcp')
            ->and(OverviewPage::keyLabel('ports', '47808/udp'))->toBe(getservbyport(47808, 'udp') !== false ? '47808/udp (' . getservbyport(47808, 'udp') . ')' : '47808/udp')
            ->and(OverviewPage::keyLabel('ports', '443'))->toBe('443')
            ->and(OverviewPage::keyLabel('protocols', '6'))->toBe('TCP (6)')
            ->and(OverviewPage::keyLabel('protocols', '58'))->toBe('ICMPV6 (58)')
            ->and(OverviewPage::keyLabel('asns', '64512'))->toBe('AS64512')
            ->and(OverviewPage::keyLabel('asns', '0'))->toBe('0 (not exported)')
            ->and(OverviewPage::keyLabel('interfaces', '3', 'core'))->toBe('core · if 3')
            ->and(OverviewPage::keyLabel('interfaces', '3'))->toBe('if 3')
            ->and(OverviewPage::protocolName('17'))->toBe('UDP')
            ->and(OverviewPage::protocolName('254'))->toBeString()
        ;
    });

    // 2.3: a chip is coloured only when its row is exactly a series the graph draws.
    test('protocol rows get slots 1 to 3 only while the graph shows protocols', function (): void {
        expect(OverviewPage::rankSlot('protocols', '6', 'protocols', []))->toBe(1)
            ->and(OverviewPage::rankSlot('protocols', '17', 'protocols', []))->toBe(2)
            ->and(OverviewPage::rankSlot('protocols', '1', 'protocols', []))->toBe(3)
            ->and(OverviewPage::rankSlot('protocols', '58', 'protocols', []))->toBe(3)
            ->and(OverviewPage::rankSlot('protocols', '47', 'protocols', []))->toBe(0)
            ->and(OverviewPage::rankSlot('protocols', '6', 'sources', []))->toBe(0)
        ;
    });

    test('no chip is coloured while the graph draws no series: before Apply, after a failed fetch', function (): void {
        for ($i = 0; $i < 12; ++$i) {
            overviewTestStore($this->repo, 'gw', OVT_T0 + $i * 300, ['10.0.0.1' => 600], [TopNStat::Proto->value => ['6' => 700, '17' => 100]]);
        }
        $db = overviewTestDatasource();
        $db->legend = ['tcp_bits', 'udp_bits', 'icmp_bits', 'other_bits'];
        [$app, $c, $states] = overviewTestComposeLive($db);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $c->getSignal('graph_display')?->setValue('protocols');
        $c->getSignal('ov_tab')?->setValue('protocols');
        $states->overview->topn = OverviewPage::computeTopN(OverviewPage::inputs($c), $this->now, null, $this->repo);
        $slots = static fn (): array => array_column(Shell::render($c, $app, $states, true)['pages']['overview']['topn']['rows'], 'slot');

        $drawn = $slots();
        $c->getSignal('graph_mode')?->setValue('filtered');
        $beforeApply = $slots();
        $c->getSignal('graph_mode')?->setValue('stored');
        $db->graphError = 'rrd_xport failed';
        $failed = $slots();

        expect($drawn)->toBe([1, 2])
            ->and($beforeApply)->toBe([0, 0])
            ->and($failed)->toBe([0, 0])
        ;
    });

    test('a configured and drawn port gets its configured slot', function (): void {
        expect(OverviewPage::rankSlot('ports', '443/tcp', 'ports', [80, 443]))->toBe(2)
            ->and(OverviewPage::rankSlot('ports', '53/udp', 'ports', [80, 443]))->toBe(0)
            ->and(OverviewPage::rankSlot('ports', '8080/tcp', 'ports', [80, 443, 8080]))->toBe(0)
            ->and(OverviewPage::rankSlot('ports', '443/tcp', 'sources', [443]))->toBe(0)
            ->and(OverviewPage::rankSlot('talkers', '10.0.0.1', 'sources', []))->toBe(0)
        ;
    });
});

describe('the KPI strip and the card', function (): void {
    test('a card says why it has no figure, and is busy only while it waits', function (): void {
        $in = overviewTestInputs(['start' => $this->now - 3600, 'end' => $this->now]);
        $waiting = OverviewPage::kpiView($in, null, $this->now);
        $collecting = OverviewPage::kpiView($in, OverviewPage::answer($in, $this->now, 'collecting'), $this->now);
        $unavailable = OverviewPage::kpiView($in, OverviewPage::answer($in, $this->now, 'unavailable', 'disk full'), $this->now);
        $outside = OverviewPage::kpiView([...$in, 'start' => $this->now - 40 * 86400], null, $this->now);

        expect(array_column($waiting, 'busy'))->toBe([true, true, true])
            ->and($collecting[0]['value'])->toBe('Collecting (first data after the next import)')
            ->and($collecting[0]['busy'])->toBeFalse()
            ->and($unavailable[2]['value'])->toBe('Top-N unavailable: disk full')
            ->and($outside[1]['value'])->toBe('Outside the 31 day window')
            ->and($outside[1]['busy'])->toBeFalse()
        ;

        Config::$settings = Config::$settings->withTopnRetentionDays(0);
        expect(OverviewPage::kpiView($in, null, $this->now)[0]['value'])->toBe('Top-N disabled');
    });

    test('a filled card shows the key, volume and share, and the protocol by name', function (): void {
        for ($i = 0; $i < 12; ++$i) {
            overviewTestStore($this->repo, 'gw', OVT_T0 + $i * 300, ['10.0.0.1' => 600], [TopNStat::Proto->value => ['6' => 700]]);
        }
        $in = overviewTestInputs();
        $cards = OverviewPage::kpiView($in, OverviewPage::computeTopN($in, $this->now, null, $this->repo), $this->now);

        expect($cards[0])->toMatchArray(['value' => '10.0.0.1', 'ip' => '10.0.0.1', 'kind' => 'address', 'approx' => true, 'busy' => false])
            ->and($cards[0]['meta'])->toBe('7.03 KiB · 60.0%')
            ->and(OverviewPage::kpiView($in, OverviewPage::computeTopN($in, $this->now, null, $this->repo), $this->now, 'bits')[0]['meta'])->toBe('57.6 kb · 60.0%')
            ->and($cards[2])->toMatchArray(['value' => 'TCP', 'meta' => '70.0% of bytes', 'ip' => ''])
            // Another table tab keeps the KPI answer current.
            ->and(OverviewPage::kpiView([...$in, 'tab' => 'ports'], OverviewPage::computeTopN($in, $this->now, null, $this->repo), $this->now)[0]['busy'])->toBeFalse()
        ;
    });

    test('the card notes coverage below 95 %, the ranking and the protocol that does not apply', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600]);
        $in = overviewTestInputs(['protocol' => 'udp']);
        $overview = new OverviewState();
        $overview->topn = OverviewPage::computeTopN($in, $this->now, null, $this->repo);

        $view = OverviewPage::topnView($in, $overview, 'sources', [], $this->now);

        expect($view['state'])->toBe('table')
            ->and($view['busy'])->toBeFalse()
            ->and($view['exactRun'])->toBeFalse()
            ->and($view['notes'])->toBe([
                "Covers 8% of the range's 5 minute intervals.",
                "Ranked by bytes within each 5 minute interval; addresses outside an interval's top 50 are not counted.",
                'The protocol filter does not apply to precomputed lists.',
            ])
            ->and($view['rows'][0])->toMatchArray(['rank' => 1, 'label' => '10.0.0.1', 'ip' => '10.0.0.1', 'intervals' => '1 of 12', 'shareText' => '60.0%'])
            // A tab switch dims the old table until the new answer arrives.
            ->and(OverviewPage::topnView([...$in, 'tab' => 'ports'], $overview, 'sources', [], $this->now)['busy'])->toBeTrue()
        ;
    });

    // After 1y -> 30d the old 'outside' answer is not an empty table for the new window.
    test('an answer for other inputs with nothing to show reads as loading, cards and table alike', function (): void {
        $in = overviewTestInputs();
        $overview = new OverviewState();
        $overview->topn = OverviewPage::answer([...$in, 'start' => $this->now - 400 * 86400], $this->now, 'outside');
        $view = OverviewPage::topnView($in, $overview, 'sources', [], $this->now);
        $cards = OverviewPage::kpiView($in, $overview->topn, $this->now);
        $collectingElsewhere = OverviewPage::answer([...$in, 'start' => OVT_T0 + 7200, 'end' => OVT_T0 + 9000], $this->now, 'collecting');
        $overview->topn = OverviewPage::answer($in, $this->now, 'collecting');

        expect($view)->toMatchArray(['state' => 'loading', 'notes' => [], 'rows' => [], 'busy' => true, 'exactRun' => false])
            ->and(array_column($cards, 'value'))->toBe(['Computing', 'Computing', 'Computing'])
            ->and(array_column($cards, 'busy'))->toBe([true, true, true])
            ->and(OverviewPage::kpiView($in, $collectingElsewhere, $this->now)[0]['value'])->toBe('Computing')
            // The answer for these inputs says what it is.
            ->and(OverviewPage::topnView($in, $overview, 'sources', [], $this->now)['state'])->toBe('collecting')
            ->and(OverviewPage::kpiView($in, $overview->topn, $this->now)[0]['value'])->toBe('Collecting (first data after the next import)')
        ;
    });

    test('out of retention the card offers the exact run and shows its result for the same inputs only', function (): void {
        $in = overviewTestInputs(['start' => $this->now - 40 * 86400, 'end' => $this->now - 39 * 86400]);
        $overview = new OverviewState();
        $overview->exact = [
            'fingerprint' => OverviewPage::exactFingerprint($in),
            'computedAt' => $this->now,
            'live' => false,
            'tab' => 'talkers',
            'dir' => 'src',
            'start' => $in['start'],
            'end' => $in['end'],
            'rows' => [['key' => '10.0.0.9', 'flows' => 3, 'packets' => 4, 'bytes' => 2048, 'share' => 12.5]],
            'command' => 'nfdump -M /data/live/gw -s srcip/bytes',
        ];

        $view = OverviewPage::topnView($in, $overview, 'sources', [], $this->now);
        $other = OverviewPage::topnView([...$in, 'tab' => 'ports'], $overview, 'sources', [], $this->now);

        expect($view['state'])->toBe('outside')
            ->and($view['exactRun'])->toBeTrue()
            ->and($view['exact']['rows'][0] ?? null)->toMatchArray(['label' => '10.0.0.9', 'bytes' => '2.00 KiB', 'shareText' => '12.5%'])
            ->and($view['exact']['movedOn'] ?? null)->toBeFalse()
            ->and($other['exact'])->toBeNull()
        ;
    });

    test('a live exact result says so once the window has moved on', function (): void {
        $in = overviewTestInputs(['live' => true, 'start' => $this->now - 40 * 86400, 'end' => $this->now]);
        $overview = new OverviewState();
        $overview->exact = [
            'fingerprint' => OverviewPage::exactFingerprint($in),
            'computedAt' => $this->now - 600,
            'live' => true,
            'tab' => 'talkers',
            'dir' => 'src',
            'start' => $in['start'],
            'end' => $in['end'],
            'rows' => [],
            'command' => 'nfdump',
        ];

        expect(OverviewPage::topnView($in, $overview, 'sources', [], $this->now)['exact']['movedOn'] ?? null)->toBeTrue();
    });
});

describe('the exact run', function (): void {
    // Wave 2 note: nfdump's -R includes the file stamped at its end, the stored lists do not.
    test('reads the same 5 minute intervals as the stored lists, with the tab element and the protocol', function (): void {
        $in = overviewTestInputs(['start' => OVT_T0 + 100, 'end' => OVT_T0 + 3700, 'tab' => 'ports', 'dir' => 'dst', 'protocol' => 'udp', 'limit' => 20, 'order' => 'packets']);
        $query = OverviewPage::exactQuery($in, 'ctx');

        expect($query->window->toRangeOption())->toBe([OVT_T0, OVT_T0 + 3599])
            ->and($query->for)->toBe('dstport')
            ->and($query->protocol)->toBe('udp')
            ->and($query->limit)->toBe(20)
            ->and($query->orderBy)->toBe('packets')
            ->and($query->handle)->toBe('ctx')
            ->and(OverviewPage::element('protocols', 'dst'))->toBe('proto')
            ->and(OverviewPage::element('interfaces', 'src'))->toBe('inif')
            ->and(OverviewPage::stat('asns', 'dst'))->toBe(TopNStat::DstAs)
        ;
    });

    test('takes the share from the same nfdump run as the rows, not from the stored totals', function (): void {
        // Out of retention the stored series cover far more than the capture files nfdump still has.
        $in = overviewTestInputs(['tab' => 'protocols']);
        $query = OverviewPage::exactQuery($in, 'ctx');
        $raw = "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,4160,53.3,41600,76.2,4160000,78.0,41600,33280000,100\n"
            . "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,17,2080,26.7,10400,19.0,1040000,19.5,10400,8320000,100\n";
        $result = new QueryResult(rows: [], command: 'nfdump', stderr: '', elapsed: 0.1, window: $query->window, rawOutput: $raw);

        expect($query->output)->toBe('csv')
            ->and(OverviewPage::exactRows($query->statRows($result)))->toBe([
                ['key' => '6', 'flows' => 4160, 'packets' => 41600, 'bytes' => 4160000, 'share' => 78.0],
                ['key' => '17', 'flows' => 2080, 'packets' => 10400, 'bytes' => 1040000, 'share' => 19.5],
            ])
        ;
    });
});

describe('the exact run over a large read', function (): void {
    // 48 sparse files of 10 MB: 480 MB, over a second at the default read rate.
    test('runs as time slices in parallel nfdump processes, merges them exactly and records the processes', function (): void {
        $root = sys_get_temp_dir() . '/nfsen-overview-split-' . bin2hex(random_bytes(4));
        for ($i = 0; $i < 48; ++$i) {
            $ts = OVT_T0 + $i * 300;
            $dir = $root . '/live/gw/' . gmdate('Y/m/d', $ts);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            $handle = fopen($dir . '/nfcapd.' . gmdate('YmdHi', $ts), 'w');
            ftruncate($handle, 10_000_000);
            fclose($handle);
        }
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw', 'core'], 'ports' => [80], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $root, 'profile' => 'live', 'max-processes' => 4],
            'log' => ['priority' => LOG_ERR],
        ])->withTopnRetentionDays(31);
        $slot = new ReflectionProperty(Config::class, 'processorClass');
        $processorBefore = $slot->isInitialized() ? Config::$processorClass : null;
        // Every part: two addresses and the protocol list the shares come from.
        Config::$processorClass = new class implements Processor {
            /** @var array<string, mixed> */
            private array $options = [];

            public function setOption(string $option, $value): void {
                $this->options[$option] = $value;
            }

            public function setFilter(string $filter): void {}

            public function setQueryHandle(string $handle): void {}

            public function setProfile(string $profile): void {}

            public function execute(): array {
                $line = static fn (string $name, string $value, int $proto, int $bytes): string => '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:10:00.000", "proto" : ' . $proto . ', "' . $name . '" : "' . $value . '", "flows" : 1, "packets" : 10, "bytes" : ' . $bytes . ', "pps" : 0, "bps" : 0, "bpp" : 0}';

                return ['command' => 'nfdump -R ' . $this->options['-R'], 'rawOutput' => implode("\n", [
                    $line('srcip', '10.0.0.1', 0, 3000),
                    $line('srcip', '10.0.0.2', 0, 1000),
                    $line('proto', '6', 6, 4000),
                ]) . "\n", 'decoded' => [], 'exitCode' => 0];
            }
        };
        Database::useShared($this->store);
        [, $c, $states] = overviewTestCompose();
        $c->getSignal('datestart')?->setValue(OVT_T0);
        $c->getSignal('dateend')?->setValue(OVT_T0 + 48 * 300);
        $c->getSignal('graph_sources')?->setValue(['gw']);

        try {
            Coroutine::run(static function () use ($c): void {
                $c->executeAction((string) $c->getAction('overview-topn-run')?->id());
            });
        } finally {
            if ($processorBefore !== null) {
                Config::$processorClass = $processorBefore;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }

        // 4 slots: the split takes 3 and leaves one for another query.
        expect($states->overview->exact['rows'] ?? null)->toBe([
            ['key' => '10.0.0.1', 'flows' => 3, 'packets' => 30, 'bytes' => 9000, 'share' => 75.0],
            ['key' => '10.0.0.2', 'flows' => 3, 'packets' => 30, 'bytes' => 3000, 'share' => 25.0],
        ])
            ->and($c->getSignal('query_status')?->string())->toStartWith('Done in ')->toEndWith(' with 3 nfdump processes.')
            ->and($c->getSignal('_ov_exact_rows')?->int())->toBe(2)
            ->and($this->store->all('SELECT kind, files, parts, passes FROM query_runs'))->toBe([['kind' => 'overview-topn', 'files' => 48, 'parts' => 3, 'passes' => 1]])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });
});

describe('the overview-topn action', function (): void {
    test('marks the tab pending, computes in a coroutine and stores the answer', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600]);
        Database::useShared($this->store);
        [, $c, $states] = overviewTestCompose();
        // Three days are read in chunks with a yield between them, so the action returns first.
        $c->getSignal('datestart')?->setValue(OVT_T0);
        $c->getSignal('dateend')?->setValue(OVT_T0 + 3 * 86400);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $pendingDuring = null;

        Coroutine::run(static function () use ($c, &$pendingDuring): void {
            $c->executeAction((string) $c->getAction('overview-topn')?->id());
            $pendingDuring = $c->getSignal('_ov_topn_pending')?->bool();
        });

        $topn = $states->overview->topn;
        expect($pendingDuring)->toBeTrue()
            ->and($c->getSignal('_ov_topn_pending')?->bool())->toBeFalse()
            ->and($topn['fingerprint'] ?? null)->toBe(OverviewPage::fingerprint(OverviewPage::inputs($c)))
            ->and($topn['state'] ?? null)->not->toBe('error')
            ->and($states->overview->topnRunning)->toBe('')
        ;
    });

    test('a request for the answer already being computed is dropped', function (): void {
        [, $c, $states] = overviewTestCompose();
        $states->overview->topnRunning = OverviewPage::fingerprint(OverviewPage::inputs($c));
        $states->overview->topnTicket = 4;

        $c->executeAction((string) $c->getAction('overview-topn')?->id());

        expect($states->overview->topnTicket)->toBe(4)
            ->and($c->getSignal('_ov_topn_pending')?->bool())->toBeFalse()
        ;
    });

    test('a superseded request leaves the guard of a newer one for the same answer alone', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600]);
        Database::useShared($this->store);
        [, $c, $states] = overviewTestCompose();
        $c->getSignal('datestart')?->setValue(OVT_T0);
        $c->getSignal('dateend')?->setValue(OVT_T0 + 3 * 86400);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $fingerprint = OverviewPage::fingerprint(OverviewPage::inputs($c));

        Coroutine::run(static function () use ($c, $states): void {
            $c->executeAction((string) $c->getAction('overview-topn')?->id());
            // Meanwhile another range was asked for, then this one again: that newer run owns the guard.
            $states->overview->topnTicket += 2;
        });

        expect($states->overview->topnRunning)->toBe($fingerprint)
            ->and($states->overview->topn)->toBeNull()
            ->and($c->getSignal('_ov_topn_pending')?->bool())->toBeTrue()
        ;
    });
});

describe('the state survives a revival', function (): void {
    test('snapshot and restore keep the answer and the exact result', function (): void {
        overviewTestStore($this->repo, 'gw', OVT_T0, ['10.0.0.1' => 600]);
        $state = new OverviewState();
        expect($state->isEmpty())->toBeTrue();
        $state->topn = OverviewPage::computeTopN(overviewTestInputs(), $this->now, null, $this->repo);
        $state->exact = ['fingerprint' => 'f', 'computedAt' => 1, 'live' => true, 'tab' => 'ports', 'dir' => 'dst', 'start' => 2, 'end' => 3, 'rows' => [['key' => '443', 'flows' => 1, 'packets' => 2, 'bytes' => 3, 'share' => null]], 'command' => 'nfdump'];

        $copy = new OverviewState();
        $copy->restore($state->snapshot());

        expect($copy->topn)->toBe($state->topn)
            ->and($copy->exact)->toBe($state->exact)
            ->and($copy->isEmpty())->toBeFalse()
        ;
    });

    test('a malformed snapshot restores nothing', function (): void {
        $state = new OverviewState();
        $state->restore(['topn' => ['kpi' => 'x'], 'exact' => ['rows' => [['key' => 5]]], 'notifications' => 'x']);

        expect($state->topn)->toBeNull()
            ->and($state->exact)->toBeNull()
            ->and($state->isEmpty())->toBeTrue()
            ->and(OverviewState::topnFrom(['kpi' => [], 'table' => ['rows' => [['key' => 'a', 'bytes' => '7']]]])['table']['rows'][0]['bytes'] ?? null)->toBe(7)
        ;
    });
});

describe('TrafficGraph', function (): void {
    test('the mode follows the page: Overview, the picker, one total, nothing (1.8)', function (): void {
        $modes = array_combine(PageRegistry::ids(), array_map(TrafficGraph::mode(...), PageRegistry::ids()));

        expect($modes)->toBe([
            'overview' => 'overview',
            'talkers' => 'picker',
            'flows' => 'picker',
            'conversations' => 'picker-total',
            'alerts' => PageRegistry::lazy() ? 'none' : 'overview',
            'health' => PageRegistry::lazy() ? 'none' : 'overview',
            'settings' => PageRegistry::lazy() ? 'none' : 'overview',
        ]);
    });

    test('titles and mode labels (2.7.2, 4.1.2)', function (): void {
        expect(TrafficGraph::title('overview', 'packets', 'ports'))->toBe('Packets by port')
            ->and(TrafficGraph::title('overview', 'traffic', 'protocols'))->toBe('Traffic by protocol')
            ->and(TrafficGraph::title('picker', 'flows', 'sources'))->toBe('Traffic by protocol')
            ->and(TrafficGraph::title('picker-total', 'traffic', 'sources'))->toBe('Total traffic')
            ->and(TrafficGraph::modeLabel(false, 288, 300))->toBe('Stored data · 5 min resolution')
            ->and(TrafficGraph::modeLabel(false, 288, 7200))->toBe('Stored data · 2 h resolution')
            ->and(TrafficGraph::modeLabel(false, 288, 86400))->toBe('Stored data · 1 day resolution')
            ->and(TrafficGraph::modeLabel(true, 0, 0))->toBe('Filtered data · press Apply filter')
            ->and(TrafficGraph::modeLabel(true, 150, 1800))->toBe('Filtered data · 30 min bins')
        ;
    });

    test('colour follows the entity: configured order, and TCP, UDP, ICMP, Other (2.3)', function (): void {
        Config::$settings = Config::$settings->withSources(['gw', 'gw_backup', 'core'])->withPorts([80, 443, 53]);

        expect(TrafficGraph::seriesSlots('sources', ['core_bits_any', 'gw_bits_any', 'gw_backup_bits_any', 'gone_bits_any']))->toBe([3, 1, 2, 0])
            ->and(TrafficGraph::seriesNames('sources', ['core_bits_any', 'gw_backup_bits_any']))->toBe(['core', 'gw_backup'])
            ->and(TrafficGraph::seriesSlots('protocols', ['other_bits', 'tcp_bits', 'icmp_flows_gw', 'udp_packets']))->toBe([4, 1, 3, 2])
            ->and(TrafficGraph::seriesNames('protocols', ['tcp_bits', 'any_bits']))->toBe(['TCP', 'Total'])
            ->and(TrafficGraph::seriesSlots('ports', ['53_bits', '443_bits_gw', '8080_bits']))->toBe([3, 2, 0])
            ->and(TrafficGraph::seriesNames('ports', ['53_bits', '443_bits_gw']))->toBe(['53', '443'])
        ;
    });

    test('a datasource without totals has none to read', function (): void {
        Config::$db = $this->createStub(Datasource::class);

        expect(TrafficGraph::readTotals(['gw'], 'live', OVT_T0, OVT_T0 + 3600, 'any'))->toBe(['totals' => null, 'error' => '']);
    });

    test('the picker stacks TCP, UDP, ICMP and Other summed over the selected sources, or the global protocol (1.8)', function (): void {
        $db = overviewTestDatasource();
        $db->legend = ['tcp_bits', 'udp_bits', 'icmp_bits', 'other_bits'];
        [$app, $c, $states] = overviewTestComposeLive($db);
        $c->getSignal('graph_sources')?->setValue(['core', 'gw']);

        $all = TrafficGraph::viewData($c, $app, $states, true, 'talkers');
        $allConfig = json_decode($all['config'], true);
        $c->getSignal('protocol')?->setValue('udp');
        $db->legend = ['udp_bits'];
        $udp = json_decode(TrafficGraph::viewData($c, $app, $states, true, 'flows')['config'], true);

        expect($all['mode'])->toBe('picker')
            ->and($all['title'])->toBe('Traffic by protocol')
            ->and($all['height'])->toBe('short')
            ->and(array_slice($db->graphCalls[0], 2))->toBe([['core', 'gw'], ['tcp', 'udp', 'icmp', 'other'], [], 'bits', 'protocols', TrafficGraph::PICKER_POINTS, 'live'])
            ->and($allConfig['seriesSlots'])->toBe([1, 2, 3, 4])
            ->and($allConfig['seriesNames'])->toBe(['TCP', 'UDP', 'ICMP', 'Other'])
            ->and($db->graphCalls[1][3])->toBe(['udp'])
            ->and($udp['seriesSlots'])->toBe([2])
            ->and($udp['seriesNames'])->toBe(['UDP'])
            ->and($all['totals'])->toBeNull()
            ->and($db->totalsReads)->toBe(0)
        ;
    });

    test('Conversations draws one neutral total, all protocols or the global one (D25)', function (): void {
        $db = overviewTestDatasource();
        $db->legend = ['any_bits'];
        [$app, $c, $states] = overviewTestComposeLive($db);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $c->getSignal('graph_trafficUnit')?->setValue('bytes');

        $total = TrafficGraph::viewData($c, $app, $states, true, 'conversations');
        $config = json_decode($total['config'], true);
        $c->getSignal('protocol')?->setValue('icmp');
        $db->legend = ['icmp_bytes'];
        $icmp = json_decode(TrafficGraph::viewData($c, $app, $states, true, 'conversations')['config'], true);

        expect($total['mode'])->toBe('picker-total')
            ->and($total['title'])->toBe('Total traffic')
            ->and(array_slice($db->graphCalls[0], 2, 5))->toBe([['gw'], ['any'], [], 'bytes', 'protocols'])
            ->and($config['seriesSlots'])->toBe([0])
            ->and($config['seriesNames'])->toBe(['Total'])
            ->and($db->graphCalls[1][3])->toBe(['icmp'])
            ->and($icmp['seriesSlots'])->toBe([0])
        ;
    });

    // D6: a refresh of the same window keeps a zoomed preview, a new window does not.
    test('the chart config names the window, a live one by its width', function (): void {
        [$app, $c, $states] = overviewTestComposeLive(overviewTestDatasource());
        $fixed = json_decode(TrafficGraph::viewData($c, $app, $states, true, 'overview')['config'], true)['window'];
        $c->getSignal('range_live')?->setValue(true);
        $live = json_decode(TrafficGraph::viewData($c, $app, $states, true, 'overview')['config'], true)['window'];
        $c->getSignal('datestart')?->setValue(OVT_T0 + 15);
        $c->getSignal('dateend')?->setValue(OVT_T0 + 3615);
        $later = json_decode(TrafficGraph::viewData($c, $app, $states, true, 'overview')['config'], true)['window'];

        expect($fixed)->toBe(OVT_T0 . ':' . (OVT_T0 + 3600))
            ->and($live)->toBe('live:3600')
            ->and($later)->toBe($live)
        ;
    });

    test('the KPI totals are read with the graph: again after an import, never while the last fetch failed', function (): void {
        $db = overviewTestDatasource();
        $db->lastWrite = OVT_T0 + 3300;
        [$app, $c, $states] = overviewTestComposeLive($db);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $render = static fn (): array => TrafficGraph::viewData($c, $app, $states, true, 'overview');

        $first = $render();
        $same = $render();
        $readsBefore = $db->totalsReads;
        $db->lastWrite = OVT_T0 + 3600;
        $imported = $render();
        $readsAfterImport = $db->totalsReads;
        $db->graphError = 'rrd_xport failed';
        $failed = $render();
        // An import throttles the next fetch, so this render reuses the failed one.
        $c->getSignal('import_running')?->setValue(true);
        $fetches = count($db->graphCalls);
        $throttled = $render();
        $fetchedWhileThrottled = count($db->graphCalls) - $fetches;
        $readsWhileFailed = $db->totalsReads;
        $c->getSignal('import_running')?->setValue(false);
        $db->graphError = '';
        $recovered = $render();

        expect($first['totals'])->toBe(['flows' => 10.0, 'packets' => 20.0, 'bytes' => 3000.0])
            ->and($first['totalsText'])->toBe(Misc::formatVolume(3000.0, 'bits'))
            ->and($same['totals'])->toBe($first['totals'])
            ->and($readsBefore)->toBe(1)
            ->and($imported['totals'])->not->toBeNull()
            ->and($readsAfterImport)->toBe(2)
            ->and($failed['totals'])->toBeNull()
            ->and($failed['totalsError'])->toBe('rrd_xport failed')
            ->and($fetchedWhileThrottled)->toBe(0)
            ->and($throttled['totals'])->toBeNull()
            ->and($throttled['totalsError'])->toBe('rrd_xport failed')
            ->and($readsWhileFailed)->toBe(2)
            ->and($recovered['totals'])->not->toBeNull()
            ->and($recovered['totalsError'])->toBe('')
            ->and($db->totalsReads)->toBe(3)
        ;
    });

    test('a filtered build that lands during an import is drawn at the next render', function (): void {
        [$app, $c, $states] = overviewTestComposeLive(overviewTestDatasource());
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $c->getSignal('graph_mode')?->setValue('filtered');
        $c->getSignal('graph_filter')?->setValue('proto tcp');
        $c->getSignal('import_running')?->setValue(true);
        $points = static fn (): int => $c->getSignal('graph_actualResolution')?->int() ?? -1;

        TrafficGraph::viewData($c, $app, $states, true, 'overview');
        $before = $points();
        FilteredGraphCache::put(GraphActions::filteredKey($c), ['start' => OVT_T0, 'end' => OVT_T0 + 600, 'step' => 300, 'legend' => ['gw'], 'data' => [OVT_T0 => [1.0], OVT_T0 + 300 => [2.0]]]);
        TrafficGraph::viewData($c, $app, $states, true, 'overview');

        expect($before)->toBe(0)->and($points())->toBe(2);
        FilteredGraphCache::clear();
    });

    test('a picker fetch that works again clears its own banner, and only that one', function (): void {
        $db = overviewTestDatasource();
        $db->legend = ['tcp_bits', 'udp_bits', 'icmp_bits', 'other_bits'];
        [$app, $c, $states] = overviewTestComposeLive($db);
        $error = $c->getSignal('_error');
        $render = static fn (): array => TrafficGraph::viewData($c, $app, $states, true, 'talkers');

        $db->graphError = 'rrd_xport failed';
        $render();
        $banner = $error?->string();
        $db->graphError = '';
        $render();
        $cleared = $error?->string();
        $error?->setValue('Range: the start is after the end');
        $render();

        expect($banner)->toBe('Graph error: rrd_xport failed')
            ->and($cleared)->toBe('')
            ->and($error?->string())->toBe('Range: the start is after the end')
        ;
    });
});

describe('the templates', function (): void {
    test('the Overview renders the KPI strip and the card from the stored answer', function (): void {
        for ($i = 0; $i < 12; ++$i) {
            overviewTestStore($this->repo, 'gw', OVT_T0 + $i * 300, ['10.0.0.1' => 600], [TopNStat::Proto->value => ['6' => 700, '47' => 100]]);
        }
        $db = overviewTestDatasource();
        $db->legend = ['tcp_bits', 'udp_bits', 'icmp_bits', 'other_bits'];
        [$app, $c, $states] = overviewTestComposeLive($db);
        $c->getSignal('graph_sources')?->setValue(['gw']);
        $c->getSignal('graph_display')?->setValue('protocols');
        $c->getSignal('ov_tab')?->setValue('protocols');
        $states->overview->topn = OverviewPage::computeTopN(OverviewPage::inputs($c), $this->now, null, $this->repo);

        $html = $c->render('pages/overview.html.twig', Shell::render($c, $app, $states, false));

        expect($html)->toContain('id="overviewKpis"', 'id="overviewTopn"', 'id="ovTab-protocols"', 'overview-topn')
            ->and($html)->toContain('<span class="rank" data-series="1">1</span>', 'TCP (6)', '<span class="rank">2</span>')
            ->and($html)->toMatch('/id="ovTab-protocols"[^>]*aria-selected="true"/')
            ->and($html)->not->toMatch('/class="[^"]*\b(muted|mono|strong|text-end|nowrap|upper|cluster-between|spacer|text-(danger|warning|success|info))\b/')
        ;
    });

    test('out of retention the card holds the estimate and the Run exact query control', function (): void {
        [$app, $c, $states] = overviewTestCompose();
        $c->getSignal('datestart')?->setValue(time() - 40 * 86400);
        $c->getSignal('dateend')?->setValue(time() - 39 * 86400);

        $html = $c->render('pages/overview.html.twig', Shell::render($c, $app, $states, false));
        $rows = $c->getSignal('_ov_exact_rows')?->id();

        expect($html)->toContain('data-run="overview-topn"', 'data-estimate="overview-topn"', 'Run exact query', 'class="empty-state"')
            // 2.5: the run announces how many rows it returned.
            ->and($html)->toContain('Number($' . $rows . ').toLocaleString(')
        ;
    });

    // D9, 1.7: the KPI strip and the card show what overview-topn stored, whatever the store holds.
    test('an Overview render reads nothing from the top-N store', function (): void {
        for ($i = 0; $i < 12; ++$i) {
            overviewTestStore($this->repo, 'gw', OVT_T0 + $i * 300, ['10.0.0.1' => 600]);
        }
        // Temp views shadow the tables for unqualified names and count the rows read through them.
        $reads = 0;
        $pdo = $this->store->pdo();
        $pdo->sqliteCreateFunction('ovt_tap', static function () use (&$reads): int {
            ++$reads;

            return 1;
        }, 0);
        foreach (['topn_interval', 'topn_5m', 'topn_1h', 'topn_1d'] as $table) {
            $pdo->exec("CREATE TEMP VIEW {$table} AS SELECT * FROM main.{$table} WHERE ovt_tap()");
        }
        Database::useShared($this->store);
        [$app, $c, $states] = overviewTestComposeLive(overviewTestDatasource());
        $c->getSignal('graph_sources')?->setValue(['gw']);

        $data = Shell::render($c, $app, $states, true);
        $html = $c->render('pages/overview.html.twig', $data);
        $cached = (new ReflectionProperty(TopNRepository::class, 'cache'))->getValue();
        $readsInRender = $reads;
        OverviewPage::computeTopN(OverviewPage::inputs($c), $this->now);

        expect($readsInRender)->toBe(0)
            ->and($cached)->toBe([])
            ->and($states->overview->topn)->toBeNull()
            ->and($data['pages']['overview']['topn']['state'])->toBe('loading')
            ->and($html)->toContain('Reading the precomputed lists')
            ->and($html)->not->toContain('10.0.0.1')
            ->and($data['graph']['totals'])->not->toBeNull()
            // The tap does see a range query.
            ->and($reads)->toBeGreaterThan(0)
        ;
    });

    test('the graph section carries the Overview controls on Overview only', function (): void {
        [$app, $c, $states] = overviewTestCompose();
        $overview = $c->render('shell/traffic-graph.html.twig', Shell::render($c, $app, $states, false));
        $c->getSignal('page')?->setValue('flows');
        $picker = $c->render('shell/traffic-graph.html.twig', Shell::render($c, $app, $states, true));

        expect($overview)->toContain('id="graphOptions"', 'id="graphOptionsToggle"', 'id="graphModeRrd"', 'id="brushToggle"', 'id="trafficGraph-series"', 'data-mode="overview"')
            ->and($picker)->not->toContain('id="graphOptions"', 'id="trafficGraph-series"')
            ->and($picker)->toContain('id="brushToggle"', 'data-mode="picker"', 'Traffic by protocol')
            ->and($overview . $picker)->not->toMatch('/class="[^"]*\b(muted|mono|strong|text-end|nowrap|upper|cluster-between|spacer|text-(danger|warning|success|info))\b/')
        ;
    });
});
