<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\query\TopNStat;

/**
 * Per-interval top-N rows plus exact hour and day rollups. Every statement seeks on
 * (profile, stat, ts).
 *
 * @phpstan-type Totals array{flows: int, packets: int, bytes: int}
 * @phpstan-type IntervalRow array{key: string, flows: int, packets: int, bytes: int}
 * @phpstan-type RangeRow array{key: string, source: string, flows: int, packets: int, bytes: int, intervals: int}
 * @phpstan-type RangeTotals array{flows: int, packets: int, bytes: int, intervals: int}
 * @phpstan-type Chunk array{0: string, 1: int, 2: int}
 */
final class TopNRepository {
    public const int TOP = 50;
    public const int INTERVAL = 300;

    /** Ranges up to this long are read from the 5 minute rows alone, so `intervals` is exact. */
    public const int EXACT_MAX = 6 * 3600;

    public const int STATUS_OK = 0;
    public const int STATUS_EMPTY = 1;
    public const int STATUS_FAILED = 2;

    /** A failed interval is retried until it has failed this often for the same file. */
    public const int MAX_ATTEMPTS = 3;

    /** @var list<string> */
    public const array ORDER_BY = ['bytes', 'packets', 'flows'];

    public const string TIER_5M = 'topn_5m';
    public const string TIER_1H = 'topn_1h';
    public const string TIER_1D = 'topn_1d';

    public const int CACHE_SIZE = 64;
    public const int CACHE_TTL = 300;

    private const int HOUR_CHUNK = 6 * 3600;

    private const string SQL_INTERVAL_STATE = 'SELECT status, attempts, file_mtime FROM topn_interval WHERE profile = ? AND source = ? AND ts = ?';

    private const string SQL_ROLLUP_SUBTRACT = 'UPDATE {tier} AS r SET flows = r.flows - o.flows, packets = r.packets - o.packets, bytes = r.bytes - o.bytes'
        . ' FROM (SELECT stat, key, flows, packets, bytes FROM topn_5m WHERE profile = :p AND stat IN ({stats}) AND ts = :ts AND source = :src) AS o'
        . ' WHERE r.profile = :p AND r.stat = o.stat AND r.ts = :bucket AND r.source = :src AND r.key = o.key';

    private const string SQL_ROLLUP_DROP_ZERO = 'DELETE FROM {tier} WHERE profile = :p AND stat IN ({stats}) AND ts = :bucket AND source = :src'
        . ' AND flows <= 0 AND packets <= 0 AND bytes <= 0';

    private const string SQL_INTERVAL_ROWS_DELETE = 'DELETE FROM topn_5m WHERE profile = :p AND stat IN ({stats}) AND ts = :ts AND source = :src';

    private const string SQL_INTERVAL_ROW_INSERT = 'INSERT INTO topn_5m (profile, stat, ts, source, key, flows, packets, bytes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    // SET expressions read the old row, so the attempts CASE sees the previous status and mtime.
    private const string SQL_INTERVAL_UPSERT = 'INSERT INTO topn_interval (profile, source, ts, flows, packets, bytes, status, attempts, file_mtime, collected_at)'
        . ' VALUES (:p, :src, :ts, :flows, :packets, :bytes, :status, 1, :mtime, :now)'
        . ' ON CONFLICT (profile, source, ts) DO UPDATE SET flows = excluded.flows, packets = excluded.packets, bytes = excluded.bytes,'
        . ' status = excluded.status, file_mtime = excluded.file_mtime, collected_at = excluded.collected_at,'
        . ' attempts = CASE WHEN excluded.status = 2 AND status = 2 AND file_mtime = excluded.file_mtime THEN attempts + 1 ELSE 1 END';

    private const string SQL_ROLLUP_ADD = 'INSERT INTO {tier} (profile, stat, ts, source, key, flows, packets, bytes)'
        . ' SELECT profile, stat, :bucket, source, key, flows, packets, bytes FROM topn_5m'
        . ' WHERE profile = :p AND stat IN ({stats}) AND ts = :ts AND source = :src'
        . ' ON CONFLICT (profile, stat, ts, source, key) DO UPDATE SET'
        . ' flows = flows + excluded.flows, packets = packets + excluded.packets, bytes = bytes + excluded.bytes';

