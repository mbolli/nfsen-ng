<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * Alert history: fired, resolved and test events.
 *
 * @phpstan-type AlertEvent array{id: int, ts: int, kind: string, ruleId: ?string, ruleName: string, profile: string,
 *                                sources: list<string>, metric: string, operator: string, value: float, threshold: ?float, origin: string}
 * @phpstan-type LegacyEntry array{ts: int, ruleId: ?string, ruleName: string, profile: string, sources: list<string>,
 *                                 metric: string, value: float}
 */
final class AlertEventRepository {
    public const array KINDS = ['fired', 'resolved', 'test'];

    public const array ORIGINS = ['live', 'migrated'];

    /** record() prunes after this many inserts. */
    public const int PRUNE_EVERY = 100;

    public const int RETENTION_SECONDS = 365 * 86_400;

    public const int KEEP_AT_LEAST = 1000;

    /** Meta key set in the same transaction that imports alerts-log.json. */
    public const string LEGACY_META_KEY = 'migrated.alerts_log';

    private int $insertsSincePrune = 0;

    public function __construct(private readonly Database $db) {}

    /**
     * @param list<string> $sources
     *
     * @return int the new event id
     */
    public function record(
        string $kind,
        int $ts,
        ?string $ruleId,
        string $ruleName,
        string $profile,
        array $sources,
        string $metric,
        string $operator,
        float $value,
        ?float $threshold,
        string $origin = 'live',
    ): int {
        $id = $this->insert($kind, $ts, $ruleId, $ruleName, $profile, $sources, $metric, $operator, $value, $threshold, $origin);

        if (++$this->insertsSincePrune >= self::PRUNE_EVERY) {
            $this->insertsSincePrune = 0;
            $this->prune(time() - self::RETENTION_SECONDS);
        }

        return $id;
    }

    /**
     * Newest first. $kinds narrows the result to those kinds; empty means every kind.
     *
     * @param list<string> $kinds
     *
     * @return list<AlertEvent>
     */
    public function recent(int $limit = 20, ?string $ruleId = null, array $kinds = []): array {
        $where = [];
        $params = [];
        if ($ruleId !== null) {
            $where[] = 'rule_id = ?';
            $params[] = $ruleId;
        }
        $kinds = array_values(array_intersect(self::KINDS, $kinds));
        if ($kinds !== []) {
            $where[] = 'kind IN (' . implode(', ', array_fill(0, \count($kinds), '?')) . ')';
            array_push($params, ...$kinds);
        }
        $params[] = max(1, $limit);

        $rows = $this->db->all(
            'SELECT id, ts, kind, rule_id, rule_name, profile, sources, metric, operator, value, threshold, origin
             FROM alert_events' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . '
             ORDER BY ts DESC, id DESC LIMIT ?',
            $params,
        );

        return array_map(self::toEvent(...), $rows);
    }

    /** @return array<string, int> ruleId => ts of the newest 'fired' */
    public function lastFired(): array {
        $rows = $this->db->all(
            "SELECT rule_id, MAX(ts) AS ts FROM alert_events
             WHERE kind = 'fired' AND rule_id IS NOT NULL
             GROUP BY rule_id",
        );

        $last = [];
        foreach ($rows as $row) {
            $last[(string) $row['rule_id']] = (int) $row['ts'];
        }

        return $last;
    }

    /**
     * Deletes the rule's events of the given kinds; no kind deletes nothing.
     *
     * @param list<string> $kinds
     *
     * @return int deleted rows
     */
    public function deleteForRule(string $ruleId, array $kinds): int {
        $kinds = array_values(array_intersect(self::KINDS, $kinds));
        if ($kinds === []) {
            return 0;
        }

        return $this->db->exec(
            'DELETE FROM alert_events WHERE rule_id = ? AND kind IN (' . implode(', ', array_fill(0, \count($kinds), '?')) . ')',
            [$ruleId, ...$kinds],
        );
    }

