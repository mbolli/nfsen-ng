<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * How fast capture files are being imported: the last 100 imports of this worker, from the
 * inotify path, the directory catch-up and the bulk import alike.
 */
final class ImportStats {
    public const int CAPACITY = 100;

    /** The rate is measured over this many seconds. */
    public const int WINDOW = 900;

    /** nfcapd rotates every 5 minutes: the gap assumed after a single arrival. */
    private const int ROTATION = 300;

    /** An import that starts at most this many seconds after the previous one ended joins its batch. */
    private const int BATCH_GAP = 30;

    /** @var list<array{profile: string, source: string, file: string, ms: int, ts: float}> */
    private static array $ring = [];

    public static function record(string $profile, string $source, string $file, int $ms, float|int|null $now = null): void {
        self::$ring[] = ['profile' => $profile, 'source' => $source, 'file' => $file, 'ms' => max(0, $ms), 'ts' => (float) ($now ?? microtime(true))];
        if (\count(self::$ring) > self::CAPACITY) {
            self::$ring = \array_slice(self::$ring, -self::CAPACITY);
        }
    }

    /**
     * Rate and average duration over the last 15 minutes.
     *
     * @return array{filesPerMinute: float, avgMs: float, lastTs: int, samples: int}
     */
    public static function summary(int $now): array {
        // A sample stamped later in the current second (microtime against time()) counts as now.
        $recent = array_values(array_filter(
            self::$ring,
            static fn (array $entry): bool => $entry['ts'] > $now - self::WINDOW && (int) $entry['ts'] <= $now,
        ));
        $samples = \count($recent);
        $lastTs = self::$ring === [] ? 0 : (int) max(array_column(self::$ring, 'ts'));
        if ($samples === 0) {
            return ['filesPerMinute' => 0.0, 'avgMs' => 0.0, 'lastTs' => $lastTs, 'samples' => 0];
        }

        usort($recent, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        $events = self::events($recent);
        $files = $samples;
        if ($samples === self::CAPACITY && \count($events) > 1) {
            // A full ring may have cut the oldest event short.
            $files -= array_shift($events)['files'];
        }
        $count = \count($events);
        $first = $events[0]['ts'];
        $gap = match (true) {
            $count > 1 => ($events[$count - 1]['ts'] - $first) / ($count - 1),
            $events[0]['arrival'] => self::ROTATION,
            default => 1, // several files under one whole-second stamp
        };
        // Each event covers one gap, and the rate falls off once no event follows.
        $span = min(self::WINDOW, max($count * $gap, max($now, $recent[$samples - 1]['ts']) - $first));

        return [
            'filesPerMinute' => round($files * 60 / $span, 2),
            'avgMs' => round(array_sum(array_column($recent, 'ms')) / $samples, 1),
            'lastTs' => $lastTs,
            'samples' => $samples,
        ];
    }

    /** Tests: forget every sample. */
    public static function reset(): void {
        self::$ring = [];
    }

    /**
     * A batch of back-to-back imports in which no source repeats is one arrival (one rotation of
     * every source); in any other batch, a bulk import, each distinct timestamp is an event.
     *
     * @param non-empty-list<array{profile: string, source: string, file: string, ms: int, ts: float}> $sorted
     *
     * @return non-empty-list<array{ts: float, files: int, arrival: bool}>
     */
    private static function events(array $sorted): array {
        $batches = [];
        $batch = [];
        foreach ($sorted as $entry) {
            if ($batch !== [] && $entry['ts'] - $entry['ms'] / 1000 - $batch[\count($batch) - 1]['ts'] > self::BATCH_GAP) {
                $batches[] = $batch;
                $batch = [];
            }
            $batch[] = $entry;
        }
        $batches[] = $batch;

        $events = [];
        foreach ($batches as $batch) {
            $sources = array_unique(array_map(static fn (array $entry): string => $entry['profile'] . "\0" . $entry['source'], $batch));
            if (\count($sources) === \count($batch)) {
                $events[] = ['ts' => $batch[0]['ts'], 'files' => \count($batch), 'arrival' => true];

                continue;
            }
            foreach (array_count_values(array_map(static fn (array $entry): string => (string) $entry['ts'], $batch)) as $ts => $files) {
                $events[] = ['ts' => (float) $ts, 'files' => $files, 'arrival' => false];
            }
        }

        return $events;
    }
}
