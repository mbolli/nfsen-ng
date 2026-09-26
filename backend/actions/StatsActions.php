<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Stats-actions action registration: runs an nfdump statistic and renders its table.
 */
final class StatsActions {
    /** Register the stats-actions action. */
    public static function register(Context $c, PageStates $states): void {
        $talkers = $states->talkers;

        $c->action(static function (Context $c) use ($talkers): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $selectedProfile = $c->getSignal('selected_profile');
            $statsFilter = $c->getSignal('stats_filter');
            $statsCount = $c->getSignal('stats_count');
            $statsFor = $c->getSignal('stats_for');
            $statsOrderBy = $c->getSignal('stats_orderBy');
            $statsLowerLimit = $c->getSignal('stats_lower_limit');
            $statsUpperLimit = $c->getSignal('stats_upper_limit');
            $graphSources = $c->getSignal('graph_sources');
            $aggregation = Helpers::aggregationFromSignals($c, 'stats_agg_');
            $ipInfoAction = $c->getAction('ip-info');
            \assert(
                $datestart !== null
                && $dateend !== null
                && $selectedProfile !== null
                && $statsFilter !== null
                && $statsCount !== null
                && $statsFor !== null
                && $statsOrderBy !== null
                && $statsLowerLimit !== null
                && $statsUpperLimit !== null
                && $graphSources !== null
            );
            $time = microtime(true);
            $contextId = $c->getId();
            $talkers->clearNotifications();

            try {
                $query = new StatsQuery(
                    window: TimeWindow::clamped($datestart->int(), $dateend->int()),
                    sources: Helpers::resolveSources($graphSources->array()),
                    profile: $selectedProfile->string(),
                    for: $statsFor->string(),
                    orderBy: $statsOrderBy->string(),
                    limit: $statsCount->int(),
                    filter: $statsFilter->string(),
                    lowerLimit: $statsLowerLimit->string(),
                    upperLimit: $statsUpperLimit->string(),
                    aggregation: $aggregation,
                    handle: $contextId,
                    protocol: RangeControls::protocol($c),
                );

                $clampNotice = $query->window->clamped ? $query->window->clampNotice() : '';
                if ($clampNotice !== '') {
                    $talkers->notify('warning', $clampNotice);
                }

                // Denominator for the progress estimate, deferred so the walk runs inside the
                // coroutine rather than in front of this action's response.
                $totalBytes = static fn (): int => $query->totalBytes();
                $processor = $query->processor();

                // nfdump runs in a coroutine so the action can return immediately and the
                // button can show real progress instead of an indeterminate spinner.
                QueryRunner::run($c, 'stats', $totalBytes, 'Starting nfdump…', static function () use (
                    $query,
                    $processor,
                    $ipInfoAction,
                    $time,
                    $contextId,
                    $clampNotice,
                    $talkers
                ): void {
                    try {
                        $result = $query->run($processor);
                        self::storeResult($talkers, $result, round(microtime(true) - $time, 3), $ipInfoAction?->url() ?? '', $clampNotice);
                    } catch (\Throwable $e) {
                        // The cancel flag is read here because QueryRunner clears it after the work.
                        self::storeFailure($talkers, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)));

                        // Rethrow: QueryRunner owns the status line, and swallowing here left it
                        // reading "Done in 0.4s." beside the red error notification.
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                // Failure while building the command (bad window, unreadable profile):
                // nothing was started, so report it synchronously.
                self::storeFailure($talkers, $e, false);
                $c->sync();
            }
        }, 'stats-actions');
    }

    /** Stores a finished run: the table, the command that ran, and the clamp notice on top. */
    public static function storeResult(TalkersState $state, QueryResult $result, float $elapsed, string $ipInfoUrl, string $clampNotice = ''): void {
        $state->setResult(Table::generate($result->rows, 'statsTable', [
            'hiddenFields' => [],
            'linkIpAddresses' => true,
            'ipInfoActionUrl' => $ipInfoUrl,
            'originalData' => $result->rawOutput,
        ]));

        $state->notifyResult($result, $elapsed, 'Statistics');
        if ($clampNotice !== '') {
            $state->notify('warning', $clampNotice);
        }
    }

    /** Stores a failed run. A cancelled run keeps kill-nfdump's notice instead of an error. */
    public static function storeFailure(TalkersState $state, \Throwable $e, bool $cancelled): void {
        if (!$cancelled) {
            Debug::getInstance()->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
            $state->notifyFailure($e);
        }
        $state->clearResult();
    }
}
