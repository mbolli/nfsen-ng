<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\StatisticCatalog;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Top Talkers (was Statistics): exact nfdump statistics for the range (4.2).
 *
 * @phpstan-import-type StatsParams from StatsActions
 * @phpstan-import-type StatsResult from TalkersState
 */
final class TalkersPage implements Page {
    /** @var array<string, string> order => how the result title names it */
    public const array ORDER_LABELS = [
        'flows' => 'flows',
        'packets' => 'packets',
        'bytes' => 'bytes',
        'pps' => 'packets per second',
        'bps' => 'bits per second',
        'bpp' => 'bytes per packet',
    ];

    /** @var list<string> the More statistics optgroups, in the order the select lists them */
    public const array GROUPS = ['Flow records', 'Addresses', 'Ports and protocols', 'AS', 'Interfaces', 'ToS', 'Masks',
        'VLAN', 'MAC', 'MPLS', 'NSEL / Cisco ASA', 'NEL / NAT'];

    /** @var array<string, string> panel => card title */
    public const array PANEL_TITLES = ['proto' => 'Protocol share', 'as' => 'Top ASNs'];

    /** A live result older than this says the window has moved on (1.7). */
    public const int LIVE_FRESH = 300;

    /** Seconds between probes of the nfdump binary for the statistics it rejects. */
    public const int PROBE_TTL = 60;

    /** @var null|array{binary: string, at: int, map: array<string, string>} the last probe */
    private static ?array $probe = null;

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
        $c->signal('any', 'stats_dir', clientWritable: true);
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
        // Rows of the last run, for the Run control's completion announcement.
        $c->signal(0, '_stats_rows');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        StatsActions::register($c, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        return self::view($states->talkers, StatsActions::params($c), self::unsupported(time()), $isUpdate, time());
    }

    /**
     * StatisticCatalog::unsupported() at most once per PROBE_TTL: a probe that timed out is not
     * cached there, and would otherwise spawn nfdump on every render.
     *
     * @param null|\Closure(string): array<string, string> $probe
     *
     * @return array<string, string> element => why this nfdump rejects it
     */
    public static function unsupported(int $now, ?string $binary = null, ?\Closure $probe = null): array {
        $binary ??= Config::$settings->nfdumpBinary;
        $last = self::$probe !== null && self::$probe['binary'] === $binary ? self::$probe : null;
        if ($last !== null && $now - $last['at'] < self::PROBE_TTL) {
            return $last['map'];
        }

        // Claimed first, so renders during a slow probe answer with the last map.
        self::$probe = ['binary' => $binary, 'at' => $now, 'map' => $last['map'] ?? []];
        $map = ($probe ?? StatisticCatalog::unsupported(...))($binary);
        self::$probe = ['binary' => $binary, 'at' => $now, 'map' => $map];

        return $map;
    }

    /**
     * Everything pages/talkers.html.twig reads, from the state and the current inputs.
     *
     * @param StatsParams           $params
     * @param array<string, string> $unsupported element => why this nfdump rejects it
     *
     * @return array<string, mixed>
     */
    public static function view(TalkersState $state, array $params, array $unsupported, bool $isUpdate, int $now): array {
        $element = StatisticCatalog::isValid($params['element']) ? $params['element'] : 'record';
        $scope = StatsActions::scope($params);
        $result = $state->result($element);

        return [
            'element' => $element,
            'tab' => StatisticCatalog::tabOf($element),
            'hasDirection' => StatisticCatalog::directionOf($element) !== null,
            'unsupported' => $unsupported[$element] ?? '',
            'catalog' => self::catalog($unsupported),
            'orders' => self::orders(),
            'counts' => StatsActions::COUNTS,
            'protocolHint' => self::protocolHint($params['protocol']),
            'stored' => array_keys($state->results),
            'result' => $result === null ? null : self::resultView($state, $element, $result, $params, $scope, $isUpdate, $now),
            'noticesFor' => $state->lastElement,
            'notifications' => $state->notifications,
            'panels' => array_map(
                static fn (string $panel): array => self::panelView($state, $panel, $params, $scope),
                TalkersState::PANELS,
            ),
        ];
    }

    /** "Src IP address, ordered by bytes", plus " · 10 rows" with a row count. */
    public static function resultTitle(string $element, string $order, ?int $rows = null): string {
        $title = StatisticCatalog::label($element) . ', ordered by ' . (self::ORDER_LABELS[$order] ?? $order);
        if ($rows === null) {
            return $title;
        }

        return $title . ' · ' . number_format($rows) . ($rows === 1 ? ' row' : ' rows');
    }

