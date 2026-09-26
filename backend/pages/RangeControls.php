<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\query\ProtocolFilter;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Shell module for the global window, sources, protocol, unit and profile (4.0.2). */
final class RangeControls implements ShellModule {
    /** Range presets and their width in seconds. */
    public const array PRESETS = ['1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000, '1y' => 31536000];

    private const array PRESET_LABELS = [
        '1h' => 'Last 1 hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '1y' => 'Last year',
    ];

    private const array PROTOCOL_LABELS = [
        'any' => 'Any protocol',
        'tcp' => 'TCP',
        'udp' => 'UDP',
        'icmp' => 'ICMP',
        'other' => 'Other',
    ];

    /** A window ending closer to now than this follows the clock. */
    private const int LIVE_SLACK = 600;

    public static function signals(Context $c): void {
        $now = time();
        $preset = Settings::normalizeRange(Config::$settings->defaultRange);

        $c->signal($now - self::PRESETS[$preset], 'datestart', clientWritable: true);
        $c->signal($now, 'dateend', clientWritable: true);
        // Data coverage, from the datasource's first and last sample; bounds the range picker.
        $c->signal($now - Config::$settings->importYears * 365 * 86400, 'data_range_min');
        $c->signal($now, 'data_range_max');
        $c->signal($preset, 'range_preset');
        $c->signal(true, 'range_live');

        // Seeded the way the Sources select presents itself in each display mode: every
        // source for "sources", one for "protocols", the "any" aggregate for "ports".
        $c->signal(match (Config::$settings->defaultGraphDisplay) {
            'ports' => ['any'],
            'protocols' => \array_slice(Config::$settings->sources, 0, 1),
            default => Config::$settings->sources,
        }, 'graph_sources', clientWritable: true);
        // 'any' until the controls bar shows a protocol picker: seeded from the graph protocols
        // preference, it would narrow every query with nothing on screen saying so.
        $c->signal('any', 'protocol', clientWritable: true);
        $c->signal(Config::$settings->defaultUnit, 'graph_trafficUnit', clientWritable: true);

        $profiles = Config::detectProfiles();
        $prefs = UserPreferences::load(Config::$prefsFile);
        $selected = $prefs !== null ? $prefs->selectedProfile : Config::$settings->nfdumpProfile;
        if (!\in_array($selected, $profiles, true)) {
            $selected = $profiles[0] ?? 'live';
        }
        $c->signal($selected, 'selected_profile', clientWritable: true);
        $c->signal($profiles, 'available_profiles');
    }

    public static function register(Context $c, Via $app, PageStates $states): void {}

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $shell = $states->shell;
        $now = time();
        $importing = $c->getSignal('import_running')?->bool() ?? false;
        if (!Shell::fatal($app) && $shell->rangeDue($importing, $now)) {
            GraphActions::updateDataRange($c);
            // The window only follows the clock where the graph shows it (1.7).
            if (PageRegistry::rendersAnalysis($activePage)) {
                self::advanceLive($c, $now);
            }
            $shell->rangeFetchedAt = $now;
        }

        $profiles = $c->getSignal('available_profiles')?->array() ?? [];

        return [
            'presets' => array_map(
                static fn (string $id): array => ['id' => $id, 'label' => self::PRESET_LABELS[$id], 'seconds' => self::PRESETS[$id]],
                array_keys(self::PRESETS),
            ),
            'profiles' => self::groupProfiles(array_values(array_filter($profiles, 'is_string'))),
            'sources' => Config::$settings->sources,
            'protocols' => array_map(
                static fn (string $id): array => ['id' => $id, 'label' => self::PROTOCOL_LABELS[$id]],
                array_keys(self::PROTOCOL_LABELS),
            ),
        ];
    }

    /** The global protocol, one of ProtocolFilter::PROTOCOLS whatever the client sent. */
    public static function protocol(Context $c): string {
        return self::normalizeProtocol($c->getSignal('protocol')?->getValue());
    }

    public static function normalizeProtocol(mixed $protocol): string {
        $p = \is_string($protocol) ? strtolower(trim($protocol)) : '';

        return \in_array($p, ProtocolFilter::PROTOCOLS, true) ? $p : 'any';
    }

    /**
     * Profiles named `group/name` grouped the way the profile select shows them: runs of
     * one group become an optgroup, plain names stand alone (group '').
     *
     * @param list<string> $profiles
     *
     * @return list<array{group: string, profiles: list<array{id: string, label: string}>}>
     */
    public static function groupProfiles(array $profiles): array {
        $groups = [];
        $current = null;
        foreach ($profiles as $profile) {
            $parts = explode('/', $profile, 2);
            $group = isset($parts[1]) ? $parts[0] : '';
            $item = ['id' => $profile, 'label' => $parts[1] ?? $profile];
            if ($current !== null && $group !== '' && $current['group'] === $group) {
                $current['profiles'][] = $item;

                continue;
            }
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = ['group' => $group, 'profiles' => [$item]];
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Slides a window that ends near now along with the clock, except under a filtered graph
     * or a built Flows traffic series: both are keyed by their window.
     */
    private static function advanceLive(Context $c, int $now): void {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        if ($datestart === null || $dateend === null) {
            return;
        }

        $flowsGraphPinned = ($c->getSignal('flows_graph_shown')?->bool() ?? false)
            && ($c->getSignal('flows_graph_key')?->string() ?? '') !== '';
        $follows = $c->getSignal('graph_mode')?->string() === 'stored' && !$flowsGraphPinned;
        $de = $dateend->int();
        $live = $follows && $now - $de < self::LIVE_SLACK;

        if ($live) {
            $window = $de - $datestart->int();
            $dateend->setValue($now, broadcast: false);
            $datestart->setValue($now - $window, broadcast: false);
        }
        $rangeLive = $c->getSignal('range_live');
        if ($rangeLive !== null && $rangeLive->bool() !== $live) {
            $rangeLive->setValue($live, broadcast: false);
        }
    }
}
