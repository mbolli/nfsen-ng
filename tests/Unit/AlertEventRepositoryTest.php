<?php

declare(strict_types=1);

use mbolli\nfsen_ng\store\AlertEventRepository;
use mbolli\nfsen_ng\store\Database;

beforeEach(function (): void {
    $this->db = Database::open(':memory:');
    $this->events = new AlertEventRepository($this->db);
});

/** Records a 'fired' event of rule $ruleId at $ts with the other columns filled in. */
function alertEventFired(AlertEventRepository $events, int $ts, ?string $ruleId = 'r1', string $kind = 'fired'): int {
    return $events->record($kind, $ts, $ruleId, 'Rule ' . ($ruleId ?? 'none'), 'live', ['gw1', 'gw2'], 'bytes', '>', 2048.5, 1000.0);
}

describe('AlertEventRepository::record()', function (): void {
    test('stores every column and returns the new id', function (): void {
        $id = $this->events->record('resolved', 1_700_000_000, 'r1', 'High traffic', 'live', ['gw1', 'gw2'], 'bytes', '>', 12.5, 1000.25);

        expect($id)->toBe(1)
            ->and($this->db->one('SELECT * FROM alert_events'))->toBe([
                'id' => 1,
                'ts' => 1_700_000_000,
                'kind' => 'resolved',
                'rule_id' => 'r1',
                'rule_name' => 'High traffic',
                'profile' => 'live',
                'sources' => '["gw1","gw2"]',
                'metric' => 'bytes',
                'operator' => '>',
                'value' => 12.5,
                'threshold' => 1000.25,
                'origin' => 'live',
            ])
        ;
    });

    test('keeps a missing rule id and a missing threshold as NULL', function (): void {
        $this->events->record('fired', 1, null, 'Old rule', '', [], 'flows', '', 3.0, null, 'migrated');

        expect($this->db->one('SELECT rule_id, threshold, origin, sources FROM alert_events'))->toBe([
            'rule_id' => null,
            'threshold' => null,
            'origin' => 'migrated',
            'sources' => '[]',
        ]);
    });

    test('rejects an unknown kind or origin', function (): void {
        expect(fn () => $this->events->record('bogus', 1, 'r1', 'R', 'live', [], 'bytes', '>', 1.0, 1.0))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->events->record('fired', 1, 'r1', 'R', 'live', [], 'bytes', '>', 1.0, 1.0, 'imported'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    test('prunes after every 100 inserts', function (): void {
        $old = time() - AlertEventRepository::RETENTION_SECONDS - 86_400;
        for ($i = 0; $i < AlertEventRepository::KEEP_AT_LEAST + 50; ++$i) {
            $this->db->exec("INSERT INTO alert_events (ts, kind, rule_name, metric, value) VALUES (?, 'fired', 'x', 'bytes', 1)", [$old + $i]);
        }

        for ($i = 1; $i < AlertEventRepository::PRUNE_EVERY; ++$i) {
            alertEventFired($this->events, time());
        }
        expect($this->db->value('SELECT COUNT(*) FROM alert_events'))->toBe(AlertEventRepository::KEEP_AT_LEAST + 50 + 99);

        alertEventFired($this->events, time());

        // 100 new events plus the newest 900 of the old ones make the 1000 kept.
        expect($this->db->value('SELECT COUNT(*) FROM alert_events'))->toBe(AlertEventRepository::KEEP_AT_LEAST);
    });
});

describe('AlertEventRepository::recent()', function (): void {
    test('returns the newest events first, decoded', function (): void {
        alertEventFired($this->events, 100);
        alertEventFired($this->events, 300, 'r2', 'resolved');
        alertEventFired($this->events, 200, 'r1', 'test');

        $recent = $this->events->recent();

        expect(array_column($recent, 'ts'))->toBe([300, 200, 100])
            ->and($recent[0])->toBe([
                'id' => 2,
                'ts' => 300,
                'kind' => 'resolved',
                'ruleId' => 'r2',
                'ruleName' => 'Rule r2',
                'profile' => 'live',
                'sources' => ['gw1', 'gw2'],
                'metric' => 'bytes',
                'operator' => '>',
                'value' => 2048.5,
                'threshold' => 1000.0,
                'origin' => 'live',
            ])
        ;
    });

    test('orders events of the same second by id', function (): void {
        $first = alertEventFired($this->events, 100);
        $second = alertEventFired($this->events, 100, 'r1', 'resolved');

        expect(array_column($this->events->recent(), 'id'))->toBe([$second, $first]);
    });

    test('honours the limit, the rule and the kinds', function (): void {
        foreach ([100, 200, 300, 400] as $ts) {
            alertEventFired($this->events, $ts, 'r1');
        }
        alertEventFired($this->events, 500, 'r2');
        alertEventFired($this->events, 600, 'r1', 'resolved');
        alertEventFired($this->events, 700, 'r1', 'test');

        expect(array_column($this->events->recent(2), 'ts'))->toBe([700, 600])
            ->and(array_column($this->events->recent(20, 'r2'), 'ts'))->toBe([500])
            ->and(array_column($this->events->recent(3, 'r1', ['fired']), 'ts'))->toBe([400, 300, 200])
            ->and(array_column($this->events->recent(20, null, ['fired', 'test']), 'kind'))->toBe(['test', 'fired', 'fired', 'fired', 'fired', 'fired'])
            ->and($this->events->recent(20, 'r9'))->toBe([])
        ;
    });

    test('decodes NULL columns', function (): void {
        $this->events->record('fired', 1, null, 'Old rule', '', [], 'flows', '', 3.0, null, 'migrated');

        $event = $this->events->recent()[0];

        expect($event['ruleId'])->toBeNull()
            ->and($event['threshold'])->toBeNull()
            ->and($event['sources'])->toBe([])
        ;
    });
});

describe('AlertEventRepository::lastFired()', function (): void {
    test('maps each rule to the time of its newest fired event only', function (): void {
        alertEventFired($this->events, 100, 'r1');
        alertEventFired($this->events, 300, 'r1');
        alertEventFired($this->events, 900, 'r1', 'resolved');
        alertEventFired($this->events, 950, 'r1', 'test');
        alertEventFired($this->events, 200, 'r2');
        alertEventFired($this->events, 999, 'r3', 'test');
        alertEventFired($this->events, 500, null);

        $last = $this->events->lastFired();
        ksort($last);

        expect($last)->toBe(['r1' => 300, 'r2' => 200]);
    });

    test('is empty without events', function (): void {
        expect($this->events->lastFired())->toBe([]);
    });
});

describe('AlertEventRepository::prune()', function (): void {
    test('deletes events older than the cut-off', function (): void {
        foreach ([100, 200, 300, 400] as $ts) {
            alertEventFired($this->events, $ts);
        }

        expect($this->events->prune(300, 0))->toBe(2)
            ->and(array_column($this->events->recent(), 'ts'))->toBe([400, 300])
        ;
    });

    test('keeps at least 1000 events even when all are old', function (): void {
        for ($i = 1; $i <= 1200; ++$i) {
            $this->db->exec("INSERT INTO alert_events (ts, kind, rule_name, metric, value) VALUES (?, 'fired', 'x', 'bytes', 1)", [$i]);
        }

        expect($this->events->prune(10_000))->toBe(200)
            ->and($this->db->value('SELECT COUNT(*) FROM alert_events'))->toBe(1000)
            ->and($this->db->value('SELECT MIN(ts) FROM alert_events'))->toBe(201)
            ->and($this->events->prune(10_000))->toBe(0)
        ;
    });

    test('keeps a custom minimum', function (): void {
        foreach ([100, 200, 300, 400] as $ts) {
            alertEventFired($this->events, $ts);
        }

        expect($this->events->prune(1_000, 3))->toBe(1)
            ->and(array_column($this->events->recent(), 'ts'))->toBe([400, 300, 200])
        ;
    });
});

describe('AlertEventRepository legacy import', function (): void {
    test('imports oldest first as migrated fired events and sets the meta key', function (): void {
        expect($this->events->legacyLogMigrated())->toBeFalse();

        $count = $this->events->importLegacyLog([
            ['ts' => 300, 'ruleId' => 'r1', 'ruleName' => 'A', 'profile' => 'live', 'sources' => ['gw1'], 'metric' => 'bytes', 'value' => 5.0],
            ['ts' => 100, 'ruleId' => null, 'ruleName' => 'B', 'profile' => 'test', 'sources' => [], 'metric' => 'flows', 'value' => 1.5],
        ]);

        expect($count)->toBe(2)
            ->and($this->events->legacyLogMigrated())->toBeTrue()
            ->and($this->db->metaGet(AlertEventRepository::LEGACY_META_KEY))->toBe('1')
            ->and($this->db->all('SELECT id, ts, kind, rule_id, rule_name, operator, threshold, origin FROM alert_events ORDER BY id'))->toBe([
                ['id' => 1, 'ts' => 100, 'kind' => 'fired', 'rule_id' => null, 'rule_name' => 'B', 'operator' => '', 'threshold' => null, 'origin' => 'migrated'],
                ['id' => 2, 'ts' => 300, 'kind' => 'fired', 'rule_id' => 'r1', 'rule_name' => 'A', 'operator' => '', 'threshold' => null, 'origin' => 'migrated'],
            ])
        ;
    });

    test('a failure inside the transaction leaves neither rows nor the key', function (): void {
        $this->db->exec("CREATE TRIGGER alert_boom BEFORE INSERT ON alert_events WHEN NEW.rule_name = 'boom' BEGIN SELECT RAISE(ABORT, 'boom'); END");

        expect(fn () => $this->events->importLegacyLog([
            ['ts' => 1, 'ruleId' => null, 'ruleName' => 'fine', 'profile' => '', 'sources' => [], 'metric' => 'bytes', 'value' => 1.0],
            ['ts' => 2, 'ruleId' => null, 'ruleName' => 'boom', 'profile' => '', 'sources' => [], 'metric' => 'bytes', 'value' => 1.0],
        ]))->toThrow(PDOException::class);

        expect($this->db->value('SELECT COUNT(*) FROM alert_events'))->toBe(0)
            ->and($this->events->legacyLogMigrated())->toBeFalse()
        ;
    });
});

describe('AlertEventRepository::deleteForRule() and deleteForOtherRules()', function (): void {
    test('delete Test events by rule, and never those without a rule', function (): void {
        foreach ([['test', 'r1'], ['fired', 'r1'], ['test', 'r2'], ['resolved', 'r2'], ['test', null]] as [$kind, $rule]) {
            $this->events->record($kind, 300, $rule, 'R', 'live', [], 'bytes', '>', 1.0, 1.0);
        }
        $kinds = fn (): array => array_map(static fn (array $e): string => ($e['rule_id'] ?? '-') . ' ' . $e['kind'], $this->db->all('SELECT rule_id, kind FROM alert_events ORDER BY id'));

        expect($this->events->deleteForRule('r1', []))->toBe(0)
            ->and($this->events->deleteForRule('r1', ['test', 'bogus']))->toBe(1)
            ->and($kinds())->toBe(['r1 fired', 'r2 test', 'r2 resolved', '- test'])
            ->and($this->events->deleteForOtherRules(['r2'], ['test']))->toBe(0)
            ->and($this->events->deleteForOtherRules([], ['test']))->toBe(1)
            ->and($kinds())->toBe(['r1 fired', 'r2 resolved', '- test'])
        ;
    });
});
