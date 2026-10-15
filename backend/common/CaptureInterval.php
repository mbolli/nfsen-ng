<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Rrd;

/**
 * How often nfcapd rotates its capture files (`nfcapd -t`, #184), read from the file names: the most common gap between
 * consecutive files of a day. A day of its own, so an install that switched intervals reads each file as written.
 */
final class CaptureInterval {
    /** nfcapd's default rotation. */
    public const int DEFAULT = 300;

    /** What NFSEN_CAPTURE_INTERVAL takes: whole minutes that divide an hour, so slots line up with the hour. */
    public const array CHOICES = ['auto', '60', '120', '180', '240', '300', '360', '600', '720', '900', '1200', '1800', '3600'];

    /** How long seconds() keeps an automatic answer. */
    private const int AUTO_TTL = 300;

    /** Gaps a day needs before its interval counts. */
    private const int MIN_GAPS = 3;

    /** Today's directory still grows: its answer is read again after this long. */
    private const int TODAY_TTL = 300;

    /** Days kept in the cache. */
    private const int CACHE_DAYS = 64;

    /** @var array<string, array{interval: ?int, at: int}> "profile|source|day start" => interval */
    private static array $days = [];

    /** @var null|array{seconds: int, at: int} */
    private static ?array $auto = null;

    /** @var null|array{seconds: ?int, at: int} */
    private static ?array $rotation = null;

    /** NFSEN_CAPTURE_INTERVAL or general.capture_interval as seconds, 0 for auto or a value not in CHOICES. */
    public static function parse(mixed $value): int {
        $value = trim((string) (\is_scalar($value) ? $value : ''));

        return $value !== 'auto' && \in_array($value, self::CHOICES, true) ? (int) $value : 0;
    }

    /**
     * The interval nfsen-ng works in (#184): slots of graphs, ranges, caches and alerts. The configured one, else the
     * step of the first source's RRD (the data already stored), else what its newest file names show, else 300.
     */
    public static function seconds(): int {
        $configured = Config::$settings->captureInterval;
        if ($configured > 0) {
            return $configured;
        }
        $now = time();
        if (self::$auto !== null && $now - self::$auto['at'] < self::AUTO_TTL) {
            return self::$auto['seconds'];
        }
        self::$auto = ['seconds' => self::automatic(), 'at' => $now];

        return self::$auto['seconds'];
    }

    /** "5 minutes", "1 minute", "1 hour": an interval in words. */
    public static function label(int $seconds): string {
        [$n, $unit] = $seconds % 3600 === 0 ? [intdiv($seconds, 3600), 'hour'] : [intdiv($seconds, 60), 'minute'];

        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }

    /** `$ts` floored to the interval. */
    public static function floor(int $ts): int {
        $i = self::seconds();

        return $ts - (($ts % $i) + $i) % $i;
    }

    /** `$ts` floored to nfcapd's rotation, so the file covering it is read: finer than seconds() before a rebuild. */
    public static function fileFloor(int $ts): int {
        $i = self::files();

        return $ts - (($ts % $i) + $i) % $i;
    }

    /** How often the first source's nfcapd rotates, as its newest files show, else seconds(). */
    public static function files(): int {
        $now = time();
        if (self::$rotation === null || $now - self::$rotation['at'] >= self::AUTO_TTL) {
            $source = Config::$settings->sources[0] ?? null;

            try {
                $detected = $source === null ? null : self::detect('', $source);
            } catch (\Throwable) {
                $detected = null;
            }
            self::$rotation = ['seconds' => $detected, 'at' => $now];
        }

        return self::$rotation['seconds'] !== null && self::$rotation['seconds'] > 0 && self::$rotation['seconds'] <= 3600
            ? self::$rotation['seconds']
            : self::seconds();
    }

    /** `$ts` raised to the interval. */
    public static function ceil(int $ts): int {
        $floor = self::floor($ts);

        return $floor === $ts ? $ts : $floor + self::seconds();
    }

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
        self::$auto = null;
        self::$rotation = null;
    }

    private static function automatic(): int {
        $source = Config::$settings->sources[0] ?? null;
        if ($source === null) {
            return self::DEFAULT;
        }
        if (strtolower(Config::$settings->datasourceName) === 'rrd' && \function_exists('rrd_info')) {
            try {
                $file = Rrd::pathFor($source);
                if (is_file($file)) {
                    return Rrd::stepOf($file);
                }
            } catch (\Throwable) {
            }
        }

        try {
            $detected = self::detect('', $source);
        } catch (\Throwable) {
            $detected = null;
        }

        return $detected !== null && \in_array((string) $detected, self::CHOICES, true) ? $detected : self::DEFAULT;
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
