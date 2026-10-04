<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\actions\Helpers;
use mbolli\nfsen_ng\actions\QueryRunner;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Misc;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\pages\state\OverviewState;
use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\query\TopNQuery;
use mbolli\nfsen_ng\query\TopNResult;
use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Overview (was Graphs): the traffic graph's configuration, the KPI strip and the top-N card,
 * both answered from the precomputed top-N by the overview-topn action, never by a render (4.1).
 *
 * @phpstan-import-type TopN from OverviewState
 * @phpstan-import-type TopNRow from OverviewState
 * @phpstan-import-type Exact from OverviewState
 * @phpstan-import-type ExactRow from OverviewState
 * @phpstan-import-type StatRow from MultiStatCsvParser
 *
 * @phpstan-type Inputs array{start: int, end: int, live: bool, sources: list<string>, profile: string, protocol: string, tab: string, dir: string, limit: int, order: string}
 * @phpstan-type RowView array{rank: int, slot: int, label: string, ip: string, bytes: string, packets: string, flows: string, share: null|float, shareText: string, intervals: string}
 * @phpstan-type KpiView array{id: string, label: string, value: string, meta: string, kind: string, ip: string, approx: bool, busy: bool}
 */
final class OverviewPage implements Page {
    /** @var list<string> */
    public const array TABS = ['talkers', 'ports', 'protocols', 'asns', 'interfaces'];

    /** @var list<int> */
    public const array LIMITS = [10, 20, 50];

    /** Tab label, key column header, the precomputed statistic and the nfdump element per direction. */
    private const array TAB_META = [
        'talkers' => ['label' => 'Talkers', 'key' => 'Address', 'noun' => 'addresses', 'src' => TopNStat::SrcIp, 'dst' => TopNStat::DstIp, 'srcElement' => 'srcip', 'dstElement' => 'dstip'],
        'ports' => ['label' => 'Ports', 'key' => 'Port', 'noun' => 'ports', 'src' => TopNStat::SrcPort, 'dst' => TopNStat::DstPort, 'srcElement' => 'srcport', 'dstElement' => 'dstport'],
        'protocols' => ['label' => 'Protocols', 'key' => 'Protocol', 'noun' => 'protocols', 'src' => TopNStat::Proto, 'dst' => TopNStat::Proto, 'srcElement' => 'proto', 'dstElement' => 'proto'],
        'asns' => ['label' => 'ASNs', 'key' => 'AS', 'noun' => 'AS numbers', 'src' => TopNStat::SrcAs, 'dst' => TopNStat::DstAs, 'srcElement' => 'srcas', 'dstElement' => 'dstas'],
        'interfaces' => ['label' => 'Interfaces', 'key' => 'Interface', 'noun' => 'interfaces', 'src' => TopNStat::InIf, 'dst' => TopNStat::OutIf, 'srcElement' => 'inif', 'dstElement' => 'outif'],
    ];

    /** The protocol series slots (2.3): TCP 1, UDP 2, ICMP and ICMPv6 3. */
    private const array PROTOCOL_SLOTS = [6 => 1, 17 => 2, 1 => 3, 58 => 3];

    /** Below this share of collected intervals the card says how much of the range it covers. */
    private const float FULL_COVERAGE = 0.95;

    /** A live exact result older than this has fallen behind the window (1.7). */
    private const int LIVE_STALE = 300;

    public static function id(): string {
        return 'overview';
    }

    public static function title(): string {
        return 'Overview';
    }

    public static function lede(): string {
        return 'Traffic for the selected range, with the top talkers from precomputed data.';
    }

    public static function icon(): string {
        return 'chart-area';
    }

    public static function group(): string {
        return 'analysis';
    }

