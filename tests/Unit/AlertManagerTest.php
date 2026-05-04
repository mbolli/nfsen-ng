<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\AlertState;
use mbolli\nfsen_ng\datasources\Datasource;

/**
 * Build a minimal AlertRule for testing.
 *
 * @param array<string, mixed> $overrides
 */
function makeRule(array $overrides = []): AlertRule {
    return AlertRule::fromArray(array_merge([
        'id' => 'test-id-001',
        'name' => 'Test rule',
        'enabled' => true,
        'profile' => 'live',
        'sources' => ['gw1'],
        'metric' => 'bytes',
        'operator' => '>',
        'thresholdType' => 'absolute',
        'thresholdValue' => 1000.0,
        'avgWindow' => '1h',
        'cooldownSlots' => 2,
        'notifyEmail' => null,
        'notifyWebhook' => null,
    ], $overrides));
}

/**
 * Build a mock Datasource stub with controllable fetchLatestSlot / fetchRollingAverage.
 *
 * @param array{flows: float, packets: float, bytes: float} $latestSlot
 * @param array{flows: float, packets: float, bytes: float} $rollingAvg
 */
function makeDatasource(array $latestSlot, array $rollingAvg = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]): Datasource {
    return new class($latestSlot, $rollingAvg) implements Datasource {
        public function __construct(
            private readonly array $latestSlot,
            private readonly array $rollingAvg,
        ) {}

        public function fetchLatestSlot(array $sources, string $profile): array {
            return $this->latestSlot;
        }

        public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds): array {
            return $this->rollingAvg;
        }

        // Stubs for all other interface methods — not used in alert tests
        public function healthChecks(string $group, array $sources): array {
            return [];
        }

        public function write(array $data): bool {
            return true;
        }

        public function get_graph_data(int $start, int $end, array $sources, array $protocols, array $ports, string $type = 'flows', string $display = 'sources', ?int $maxrows = 500, string $profile = ''): array|string {
            return [];
        }

        public function reset(array $sources, string $profile = ''): bool {
            return true;
        }

        public function date_boundaries(string $source, string $profile = ''): array {
            return [0, 0];
        }

        public function last_update(string $source, int $port = 0, string $profile = ''): int {
            return 0;
        }

        public function get_data_path(string $source = '', int $port = 0, string $profile = ''): string {
            return '';
        }
    };
}

// ── AlertManager::parseWindow ──────────────────────────────────────────────

describe('AlertManager::parseWindow()', function (): void {
    beforeEach(function (): void {
        $this->mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
    });

    test('parses all known window strings', function (): void {
        expect($this->mgr->parseWindow('10m'))->toBe(600)
            ->and($this->mgr->parseWindow('30m'))->toBe(1800)
            ->and($this->mgr->parseWindow('1h'))->toBe(3600)
            ->and($this->mgr->parseWindow('6h'))->toBe(21600)
            ->and($this->mgr->parseWindow('12h'))->toBe(43200)
            ->and($this->mgr->parseWindow('24h'))->toBe(86400)
        ;
    });

    test('returns 3600 for unknown window strings', function (): void {
        expect($this->mgr->parseWindow('unknown'))->toBe(3600);
    });
});

// ── AlertManager::computeThreshold ────────────────────────────────────────

describe('AlertManager::computeThreshold()', function (): void {
    test('absolute threshold returns thresholdValue directly', function (): void {
        $rule = makeRule(['thresholdType' => 'absolute', 'thresholdValue' => 5000.0]);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9000.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        expect($mgr->computeThreshold($rule, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9000.0]))->toBe(5000.0);
    });

    test('percent_of_avg computes percentage of rolling average', function (): void {
        $rule = makeRule([
            'thresholdType' => 'percent_of_avg',
            'thresholdValue' => 200.0, // 200%
            'avgWindow' => '1h',
            'metric' => 'bytes',
        ]);
        // Rolling average: 500 bytes → 200% = 1000
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 1200.0], ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 500.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $threshold = $mgr->computeThreshold($rule, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 1200.0]);
        expect($threshold)->toBe(1000.0);
    });

    test('percent_of_avg returns PHP_FLOAT_MAX when avg is zero (cold-start protection)', function (): void {
        $rule = makeRule([
            'thresholdType' => 'percent_of_avg',
            'thresholdValue' => 150.0,
            'avgWindow' => '1h',
            'metric' => 'bytes',
        ]);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0], ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $threshold = $mgr->computeThreshold($rule, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]);
        expect($threshold)->toBe(PHP_FLOAT_MAX);
    });
});

// ── AlertManager::runPeriodic ─────────────────────────────────────────────

