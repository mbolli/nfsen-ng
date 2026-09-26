<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\QueryProgress;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\FilteredSeries;
use mbolli\nfsen_ng\query\CostEstimate;
use mbolli\nfsen_ng\query\CoverageQuery;
use mbolli\nfsen_ng\query\TimelineQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use OpenSwoole\Coroutine;

/**
 * The Overview graph: its data, the filtered build and the refresh action.
 *
 * @phpstan-import-type GraphData from Datasource
 */
final class GraphActions {
    /** Banner prefixes of the graph's own failures, the only ones a successful fetch clears. */
    private const array ERROR_PREFIXES = ['Graph error: ', 'Filtered graph: '];

    /**
     * Fetch graph data from the datasource, updating the live, resolution and last-update signals.
     *
     * @return array{}|GraphData empty when the fetch failed (the _error signal carries why)
     */
    public static function fetchGraphData(Context $c): array {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphDisplay = $c->getSignal('graph_display');
        $graphSources = $c->getSignal('graph_sources');
        $graphPorts = $c->getSignal('graph_ports');
        $graphProtocols = $c->getSignal('graph_protocols');
        $graphDatatype = $c->getSignal('graph_datatype');
        $graphTrafficUnit = $c->getSignal('graph_trafficUnit');
        $graphResolution = $c->getSignal('graph_resolution');
        $graphIsLive = $c->getSignal('graph_isLive');
        $graphActualRes = $c->getSignal('graph_actualResolution');
        $graphLastUpdate = $c->getSignal('graph_lastUpdate');
        $error = $c->getSignal('_error');
        $selectedProfile = $c->getSignal('selected_profile');
        $graphMode = $c->getSignal('graph_mode');
        $graphFilter = $c->getSignal('graph_filter');
        \assert(
            $datestart !== null
            && $dateend !== null
            && $graphDisplay !== null
            && $graphSources !== null
            && $graphPorts !== null
            && $graphProtocols !== null
            && $graphDatatype !== null
            && $graphTrafficUnit !== null
            && $graphResolution !== null
            && $graphIsLive !== null
            && $graphActualRes !== null
            && $graphLastUpdate !== null
            && $error !== null
            && $selectedProfile !== null
            && $graphMode !== null
            && $graphFilter !== null
        );

        $ds = $datestart->int();
        $de = $dateend->int();

        $graphIsLive->setValue($c->getSignal('range_live')?->bool() ?? false, broadcast: false);

        $dt = $graphDatatype->string();
        $unit = ($dt !== 'traffic') ? $dt : $graphTrafficUnit->string();
        $display = $graphDisplay->string();
        $sources = self::normalizeSources($graphSources->array(), $display);
        $ports = self::normalizePorts($graphPorts->array());

        // Both signals are client-writable, and graph._config feeds them straight into the
        // chart, where a port outside a plain array takes the ports view down (#160).
        if ($graphPorts->getValue() !== $ports) {
            $graphPorts->setValue($ports, broadcast: false);
        }
        if ($graphSources->getValue() !== $sources) {
            $graphSources->setValue($sources, broadcast: false);
        }

        // Filtered mode (#166): only run-filtered-graph builds; a render would fork hundreds of
        // nfdump processes per SSE push. A cache miss renders the "press Apply" state.
        if ($graphMode->string() === 'filtered') {
            $graphIsLive->setValue(false, broadcast: false);

            $cached = FilteredGraphCache::get(self::filteredKey($c));

            if ($cached === null) {
                // 0 points is what the "press Apply" hint keys off.
                $graphActualRes->setValue(0, broadcast: false);

                return [];
            }

            $graphActualRes->setValue(\count($cached['data']), broadcast: false);
            $graphLastUpdate->setValue($cached['end'], broadcast: false);
            self::clearOwnError($error);

            return $cached;
        }

        $query = new TimelineQuery(
            window: TimeWindow::raw($ds, $de),
            sources: $sources,
            protocols: self::normalizeProtocols($graphProtocols->array()),
            ports: $ports,
            unit: $unit,
            display: $display,
            resolution: $graphResolution->int(),
            profile: $selectedProfile->string(),
        );

        try {
            // A datasource may answer with an error string instead of a series (RRD does);
            // the query turns that into an exception so both failures arrive the same way.
            $data = $query->run();
        } catch (\Throwable $e) {
            $error->setValue('Graph error: ' . $e->getMessage(), broadcast: false);

            return [];
        }

        $pointCount = \count($data['data']);
        $graphActualRes->setValue($pointCount, broadcast: false);

        // Use the actual datasource last-write time rather than wall-clock "now"
        $lastWrite = $query->lastWrite();
        $graphLastUpdate->setValue($lastWrite > 0 ? $lastWrite : time(), broadcast: false);
        self::clearOwnError($error);

        return $data;
    }

