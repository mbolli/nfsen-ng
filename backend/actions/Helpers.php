<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;

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
     * Scans the filesystem path structure: profiles-data/profile/source/YYYY/MM/DD/.
     *
     * @param list<string> $sources
     */
    public static function countNfcapdFiles(int $ds, int $de, array $sources, string $profile = ''): int {
        $sourcePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . ($profile !== '' ? $profile : Config::$settings->nfdumpProfile);
        $count = 0;

        foreach ($sources as $source) {
            $cur = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($ds);
            $end = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($de);

            while ($cur->format('Ymd') <= $end->format('Ymd')) {
                $dayPath = $sourcePath
                    . \DIRECTORY_SEPARATOR . (string) $source
                    . \DIRECTORY_SEPARATOR . $cur->format('Y')
                    . \DIRECTORY_SEPARATOR . $cur->format('m')
                    . \DIRECTORY_SEPARATOR . $cur->format('d');
                $cur->modify('+1 day');

                if (!is_dir($dayPath)) {
                    continue;
                }

                foreach (scandir($dayPath) ?: [] as $file) {
                    if (!preg_match('/^nfcapd\.(\d{12})$/', (string) $file, $m)) {
                        continue;
                    }

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1], Config::nfcapdTimezone());
                    if ($dt === false) {
                        continue;
                    }

                    $ft = $dt->getTimestamp();
                    if ($ft >= $ds && $ft <= $de) {
                        ++$count;
                    }
                }
            }
        }

        return $count;
    }
}
