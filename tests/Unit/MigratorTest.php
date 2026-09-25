<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migration;
use mbolli\nfsen_ng\store\migrations\M0001Initial;
use mbolli\nfsen_ng\store\Migrator;

/** @return array<string, list<string>> table and index names by type */
function migratorTestSchema(Database $db): array {
    $schema = ['table' => [], 'index' => []];
    foreach ($db->all("SELECT type, name FROM sqlite_master WHERE type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
        $schema[(string) $row['type']][] = (string) $row['name'];
    }

    return $schema;
}

function migratorTestMigration(int $version, Closure $up): Migration {
    return new class($version, $up) implements Migration {
        public function __construct(private readonly int $version, private readonly Closure $up) {}

        public function version(): int {
            return $this->version;
        }

        public function up(Database $db): void {
            ($this->up)($db);
        }
    };
}

beforeEach(function (): void {
    // The downgrade case logs at LOG_ERR, which Debug echoes on the CLI.
    ob_start();
});

afterEach(function (): void {
    ob_end_clean();
});

test('the migration list is strictly increasing and starts with M0001Initial', function (): void {
    $migrations = Migrator::all();
    $versions = array_map(fn (Migration $m) => $m->version(), $migrations);
    $sorted = $versions;
    sort($sorted);

    expect($migrations[0])->toBeInstanceOf(M0001Initial::class)
        ->and($versions)->toBe(array_values(array_unique($sorted)))
        ->and(Migrator::latestVersion())->toBe(end($versions))
        ->and(Migrator::latestVersion())->toBe(1)
    ;
});

test('a fresh database reaches version 1 with every table and index', function (): void {
    $db = Database::open(':memory:');

    expect($db->schemaVersion())->toBe(1)
        ->and(migratorTestSchema($db))->toBe([
            'table' => ['alert_events', 'meta', 'query_runs', 'saved_filters', 'topn_1d', 'topn_1h', 'topn_5m', 'topn_interval'],
            'index' => [
                'alert_events_by_rule',
                'alert_events_by_ts',
                'query_runs_by_kind',
                'saved_filters_key',
                'saved_filters_order',
                'topn_interval_by_ts',
            ],
        ])
    ;
});

test('a file created by another tool at version 0 is migrated on open', function (): void {
    $path = sys_get_temp_dir() . '/nfsen-migrator-' . bin2hex(random_bytes(6)) . '.sqlite';
    new PDO('sqlite:' . $path)->exec('CREATE TABLE unrelated (x INTEGER)');

    try {
        $db = Database::open($path);

        expect($db->schemaVersion())->toBe(1)
            ->and(migratorTestSchema($db)['table'])->toContain('unrelated', 'meta', 'topn_5m')
        ;
    } finally {
        unset($db);
        array_map('unlink', glob($path . '*') ?: []);
    }
});

test('the schema enforces its constraints', function (): void {
    $db = Database::open(':memory:');
    $insertFilter = 'INSERT INTO saved_filters (name, expression, expression_key, origin, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0)';
    $db->exec($insertFilter, ['Web', 'port 443', 'port 443', 'user']);

    expect(fn () => $db->exec($insertFilter, ['Again', 'port  443', 'port 443', 'user']))->toThrow(PDOException::class)
        ->and(fn () => $db->exec($insertFilter, ['Other', 'port 80', 'port 80', 'somewhere']))->toThrow(PDOException::class)
        ->and(fn () => $db->exec("INSERT INTO alert_events (ts, kind, rule_name, metric, value) VALUES (0, 'maybe', 'r', 'bytes', 1)"))->toThrow(PDOException::class)
        ->and(fn () => $db->exec('INSERT INTO query_runs (kind, ts, bytes, elapsed_ms, ok) VALUES (?, 0, 0, 0, 2)', ['flows']))->toThrow(PDOException::class)
    ;
});

test('re-running is a no-op', function (): void {
    $db = Database::open(':memory:');
    $db->metaSet('kept', '1');
    $schema = migratorTestSchema($db);

    expect(Migrator::migrate($db))->toBe(1)
        ->and(migratorTestSchema($db))->toBe($schema)
        ->and($db->metaGet('kept'))->toBe('1')
    ;
});

test('a higher on-disk version is reported and nothing is dropped', function (): void {
    $db = Database::open(':memory:');
    $db->metaSet('kept', '1');
    $db->exec('PRAGMA user_version = 7');
    Debug::drainBuffer();

    expect(Migrator::migrate($db))->toBe(7)
        ->and($db->schemaVersion())->toBe(7)
        ->and($db->metaGet('kept'))->toBe('1')
        ->and(migratorTestSchema($db)['table'])->toContain('topn_5m', 'saved_filters')
    ;

    $logged = array_values(array_filter(
        Debug::drainBuffer(),
        fn (array $entry) => str_contains($entry['msg'], 'has schema version 7, newer than 1'),
    ));
    expect($logged)->toHaveCount(1)
        ->and($logged[0]['level'])->toBe(LOG_ERR)
        ->and($logged[0]['msg'])->toContain(':memory:')
    ;
});

test('runs only the migrations above the current version, each in its own transaction', function (): void {
    $db = Database::open(':memory:');
    $ran = [];
    $migrations = [
        new M0001Initial(),
        migratorTestMigration(2, function (Database $db) use (&$ran): void {
            $ran[] = 2;
            $db->exec('CREATE TABLE second (x INTEGER)');
        }),
        migratorTestMigration(3, function (Database $db) use (&$ran): void {
            $ran[] = 3;
            $db->exec('CREATE TABLE third (x INTEGER)');

            throw new RuntimeException('migration 3 failed');
        }),
    ];

    expect(fn () => Migrator::migrate($db, $migrations))->toThrow(RuntimeException::class, 'migration 3 failed')
        ->and($ran)->toBe([2, 3])
        ->and($db->schemaVersion())->toBe(2)
        ->and(migratorTestSchema($db)['table'])->toContain('second')->not->toContain('third')
    ;
});

test('rejects migration versions that do not increase', function (): void {
    $db = Database::open(':memory:');
    $noop = fn (Database $db) => null;

    expect(fn () => Migrator::migrate($db, [migratorTestMigration(2, $noop), migratorTestMigration(2, $noop)]))
        ->toThrow(LogicException::class)
    ;
});
