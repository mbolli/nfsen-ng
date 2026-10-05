<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\pages\AlertsPage;
use mbolli\nfsen_ng\store\AlertSampleRepository;
use mbolli\nfsen_ng\store\Database;

beforeEach(function (): void {
    $this->db = Database::open(':memory:');
    $this->samples = new AlertSampleRepository($this->db);
});

/** @return list<array{rule_id: string, ts: int, fingerprint: string, value: float}> */
function alertSampleRows(Database $db): array {
    return $db->all('SELECT rule_id, ts, fingerprint, value FROM alert_samples ORDER BY rule_id, ts');
}

describe('AlertSampleRepository::record()', function (): void {
    test('stores one value per rule and interval, the last write winning', function (): void {
        $this->samples->record('r1', 'f', 3000, 10.0);
        $this->samples->record('r1', 'f', 3300, 20.0);
        $this->samples->record('r1', 'f', 3300, 25.0);
        $this->samples->record('r2', 'g', 3300, 7.5);

        expect(alertSampleRows($this->db))->toBe([
            ['rule_id' => 'r1', 'ts' => 3000, 'fingerprint' => 'f', 'value' => 10.0],
            ['rule_id' => 'r1', 'ts' => 3300, 'fingerprint' => 'f', 'value' => 25.0],
            ['rule_id' => 'r2', 'ts' => 3300, 'fingerprint' => 'g', 'value' => 7.5],
        ]);
    });

    test('a new fingerprint drops the rule\'s older samples, not those of other rules', function (): void {
        $this->samples->record('r1', 'before-edit', 3000, 10.0);
        $this->samples->record('r2', 'before-edit', 3000, 1.0);
        $this->samples->record('r1', 'after-edit', 3300, 20.0);

        expect(alertSampleRows($this->db))->toBe([
            ['rule_id' => 'r1', 'ts' => 3300, 'fingerprint' => 'after-edit', 'value' => 20.0],
            ['rule_id' => 'r2', 'ts' => 3000, 'fingerprint' => 'before-edit', 'value' => 1.0],
        ]);
    });

    test('keeps the longest window before the recorded interval, and the samples after an interval checked late', function (): void {
        $slot = 1_790_000_100;
        foreach ([$slot - AlertSampleRepository::KEEP_SECONDS - 300, $slot - AlertSampleRepository::KEEP_SECONDS, $slot - 300, $slot + 600] as $ts) {
            $this->db->exec("INSERT INTO alert_samples (rule_id, ts, fingerprint, value) VALUES ('r1', ?, 'f', 1)", [$ts]);
        }

        $this->samples->record('r1', 'f', $slot, 2.0);
        $this->samples->record('r1', 'f', $slot - 600, 3.0, $slot + 3600);

        expect(array_column(alertSampleRows($this->db), 'ts'))->toBe([$slot - AlertSampleRepository::KEEP_SECONDS, $slot - 600, $slot - 300, $slot, $slot + 600]);
    });

    test('drops the samples after the recorded interval only when one lies beyond the horizon and the interval does not', function (): void {
        $horizon = 1_790_003_700;
        $rows = static fn (Database $db): array => array_map(static fn (array $row): string => $row['rule_id'] . '@' . $row['ts'], alertSampleRows($db));
        foreach ([['r1', $horizon - 300], ['r1', $horizon + 3600], ['r2', $horizon + 3600]] as [$rule, $ts]) {
            $this->db->exec("INSERT INTO alert_samples (rule_id, ts, fingerprint, value) VALUES (?, ?, 'f', 1)", [$rule, $ts]);
        }

        // A timeline that stays ahead of the clock is no rewind.
        $this->samples->record('r2', 'f', $horizon + 300, 2.0, $horizon);
        $ahead = $rows($this->db);
        $this->samples->record('r1', 'f', $horizon - 3600, 2.0, $horizon);

        expect($ahead)->toBe(['r1@' . ($horizon - 300), 'r1@' . ($horizon + 3600), 'r2@' . ($horizon + 300), 'r2@' . ($horizon + 3600)])
            ->and($rows($this->db))->toBe(['r1@' . ($horizon - 3600), 'r2@' . ($horizon + 300), 'r2@' . ($horizon + 3600)])
        ;
    });

    test('keeps what the longest averaging window can ask for', function (): void {
        $manager = new AlertManager($this->createStub(Datasource::class), sys_get_temp_dir() . '/nope.json', sys_get_temp_dir() . '/nope.log', '');
        $longest = max(array_map($manager->parseWindow(...), array_keys(AlertsPage::WINDOWS)));

        expect(AlertSampleRepository::KEEP_SECONDS)->toBe($longest);
    });

    test('refuses a value that is not a finite number', function (): void {
        expect(fn () => $this->samples->record('r1', 'f', 300, NAN))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->samples->record('r1', 'f', 300, INF))->toThrow(InvalidArgumentException::class)
            ->and(alertSampleRows($this->db))->toBe([])
        ;
    });
});

