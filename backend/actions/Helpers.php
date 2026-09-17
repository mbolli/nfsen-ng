<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;
use Mbolli\PhpVia\Context;

/**
 * Shared static helpers used by multiple action classes.
 */
final class Helpers {
    /**
     * The aggregation signals of one panel, as Nfdump::buildAggregationString() wants them.
     *
     * The Flows panel and the Statistics panel each own a set of these, under their own
     * prefix, so the same eight controls can be read without either tab reaching into the
     * other's state. A signal the context does not have contributes its "off" value, which
     * keeps a panel that has not declared the whole set from aggregating by accident.
     *
     * @return array<string, mixed>
     */
    public static function aggregationFromSignals(Context $c, string $prefix): array {
        $flag = static fn (string $name): bool => $c->getSignal($prefix . $name)?->bool() ?? false;
        $text = static fn (string $name, string $default): string => $c->getSignal($prefix . $name)?->string() ?? $default;

        return [
            'bidirectional' => $flag('bidirectional'),
            'proto' => $flag('proto'),
            'srcport' => $flag('srcport'),
            'dstport' => $flag('dstport'),
            'srcip' => $text('srcip', 'none'),
            'srcipPrefix' => $text('srcip_prefix', ''),
            'dstip' => $text('dstip', 'none'),
            'dstipPrefix' => $text('dstip_prefix', ''),
        ];
    }

    /**
     * Resolve the sources selected in the UI (the graph_sources signal) to the
     * concrete list of sources to pass to nfdump's -M option.
     *
     * An empty selection or the special "any" sentinel means "all configured
     * sources"; otherwise the user's explicit selection is honoured verbatim.
     *
     * The signal is client-writable, so entries arrive as arbitrary scalars under
     * arbitrary keys — normalize before handing them to nfdump's -M option.
     *
     * @param array<int|string, mixed> $selected the graph_sources signal value
     *
     * @return list<string>
     */
    public static function resolveSources(array $selected): array {
        $sources = array_values(array_filter(
            array_map(static fn (mixed $s): string => \is_scalar($s) ? trim((string) $s) : '', $selected),
            static fn (string $s): bool => $s !== ''
        ));

        if ($sources === [] || \in_array('any', $sources, true)) {
            return Config::$settings->sources;
        }

        return $sources;
    }

    /**
     * Count nfcapd files in a date range for the given sources.
     *
     * Thin wrapper over NfcapdFiles::list() — the scan itself is shared with the
     * filtered-graph builder and the nfdump progress estimator, which need the
     * paths and sizes rather than just the tally.
     *
     * @param list<string> $sources
     */
    public static function countNfcapdFiles(int $ds, int $de, array $sources, string $profile = ''): int {
        return \count(NfcapdFiles::list($ds, $de, $sources, $profile));
    }

    /**
     * Scan once and publish both the file count and the total size behind it.
     *
     * The Flows/Statistics tabs show the count; the filtered graph also needs the size, so
     * it can state what a build will read before it starts. One scan feeds both — the walk
     * is the expensive part, not the tally.
     *
     * When $clampToFilteredWindow is set the measurement covers the window a filtered build
     * would actually read (NFSEN_MAX_STATS_WINDOW applies), so the figure shown next to
     * Apply matches the work that button will do.
     *
     * @param list<string> $sources
     */
    public static function measureNfcapdFiles(
        Context $c,
        int $ds,
        int $de,
        array $sources,
        string $profile = '',
        bool $clampToFilteredWindow = false,
    ): void {
        $count = $c->getSignal('nfcapd_file_count');
        $bytes = $c->getSignal('nfcapd_total_bytes');
        if ($count === null || $bytes === null) {
            return;
        }

        if ($clampToFilteredWindow) {
            [$ds, $de] = GraphActions::clampFilteredWindow($ds, $de);
        }

        // Skip the walk when nothing that defines it has changed. This is called from the
        // graph's change handler, which fires on every protocol/datatype/filter interaction
        // in filtered mode — and the walk now stat()s every file, so repeating it per
        // keystroke-adjacent event would block the single worker on a wide window.
        $signature = implode("\x1f", [$ds, $de, implode(',', $sources), $profile]);
        $measured = $c->getSignal('nfcapd_measured');
        if ($measured !== null && $measured->string() === $signature) {
            return;
        }

        $files = NfcapdFiles::list($ds, $de, $sources, $profile);
        $count->setValue(\count($files), broadcast: false);
        $bytes->setValue(NfcapdFiles::totalSize($files), broadcast: false);
        $measured?->setValue($signature, broadcast: false);
    }
}
