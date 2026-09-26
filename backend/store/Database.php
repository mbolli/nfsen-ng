<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;

/**
 * All coroutines share one blocking connection: never hold a transaction across a yield.
 * Floats bind as TEXT, so compare them with an aggregate or expression as CAST(? AS REAL).
 *
 * @phpstan-type StoreFacts array{path: string, exists: bool, writable: bool, journalMode: string, schemaVersion: int, sizeBytes: int, error: string}
 */
final class Database {
    public const string FILENAME = 'nfsen-ng.sqlite';

    /** A broken store is not retried on every call; this is how long a failure is remembered. */
    public const int FAILURE_TTL_SECONDS = 60;

    private const int STATEMENT_CACHE_MAX = 256;

    private static ?self $shared = null;

    private static ?StoreUnavailableException $failure = null;

    private static string $failedPath = '';

    private static int $failedAt = 0;

    /** @var array<string, \PDOStatement> prepared statements keyed by SQL */
    private array $statements = [];

    /** @var array<string, string> parameter names each cached statement was last bound with */
    private array $boundKeys = [];

    private int $transactionDepth = 0;

    private function __construct(
        private readonly \PDO $pdo,
        private readonly string $path,
        private readonly string $journalMode,
        private readonly bool $readOnly,
    ) {}

    /**
     * Process-wide connection to <state dir>/nfsen-ng.sqlite; opened and migrated on first use.
     *
     * @throws StoreUnavailableException when the driver is missing, the state directory is
     *                                   not writable, or opening or migrating fails
     */
    public static function shared(): self {
        if (self::$shared instanceof self) {
            return self::$shared;
        }

        if (!isset(Config::$stateDir) || Config::$stateDir === '') {
            throw new StoreUnavailableException('the state directory is not configured yet');
        }

        $dir = rtrim(Config::$stateDir, \DIRECTORY_SEPARATOR);
        $path = $dir . \DIRECTORY_SEPARATOR . self::FILENAME;

        if (self::$failure instanceof StoreUnavailableException
            && self::$failedPath === $path
            && time() - self::$failedAt < self::FAILURE_TTL_SECONDS) {
            throw self::$failure;
        }

        try {
            self::prepareLocation($dir, $path);

            self::$shared = self::open($path);
        } catch (StoreUnavailableException $e) {
            throw self::remember(new StoreUnavailableException($e->reason, $path, $e), $path);
        } catch (\PDOException $e) {
            throw self::remember(new StoreUnavailableException($e->getMessage(), $path, $e), $path);
        }

        self::$failure = null;

        return self::$shared;
    }

    /**
     * Opens a connection; ':memory:' is allowed. Migrates unless $readOnly. A file whose
     * schema is newer than this code knows is reopened read-only and nothing is dropped.
     *
     * @throws StoreUnavailableException when pdo_sqlite is missing
     * @throws \PDOException             when SQLite cannot open or migrate the file
     */
    public static function open(string $path, bool $readOnly = false): self {
        $db = self::connect($path, $readOnly);

        if (!$readOnly && Migrator::migrate($db) > Migrator::latestVersion() && $path !== ':memory:') {
            return self::connect($path, true);
        }

        return $db;
    }

    /**
     * @param list<string> $drivers
     *
     * @throws StoreUnavailableException when 'sqlite' is missing
     */
    public static function requireDriver(array $drivers): void {
        if (!\in_array('sqlite', $drivers, true)) {
            throw new StoreUnavailableException('pdo_sqlite is not installed');
        }
    }

    /**
     * Read-only facts for Health and MCP; never creates, migrates or throws.
     *
     * @return StoreFacts
     */
    public static function inspect(string $path): array {
        $facts = [
            'path' => $path,
            'exists' => false,
            'writable' => false,
            'journalMode' => '',
            'schemaVersion' => 0,
            'sizeBytes' => 0,
            'error' => '',
        ];

        try {
            clearstatcache();
            if (!is_file($path)) {
                $dir = \dirname($path);
                $facts['writable'] = is_dir($dir) && is_writable($dir);
                self::requireDriver(array_values(\PDO::getAvailableDrivers()));

                return $facts;
            }

            $walPath = $path . '-wal';
            $hasWal = is_file($walPath);
            $facts['exists'] = true;
            // Same rule as shared(): the -wal, -shm or -journal files go next to the database.
            $facts['writable'] = is_writable($path) && is_writable(\dirname($path));
            $facts['sizeBytes'] = (int) filesize($path) + ($hasWal ? (int) filesize($walPath) : 0);

            // A read-only connection creates the -wal and -shm files it does not find.
            if (!$hasWal || !is_file($path . '-shm')) {
                [$facts['journalMode'], $facts['schemaVersion']] = self::readHeader($path);
                self::requireDriver(array_values(\PDO::getAvailableDrivers()));
                if ($hasWal) {
                    $facts['journalMode'] = 'wal';
                    $facts['error'] = 'the -wal file is not checkpointed and no process has the store open, so the schema version may be stale';
                }

                return $facts;
            }

            $db = self::connect($path, true);
            $facts['journalMode'] = $db->journalMode();
            $facts['schemaVersion'] = $db->schemaVersion();
        } catch (\Throwable $e) {
            $facts['error'] = $e->getMessage();
        }

        return $facts;
    }

