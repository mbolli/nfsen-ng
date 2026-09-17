<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Flow-actions action registration — runs nfdump and renders the flow result table.
 */
final class FlowActions {
    /**
     * Register the flow-actions action.
     *
     * @param list<array{id: string, type: string, message: string}> $flowNotifications
     */
    public static function register(Context $c, array &$flowNotifications, string &$flowTableHtml): void {
        $c->action(static function (Context $c) use (&$flowNotifications, &$flowTableHtml): void {
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
                    handle: $c->getId(),
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
                    &$flowNotifications,
                    &$flowTableHtml
                ): void {
                    try {
                        $result = $query->run($processor);
                        $flowData = $result->rows;

                        $flowCount->setValue($result->count(), broadcast: false);
                        $elapsed = round(microtime(true) - $time, 3);
                        $cmd = htmlspecialchars($result->command, ENT_QUOTES | ENT_HTML5);
                        $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                            ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                            : "Flows processed in {$elapsed}s."]];

                        if ($result->stderr !== '') {
                            $flowNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars($result->stderr, ENT_QUOTES | ENT_HTML5)];
                        }

                        $flowTableHtml = Table::generate($flowData, 'flowTable', [
                            'hiddenFields' => [],
                            'linkIpAddresses' => true,
                            'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                            'originalData' => $result->rawOutput,
                        ]);
                    } catch (\Throwable $e) {
                        Debug::getInstance()->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
                        $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                        $flowTableHtml = '';

                        // Rethrow: QueryRunner owns the status line, and swallowing here left it
                        // reading "Done in 0.4s." beside the red error notification.
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                // Failure while *building* the command — nothing started, report synchronously.
                Debug::getInstance()->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
                $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                $flowTableHtml = '';
                $c->sync();
            }
        }, 'flow-actions');
    }
}
