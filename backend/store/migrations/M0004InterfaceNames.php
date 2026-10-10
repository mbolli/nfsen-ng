<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store\migrations;

use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migration;

/** Interface names an exporter sent with its flows (nfdump's %inam and %onam), per source and SNMP index (#178). */
final class M0004InterfaceNames implements Migration {
    private const string DDL = <<<'SQL'
        CREATE TABLE interface_names (
            source   TEXT    NOT NULL,
            if_index INTEGER NOT NULL,
            name     TEXT    NOT NULL,
            seen     INTEGER NOT NULL,                  -- epoch s of the capture file that named it last
            PRIMARY KEY (source, if_index)
        ) WITHOUT ROWID;
        SQL;

    public function version(): int {
        return 4;
    }

    public function up(Database $db): void {
        $db->exec(self::DDL);
    }
}