describe('AlertSampleRepository::average()', function (): void {
    test('averages the rule\'s samples of the fingerprint in [from, to)', function (): void {
        $this->samples->record('r1', 'f', 600, 10.0);
        $this->samples->record('r1', 'f', 900, 20.0);
        $this->samples->record('r1', 'f', 1200, 60.0);
        $this->samples->record('r2', 'f', 900, 1000.0);

        expect($this->samples->average('r1', 'f', 600, 1200))->toBe(['count' => 2, 'average' => 15.0])
            ->and($this->samples->average('r1', 'f', 601, 1201))->toBe(['count' => 2, 'average' => 40.0])
            ->and($this->samples->average('r1', 'other', 0, 2000))->toBe(['count' => 0, 'average' => 0.0])
            ->and($this->samples->average('r3', 'f', 0, 2000))->toBe(['count' => 0, 'average' => 0.0])
        ;
    });

    test('a zero value is a sample like any other', function (): void {
        $this->samples->record('r1', 'f', 600, 0.0);

        expect($this->samples->average('r1', 'f', 0, 900))->toBe(['count' => 1, 'average' => 0.0]);
    });
});

describe('AlertSampleRepository deletion', function (): void {
    test('deleteRule() drops one rule\'s samples', function (): void {
        $this->samples->record('r1', 'f', 600, 1.0);
        $this->samples->record('r1', 'f', 900, 2.0);
        $this->samples->record('r2', 'f', 900, 3.0);

        expect($this->samples->deleteRule('r1'))->toBe(2)
            ->and(array_column(alertSampleRows($this->db), 'rule_id'))->toBe(['r2'])
        ;
    });

    test('keepOnly() drops the samples of rules that are gone', function (): void {
        foreach (['r1', 'r2', 'r3'] as $rule) {
            $this->samples->record($rule, 'f', 600, 1.0);
        }

        expect($this->samples->keepOnly(['r2', 'r9']))->toBe(2)
            ->and(array_column(alertSampleRows($this->db), 'rule_id'))->toBe(['r2'])
            ->and($this->samples->keepOnly(['r2']))->toBe(0)
        ;
    });

    test('keepFingerprints() drops each listed rule\'s samples of another fingerprint, and leaves unlisted rules alone', function (): void {
        $this->samples->record('r1', 'a', 600, 1.0);
        $this->db->exec("INSERT INTO alert_samples (rule_id, ts, fingerprint, value) VALUES ('r1', 900, 'b', 2)");
        $this->samples->record('r2', 'a', 600, 3.0);
        $this->samples->record('r3', 'x', 600, 4.0);

        expect($this->samples->keepFingerprints(['r1' => 'b', 'r2' => 'a', 'r9' => 'z']))->toBe(1)
            ->and(alertSampleRows($this->db))->toBe([
                ['rule_id' => 'r1', 'ts' => 900, 'fingerprint' => 'b', 'value' => 2.0],
                ['rule_id' => 'r2', 'ts' => 600, 'fingerprint' => 'a', 'value' => 3.0],
                ['rule_id' => 'r3', 'ts' => 600, 'fingerprint' => 'x', 'value' => 4.0],
            ])
            ->and($this->samples->keepFingerprints(['r3' => 'y']))->toBe(1)
            ->and($this->samples->keepFingerprints([]))->toBe(0)
        ;
    });

    test('prune() drops the listed rules\' samples before a time, whether they still record or not', function (): void {
        foreach ([['r1', 300], ['r1', 600], ['r1', 900], ['r2', 300]] as [$rule, $ts]) {
            $this->db->exec("INSERT INTO alert_samples (rule_id, ts, fingerprint, value) VALUES (?, ?, 'f', 1)", [$rule, $ts]);
        }

        expect($this->samples->prune(['r1', 'r9'], 900))->toBe(2)
            ->and(array_map(static fn (array $row): string => $row['rule_id'] . '@' . $row['ts'], alertSampleRows($this->db)))->toBe(['r1@900', 'r2@300'])
            ->and($this->samples->prune([], PHP_INT_MAX))->toBe(0)
        ;
    });

    test('keepOnly(), keepFingerprints() and prune() only read when nothing is to go, so a writer elsewhere does not block them', function (): void {
        $path = sys_get_temp_dir() . '/nfsen-alert-samples-' . bin2hex(random_bytes(6)) . '.sqlite';
        $db = Database::open($path);
        $samples = new AlertSampleRepository($db);
        $samples->record('r1', 'f', 600, 1.0);
        $writer = new PDO('sqlite:' . $path);
        $writer->exec('BEGIN IMMEDIATE');

        try {
            expect($samples->keepOnly(['r1']))->toBe(0)
                ->and($samples->keepFingerprints(['r1' => 'f']))->toBe(0)
                ->and($samples->prune(['r1'], 600))->toBe(0)
                ->and(fn () => $samples->keepOnly([]))->toThrow(PDOException::class)
                ->and(fn () => $samples->keepFingerprints(['r1' => 'g']))->toThrow(PDOException::class)
                ->and(fn () => $samples->prune(['r1'], 601))->toThrow(PDOException::class)
            ;
        } finally {
            $writer->exec('ROLLBACK');
            unset($writer, $samples, $db);
            array_map('unlink', glob($path . '*') ?: []);
        }
    });
});
