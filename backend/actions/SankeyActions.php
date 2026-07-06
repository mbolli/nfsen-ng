<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\processor\Nfdump;
use Mbolli\PhpVia\Context;

/**
 * Sankey-diagram action registration — aggregates flows into top-N src/dst pairs
 * and renders them as a {nodes, links} payload for the nfsen-sankey chart component.
 */
final class SankeyActions {
    /**
     * Register the sankey-actions action.
     *
     * @param list<array{id: string, type: string, message: string}> $sankeyNotifications
     */
    public static function register(
        Context $c,
        array &$sankeyNotifications,
        string &$sankeyData
    ): void {
        $c->action(static function (Context $c) use (&$sankeyNotifications, &$sankeyData): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $selectedProfile = $c->getSignal('selected_profile');
            $sankeyFilter = $c->getSignal('sankey_filter');
            $sankeyTopN = $c->getSignal('sankey_topN');
            $sankeyMetric = $c->getSignal('sankey_metric');
            $sankeyLowerLimit = $c->getSignal('sankey_lower_limit');
            $sankeyUpperLimit = $c->getSignal('sankey_upper_limit');
            $graphSources = $c->getSignal('graph_sources');
            \assert(
                $datestart !== null
                && $dateend !== null
                && $selectedProfile !== null
                && $sankeyFilter !== null
                && $sankeyTopN !== null
                && $sankeyMetric !== null
                && $sankeyLowerLimit !== null
                && $sankeyUpperLimit !== null
                && $graphSources !== null
            );

            $time = microtime(true);
            $sankeyNotifications = [];

            try {
                $srcs = $graphSources->array();
                if (\in_array('any', $srcs, true) || empty($srcs)) {
                    $srcs = Config::$settings->sources;
                }

                $metric = $sankeyMetric->string() === 'packets' ? 'packets' : 'bytes';

                $processor = new Config::$processorClass();
                $processor->setProfile($selectedProfile->string());
                $processor->setOption('-M', implode(':', $srcs));

                $ds = $datestart->int();
                $de = $dateend->int();
                $maxWindow = Config::$settings->maxStatsWindow;
                if ($maxWindow > 0 && ($de - $ds) > $maxWindow) {
                    $ds = $de - $maxWindow;
                    $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => 'Time window clamped to ' . round($maxWindow / 86400, 1) . ' days (NFSEN_MAX_STATS_WINDOW).'];
                }
                $processor->setOption('-R', [$ds, $de]);

                $processor->setOption(
                    '-a',
                    '-A' . Nfdump::buildAggregationString(['srcip' => 'srcip', 'dstip' => 'dstip'])
                );
                $processor->setOption('-O', $metric);
                $processor->setOption('-n', $sankeyTopN->int());
                $processor->setOption('-N', null);
                $processor->setOption('-o', 'fmt:%sa %da %ibyt %ipkt %fl');

                // Byte thresholds are prepended to the filter expression, same as Flows/Statistics.
                $thresholdFilter = Nfdump::buildThresholdFilter(
                    trim($sankeyLowerLimit->string()),
                    trim($sankeyUpperLimit->string())
                );
                $combinedFilter = trim($sankeyFilter->string());
                if ($thresholdFilter !== '') {
                    $combinedFilter = $thresholdFilter . ($combinedFilter !== '' ? ' and ' . $combinedFilter : '');
                }
                $processor->setFilter($combinedFilter);
                $result = $processor->execute();

                $rows = $result['decoded'] ?? [];
                if (!\is_array($rows)) {
                    throw new \RuntimeException('Invalid data from nfdump processor');
                }

                $sankeyData = json_encode(self::buildSankeyPayload($rows, $metric), JSON_THROW_ON_ERROR);

                $elapsed = round(microtime(true) - $time, 3);
                $cmd = htmlspecialchars((string) ($result['command'] ?? ''), ENT_QUOTES | ENT_HTML5);
                $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                    ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                    : "Sankey data processed in {$elapsed}s."];

                if (!empty($result['stderr'])) {
                    $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars((string) $result['stderr'], ENT_QUOTES | ENT_HTML5)];
                }
            } catch (\Throwable $e) {
                Debug::getInstance()->log('Sankey action error: ' . $e->getMessage(), LOG_ERR);
                $sankeyNotifications = [['id' => bin2hex(random_bytes(4)), 'type' => 'error', 'message' => 'Error: ' . $e->getMessage()]];
                $sankeyData = json_encode(['nodes' => [], 'links' => []], JSON_THROW_ON_ERROR);
            }

            $c->sync();
        }, 'sankey-actions');

        // Dismiss a Sankey notification by ID. Kept separate from StatsActions'
        // shared dismiss-notification action to avoid coupling that class to Sankey.
        $c->action(static function (Context $c) use (&$sankeyNotifications): void {
            $id = $c->input('id') ?? '';
            if (empty($id)) {
                return;
            }
            $sankeyNotifications = array_values(array_filter($sankeyNotifications, fn ($n) => $n['id'] !== $id));
            $c->sync();
        }, 'dismiss-sankey-notification');
    }

    /**
     * Transform decoded aggregated rows (sa/da/ibyt/ipkt/fl) into a {nodes, links} payload
     * for a strict two-column bipartite Sankey — all sources on the left, all destinations
     * on the right, matching "source -> destination traffic" rather than a general flow graph.
     *
     * Self-loops (sa === da) are dropped; these do occasionally show up in real captures
     * (loopback/NAT artifacts) and have no meaning in a source-column/destination-column layout.
     *
     * Node ids are prefixed with 'src:'/'dst:' so the same IP appearing as both a source
     * (in one pair) and a destination (in another pair) becomes two distinct nodes, one per
     * column — ECharts requires unique node names, and this sidesteps reconciling which
     * column a given IP "belongs" to.
     *
     * @param array<array<string, string>> $rows
     * @param string                       $metric 'bytes' or 'packets' — selects the link value field
     *
     * @return array{nodes: list<array{name: string, label: string}>, links: list<array{source: string, target: string, value: int, flows: int}>}
     */
    public static function buildSankeyPayload(array $rows, string $metric): array {
        $valueField = $metric === 'packets' ? 'ipkt' : 'ibyt';
        $nodes = [];
        $links = [];

        foreach ($rows as $row) {
            $sa = trim((string) ($row['sa'] ?? ''));
            $da = trim((string) ($row['da'] ?? ''));
            if ($sa === '' || $da === '' || $sa === $da) {
                continue;
            }

            $sourceId = 'src:' . $sa;
            $targetId = 'dst:' . $da;
            $nodes[$sourceId] = ['name' => $sourceId, 'label' => $sa];
            $nodes[$targetId] = ['name' => $targetId, 'label' => $da];

            $links[] = [
                'source' => $sourceId,
                'target' => $targetId,
                'value' => (int) ($row[$valueField] ?? 0),
                'flows' => (int) ($row['fl'] ?? 0),
            ];
        }

        return ['nodes' => array_values($nodes), 'links' => $links];
    }
}