    private const string SQL_RANGE_CHUNK = 'SELECT {group}, SUM(flows) AS flows, SUM(packets) AS packets, SUM(bytes) AS bytes, COUNT(*) AS n'
        . ' FROM {tier} WHERE profile = ? AND stat = ? AND ts >= ? AND ts < ? AND source IN ({in}) GROUP BY {group}';

    private const string SQL_RANGE_TOTALS = 'SELECT COALESCE(SUM(flows), 0) AS flows, COALESCE(SUM(packets), 0) AS packets,'
        . ' COALESCE(SUM(bytes), 0) AS bytes, COUNT(*) AS intervals'
        . ' FROM topn_interval WHERE profile = ? AND ts >= ? AND ts < ? AND status < 2 AND source IN ({in})';

    private const string SQL_COLLECTED_TS = 'SELECT ts FROM topn_interval WHERE profile = ? AND source = ? AND ts >= ? AND ts < ?'
        . ' AND (status < 2 OR attempts >= 3) ORDER BY ts';

    private const string SQL_PRUNE_STAT = 'DELETE FROM {tier} WHERE profile = ? AND stat = ? AND ts >= ? AND ts < ?';

    private const string SQL_PRUNE_INTERVALS = 'DELETE FROM topn_interval WHERE profile = ? AND ts >= ? AND ts < ?';

    private const string SQL_OLDEST_INTERVAL = 'SELECT MIN(ts) FROM topn_interval WHERE profile = ?';

    private const string SQL_OLDEST_STAT = 'SELECT MIN(ts) FROM {tier} WHERE profile = ? AND stat = ?';

    // topn_interval holds one row per capture file, small enough to read whole.
    private const string SQL_PROFILES = 'SELECT DISTINCT profile FROM topn_interval ORDER BY profile';

    /** @var array<string, array{generation: int, at: int, rows: list<RangeRow>, totals: RangeTotals}> */
    private static array $cache = [];

    public function __construct(private readonly Database $db) {}

    /**
     * Writes one interval and keeps both rollups exact, in one transaction. A re-collected
     * interval first takes its old rows out of the rollups; keys that drop to zero are removed.
     *
     * @param Totals                        $totals
     * @param array<int, list<IntervalRow>> $rowsByStat keyed by TopNStat value
     */
    public function storeInterval(string $profile, string $source, int $ts, array $totals, int $status, int $fileMtime, array $rowsByStat, ?int $now = null): void {
        $rows = self::mergeRows($rowsByStat);
        $at = $now ?? time();
        $interval = [':p' => $profile, ':src' => $source, ':ts' => $ts];
        $buckets = [self::TIER_1H => $ts - $ts % 3600, self::TIER_1D => $ts - $ts % 86400];

        $this->db->transaction(function (Database $db) use ($profile, $source, $ts, $totals, $status, $fileMtime, $rows, $at, $interval, $buckets): void {
            if ($db->one(self::SQL_INTERVAL_STATE, [$profile, $source, $ts]) !== null) {
                foreach ($buckets as $tier => $bucket) {
                    $db->exec(self::sql(self::SQL_ROLLUP_SUBTRACT, $tier), $interval + [':bucket' => $bucket]);
                    $db->exec(self::sql(self::SQL_ROLLUP_DROP_ZERO, $tier), [':p' => $profile, ':src' => $source, ':bucket' => $bucket]);
                }
                $db->exec(self::sql(self::SQL_INTERVAL_ROWS_DELETE), $interval);
            }

            foreach ($rows as $stat => $statRows) {
                foreach ($statRows as $row) {
                    $db->exec(self::SQL_INTERVAL_ROW_INSERT, [$profile, $stat, $ts, $source, $row['key'], $row['flows'], $row['packets'], $row['bytes']]);
                }
            }

            $db->exec(self::SQL_INTERVAL_UPSERT, $interval + [
                ':flows' => $totals['flows'],
                ':packets' => $totals['packets'],
                ':bytes' => $totals['bytes'],
                ':status' => $status,
                ':mtime' => $fileMtime,
                ':now' => $at,
            ]);

            if ($rows !== []) {
                foreach ($buckets as $tier => $bucket) {
                    $db->exec(self::sql(self::SQL_ROLLUP_ADD, $tier), $interval + [':bucket' => $bucket]);
                }
            }
        });
    }

