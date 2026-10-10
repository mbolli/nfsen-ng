<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migrator;
use mbolli\nfsen_ng\store\StoreUnavailableException;

/** A fresh temp directory, removed again in afterEach. */
function databaseTestDir(): string {
    $dir = sys_get_temp_dir() . '/nfsen-db-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o775, true);
    $GLOBALS['databaseTestDirs'][] = $dir;

    return $dir;
}

function databaseTestRemove(string $path): void {
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0o644);
        @unlink($path);

        return;
    }
    if (!is_dir($path)) {
        return;
    }
    @chmod($path, 0o775);
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            databaseTestRemove($path . '/' . $entry);
        }
    }
    @rmdir($path);
}

/**
 * Runs $body in a PHP subprocess as nobody when the suite runs as root, because root may write
 * to a 0555 directory. $body sees $args and returns a JSON-encodable value.
 *
 * @param array<string, mixed> $args
 */
function databaseTestUnprivileged(string $body, array $args = []): mixed {
    $script = <<<'PHP'
        use mbolli\nfsen_ng\common\Config;
        use mbolli\nfsen_ng\common\Debug;
        use mbolli\nfsen_ng\store\Database;
        use mbolli\nfsen_ng\store\StoreUnavailableException;

        require getenv('NFSEN_TEST_ROOT') . '/vendor/autoload.php';
        if (posix_geteuid() === 0 && !(posix_setgid(65534) && posix_setuid(65534))) {
            fwrite(STDERR, 'cannot drop root rights');
            exit(2);
        }
        Debug::getInstance()->setDebug(false);
        echo json_encode((static function (array $args): mixed { BODY })(json_decode((string) getenv('NFSEN_TEST_ARGS'), true)));
        PHP;

    $process = proc_open(
        [PHP_BINARY, '-r', str_replace('BODY', $body, $script)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        [...getenv(), 'NFSEN_TEST_ROOT' => dirname(__DIR__, 2), 'NFSEN_TEST_ARGS' => json_encode($args, JSON_THROW_ON_ERROR)],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start the unprivileged subprocess');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('unprivileged subprocess failed: ' . $err . $out);
    }

    return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    $GLOBALS['databaseTestDirs'] = [];
    Database::resetShared();
    // Store failures are logged at LOG_ERR, which Debug echoes on the CLI.
    ob_start();
});

afterEach(function (): void {
    ob_end_clean();
    Database::resetShared();
    // A typed static cannot be unset again; '' reads as "not configured" in Database::shared().
    Config::$stateDir = $this->stateDirBefore ?? '';
    foreach ($GLOBALS['databaseTestDirs'] as $dir) {
        databaseTestRemove($dir);
    }
});

