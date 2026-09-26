<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\QueryProgress;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\processor\FilteredSeries;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\query\CostEstimate;
use mbolli\nfsen_ng\query\FilterComposer;
use mbolli\nfsen_ng\query\ProtocolFilter;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine;

/**
 * Traffic over time for the flow query currently described on the Flows tab.
 *
 * What #166 actually asked for: you filter in Flows, and you want to see *when* those flows
 * happened without rebuilding the same query somewhere else. The plot is the flow filter, run
 * through the same per-bin builder the Graphs tab's filtered mode uses.
 *
 * Two flow settings are deliberately ignored here, because neither changes which records
 * match: the row limit only truncates the table, and the aggregation options only regroup
 * rows. So the table can list a hundred flows while the graph plots every matching byte, which
 * is correct and is stated on screen rather than left to be discovered.
 */
final class FlowGraphActions {
    /**
     * Everything that defines the series, read from the Flows tab's own signals.
     *
     * @return array{start: int, end: int, clamped: bool, sources: list<string>, filter: string, invalid: string, unit: string, display: string, points: int, profile: string}
     */
    public static function params(Context $c): array {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $selectedProfile = $c->getSignal('selected_profile');
        $flowFilter = $c->getSignal('flows_filter');
        $flowLower = $c->getSignal('flows_lower_limit');
        $flowUpper = $c->getSignal('flows_upper_limit');
        $graphSources = $c->getSignal('graph_sources');
        $unit = $c->getSignal('flows_graph_unit');
        // The global protocol; until the controls bar declares it, every query is 'any'.
        $protocol = $c->getSignal('protocol');
        \assert(
            $datestart !== null
            && $dateend !== null
            && $selectedProfile !== null
            && $flowFilter !== null
            && $flowLower !== null
            && $flowUpper !== null
            && $graphSources !== null
            && $unit !== null
        );

        $window = TimeWindow::clamped($datestart->int(), $dateend->int());
        $sources = Helpers::resolveSources($graphSources->array());

        // The table's thresholds and protocol belong to the graph's query too, or the graph
        // would plot more traffic than the table lists.
        try {
            $filter = self::effectiveFilter(
                $flowFilter->string(),
                $flowLower->string(),
                $flowUpper->string(),
                ProtocolFilter::normalize($protocol?->string() ?? 'any'),
            );
            $invalid = '';
        } catch (\InvalidArgumentException $e) {
            // Every render reads this, so a half-typed filter is refused by the build instead.
            $filter = trim($flowFilter->string());
            $invalid = $e->getMessage();
        }

        return [
            'start' => $window->start,
            'end' => $window->end,
            'clamped' => $window->clamped,
            'sources' => $sources,
            'filter' => $filter,
            'invalid' => $invalid,
            'unit' => $unit->string() !== '' ? $unit->string() : 'bytes',
            // One line per source when several are selected, otherwise the protocol split.
            // A filtered series cannot break down by port: the filter *is* the port selection.
            'display' => \count($sources) > 1 ? 'sources' : 'protocols',
            'points' => 150,
            'profile' => $selectedProfile->string(),
        ];
    }

    /**
     * The same composition FlowsQuery::effectiveFilter() applies.
     *
     * @throws \InvalidArgumentException for a filter with unbalanced parentheses
     */
    public static function effectiveFilter(string $filter, string $lower, string $upper, string $protocol = 'any'): string {
        return FilterComposer::and(
            Nfdump::buildThresholdFilter(trim($lower), trim($upper)),
            ProtocolFilter::term(ProtocolFilter::normalize($protocol)),
            $filter,
        );
    }

    public static function cacheKey(Context $c): string {
        $p = self::params($c);

        return FilteredGraphCache::key(
            $p['start'],
            $p['end'],
            $p['sources'],
            $p['filter'],
            $p['unit'],
            $p['display'],
            $p['points'],
            $p['profile'],
        );
    }

