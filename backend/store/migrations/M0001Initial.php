<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store\migrations;

use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migration;

/** The initial schema: meta, top-N tiers, saved filters, alert events and query runs. */
final class M0001Initial implements Migration {
    private const string DDL = <<<'SQL'
        CREATE TABLE meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        ) WITHOUT ROWID;

        -- One row per collected capture file: coverage and interval totals (from nfdump -I).
        CREATE TABLE topn_interval (
            profile      TEXT    NOT NULL,
            source       TEXT    NOT NULL,
            ts           INTEGER NOT NULL,              -- interval start, epoch s (nfcapd file name, nfcapd TZ)
            flows        INTEGER NOT NULL DEFAULT 0,
            packets      INTEGER NOT NULL DEFAULT 0,
            bytes        INTEGER NOT NULL DEFAULT 0,
            status       INTEGER NOT NULL DEFAULT 0,    -- 0 ok, 1 empty file, 2 failed
            attempts     INTEGER NOT NULL DEFAULT 1,
            file_mtime   INTEGER NOT NULL DEFAULT 0,    -- capture file mtime when collected (skip rule, 3.2.1)
            collected_at INTEGER NOT NULL,
            PRIMARY KEY (profile, source, ts)
        ) WITHOUT ROWID;
        CREATE INDEX topn_interval_by_ts ON topn_interval (profile, ts);

        -- Per-interval top 50 per statistic and source.
        CREATE TABLE topn_5m (
            profile TEXT    NOT NULL,
            stat    INTEGER NOT NULL,                   -- TopNStat value (3.2.2)
            ts      INTEGER NOT NULL,
            source  TEXT    NOT NULL,
            key     TEXT    NOT NULL,
            flows   INTEGER NOT NULL,
            packets INTEGER NOT NULL,
            bytes   INTEGER NOT NULL,
            PRIMARY KEY (profile, stat, ts, source, key)
        ) WITHOUT ROWID;

        -- Hour rollup (ts = hour start, UTC aligned): exact sum of the topn_5m rows of that hour, every key.
        CREATE TABLE topn_1h (
            profile TEXT NOT NULL, stat INTEGER NOT NULL, ts INTEGER NOT NULL, source TEXT NOT NULL, key TEXT NOT NULL,
            flows INTEGER NOT NULL, packets INTEGER NOT NULL, bytes INTEGER NOT NULL,
            PRIMARY KEY (profile, stat, ts, source, key)
        ) WITHOUT ROWID;

        -- Day rollup (ts = day start, UTC aligned): exact sum of the topn_5m rows of that day, every key.
        CREATE TABLE topn_1d (
            profile TEXT NOT NULL, stat INTEGER NOT NULL, ts INTEGER NOT NULL, source TEXT NOT NULL, key TEXT NOT NULL,
            flows INTEGER NOT NULL, packets INTEGER NOT NULL, bytes INTEGER NOT NULL,
            PRIMARY KEY (profile, stat, ts, source, key)
        ) WITHOUT ROWID;

        CREATE TABLE saved_filters (
            id             INTEGER PRIMARY KEY,
            name           TEXT    NOT NULL,
            expression     TEXT    NOT NULL,
            expression_key TEXT    NOT NULL,            -- trim + collapse whitespace; uniqueness
            starred        INTEGER NOT NULL DEFAULT 0 CHECK (starred IN (0, 1)),
            origin         TEXT    NOT NULL DEFAULT 'user'
                           CHECK (origin IN ('user', 'browser', 'preference', 'deployment')),
            created_at     INTEGER NOT NULL,
            updated_at     INTEGER NOT NULL,
            last_used_at   INTEGER,
            use_count      INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX saved_filters_key ON saved_filters (expression_key);
        CREATE INDEX saved_filters_order ON saved_filters (starred DESC, last_used_at DESC, name);

        CREATE TABLE alert_events (
            id        INTEGER PRIMARY KEY,
            ts        INTEGER NOT NULL,
            kind      TEXT    NOT NULL CHECK (kind IN ('fired', 'resolved', 'test')),
            rule_id   TEXT,                             -- NULL for migrated entries without a matching rule
            rule_name TEXT    NOT NULL,
            profile   TEXT    NOT NULL DEFAULT '',
            sources   TEXT    NOT NULL DEFAULT '[]',    -- JSON list
            metric    TEXT    NOT NULL,
            operator  TEXT    NOT NULL DEFAULT '',
            value     REAL    NOT NULL,
            threshold REAL,                             -- NULL: no baseline
            origin    TEXT    NOT NULL DEFAULT 'live' CHECK (origin IN ('live', 'migrated'))
        );
        CREATE INDEX alert_events_by_ts ON alert_events (ts DESC);
        CREATE INDEX alert_events_by_rule ON alert_events (rule_id, ts DESC);

        CREATE TABLE query_runs (
            id         INTEGER PRIMARY KEY,
            kind       TEXT    NOT NULL,                -- query_kind (1.6)
            ts         INTEGER NOT NULL,
            bytes      INTEGER NOT NULL,                -- on-disk capture bytes the run read (upper bound)
            files      INTEGER NOT NULL DEFAULT 0,
            elapsed_ms INTEGER NOT NULL,
            ok         INTEGER NOT NULL CHECK (ok IN (0, 1))
        );
        CREATE INDEX query_runs_by_kind ON query_runs (kind, ts DESC);
        SQL;

    public function version(): int {
        return 1;
    }

    public function up(Database $db): void {
        $db->exec(self::DDL);
    }
}