    /**
     * The picker's tabs, the More select's groups, and the maps the browser resolves a family
     * and a direction with (`_statsCatalog`).
     *
     * @param array<string, string> $unsupported
     *
     * @return array{tabs: list<array{id: string, label: string}>, groups: list<array{label: string, options: list<array{value: string, label: string, reason: string}>}>,
     *               client: array{tabOf: array<string, string>, dirOf: array<string, string>, family: array<string, array{any: string, src: string, dst: string}>, unsupported: array<string, string>}}
     */
    public static function catalog(array $unsupported): array {
        $tabs = [];
        $family = [];
        foreach (StatisticCatalog::TABS as $id => $tab) {
            $tabs[] = ['id' => $id, 'label' => $tab['label']];
            $family[$id] = self::family($id);
        }

        $options = array_fill_keys(self::GROUPS, []);
        $tabOf = [];
        $dirOf = [];
        foreach (StatisticCatalog::all() as $entry) {
            $value = $entry['value'];
            $options[$entry['group']][] = ['value' => $value, 'label' => $entry['label'], 'reason' => $unsupported[$value] ?? ''];
            $tab = StatisticCatalog::tabOf($value);
            if ($tab !== 'more') {
                $tabOf[$value] = $tab;
            }
            $direction = StatisticCatalog::directionOf($value);
            if ($direction !== null) {
                $dirOf[$value] = $direction;
                $family[$value] = self::family($value);
            }
        }

        $groups = [];
        foreach ($options as $label => $list) {
            if ($list !== []) {
                $groups[] = ['label' => $label, 'options' => $list];
            }
        }

        return [
            'tabs' => $tabs,
            'groups' => $groups,
            'client' => ['tabOf' => $tabOf, 'dirOf' => $dirOf, 'family' => $family, 'unsupported' => $unsupported],
        ];
    }

    /** @return array{any: string, src: string, dst: string} */
    private static function family(string $tabOrElement): array {
        return [
            'any' => StatisticCatalog::resolve($tabOrElement, 'any'),
            'src' => StatisticCatalog::resolve($tabOrElement, 'src'),
            'dst' => StatisticCatalog::resolve($tabOrElement, 'dst'),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function orders(): array {
        return array_map(static fn (string $o): array => ['value' => $o, 'label' => ucfirst(self::ORDER_LABELS[$o])], StatisticCatalog::ORDER_BY);
    }

    private static function protocolHint(string $protocol): string {
        return match ($protocol) {
            'tcp', 'udp', 'icmp' => 'Filtered to ' . strtoupper($protocol) . ', so the share is trivial.',
            'other' => 'Filtered to protocols other than TCP, UDP and ICMP.',
            default => '',
        };
    }

    /**
     * @param StatsResult $result
     * @param StatsParams $params
     *
     * @return array<string, mixed>
     */
    private static function resultView(TalkersState $state, string $element, array $result, array $params, string $scope, bool $isUpdate, int $now): array {
        $send = $state->sendResult('table', $result['id'], $isUpdate);
        $scopeStale = $result['scope'] !== $scope;

        return [
            'element' => $element,
            'id' => $result['id'],
            'send' => $send,
            'html' => $send ? $result['html'] : '',
            'title' => self::resultTitle($element, $result['inputs']['order'], $result['rows']),
            'caption' => self::resultTitle($element, $result['inputs']['order']),
            'rows' => $result['rows'],
            'command' => $result['command'],
            // The elapsed time is shown on its own.
            'notes' => array_values(array_filter($result['notes'], static fn (string $n): bool => !str_starts_with($n, 'Execution time'))),
            'warnings' => $result['warnings'],
            'elapsed' => $result['elapsed'],
            'from' => $result['from'],
            'to' => $result['to'],
            'stale' => $result['fingerprint'] !== StatsActions::fingerprint([...$params, 'element' => $element]),
            'scopeStale' => $scopeStale,
            'moved' => $result['live'] && !$scopeStale && $now - $result['ts'] > self::LIVE_FRESH,
            'inputs' => $result['inputs'],
            // The -A the fingerprint covers, so the browser compares what nfdump would get.
            'aggregation' => $element === 'record' ? Nfdump::buildAggregationString($result['inputs']['aggregation']) : null,
        ];
    }

    /**
     * @param StatsParams $params
     *
     * @return array<string, mixed>
     */
    private static function panelView(TalkersState $state, string $panel, array $params, string $scope): array {
        $stored = $state->panel($panel);
        $bars = $stored['bars'] ?? [];
        $maxBytes = $bars === [] ? 0 : max(array_column($bars, 'bytes'));

        return [
            'id' => $panel,
            'title' => self::PANEL_TITLES[$panel] ?? $panel,
            'ran' => $stored !== null,
            'error' => $stored['error'] ?? '',
            'command' => $stored['command'] ?? '',
            'stale' => $stored !== null && $stored['fingerprint'] !== StatsActions::panelFingerprint($params, $panel),
            'scopeStale' => $stored !== null && $stored['scope'] !== $scope,
            'inputs' => $stored['inputs'] ?? ['filter' => '', 'lower' => '', 'upper' => ''],
            'bars' => array_map(static fn (array $bar): array => [
                'label' => $bar['label'],
                'series' => $bar['series'],
                'width' => round($bar['share'] !== null ? min(100.0, $bar['share']) : ($maxBytes > 0 ? $bar['bytes'] / $maxBytes * 100 : 0.0), 1),
                'value' => $bar['share'] !== null ? number_format($bar['share'], 1) . '%' : Estimate::humanBytes($bar['bytes']),
                'bytes' => Estimate::humanBytes($bar['bytes']),
            ], $bars),
        ];
    }
}
