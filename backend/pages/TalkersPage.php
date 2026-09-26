<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\common\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Top Talkers (was Statistics): exact nfdump statistics for the range (4.2). */
final class TalkersPage implements Page {
    public static function id(): string {
        return 'talkers';
    }

    public static function title(): string {
        return 'Top Talkers';
    }

    public static function lede(): string {
        return 'Exact top N from nfdump for the selected range, sources and protocol.';
    }

    public static function icon(): string {
        return 'list-ranked';
    }

    public static function group(): string {
        return 'analysis';
    }

    public static function signals(Context $c): void {
        $c->signal('', 'stats_filter', clientWritable: true);
        $c->signal(10, 'stats_count', clientWritable: true);
        $c->signal('record', 'stats_for', clientWritable: true);
        $c->signal(Config::$settings->defaultStatsOrderBy, 'stats_orderBy', clientWritable: true);
        // Byte thresholds become filter terms: nfdump's -l/-L do not apply to -s statistics.
        $c->signal('', 'stats_lower_limit', clientWritable: true);
        $c->signal('', 'stats_upper_limit', clientWritable: true);
        // Aggregation for the Flow Records statistic (#174), kept apart from the Flows
        // page's own set so configuring one never reshapes the other's next query.
        $c->signal(false, 'stats_agg_bidirectional', clientWritable: true);
        $c->signal(false, 'stats_agg_proto', clientWritable: true);
        $c->signal(false, 'stats_agg_srcport', clientWritable: true);
        $c->signal(false, 'stats_agg_dstport', clientWritable: true);
        $c->signal('none', 'stats_agg_srcip', clientWritable: true);
        $c->signal('', 'stats_agg_srcip_prefix', clientWritable: true);
        $c->signal('none', 'stats_agg_dstip', clientWritable: true);
        $c->signal('', 'stats_agg_dstip_prefix', clientWritable: true);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        StatsActions::register($c, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $talkers = $states->talkers;

        return [
            'notifications' => $talkers->notifications,
            'result' => [
                'id' => $talkers->resultId,
                'send' => $talkers->sendResult('table', $talkers->resultId, $isUpdate),
            ],
            Shell::LEGACY => [
                'statsTableHtml' => $talkers->tableHtml,
                'statsNotifications' => $talkers->notifications,
            ],
        ];
    }
}
