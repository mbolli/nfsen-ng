<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\Misc;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\datasources\TotalsProvider;
use mbolli\nfsen_ng\pages\state\ShellState;
use mbolli\nfsen_ng\query\TimelineQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Shell module for the persistent traffic graph above the analysis pages (1.8, 4.1.3): the
 * Overview configuration on Overview, the protocol picker on Top Talkers and Flows, one total
 * series on Conversations, nothing elsewhere.
 *
 * @phpstan-import-type GraphData from Datasource
 * @phpstan-import-type Totals from TotalsProvider
 */
final class TrafficGraph implements ShellModule {
    /** Points of the picker series (1.8). */
    public const int PICKER_POINTS = 300;

    private const array DISPLAY_NOUNS = ['sources' => 'source', 'protocols' => 'protocol', 'ports' => 'port'];

    private const array PROTOCOL_NAMES = ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'other' => 'Other', 'any' => 'Total'];

    /**
     * Per tab: what the last fetch was for and why it failed, so a changed input fetches even
     * while an import throttles the graph, and the KPI totals with the inputs they were read for.
     *
     * @var null|\WeakMap<ShellState, array{key: string, failed: string, totalsKey: string, totals: null|Totals, totalsError: string}>
     */
    private static ?\WeakMap $cache = null;

    public static function signals(Context $c): void {}

    public static function register(Context $c, Via $app, PageStates $states): void {}

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $mode = self::mode($activePage);
        $shell = $states->shell;
        $now = time();
        $importing = $c->getSignal('import_running')?->bool() ?? false;
        $fatal = Shell::fatal($app);
        $cache = self::cacheFor($shell);

        if ($mode !== 'none' && !$fatal) {
            $key = self::fetchKey($c, $mode);
            if ($key !== $cache['key'] || $shell->graphDue($importing, $now)) {
                $cache['failed'] = self::fetch($c, $states, $mode, $now);
                $cache['key'] = $key;
            }
            if ($mode === 'overview') {
                // While the last fetch failed, the datasource is not asked for the totals as well.
                $cache = $cache['failed'] === ''
                    ? self::withTotals($c, $cache)
                    : [...$cache, 'totalsKey' => '', 'totals' => null, 'totalsError' => $cache['failed']];
            }
            self::$cache ??= new \WeakMap();
            self::$cache[$shell] = $cache;
        }

        $datatype = $c->getSignal('graph_datatype')?->string() ?? 'traffic';
        $display = GraphActions::display($c->getSignal('graph_display')?->string() ?? 'sources');
        $filtered = $mode === 'overview' && $c->getSignal('graph_mode')?->string() === 'filtered';
        $points = $c->getSignal('graph_actualResolution')?->int() ?? 0;
        $title = self::title($mode, $datatype, $display);
        $modeLabel = self::modeLabel($filtered, $points, $shell->graphStep);
        $legend = $mode === 'none' ? [] : $shell->graphLegend;
        $seriesDisplay = $mode === 'overview' ? ($filtered && $display === 'ports' ? 'protocols' : $display) : 'protocols';

        return [
            'mode' => $mode,
            'data' => $mode === 'none' ? '' : $shell->graphJson,
            'config' => json_encode(self::config($c, $mode, $seriesDisplay, $legend, $shell->graphStep), JSON_THROW_ON_ERROR),
            'title' => $title,
            'modeLabel' => $modeLabel,
            'height' => $mode === 'overview' ? 'tall' : 'short',
            'seriesKeys' => $legend,
            'live' => ($c->getSignal('range_live')?->bool() ?? false) && !$filtered,
            'filtered' => $filtered,
            'points' => $points,
            'step' => $shell->graphStep,
            'lastUpdate' => $c->getSignal('graph_lastUpdate')?->int() ?? 0,
            'ariaLabel' => $title . ', ' . $modeLabel,
            'totals' => $cache['totals'],
            'totalsText' => $cache['totals'] !== null
                ? Misc::formatVolume($cache['totals']['bytes'], $c->getSignal('graph_trafficUnit')?->string() === 'bytes' ? 'bytes' : 'bits')
                : '',
            'totalsError' => $cache['totalsError'],
            'totalsAvailable' => !$fatal && Config::$db instanceof TotalsProvider,
        ];
    }

    /**
     * 'overview' on Overview, 'picker' on Top Talkers and Flows, 'picker-total' on
     * Conversations (D25), 'none' off the analysis pages (1.2).
     */
    public static function mode(string $activePage): string {
        if (!PageRegistry::rendersAnalysis($activePage)) {
            return 'none';
        }

        return match ($activePage) {
            TalkersPage::id(), FlowsPage::id() => 'picker',
            ConversationsPage::id() => 'picker-total',
            default => 'overview',
        };
    }

    public static function title(string $mode, string $datatype, string $display): string {
        return match ($mode) {
            'picker' => 'Traffic by protocol',
            'picker-total' => 'Total traffic',
            default => match ($datatype) {
                'packets' => 'Packets',
                'flows' => 'Flows',
                default => 'Traffic',
            } . ' by ' . (self::DISPLAY_NOUNS[$display] ?? 'source'),
        };
    }

    /** "Stored data · 5 min resolution", "Filtered data · 30 min bins", "Filtered data · press Apply filter" (4.1.2). */
    public static function modeLabel(bool $filtered, int $points, int $step): string {
        if ($filtered) {
            if ($points === 0) {
                return 'Filtered data · press Apply filter';
            }

            return $step > 0 ? 'Filtered data · ' . self::humanStep($step) . ' bins' : 'Filtered data';
        }

        return $step > 0 ? 'Stored data · ' . self::humanStep($step) . ' resolution' : 'Stored data';
    }

    public static function humanStep(int $step): string {
        return match (true) {
            $step % 86400 === 0 => ($step / 86400) . ' day' . ($step === 86400 ? '' : 's'),
            $step % 3600 === 0 => ($step / 3600) . ' h',
            default => max(1, (int) round($step / 60)) . ' min',
        };
    }

    /**
     * The fixed series position of each legend key (2.3): configured source or port order,
     * TCP 1, UDP 2, ICMP 3, Other 4; 0 for a key that has none (the neutral colour). A
     * position above 8 is past the palette, which the chart resolves per style.
     *
     * @param list<string> $legend
     *
     * @return list<int>
     */
    public static function seriesSlots(string $display, array $legend): array {
        return array_map(static fn (string $key): int => self::entity($display, $key)['slot'], $legend);
    }

    /**
     * Readable series names: the source, the port, or the protocol.
     *
     * @param list<string> $legend
     *
     * @return list<string>
     */
    public static function seriesNames(string $display, array $legend): array {
        $names = array_map(static fn (string $key): string => self::entity($display, $key)['name'], $legend);

        // ECharts identifies series by name, so a collision keeps the raw keys.
        return \count(array_unique($names)) === \count($names) ? $names : $legend;
    }

    /**
     * Stored volume of the window for the KPI card, or why there is none.
     *
     * @param list<string> $sources
     *
     * @return array{totals: null|Totals, error: string}
     */
    public static function readTotals(array $sources, string $profile, int $start, int $end, string $protocol): array {
        if (!Config::$db instanceof TotalsProvider) {
            return ['totals' => null, 'error' => ''];
        }

        try {
            return ['totals' => Config::$db->fetchTotals($sources, $profile, $start, $end, $protocol), 'error' => ''];
        } catch (\Throwable $e) {
            return ['totals' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return array{slot: int, name: string} */
    private static function entity(string $display, string $key): array {
        $head = explode('_', $key, 2)[0];

        if ($display === 'protocols') {
            $index = array_search($head, GraphActions::PROTOCOL_SERIES, true);

            return ['slot' => $index === false ? 0 : $index + 1, 'name' => self::PROTOCOL_NAMES[$head] ?? $key];
        }

        if ($display === 'ports') {
            $index = is_numeric($head) ? array_search((int) $head, Config::$settings->ports, true) : false;

            return ['slot' => $index === false ? 0 : $index + 1, 'name' => $head];
        }

        // Source names may hold underscores themselves, so the longest match wins.
        $best = null;
        foreach (Config::$settings->sources as $index => $source) {
            if (($key === $source || str_starts_with($key, $source . '_')) && ($best === null || \strlen($source) > \strlen(Config::$settings->sources[$best]))) {
                $best = $index;
            }
        }

        return $best === null ? ['slot' => 0, 'name' => $key] : ['slot' => $best + 1, 'name' => Config::$settings->sources[$best]];
    }

    /** @return array{key: string, failed: string, totalsKey: string, totals: null|Totals, totalsError: string} */
    private static function cacheFor(ShellState $shell): array {
        self::$cache ??= new \WeakMap();

        return self::$cache[$shell] ?? ['key' => '', 'failed' => '', 'totalsKey' => '', 'totals' => null, 'totalsError' => ''];
    }

    /** Everything a fetch depends on, so a change fetches whatever the import throttle says. */
    private static function fetchKey(Context $c, string $mode): string {
        $values = [$mode];
        foreach (['datestart', 'dateend', 'graph_sources', 'selected_profile', 'protocol', 'graph_trafficUnit'] as $name) {
            $values[] = $c->getSignal($name)?->getValue();
        }
        if ($mode === 'overview') {
            foreach (['graph_display', 'graph_ports', 'graph_datatype', 'graph_resolution', 'graph_mode', 'graph_filter'] as $name) {
                $values[] = $c->getSignal($name)?->getValue();
            }
            // A filtered build that lands during an import must show without waiting for the throttle.
            if ($c->getSignal('graph_mode')?->string() === 'filtered') {
                $key = GraphActions::filteredKey($c);
                $values[] = FilteredGraphCache::has($key) ? 'full' : (FilteredGraphCache::get($key) !== null ? 'partial' : 'none');
            }
        }

        return md5(serialize($values));
    }

    /**
     * The stored totals for the KPI card, read again only when the window moves to another
     * 5 minute interval, an input changes or an import lands (the graph's last write).
     *
     * @param array{key: string, failed: string, totalsKey: string, totals: null|Totals, totalsError: string} $cache
     *
     * @return array{key: string, failed: string, totalsKey: string, totals: null|Totals, totalsError: string}
     */
    private static function withTotals(Context $c, array $cache): array {
        $start = $c->getSignal('datestart')?->int() ?? 0;
        $end = $c->getSignal('dateend')?->int() ?? 0;
        $sources = GraphActions::normalizeSources($c->getSignal('graph_sources')?->array() ?? [], 'sources');
        $profile = $c->getSignal('selected_profile')?->string() ?? Config::$settings->nfdumpProfile;
        $protocol = RangeControls::protocol($c);
        // In buckets, since a datasource without a last write reports the time of the fetch.
        $written = intdiv($c->getSignal('graph_lastUpdate')?->int() ?? 0, 300);
        $key = implode("\x1f", [intdiv($start, 300), intdiv($end, 300), implode(',', $sources), $profile, $protocol, $written]);
        if ($key === $cache['totalsKey']) {
            return $cache;
        }

        $read = self::readTotals($sources, $profile, $start, $end, $protocol);

        return [...$cache, 'totalsKey' => $key, 'totals' => $read['totals'], 'totalsError' => $read['error']];
    }

    /**
     * Fetches the series of the mode. A throw here would leave the graph silently frozen on
     * its previous data (#160), so it surfaces as a banner and the next render retries.
     *
     * @return string why the datasource failed, '' when it answered
     */
    private static function fetch(Context $c, PageStates $states, string $mode, int $now): string {
        $shell = $states->shell;
        $failed = '';

        try {
            $data = $mode === 'overview' ? GraphActions::fetchGraphData($c) : self::fetchPicker($c, $mode);
            $shell->graphJson = json_encode($data, JSON_THROW_ON_ERROR);
            $shell->graphLegend = $data['legend'] ?? [];
            $shell->graphStep = ($data['data'] ?? []) === [] ? 0 : ($data['step'] ?? 0);
            // Stored mode answers [] only when the datasource failed; fetchGraphData set _error.
            if ($data === [] && $mode === 'overview' && $c->getSignal('graph_mode')?->string() !== 'filtered') {
                $failed = self::errorText($c);
            } elseif ($mode !== 'overview') {
                GraphActions::clearOwnError($c->getSignal('_error'));
            }
        } catch (\Throwable $e) {
            $c->getSignal('_error')?->setValue('Graph error: ' . $e->getMessage());
            $shell->graphJson = '[]';
            $shell->graphLegend = [];
            $shell->graphStep = 0;
            $failed = $e->getMessage();
        }
        $shell->graphFetchedAt = $now;

        return $failed;
    }

    private static function errorText(Context $c): string {
        $error = $c->getSignal('_error')?->string() ?? '';
        $error = str_starts_with($error, 'Graph error: ') ? substr($error, \strlen('Graph error: ')) : $error;

        return $error !== '' ? $error : 'the datasource did not answer';
    }

    /**
     * The picker series (1.8): the stored protocol split over the selected sources, or one
     * total series on Conversations.
     *
     * @return GraphData
     */
    private static function fetchPicker(Context $c, string $mode): array {
        $protocol = RangeControls::protocol($c);
        $protocols = $mode === 'picker-total' || $protocol !== 'any' ? [$protocol] : GraphActions::PROTOCOL_SERIES;
        $query = new TimelineQuery(
            window: TimeWindow::raw($c->getSignal('datestart')?->int() ?? 0, $c->getSignal('dateend')?->int() ?? 0),
            sources: GraphActions::normalizeSources($c->getSignal('graph_sources')?->array() ?? [], 'protocols'),
            protocols: $protocols,
            unit: GraphActions::unit('traffic', $c->getSignal('graph_trafficUnit')?->string() ?? 'bits'),
            display: 'protocols',
            resolution: self::PICKER_POINTS,
            profile: $c->getSignal('selected_profile')?->string() ?? Config::$settings->nfdumpProfile,
        );
        $data = $query->run();

        $c->getSignal('graph_isLive')?->setValue($c->getSignal('range_live')?->bool() ?? false);
        $c->getSignal('graph_actualResolution')?->setValue(\count($data['data']));
        $c->getSignal('graph_step')?->setValue($data['data'] === [] ? 0 : $data['step']);
        $lastWrite = $query->lastWrite();
        $c->getSignal('graph_lastUpdate')?->setValue($lastWrite > 0 ? $lastWrite : time());

        return $data;
    }

    /**
     * The server-known part of the chart configuration; the style toggles are client-local.
     *
     * @param list<string> $legend
     *
     * @return array<string, mixed>
     */
    private static function config(Context $c, string $mode, string $seriesDisplay, array $legend, int $step): array {
        $datatype = $mode === 'overview' ? ($c->getSignal('graph_datatype')?->string() ?? 'traffic') : 'traffic';
        $trafficUnit = $c->getSignal('graph_trafficUnit')?->string() === 'bytes' ? 'bytes' : 'bits';

        return [
            'mode' => $mode,
            'display' => $seriesDisplay,
            'type' => \in_array($datatype, ['packets', 'flows'], true) ? $datatype : 'traffic',
            'unit' => GraphActions::unit($datatype, $trafficUnit),
            'trafficUnit' => $trafficUnit,
            'step' => $step,
            // A refresh of the same window keeps a zoomed preview; a live one is known by its width.
            'window' => ($c->getSignal('range_live')?->bool() ?? false)
                ? 'live:' . (($c->getSignal('dateend')?->int() ?? 0) - ($c->getSignal('datestart')?->int() ?? 0))
                : ($c->getSignal('datestart')?->int() ?? 0) . ':' . ($c->getSignal('dateend')?->int() ?? 0),
            'seriesSlots' => $mode === 'picker-total' ? array_fill(0, \count($legend), 0) : self::seriesSlots($seriesDisplay, $legend),
            'seriesNames' => $mode === 'picker-total'
                ? array_map(static fn (string $key): string => self::entity('protocols', $key)['name'], $legend)
                : self::seriesNames($seriesDisplay, $legend),
            'displayTz' => $c->getSignal('displayTz')?->string() ?? 'browser',
            'nfcapdTz' => $c->getSignal('nfcapdTz')?->string() ?? 'UTC',
        ];
    }
}
