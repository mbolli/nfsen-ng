<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\store\migrations\M0001Initial;
use mbolli\nfsen_ng\store\migrations\M0002QueryRunParts;

/**
 * Brings a database up to the newest schema this code knows. Runs only from the server
 * worker (the first {@see Database::shared()} call) and from tests, never from MCP.
 */
final class Migrator {
    /** @return list<Migration> */
    public static function all(): array {
        return [new M0001Initial(), new M0002QueryRunParts()];
    }

    public static function latestVersion(): int {
        $migrations = self::all();

        return $migrations === [] ? 0 : $migrations[array_key_last($migrations)]->version();
    }

    /**
     * Runs every migration with version > user_version, each in its own transaction, and
     * returns the final version. A newer on-disk schema is logged and left untouched.
     *
     * @param null|list<Migration> $migrations defaults to {@see all()}; tests pass their own
     */
    public static function migrate(Database $db, ?array $migrations = null): int {
        $migrations ??= self::all();
        $latest = 0;
        foreach ($migrations as $migration) {
            if ($migration->version() <= $latest) {
                throw new \LogicException('Migration versions must be strictly increasing, ' . $migration::class . ' is not.');
            }
            $latest = $migration->version();
        }

        $current = $db->schemaVersion();
        if ($current > $latest) {
            Debug::getInstance()->log(
                "SQLite store {$db->path()} has schema version {$current}, newer than {$latest} known to this nfsen-ng version. Using it read-only, nothing is dropped.",
                LOG_ERR,
            );

            return $current;
        }

        foreach ($migrations as $migration) {
            $version = $migration->version();
            if ($version <= $current) {
                continue;
            }
            $db->transaction(static function (Database $db) use ($migration, $version): void {
                // Another process may have migrated while this one waited for the write lock.
                if ($db->schemaVersion() >= $version) {
                    return;
                }
                $migration->up($db);
                $db->exec('PRAGMA user_version = ' . $version);
            });
        }

        return $db->schemaVersion();
    }
}
