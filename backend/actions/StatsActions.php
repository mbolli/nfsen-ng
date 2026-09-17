<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Stats, dismiss-notification, and count-files action registrations.
 */
final class StatsActions {
    /**
     * Register stats-actions, dismiss-notification, and count-files actions.
     *
     * @param list<array{id: string, type: string, message: string}> $flowNotifications
     * @param list<array{id: string, type: string, message: string}> $statsNotifications
     */
    public static function register(
        Context $c,
        array &$flowNotifications,
        array &$statsNotifications,
        string &$statsTableHtml
    ): void {
        $c->action(static function (Context $c) use (&$statsNotifications, &$statsTableHtml): void {
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
            $forParam = $statsFor->string() . '/' . $statsOrderBy->string();
            $statsNotifications = [];

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
                    handle: $c->getId(),
                );

                if ($query->window->clamped) {
                    $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $query->window->clampNotice()];
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
                    &$statsNotifications,
                    &$statsTableHtml
                ): void {
                    try {
                        $result = $query->run($processor);

                        $elapsed = round(microtime(true) - $time, 3);
                        $cmd = htmlspecialchars($result->command, ENT_QUOTES | ENT_HTML5);
                        $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                            ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                            : "Statistics processed in {$elapsed}s."];

                        if ($result->stderr !== '') {
                            $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars($result->stderr, ENT_QUOTES | ENT_HTML5)];
                        }

                        $statsTableHtml = Table::generate($result->rows, 'statsTable', [
                            'hiddenFields' => [],
                            'linkIpAddresses' => true,
                            'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                            'originalData' => $result->rawOutput,
                        ]);
                    } catch (\Throwable $e) {
                        Debug::getInstance()->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
                        $statsNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                        $statsTableHtml = '';

                        // Rethrow: QueryRunner owns the status line, and swallowing here left it
                        // reading "Done in 0.4s." beside the red error notification.
                        throw $e;
                    }
                });
            } catch (\Throwable $e) {
                // Failure while *building* the command (bad window, unreadable profile) —
                // nothing was started, so report it synchronously.
                Debug::getInstance()->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
                $statsNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                $statsTableHtml = '';
                $c->sync();
            }
        }, 'stats-actions');

        // Dismiss a notification by ID from either tab
        $c->action(static function (Context $c) use (&$flowNotifications, &$statsNotifications): void {
            $id = $c->input('id') ?? '';
            if (empty($id)) {
                return;
            }
            $flowNotifications = array_values(array_filter($flowNotifications, fn ($n) => $n['id'] !== $id));
            $statsNotifications = array_values(array_filter($statsNotifications, fn ($n) => $n['id'] !== $id));
            $c->sync();
        }, 'dismiss-notification');

        // Count nfcapd files — lightweight scan triggered by date/source filter changes
        $c->action(static function (Context $c): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $graphSources = $c->getSignal('graph_sources');
            $selectedProfile = $c->getSignal('selected_profile');
            $nfcapdFileCount = $c->getSignal('nfcapd_file_count');
            \assert($datestart !== null && $dateend !== null && $graphSources !== null && $selectedProfile !== null && $nfcapdFileCount !== null);

            // Never clamped. One count serves Flows, Statistics, Sankey and the filtered
            // graph, and only some of those clamp their window, so keying the clamp on the
            // Graphs tab's mode made the Flows count under-report whenever that tab happened
            // to be in filtered mode. It describes the selected range; a consumer that reads a
            // shorter one says so itself.
            $srcs = Helpers::resolveSources($graphSources->array());
            Helpers::measureNfcapdFiles(
                $c,
                $datestart->int(),
                $dateend->int(),
                $srcs,
                $selectedProfile->string(),
            );
            $c->sync();
        }, 'count-files');
    }
}
