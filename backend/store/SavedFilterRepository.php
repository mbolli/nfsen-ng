<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\common\Debug;
use OpenSwoole\Coroutine;

/**
 * The saved nfdump filters, unique by normalised expression (D16).
 *
 * @phpstan-type SavedFilter array{id: int, name: string, expression: string, starred: bool, origin: string,
 *                                 createdAt: int, updatedAt: int, lastUsedAt: ?int, useCount: int}
 */
final class SavedFilterRepository {
    public const array ORIGINS = ['user', 'browser', 'preference', 'deployment'];

    public const int NAME_MAX_LENGTH = 80;

    /** A filter saved without a name is named after its expression, cut to this length. */
    public const int DEFAULT_NAME_LENGTH = 60;

    private const string COLUMNS = 'id, name, expression, starred, origin, created_at, updated_at, last_used_at, use_count';

    /** @var null|\WeakMap<Database, bool> stores seeded by this process; false while a seed runs */
    private static ?\WeakMap $seeded = null;

    public function __construct(private readonly Database $db) {}

    /** Trimmed, every whitespace run collapsed to one space. */
    public static function key(string $expression): string {
        return trim(preg_replace('/\s+/', ' ', $expression) ?? $expression);
    }

    public static function defaultName(string $expression): string {
        return mb_substr(self::key($expression), 0, self::DEFAULT_NAME_LENGTH);
    }

    /**
     * Starred first, then last used, then name. Seeds the presets on the first call per store.
     *
     * @param string $search matched case-insensitively against name and expression
     *
     * @return list<SavedFilter>
     */
    public function list(string $search = ''): array {
        $this->seedOnce();

        $filters = array_map(
            self::filter(...),
            $this->db->all('SELECT ' . self::COLUMNS . ' FROM saved_filters ORDER BY starred DESC, last_used_at DESC, name COLLATE NOCASE, id'),
        );

        $search = trim($search);
        if ($search === '') {
            return $filters;
        }

        return array_values(array_filter(
            $filters,
            static fn (array $filter): bool => mb_stripos($filter['name'], $search) !== false
                || mb_stripos($filter['expression'], $search) !== false,
        ));
    }

    /** @return null|SavedFilter */
    public function find(int $id): ?array {
        $row = $this->db->one('SELECT ' . self::COLUMNS . ' FROM saved_filters WHERE id = ?', [$id]);

        return $row === null ? null : self::filter($row);
    }

