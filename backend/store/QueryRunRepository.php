<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * Finished query runs, the source of the measured throughput in estimates.
 */
final class QueryRunRepository {
    public const int KEEP_PER_KIND = 200;

    /** medianThroughput() reads this many of the newest qualifying runs. */
    public const int SAMPLE_SIZE = 20;

    public const int MIN_SAMPLES = 3;

    /** Smaller or shorter runs are dominated by nfdump's start-up cost, not by reading. */
    public const int MIN_BYTES = 33_554_432;

    public const int MIN_ELAPSED_MS = 200;

    public function __construct(private readonly Database $db) {}

    /**
     * Keeps the newest KEEP_PER_KIND runs of the kind.
     *
     * @param int $bytes on-disk capture bytes the run read (an upper bound)
     */
    public function record(string $kind, int $bytes, int $files, int $elapsedMs, bool $ok, ?int $now = null): void {
        $now ??= time();

        $this->db->transaction(static function (Database $db) use ($kind, $bytes, $files, $elapsedMs, $ok, $now): void {
            $db->exec(
                'INSERT INTO query_runs (kind, ts, bytes, files, elapsed_ms, ok) VALUES (?, ?, ?, ?, ?, ?)',
                [$kind, $now, max(0, $bytes), max(0, $files), max(0, $elapsedMs), $ok],
            );
            $db->exec(
                'DELETE FROM query_runs WHERE kind = ? AND id NOT IN (
                    SELECT id FROM query_runs WHERE kind = ? ORDER BY ts DESC, id DESC LIMIT ?
                )',
                [$kind, $kind, self::KEEP_PER_KIND],
            );
        });
    }

    /**
     * Median bytes/s of the last SAMPLE_SIZE ok runs with at least MIN_BYTES and
     * MIN_ELAPSED_MS; null below MIN_SAMPLES such runs.
     */
    public function medianThroughput(string $kind): ?float {
        $rows = $this->db->all(
            'SELECT bytes, elapsed_ms FROM query_runs
             WHERE kind = ? AND ok = 1 AND bytes >= ? AND elapsed_ms >= ?
             ORDER BY ts DESC, id DESC LIMIT ?',
            [$kind, self::MIN_BYTES, self::MIN_ELAPSED_MS, self::SAMPLE_SIZE],
        );
        if (\count($rows) < self::MIN_SAMPLES) {
            return null;
        }

        $rates = array_map(
            static fn (array $row): float => (float) $row['bytes'] * 1000.0 / (float) $row['elapsed_ms'],
            $rows,
        );
        sort($rates);
        $count = \count($rates);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $rates[$middle] : ($rates[$middle - 1] + $rates[$middle]) / 2;
    }
}
