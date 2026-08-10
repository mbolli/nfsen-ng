<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\UserPreferences;
use Mbolli\PhpVia\Context;

/**
 * Graph-related helper methods and action registrations.
 */
final class GraphActions {
    /**
     * Fetch graph data from the datasource, updating live/resolution/last-update signals.
     *
     * @return array<string, mixed>
     */
    public static function fetchGraphData(Context $c): array {
        $datestart = $c->getSignal('datestart');
        $dateend = $c->getSignal('dateend');
        $graphDisplay = $c->getSignal('graph_display');
        $graphSources = $c->getSignal('graph_sources');
        $graphPorts = $c->getSignal('graph_ports');
        $graphProtocols = $c->getSignal('graph_protocols');
        $graphDatatype = $c->getSignal('graph_datatype');
        $graphTrafficUnit = $c->getSignal('graph_trafficUnit');
        $graphResolution = $c->getSignal('graph_resolution');
        $graphIsLive = $c->getSignal('graph_isLive');
        $graphActualRes = $c->getSignal('graph_actualResolution');
        $graphLastUpdate = $c->getSignal('graph_lastUpdate');
        $error = $c->getSignal('_error');
        $selectedProfile = $c->getSignal('selected_profile');
        \assert(
            $datestart !== null
            && $dateend !== null
            && $graphDisplay !== null
            && $graphSources !== null
            && $graphPorts !== null
            && $graphProtocols !== null
            && $graphDatatype !== null
            && $graphTrafficUnit !== null
            && $graphResolution !== null
            && $graphIsLive !== null
            && $graphActualRes !== null
            && $graphLastUpdate !== null
            && $error !== null
            && $selectedProfile !== null
        );

        $ds = $datestart->int();
        $de = $dateend->int();

        $isLive = (time() - $de) < 300;
        $graphIsLive->setValue($isLive, broadcast: false);

        $dt = $graphDatatype->string();
        $unit = ($dt !== 'traffic') ? $dt : $graphTrafficUnit->string();
        $display = $graphDisplay->string();
        $sources = self::normalizeSources($graphSources->array(), $display);
        $ports = self::normalizePorts($graphPorts->array());

        // Push the sanitized selections back at the browser. Both signals are
        // client-writable and the client's own copy can end up a different shape than
        // the server contract (a scalar, an object, strings where ints are expected) —
        // graph._config feeds those straight into the chart, where a port that isn't
        // part of a plain array takes the ports view down for good (#160). Normalizing
        // in place means one authoritative shape instead of a per-consumer guess.
        if ($graphPorts->getValue() !== $ports) {
            $graphPorts->setValue($ports, broadcast: false);
        }
        if ($graphSources->getValue() !== $sources) {
            $graphSources->setValue($sources, broadcast: false);
        }

        try {
            $data = Config::$db->get_graph_data(
                $ds,
                $de,
                $sources,
                $graphProtocols->array(),
                $ports,
                $unit,
                $display,
                $graphResolution->int(),
                $selectedProfile->string()
            );
        } catch (\Throwable $e) {
            $error->setValue('Graph error: ' . $e->getMessage(), broadcast: false);

            return [];
        }

        $pointCount = \count($data[array_key_first($data) ?? ''] ?? $data);
        $graphActualRes->setValue($pointCount, broadcast: false);

        // Use the actual RRD last-write time rather than wall-clock "now"
        $activeSources = array_values(array_filter($sources, static fn (string $s) => $s !== 'any'))
            ?: Config::$settings->sources;
        $lastWrite = empty($activeSources) ? 0 : max(array_map(
            fn ($s) => Config::$db->last_update($s, 0, $selectedProfile->string()),
            $activeSources
        ));
        $graphLastUpdate->setValue($lastWrite > 0 ? $lastWrite : time(), broadcast: false);
        $error->setValue('', broadcast: false);

        return $data;
    }