    /**
     * The top 50 keys of a statistic over [start, end), summed over the sources (per source for
     * interfaces), ordered by $orderBy. Runs chunk by chunk and calls $yield between chunks.
     *
     * @param list<string>         $sources
     * @param null|\Closure():void $yield
     *
     * @return list<RangeRow>
     */
    public function rangeTop(string $profile, TopNStat $stat, array $sources, int $start, int $end, string $orderBy, ?\Closure $yield = null): array {
        self::assertOrder($orderBy);
        $sources = array_values(array_unique($sources));
        if ($sources === [] || $end <= $start) {
            return [];
        }

        $perSource = $stat->perSource();

        /** @var array<string, RangeRow> $merged */
        $merged = [];
        foreach (self::chunks($start, $end) as $i => [$tier, $from, $to]) {
            if ($i > 0 && $yield !== null) {
                $yield();
            }
            $sql = self::rangeChunkSql($tier, $perSource, \count($sources));
            foreach ($this->db->all($sql, [$profile, $stat->value, $from, $to, ...$sources]) as $row) {
                $key = (string) $row['key'];
                $source = $perSource ? (string) $row['source'] : '';
                $id = $source . "\0" . $key;
                $merged[$id] ??= ['key' => $key, 'source' => $source, 'flows' => 0, 'packets' => 0, 'bytes' => 0, 'intervals' => 0];
                $merged[$id]['flows'] += self::int($row['flows']);
                $merged[$id]['packets'] += self::int($row['packets']);
                $merged[$id]['bytes'] += self::int($row['bytes']);
                $merged[$id]['intervals'] += self::int($row['n']);
            }
        }

        $rows = array_values($merged);
        // strcmp, because <=> compares numeric keys such as ports as numbers and SQLite as text.
        usort($rows, static fn (array $a, array $b): int => [self::metric($b, $orderBy), $b['bytes']] <=> [self::metric($a, $orderBy), $a['bytes']]
            ?: strcmp($a['source'], $b['source'])
            ?: strcmp($a['key'], $b['key']));

        return \array_slice($rows, 0, self::TOP);
    }

    /**
     * Interval totals over [start, end) of the collected intervals (status ok or empty), and
     * how many there are.
     *
     * @param list<string> $sources
     *
     * @return RangeTotals
     */
    public function rangeTotals(string $profile, array $sources, int $start, int $end): array {
        $sources = array_values(array_unique($sources));
        if ($sources === [] || $end <= $start) {
            return ['flows' => 0, 'packets' => 0, 'bytes' => 0, 'intervals' => 0];
        }

        $sql = str_replace('{in}', self::placeholders(\count($sources)), self::SQL_RANGE_TOTALS);
        $row = $this->db->one($sql, [$profile, $start, $end, ...$sources]) ?? [];

        return [
            'flows' => self::int($row['flows'] ?? 0),
            'packets' => self::int($row['packets'] ?? 0),
            'bytes' => self::int($row['bytes'] ?? 0),
            'intervals' => self::int($row['intervals'] ?? 0),
        ];
    }

    /** @return null|array{status: int, attempts: int, fileMtime: int} */
    public function intervalState(string $profile, string $source, int $ts): ?array {
        $row = $this->db->one(self::SQL_INTERVAL_STATE, [$profile, $source, $ts]);
        if ($row === null) {
            return null;
        }

        return ['status' => self::int($row['status']), 'attempts' => self::int($row['attempts']), 'fileMtime' => self::int($row['file_mtime'])];
    }

