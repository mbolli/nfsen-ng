<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * How often nfcapd rotates its capture files (`nfcapd -t`, #184), read from the file names: the most common gap between
 * consecutive files of a day. A day of its own, so an install that switched intervals reads each file as written.
 */
final class CaptureInterval {
    /** nfcapd's default rotation. */
    public const int DEFAULT = 300;

    /** Gaps a day needs before its interval counts. */
    private const int MIN_GAPS = 3;

    /** Today's directory still grows: its answer is read again after this long. */
    private const int TODAY_TTL = 300;

    /** Days kept in the cache. */
    private const int CACHE_DAYS = 64;

    /** @var array<string, array{interval: ?int, at: int}> "profile|source|day start" => interval */
    private static array $days = [];

    /**
     * The interval of the capture file of `$source` starting at `$ts`: its own day's, else the day before's, else
     * null when neither has enough files to tell.
     */
    public static function forFile(string $profile, string $source, int $ts): ?int {
        $day = (new \DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone())->setTime(0, 0);

        return self::ofDay($profile, $source, $day) ?? self::ofDay($profile, $source, $day->modify('-1 day'));
    }

    /** The interval of a source's newest files, or null when there are too few to tell. */
    public static function detect(string $profile, string $source): ?int {
        $newest = NfcapdFiles::newest($profile, $source, 7);

        return $newest === null ? null : self::forFile($profile, $source, $newest['ts']);
    }

    /**
     * The most common positive gap in `$stamps`, ascending file times, or null with fewer than MIN_GAPS gaps.
     *
     * @param list<int> $stamps
     */
    public static function fromStamps(array $stamps): ?int {
        $counts = [];
        for ($i = 1, $n = \count($stamps); $i < $n; ++$i) {
            $gap = $stamps[$i] - $stamps[$i - 1];
            if ($gap > 0) {
                $counts[$gap] = ($counts[$gap] ?? 0) + 1;
            }
        }
        if (array_sum($counts) < self::MIN_GAPS) {
            return null;
        }
        // The most common gap; on a tie the shorter one, since a missing file only ever lengthens a gap.
        uksort($counts, static fn (int $a, int $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return (int) array_key_first($counts);
    }

    /** Forgets what was read (tests, and a profile tree that changed under a running server). */
    public static function reset(): void {
        self::$days = [];
    }

    private static function ofDay(string $profile, string $source, \DateTimeImmutable $day): ?int {
        $start = $day->getTimestamp();
        $key = $profile . '|' . $source . '|' . $start;
        $now = time();
        $cached = self::$days[$key] ?? null;
        if ($cached !== null && ($start + 86400 <= $cached['at'] || $now - $cached['at'] < self::TODAY_TTL)) {
            return $cached['interval'];
        }
        $end = $day->modify('+1 day')->getTimestamp() - 1;
        $interval = self::fromStamps(NfcapdFiles::names($start, $end, $source, $profile));
        if (\count(self::$days) >= self::CACHE_DAYS) {
            array_shift(self::$days);
        }
        self::$days[$key] = ['interval' => $interval, 'at' => $now];

        return $interval;
    }
}