    /**
     * What this build would read: the runs from arithmetic, the files and bytes from the Flows
     * estimate (`_est_flows`, same window and sources), so a render never walks the capture tree.
     *
     * @return array{files: int, bytes: string, intervals: int, clamped: bool, window: string, estimated: bool}
     */
    public static function cost(Context $c): array {
        $p = self::params($c);
        $groups = $p['display'] === 'sources' ? max(1, \count($p['sources'])) : 1;
        $estimate = $c->getSignal(QueryKit::estimateSignal('flows'))?->getValue();
        $estimate = \is_array($estimate) ? $estimate : [];
        $estimated = ($estimate['window'] ?? '') !== '';

        return [
            'files' => \is_int($estimate['files'] ?? null) ? $estimate['files'] : 0,
            'bytes' => \is_string($estimate['bytesHuman'] ?? null) ? $estimate['bytesHuman'] : '',
            'intervals' => CostEstimate::runsForFilteredSeries(
                TimeWindow::raw($p['start'], $p['end']),
                $p['points'],
                $groups,
            ),
            'clamped' => $p['clamped'],
            'window' => GraphActions::formatWindow(Config::$settings->maxStatsWindow),
            'estimated' => $estimated,
        ];
    }

    /**
     * The series for the current flow query, from cache. Null means "not built yet", which the
     * panel renders as a prompt rather than an empty chart: building reads capture files, so it
     * only ever happens when asked.
     *
     * @return null|array{data: array<int|string, mixed>, start: int, end: int, step: int, legend: list<string>}
     */
    public static function cached(Context $c): ?array {
        // Rendered from the key the last build actually stored, not from the current one.
        // The window end follows live time, so recomputing the key here blanked the panel a
        // second after every build. Showing the built series and saying when it no longer
        // matches the query is both more useful and more honest.
        $built = $c->getSignal('flows_graph_key');
        $key = $built?->string() ?? '';

        return $key !== '' ? FilteredGraphCache::get($key) : null;
    }

    /**
     * True when the query on screen no longer matches the series being shown.
     *
     * Deliberately not a comparison of exact cache keys. The window end follows live time, so
     * an exact match went stale a second after every build and the warning became noise. The
     * fingerprint rounds the window to the five-minute slot the data itself uses, so it changes
     * when someone changes the query, not when the clock ticks.
     */
    public static function isStale(Context $c): bool {
        $built = $c->getSignal('flows_graph_fingerprint');
        $fingerprint = $built?->string() ?? '';

        return $fingerprint !== '' && $fingerprint !== self::fingerprint($c);
    }

    /** Identity of the query as a human would describe it, insensitive to the clock. */
    public static function fingerprint(Context $c): string {
        return self::fingerprintOf(self::params($c));
    }

    /**
     * Same identity, from a captured parameter set.
     *
     * The build records what it built from, not what the signals say when it finishes: a
     * window or filter change while nfdump was running otherwise had the panel claim the
     * series matched a query it was never built from.
     *
     * @param array{start: int, end: int, sources: list<string>, filter: string, unit: string, display: string, profile: string} $p
     */
    public static function fingerprintOf(array $p): string {
        return hash('xxh128', implode("\x1f", [
            $p['start'] - ($p['start'] % 300),
            $p['end'] - ($p['end'] % 300),
            implode(',', $p['sources']),
            $p['filter'],
            $p['unit'],
            $p['display'],
            $p['profile'],
        ]));
    }

