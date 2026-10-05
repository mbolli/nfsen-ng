<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\query\TopNStat;

/**
 * Per-interval top-N rows plus exact hour and day rollups. Every statement seeks on
 * (profile, stat, ts).
 *
 * An interval is either in a rollup or marked pending (a meta row), never both; range chunks
 * read the marked intervals from topn_5m, so every answer stays exact.
 *
 * @phpstan-type Totals array{flows: int, packets: int, bytes: int}
 * @phpstan-type IntervalRow array{key: string, flows: int, packets: int, bytes: int}
 * @phpstan-type RangeRow array{key: string, source: string, flows: int, packets: int, bytes: int, intervals: int}
 * @phpstan-type RangeTotals array{flows: int, packets: int, bytes: int, intervals: int}
 * @phpstan-type Chunk array{0: string, 1: int, 2: int}
 * @phpstan-type Mark array{tier: string, stat: int, profile: string, ts: int, source: string}
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

    /** Rows per INSERT of the 5 minute rows; a shorter last batch is padded with NULL rows. */
    public const int INSERT_BATCH = 50;

    /** Meta keys of the pending marks start with this, then tier, stat, profile, ts and source. */
    public const string MARK = 'topn.pending';

    private const int HOUR_CHUNK = 6 * 3600;

    private const string SEP = "\x1f";

    /** @var array<string, int> rollup tiers and their bucket size */
    private const array ROLLUPS = [self::TIER_1H => 3600, self::TIER_1D => 86400];

    /**
     * Statistics whose day bucket holds thousands of keys get a flush transaction of their own;
     * an hour bucket, and the day bucket of every other statistic, is small enough to share one.
     *
     * @var list<int>
     */
    private const array WIDE_DAY = [TopNStat::SrcIp->value, TopNStat::DstIp->value, TopNStat::SrcPort->value, TopNStat::DstPort->value];

    private const string SQL_INTERVAL_STATE = 'SELECT status, attempts, file_mtime FROM topn_interval WHERE profile = ? AND source = ? AND ts = ?';

    private const string SQL_ROLLUP_SUBTRACT = 'UPDATE {tier} AS r SET flows = r.flows - o.flows, packets = r.packets - o.packets, bytes = r.bytes - o.bytes'
        . ' FROM (SELECT key, flows, packets, bytes FROM topn_5m WHERE profile = :p AND stat = :stat AND ts = :ts AND source = :src) AS o'
        . ' WHERE r.profile = :p AND r.stat = :stat AND r.ts = :bucket AND r.source = :src AND r.key = o.key';

    // Only the keys just subtracted can have reached zero, so the rest of the bucket is not read.
    private const string SQL_ROLLUP_DROP_ZERO = 'DELETE FROM {tier} WHERE profile = :p AND stat = :stat AND ts = :bucket AND source = :src'
        . ' AND key IN (SELECT key FROM topn_5m WHERE profile = :p AND stat = :stat AND ts = :ts AND source = :src)'
        . ' AND flows <= 0 AND packets <= 0 AND bytes <= 0';

    private const string SQL_INTERVAL_ROWS_DELETE = 'DELETE FROM topn_5m WHERE profile = :p AND stat IN ({stats}) AND ts = :ts AND source = :src';

    private const string SQL_INTERVAL_ROWS_INSERT = 'INSERT INTO topn_5m (profile, stat, ts, source, key, flows, packets, bytes)'
        . ' SELECT ?, column1, ?, ?, column2, column3, column4, column5 FROM (VALUES {rows}) WHERE column2 IS NOT NULL';

    private const string SQL_MARK = 'INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?)';

    private const string SQL_MARKS_FIND = 'SELECT key FROM meta WHERE key IN ({marks})';

    private const string SQL_MARKS_DROP = 'DELETE FROM meta WHERE key IN ({marks})';

    private const string SQL_MARKS = 'SELECT key, value FROM meta WHERE key > ? AND key < ?';

    private const string SQL_MARK_COUNT = 'SELECT COUNT(*) FROM meta WHERE key > ? AND key < ?';

    // A mark key ends in the 10 digit ts and the source, which is also its value.
    private const string SQL_FLUSH_ADD = 'INSERT INTO {tier} (profile, stat, ts, source, key, flows, packets, bytes)'
        . ' SELECT :p, :stat, :bucket, :src, m.key, SUM(m.flows), SUM(m.packets), SUM(m.bytes) FROM meta AS p CROSS JOIN topn_5m AS m'
        . ' WHERE p.key >= :lo AND p.key < :hi AND p.value = :src'
        . ' AND m.profile = :p AND m.stat = :stat AND m.ts = CAST(substr(p.key, length(:prefix) + 1, 10) AS INTEGER) AND m.source = :src'
        . ' GROUP BY m.key'
        . ' ON CONFLICT (profile, stat, ts, source, key) DO UPDATE SET'
        . ' flows = flows + excluded.flows, packets = packets + excluded.packets, bytes = bytes + excluded.bytes';

    private const string SQL_FLUSH_DONE = 'DELETE FROM meta WHERE key >= :lo AND key < :hi AND value = :src';

    private const string PRAGMA_CHECKPOINT = 'PRAGMA wal_checkpoint(PASSIVE)';

    // SET expressions read the old row, so the attempts CASE sees the previous status and mtime.
    private const string SQL_INTERVAL_UPSERT = 'INSERT INTO topn_interval (profile, source, ts, flows, packets, bytes, status, attempts, file_mtime, collected_at)'
        . ' VALUES (:p, :src, :ts, :flows, :packets, :bytes, :status, 1, :mtime, :now)'
        . ' ON CONFLICT (profile, source, ts) DO UPDATE SET flows = excluded.flows, packets = excluded.packets, bytes = excluded.bytes,'
        . ' status = excluded.status, file_mtime = excluded.file_mtime, collected_at = excluded.collected_at,'
        . ' attempts = CASE WHEN excluded.status = 2 AND status = 2 AND file_mtime = excluded.file_mtime THEN attempts + 1 ELSE 1 END';

    private const string SQL_RANGE_CHUNK = 'SELECT {group}, SUM(flows) AS flows, SUM(packets) AS packets, SUM(bytes) AS bytes, COUNT(*) AS n'
        . ' FROM {tier} WHERE profile = ? AND stat = ? AND ts >= ? AND ts < ? AND source IN ({in}) GROUP BY {group}';

    // A rollup chunk plus the 5 minute rows of the intervals still marked for that tier and statistic.
    private const string SQL_RANGE_CHUNK_ROLLUP = 'SELECT {group}, SUM(flows) AS flows, SUM(packets) AS packets, SUM(bytes) AS bytes, COUNT(*) AS n FROM ('
        . 'SELECT source, key, flows, packets, bytes FROM {tier} WHERE profile = ? AND stat = ? AND ts >= ? AND ts < ? AND source IN ({in})'
        . ' UNION ALL SELECT m.source, m.key, m.flows, m.packets, m.bytes FROM meta AS p CROSS JOIN topn_5m AS m'
        . ' WHERE p.key >= ? AND p.key < ? AND p.value IN ({in})'
        . ' AND m.profile = ? AND m.stat = ? AND m.ts = CAST(substr(p.key, length(?) + 1, 10) AS INTEGER) AND m.source = p.value'
        . ') GROUP BY {group}';

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
     * Writes one interval in one transaction with a pending mark per rollup tier and statistic. A
     * re-collected interval first takes its old rows out of the rollups that hold them.
     *
     * @param Totals                        $totals
     * @param array<int, list<IntervalRow>> $rowsByStat keyed by TopNStat value
     */
    public function storeInterval(string $profile, string $source, int $ts, array $totals, int $status, int $fileMtime, array $rowsByStat, ?int $now = null): void {
        $rows = self::mergeRows($rowsByStat);
        $at = $now ?? time();
        $interval = [':p' => $profile, ':src' => $source, ':ts' => $ts];

        /** @var array<string, array<int, string>> $marks tier => stat => mark key */
        $marks = [];
        foreach (self::ROLLUPS as $tier => $_) {
            foreach (TopNStat::cases() as $stat) {
                $marks[$tier][$stat->value] = self::markKey($tier, $stat->value, $profile, $ts, $source);
            }
        }
        $allMarks = array_merge(...array_values(array_map(array_values(...), $marks)));

        $this->db->transaction(function (Database $db) use ($profile, $source, $ts, $totals, $status, $fileMtime, $rows, $at, $interval, $marks, $allMarks): void {
            if ($db->one(self::SQL_INTERVAL_STATE, [$profile, $source, $ts]) !== null) {
                $pending = array_flip(array_map(static fn (array $row): string => (string) $row['key'], $db->all(self::marksSql(self::SQL_MARKS_FIND), $allMarks)));
                foreach (self::ROLLUPS as $tier => $size) {
                    foreach ($marks[$tier] as $stat => $mark) {
                        if (isset($pending[$mark])) {
                            continue;
                        }
                        $params = $interval + [':stat' => $stat, ':bucket' => $ts - $ts % $size];
                        $db->exec(self::sql(self::SQL_ROLLUP_SUBTRACT, $tier), $params);
                        $db->exec(self::sql(self::SQL_ROLLUP_DROP_ZERO, $tier), $params);
                    }
                }
                $db->exec(self::marksSql(self::SQL_MARKS_DROP), $allMarks);
                $db->exec(self::sql(self::SQL_INTERVAL_ROWS_DELETE), $interval);
            }

            foreach (self::insertBatches($profile, $ts, $source, $rows) as $params) {
                $db->exec(self::insertSql(), $params);
            }

            $db->exec(self::SQL_INTERVAL_UPSERT, $interval + [
                ':flows' => $totals['flows'],
                ':packets' => $totals['packets'],
                ':bytes' => $totals['bytes'],
                ':status' => $status,
                ':mtime' => $fileMtime,
                ':now' => $at,
            ]);

            foreach ($rows as $stat => $_) {
                foreach ($marks as $tierMarks) {
                    $db->exec(self::SQL_MARK, [$tierMarks[$stat], $source]);
                }
            }
        });
    }

    /**
     * Adds the marked intervals to the rollups, a source's bucket at a time (see WIDE_DAY), and
     * calls $yield after each transaction. Returns the transactions written.
     *
     * @param null|\Closure():void $yield
     */
    public function flushRollups(?\Closure $yield = null): int {
        $groups = [];
        foreach ($this->db->all(self::SQL_MARKS, self::markRange()) as $row) {
            $mark = self::parseMark((string) $row['key'], (string) $row['value']);
            if ($mark === null) {
                continue;
            }
            $bucket = $mark['ts'] - $mark['ts'] % self::ROLLUPS[$mark['tier']];
            $own = $mark['tier'] === self::TIER_1D && \in_array($mark['stat'], self::WIDE_DAY, true) ? $mark['stat'] : 0;
            $groups[implode(self::SEP, [$mark['tier'], $own, $mark['profile'], $mark['source'], $bucket])][$mark['stat']] = $mark + ['bucket' => $bucket];
        }

        $written = 0;
        foreach ($groups as $stats) {
            $this->db->transaction(static function (Database $db) use ($stats): void {
                foreach ($stats as $mark) {
                    $prefix = self::markPrefix($mark['tier'], $mark['stat'], $mark['profile']);
                    $range = [
                        ':lo' => $prefix . self::pad($mark['bucket']),
                        ':hi' => $prefix . self::pad($mark['bucket'] + self::ROLLUPS[$mark['tier']]),
                        ':src' => $mark['source'],
                    ];
                    $db->exec(self::sql(self::SQL_FLUSH_ADD, $mark['tier']), $range + [':p' => $mark['profile'], ':stat' => $mark['stat'], ':bucket' => $mark['bucket'], ':prefix' => $prefix]);
                    $db->exec(self::SQL_FLUSH_DONE, $range);
                }
            });
            ++$written;
            if ($yield !== null) {
                $yield();
            }
        }

        return $written;
    }

    /** Pending marks: one per interval, rollup tier and statistic not yet flushed. */
    public function pendingRollups(): int {
        return self::int($this->db->value(self::SQL_MARK_COUNT, self::markRange()));
    }

    /**
     * Copies the committed WAL pages into the database file. Called between writes, it keeps
     * SQLite's automatic checkpoint (1000 pages) out of them. Does nothing outside WAL mode.
     */
    public function checkpoint(): void {
        if ($this->db->journalMode() === 'wal') {
            $this->db->exec(self::PRAGMA_CHECKPOINT);
        }
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
            $params = [$profile, $stat->value, $from, $to, ...$sources];
            if ($tier !== self::TIER_5M) {
                $prefix = self::markPrefix($tier, $stat->value, $profile);
                array_push($params, $prefix . self::pad($from), $prefix . self::pad($to), ...$sources);
                array_push($params, $profile, $stat->value, $prefix);
            }
            foreach ($this->db->all(self::rangeChunkSql($tier, $perSource, \count($sources)), $params) as $row) {
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

    /** The statement one range chunk runs; a rollup chunk also reads the intervals still marked. */
    public static function rangeChunkSql(string $tier, bool $perSource, int $sourceCount): string {
        return str_replace(
            ['{tier}', '{group}', '{in}'],
            [self::assertTier($tier), $perSource ? 'source, key' : 'key', self::placeholders($sourceCount)],
            $tier === self::TIER_5M ? self::SQL_RANGE_CHUNK : self::SQL_RANGE_CHUNK_ROLLUP,
        );
    }

    /** The meta key that marks an interval's statistic as not yet added to a rollup tier. */
    public static function markKey(string $tier, int $stat, string $profile, int $ts, string $source): string {
        return self::markPrefix($tier, $stat, $profile) . self::pad($ts) . self::SEP . $source;
    }

    /** @return null|Mark */
    public static function parseMark(string $key, string $source): ?array {
        $parts = explode(self::SEP, $key, 4);
        $rest = $parts[3] ?? '';
        $tail = self::SEP . $source;
        if (\count($parts) !== 4 || $parts[0] !== self::MARK || !isset(self::ROLLUPS[$parts[1]]) || TopNStat::tryFrom((int) $parts[2]) === null
            || !str_ends_with($rest, $tail) || \strlen($rest) < \strlen($tail) + 11) {
            return null;
        }
        $head = substr($rest, 0, -\strlen($tail));
        $ts = substr($head, -10);
        if (!ctype_digit($ts) || $head[-11] !== self::SEP) {
            return null;
        }

        return ['tier' => $parts[1], 'stat' => (int) $parts[2], 'profile' => substr($head, 0, -11), 'ts' => (int) $ts, 'source' => $source];
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

    /** Every mark of one interval fits the same statement: one placeholder per tier and statistic. */
    private static function marksSql(string $template): string {
        return str_replace('{marks}', self::placeholders(\count(self::ROLLUPS) * \count(TopNStat::cases())), $template);
    }

    private static function insertSql(): string {
        return str_replace('{rows}', implode(', ', array_fill(0, self::INSERT_BATCH, '(?, ?, ?, ?, ?)')), self::SQL_INTERVAL_ROWS_INSERT);
    }

    /**
     * Parameters of the batched 5 minute row inserts, rows in primary key order.
     *
     * @param array<int, list<IntervalRow>> $rows
     *
     * @return list<list<null|int|string>>
     */
    private static function insertBatches(string $profile, int $ts, string $source, array $rows): array {
        $flat = [];
        foreach ($rows as $stat => $statRows) {
            foreach ($statRows as $row) {
                $flat[] = [$stat, $row['key'], $row['flows'], $row['packets'], $row['bytes']];
            }
        }
        usort($flat, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

        $batches = [];
        foreach (array_chunk($flat, self::INSERT_BATCH) as $chunk) {
            $params = [$profile, $ts, $source];
            foreach ($chunk as $row) {
                array_push($params, ...$row);
            }
            $batches[] = array_pad($params, 3 + 5 * self::INSERT_BATCH, null);
        }

        return $batches;
    }

    private static function markPrefix(string $tier, int $stat, string $profile): string {
        return implode(self::SEP, [self::MARK, $tier, (string) $stat, $profile]) . self::SEP;
    }

    /** @return array{0: string, 1: string} bounds that hold every mark key */
    private static function markRange(): array {
        return [self::MARK . self::SEP, self::MARK . "\x20"];
    }

    /** Fixed width, so mark keys sort by time. */
    private static function pad(int $ts): string {
        return \sprintf('%010d', $ts);
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
