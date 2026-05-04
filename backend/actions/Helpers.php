<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;

/**
 * Shared static helpers used by multiple action classes.
 */
final class Helpers {
    /** Build an nfsen-toast HTML snippet. */
    public static function makeToast(string $type, string $message, bool $autoDismiss = false): string {
        return \sprintf(
            '<nfsen-toast id="toast-%s" data-type="%s" data-message="%s"%s></nfsen-toast>',
            bin2hex(random_bytes(4)),
            htmlspecialchars($type, ENT_QUOTES),
            htmlspecialchars($message, ENT_QUOTES),
            $autoDismiss ? ' data-auto-dismiss="true"' : ''
        );
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
            $cur = (new \DateTime())->setTimestamp($ds);
            $end = (new \DateTime())->setTimestamp($de);

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

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1]);
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
