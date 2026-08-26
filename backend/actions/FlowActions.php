<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\processor\Nfdump;
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
            $flowAggBidirectional = $c->getSignal('flows_agg_bidirectional');
            $flowAggProto = $c->getSignal('flows_agg_proto');
            $flowAggSrcPort = $c->getSignal('flows_agg_srcport');
            $flowAggDstPort = $c->getSignal('flows_agg_dstport');
            $flowAggSrcIp = $c->getSignal('flows_agg_srcip');
            $flowAggSrcIpPrefix = $c->getSignal('flows_agg_srcip_prefix');
            $flowAggDstIp = $c->getSignal('flows_agg_dstip');
            $flowAggDstIpPrefix = $c->getSignal('flows_agg_dstip_prefix');
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
                && $flowAggBidirectional !== null
                && $flowAggProto !== null
                && $flowAggSrcPort !== null
                && $flowAggDstPort !== null
                && $flowAggSrcIp !== null
                && $flowAggSrcIpPrefix !== null
                && $flowAggDstIp !== null
                && $flowAggDstIpPrefix !== null
                && $flowOrderByTstart !== null
                && $flowCount !== null
                && $graphSources !== null
            );
            $time = microtime(true);

            $aggregate = Nfdump::buildAggregationString([
                'bidirectional' => $flowAggBidirectional->bool(),
                'proto' => $flowAggProto->bool(),
                'srcport' => $flowAggSrcPort->bool(),
                'dstport' => $flowAggDstPort->bool(),
                'srcip' => $flowAggSrcIp->string(),
                'srcipPrefix' => $flowAggSrcIpPrefix->string(),
                'dstip' => $flowAggDstIp->string(),
                'dstipPrefix' => $flowAggDstIpPrefix->string(),
            ]);

            try {
                $srcs = Helpers::resolveSources($graphSources->array());
                $processor = new Config::$processorClass();
                $processor->setProfile($selectedProfile->string());
                $processor->setOption('-M', implode(':', $srcs));
                $processor->setOption('-R', [$datestart->int(), $dateend->int()]);
                // Denominator for the progress estimate: bytes nfdump is about to read.
                $totalBytes = NfcapdFiles::totalSize(NfcapdFiles::list(
                    $datestart->int(),
                    $dateend->int(),
                    $srcs,
                    $selectedProfile->string()
                ));
                $processor->setOption('-c', $flowLimit->int());
                $processor->setOption('-o', 'json');
                if ($flowOrderByTstart->bool()) {
                    $processor->setOption('-O', 'tstart');
                }
                if (!empty($aggregate)) {
                    $processor->setOption(
                        $aggregate === 'bidirectional' ? '-B' : '-a',
                        $aggregate === 'bidirectional' ? '' : '-A' . $aggregate
                    );
                }
                $thresholdFilter = Nfdump::buildThresholdFilter(
                    trim($flowLowerLimit->string()),
                    trim($flowUpperLimit->string())
                );
                $combinedFlowFilter = trim($flowFilter->string());
                if ($thresholdFilter !== '') {
                    $combinedFlowFilter = $thresholdFilter . ($combinedFlowFilter !== '' ? ' and ' . $combinedFlowFilter : '');
                }
                $processor->setFilter($combinedFlowFilter);

                // nfdump runs in a coroutine so the action can return immediately and the
                // button can show real progress instead of an indeterminate spinner.
                QueryRunner::run($c, $totalBytes, 'Starting nfdump…', static function () use (
                    $processor,
                    $ipInfoAction,
                    $flowCount,
                    $time,
                    &$flowNotifications,
                    &$flowTableHtml
                ): void {
                    try {
                        $result = $processor->execute();

                        // nfdump can answer with a JSON object rather than an array; Table::generate()
                        // indexes the first row positionally, so re-key to a list.
                        $flowData = array_values((array) ($result['decoded'] ?? []));

                        $flowCount->setValue(\count($flowData), broadcast: false);
                        $elapsed = round(microtime(true) - $time, 3);
                        $cmd = htmlspecialchars((string) ($result['command'] ?? ''), ENT_QUOTES | ENT_HTML5);
                        $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                            ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                            : "Flows processed in {$elapsed}s."]];

                        if (!empty($result['stderr'])) {
                            $flowNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr'], ENT_QUOTES | ENT_HTML5)];
                        }

                        $flowTableHtml = Table::generate($flowData, 'flowTable', [
                            'hiddenFields' => [],
                            'linkIpAddresses' => true,
                            'ipInfoActionUrl' => $ipInfoAction !== null ? $ipInfoAction->url() : '',
                            'originalData' => $result['rawOutput'] ?? null,
                        ]);
                    } catch (\Throwable $e) {
                        Debug::getInstance()->log('Flow action error: ' . $e->getMessage(), LOG_ERR);
                        $flowNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                        $flowTableHtml = '';
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