    public static function signals(Context $c): void {
        $c->signal(Config::$settings->defaultGraphDisplay, 'graph_display', clientWritable: true);
        $c->signal(Config::$settings->ports, 'graph_ports', clientWritable: true);
        $c->signal(Config::$settings->defaultGraphDatatype, 'graph_datatype', clientWritable: true);
        $c->signal(500, 'graph_resolution', clientWritable: true);
        // 'stored' plots the datasource's series; 'filtered' re-reads the capture files
        // through an nfdump filter, which only run-filtered-graph may do, never a render.
        $c->signal('stored', 'graph_mode', clientWritable: true);
        $c->signal('', 'graph_filter', clientWritable: true);
        $c->signal(false, 'graph_isLive', clientWritable: false);
        $c->signal(0, 'graph_actualResolution', clientWritable: false);
        $c->signal(0, 'graph_lastUpdate', clientWritable: false);
        $c->signal(0, 'graph_step', clientWritable: false);

        $c->signal('talkers', 'ov_tab', clientWritable: true);
        $c->signal('src', 'ov_dir', clientWritable: true);
        $c->signal(10, 'ov_limit', clientWritable: true);
        $c->signal('bytes', 'ov_order', clientWritable: true);
        $c->signal(false, '_ov_topn_pending', clientWritable: false);
        // Rows of the last exact run, for the completion announcement ("12 rows returned", 2.5).
        $c->signal(0, '_ov_exact_rows', clientWritable: false);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        GraphActions::register($c);
        $overview = $states->overview;

        // Computes the KPI cards and the table in a coroutine, from the stored top-N only (4.1.5).
        $c->action(static function (Context $c) use ($app, $overview): void {
            try {
                self::startTopN($c, $app, $overview);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('overview-topn failed: ' . $e->getMessage(), LOG_ERR);
                $c->getSignal('_error')?->setValue('Top of the range: ' . $e->getMessage());
                $c->sync();
            }
        }, 'overview-topn');

        // Out of retention (or with collection off) the same list comes from nfdump, on request.
        $c->action(static function (Context $c) use ($overview): void {
            try {
                self::startExact($c, $overview);
            } catch (\Throwable $e) {
                $overview->notifyFailure($e);
                $c->sync();
            }
        }, 'overview-topn-run');
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $overview = $states->overview;
        $in = self::inputs($c);
        $now = time();
        $display = GraphActions::display($c->getSignal('graph_display')?->string() ?? 'sources');
        $graphPorts = GraphActions::normalizePorts($c->getSignal('graph_ports')?->array() ?? []);
        $filtered = $c->getSignal('graph_mode')?->string() === 'filtered';
        // Rank chips carry a colour only for rows that are a series the graph draws (2.3): none
        // before Apply, after a failed fetch or without a point to draw.
        $drawn = $states->shell->graphStep > 0 && $states->shell->graphLegend !== [];
        $charted = match (true) {
            !$drawn => 'none',
            $filtered => $display === 'sources' ? 'sources' : 'protocols',
            default => $display,
        };

        return [
            'notifications' => $overview->notifications,
            'kpi' => self::kpiView($in, $overview->topn, $now, $c->getSignal('graph_trafficUnit')?->string() === 'bytes' ? 'bytes' : 'bits'),
            'topn' => self::topnView($in, $overview, $charted, $graphPorts, $now),
        ];
    }

    /**
     * The inputs of the top-N and the exact run, normalised: every one of them is client-writable.
     *
     * @return Inputs
     */
    public static function inputs(Context $c): array {
        $tab = $c->getSignal('ov_tab')?->getValue();
        $tab = \is_string($tab) && isset(self::TAB_META[$tab]) ? $tab : 'talkers';
        $dir = $c->getSignal('ov_dir')?->getValue();
        $limit = $c->getSignal('ov_limit')?->getValue();
        $limit = is_numeric($limit) && \in_array((int) $limit, self::LIMITS, true) ? (int) $limit : self::LIMITS[0];
        $order = $c->getSignal('ov_order')?->getValue();
        $start = $c->getSignal('datestart')?->int() ?? 0;

        return [
            'start' => $start,
            'end' => max($start, $c->getSignal('dateend')?->int() ?? 0),
            'live' => $c->getSignal('range_live')?->bool() ?? false,
            'sources' => self::sources($c->getSignal('graph_sources')?->array() ?? []),
            'profile' => self::profile($c),
            'protocol' => RangeControls::protocol($c),
            'tab' => $tab,
            // Protocols have no direction; one value keeps their fingerprint stable.
            'dir' => $tab !== 'protocols' && $dir === 'dst' ? 'dst' : 'src',
            'limit' => $limit,
            'order' => \is_string($order) && \in_array($order, TopNRepository::ORDER_BY, true) ? $order : 'bytes',
        ];
    }

    /**
     * What the KPI cards depend on: the window in 5 minute intervals, the sources and the profile.
     *
     * @param Inputs $in
     */
    public static function kpiFingerprint(array $in): string {
        $sources = $in['sources'];
        sort($sources);

        return implode("\x1f", [intdiv($in['start'], TopNRepository::INTERVAL), intdiv($in['end'], TopNRepository::INTERVAL), implode(',', $sources), $in['profile']]);
    }

