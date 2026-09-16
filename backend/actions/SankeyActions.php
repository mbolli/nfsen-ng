<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Context;

/**
 * Sankey-diagram action registration — aggregates flows into top-N src/dst pairs
 * and renders them as a {nodes, links} payload for the nfsen-sankey chart component.
 */
final class SankeyActions {
    /**
     * nfdump's `fmt:` token names mapped to the column names its aggregated `-o csv`
     * output uses for the same fields. Both name the same values; which set arrives
     * depends on the nfdump version (see the `-o` selection in register()).
     */
    private const CSV_COLUMNS = [
        'sa' => 'srcAddr',
        'da' => 'dstAddr',
        'dp' => 'dstPort',
        'ibyt' => 'bytes',
        'ipkt' => 'packets',
        'fl' => 'flows',
    ];

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
            $sankeyShowPorts = $c->getSignal('sankey_show_ports');
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
                && $sankeyShowPorts !== null
                && $sankeyLowerLimit !== null
                && $sankeyUpperLimit !== null
                && $graphSources !== null
            );

            $time = microtime(true);
            $sankeyNotifications = [];

            try {
                $query = new MatrixQuery(
                    window: TimeWindow::clamped($datestart->int(), $dateend->int()),
                    sources: Helpers::resolveSources($graphSources->array()),
                    profile: $selectedProfile->string(),
                    metric: $sankeyMetric->string(),
                    topN: $sankeyTopN->int(),
                    showPorts: $sankeyShowPorts->bool(),
                    filter: $sankeyFilter->string(),
                    lowerLimit: $sankeyLowerLimit->string(),
                    upperLimit: $sankeyUpperLimit->string(),
                    handle: $c->getId(),
                );
                $metric = $query->metric();
                $showPorts = $query->showPorts;

                if ($query->window->clamped) {
                    $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => $query->window->clampNotice()];
                }

                $result = $query->run();
                $sankeyData = json_encode(self::buildSankeyPayload($result->rows, $metric, $showPorts), JSON_THROW_ON_ERROR);

                $elapsed = round(microtime(true) - $time, 3);
                $cmd = htmlspecialchars($result->command, ENT_QUOTES | ENT_HTML5);
                $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'success', 'message' => $cmd
                    ? "<b>nfdump:</b> <code>{$cmd}</code> ({$elapsed}s)"
                    : "Sankey data processed in {$elapsed}s."];

                if ($result->stderr !== '') {
                    $sankeyNotifications[] = ['id' => bin2hex(random_bytes(4)), 'type' => 'warning', 'message' => '<b>nfdump warning:</b> ' . htmlspecialchars($result->stderr, ENT_QUOTES | ENT_HTML5)];
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
     * Transform decoded aggregated rows (sa/da/ibyt/ipkt/fl, or their `-o csv` column-name
     * equivalents — see self::CSV_COLUMNS) into a {nodes, links} payload for a strict
     * two-column bipartite Sankey — all sources on the left, all destinations on the right,
     * matching "source -> destination traffic" rather than a general flow graph.
     *
     * Self-loops (sa === da) are dropped; these do occasionally show up in real captures
     * (loopback/NAT artifacts) and have no meaning in a source-column/destination-column layout.
     *
     * Node ids are prefixed with 'src:'/'dst:' so the same IP appearing as both a source
     * (in one pair) and a destination (in another pair) becomes two distinct nodes, one per
     * column — ECharts requires unique node names, and this sidesteps reconciling which
     * column a given IP "belongs" to.
     *
     * When $showPorts is true the payload gains a middle column: each row carries a
     * destination port ('dp'), producing src IP -> dst port -> dst IP. See
     * self::buildPortSankeyPayload() for that three-column variant.
     *
     * @param array<array<string, string>> $rows
     * @param string                       $metric    'bytes' or 'packets' — selects the link value field
     * @param bool                         $showPorts add a dst-port middle column (three-column layout)
     *
     * @return array{nodes: list<array{name: string, label: string}>, links: list<array{source: string, target: string, value: int, flows: int}>}
     */
    public static function buildSankeyPayload(array $rows, string $metric, bool $showPorts = false): array {
        $valueField = $metric === 'packets' ? 'ipkt' : 'ibyt';

        if ($showPorts) {
            return self::buildPortSankeyPayload($rows, $valueField);
        }

        $nodes = [];
        $links = [];

        foreach ($rows as $row) {
            $sa = self::field($row, 'sa');
            $da = self::field($row, 'da');
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
                'value' => (int) self::field($row, $valueField),
                'flows' => (int) self::field($row, 'fl'),
            ];
        }

        return ['nodes' => array_values($nodes), 'links' => $links];
    }

    /**
     * Reads one aggregated-row field by its nfdump `fmt:` token name, falling back to the
     * equivalent `-o csv` column name.
     *
     * Rows arrive in either shape depending on the nfdump version the deployment runs (see
     * the `-o` selection in register()), and only the names differ — the values are identical.
     *
     * @param array<string, mixed> $row
     */
    private static function field(array $row, string $token): string {
        $csvName = self::CSV_COLUMNS[$token] ?? '';

        return trim((string) ($row[$token] ?? $row[$csvName] ?? ''));
    }

    /**
     * Three-column variant of buildSankeyPayload(): src IP -> dst port -> dst IP.
     *
     * Each aggregated row (sa/da/dp/…) is split into two links — src->port and
     * port->dst — that share the middle 'port:<dp>' node. Unlike the strictly
     * bipartite two-column case, neither half is unique per row (one source hits a
     * port across many destinations, and one port is reached by many sources), so
     * both link halves are summed per (source, target) pair to avoid stacking
     * duplicate ribbons. Volume conservation holds: total flow into a port node
     * equals total flow out of it.
     *
     * Node ids keep the 'src:'/'dst:' prefixes (plus 'port:' for the middle column)
     * so ECharts' topology-driven layout still yields exactly three ordered columns.
     * Self-loops (sa === da) and rows missing an endpoint or port are dropped.
     *
     * @param array<array<string, string>> $rows
     * @param string                       $valueField 'ipkt' or 'ibyt'
     *
     * @return array{nodes: list<array{name: string, label: string}>, links: list<array{source: string, target: string, value: int, flows: int}>}
     */
    private static function buildPortSankeyPayload(array $rows, string $valueField): array {
        $nodes = [];

        /** @var array<string, array{source: string, target: string, value: int, flows: int}> $links */
        $links = [];

        foreach ($rows as $row) {
            $sa = self::field($row, 'sa');
            $da = self::field($row, 'da');
            $dp = self::field($row, 'dp');
            if ($sa === '' || $da === '' || $dp === '' || $sa === $da) {
                continue;
            }

            $value = (int) self::field($row, $valueField);
            $flows = (int) self::field($row, 'fl');

            $sourceId = 'src:' . $sa;
            $portId = 'port:' . $dp;
            $targetId = 'dst:' . $da;
            $nodes[$sourceId] = ['name' => $sourceId, 'label' => $sa];
            $nodes[$portId] = ['name' => $portId, 'label' => $dp];
            $nodes[$targetId] = ['name' => $targetId, 'label' => $da];

            self::accumulateLink($links, $sourceId, $portId, $value, $flows);
            self::accumulateLink($links, $portId, $targetId, $value, $flows);
        }

        return ['nodes' => array_values($nodes), 'links' => array_values($links)];
    }

    /**
     * Sum a link's value/flows into the accumulator, keyed by its (source, target)
     * pair, creating it on first sight.
     *
     * @param array<string, array{source: string, target: string, value: int, flows: int}> $links
     */
    private static function accumulateLink(array &$links, string $source, string $target, int $value, int $flows): void {
        $key = $source . "\0" . $target;
        if (isset($links[$key])) {
            $links[$key]['value'] += $value;
            $links[$key]['flows'] += $flows;

            return;
        }
        $links[$key] = ['source' => $source, 'target' => $target, 'value' => $value, 'flows' => $flows];
    }
}