    /** Tests: drop the shared instance and any remembered failure. */
    public static function resetShared(): void {
        self::$shared = null;
        self::$failure = null;
        self::$failedPath = '';
        self::$failedAt = 0;
    }

    /** Tests: install an instance as the shared one. */
    public static function useShared(self $db): void {
        self::resetShared();
        self::$shared = $db;
    }

    public function path(): string {
        return $this->path;
    }

    /** 'wal', or 'delete' when WAL was refused (FUSE mounts, ':memory:'). */
    public function journalMode(): string {
        return $this->journalMode;
    }

    /** True for a read-only open and for a schema newer than this code knows. */
    public function isReadOnly(): bool {
        return $this->readOnly;
    }

    /** PRAGMA user_version. */
    public function schemaVersion(): int {
        return (int) $this->value('PRAGMA user_version');
    }

    public function pdo(): \PDO {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array {
        $stmt = $this->run($sql, $params);

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } finally {
            $stmt->closeCursor();
        }

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return null|array<string, mixed>
     */
    public function one(string $sql, array $params = []): ?array {
        $stmt = $this->run($sql, $params);

        try {
            /** @var array<string, mixed>|false $row */
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } finally {
            $stmt->closeCursor();
        }

        return $row === false ? null : $row;
    }

    /**
     * First column of the first row, null when there is no row.
     *
     * @param array<int|string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed {
        $stmt = $this->run($sql, $params);

        try {
            $value = $stmt->fetchColumn();
        } finally {
            $stmt->closeCursor();
        }

        return $value === false ? null : $value;
    }

    /**
     * Without parameters the SQL may hold several statements (migrations).
     *
     * @param array<int|string, mixed> $params
     *
     * @return int affected rows
     */
    public function exec(string $sql, array $params = []): int {
        if ($params === []) {
            return (int) $this->pdo->exec($sql);
        }

        $stmt = $this->run($sql, $params);
        $count = $stmt->rowCount();
        $stmt->closeCursor();

        return $count;
    }

    /**
     * BEGIN IMMEDIATE ... COMMIT; ROLLBACK and rethrow on any \Throwable. A nested call
     * becomes a savepoint. The closure must not yield (see the class comment).
     *
     * @template T
     *
     * @param \Closure(self): T $fn
     *
     * @return T
     */
    public function transaction(\Closure $fn): mixed {
        $savepoint = 'sp' . $this->transactionDepth;
        $outermost = $this->transactionDepth === 0;

        $this->pdo->exec($outermost ? 'BEGIN IMMEDIATE' : "SAVEPOINT {$savepoint}");
        ++$this->transactionDepth;

        try {
            $result = $fn($this);
            $this->pdo->exec($outermost ? 'COMMIT' : "RELEASE {$savepoint}");
        } catch (\Throwable $e) {
            $this->rollBack($outermost, $savepoint);

            throw $e;
        } finally {
            --$this->transactionDepth;
        }

        return $result;
    }

    public function metaGet(string $key): ?string {
        $value = $this->value('SELECT value FROM meta WHERE key = ?', [$key]);

        return $value === null ? null : (string) $value;
    }

    public function metaSet(string $key, string $value): void {
        $this->exec(
            'INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value = excluded.value',
            [$key, $value],
        );
    }

    /**
     * @throws StoreUnavailableException when pdo_sqlite is missing
     * @throws \PDOException             when SQLite cannot open the file
     */
    private static function connect(string $path, bool $readOnly): self {
        self::requireDriver(array_values(\PDO::getAvailableDrivers()));
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + ($readOnly ? [\PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY] : []));
        $pdo->exec('PRAGMA busy_timeout = 250');
        $mode = self::negotiateJournalMode($pdo, $readOnly);
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        return new self($pdo, $path, $mode, $readOnly);
    }

    /** @return 'delete'|'wal' */
    private static function negotiateJournalMode(\PDO $pdo, bool $readOnly): string {
        if (!$readOnly) {
            $stmt = $pdo->query('PRAGMA journal_mode = WAL');
            $mode = $stmt === false ? '' : strtolower((string) $stmt->fetchColumn());
            if ($mode !== 'wal') {
                // FUSE (Unraid shfs) can refuse WAL
                $pdo->exec('PRAGMA journal_mode = DELETE');
            }
        } else {
            $stmt = $pdo->query('PRAGMA journal_mode');
            $mode = $stmt === false ? '' : strtolower((string) $stmt->fetchColumn());
        }

        return $mode === 'wal' ? 'wal' : 'delete';
    }

