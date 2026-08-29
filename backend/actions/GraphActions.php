<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\QueryProgress;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\FilteredSeries;
use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine;

/**
 * Graph-related helper methods and action registrations.
 */
/**
 * @phpstan-import-type GraphData from Datasource
 */
final class GraphActions {
    /**
     * Fetch graph data from the datasource, updating live/resolution/last-update signals.
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

        $isLive = (time() - $de) < 300;
        $graphIsLive->setValue($isLive, broadcast: false);

        $dt = $graphDatatype->string();
        $unit = ($dt !== 'traffic') ? $dt : $graphTrafficUnit->string();
        $display = $graphDisplay->string();
        $sources = self::normalizeSources($graphSources->array(), $display);
        $ports = self::normalizePorts($graphPorts->array());

        // Push the sanitized selections back at the browser. Both signals are
        // client-writable and the client's own copy can end up a different shape than
        // the server contract (a scalar, an object, strings where ints are expected) —
        // graph._config feeds those straight into the chart, where a port that isn't
        // part of a plain array takes the ports view down for good (#160). Normalizing
        // in place means one authoritative shape instead of a per-consumer guess.
        if ($graphPorts->getValue() !== $ports) {
            $graphPorts->setValue($ports, broadcast: false);
        }
        if ($graphSources->getValue() !== $sources) {
            $graphSources->setValue($sources, broadcast: false);
        }

        // ── Filtered mode (#166) ────────────────────────────────────────────
        // Never build here: this method runs on every re-render, so a build would fork
        // hundreds of nfdump processes on each SSE push. The series is produced only by
        // the run-filtered-graph action and looked up from the cache afterwards; a miss
        // renders an empty graph whose UI prompts for Apply.
        if ($graphMode->string() === 'filtered') {
            $graphIsLive->setValue(false, broadcast: false);

            $cached = FilteredGraphCache::get(self::filteredKey($c));

            if ($cached === null) {
                // 0 points is what the "press Apply" hint keys off — see graph-view.html.twig.
                $graphActualRes->setValue(0, broadcast: false);

                return [];
            }

            $graphActualRes->setValue(\count($cached['data']), broadcast: false);
            $graphLastUpdate->setValue($cached['end'], broadcast: false);
            $error->setValue('', broadcast: false);

            return $cached;
        }

        try {
            $data = Config::$db->get_graph_data(
                $ds,
                $de,
                $sources,
                self::normalizeProtocols($graphProtocols->array()),
                $ports,
                $unit,
                $display,
                $graphResolution->int(),
                $selectedProfile->string()
            );
        } catch (\Throwable $e) {
            $error->setValue('Graph error: ' . $e->getMessage(), broadcast: false);

            return [];
        }

        // A datasource may answer with an error string instead of a series (RRD does).
        // Report it like any other failure — counting it would be a TypeError.
        if (\is_string($data)) {
            $error->setValue('Graph error: ' . $data, broadcast: false);

            return [];
        }

        $pointCount = \count($data['data']);
        $graphActualRes->setValue($pointCount, broadcast: false);

        // Use the actual RRD last-write time rather than wall-clock "now"
        $activeSources = array_values(array_filter($sources, static fn (string $s) => $s !== 'any'))
            ?: Config::$settings->sources;
        $lastWrite = empty($activeSources) ? 0 : max(array_map(
            fn ($s) => Config::$db->last_update($s, 0, $selectedProfile->string()),
            $activeSources
        ));
        $graphLastUpdate->setValue($lastWrite > 0 ? $lastWrite : time(), broadcast: false);
        $error->setValue('', broadcast: false);

        return $data;
    }

    /**
     * Resolve every input that defines a filtered-graph query.
     *
     * Shared by the cache lookup on the render path and by the builder in the action, so
     * the two can never disagree about which key a given UI state maps to — a mismatch
     * would rebuild on every render and still never hit.
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
            // A filtered series has no per-port breakdown to draw — the filter *is* the
            // port selection — so the ports view falls back to the protocol split.
            'display' => $display === 'sources' ? 'sources' : 'protocols',
            'points' => $graphResolution->int(),
            'profile' => $selectedProfile->string(),
        ];
    }

    /**
     * Apply NFSEN_MAX_STATS_WINDOW to a filtered-graph window.
     *
     * A filtered build re-reads every capture in the range, so an unbounded window is the
     * same open-ended cost the Statistics and Sankey tabs already clamp — it just shows up
     * as minutes of nfdump instead of one long query. Same setting, same behaviour: keep
     * the end of the window and pull the start forward.
     *
     * @return array{int, int, bool} start, end, whether the window was shortened
     */
    public static function clampFilteredWindow(int $start, int $end): array {
        $max = Config::$settings->maxStatsWindow;

        if ($max > 0 && ($end - $start) > $max) {
            return [$end - $max, $end, true];
        }

        return [$start, $end, false];
    }

