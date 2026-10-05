<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Enumerates the nfcapd capture files backing a time range.
 *
 * The on-disk layout is `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`
 * (see AGENTS.md). Several features need to know which files a window actually covers:
 * the Flows/Statistics tabs to show a file count, the filtered-graph builder to bin them,
 * the progress estimator to size a single nfdump run, and the Health page to find the
 * newest capture and count pending files. The scan lives here once instead of per caller.
 *
 * @phpstan-type NfcapdFile array{ts: int, path: string, relPath: string, source: string, size: int}
 * @phpstan-type NewestFile array{ts: int, name: string, path: string}
 */
final class NfcapdFiles {
    /** Matches a rotated capture file and captures its YYYYMMDDHHII stamp; `nfcapd.current.*` never matches. */
    private const FILE_PATTERN = '/^nfcapd\.(\d{12})$/';

    private static bool $stopped = false;

    /**
     * From shutdown: a running walk throws at its next day directory, since sizing a year of
     * captures on a slow disk outlasts the stop budget. False resumes (tests).
     */
    public static function stop(bool $stopped = true): void {
        self::$stopped = $stopped;
    }

    /**
     * List every nfcapd file whose timestamp falls within [$ds, $de], ascending by timestamp.
     *
     * `relPath` is the `YYYY/MM/DD/nfcapd.…` fragment nfdump expects for `-r`/`-R`, which are
     * resolved relative to the source directories given to `-M`, not an absolute path.
     * `size` is 0 when the file disappeared between the scan and the stat (nfcapd rotation
     * races the scan); callers use it only for progress weighting, so 0 is harmless.
     *
     * @param list<string> $sources
     *
     * @return list<NfcapdFile>
     */
    public static function list(int $ds, int $de, array $sources, string $profile = ''): array {
        $files = [];

        foreach ($sources as $source) {
            foreach (self::daysBetween($ds, $de) as $dayFragment) {
                $dayPath = self::sourcePath($profile, $source) . \DIRECTORY_SEPARATOR . $dayFragment;
                foreach (self::stampsIn($dayPath) as $file => $ft) {
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
     * Timestamps of one source's capture files within [$ds, $de], ascending, read from the file
     * names alone (no stat), so counting a week of files stays cheap.
     *
     * @return list<int>
     */
    public static function names(int $ds, int $de, string $source, string $profile = ''): array {
        $stamps = [];
        foreach (self::daysBetween($ds, $de) as $dayFragment) {
            $dayPath = self::sourcePath($profile, $source) . \DIRECTORY_SEPARATOR . $dayFragment;
            foreach (self::stampsIn($dayPath) as $ft) {
                if ($ft >= $ds && $ft <= $de) {
                    $stamps[] = $ft;
                }
            }
        }

        sort($stamps);

        return $stamps;
    }

    /**
     * The newest rotated capture file of a source: today's day directory in the nfcapd
     * timezone first, then up to $maxDaysBack earlier days. Null when none of them has one.
     *
     * @return ?NewestFile
     */
    public static function newest(string $profile, string $source, int $maxDaysBack = 7, ?int $now = null): ?array {
        $day = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(Config::nfcapdTimezone());
        $sourcePath = self::sourcePath($profile, $source);

        for ($back = 0; $back <= $maxDaysBack; ++$back) {
            $dayPath = $sourcePath . \DIRECTORY_SEPARATOR . self::dayFragment($day);
            $day = $day->modify('-1 day');

            $stamps = self::stampsIn($dayPath);
            if ($stamps === []) {
                continue;
            }

            $ts = max($stamps);
            $name = (string) array_search($ts, $stamps, true);

            return ['ts' => $ts, 'name' => $name, 'path' => $dayPath . \DIRECTORY_SEPARATOR . $name];
        }

        return null;
    }

    /** `<profiles-data>/<profile>/<source>`, with '' meaning the configured default profile. */
    public static function sourcePath(string $profile, string $source): string {
        return Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR . ($profile !== '' ? $profile : Config::$settings->nfdumpProfile)
            . \DIRECTORY_SEPARATOR . $source;
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

    /**
     * `YYYY/MM/DD` fragments of every nfcapd-timezone day that overlaps [$ds, $de].
     *
     * @return list<string>
     */
    private static function daysBetween(int $ds, int $de): array {
        $cur = (new \DateTimeImmutable('@' . $ds))->setTimezone(Config::nfcapdTimezone());
        $end = (new \DateTimeImmutable('@' . $de))->setTimezone(Config::nfcapdTimezone())->format('Ymd');

        $days = [];
        while ($cur->format('Ymd') <= $end) {
            $days[] = self::dayFragment($cur);
            $cur = $cur->modify('+1 day');
        }

        return $days;
    }

    private static function dayFragment(\DateTimeImmutable $day): string {
        return $day->format('Y') . \DIRECTORY_SEPARATOR . $day->format('m') . \DIRECTORY_SEPARATOR . $day->format('d');
    }

    /**
     * Rotated capture files of one day directory, keyed by file name.
     *
     * @return array<string, int> file name => timestamp from the name
     */
    private static function stampsIn(string $dayPath): array {
        if (self::$stopped) {
            throw new \RuntimeException('nfsen-ng is stopping, so the capture files are not listed.');
        }
        if (!is_dir($dayPath)) {
            return [];
        }

        $stamps = [];
        foreach (scandir($dayPath) ?: [] as $file) {
            if (!preg_match(self::FILE_PATTERN, $file, $m)) {
                continue;
            }

            $dt = \DateTimeImmutable::createFromFormat('!YmdHi', $m[1], Config::nfcapdTimezone());
            if ($dt !== false) {
                $stamps[$file] = $dt->getTimestamp();
            }
        }

        return $stamps;
    }
}