    /**
     * Creates the state directory when needed and checks that SQLite can write there.
     *
     * @throws StoreUnavailableException
     */
    private static function prepareLocation(string $dir, string $path): void {
        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            if (file_exists($dir)) {
                throw new StoreUnavailableException("the state directory {$dir} is not a directory", $path);
            }
            $error = '';
            set_error_handler(static function (int $level, string $message) use (&$error): bool {
                $error = preg_replace('/^mkdir\(\): /', '', $message) ?? $message;

                return true;
            });

            try {
                $created = mkdir($dir, 0o775, true);
            } finally {
                restore_error_handler();
            }
            if (!$created && !is_dir($dir)) {
                throw new StoreUnavailableException("the state directory {$dir} cannot be created" . ($error === '' ? '' : ": {$error}"), $path);
            }
        }
        if (!is_writable($dir)) {
            throw new StoreUnavailableException("the state directory {$dir} is not writable", $path);
        }
        if (file_exists($path) && !is_writable($path)) {
            throw new StoreUnavailableException('the database file is not writable', $path);
        }
    }

    /**
     * Journal mode and user_version from the 100-byte file header.
     *
     * @return array{0: string, 1: int}
     */
    private static function readHeader(string $path): array {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        $header = (string) fread($handle, 100);
        fclose($handle);

        if ($header === '') {
            return ['delete', 0];
        }
        if (\strlen($header) < 100 || !str_starts_with($header, "SQLite format 3\0")) {
            throw new \RuntimeException('not an SQLite database');
        }
        $version = unpack('N', $header, 60);
        $version = \is_array($version) ? (int) $version[1] : 0;

        return [\ord($header[18]) === 2 ? 'wal' : 'delete', $version >= 0x80000000 ? $version - 0x100000000 : $version];
    }

    private static function remember(StoreUnavailableException $e, string $path): StoreUnavailableException {
        self::$failure = $e;
        self::$failedPath = $path;
        self::$failedAt = time();
        Debug::getInstance()->log($e->getMessage(), LOG_ERR);

        return $e;
    }

    /** @param array<int|string, mixed> $params */
    private function run(string $sql, array $params): \PDOStatement {
        $bound = [];
        foreach ($params as $key => $value) {
            $bound[\is_int($key) ? $key + 1 : ':' . ltrim($key, ':')] = self::typed($value);
        }
        $keys = array_keys($bound);
        sort($keys);

        $stmt = $this->statement($sql, implode(',', $keys));
        foreach ($bound as $key => $typed) {
            $stmt->bindValue($key, ...$typed);
        }

        try {
            $stmt->execute();
        } catch (\Throwable $e) {
            // A statement that failed mid-step is not handed out again.
            $stmt->closeCursor();
            unset($this->statements[$sql], $this->boundKeys[$sql]);

            throw $e;
        }

        return $stmt;
    }

    /**
     * PDO statements keep their bindings between executions, so a call with other parameter
     * names than the last one gets a fresh statement, where an unbound parameter is NULL.
     */
    private function statement(string $sql, string $keys): \PDOStatement {
        if (isset($this->statements[$sql]) && ($this->boundKeys[$sql] ?? null) === $keys) {
            return $this->statements[$sql];
        }
        unset($this->statements[$sql]);
        if (\count($this->statements) >= self::STATEMENT_CACHE_MAX) {
            $oldest = array_key_first($this->statements);
            unset($this->statements[$oldest], $this->boundKeys[$oldest]);
        }
        $this->boundKeys[$sql] = $keys;

        return $this->statements[$sql] = $this->pdo->prepare($sql);
    }

    /** @return array{0: mixed, 1: int} */
    private static function typed(mixed $value): array {
        return match (true) {
            $value === null => [null, \PDO::PARAM_NULL],
            \is_bool($value) => [(int) $value, \PDO::PARAM_INT],
            \is_int($value) => [$value, \PDO::PARAM_INT],
            // PDO cannot bind a REAL, so a float travels as exact TEXT (see the class comment)
            \is_float($value) => is_finite($value) ? [var_export($value, true), \PDO::PARAM_STR] : [null, \PDO::PARAM_NULL],
            \is_string($value), $value instanceof \Stringable => [(string) $value, \PDO::PARAM_STR],
            default => throw new \InvalidArgumentException('SQLite parameters must be scalar or null, got ' . get_debug_type($value)),
        };
    }

    private function rollBack(bool $outermost, string $savepoint): void {
        try {
            if ($outermost) {
                $this->pdo->exec('ROLLBACK');
            } else {
                $this->pdo->exec("ROLLBACK TO {$savepoint}");
                $this->pdo->exec("RELEASE {$savepoint}");
            }
        } catch (\PDOException) {
            // SQLite already rolled back on its own (for example after SQLITE_FULL).
        }
    }
}