describe('Database::open()', function (): void {
    test('sets the connection pragmas', function (): void {
        $db = Database::open(databaseTestDir() . '/store.sqlite');

        expect($db->value('PRAGMA busy_timeout'))->toBe(250)
            ->and($db->value('PRAGMA synchronous'))->toBe(1)
            ->and($db->value('PRAGMA foreign_keys'))->toBe(1)
            ->and($db->value('PRAGMA temp_store'))->toBe(2)
        ;
    });

    test('uses WAL on a temp file', function (): void {
        $db = Database::open(databaseTestDir() . '/store.sqlite');

        expect($db->journalMode())->toBe('wal')
            ->and($db->value('PRAGMA journal_mode'))->toBe('wal')
        ;
    });

    test("reports the delete journal for ':memory:', which answers memory to the WAL pragma", function (): void {
        $db = Database::open(':memory:');

        expect($db->journalMode())->toBe('delete')
            ->and($db->isReadOnly())->toBeFalse()
        ;
    });

    test('opens a file whose VFS refuses WAL with the delete journal', function (): void {
        // unix-none has no shared memory, so SQLite refuses WAL there as it does on FUSE.
        $path = databaseTestDir() . '/store.sqlite';

        $db = Database::open('file:' . $path . '?vfs=unix-none');

        expect($db->journalMode())->toBe('delete')
            ->and($db->value('PRAGMA journal_mode'))->toBe('delete')
            ->and($db->schemaVersion())->toBe(Migrator::latestVersion())
            ->and(ord(substr((string) file_get_contents($path, length: 100), 18, 1)))->toBe(1)
            ->and(file_exists($path . '-wal'))->toBeFalse()
        ;
    });

    test('switches the connection to the delete journal when WAL is refused', function (): void {
        $pdo = new PDO('sqlite:file:' . databaseTestDir() . '/store.sqlite?vfs=unix-none');
        $pdo->exec('PRAGMA journal_mode = TRUNCATE');

        $mode = new ReflectionMethod(Database::class, 'negotiateJournalMode')->invoke(null, $pdo, false);

        expect($mode)->toBe('delete')
            ->and($pdo->query('PRAGMA journal_mode')->fetchColumn())->toBe('delete')
        ;
    });

    test('a fresh database is migrated to the latest version with every table', function (): void {
        $db = Database::open(databaseTestDir() . '/store.sqlite');
        $tables = array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"), 'name');

        expect($db->schemaVersion())->toBe(Migrator::latestVersion())
            ->and($tables)->toBe(['alert_events', 'alert_samples', 'interface_names', 'meta', 'query_runs', 'saved_filters', 'topn_1d', 'topn_1h', 'topn_5m', 'topn_interval'])
        ;
    });

    test('a read-only open rejects writes and does not migrate', function (): void {
        $path = databaseTestDir() . '/store.sqlite';
        new PDO('sqlite:' . $path)->exec('CREATE TABLE t (x INTEGER)');

        $readOnly = Database::open($path, readOnly: true);

        expect($readOnly->isReadOnly())->toBeTrue()
            ->and($readOnly->schemaVersion())->toBe(0)
            ->and(fn () => $readOnly->exec('INSERT INTO t (x) VALUES (?)', [1]))->toThrow(PDOException::class)
        ;
    });

    test('a schema newer than the code is opened read-only and nothing is dropped', function (): void {
        $path = databaseTestDir() . '/store.sqlite';
        $db = Database::open($path);
        $db->metaSet('kept', 'yes');
        $db->exec('PRAGMA user_version = 99');
        unset($db);

        $reopened = Database::open($path);

        expect($reopened->isReadOnly())->toBeTrue()
            ->and($reopened->schemaVersion())->toBe(99)
            ->and($reopened->metaGet('kept'))->toBe('yes')
            ->and(fn () => $reopened->metaSet('new', 'no'))->toThrow(PDOException::class)
        ;
    });
});

describe('Database::requireDriver()', function (): void {
    test('throws when no driver is available', function (): void {
        expect(fn () => Database::requireDriver([]))
            ->toThrow(StoreUnavailableException::class, 'pdo_sqlite is not installed')
        ;
    });

    test('throws when only other drivers are available', function (): void {
        expect(fn () => Database::requireDriver(['mysql', 'pgsql']))
            ->toThrow(StoreUnavailableException::class, 'pdo_sqlite is not installed')
        ;
    });

    test('accepts a list that contains sqlite', function (): void {
        Database::requireDriver(['mysql', 'sqlite']);

        expect(true)->toBeTrue();
    });
});

