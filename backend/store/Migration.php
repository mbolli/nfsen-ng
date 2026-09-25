<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * One schema step. {@see Migrator} runs it inside its own transaction and then sets
 * PRAGMA user_version to {@see version()}.
 */
interface Migration {
    /** Strictly increasing across migrations; equals PRAGMA user_version after it ran. */
    public function version(): int;

    public function up(Database $db): void;
}
