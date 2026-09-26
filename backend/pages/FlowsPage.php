<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\FlowGraphActions;
use mbolli\nfsen_ng\common\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Flows: individual flow records for the range, and their traffic over time (4.3). */
final class FlowsPage implements Page {
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
        // a graph someone waited for.
        $c->signal('', 'flows_graph_key');
        $c->signal('', 'flows_graph_fingerprint');
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
        $c->signal(0, 'flows_count');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        FlowActions::register($c, $states);
        FlowGraphActions::register($c);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $flows = $states->flows;
        $fatal = Shell::fatal($app);
        // The series and its cost are both cheap: a cache lookup and arithmetic over the
        // counts the query kit keeps, so they render whether or not the panel is open.
        $graph = $fatal ? null : FlowGraphActions::cached($c);

        return [
            'notifications' => $flows->notifications,
            'count' => $flows->count,
            'result' => [
                'id' => $flows->resultId,
                'send' => $flows->sendResult('table', $flows->resultId, $isUpdate),
            ],
            Shell::LEGACY => [
                'flowTableHtml' => $flows->tableHtml,
                'flowNotifications' => $flows->notifications,
                // The whole series object: nfsen-chart reads {data, legend, ...}.
                'flowsGraphData' => json_encode($graph ?? [], JSON_THROW_ON_ERROR),
                'flowsGraphBuilt' => $graph !== null,
                'flowsGraphCost' => $fatal ? null : FlowGraphActions::cost($c),
                'flowsGraphStale' => !$fatal && FlowGraphActions::isStale($c),
            ],
        ];
    }
}