    /**
     * The KPI inputs plus the table's tab, direction, limit and order.
     *
     * @param Inputs $in
     */
    public static function fingerprint(array $in): string {
        return implode("\x1f", [self::kpiFingerprint($in), $in['tab'], $in['dir'], $in['limit'], $in['order']]);
    }

    /**
     * The exact run's fingerprint encodes a live window by its width, so a live result does
     * not turn stale while the window follows the clock (1.7).
     *
     * @param Inputs $in
     */
    public static function exactFingerprint(array $in): string {
        $sources = $in['sources'];
        sort($sources);
        $window = $in['live']
            ? 'live:' . ($in['end'] - $in['start'])
            : intdiv($in['start'], TopNRepository::INTERVAL) . ':' . intdiv($in['end'], TopNRepository::INTERVAL);

        return implode("\x1f", [$window, implode(',', $sources), $in['profile'], $in['protocol'], $in['tab'], $in['dir'], $in['limit'], $in['order']]);
    }

    /** The precomputed statistic of a tab and direction. */
    public static function stat(string $tab, string $dir): TopNStat {
        $meta = self::TAB_META[$tab] ?? self::TAB_META['talkers'];

        return $dir === 'dst' ? $meta['dst'] : $meta['src'];
    }

    /** The nfdump -s element of a tab and direction, for the exact run. */
    public static function element(string $tab, string $dir): string {
        $meta = self::TAB_META[$tab] ?? self::TAB_META['talkers'];

        return $dir === 'dst' ? $meta['dstElement'] : $meta['srcElement'];
    }

    /** Whether the window starts before the retained top-N; retention 0 means collection is off. */
    public static function outsideRetention(int $start, int $retentionDays, int $now): bool {
        return $retentionDays > 0 && $start < $now - $retentionDays * 86400;
    }

    /**
     * The KPI and table answer for these inputs, read from the stored top-N. Never throws for
     * a missing store: that becomes the 'unavailable' state with its reason.
     *
     * @param Inputs               $in
     * @param null|\Closure():void $yield called between the chunks of each range query
     *
     * @return TopN
     */
    public static function computeTopN(array $in, int $now, ?\Closure $yield = null, ?TopNRepository $repo = null): array {
        $retention = Config::$settings->topnRetentionDays;
        $answer = self::answer($in, $now, 'ok');
        if ($retention <= 0) {
            return self::answer($in, $now, 'disabled');
        }
        if (self::outsideRetention($in['start'], $retention, $now)) {
            return self::answer($in, $now, 'outside');
        }

        try {
            $repo ??= new TopNRepository(Database::shared());
        } catch (StoreUnavailableException $e) {
            return self::answer($in, $now, 'unavailable', $e->reason);
        }

        $window = TimeWindow::raw($in['start'], $in['end']);
        $run = static fn (TopNStat $stat, int $limit, string $order): TopNResult => (new TopNQuery($window, $in['sources'], $in['profile'], $stat, $limit, $order))
            ->run($repo, $retention, $now, $yield)
        ;
        $stat = self::stat($in['tab'], $in['dir']);
        $table = $run($stat, $in['limit'], $in['order']);
        $kpi = [
            'src' => $run(TopNStat::SrcIp, 1, 'bytes')->rows[0] ?? null,
            'dst' => $run(TopNStat::DstIp, 1, 'bytes')->rows[0] ?? null,
            'proto' => $run(TopNStat::Proto, 1, 'bytes')->rows[0] ?? null,
        ];

        $intervals = intdiv(max(0, $window->end - $window->end % TopNRepository::INTERVAL - ($window->start - $window->start % TopNRepository::INTERVAL)), TopNRepository::INTERVAL);

        return [
            ...$answer,
            'state' => $table->totals['intervals'] === 0 ? 'collecting' : ($table->rows === [] ? 'empty' : 'ok'),
            'kpi' => $kpi,
            'table' => [
                'tab' => $in['tab'],
                'dir' => $in['dir'],
                'rows' => $table->rows,
                'exactIntervals' => $table->exactIntervals,
                'expected' => $intervals * ($stat->perSource() ? 1 : max(1, \count(array_unique($in['sources'])))),
                'coverage' => $table->coverage,
            ],
        ];
    }