    /** Registers build-flows-graph. */
    public static function register(Context $c): void {
        $c->action(static function (Context $c): void {
            $queryRunning = $c->getSignal('query_running');
            $queryPermille = $c->getSignal('query_permille');
            $queryStatus = $c->getSignal('query_status');
            $queryEta = $c->getSignal('query_eta');
            $queryExact = $c->getSignal('query_exact');
            $queryKind = $c->getSignal('query_kind');
            $shown = $c->getSignal('flows_graph_shown');
            $error = $c->getSignal('_error');
            \assert(
                $queryRunning !== null
                && $queryPermille !== null
                && $queryStatus !== null
                && $queryEta !== null
                && $queryExact !== null
                && $queryKind !== null
                && $shown !== null
                && $error !== null
            );

            // Asking for the panel is what reveals it; the build below fills it.
            $shown->setValue(true, broadcast: false);

            // One build per tab: a second would race the first's cache write.
            if ($queryRunning->bool()) {
                $c->sync();

                return;
            }

            $params = self::params($c);
            if ($params['invalid'] !== '') {
                $queryKind->setValue('flowsgraph', broadcast: false);
                $queryStatus->setValue('Failed: ' . $params['invalid'], broadcast: false);
                $error->setValue('Traffic graph: ' . $params['invalid'], broadcast: false);
                $c->sync();

                return;
            }

            $key = self::cacheKey($c);
            $builtKey = $c->getSignal('flows_graph_key');
            $builtPrint = $c->getSignal('flows_graph_fingerprint');
            \assert($builtKey !== null && $builtPrint !== null);

            // Already built for exactly this query: point the panel at it and re-render.
            if (FilteredGraphCache::has($key)) {
                $builtKey->setValue($key, broadcast: false);
                $builtPrint->setValue(self::fingerprintOf($params), broadcast: false);
                $c->sync();

                return;
            }

            $contextId = $c->getId();
            QueryCancel::clear($contextId);

            $queryKind->setValue('flowsgraph', broadcast: false);
            $queryRunning->setValue(true, broadcast: false);
            $queryPermille->setValue(0, broadcast: false);
            $queryEta->setValue('', broadcast: false);
            // Exact: the bin count is known before the first nfdump runs.
            $queryExact->setValue(true, broadcast: false);
            $queryStatus->setValue('Reading capture files…', broadcast: false);
            $error->setValue('', broadcast: false);
            $c->sync();

            Coroutine::create(static function () use (
                $c,
                $params,
                $key,
                $builtKey,
                $builtPrint,
                $contextId,
                $queryRunning,
                $queryPermille,
                $queryStatus,
                $queryEta,
                $error
            ): void {
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
                    $c->syncSignals();
                });

                try {
                    $data = FilteredSeries::build(
                        $params['start'],
                        $params['end'],
                        $params['sources'],
                        $params['filter'],
                        ['any'],
                        $params['unit'],
                        $params['display'],
                        $params['points'],
                        $params['profile'],
                        onProgress: static function (int $d, int $t) use ($progress): void {
                            $progress->update($d, $t);
                        },
                        shouldCancel: static fn (): bool => QueryCancel::isRequested($contextId),
                        handle: $contextId,
                    );

                    $cancelled = QueryCancel::isRequested($contextId);
                    FilteredGraphCache::put($key, $data, partial: $cancelled);
                    $builtKey->setValue($key, broadcast: false);
                    $builtPrint->setValue(self::fingerprintOf($params), broadcast: false);
                    $finalStatus = $cancelled
                        ? 'Cancelled: showing partial results. Build again to finish.'
                        : 'Done in ' . round($progress->elapsed(), 1) . 's.';
                } catch (\Throwable $e) {
                    // An uncaught error inside a coroutine takes the whole worker down, not
                    // just this request.
                    Debug::getInstance()->log('Flows graph failed: ' . $e->getMessage(), LOG_ERR);
                    $error->setValue('Traffic graph: ' . $e->getMessage(), broadcast: false);
                    $finalStatus = 'Failed: ' . $e->getMessage();
                } finally {
                    $progress->finish();
                    $queryStatus->setValue($finalStatus ?? 'Done.', broadcast: false);
                    $queryRunning->setValue(false, broadcast: false);
                    QueryCancel::clear($contextId);
                    $c->sync();
                }
            });
        }, 'build-flows-graph');

        // A re-render and nothing else. The unit buttons change what the graph would plot, but
        // building reads capture files, so a change must never trigger one; it only needs the
        // server to notice, so the panel can say the series no longer matches the query.
        $c->action(static function (Context $c): void {
            $c->sync();
        }, 'touch-flows-graph');
    }
}
