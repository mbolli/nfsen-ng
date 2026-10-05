<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\actions\RangeActions;
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

    /** The data range (first and last sample) is read at most this often per tab. */
    public const int RANGE_TTL = 60;

    /** More capture files than this still to import, and the import chip says it is behind. */
    public const int BEHIND_FILES = 12;

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

    public static function signals(Context $c): void {
        $now = time();
        $preset = Settings::normalizeRange(Config::$settings->defaultRange);

        $c->signal($now - self::PRESETS[$preset], 'datestart', clientWritable: true);
        $c->signal($now, 'dateend', clientWritable: true);
        // Data coverage, from the datasource's first and last sample; bounds the range picker.
        $c->signal($now - Config::$settings->importYears * 365 * 86400, 'data_range_min', clientWritable: false);
        $c->signal($now, 'data_range_max', clientWritable: false);
        // Set by the server, but the browser's copy keeps a pinned window pinned across a revival.
        $c->signal($preset, 'range_preset', clientWritable: true);
        $c->signal(true, 'range_live', clientWritable: true);

        $c->signal(Config::$settings->sources, 'graph_sources', clientWritable: true);
        $c->signal(self::normalizeProtocol(Config::$settings->defaultGraphProtocols[0] ?? 'any'), 'protocol', clientWritable: true);
        $c->signal(Config::$settings->defaultUnit, 'graph_trafficUnit', clientWritable: true);

        $profiles = Config::detectProfiles();
        $prefs = UserPreferences::load(Config::$prefsFile);
        $selected = $prefs !== null ? $prefs->selectedProfile : Config::$settings->nfdumpProfile;
        if (!\in_array($selected, $profiles, true)) {
            $selected = $profiles[0] ?? 'live';
        }
        $c->signal($selected, 'selected_profile', clientWritable: true);
        $c->signal($profiles, 'available_profiles', clientWritable: false);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        RangeActions::register($c, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate, string $activePage): array {
        $shell = $states->shell;
        $now = time();
        $importing = $c->getSignal('import_running')?->bool() ?? false;
        if (!Shell::fatal($app)) {
            if ($now - $shell->rangeFetchedAt >= self::RANGE_TTL) {
                GraphActions::updateDataRange($c);
                $shell->rangeFetchedAt = $now;
            }
            // Only where the graph shows the window, and only in a render that also fetches the
            // graph, so an import's throttle holds the two together (1.7).
            if (PageRegistry::rendersAnalysis($activePage) && $shell->graphDue($importing, $now)) {
                self::advanceLive($c, $now);
            }
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
            'importChip' => self::importChip(
                $importing,
                (string) $app->globalState('import_status_text', ''),
                (string) $app->globalState(HealthPage::IMPORT_OUTCOME, ''),
                (int) $app->globalState('import_progress', 0),
                (string) $app->globalState('import_eta', ''),
            ),
        ];
    }

    /**
     * The import chip (4.0.2): neutral while an import runs, a warning while it is more than
     * BEHIND_FILES files behind, an error after an import pass failed (`$outcome`, see
     * HealthPage::IMPORT_OUTCOME) until the next one starts. The file counts are read from the
     * status line the import writes ("Scanning 12 / 40 files").
     *
     * @return array{visible: bool, level: string, label: string, running: bool, progress: int, eta: string}
     */
    public static function importChip(bool $running, string $status, string $outcome, int $progress, string $eta): array {
        if (!$running) {
            $failed = $outcome === 'failed';

            return [
                'visible' => $failed,
                'level' => $failed ? 'error' : '',
                'label' => $failed ? 'Import failed' : '',
                'running' => false,
                'progress' => 0,
                'eta' => '',
            ];
        }

        $pending = preg_match('~([\d,]+)\s*/\s*([\d,]+)\s+files~', $status, $m) === 1
            ? (int) str_replace(',', '', $m[2]) - (int) str_replace(',', '', $m[1])
            : 0;
        $behind = $pending > self::BEHIND_FILES;

        return [
            'visible' => true,
            'level' => $behind ? 'warning' : '',
            'label' => $behind ? 'Import behind' : 'Import running',
            'running' => true,
            'progress' => max(0, min(100, $progress)),
            'eta' => $eta,
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
     * Slides a live window along with the clock, except under a filtered graph or a built
     * Flows traffic series: both are keyed by their window.
     */
    private static function advanceLive(Context $c, int $now): void {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        if ($datestart === null || $dateend === null || !($c->getSignal('range_live')?->bool() ?? false)) {
            return;
        }

        $flowsGraphPinned = ($c->getSignal('flows_graph_shown')?->bool() ?? false)
            && ($c->getSignal('flows_graph_key')?->string() ?? '') !== '';
        if ($c->getSignal('graph_mode')?->string() !== 'stored' || $flowsGraphPinned) {
            return;
        }

        $width = max(RangeActions::STEP, $dateend->int() - $datestart->int());
        if ($dateend->int() !== $now) {
            $dateend->setValue($now);
            $datestart->setValue($now - $width);
        }
    }
}