    /**
     * Deletes the events of the given kinds whose rule is not in $ruleIds; events without a rule stay.
     *
     * @param list<string> $ruleIds
     * @param list<string> $kinds
     *
     * @return int deleted rows
     */
    public function deleteForOtherRules(array $ruleIds, array $kinds): int {
        $kinds = array_values(array_intersect(self::KINDS, $kinds));
        if ($kinds === []) {
            return 0;
        }
        $keep = $ruleIds === [] ? '' : ' AND rule_id NOT IN (' . implode(', ', array_fill(0, \count($ruleIds), '?')) . ')';

        return $this->db->exec(
            'DELETE FROM alert_events WHERE rule_id IS NOT NULL AND kind IN (' . implode(', ', array_fill(0, \count($kinds), '?')) . ')' . $keep,
            [...$kinds, ...$ruleIds],
        );
    }

    /**
     * Deletes events older than $olderThan, but never the newest $keepAtLeast.
     *
     * @return int deleted rows
     */
    public function prune(int $olderThan, int $keepAtLeast = self::KEEP_AT_LEAST): int {
        return $this->db->exec(
            'DELETE FROM alert_events WHERE ts < ? AND id NOT IN (
                SELECT id FROM alert_events ORDER BY ts DESC, id DESC LIMIT ?
            )',
            [$olderThan, max(0, $keepAtLeast)],
        );
    }

    public function legacyLogMigrated(): bool {
        return $this->db->metaGet(self::LEGACY_META_KEY) === '1';
    }

    /**
     * Imports alerts-log.json entries as migrated 'fired' events, oldest first, and sets the
     * meta key, all in one transaction: a failure leaves neither rows nor the key behind.
     *
     * @param list<LegacyEntry> $entries
     *
     * @return int imported entries
     */
    public function importLegacyLog(array $entries): int {
        usort($entries, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        return $this->db->transaction(function (Database $db) use ($entries): int {
            foreach ($entries as $entry) {
                $this->insert('fired', $entry['ts'], $entry['ruleId'], $entry['ruleName'], $entry['profile'], $entry['sources'], $entry['metric'], '', $entry['value'], null, 'migrated');
            }
            $db->metaSet(self::LEGACY_META_KEY, '1');

            return \count($entries);
        });
    }

    /** @param list<string> $sources */
    private function insert(
        string $kind,
        int $ts,
        ?string $ruleId,
        string $ruleName,
        string $profile,
        array $sources,
        string $metric,
        string $operator,
        float $value,
        ?float $threshold,
        string $origin,
    ): int {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown alert event kind '{$kind}'");
        }
        if (!\in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException("Unknown alert event origin '{$origin}'");
        }

        $this->db->exec(
            'INSERT INTO alert_events (ts, kind, rule_id, rule_name, profile, sources, metric, operator, value, threshold, origin)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $ts,
                $kind,
                $ruleId,
                $ruleName,
                $profile,
                json_encode(array_values(array_map('strval', $sources)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                $metric,
                $operator,
                is_finite($value) ? $value : 0.0,
                $threshold !== null && is_finite($threshold) ? $threshold : null,
                $origin,
            ],
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return AlertEvent
     */
    private static function toEvent(array $row): array {
        $sources = json_decode((string) $row['sources'], true);

        return [
            'id' => (int) $row['id'],
            'ts' => (int) $row['ts'],
            'kind' => (string) $row['kind'],
            'ruleId' => $row['rule_id'] === null ? null : (string) $row['rule_id'],
            'ruleName' => (string) $row['rule_name'],
            'profile' => (string) $row['profile'],
            'sources' => \is_array($sources) ? array_values(array_map(static fn (mixed $s): string => \is_scalar($s) ? (string) $s : '', $sources)) : [],
            'metric' => (string) $row['metric'],
            'operator' => (string) $row['operator'],
            'value' => (float) $row['value'],
            'threshold' => $row['threshold'] === null ? null : (float) $row['threshold'],
            'origin' => (string) $row['origin'],
        ];
    }
}
