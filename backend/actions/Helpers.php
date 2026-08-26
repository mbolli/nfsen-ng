<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\NfcapdFiles;

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
}
