<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Enumerates the nfcapd capture files backing a time range.
 *
 * The on-disk layout is `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
 * (see AGENTS.md). Several features need to know which files a window actually covers —
 * the Flows/Statistics tabs to show a file count, the filtered-graph builder to bin them,
 * and the progress estimator to size a single nfdump run — so the scan lives here once
 * instead of being re-derived per caller.
 *
 * @phpstan-type NfcapdFile array{ts: int, path: string, relPath: string, source: string, size: int}
 */
final class NfcapdFiles {
    /** Matches a rotated capture file and captures its YYYYMMDDHHII stamp. */
    private const FILE_PATTERN = '/^nfcapd\.(\d{12})$/';

    /**
     * List every nfcapd file whose timestamp falls within [$ds, $de], ascending by timestamp.
     *
     * `relPath` is the `YYYY/MM/DD/nfcapd.…` fragment nfdump expects for `-r`/`-R`, which are
     * resolved relative to the source directories given to `-M` — not an absolute path.
     * `size` is 0 when the file disappeared between the scan and the stat (nfcapd rotation
     * races the scan); callers use it only for progress weighting, so 0 is harmless.
     *
     * @param list<string> $sources
     *
     * @return list<NfcapdFile>
     */
    public static function list(int $ds, int $de, array $sources, string $profile = ''): array {
        $sourcePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . ($profile !== '' ? $profile : Config::$settings->nfdumpProfile);

        $files = [];

        foreach ($sources as $source) {
            $cur = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($ds);
            $end = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($de);

            while ($cur->format('Ymd') <= $end->format('Ymd')) {
                $dayFragment = $cur->format('Y') . \DIRECTORY_SEPARATOR
                    . $cur->format('m') . \DIRECTORY_SEPARATOR
                    . $cur->format('d');
                $dayPath = $sourcePath . \DIRECTORY_SEPARATOR . $source . \DIRECTORY_SEPARATOR . $dayFragment;
                $cur->modify('+1 day');

                if (!is_dir($dayPath)) {
                    continue;
                }

                foreach (scandir($dayPath) ?: [] as $file) {
                    if (!preg_match(self::FILE_PATTERN, (string) $file, $m)) {
                        continue;
                    }

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1], Config::nfcapdTimezone());
                    if ($dt === false) {
                        continue;
                    }

                    $ft = $dt->getTimestamp();
                    if ($ft < $ds || $ft > $de) {
                        continue;
                    }

                    $absolute = $dayPath . \DIRECTORY_SEPARATOR . $file;
                    $files[] = [
                        'ts' => $ft,
                        'path' => $absolute,
                        'relPath' => $dayFragment . \DIRECTORY_SEPARATOR . $file,
                        'source' => $source,
                        'size' => (int) (@filesize($absolute) ?: 0),
                    ];
                }
            }
        }

        usort($files, static fn (array $a, array $b) => [$a['ts'], $a['source']] <=> [$b['ts'], $b['source']]);

        return $files;
    }

    /**
     * Total on-disk size of the files covering a range, in bytes.
     *
     * Used as the denominator when estimating how far a single long-running nfdump
     * has got (see Nfdump::progressBytes()).
     *
     * @param list<NfcapdFile> $files
     */
    public static function totalSize(array $files): int {
        $total = 0;
        foreach ($files as $file) {
            $total += $file['size'];
        }

        return $total;
    }
}