    /**
     * An answer without rows, in the given state.
     *
     * @param Inputs $in
     *
     * @return TopN
     */
    public static function answer(array $in, int $now, string $state, string $reason = ''): array {
        return [
            'fingerprint' => self::fingerprint($in),
            'kpiFingerprint' => self::kpiFingerprint($in),
            'computedAt' => $now,
            'state' => $state,
            'reason' => $reason,
            'kpi' => ['src' => null, 'dst' => null, 'proto' => null],
            'table' => ['tab' => $in['tab'], 'dir' => $in['dir'], 'rows' => [], 'exactIntervals' => false, 'expected' => 0, 'coverage' => 0.0],
        ];
    }

    /**
     * A key as the card shows it (4.1.6): "443/tcp (https)", "TCP (6)", "AS64512",
     * "0 (not exported)", "core · if 3".
     */
    public static function keyLabel(string $tab, string $key, string $source = ''): string {
        return match ($tab) {
            'ports' => self::portLabel($key),
            'protocols' => self::protocolName($key) . (ctype_digit($key) ? ' (' . $key . ')' : ''),
            'asns' => $key === '0' ? '0 (not exported)' : (ctype_digit($key) ? 'AS' . $key : $key),
            'interfaces' => ($source !== '' ? $source . ' · ' : '') . 'if ' . $key,
            default => $key,
        };
    }

    /** "TCP" for 6, the protocol database's name for others, the number when it has none. */
    public static function protocolName(string $key): string {
        if (!ctype_digit($key)) {
            return strtoupper($key);
        }
        $name = match ((int) $key) {
            1 => 'icmp',
            6 => 'tcp',
            17 => 'udp',
            58 => 'icmpv6',
            default => getprotobynumber((int) $key),
        };

        return \is_string($name) && $name !== '' ? strtoupper($name) : $key;
    }