describe('queries', function (): void {
    test('all, one, value and exec with positional and named parameters', function (): void {
        $db = Database::open(':memory:');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT, score REAL, flag INTEGER, note TEXT)');

        $inserted = $db->exec('INSERT INTO t (name, score, flag, note) VALUES (?, ?, ?, ?)', ['a', 1.5, true, null]);
        $db->exec('INSERT INTO t (name, score, flag, note) VALUES (:name, :score, :flag, :note)', ['name' => 'b', 'score' => 0.1, 'flag' => false, 'note' => 'x']);

        expect($inserted)->toBe(1)
            ->and($db->all('SELECT name, score, flag, note FROM t ORDER BY id'))->toBe([
                ['name' => 'a', 'score' => 1.5, 'flag' => 1, 'note' => null],
                ['name' => 'b', 'score' => 0.1, 'flag' => 0, 'note' => 'x'],
            ])
            ->and($db->one('SELECT name FROM t WHERE flag = ?', [0]))->toBe(['name' => 'b'])
            ->and($db->one('SELECT name FROM t WHERE id = ?', [42]))->toBeNull()
            ->and($db->value('SELECT COUNT(*) FROM t'))->toBe(2)
            ->and($db->value('SELECT name FROM t WHERE id = ?', [42]))->toBeNull()
            ->and($db->exec('UPDATE t SET flag = ? WHERE score > ?', [1, 0.0]))->toBe(2)
        ;
    });

    test('prepared statements are cached by SQL', function (): void {
        $db = Database::open(':memory:');
        $cache = new ReflectionProperty(Database::class, 'statements');
        $before = count($cache->getValue($db));

        $db->value('SELECT ?', [1]);
        $db->value('SELECT ?', [2]);
        $db->one('SELECT ? AS v', [3]);

        $statements = $cache->getValue($db);
        expect(count($statements))->toBe($before + 2)
            ->and($statements)->toHaveKeys(['SELECT ?', 'SELECT ? AS v'])
            ->and($db->value('SELECT ?', [7]))->toBe(7)
        ;
    });

    test('a statement whose execution failed leaves the cache, and the same SQL works again', function (): void {
        $db = Database::open(':memory:');
        $db->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $cache = new ReflectionProperty(Database::class, 'statements');
        $db->exec('INSERT INTO t (id) VALUES (?)', [1]);

        expect(fn () => $db->exec('INSERT INTO t (id) VALUES (?)', [1]))->toThrow(PDOException::class)
            ->and($cache->getValue($db))->not->toHaveKey('INSERT INTO t (id) VALUES (?)')
            ->and($db->exec('INSERT INTO t (id) VALUES (?)', [2]))->toBe(1)
            ->and($db->value('SELECT COUNT(*) FROM t'))->toBe(2)
        ;
    });

    test('a cached statement does not keep the bindings of an earlier call', function (): void {
        $db = Database::open(':memory:');

        expect($db->one('SELECT :a AS a, :b AS b', ['a' => 1, 'b' => 2]))->toBe(['a' => 1, 'b' => 2])
            ->and($db->one('SELECT :a AS a, :b AS b', ['a' => 3]))->toBe(['a' => 3, 'b' => null])
            ->and($db->one('SELECT :a AS a, :b AS b', [':b' => 4, 'a' => 5]))->toBe(['a' => 5, 'b' => 4])
            ->and($db->value('SELECT ? + ?', [1, 2]))->toBe(3)
            ->and($db->value('SELECT ? + ?', [5]))->toBeNull()
        ;
    });

    test('floats bind as TEXT, so a comparison with an aggregate needs CAST(? AS REAL)', function (): void {
        $db = Database::open(':memory:');
        $db->exec('CREATE TABLE t (v REAL)');
        $db->exec('INSERT INTO t (v) VALUES (?)', [2.5]);
        $sum = 'SELECT COUNT(*) FROM (SELECT SUM(v) AS s FROM t) WHERE s > ';

        expect($db->value('SELECT typeof(v) FROM t'))->toBe('real')
            ->and($db->value('SELECT COUNT(*) FROM t WHERE v > ?', [1.5]))->toBe(1)
            ->and($db->value($sum . '?', [1.5]))->toBe(0)
            ->and($db->value($sum . 'CAST(? AS REAL)', [1.5]))->toBe(1)
        ;
    });

    test('rejects a non-scalar parameter', function (): void {
        $db = Database::open(':memory:');

        expect(fn () => $db->value('SELECT ?', [[1, 2]]))->toThrow(InvalidArgumentException::class);
    });

    test('meta get and set', function (): void {
        $db = Database::open(':memory:');

        expect($db->metaGet('missing'))->toBeNull();

        $db->metaSet('migrated.alerts_log', '1');
        $db->metaSet('migrated.alerts_log', '2');

        expect($db->metaGet('migrated.alerts_log'))->toBe('2')
            ->and($db->value('SELECT COUNT(*) FROM meta'))->toBe(1)
        ;
    });
});