    /**
     * Sanitize the graph_sources signal before it reaches a datasource.
     *
     * The signal is client-writable, so a stale or malformed value must never
     * reach the RRD path builder: an empty entry turns into a bare
     * "<profile>/.rrd" filename and rrd_xport fails the whole graph (#160).
     * The "any" sentinel is only meaningful for the ports view, where it selects
     * the cross-source aggregate RRD; elsewhere it means "every source".
     *
     * @param array<int|string, mixed> $selected raw graph_sources signal value
     *
     * @return list<string>
     */
    public static function normalizeSources(array $selected, string $display): array {
        $sources = array_values(array_filter(
            array_map(static fn ($s) => trim((string) $s), $selected),
            static fn (string $s) => $s !== ''
        ));

        if ($sources === [] || (\in_array('any', $sources, true) && $display !== 'ports')) {
            return Config::$settings->sources;
        }

        return $sources;
    }

    /**
     * Sanitize the graph_ports signal before it reaches a datasource.
     *
     * Ports are ints everywhere on the server (Settings::$ports, the Datasource
     * get_data_path(string $source, int $port) contract), but the signal is
     * client-writable and the browser has every reason to hand back strings — a
     * <select>'s option values are strings by definition, and Datastar's bind
     * adapter only recovers the numeric type for options it has already written
     * the signal into. A string port used to reach Rrd::get_data_path() and kill
     * the whole ports view with a TypeError (#160).
     *
     * @param array<int|string, mixed> $selected raw graph_ports signal value
     *
     * @return list<int>
     */
    public static function normalizePorts(array $selected): array {
        $ports = array_values(array_map(
            static fn ($p) => (int) $p,
            array_filter($selected, static fn ($p) => is_numeric($p) && (int) $p > 0)
        ));

        return $ports === [] ? Config::$settings->ports : $ports;
    }

    /**
     * Update data_range_min / data_range_max from actual RRD boundaries.
     */
    public static function updateDataRange(Context $c): void {
        $dataRangeMin = $c->getSignal('data_range_min');
        $dataRangeMax = $c->getSignal('data_range_max');
        $selectedProfile = $c->getSignal('selected_profile');
        \assert($dataRangeMin !== null && $dataRangeMax !== null && $selectedProfile !== null);

        $sources = Config::$settings->sources;
        if (empty($sources)) {
            return;
        }

        $fallbackMin = time() - Config::$settings->importYears * 365 * 86400;
        $firsts = [];
        $lasts = [];

        foreach ($sources as $source) {
            try {
                [$first, $last] = Config::$db->date_boundaries($source, $selectedProfile->string());
                if ($first > 0) {
                    $firsts[] = $first;
                }
                if ($last > 0) {
                    $lasts[] = $last;
                }
            } catch (\Throwable) {
                // RRD may not exist yet — skip
            }
        }

        $dataRangeMin->setValue(empty($firsts) ? $fallbackMin : min($firsts), broadcast: false);
        $dataRangeMax->setValue(empty($lasts) ? time() : max($lasts), broadcast: false);
    }

    /** Register the change-profile and refresh-graphs actions. */
    public static function register(Context $c): void {
        $c->action(static function (Context $c): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            $dataRangeMax = $c->getSignal('data_range_max');
            $selectedProfile = $c->getSignal('selected_profile');
            \assert($datestart !== null && $dateend !== null && $dataRangeMax !== null && $selectedProfile !== null);

            $newProfile = $selectedProfile->string();
            $available = Config::detectProfiles();
            if (!\in_array($newProfile, $available, true)) {
                return;
            }

            $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
            $prefs->withSelectedProfile($newProfile)->save(Config::$prefsFile);

            self::updateDataRange($c);

            // Slide the visible window to the new profile's latest data
            $newMax = $dataRangeMax->int();
            $window = $dateend->int() - $datestart->int();
            $dateend->setValue($newMax, broadcast: false);
            $datestart->setValue($newMax - $window, broadcast: false);

            $c->sync();
        }, 'change-profile');

        $c->action(static function (Context $c): void {
            $datestart = $c->getSignal('datestart');
            $dateend = $c->getSignal('dateend');
            \assert($datestart !== null && $dateend !== null);

            // Advance live window if within 10 min of now
            $now = time();
            $de = $dateend->int();
            if ($now - $de < 600) {
                $window = $de - $datestart->int();
                $dateend->setValue($now, broadcast: false);
                $datestart->setValue($now - $window, broadcast: false);
            }

            self::fetchGraphData($c);
            $c->sync();
        }, 'refresh-graphs');
    }
}