    /**
     * @throws DuplicateFilterException  carrying the existing filter's name
     * @throws \InvalidArgumentException for an empty expression, a name over 80 characters or an unknown origin
     */
    public function create(string $name, string $expression, string $origin = 'user', bool $starred = false): int {
        self::assertOrigin($origin);
        $expression = self::expression($expression);
        $key = self::key($expression);
        $name = self::name($name, $expression);
        $this->assertUnique($key);

        $now = time();
        $this->db->exec(
            'INSERT INTO saved_filters (name, expression, expression_key, starred, origin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $expression, $key, $starred, $origin, $now, $now],
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * An empty name falls back to the expression.
     *
     * @throws \InvalidArgumentException for a missing filter or a name over 80 characters
     */
    public function rename(int $id, string $name): void {
        $filter = $this->find($id) ?? throw new \InvalidArgumentException('The saved filter no longer exists.');

        $this->db->exec(
            'UPDATE saved_filters SET name = ?, updated_at = ? WHERE id = ?',
            [self::name($name, $filter['expression']), time(), $id],
        );
    }

    /**
     * @throws DuplicateFilterException  when another saved filter has the same expression
     * @throws \InvalidArgumentException for a missing filter, an empty expression or a name over 80 characters
     */
    public function update(int $id, string $name, string $expression): void {
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('The saved filter no longer exists.');
        }
        $expression = self::expression($expression);
        $key = self::key($expression);
        $name = self::name($name, $expression);
        $this->assertUnique($key, $id);

        $this->db->exec(
            'UPDATE saved_filters SET name = ?, expression = ?, expression_key = ?, updated_at = ? WHERE id = ?',
            [$name, $expression, $key, time(), $id],
        );
    }

    public function star(int $id, bool $starred): void {
        $this->db->exec('UPDATE saved_filters SET starred = ? WHERE id = ?', [$starred, $id]);
    }

    public function delete(int $id): void {
        $this->db->exec('DELETE FROM saved_filters WHERE id = ?', [$id]);
    }

    /** Marks the filter as used: last_used_at and use_count + 1. */
    public function touch(int $id, int $now): void {
        $this->db->exec('UPDATE saved_filters SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?', [$now, $id]);
    }

    /**
     * Insert-or-skip by key. Empty expressions are skipped, names are cut to 80 characters.
     *
     * @param list<array{name: string, expression: string}> $items
     *
     * @return int number inserted
     *
     * @throws \InvalidArgumentException for an unknown origin
     */
    public function import(array $items, string $origin): int {
        self::assertOrigin($origin);
        $now = time();

        return $this->db->transaction(static function (Database $db) use ($items, $origin, $now): int {
            $inserted = 0;
            foreach ($items as $item) {
                $expression = trim($item['expression']);
                if ($expression === '') {
                    continue;
                }
                $name = mb_substr(self::key($item['name']), 0, self::NAME_MAX_LENGTH);
                $inserted += $db->exec(
                    'INSERT INTO saved_filters (name, expression, expression_key, starred, origin, created_at, updated_at)
                     VALUES (?, ?, ?, 0, ?, ?, ?)
                     ON CONFLICT (expression_key) DO NOTHING',
                    [$name !== '' ? $name : self::defaultName($expression), $expression, self::key($expression), $origin, $now, $now],
                );
            }

            return $inserted;
        });
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return SavedFilter
     */
    private static function filter(array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'expression' => (string) $row['expression'],
            'starred' => (int) $row['starred'] === 1,
            'origin' => (string) $row['origin'],
            'createdAt' => (int) $row['created_at'],
            'updatedAt' => (int) $row['updated_at'],
            'lastUsedAt' => $row['last_used_at'] === null ? null : (int) $row['last_used_at'],
            'useCount' => (int) $row['use_count'],
        ];
    }

    /** @throws \InvalidArgumentException */
    private static function expression(string $expression): string {
        $expression = trim($expression);
        if ($expression === '') {
            throw new \InvalidArgumentException('The filter expression is empty.');
        }

        return $expression;
    }

    /** @throws \InvalidArgumentException */
    private static function name(string $name, string $expression): string {
        $name = self::key($name);
        if ($name === '') {
            return self::defaultName($expression);
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException('A filter name has at most ' . self::NAME_MAX_LENGTH . ' characters.');
        }

        return $name;
    }

    /** @throws \InvalidArgumentException */
    private static function assertOrigin(string $origin): void {
        if (!\in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException('Unknown saved filter origin.');
        }
    }

    /** @throws DuplicateFilterException */
    private function assertUnique(string $key, ?int $exceptId = null): void {
        $existing = $this->db->one('SELECT id, name FROM saved_filters WHERE expression_key = ?', [$key]);
        if ($existing !== null && (int) $existing['id'] !== $exceptId) {
            throw new DuplicateFilterException((int) $existing['id'], (string) $existing['name']);
        }
    }

    private function seedOnce(): void {
        self::$seeded ??= new \WeakMap();
        if ($this->db->isReadOnly()) {
            return;
        }
        // The seed yields on its file read: wait for a running one instead of listing without it.
        while ((self::$seeded[$this->db] ?? null) === false && Coroutine::getCid() > 0) {
            Coroutine::usleep(10_000);
        }
        if (isset(self::$seeded[$this->db])) {
            return;
        }
        self::$seeded[$this->db] = false;

        try {
            SavedFilterSeeder::seed($this->db);
            self::$seeded[$this->db] = true;
        } catch (\Throwable $e) {
            unset(self::$seeded[$this->db]);
            Debug::getInstance()->log('Saved filter presets were not imported: ' . $e->getMessage(), LOG_WARNING);
        }
    }
}
