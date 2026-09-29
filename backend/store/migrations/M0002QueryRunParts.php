<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store\migrations;

use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migration;

/** How many nfdump processes each query run read its files with, and whether it read them twice. */
final class M0002QueryRunParts implements Migration {
    public function version(): int {
        return 2;
    }

    public function up(Database $db): void {
        $db->exec('ALTER TABLE query_runs ADD COLUMN parts INTEGER NOT NULL DEFAULT 1');
        $db->exec('ALTER TABLE query_runs ADD COLUMN passes INTEGER NOT NULL DEFAULT 1');
    }
}