    /**
     * What the Apply button is about to cost, for display beside it.
     *
     * "Cost grows with the window" is true but unactionable; the app already knows the
     * real numbers, so show them. File count and size come from the signals the
     * count-files action maintains — this runs on every render and must not walk the
     * capture tree itself.
     *
     * @return array{files: int, bytes: string, intervals: int, clamped: bool, window: string}
     */
    public static function filteredCost(Context $c): array {
        $p = self::filteredParams($c);
        $files = $c->getSignal('nfcapd_file_count');
        $bytes = $c->getSignal('nfcapd_total_bytes');
        $groups = $p['display'] === 'sources' ? max(1, \count($p['sources'])) : 1;
        $step = FilteredSeries::binWidth($p['start'], $p['end'], $p['points'], $groups);

        return [
            'files' => $files?->int() ?? 0,
            'bytes' => QueryRunner::formatBytes($bytes?->int() ?? 0),
            'intervals' => (int) ceil(max(1, $p['end'] - $p['start']) / $step) * $groups,
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
        if ($seconds >= 86400) {
            $days = round($seconds / 86400, 1);

            return self::plural($days, 'day');
        }

        if ($seconds >= 3600) {
            return self::plural(round($seconds / 3600, 1), 'hour');
        }

        return self::plural(max(1, (int) round($seconds / 60)), 'minute');
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
     * datasources spell 'any' — they index $protocols[0] directly, so leaving it empty
     * is an undefined offset rather than a wider query.
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
     * client-writable and the browser has every reason to hand back strings — a
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

        $fallbackMin = time() - Config::$settings->importYears * 365 * 86400;
        $firsts = [];
        $lasts = [];

        foreach ($sources as $source) {
            try {
                [$first, $last] = Config::$db->date_boundaries($source, $selectedProfile->string());
                if ($first > 0) {
                    $firsts[] = $first;
                }
                if ($last > 0) {
                    $lasts[] = $last;
                }
            } catch (\Throwable) {
                // RRD may not exist yet — skip
            }
        }

        $dataRangeMin->setValue(empty($firsts) ? $fallbackMin : min($firsts), broadcast: false);
        $dataRangeMax->setValue(empty($lasts) ? time() : max($lasts), broadcast: false);
    }

    /** Register the change-profile and refresh-graphs actions. */
    public static function register(Context $c): void {
        $c->action(static function (Context $c): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $dataRangeMax = $c->getSignal('data_range_max');
            $selectedProfile = $c->getSignal('selected_profile');
            \assert($datestart !== null && $dateend !== null && $dataRangeMax !== null && $selectedProfile !== null);

            $newProfile = $selectedProfile->string();
            $available = Config::detectProfiles();
            if (!\in_array($newProfile, $available, true)) {
                return;
            }

            $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
            $prefs->withSelectedProfile($newProfile)->save(Config::$prefsFile);

            self::updateDataRange($c);

            // Slide the visible window to the new profile's latest data
            $newMax = $dataRangeMax->int();
            $window = $dateend->int() - $datestart->int();
            $dateend->setValue($newMax, broadcast: false);
            $datestart->setValue($newMax - $window, broadcast: false);

            $c->sync();
        }, 'change-profile');

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

            // One build per tab at a time — a second Apply would race the first's cache write.
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
                    // Signals only — a full sync() here would re-render the whole page
                    // once per bin. Confirmed to stream progressively over SSE.
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
                    );

                    // A cancelled run is still worth showing, but it is not the answer for
                    // this key — cache it as partial so the next Apply rebuilds instead of
                    // being short-circuited by the cache hit.
                    $cancelled = QueryCancel::isRequested($contextId);
                    FilteredGraphCache::put($key, $data, partial: $cancelled);
                    $finalStatus = $cancelled
                        ? 'Cancelled — showing partial results. Apply again to finish.'
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

        $c->action(static function (Context $c): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            \assert($datestart !== null && $dateend !== null);

            // Advance live window if within 10 min of now — but not in filtered mode,
            // where the window is part of the series' cache key (see app.php).
            $graphMode = $c->getSignal('graph_mode');
            $now = time();
            $de = $dateend->int();
            if ($graphMode?->string() === 'rrd' && $now - $de < 600) {
                $window = $de - $datestart->int();
                $dateend->setValue($now, broadcast: false);
                $datestart->setValue($now - $window, broadcast: false);
            }

            // Keep the projected cost honest. In filtered mode this action only fires on an
            // explicit mode switch or a resolution change (the 250 ms change handler and the
            // live tick are both gated to 'rrd'), so the extra directory walk is rare.
            if ($graphMode?->string() === 'filtered') {
                $graphSources = $c->getSignal('graph_sources');
                $selectedProfile = $c->getSignal('selected_profile');
                if ($graphSources !== null && $selectedProfile !== null) {
                    Helpers::measureNfcapdFiles(
                        $c,
                        $datestart->int(),
                        $dateend->int(),
                        Helpers::resolveSources($graphSources->array()),
                        $selectedProfile->string(),
                        true
                    );
                }
            }

            self::fetchGraphData($c);
            $c->sync();
        }, 'refresh-graphs');
    }

    /** "1 day" / "2.5 days", trimming a trailing .0 so whole values read naturally. */
    private static function plural(float|int $value, string $noun): string {
        $text = (float) $value === floor((float) $value) ? (string) (int) $value : (string) $value;

        return $text . ' ' . $noun . ((float) $value === 1.0 ? '' : 's');
    }
}
