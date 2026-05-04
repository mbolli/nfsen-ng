<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\processor\Nfdump;
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
                $srcs = $graphSources->array();
                if (\in_array('any', $srcs, true) || empty($srcs)) {
                    $srcs = Config::$settings->sources;
                }

                $processor = new Config::$processorClass();
                $processor->setProfile($selectedProfile->string());
                $processor->setOption('-M', implode(':', $srcs));
                $ds = $datestart->int();
                $de = $dateend->int();
                $maxWindow = Config::$settings->maxStatsWindow;
                if ($maxWindow > 0 && ($de - $ds) > $maxWindow) {
                    $ds = $de - $maxWindow;
                    $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => 'Time window clamped to ' . round($maxWindow / 86400, 1) . ' days (NFSEN_MAX_STATS_WINDOW).'];
                }
                $processor->setOption('-R', [$ds, $de]);
                $processor->setOption('-n', $statsCount->int());
                $processor->setOption('-o', 'json');
                $processor->setOption('-s', $forParam);
                // Byte thresholds are prepended to the filter expression.
                // nfdump -l/-L only work for line/packed output, not for -s stats mode.
                $thresholdFilter = Nfdump::buildThresholdFilter(
                    trim($statsLowerLimit->string()),
                    trim($statsUpperLimit->string())
                );
                $combinedFilter = trim($statsFilter->string());
                if ($thresholdFilter !== '') {
                    $combinedFilter = $thresholdFilter . ($combinedFilter !== '' ? ' and ' . $combinedFilter : '');
                }
                $processor->setFilter($combinedFilter);
                $result = $processor->execute();

                $statsData = $result['decoded'] ?? [];
                if (!\is_array($statsData)) {
                    throw new \RuntimeException('Invalid data from nfdump processor');
                }

                $elapsed = round(microtime(true) - $time, 3);
                $cmd = htmlspecialchars((string) ($result['command'] ?? ''), ENT_QUOTES | ENT_HTML5);
                $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                    ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                    : "Statistics processed in {$elapsed}s."];

                if (!empty($result['stderr'])) {
                    $statsNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr'], ENT_QUOTES | ENT_HTML5)];
                }

                $statsTableHtml = Table::generate($statsData, 'statsTable', [
                    'hiddenFields' => [],
                    'linkIpAddresses' => true,
                    'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                    'originalData' => $result['rawOutput'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Stats action error: ' . $e->getMessage(), LOG_ERR);
                $statsNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                $statsTableHtml = '';
            }

            $c->sync();
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

            $srcs = $graphSources->array();
            if (\in_array('any', $srcs, true) || empty($srcs)) {
                $srcs = Config::$settings->sources;
            }
            $nfcapdFileCount->setValue(
                Helpers::countNfcapdFiles($datestart->int(), $dateend->int(), $srcs, $selectedProfile->string()),
                broadcast: false
            );
            $c->sync();
        }, 'count-files');
    }
}