describe('Database::transaction()', function (): void {
    test('commits and returns the closure result', function (): void {
        $db = Database::open(':memory:');

        $result = $db->transaction(function (Database $tx): string {
            $tx->metaSet('a', '1');

            return 'done';
        });

        expect($result)->toBe('done')
            ->and($db->metaGet('a'))->toBe('1')
            ->and($db->pdo()->inTransaction())->toBeFalse()
        ;
    });

    test('rolls back and rethrows an exception', function (): void {
        $db = Database::open(':memory:');

        expect(fn () => $db->transaction(function (Database $tx): void {
            $tx->metaSet('a', '1');

            throw new RuntimeException('boom');
        }))->toThrow(RuntimeException::class, 'boom');

        expect($db->metaGet('a'))->toBeNull();
    });

    test('rolls back on an Error too', function (): void {
        $db = Database::open(':memory:');

        expect(fn () => $db->transaction(function (Database $tx): void {
            $tx->metaSet('a', '1');

            throw new Error('fatal');
        }))->toThrow(Error::class, 'fatal');

        expect($db->metaGet('a'))->toBeNull();

        $db->transaction(fn (Database $tx) => $tx->metaSet('b', '1'));
        expect($db->metaGet('b'))->toBe('1');
    });

    test('a nested transaction is a savepoint', function (): void {
        $db = Database::open(':memory:');

        $db->transaction(function (Database $tx): void {
            $tx->metaSet('outer', '1');

            try {
                $tx->transaction(function (Database $inner): void {
                    $inner->metaSet('inner', '1');

                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }

            $tx->transaction(fn (Database $inner) => $inner->metaSet('second', '1'));
        });

        expect($db->metaGet('outer'))->toBe('1')
            ->and($db->metaGet('inner'))->toBeNull()
            ->and($db->metaGet('second'))->toBe('1')
        ;
    });
});

describe('Database::shared()', function (): void {
    test('creates the state directory, migrates and reuses the connection', function (): void {
        Config::$stateDir = databaseTestDir() . '/state/nested';

        $db = Database::shared();

        expect($db->path())->toBe(Config::$stateDir . '/' . Database::FILENAME)
            ->and(is_file($db->path()))->toBeTrue()
            ->and($db->schemaVersion())->toBe(Migrator::latestVersion())
            ->and(Database::shared())->toBe($db)
        ;
    });

    test('an uncreatable state directory throws StoreUnavailableException with the path', function (): void {
        $blocker = databaseTestDir() . '/not-a-directory';
        touch($blocker);
        Config::$stateDir = $blocker . '/state';
        $path = Config::$stateDir . '/' . Database::FILENAME;

        try {
            Database::shared();
            $caught = null;
        } catch (StoreUnavailableException $e) {
            $caught = $e;
        }

        expect($caught)->toBeInstanceOf(StoreUnavailableException::class)
            ->and($caught->path)->toBe($path)
            ->and($caught->getMessage())->toContain($path)
            ->and($caught->reason)->toContain($blocker . '/state')
        ;
    });

    test('an unwritable state directory throws StoreUnavailableException with the path', function (): void {
        $dir = databaseTestDir() . '/readonly-state';
        mkdir($dir);
        chmod($dir, 0o555);

        $result = databaseTestUnprivileged(<<<'PHP'
            Config::$stateDir = $args['dir'];
            try {
                Database::shared();

                return ['opened' => true];
            } catch (StoreUnavailableException $e) {
                return ['path' => $e->path, 'message' => $e->getMessage()];
            }
            PHP, ['dir' => $dir]);

        expect($result)->toBe([
            'path' => $dir . '/' . Database::FILENAME,
            'message' => 'SQLite store ' . $dir . '/' . Database::FILENAME . ' is unavailable: the state directory ' . $dir . ' is not writable',
        ])
            ->and(file_exists($dir . '/' . Database::FILENAME))->toBeFalse()
        ;
    });

    test('a file that is not a database maps the PDOException', function (): void {
        Config::$stateDir = databaseTestDir();
        file_put_contents(Config::$stateDir . '/' . Database::FILENAME, str_repeat('not a database ', 20));

        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class, 'file is not a database');
    });

    test('a failure is remembered for the same path until reset', function (): void {
        $blocker = databaseTestDir() . '/blocker';
        touch($blocker);
        Config::$stateDir = $blocker;

        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class, 'is not a directory');

        // The directory becomes usable, but the remembered failure still answers.
        unlink($blocker);
        mkdir($blocker);
        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class, 'is not a directory');

        Database::resetShared();
        expect(Database::shared()->schemaVersion())->toBe(Migrator::latestVersion());
    });

    test('a remembered failure is retried once FAILURE_TTL_SECONDS have passed', function (): void {
        $blocker = databaseTestDir() . '/blocker';
        touch($blocker);
        Config::$stateDir = $blocker;
        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class, 'is not a directory');

        unlink($blocker);
        mkdir($blocker);
        $failedAt = new ReflectionProperty(Database::class, 'failedAt');

        $failedAt->setValue(null, time() - Database::FAILURE_TTL_SECONDS + 5);
        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class, 'is not a directory');

        $failedAt->setValue(null, time() - Database::FAILURE_TTL_SECONDS);
        expect(Database::shared()->schemaVersion())->toBe(Migrator::latestVersion());
    });

    test('a different state directory is tried despite a remembered failure', function (): void {
        $blocker = databaseTestDir() . '/blocker';
        touch($blocker);
        Config::$stateDir = $blocker;
        expect(fn () => Database::shared())->toThrow(StoreUnavailableException::class);

        Config::$stateDir = databaseTestDir();
        expect(Database::shared()->schemaVersion())->toBe(Migrator::latestVersion());
    });

    test('useShared installs an instance', function (): void {
        $db = Database::open(':memory:');
        Database::useShared($db);

        expect(Database::shared())->toBe($db);
    });
});

