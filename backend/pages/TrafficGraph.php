<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Shell module for the persistent traffic graph above the analysis pages (1.8, 4.1.3). */
final class TrafficGraph implements ShellModule {
    private const array DISPLAY_NOUNS = ['sources' => 'source', 'protocols' => 'protocol', 'ports' => 'port'];

    public static function signals(Context $c): void {}

    public static function register(Context $c, Via $app, PageStates $states): void {}

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $mode = self::mode($activePage);
        $shell = $states->shell;
        $now = time();
        $importing = $c->getSignal('import_running')?->bool() ?? false;

        if ($mode !== 'none' && !Shell::fatal($app) && $shell->graphDue($importing, $now)) {
            self::fetch($c, $states, $now);
        }

        $datatype = $c->getSignal('graph_datatype')?->string() ?? 'traffic';
        $display = $c->getSignal('graph_display')?->string() ?? 'sources';
        $filtered = $c->getSignal('graph_mode')?->string() === 'filtered';
        $points = $c->getSignal('graph_actualResolution')?->int() ?? 0;
        $title = ucfirst($datatype) . ' by ' . (self::DISPLAY_NOUNS[$display] ?? 'source');
        $modeLabel = self::modeLabel($filtered, $points, $shell->graphStep);

        return [
            'mode' => $mode,
            'data' => $mode === 'none' ? '' : $shell->graphJson,
            'config' => json_encode(self::config($c), JSON_THROW_ON_ERROR),
            'title' => $title,
            'modeLabel' => $modeLabel,
            'height' => $mode === 'overview' ? 'tall' : 'short',
            'seriesKeys' => $shell->graphLegend,
            'live' => $c->getSignal('graph_isLive')?->bool() ?? false,
            'filtered' => $filtered,
            'points' => $points,
            'lastUpdate' => $c->getSignal('graph_lastUpdate')?->int() ?? 0,
            'ariaLabel' => $title . ', ' . $modeLabel,
            Shell::LEGACY => ['graphData' => $shell->graphJson],
        ];
    }

    /** 'none' off the analysis pages, which only happens once pages render lazily (1.2). */
    public static function mode(string $activePage): string {
        return PageRegistry::rendersAnalysis($activePage) ? 'overview' : 'none';
    }

    /** "Stored data · 5 min resolution", "Filtered data · press Apply filter" (4.1.2). */
    public static function modeLabel(bool $filtered, int $points, int $step): string {
        if ($filtered) {
            return $points > 0 ? 'Filtered data' : 'Filtered data · press Apply filter';
        }

        return $step > 0 ? 'Stored data · ' . self::humanStep($step) . ' resolution' : 'Stored data';
    }

    public static function humanStep(int $step): string {
        return match (true) {
            $step % 86400 === 0 => ($step / 86400) . ' day' . ($step === 86400 ? '' : 's'),
            $step % 3600 === 0 => ($step / 3600) . ' h',
            default => max(1, (int) round($step / 60)) . ' min',
        };
    }

    /**
     * Fetches the Overview series. A throw here would leave the graph silently frozen on
     * its previous data (#160), so it surfaces as a banner and the next render retries.
     */
    private static function fetch(Context $c, PageStates $states, int $now): void {
        $shell = $states->shell;

        try {
            $data = GraphActions::fetchGraphData($c);
            $shell->graphJson = json_encode($data, JSON_THROW_ON_ERROR);
            $shell->graphLegend = $data['legend'] ?? [];
            $shell->graphStep = $data['step'] ?? 0;
        } catch (\Throwable $e) {
            $c->getSignal('_error')?->setValue('Graph error: ' . $e->getMessage(), broadcast: false);
            $shell->graphJson = '[]';
            $shell->graphLegend = [];
            $shell->graphStep = 0;
        }
        $shell->graphFetchedAt = $now;
    }

    /** @return array<string, mixed> the server-known part of the chart configuration */
    private static function config(Context $c): array {
        return [
            'display' => $c->getSignal('graph_display')?->string() ?? 'sources',
            'sources' => $c->getSignal('graph_sources')?->array() ?? [],
            'protocols' => $c->getSignal('graph_protocols')?->array() ?? [],
            'ports' => $c->getSignal('graph_ports')?->array() ?? [],
            'type' => $c->getSignal('graph_datatype')?->string() ?? 'traffic',
            'trafficUnit' => $c->getSignal('graph_trafficUnit')?->string() ?? 'bits',
            'displayTz' => $c->getSignal('displayTz')?->string() ?? 'browser',
            'nfcapdTz' => $c->getSignal('nfcapdTz')?->string() ?? 'UTC',
        ];
    }
}