    /**
     * The chip slot of a row (2.3): only a row that is exactly a series the graph draws gets
     * one, so a protocol row in the Protocols display and a configured port's row in the
     * Ports display; 0 everywhere else.
     *
     * @param list<int> $graphPorts the ports the Ports display draws
     */
    public static function rankSlot(string $tab, string $key, string $display, array $graphPorts): int {
        if ($tab === 'protocols' && $display === 'protocols' && ctype_digit($key)) {
            return self::PROTOCOL_SLOTS[(int) $key] ?? 0;
        }
        if ($tab === 'ports' && $display === 'ports') {
            $port = (int) explode('/', $key, 2)[0];
            $index = array_search($port, Config::$settings->ports, true);
            if ($index !== false && $index < 8 && \in_array($port, $graphPorts, true)) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * The four KPI cards (4.1.5). Total traffic comes from the traffic graph's totals, which the
     * template reads itself; the three top-N cards from the stored answer, their volumes in the
     * global unit (D5).
     *
     * @param Inputs    $in
     * @param null|TopN $topn
     *
     * @return list<KpiView>
     */
    public static function kpiView(array $in, ?array $topn, int $now, string $unit = 'bytes'): array {
        $retention = Config::$settings->topnRetentionDays;
        $state = match (true) {
            $retention <= 0 => 'Top-N disabled',
            self::outsideRetention($in['start'], $retention, $now) => 'Outside the ' . $retention . ' day window',
            default => null,
        };
        $current = $topn !== null && $topn['kpiFingerprint'] === self::kpiFingerprint($in);
        $cards = [];
        foreach (['src' => 'Top source', 'dst' => 'Top destination', 'proto' => 'Top protocol'] as $which => $label) {
            $card = ['id' => 'kpi-' . $which, 'label' => $label, 'value' => '', 'meta' => '', 'kind' => 'state', 'ip' => '', 'approx' => false, 'busy' => false];
            $row = $topn['kpi'][$which] ?? null;
            // An answer for other inputs is shown dimmed only when it has a figure to show.
            $text = $state ?? match (true) {
                $row !== null => null,
                !$current => 'Computing',
                default => self::stateText($topn),
            };
            if ($text !== null) {
                $cards[] = [...$card, 'value' => $text, 'busy' => $state === null && !$current];

                continue;
            }
            \assert($row !== null);
            $share = number_format($row['share'], 1) . '%';
            $cards[] = [
                ...$card,
                'value' => $which === 'proto' ? self::protocolName($row['key']) : $row['key'],
                'meta' => $which === 'proto' ? $share . ' of bytes' : Misc::formatVolume((float) $row['bytes'], $unit) . ' · ' . $share,
                'kind' => $which === 'proto' ? '' : 'address',
                'ip' => $which === 'proto' ? '' : $row['key'],
                'approx' => true,
                'busy' => !$current,
            ];
        }

        return $cards;
    }

    /**
     * The top-N card (4.1.6): its controls, the stored table or the exact run, and the notes.
     *
     * @param Inputs    $in
     * @param list<int> $graphPorts
     *
     * @return array<string, mixed>
     */
    public static function topnView(array $in, OverviewState $overview, string $display, array $graphPorts, int $now): array {
        $retention = Config::$settings->topnRetentionDays;
        $topn = $overview->topn;
        $tabs = array_map(
            static fn (string $id): array => ['id' => $id, 'label' => self::TAB_META[$id]['label'], 'selected' => $id === $in['tab']],
            self::TABS,
        );

        $current = $topn !== null && $topn['fingerprint'] === self::fingerprint($in);
        $state = match (true) {
            $retention <= 0 => 'disabled',
            self::outsideRetention($in['start'], $retention, $now) => 'outside',
            // Nothing yet, or an answer for other inputs with no rows to show dimmed.
            $topn === null, !$current && $topn['table']['rows'] === [] => 'loading',
            $topn['state'] === 'ok' => $topn['table']['rows'] === [] ? 'empty' : 'table',
            \in_array($topn['state'], ['unavailable', 'error'], true) => 'unavailable',
            \in_array($topn['state'], ['collecting', 'empty'], true) => $topn['state'],
            // Answered for a window out of retention or with collection off: not these inputs.
            default => 'loading',
        };
        $shown = $topn['table'] ?? null;
        $shownTab = $shown['tab'] ?? $in['tab'];
        $rows = [];
        if ($state === 'table' && $shown !== null) {
            foreach ($shown['rows'] as $i => $row) {
                $rows[] = self::rowView($i + 1, $shownTab, $row['key'], $row['source'], $row['flows'], $row['packets'], $row['bytes'], $row['share'], $display, $graphPorts)
                    + ['intervals' => $shown['exactIntervals'] ? $row['intervals'] . ' of ' . $shown['expected'] : ''];
            }
        }

        $notes = [];
        if ($state === 'table' && $shown !== null && $shown['coverage'] < self::FULL_COVERAGE) {
            $notes[] = 'Covers ' . (int) floor($shown['coverage'] * 100) . "% of the range's 5 minute intervals.";
        }
        if (\in_array($state, ['table', 'empty'], true)) {
            $notes[] = 'Ranked by bytes within each 5 minute interval; ' . self::TAB_META[$shownTab]['noun'] . " outside an interval's top " . TopNRepository::TOP . ' are not counted.';
        }
        if ($in['protocol'] !== 'any' && \in_array($state, ['table', 'empty', 'collecting'], true)) {
            $notes[] = 'The protocol filter does not apply to precomputed lists.';
        }

        $exactRun = \in_array($state, ['disabled', 'outside', 'unavailable'], true);

        return [
            'tabs' => $tabs,
            'tab' => $in['tab'],
            'dir' => $in['dir'],
            'hasDir' => $in['tab'] !== 'protocols',
            'dirLabels' => $in['tab'] === 'interfaces' ? ['In', 'Out'] : ['Source', 'Destination'],
            'limit' => $in['limit'],
            'order' => $in['order'],
            'keyHeader' => self::TAB_META[$shownTab]['key'],
            'state' => $state,
            'reason' => match ($state) {
                'disabled' => 'Top-N collection is off (NFSEN_TOPN_RETENTION_DAYS is 0).',
                'outside' => 'This range starts before the ' . $retention . ' days of precomputed top-N data.',
                'unavailable' => 'Top-N unavailable: ' . ($topn['reason'] ?? ''),
                'collecting' => 'Collecting: the first data arrives after the next import.',
                'empty' => 'The precomputed lists hold no flows for this range.',
                default => '',
            },
            // The table is dimmed while it answers other inputs than the current ones.
            'busy' => $state === 'loading' || ($topn !== null && !$exactRun && !$current),
            'rows' => $rows,
            'exactIntervals' => $state === 'table' && ($shown['exactIntervals'] ?? false),
            'notes' => $notes,
            'exactRun' => $exactRun,
            'exact' => $exactRun ? self::exactView($in, $overview->exact, $display, $graphPorts, $now) : null,
        ];
    }

    /**
     * nfdump's csv rows as the exact table keeps them. The share is nfdump's own bytP, so rows
     * and denominator come from the same capture files (the stored series outlive them).
     *
     * @param list<StatRow> $rows
     *
     * @return list<ExactRow>
     */
    public static function exactRows(array $rows): array {
        return array_map(static fn (array $row): array => [
            'key' => $row['key'],
            'flows' => $row['flows'],
            'packets' => $row['packets'],
            'bytes' => $row['bytes'],
            'share' => $row['bytesPct'] !== null ? min(100.0, $row['bytesPct']) : null,
        ], $rows);
    }

    /**
     * The exact run's query (4.1.6): the tab's element over the same intervals the stored lists
     * cover, [start, end) in 5 minute steps, so the two agree (nfdump's -R includes its end file).
     *
     * @param Inputs $in
     */
    public static function exactQuery(array $in, string $handle): StatsQuery {
        $start = $in['start'] - $in['start'] % TopNRepository::INTERVAL;
        $end = $in['end'] - $in['end'] % TopNRepository::INTERVAL;

        return new StatsQuery(
            window: TimeWindow::clamped($start, max($start, $end - 1)),
            sources: $in['sources'],
            profile: $in['profile'],
            for: self::element($in['tab'], $in['dir']),
            orderBy: $in['order'],
            limit: $in['limit'],
            handle: $handle,
            protocol: $in['protocol'],
            output: 'csv',
        );
    }

    /**
     * Marks the tab pending and computes in a coroutine; a request for the answer already in
     * flight is dropped, and only the newest request writes its answer.
     */
    private static function startTopN(Context $c, Via $app, OverviewState $overview): void {
        $in = self::inputs($c);
        $fingerprint = self::fingerprint($in);
        if ($overview->topnRunning === $fingerprint) {
            return;
        }
        $overview->topnRunning = $fingerprint;
        $ticket = ++$overview->topnTicket;
        $pending = $c->getSignal('_ov_topn_pending');
        $pending?->setValue(true);
        self::push($c, $app, full: false);

        Coroutine::create(static function () use ($c, $app, $overview, $in, $ticket, $pending): void {
            try {
                $answer = self::computeTopN($in, time(), static function (): void {
                    Coroutine::usleep(1000);
                });
            } catch (\Throwable $e) {
                Debug::getInstance()->log('overview-topn failed: ' . $e->getMessage(), LOG_ERR);
                $answer = self::answer($in, time(), 'error', $e->getMessage());
            }

            try {
                // Only the newest request settles the tab, so a superseded one cannot clear the guard.
                if ($ticket === $overview->topnTicket) {
                    $overview->topn = $answer;
                    $pending?->setValue(false);
                    $overview->topnRunning = '';
                }
                self::push($c, $app, full: true);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('overview-topn sync failed: ' . $e->getMessage(), LOG_ERR);
            }
        });
    }

    /**
     * The exact run out of retention, through QueryRunner (progress, Kill, recorded throughput),
     * split into parallel nfdump processes when that pays.
     */
    private static function startExact(Context $c, OverviewState $overview): void {
        $in = self::inputs($c);
        $contextId = $c->getId();
        $query = self::exactQuery($in, $contextId);
        $fingerprint = self::exactFingerprint($in);
        $overview->clearNotifications();
        if ($query->window->clamped) {
            $overview->notify('warning', $query->window->clampNotice());
        }

        $count = $c->getSignal('_ov_exact_rows');
        QueryRunner::run($c, 'overview-topn', static fn (): int => $query->totalBytes(), 'Starting nfdump…', static function (?\Closure $onSplit = null) use ($query, $in, $overview, $fingerprint, $contextId, $count): void {
            try {
                $result = $query->runPartitioned('overview-topn', null, $onSplit, static fn (): bool => QueryCancel::isRequested($contextId));
                $rows = self::exactRows($query->statRows($result));
                $count?->setValue(\count($rows));
                $overview->exact = [
                    'fingerprint' => $fingerprint,
                    'computedAt' => time(),
                    'live' => $in['live'],
                    'tab' => $in['tab'],
                    'dir' => $in['dir'],
                    'start' => $query->window->start,
                    'end' => $query->window->end + 1,
                    'rows' => $rows,
                    'command' => $result->command,
                ];
                if ($result->stderr !== '') {
                    $overview->notify('warning', 'nfdump warning:', $result->stderr);
                }
            } catch (\Throwable $e) {
                if (!QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId))) {
                    Debug::getInstance()->log('overview-topn-run failed: ' . $e->getMessage(), LOG_ERR);
                    $overview->notifyFailure($e);
                }
                $overview->exact = null;

                // QueryRunner owns the status line, which says the run failed.
                throw $e;
            }
        });
    }

