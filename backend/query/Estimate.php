<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\query;

/**
 * What a query would read and roughly how long that takes, as shown before a Run.
 */
final readonly class Estimate {
    /** 16 GiB, the same ceiling as the MCP guard's maxBytes(). */
    public const int HEAVY_BYTES = 17_179_869_184;

    public function __construct(
        public int $files,
        public int $bytes,
        public int $runs,
        /** Null when there is nothing to read. */
        public ?int $seconds,
        /** Throughput from recorded runs rather than the default. */
        public bool $measured,
        public bool $clamped,
        /** Length of the window the estimate covers, e.g. "7 days". */
        public string $window,
    ) {}

    public function isHeavy(): bool {
        return $this->bytes > self::HEAVY_BYTES;
    }

    /**
     * @return array{files: int, bytes: int, bytesHuman: string, runs: int, seconds: ?int, secondsHuman: string,
     *               measured: bool, clamped: bool, window: string, heavy: bool}
     */
    public function toArray(): array {
        return [
            'files' => $this->files,
            'bytes' => $this->bytes,
            'bytesHuman' => self::humanBytes($this->bytes),
            'runs' => $this->runs,
            'seconds' => $this->seconds,
            'secondsHuman' => self::humanSeconds($this->seconds),
            'measured' => $this->measured,
            'clamped' => $this->clamped,
            'window' => $this->window,
            'heavy' => $this->isHeavy(),
        ];
    }

    /** Binary units with one decimal below ten, e.g. "3.2 GiB", "43 MiB". */
    public static function humanBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$unit];
    }

    /**
     * The time part of "about 34 s": seconds up to two minutes, then minutes, then hours and
     * minutes, because more precision than that is not in an estimate. '' for null.
     */
    public static function humanSeconds(?int $seconds): string {
        if ($seconds === null) {
            return '';
        }
        $seconds = max(0, $seconds);
        if ($seconds < 120) {
            return $seconds . ' s';
        }

        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $rest = $minutes % 60;

        return intdiv($minutes, 60) . ' h' . ($rest > 0 ? ' ' . $rest . ' min' : '');
    }
}