    /**
     * Resolve every input that defines a filtered-graph query.
     *
     * Shared by the cache lookup on the render path and by the builder in the action, so
     * the two never disagree about the key: a mismatch would rebuild and still never hit.
     *
     * @return array{start: int, end: int, clamped: bool, sources: list<string>, filter: string, protocols: list<string>, unit: string, display: string, points: int, profile: string}
     */
    public static function filteredParams(Context $c): array {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphDisplay = $c->getSignal('graph_display');
        $graphSources = $c->getSignal('graph_sources');
        $graphDatatype = $c->getSignal('graph_datatype');
        $graphTrafficUnit = $c->getSignal('graph_trafficUnit');
        $graphResolution = $c->getSignal('graph_resolution');
        $graphFilter = $c->getSignal('graph_filter');
        $graphProtocols = $c->getSignal('graph_protocols');
        $selectedProfile = $c->getSignal('selected_profile');
        \assert(
            $datestart !== null
            && $dateend !== null
            && $graphDisplay !== null
            && $graphSources !== null
            && $graphDatatype !== null
            && $graphTrafficUnit !== null
            && $graphResolution !== null
            && $graphFilter !== null
            && $graphProtocols !== null
            && $selectedProfile !== null
        );

        $dt = $graphDatatype->string();
        $display = $graphDisplay->string();
        [$start, $end, $clamped] = self::clampFilteredWindow($datestart->int(), $dateend->int());

        return [
            'start' => $start,
            'end' => $end,
            'clamped' => $clamped,
            // Helpers::resolveSources(), not normalizeSources(): nfdump is handed real
            // source names for -M, and the 'any' sentinel is not one.
            'sources' => Helpers::resolveSources($graphSources->array()),
            'filter' => trim($graphFilter->string()),
            'protocols' => FilteredSeries::normalizeProtocolSelection(self::normalizeProtocols($graphProtocols->array())),
            'unit' => ($dt !== 'traffic') ? $dt : $graphTrafficUnit->string(),
            // A filtered series has no per-port breakdown: the filter is the port selection,
            // so the ports view falls back to the protocol split.
            'display' => $display === 'sources' ? 'sources' : 'protocols',
            'points' => $graphResolution->int(),
            'profile' => $selectedProfile->string(),
        ];
    }

    /**
     * Apply NFSEN_MAX_STATS_WINDOW to a filtered-graph window.
     *
     * A filtered build re-reads every capture in the range, the same open-ended cost the
     * Statistics and Sankey tabs clamp, so the same setting applies: keep the end of the
     * window and pull the start forward.
     *
     * @return array{int, int, bool} start, end, whether the window was shortened
     */
    public static function clampFilteredWindow(int $start, int $end): array {
        $window = TimeWindow::clamped($start, $end);

        return [$window->start, $window->end, $window->clamped];
    }