describe('AlertManager::runPeriodic()', function (): void {
    afterEach(function (): void {
        foreach ([
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            '/tmp/alerts-state-test.json.tmp.' . getmypid(),
            '/tmp/alerts-log-test.json.tmp.' . getmypid(),
        ] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    });

    test('fires a rule when condition is met', function (): void {
        $rule = makeRule(['thresholdType' => 'absolute', 'thresholdValue' => 1000.0, 'metric' => 'bytes', 'operator' => '>']);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 2000.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $fired = $mgr->runPeriodic([$rule], 'live');
        expect($fired)->toBe(['Test rule']);
    });

    test('does not fire a rule when condition is not met', function (): void {
        $rule = makeRule(['thresholdType' => 'absolute', 'thresholdValue' => 5000.0, 'metric' => 'bytes', 'operator' => '>']);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 2000.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $fired = $mgr->runPeriodic([$rule], 'live');
        expect($fired)->toBe([]);
    });

    test('skips disabled rules', function (): void {
        $rule = makeRule(['enabled' => false, 'thresholdValue' => 1.0, 'metric' => 'bytes', 'operator' => '>']);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $fired = $mgr->runPeriodic([$rule], 'live');
        expect($fired)->toBe([]);
    });

    test('skips rules for a different profile', function (): void {
        $rule = makeRule(['profile' => 'other', 'thresholdValue' => 1.0, 'metric' => 'bytes', 'operator' => '>']);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $fired = $mgr->runPeriodic([$rule], 'live');
        expect($fired)->toBe([]);
    });

    test('respects cooldown: does not re-fire while cooldown is active', function (): void {
        $rule = makeRule([
            'thresholdType' => 'absolute',
            'thresholdValue' => 1.0,
            'metric' => 'bytes',
            'operator' => '>',
            'cooldownSlots' => 3,
        ]);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );

        // First call — fires
        $fired1 = $mgr->runPeriodic([$rule], 'live');
        expect($fired1)->toBe(['Test rule']);

        // Second call — in cooldown
        $fired2 = $mgr->runPeriodic([$rule], 'live');
        expect($fired2)->toBe([]);

        // Third call — still in cooldown
        $fired3 = $mgr->runPeriodic([$rule], 'live');
        expect($fired3)->toBe([]);
    });

    test('fires again after cooldown expires', function (): void {
        $rule = makeRule([
            'thresholdType' => 'absolute',
            'thresholdValue' => 1.0,
            'metric' => 'bytes',
            'operator' => '>',
            'cooldownSlots' => 2,
        ]);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );

        // Fire once (cooldownSlots=2 → sets cooldownRemaining=1)
        $mgr->runPeriodic([$rule], 'live');
        // First tick-down → cooldownRemaining=0
        $mgr->runPeriodic([$rule], 'live');
        // Now fires again
        $fired = $mgr->runPeriodic([$rule], 'live');
        expect($fired)->toBe(['Test rule']);
    });

    test('appends to alert log when rule fires', function (): void {
        $rule = makeRule(['thresholdValue' => 1.0, 'metric' => 'bytes', 'operator' => '>']);
        $mgr = new AlertManager(
            makeDatasource(['flows' => 0.0, 'packets' => 0.0, 'bytes' => 9999.0]),
            '/tmp/alerts-state-test.json',
            '/tmp/alerts-log-test.json',
            ''
        );
        $mgr->runPeriodic([$rule], 'live');

        $log = $mgr->getRecentLog(5);
        expect($log)->toHaveCount(1)
            ->and($log[0]['rule'])->toBe('Test rule')
            ->and($log[0]['metric'])->toBe('bytes')
        ;
    });
});

// ── AlertRule::fromArray / toArray roundtrip ───────────────────────────────

describe('AlertRule roundtrip', function (): void {
    test('fromArray → toArray preserves all fields', function (): void {
        $data = [
            'id' => 'abc-123',
            'name' => 'My rule',
            'enabled' => true,
            'profile' => 'live',
            'sources' => ['gw1', 'gw2'],
            'metric' => 'flows',
            'operator' => '<=',
            'thresholdType' => 'percent_of_avg',
            'thresholdValue' => 80.0,
            'avgWindow' => '6h',
            'cooldownSlots' => 5,
            'notifyEmail' => 'alert@example.com',
            'notifyWebhook' => 'https://example.com/hook',
        ];

        $rule = AlertRule::fromArray($data);
        expect($rule->toArray())->toBe($data);
    });

    test('withEnabled returns a new instance with updated enabled flag', function (): void {
        $rule = makeRule(['enabled' => true]);
        $disabled = $rule->withEnabled(false);

        expect($disabled->enabled)->toBeFalse()
            ->and($rule->enabled)->toBeTrue() // original unchanged
            ->and($disabled->id)->toBe($rule->id)
        ;
    });
});

// ── AlertState ────────────────────────────────────────────────────────────

describe('AlertState', function (): void {
    test('initial() returns zero state', function (): void {
        $state = AlertState::initial();
        expect($state->cooldownRemaining)->toBe(0)
            ->and($state->lastTriggeredAt)->toBe(0)
            ->and($state->recentTriggers)->toBe([])
        ;
    });

    test('fromArray → toArray roundtrip', function (): void {
        $data = ['cooldownRemaining' => 2, 'lastTriggeredAt' => 1700000000, 'recentTriggers' => [1700000000]];
        $state = AlertState::fromArray($data);
        expect($state->toArray())->toBe($data);
    });
});
