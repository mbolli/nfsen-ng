<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

/**
 * Each filtered alert rule's value per checked interval, the baseline of its percent-of-average
 * threshold. Samples of another fingerprint than the rule's were counted before an edit.
 */
final class AlertSampleRepository {
    /** The longest averaging window a rule can choose; record() and prune() drop what lies further back. */
    public const int KEEP_SECONDS = 86_400;

    public function __construct(private readonly Database $db) {}

    /**
     * Stores the value of the interval at $ts, dropping the rule's samples of another fingerprint or
     * older than KEEP_SECONDS. Its samples after $ts go only when one lies beyond $horizon and $ts
     * does not: the clock or NFCAPD_TZ moved back. An interval checked late keeps them.
     *
     * @param null|int $horizon latest time the clock can explain; null: no rewind check
     */
    public function record(string $ruleId, string $fingerprint, int $ts, float $value, ?int $horizon = null): void {
        if (!is_finite($value)) {
            throw new \InvalidArgumentException("Alert sample of rule {$ruleId} is not a finite number");
        }

        $this->db->transaction(function (Database $db) use ($ruleId, $fingerprint, $ts, $value, $horizon): void {
            $db->exec(
                'DELETE FROM alert_samples WHERE rule_id = ? AND (fingerprint <> ? OR ts < ?)',
                [$ruleId, $fingerprint, $ts - self::KEEP_SECONDS],
            );
            if ($horizon !== null && $ts <= $horizon) {
                $db->exec(
                    'DELETE FROM alert_samples WHERE rule_id = ? AND ts > ?
                     AND EXISTS (SELECT 1 FROM alert_samples WHERE rule_id = ? AND ts > ?)',
                    [$ruleId, $ts, $ruleId, $horizon],
                );
            }
            $db->exec(
                'INSERT INTO alert_samples (rule_id, ts, fingerprint, value) VALUES (?, ?, ?, ?)
                 ON CONFLICT (rule_id, ts) DO UPDATE SET fingerprint = excluded.fingerprint, value = excluded.value',
                [$ruleId, $ts, $fingerprint, $value],
            );
        });
    }

    /**
     * How many samples of the rule with this fingerprint start in [$from, $to), and their mean
     * (0.0 without samples).
     *
     * @return array{count: int, average: float}
     */
    public function average(string $ruleId, string $fingerprint, int $from, int $to): array {
        $row = $this->db->one(
            'SELECT COUNT(*) AS n, AVG(value) AS mean FROM alert_samples
             WHERE rule_id = ? AND fingerprint = ? AND ts >= ? AND ts < ?',
            [$ruleId, $fingerprint, $from, $to],
        );

        return [
            'count' => (int) ($row['n'] ?? 0),
            'average' => (float) ($row['mean'] ?? 0.0),
        ];
    }

    /**
     * Drops each rule's samples of another fingerprint than the one given for it, so a rule edited
     * and edited back starts over; writes only when there is something to drop.
     *
     * @param array<string, string> $fingerprints rule ID => its current fingerprint
     *
     * @return int deleted samples
     */
    public function keepFingerprints(array $fingerprints): int {
        $stale = [];
        foreach ($this->db->all('SELECT DISTINCT rule_id, fingerprint FROM alert_samples') as $row) {
            $current = $fingerprints[(string) $row['rule_id']] ?? null;
            if ($current !== null && $current !== (string) $row['fingerprint']) {
                $stale[(string) $row['rule_id']] = $current;
            }
        }
        if ($stale === []) {
            return 0;
        }

        return $this->db->transaction(static function (Database $db) use ($stale): int {
            $deleted = 0;
            foreach ($stale as $ruleId => $fingerprint) {
                $deleted += $db->exec('DELETE FROM alert_samples WHERE rule_id = ? AND fingerprint <> ?', [(string) $ruleId, $fingerprint]);
            }

            return $deleted;
        });
    }

    /**
     * Drops the samples of these rules that start before $before, recording or not; writes only
     * when there is something to drop.
     *
     * @param list<string> $ruleIds
     *
     * @return int deleted samples
     */
    public function prune(array $ruleIds, int $before): int {
        if ($ruleIds === []) {
            return 0;
        }
        $in = 'rule_id IN (' . implode(', ', array_fill(0, \count($ruleIds), '?')) . ') AND ts < ?';
        $params = [...$ruleIds, $before];
        if ($this->db->one("SELECT 1 AS old FROM alert_samples WHERE {$in} LIMIT 1", $params) === null) {
            return 0;
        }

        return $this->db->exec("DELETE FROM alert_samples WHERE {$in}", $params);
    }

    /** @return int deleted samples */
    public function deleteRule(string $ruleId): int {
        return $this->db->exec('DELETE FROM alert_samples WHERE rule_id = ?', [$ruleId]);
    }

    /**
     * Drops the samples of every rule not in $ruleIds; writes only when there is something to drop.
     *
     * @param list<string> $ruleIds
     *
     * @return int deleted samples
     */
    public function keepOnly(array $ruleIds): int {
        $stored = array_map(
            static fn (array $row): string => (string) $row['rule_id'],
            $this->db->all('SELECT DISTINCT rule_id FROM alert_samples'),
        );
        $gone = array_values(array_diff($stored, $ruleIds));
        if ($gone === []) {
            return 0;
        }

        return $this->db->exec(
            'DELETE FROM alert_samples WHERE rule_id IN (' . implode(', ', array_fill(0, \count($gone), '?')) . ')',
            $gone,
        );
    }
}