    /**
     * Interval starts in [start, end) that need no collection: ok or empty, or failed too often.
     *
     * @return list<int>
     */
    public function collectedTs(string $profile, string $source, int $start, int $end): array {
        return array_map(
            static fn (array $row): int => self::int($row['ts']),
            $this->db->all(self::SQL_COLLECTED_TS, [$profile, $source, $start, $end]),
        );
    }

    /**
     * One DELETE: a statistic's rows of one tier in [from, to), or, without a statistic, the
     * topn_interval rows. Returns the rows deleted.
     */
    public function pruneChunk(string $profile, int $from, int $to, ?TopNStat $stat = null, string $tier = self::TIER_5M): int {
        if ($stat === null) {
            return $this->db->exec(self::SQL_PRUNE_INTERVALS, [$profile, $from, $to]);
        }

        return $this->db->exec(self::sql(self::SQL_PRUNE_STAT, self::assertTier($tier)), [$profile, $stat->value, $from, $to]);
    }

    /** Oldest collected interval of a profile, or with a statistic the oldest row of that tier. */
    public function oldestTs(string $profile, ?TopNStat $stat = null, string $tier = self::TIER_5M): ?int {
        $value = $stat === null
            ? $this->db->value(self::SQL_OLDEST_INTERVAL, [$profile])
            : $this->db->value(self::sql(self::SQL_OLDEST_STAT, self::assertTier($tier)), [$profile, $stat->value]);

        return $value === null ? null : self::int($value);
    }

    /**
     * Profiles that have collected intervals.
     *
     * @return list<string>
     */
    public function profiles(): array {
        return array_map(static fn (array $row): string => (string) $row['profile'], $this->db->all(self::SQL_PROFILES));
    }

    /**
     * Tier segments for [start, end), each a single ts range: 5 minute rows for ranges up to
     * EXACT_MAX and for the ragged edges, whole hours in 6 hour chunks, whole UTC days one by one.
     *
     * @return list<Chunk>
     */
    public static function chunks(int $start, int $end): array {
        if ($end <= $start) {
            return [];
        }
        if ($end - $start <= self::EXACT_MAX) {
            return [[self::TIER_5M, $start, $end]];
        }

        $h0 = self::ceilTo($start, 3600);
        $h1 = $end - $end % 3600;
        if ($h0 >= $h1) {
            return [[self::TIER_5M, $start, $end]];
        }
        $d0 = self::ceilTo($h0, 86400);
        $d1 = $h1 - $h1 % 86400;

        $chunks = [];
        if ($start < $h0) {
            $chunks[] = [self::TIER_5M, $start, $h0];
        }
        if ($d0 < $d1) {
            array_push($chunks, ...self::split(self::TIER_1H, $h0, $d0, self::HOUR_CHUNK));
            array_push($chunks, ...self::split(self::TIER_1D, $d0, $d1, 86400));
            array_push($chunks, ...self::split(self::TIER_1H, $d1, $h1, self::HOUR_CHUNK));
        } else {
            array_push($chunks, ...self::split(self::TIER_1H, $h0, $h1, self::HOUR_CHUNK));
        }
        if ($h1 < $end) {
            $chunks[] = [self::TIER_5M, $h1, $end];
        }

        return $chunks;
    }

    /** The statement one range chunk runs. */
    public static function rangeChunkSql(string $tier, bool $perSource, int $sourceCount): string {
        return str_replace(
            ['{tier}', '{group}', '{in}'],
            [self::assertTier($tier), $perSource ? 'source, key' : 'key', self::placeholders($sourceCount)],
            self::SQL_RANGE_CHUNK,
        );
    }

