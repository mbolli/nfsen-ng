<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store\migrations;

use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migration;

/** Each filtered alert rule's value per checked interval: the baseline of its percent-of-average threshold. */
final class M0003AlertSamples implements Migration {
    private const string DDL = <<<'SQL'
        CREATE TABLE alert_samples (
            rule_id     TEXT    NOT NULL,
            ts          INTEGER NOT NULL,               -- interval start, epoch s
            fingerprint TEXT    NOT NULL,               -- what the value counts (filter, metric, profile, sources)
            value       REAL    NOT NULL,
            PRIMARY KEY (rule_id, ts)
        ) WITHOUT ROWID;
        SQL;

    public function version(): int {
        return 3;
    }

    public function up(Database $db): void {
        $db->exec(self::DDL);
    }
}
