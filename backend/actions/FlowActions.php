<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Flow-actions action registration: runs nfdump and renders the flow result table.
 */
final class FlowActions {
    /** Register the flow-actions action. */
    public static function register(Context $c, PageStates $states): void {
        $flows = $states->flows;

        $c->action(static function (Context $c) use ($flows): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $selectedProfile = $c->getSignal('selected_profile');
            $flowFilter = $c->getSignal('flows_filter');
            $flowLowerLimit = $c->getSignal('flows_lower_limit');
            $flowUpperLimit = $c->getSignal('flows_upper_limit');
            $flowLimit = $c->getSignal('flows_limit');
            $aggregation = Helpers::aggregationFromSignals($c, 'flows_agg_');
            $flowOrderByTstart = $c->getSignal('flows_orderByTstart');
            $flowCount = $c->getSignal('flows_count');
            $graphSources = $c->getSignal('graph_sources');
            $ipInfoAction = $c->getAction('ip-info');
            \assert(
                $datestart !== null
                && $dateend !== null
                && $selectedProfile !== null
                && $flowFilter !== null
                && $flowLowerLimit !== null
                && $flowUpperLimit !== null
                && $flowLimit !== null
                && $flowOrderByTstart !== null
                && $flowCount !== null
                && $graphSources !== null
            );
            $time = microtime(true);
            $contextId = $c->getId();

            try {
                $query = new FlowsQuery(
                    // Not clamped: a flow listing is bounded by its record limit, not the range.
                    window: TimeWindow::raw($datestart->int(), $dateend->int()),
                    sources: Helpers::resolveSources($graphSources->array()),
                    profile: $selectedProfile->string(),
                    limit: $flowLimit->int(),
                    filter: $flowFilter->string(),
                    lowerLimit: $flowLowerLimit->string(),
                    upperLimit: $flowUpperLimit->string(),
                    aggregation: $aggregation,
                    orderByStart: $flowOrderByTstart->bool(),
                    handle: $contextId,
                    protocol: RangeControls::protocol($c),
                );

                // Denominator for the progress estimate, deferred so the walk runs inside the
                // coroutine rather than in front of this action's response.
                $totalBytes = static fn (): int => $query->totalBytes();
                $processor = $query->processor();

                // nfdump runs in a coroutine so the action can return immediately and the
                // button can show real progress instead of an indeterminate spinner.
                QueryRunner::run($c, 'flows', $totalBytes, 'Starting nfdump…', static function () use (
                    $query,
                    $processor,
                    $ipInfoAction,
                    $flowCount,
                    $time,
                    $contextId,
                    $flows
                ): void {
                    try {
                        $result = $query->run($processor);
                        $flowCount->setValue($result->count(), broadcast: false);
                        self::storeResult($flows, $result, round(microtime(true) - $time, 3), $ipInfoAction?->url() ?? '');
                    } catch (\Throwable $e) {
                        // The cancel flag is read here because QueryRunner clears it after the work.
                        self::storeFailure($flows, $e, QueryRunner::wasCancelled($e, QueryCancel::isRequested($contextId)));
                        $flowCount->setValue(0, broadcast: false);

                        // Rethrow: QueryRunner owns the status line, and swallowing here left it
                        // reading "Done in 0.4s." beside the red error notification.
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                // Failure while building the command: nothing started, report synchronously.
                self::storeFailure($flows, $e, false);
                $flowCount->setValue(0, broadcast: false);
                $c->sync();
            }
        }, 'flow-actions');
    }

    /** Stores a finished run: the table, and a notice with the command that ran. */
    public static function storeResult(FlowsState $state, QueryResult $result, float $elapsed, string $ipInfoUrl): void {
        $state->setResult(Table::generate($result->rows, 'flowTable', [
            'hiddenFields' => [],
            'linkIpAddresses' => true,
            'ipInfoActionUrl' => $ipInfoUrl,
            'originalData' => $result->rawOutput,
        ]), $result->count());

        $state->notifyResult($result, $elapsed, 'Flows');
    }

    /** Stores a failed run. A cancelled run keeps kill-nfdump's notice instead of an error. */
    public static function storeFailure(FlowsState $state, \Throwable $e, bool $cancelled): void {
        if (!$cancelled) {
            Debug::getInstance()->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
            $state->notifyFailure($e);
        }
        $state->clearResult();
    }
}