    /**
     * What the Apply button is about to cost, for display beside it.
     *
     * File count and size come from the signals measureFilteredCost() keeps current: this runs
     * on every render and must not walk the capture tree itself.
     *
     * @return array{files: int, bytes: string, intervals: int, clamped: bool, window: string}
     */
    public static function filteredCost(Context $c): array {
        $p = self::filteredParams($c);
        $files = $c->getSignal('nfcapd_file_count');
        $bytes = $c->getSignal('nfcapd_total_bytes');
        $groups = $p['display'] === 'sources' ? max(1, \count($p['sources'])) : 1;

        $runs = CostEstimate::runsForFilteredSeries(
            TimeWindow::raw($p['start'], $p['end']),
            $p['points'],
            $groups,
        );

        return [
            // The counts cover the selected range, which is not what a clamped build reads, so
            // the panel drops them when the window was shortened rather than overstating.
            'files' => $files?->int() ?? 0,
            'bytes' => QueryRunner::formatBytes($bytes?->int() ?? 0),
            'intervals' => $runs,
            'clamped' => $p['clamped'],
            'window' => self::formatWindow(Config::$settings->maxStatsWindow),
        ];
    }

    /**
     * Human-readable length of a time window, in whatever unit reads naturally.
     *
     * Days alone are not enough: a one-hour cap formatted as days rounds to "0 days",
     * which tells the user nothing and looks broken.
     */
    public static function formatWindow(int $seconds): string {
        return TimeWindow::humanize($seconds);
    }

    /** Cache key for the filtered query the UI currently describes. */
    public static function filteredKey(Context $c): string {
        $p = self::filteredParams($c);

        return FilteredGraphCache::key(
            $p['start'],
            $p['end'],
            $p['sources'],
            $p['filter'],
            $p['unit'],
            $p['display'],
            $p['points'],
            $p['profile'],
            $p['protocols'],
        );
    }

    /**
     * Sanitize the graph_sources signal before it reaches a datasource.
     *
     * The signal is client-writable, so a stale or malformed value must never
     * reach the RRD path builder: an empty entry turns into a bare
     * "<profile>/.rrd" filename and rrd_xport fails the whole graph (#160).
     * The "any" sentinel is only meaningful for the ports view, where it selects
     * the cross-source aggregate RRD; elsewhere it means "every source".
     *
     * @param array<int|string, mixed> $selected raw graph_sources signal value
     *
     * @return list<string>
     */
    public static function normalizeSources(array $selected, string $display): array {
        $sources = array_values(array_filter(
            array_map(static fn ($s) => trim((string) $s), $selected),
            static fn (string $s) => $s !== ''
        ));

        if ($sources === [] || (\in_array('any', $sources, true) && $display !== 'ports')) {
            return Config::$settings->sources;
        }

        return $sources;
    }

    /**
     * Sanitize the graph_protocols signal before it reaches a datasource.
     *
     * Same client-writable-signal problem as normalizeSources(): entries can be any
     * scalar under any key. An empty selection means "no protocol filter", which the
     * datasources spell 'any': they index $protocols[0] directly.
     *
     * @param array<int|string, mixed> $selected raw graph_protocols signal value
     *
     * @return list<string>
     */
    public static function normalizeProtocols(array $selected): array {
        $protocols = array_values(array_filter(
            array_map(static fn (mixed $p): string => \is_scalar($p) ? trim((string) $p) : '', $selected),
            static fn (string $p): bool => $p !== ''
        ));

        return $protocols === [] ? ['any'] : $protocols;
    }

    /**
     * Sanitize the graph_ports signal before it reaches a datasource.
     *
     * Ports are ints everywhere on the server (Settings::$ports, the Datasource
     * get_data_path(string $source, int $port) contract), but the signal is
     * client-writable and the browser has every reason to hand back strings: a
     * <select>'s option values are strings by definition, and Datastar's bind
     * adapter only recovers the numeric type for options it has already written
     * the signal into. A string port used to reach Rrd::get_data_path() and kill
     * the whole ports view with a TypeError (#160).
     *
     * @param array<int|string, mixed> $selected raw graph_ports signal value
     *
     * @return list<int>
     */
    public static function normalizePorts(array $selected): array {
        $ports = array_values(array_map(
            static fn ($p) => (int) $p,
            array_filter($selected, static fn ($p) => is_numeric($p) && (int) $p > 0)
        ));

        return $ports === [] ? Config::$settings->ports : $ports;
    }