describe('Database::inspect()', function (): void {
    test('a missing file is reported and not created', function (): void {
        $dir = databaseTestDir();
        $path = $dir . '/missing/' . Database::FILENAME;

        $facts = Database::inspect($path);

        expect($facts)->toBe([
            'path' => $path,
            'exists' => false,
            'writable' => false,
            'journalMode' => '',
            'schemaVersion' => 0,
            'sizeBytes' => 0,
            'error' => '',
        ])
            ->and(file_exists($path))->toBeFalse()
            ->and(file_exists($dir . '/missing'))->toBeFalse()
            ->and(Database::inspect($dir . '/' . Database::FILENAME)['writable'])->toBeTrue()
        ;
    });

    test('a fresh file reports its facts and leaves no side files', function (): void {
        $path = databaseTestDir() . '/' . Database::FILENAME;
        $db = Database::open($path);
        unset($db);

        $facts = Database::inspect($path);

        expect($facts['exists'])->toBeTrue()
            ->and($facts['writable'])->toBeTrue()
            ->and($facts['journalMode'])->toBe('wal')
            ->and($facts['schemaVersion'])->toBe(Migrator::latestVersion())
            ->and($facts['sizeBytes'])->toBe(filesize($path))
            ->and($facts['error'])->toBe('')
            ->and(glob($path . '-*'))->toBe([])
        ;
    });

    test('a file in use is read through a read-only connection', function (): void {
        $path = databaseTestDir() . '/' . Database::FILENAME;
        $db = Database::open($path);
        $db->metaSet('a', '1');

        $facts = Database::inspect($path);

        expect(is_file($path . '-wal'))->toBeTrue()
            ->and($facts['journalMode'])->toBe('wal')
            ->and($facts['schemaVersion'])->toBe(Migrator::latestVersion())
            ->and($facts['sizeBytes'])->toBeGreaterThan(filesize($path))
            ->and($facts['error'])->toBe('')
        ;
    });

    test('a WAL without -shm is read from the header and no -shm is created', function (): void {
        $dir = databaseTestDir();
        $live = Database::open($dir . '/live.sqlite');
        $live->metaSet('a', '1');
        $path = $dir . '/' . Database::FILENAME;
        copy($live->path(), $path);
        copy($live->path() . '-wal', $path . '-wal');

        $facts = Database::inspect($path);

        expect(file_exists($path . '-shm'))->toBeFalse()
            ->and($facts['exists'])->toBeTrue()
            ->and($facts['journalMode'])->toBe('wal')
            ->and($facts['sizeBytes'])->toBe(filesize($path) + filesize($path . '-wal'))
            ->and($facts['error'])->toContain('-wal file is not checkpointed')
        ;
    });

    test('a read-only file is inspected without errors', function (): void {
        $dir = databaseTestDir();
        $path = $dir . '/' . Database::FILENAME;
        $db = Database::open($path);
        unset($db);
        chmod($dir, 0o777);
        chmod($path, 0o444);

        $facts = databaseTestUnprivileged('return Database::inspect($args["path"]);', ['path' => $path]);

        expect($facts['exists'])->toBeTrue()
            ->and($facts['writable'])->toBeFalse()
            ->and($facts['schemaVersion'])->toBe(Migrator::latestVersion())
            ->and($facts['error'])->toBe('')
            ->and(glob($path . '-*'))->toBe([])
        ;
    });

    test('a writable file in an unwritable directory is not writable, as for shared()', function (): void {
        $dir = databaseTestDir() . '/locked';
        mkdir($dir);
        $path = $dir . '/' . Database::FILENAME;
        $db = Database::open($path);
        unset($db);
        chmod($path, 0o666);
        chmod($dir, 0o555);

        $result = databaseTestUnprivileged(<<<'PHP'
            Config::$stateDir = $args['dir'];
            try {
                Database::shared();
                $shared = 'opened';
            } catch (StoreUnavailableException $e) {
                $shared = $e->reason;
            }

            return ['facts' => Database::inspect($args['path']), 'shared' => $shared];
            PHP, ['dir' => $dir, 'path' => $path]);

        expect($result['facts']['exists'])->toBeTrue()
            ->and($result['facts']['writable'])->toBeFalse()
            ->and($result['facts']['error'])->toBe('')
            ->and($result['shared'])->toBe("the state directory {$dir} is not writable")
        ;
    });

    test('a file that is not a database is reported, not thrown', function (): void {
        $path = databaseTestDir() . '/' . Database::FILENAME;
        file_put_contents($path, str_repeat('garbage ', 20));

        $facts = Database::inspect($path);

        expect($facts['exists'])->toBeTrue()
            ->and($facts['error'])->toBe('not an SQLite database')
        ;
    });
});
