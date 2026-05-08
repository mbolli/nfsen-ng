<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;

/**
 * Shared static helpers used by multiple action classes.
 */
final class Helpers {
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