    /**
     * Range results are cached per profile, statistic, sources, window and order, without the
     * limit: the top 50 is kept and callers slice it.
     *
     * @param list<string> $sources
     */
    public static function cacheKey(string $profile, TopNStat $stat, array $sources, int $start, int $end, string $orderBy): string {
        $sources = array_values(array_unique($sources));
        sort($sources);

        return implode("\0", [$profile, (string) $stat->value, implode("\x1f", $sources), (string) $start, (string) $end, $orderBy]);
    }

    /** @return null|array{rows: list<RangeRow>, totals: RangeTotals} */
    public static function cached(string $key, int $generation, int $now): ?array {
        $entry = self::$cache[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        unset(self::$cache[$key]);
        if ($entry['generation'] !== $generation || $now - $entry['at'] >= self::CACHE_TTL || $now < $entry['at']) {
            return null;
        }
        self::$cache[$key] = $entry;

        return ['rows' => $entry['rows'], 'totals' => $entry['totals']];
    }

    /**
     * @param list<RangeRow> $rows
     * @param RangeTotals    $totals
     */
    public static function remember(string $key, int $generation, int $now, array $rows, array $totals): void {
        unset(self::$cache[$key]);
        self::$cache[$key] = ['generation' => $generation, 'at' => $now, 'rows' => $rows, 'totals' => $totals];
        while (\count(self::$cache) > self::CACHE_SIZE) {
            unset(self::$cache[array_key_first(self::$cache)]);
        }
    }

    public static function clearCache(): void {
        self::$cache = [];
    }

    /**
     * @param array<int, list<IntervalRow>> $rowsByStat
     *
     * @return array<int, list<IntervalRow>> known statistics only, duplicate keys summed
     */
    private static function mergeRows(array $rowsByStat): array {
        $merged = [];
        foreach ($rowsByStat as $stat => $rows) {
            if (TopNStat::tryFrom($stat) === null) {
                throw new \InvalidArgumentException("Unknown top-N statistic {$stat}.");
            }
            $byKey = [];
            foreach ($rows as $row) {
                $key = $row['key'];
                $byKey[$key] ??= ['key' => $key, 'flows' => 0, 'packets' => 0, 'bytes' => 0];
                $byKey[$key]['flows'] += $row['flows'];
                $byKey[$key]['packets'] += $row['packets'];
                $byKey[$key]['bytes'] += $row['bytes'];
            }
            if ($byKey !== []) {
                $merged[$stat] = array_values($byKey);
            }
        }

        return $merged;
    }

    private static function sql(string $template, string $tier = self::TIER_5M): string {
        return str_replace(['{tier}', '{stats}'], [$tier, implode(',', TopNStat::values())], $template);
    }

    /**
     * @return list<Chunk>
     */
    private static function split(string $tier, int $from, int $to, int $size): array {
        $chunks = [];
        for ($t = $from; $t < $to; $t += $size) {
            $chunks[] = [$tier, $t, min($t + $size, $to)];
        }

        return $chunks;
    }

    private static function ceilTo(int $ts, int $step): int {
        $rest = $ts % $step;

        return $rest === 0 ? $ts : $ts - $rest + $step;
    }

    private static function placeholders(int $count): string {
        return implode(', ', array_fill(0, max(1, $count), '?'));
    }

    private static function assertTier(string $tier): string {
        if (!\in_array($tier, [self::TIER_5M, self::TIER_1H, self::TIER_1D], true)) {
            throw new \InvalidArgumentException("Unknown top-N tier {$tier}.");
        }

        return $tier;
    }

    private static function assertOrder(string $orderBy): void {
        if (!\in_array($orderBy, self::ORDER_BY, true)) {
            throw new \InvalidArgumentException('Unknown order, expected one of ' . implode(', ', self::ORDER_BY) . '.');
        }
    }

    /** @param RangeRow $row */
    private static function metric(array $row, string $orderBy): int {
        return match ($orderBy) {
            'packets' => $row['packets'],
            'flows' => $row['flows'],
            default => $row['bytes'],
        };
    }

    private static function int(mixed $value): int {
        return is_numeric($value) ? (int) $value : 0;
    }
}