    /**
     * The exact result for the current inputs, if the tab has one.
     *
     * @param Inputs     $in
     * @param null|Exact $exact
     * @param list<int>  $graphPorts
     *
     * @return null|array<string, mixed>
     */
    private static function exactView(array $in, ?array $exact, string $display, array $graphPorts, int $now): ?array {
        if ($exact === null || $exact['fingerprint'] !== self::exactFingerprint($in)) {
            return null;
        }
        $rows = [];
        foreach ($exact['rows'] as $i => $row) {
            $rows[] = self::rowView($i + 1, $exact['tab'], $row['key'], '', $row['flows'], $row['packets'], $row['bytes'], $row['share'], $display, $graphPorts) + ['intervals' => ''];
        }

        return [
            'rows' => $rows,
            'command' => $exact['command'],
            'start' => $exact['start'],
            'end' => $exact['end'],
            'movedOn' => $exact['live'] && $now - $exact['computedAt'] > self::LIVE_STALE,
        ];
    }

    /**
     * @param list<int> $graphPorts
     *
     * @return array{rank: int, slot: int, label: string, ip: string, bytes: string, packets: string, flows: string, share: null|float, shareText: string}
     */
    private static function rowView(int $rank, string $tab, string $key, string $source, int $flows, int $packets, int $bytes, ?float $share, string $display, array $graphPorts): array {
        return [
            'rank' => $rank,
            'slot' => self::rankSlot($tab, $key, $display, $graphPorts),
            'label' => self::keyLabel($tab, $key, $source),
            'ip' => $tab === 'talkers' ? $key : '',
            'bytes' => Misc::formatVolume((float) $bytes, 'bytes'),
            'packets' => Misc::formatCount((float) $packets),
            'flows' => Misc::formatCount((float) $flows),
            'share' => $share,
            'shareText' => $share !== null ? number_format($share, 1) . '%' : '',
        ];
    }

