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

        $files = NfcapdFiles::list($ds, $de, $sources, $profile);
        $count->setValue(\count($files), broadcast: false);
        $bytes->setValue(NfcapdFiles::totalSize($files), broadcast: false);
    }
}