    /**
     * Update data_range_min / data_range_max from actual RRD boundaries.
     */
    public static function updateDataRange(Context $c): void {
        $dataRangeMin = $c->getSignal('data_range_min');
        $dataRangeMax = $c->getSignal('data_range_max');
        $selectedProfile = $c->getSignal('selected_profile');
        \assert($dataRangeMin !== null && $dataRangeMax !== null && $selectedProfile !== null);

        $sources = Config::$settings->sources;
        if (empty($sources)) {
            return;
        }

        // withLastUpdate: false: only the first and last sample are used, while each
        // last_update() is an HTTP request on VictoriaMetrics.
        $coverage = (new CoverageQuery($sources, $selectedProfile->string(), withLastUpdate: false))->run();

        $dataRangeMin->setValue($coverage['first'] > 0 ? $coverage['first'] : CoverageQuery::fallbackFirst(), broadcast: false);
        $dataRangeMax->setValue($coverage['last'] > 0 ? $coverage['last'] : time(), broadcast: false);
    }

    /** Register the run-filtered-graph and refresh-graphs actions. */
    public static function register(Context $c): void {
        // Build a filter-aware series by re-reading the nfcapd files (#166).
        //
        // Modelled on trigger-import: the action returns immediately and the work runs in
        // a coroutine, which keeps the POST from hanging for the whole build and lets the
        // Kill button through. Progress reaches the browser as signal-only patches
        // (syncSignals), roughly an order of magnitude cheaper than re-rendering the page
        // for each of the several hundred bins.
        $c->action(static function (Context $c): void {
            $queryRunning = $c->getSignal('query_running');
            $queryPermille = $c->getSignal('query_permille');
            $queryStatus = $c->getSignal('query_status');
            $queryEta = $c->getSignal('query_eta');
            $queryExact = $c->getSignal('query_exact');
            $queryKind = $c->getSignal('query_kind');
            $graphMode = $c->getSignal('graph_mode');
            $error = $c->getSignal('_error');
            \assert(
                $queryRunning !== null
                && $queryPermille !== null
                && $queryStatus !== null
                && $queryEta !== null
                && $queryExact !== null
                && $queryKind !== null
                && $graphMode !== null
                && $error !== null
            );

            // Apply is only meaningful in filtered mode; anywhere else behave like a refresh.
            if ($graphMode->string() !== 'filtered') {
                self::fetchGraphData($c);
                $c->sync();

                return;
            }

            // One build per tab at a time: a second Apply would race the first's cache write.
            if ($queryRunning->bool()) {
                return;
            }

            $params = self::filteredParams($c);
            $key = self::filteredKey($c);

            // Already built: nothing to do but re-render off the cache.
            if (FilteredGraphCache::has($key)) {
                self::fetchGraphData($c);
                $c->sync();

                return;
            }

            $contextId = $c->getId();
            QueryCancel::clear($contextId);

            $queryKind->setValue('graph', broadcast: false);
            $queryRunning->setValue(true, broadcast: false);
            $queryPermille->setValue(0, broadcast: false);
            $queryEta->setValue('', broadcast: false);
            // Exact, unlike the byte-sampled estimate the single-shot Flows/Statistics
            // queries report: here the bin count is known up front.
            $queryExact->setValue(true, broadcast: false);
            $queryStatus->setValue('Reading capture files…', broadcast: false);
            $error->setValue('', broadcast: false);
            $c->sync();

            Coroutine::create(static function () use (
                $c,
                $params,
                $key,
                $contextId,
                $queryRunning,
                $queryPermille,
                $queryStatus,
                $queryEta,
                $error
            ): void {
                $binCount = 0;

                $progress = new QueryProgress(static function (int $permille, string $eta, int $done, int $total) use (
                    $c,
                    $queryPermille,
                    $queryStatus,
                    $queryEta
                ): void {
                    $queryPermille->setValue($permille, broadcast: false);
                    $queryEta->setValue($eta, broadcast: false);
                    $queryStatus->setValue(
                        $total > 0 ? "Scanning {$done} / {$total} intervals" : 'Reading capture files…',
                        broadcast: false
                    );
                    // Signals only: a full sync() here would re-render the whole page once per bin.
                    $c->syncSignals();
                });

                try {
                    $data = FilteredSeries::build(
                        $params['start'],
                        $params['end'],
                        $params['sources'],
                        $params['filter'],
                        $params['protocols'],
                        $params['unit'],
                        $params['display'],
                        $params['points'],
                        $params['profile'],
                        onProgress: static function (int $d, int $t) use (&$binCount, $progress): void {
                            $binCount = $t;
                            $progress->update($d, $t);
                        },
                        shouldCancel: static fn (): bool => QueryCancel::isRequested($contextId),
                        handle: $contextId,
                    );

                    // A cancelled run is shown but cached as partial, so the next Apply rebuilds.
                    $cancelled = QueryCancel::isRequested($contextId);
                    FilteredGraphCache::put($key, $data, partial: $cancelled);
                    $finalStatus = $cancelled
                        ? 'Cancelled, showing partial results. Apply again to finish.'
                        : 'Done in ' . round($progress->elapsed(), 1) . 's.';
                } catch (\Throwable $e) {
                    // Catching Throwable is not defensive padding: an uncaught error inside a
                    // coroutine takes the whole OpenSwoole worker down, not just this request.
                    Debug::getInstance()->log('Filtered graph failed: ' . $e->getMessage(), LOG_ERR);
                    $error->setValue('Filtered graph: ' . $e->getMessage(), broadcast: false);
                    // Carry the reason, not just the fact. The status line sits right next to
                    // the button, and a bare "Failed." next to a Flows panel that says exactly
                    // why it found nothing is the wrong half of the story to show.
                    $finalStatus = 'Failed: ' . $e->getMessage();
                } finally {
                    // finish() before the closing message, not after: it emits a last tick that
                    // rewrites query_status from the counts, which would otherwise overwrite
                    // whatever outcome we just set with a bare "Scanning N / N intervals".
                    $progress->finish($binCount);
                    $queryStatus->setValue($finalStatus ?? '', broadcast: false);
                    $queryRunning->setValue(false, broadcast: false);
                    QueryCancel::clear($contextId);
                    $c->sync();
                }
            });
        }, 'run-filtered-graph');

        // A live window advances in the render that follows (RangeControls), not here.
        $c->action(static function (Context $c): void {
            try {
                self::measureFilteredCost($c);
                self::fetchGraphData($c);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('refresh-graphs failed: ' . $e->getMessage(), LOG_ERR);
            }
            $c->sync();
        }, 'refresh-graphs');
    }

    /**
     * Re-counts the capture files behind the filtered graph's cost line after the window,
     * sources or profile changed. Names and sizes only, and skipped while nothing changed.
     */
    public static function measureFilteredCost(Context $c): void {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphSources = $c->getSignal('graph_sources');
        $selectedProfile = $c->getSignal('selected_profile');
        if ($c->getSignal('graph_mode')?->string() !== 'filtered'
            || $datestart === null || $dateend === null || $graphSources === null || $selectedProfile === null) {
            return;
        }

        Helpers::measureNfcapdFiles(
            $c,
            $datestart->int(),
            $dateend->int(),
            Helpers::resolveSources($graphSources->array()),
            $selectedProfile->string(),
            true
        );
    }

    /** Clears the banner when it holds one of this graph's own failures, and only then. */
    private static function clearOwnError(Signal $error): void {
        $text = $error->string();
        if ($text !== '' && array_any(self::ERROR_PREFIXES, static fn (string $p): bool => str_starts_with($text, $p))) {
            $error->setValue('', broadcast: false);
        }
    }
}