    /** @param null|TopN $topn the answer for the current inputs */
    private static function stateText(?array $topn): string {
        return match ($topn['state'] ?? null) {
            'collecting' => 'Collecting (first data after the next import)',
            'unavailable', 'error' => 'Top-N unavailable: ' . ($topn['reason'] ?? ''),
            'ok', 'empty' => 'No flows in the precomputed lists for this range',
            default => 'Computing',
        };
    }

    /** "443/tcp (https)"; a bare port from nfdump stays a number. */
    private static function portLabel(string $key): string {
        [$port, $proto] = array_pad(explode('/', $key, 2), 2, '');
        if (!ctype_digit($port) || !\in_array($proto, ['tcp', 'udp'], true)) {
            return $key;
        }
        $service = getservbyport((int) $port, $proto);

        return \is_string($service) && $service !== '' ? $key . ' (' . $service . ')' : $key;
    }

    /**
     * Configured sources only: graph_sources is client-writable and reaches SQLite and nfdump.
     *
     * @param array<int|string, mixed> $selected
     *
     * @return list<string>
     */
    private static function sources(array $selected): array {
        $configured = Config::$settings->sources;
        $known = array_values(array_intersect(Helpers::resolveSources($selected), $configured));

        return $known !== [] ? $known : $configured;
    }

    /** The selected profile when the server listed it, else the configured one. */
    private static function profile(Context $c): string {
        $selected = $c->getSignal('selected_profile')?->string() ?? '';
        $available = $c->getSignal('available_profiles')?->array() ?? [];

        return \in_array($selected, $available, true) ? $selected : Config::$settings->nfdumpProfile;
    }

    /**
     * Before this tab's SSE stream is up a patch would be dropped; the connect sync renders the
     * stored answer instead.
     */
    private static function push(Context $c, Via $app, bool $full): void {
        if (!$c->isConnected()) {
            return;
        }
        $full ? $c->sync() : $c->syncSignals();
    }
}
