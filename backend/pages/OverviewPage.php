<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Overview (was Graphs): the traffic graph's configuration and the precomputed top-N (4.1). */
final class OverviewPage implements Page {
    public static function id(): string {
        return 'overview';
    }

    public static function title(): string {
        return 'Overview';
    }

    public static function lede(): string {
        return 'Traffic for the selected range, with the top talkers from precomputed data.';
    }

    public static function icon(): string {
        return 'chart-area';
    }

    public static function group(): string {
        return 'analysis';
    }

    public static function signals(Context $c): void {
        $c->signal(Config::$settings->defaultGraphDisplay, 'graph_display', clientWritable: true);
        $c->signal(Config::$settings->ports, 'graph_ports', clientWritable: true);
        $c->signal(Config::$settings->defaultGraphProtocols, 'graph_protocols', clientWritable: true);
        $c->signal(Config::$settings->defaultGraphDatatype, 'graph_datatype', clientWritable: true);
        $c->signal(500, 'graph_resolution', clientWritable: true);
        // 'stored' plots the datasource's series; 'filtered' re-reads the capture files
        // through an nfdump filter, which only run-filtered-graph may do, never a render.
        $c->signal('stored', 'graph_mode', clientWritable: true);
        $c->signal('', 'graph_filter', clientWritable: true);
        $c->signal(false, 'graph_isLive');
        $c->signal(0, 'graph_actualResolution');
        $c->signal(0, 'graph_lastUpdate');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        GraphActions::register($c);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $filtered = $c->getSignal('graph_mode')?->string() === 'filtered';

        return [
            'notifications' => $states->overview->notifications,
            Shell::LEGACY => [
                // The Apply button's projected cost, only in filtered mode.
                'filteredCost' => $filtered && !Shell::fatal($app) ? GraphActions::filteredCost($c) : null,
            ],
        ];
    }
}
