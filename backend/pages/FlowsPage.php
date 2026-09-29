<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\FlowGraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\TableFormatter;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\FlowsQuery;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Flows: individual flow records for the range, their traffic over time, and a summary (4.3).
 *
 * @phpstan-import-type ReturnedSummary from FlowsQuery
 * @phpstan-import-type RangeSummary from FlowsState
 * @phpstan-import-type FilteredSummary from FlowsState
 *
 * @phpstan-type Figure array{label: string, value: string, epoch: ?int}
 * @phpstan-type ProtocolLine array{name: string, series: ?int, flows: string, packets: string, bytes: string}
 */
final class FlowsPage implements Page {
    /** The Limit select's choices (4.3.2). */
    public const array LIMITS = [20, 50, 100, 500, 1000, 10_000];

    /** Protocol series slots of the picker graph (D25): the Summary's protocol rows share them. */
    private const array PROTOCOL_SERIES = ['tcp' => 1, 'udp' => 2, 'icmp' => 3, 'icmp6' => 3, 'ipv6-icmp' => 3, 'other' => 4];

    private const array PROTOCOL_NAMES = ['any' => 'All protocols', 'tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'other' => 'Other'];

    public static function id(): string {
        return 'flows';
    }

    public static function title(): string {
        return 'Flows';
    }

    public static function lede(): string {
        return 'Individual flow records for the selected range.';
    }

    public static function icon(): string {
        return 'rows';
    }

    public static function group(): string {
        return 'analysis';
    }

    public static function signals(Context $c): void {
        $c->signal('', 'flows_filter', clientWritable: true);
        // Traffic over time for this query (#166): hidden until asked for, because building
        // it reads capture files.
        $c->signal(false, 'flows_graph_shown', clientWritable: true);
        $c->signal('bytes', 'flows_graph_unit', clientWritable: true);
        // The cache key and fingerprint of the last build, so a moving window does not blank
        // a graph someone waited for. The browser's copy brings them back after a revival.
        $c->signal('', 'flows_graph_key', clientWritable: true);
        $c->signal('', 'flows_graph_fingerprint', clientWritable: true);
        $c->signal(Config::$settings->defaultFlowLimit, 'flows_limit', clientWritable: true);
        $c->signal(false, 'flows_agg_bidirectional', clientWritable: true);
        $c->signal(false, 'flows_agg_proto', clientWritable: true);
        $c->signal(false, 'flows_agg_srcport', clientWritable: true);
        $c->signal(false, 'flows_agg_dstport', clientWritable: true);
        $c->signal('none', 'flows_agg_srcip', clientWritable: true);
        $c->signal('', 'flows_agg_srcip_prefix', clientWritable: true);
        $c->signal('none', 'flows_agg_dstip', clientWritable: true);
        $c->signal('', 'flows_agg_dstip_prefix', clientWritable: true);
        $c->signal(false, 'flows_orderByTstart', clientWritable: true);
        // Byte thresholds, composed into the filter as bytes > / bytes <.
        $c->signal('', 'flows_lower_limit', clientWritable: true);
        $c->signal('', 'flows_upper_limit', clientWritable: true);
        $c->signal(0, 'flows_count', clientWritable: false);
        // "flows" for listed records, "rows" once aggregation merged them (the Run announcement).
        $c->signal('flows', 'flows_count_label', clientWritable: false);
        $c->signal(QueryKit::ESTIMATE_DEFAULT, FlowActions::SUMMARY_ESTIMATE, clientWritable: false);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        FlowActions::register($c, $states, $app);
        FlowGraphActions::register($c);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $flows = $states->flows;
        $fatal = Shell::fatal($app);
        // The series and its cost are both cheap: a cache lookup and arithmetic over the
        // estimate the query kit keeps, so they render whether or not the panel is open.
        $graph = $fatal ? null : FlowGraphActions::cached($c);
        $inputs = FlowActions::inputs($c);
        $hasResult = $flows->hasResult();
        $stale = $hasResult && $flows->fingerprint !== '' && $flows->fingerprint !== FlowActions::fingerprintOf($inputs);
        $filtered = $flows->filteredSummary;
        $returned = $flows->returnedSummary;

        // D26: a host goes out in full only when the client lacks it; by then the store may have
        // dropped the chunks it needs, and the page says so instead.
        $tableSend = $flows->sendResult('table', $flows->resultId, $isUpdate);
        if ($tableSend && $flows->rowChunks > 0 && !$flows->hasPayload()) {
            $flows->rowsLost = true;
        }
        $rawSend = $flows->sendResult('raw', $flows->resultId, $isUpdate);
        if ($rawSend && $flows->rawChunks > 0 && !$flows->hasPayload()) {
            $flows->rawLost = true;
        }

        return [
            'notifications' => $flows->notifications,
            'count' => $flows->count,
            'countLabel' => $flows->countLabel(),
            'hasResult' => $hasResult,
            'result' => [
                'id' => $flows->resultId,
                'send' => $tableSend,
                'lost' => $flows->rowsLost,
            ],
            'raw' => [
                'id' => $flows->resultId,
                'send' => $rawSend,
                'lost' => $flows->rawLost,
                'chunks' => $flows->rawChunks,
            ],
            'tableHtml' => $tableSend && !$flows->rowsLost ? $flows->tableForSend() : '',
            'limit' => $flows->limit,
            'limitReached' => $flows->limit > 0 && ($returned['flows'] ?? $flows->count) >= $flows->limit,
            'aggregated' => $returned['aggregated'] ?? false,
            'flowsRead' => $returned['flows'] ?? $flows->count,
            'limits' => self::limits($inputs['limit']),
            'command' => $flows->command,
            'notes' => $flows->notes,
            'elapsed' => $flows->elapsed,
            'rawEmpty' => $flows->rawKept === 0,
            'rawTruncated' => $flows->rawTruncated(),
            'rawShown' => Estimate::humanBytes($flows->rawKept),
            'rawSize' => Estimate::humanBytes($flows->rawBytes),
            'downloadName' => FlowActions::exportName($flows->windowStart, $flows->windowEnd) . '.txt',
            'stale' => $stale,
            'movedOn' => $hasResult && !$stale && $flows->live && time() - $flows->ranAt > FlowActions::LIVE_MOVED_AFTER,
            'covers' => ['from' => $flows->windowStart, 'to' => $flows->windowEnd],
            'returned' => self::returnedView($returned),
            'range' => self::rangeView($flows->rangeSummary),
            'rangePending' => $flows->rangePending,
            'filtered' => $filtered === null ? null : [
                ...self::filteredView($filtered),
                'stale' => $filtered['fingerprint'] !== FlowActions::fingerprintOf($inputs, totalsOnly: true),
            ],
            'summaryEstimate' => [
                'filterSignal' => '',
                'kind' => 'flows-summary',
                'clamped' => false,
                'validates' => false,
                'estimates' => true,
                'filterId' => '',
                'estimateId' => $c->getSignal(FlowActions::SUMMARY_ESTIMATE)?->id() ?? '',
                'earlyStop' => false,
            ],
            'graph' => [
                // The whole series object: nfsen-chart reads {data, legend, ...}.
                'data' => json_encode($graph ?? [], JSON_THROW_ON_ERROR),
                'built' => $graph !== null,
                'cost' => $fatal ? null : FlowGraphActions::cost($c),
                'stale' => !$fatal && FlowGraphActions::isStale($c),
            ],
        ];
    }

    /**
     * The Limit select's choices, plus the current value when a preference set another one.
     *
     * @return list<int>
     */
    public static function limits(int $current): array {
        $limits = self::LIMITS;
        if (!\in_array($current, $limits, true)) {
            $limits[] = $current;
            sort($limits);
        }

        return $limits;
    }

    /**
     * @param null|ReturnedSummary $s
     *
     * @return null|list<Figure>
     */
    public static function returnedView(?array $s): ?array {
        if ($s === null) {
            return null;
        }

        $figure = static fn (string $label, string $value, ?float $epoch = null): array => [
            'label' => $label,
            'value' => $value,
            'epoch' => $epoch !== null ? (int) $epoch : null,
        ];

        return [
            $figure('Flows', number_format($s['flows'])),
            $figure('Packets', number_format($s['packets'])),
            $figure('Bytes', self::format($s['bytes'], 'bytes')),
            $figure('First seen', $s['first'] !== null ? date('Y-m-d H:i:s', (int) $s['first']) : 'n/a', $s['first']),
            $figure('Last seen', $s['last'] !== null ? date('Y-m-d H:i:s', (int) $s['last']) : 'n/a', $s['last']),
            $figure('Duration', $s['duration'] !== null ? self::format($s['duration'], 'duration') : 'n/a'),
            $figure('Average bits/s', $s['bps'] !== null ? self::format($s['bps'], 'bps') : 'n/a'),
            $figure('Average packets/s', $s['pps'] !== null ? self::format($s['pps'], 'pps') : 'n/a'),
            $figure('Average bytes/packet', $s['bpp'] !== null ? number_format($s['bpp'], 1) : 'n/a'),
        ];
    }

    /**
     * @param null|RangeSummary $s
     *
     * @return null|array{available: bool, reason: string, protocols: list<ProtocolLine>}
     */
    public static function rangeView(?array $s): ?array {
        if ($s === null) {
            return null;
        }

        $lines = [];
        foreach (self::PROTOCOL_NAMES as $key => $name) {
            $t = $s['totals'][$key] ?? null;
            if ($t === null) {
                continue;
            }
            $lines[] = [
                'name' => $name,
                'series' => self::PROTOCOL_SERIES[$key] ?? null,
                'flows' => number_format($t['flows']),
                'packets' => number_format($t['packets']),
                'bytes' => self::format($t['bytes'], 'bytes'),
            ];
        }

        return ['available' => $s['available'], 'reason' => $s['reason'], 'protocols' => $lines];
    }

    /**
     * @param FilteredSummary $s
     *
     * @return array{flows: string, packets: string, bytes: string, protocols: list<ProtocolLine>, command: string, elapsed: float, ranAt: int}
     */
    public static function filteredView(array $s): array {
        $lines = [];
        foreach ($s['totals']['protocols'] as $row) {
            $key = strtolower($row['proto']);
            $lines[] = [
                'name' => $row['proto'],
                // GRE, ESP and the rest stay neutral, as on Top Talkers (2.3).
                'series' => $key !== 'other' ? self::PROTOCOL_SERIES[$key] ?? null : null,
                'flows' => number_format($row['flows']),
                'packets' => number_format($row['packets']),
                'bytes' => self::format($row['bytes'], 'bytes'),
            ];
        }

        return [
            'flows' => number_format($s['totals']['flows']),
            'packets' => number_format($s['totals']['packets']),
            'bytes' => self::format($s['totals']['bytes'], 'bytes'),
            'protocols' => $lines,
            'command' => $s['command'],
            'elapsed' => $s['elapsed'],
            'ranAt' => $s['ranAt'],
        ];
    }

    private static function format(float|int $value, string $field): string {
        return strip_tags(TableFormatter::formatCellValue($value, $field, ['linkIpAddresses' => false]));
    }
}
