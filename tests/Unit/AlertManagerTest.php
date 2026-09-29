<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\AlertState;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfcapdFiles;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\store\AlertEventRepository;
use mbolli\nfsen_ng\store\Database;
use OpenSwoole\Coroutine;

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
 * A Datasource stub with controllable fetchLatestSlot / fetchRollingAverage. The latest slot can be
 * changed between evaluations; a Throwable makes fetchLatestSlot throw it. $latestCalls counts reads.
 * Once $rows is filled it behaves like the RRD store: each source's newest row is its latest slot.
 * $onRead runs inside every read, where a real read can yield to another coroutine.
 *
 * @param array{flows: float, packets: float, bytes: float}|Throwable $latestSlot
 * @param array{flows: float, packets: float, bytes: float}           $rollingAvg
 */
function makeDatasource(array|Throwable $latestSlot, array $rollingAvg = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0]): Datasource {
    return new class($latestSlot, $rollingAvg) implements Datasource {
        public int $latestCalls = 0;

        /** @var array<string, array<int, array{flows: float, packets: float, bytes: float}>> source => slot => values */
        public array $rows = [];

        public ?Closure $onRead = null;

        public function __construct(
            public array|Throwable $latestSlot,
            public array $rollingAvg,
        ) {}

        public function fetchLatestSlot(array $sources, string $profile): array {
            ++$this->latestCalls;
            if ($this->onRead !== null) {
                ($this->onRead)();
            }
            if ($this->latestSlot instanceof Throwable) {
                throw $this->latestSlot;
            }
            if ($this->rows === []) {
                return $this->latestSlot;
            }

            $sum = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
            foreach ($sources as $source) {
                $rows = $this->rows[$source] ?? [];
                if ($rows !== []) {
                    foreach ($rows[max(array_keys($rows))] as $metric => $value) {
                        $sum[$metric] += $value;
                    }
                }
            }

            return $sum;
        }

        public function last_update(string $source, int $port = 0, string $profile = ''): int {
            return ($this->rows[$source] ?? []) === [] ? 0 : max(array_keys($this->rows[$source]));
        }

        public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds): array {
            return $this->rollingAvg;
        }

        // Stubs for the other interface methods, not used in alert tests
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

        public function acceptsHistoricWrites(): bool {
            return true;
        }

        public function date_boundaries(string $source, string $profile = ''): array {
            return [0, 0];
        }

        public function get_data_path(string $source = '', int $port = 0, string $profile = ''): string {
            return '';
        }
    };
}

/** @return array{flows: float, packets: float, bytes: float} */
function alertBytes(float $bytes): array {
    return ['flows' => 1.0, 'packets' => 2.0, 'bytes' => $bytes];
}

/**
 * What ImportDaemon does for one file: the source's row lands in the store, then the callback
 * runs. gw2 is the last configured source.
 *
 * @param list<AlertRule> $rules
 *
 * @return list<string>
 */
function alertImport(AlertManager $mgr, Datasource $ds, array $rules, string $source, int $ts, float $bytes): array {
    $ds->rows[$source][$ts] = alertBytes($bytes);

    return $mgr->onFileImported($rules, 'live', $ts, $source === 'gw2', $source);
}

/** @return list<array{int, float}> [slot, value] of every event, oldest first */
function alertSlotValues(Database $db): array {
    return array_map(static fn (array $event): array => [$event['ts'], $event['value']], alertEvents($db));
}

/** A manager with its state and log in the test's temp directory and an in-memory history. */
function alertManager(object $test, Datasource $ds, string $emailFrom = ''): AlertManager {
    return new AlertManager($ds, $test->dir . '/alerts-state.json', $test->dir . '/alerts-log.json', $emailFrom, $test->events);
}

/** @return list<array{ts: int, kind: string, value: float, threshold: ?float}> oldest first */
function alertEvents(Database $db): array {
    return $db->all('SELECT ts, kind, value, threshold FROM alert_events ORDER BY id');
}

/** An empty rotated capture file of $source in profile 'live' for the interval starting at $ts. */
function alertCaptureFile(string $source, int $ts): void {
    $day = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
    $dir = NfcapdFiles::sourcePath('live', $source) . '/' . $day->format('Y/m/d');
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    touch($dir . '/nfcapd.' . $day->format('YmdHi'));
}

/** Settings for filtered rules: nfdump-canned answers, with $maxProcesses slots. */
function alertCannedSettings(object $test, int $maxProcesses, int $logPriority = LOG_ERR): Settings {
    return Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => dirname(__DIR__) . '/Support/bin/nfdump-canned', 'profiles-data' => $test->dir . '/profiles', 'profile' => 'live', 'max-processes' => $maxProcesses],
        'log' => ['priority' => $logPriority],
    ]);
}

function alertRemoveTree(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                alertRemoveTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/alert-manager-test-' . bin2hex(random_bytes(4));
    mkdir($this->dir . '/profiles', 0o777, true);
    $this->db = Database::open(':memory:');
    $this->events = new AlertEventRepository($this->db);

    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $this->dir . '/profiles', 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => LOG_ERR],
    ]);
});

afterEach(function (): void {
    alertRemoveTree($this->dir);
    Database::resetShared();
    if ($this->settingsBefore instanceof Settings) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->stateDirBefore !== null) {
        Config::$stateDir = $this->stateDirBefore;
    }
});

// ── AlertManager::parseWindow ──────────────────────────────────────────────

describe('AlertManager::parseWindow()', function (): void {
    test('parses all known window strings', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));

        expect($mgr->parseWindow('10m'))->toBe(600)
            ->and($mgr->parseWindow('30m'))->toBe(1800)
            ->and($mgr->parseWindow('1h'))->toBe(3600)
            ->and($mgr->parseWindow('6h'))->toBe(21600)
            ->and($mgr->parseWindow('12h'))->toBe(43200)
            ->and($mgr->parseWindow('24h'))->toBe(86400)
        ;
    });

    test('returns 3600 for unknown window strings', function (): void {
        expect(alertManager($this, makeDatasource(alertBytes(0.0)))->parseWindow('unknown'))->toBe(3600);
    });
});

// ── AlertManager::computeThreshold ────────────────────────────────────────

describe('AlertManager::computeThreshold()', function (): void {
    test('absolute threshold returns thresholdValue directly', function (): void {
        $rule = makeRule(['thresholdType' => 'absolute', 'thresholdValue' => 5000.0]);
        $mgr = alertManager($this, makeDatasource(alertBytes(9000.0)));

        expect($mgr->computeThreshold($rule, alertBytes(9000.0)))->toBe(5000.0);
    });

    test('percent_of_avg computes percentage of rolling average', function (): void {
        $rule = makeRule(['thresholdType' => 'percent_of_avg', 'thresholdValue' => 200.0, 'avgWindow' => '1h']);
        // Rolling average: 500 bytes, 200% = 1000
        $mgr = alertManager($this, makeDatasource(alertBytes(1200.0), alertBytes(500.0)));

        expect($mgr->computeThreshold($rule, alertBytes(1200.0)))->toBe(1000.0);
    });

    test('percent_of_avg returns PHP_FLOAT_MAX when avg is zero (cold-start protection)', function (): void {
        $rule = makeRule(['thresholdType' => 'percent_of_avg', 'thresholdValue' => 150.0, 'avgWindow' => '1h']);
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0), alertBytes(0.0)));

        expect($mgr->computeThreshold($rule, alertBytes(9999.0)))->toBe(PHP_FLOAT_MAX);
    });
});

// ── AlertManager::runPeriodic ─────────────────────────────────────────────

describe('AlertManager::runPeriodic()', function (): void {
    test('fires a rule when condition is met', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));

        expect($mgr->runPeriodic([makeRule()], 'live', 300))->toBe(['Test rule'])
            ->and(alertEvents($this->db))->toBe([['ts' => 300, 'kind' => 'fired', 'value' => 2000.0, 'threshold' => 1000.0]])
        ;
    });

    test('does not fire a rule when condition is not met', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));

        expect($mgr->runPeriodic([makeRule(['thresholdValue' => 5000.0])], 'live', 300))->toBe([])
            ->and(alertEvents($this->db))->toBe([])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(300)
        ;
    });

    test('skips disabled rules', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0)));

        expect($mgr->runPeriodic([makeRule(['enabled' => false, 'thresholdValue' => 1.0])], 'live', 300))->toBe([])
            ->and($mgr->states())->toBe([])
        ;
    });

    test('skips rules for a different profile', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0)));

        expect($mgr->runPeriodic([makeRule(['profile' => 'other', 'thresholdValue' => 1.0])], 'live', 300))->toBe([]);
    });

    test('evaluates a slot once, and never an older one', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 0]);

        expect($mgr->runPeriodic([$rule], 'live', 600))->toBe(['Test rule'])
            ->and($mgr->runPeriodic([$rule], 'live', 600))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', 300))->toBe([])
            ->and($ds->latestCalls)->toBe(1)
        ;
    });

    test('skips a slot more than an hour older than the last evaluated one, too', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 0]);

        $mgr->runPeriodic([$rule], 'live', 100_000);

        expect($mgr->runPeriodic([$rule], 'live', 100_000 - 7200))->toBe([])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(100_000)
            ->and($ds->latestCalls)->toBe(1)
        ;
    });

    test('evaluates again when the last evaluated slot lies in the future (the clock or NFCAPD_TZ moved back)', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 0]);
        $now = intdiv(time(), 300) * 300;

        $mgr->runPeriodic([$rule], 'live', $now + 7200);

        expect($mgr->runPeriodic([$rule], 'live', $now - 300))->toBe(['Test rule'])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe($now - 300)
        ;
    });

    test('skips the slot it evaluated last even when that lies hours ahead of the clock', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 0]);
        $ahead = intdiv(time(), 300) * 300 + 7200;

        expect($mgr->runPeriodic([$rule], 'live', $ahead))->toBe(['Test rule'])
            ->and($mgr->runPeriodic([$rule], 'live', $ahead))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', $ahead - 300))->toBe([])
            ->and(array_column(alertEvents($this->db), 'ts'))->toBe([$ahead])
            ->and($ds->latestCalls)->toBe(1)
        ;
    });

    test('forget() while the read is in flight discards that evaluation', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 0]);
        $mgr->runPeriodic([$rule], 'live', 300);

        $ds->onRead = static fn () => $mgr->forget('test-id-001', $rule->withEnabled(false));

        expect($mgr->runPeriodic([$rule], 'live', 600))->toBe([])
            ->and($mgr->states())->toBe([])
            ->and($mgr->firingCount())->toBe(0)
            ->and(alertManager($this, makeDatasource(alertBytes(0.0)))->states())->toBe([])
        ;

        // A rule without state yet: nothing is written back either.
        expect($mgr->runPeriodic([makeRule(['id' => 'fresh'])], 'live', 600))->toBe(['Test rule']);
        $ds->onRead = static fn () => $mgr->forget('quiet');
        $mgr->runPeriodic([makeRule(['id' => 'quiet'])], 'live', 900);
        $ds->onRead = null;
        $mgr->runPeriodic([$rule->withEnabled(false)], 'live', 900);

        expect(array_keys($mgr->states()))->toBe(['fresh'])
            ->and(array_column(alertEvents($this->db), 'kind'))->toBe(['fired', 'resolved', 'fired'])
        ;
    });

    test('forget() of one rule while another rule\'s read is in flight discards the stale rule', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $r1 = makeRule(['id' => 'r1', 'name' => 'R1', 'cooldownSlots' => 0]);
        $r2 = makeRule(['id' => 'r2', 'name' => 'R2', 'cooldownSlots' => 0]);
        expect($mgr->runPeriodic([$r1, $r2], 'live', 300))->toBe(['R1', 'R2']);

        $done = false;
        $ds->onRead = static function () use ($mgr, $r2, &$done): void {
            if (!$done) {
                $done = true;
                $mgr->forget('r2', $r2->withEnabled(false));
            }
        };

        expect($mgr->runPeriodic([$r1, $r2], 'live', 600))->toBe(['R1'])
            ->and(array_keys($mgr->states()))->toBe(['r1'])
            ->and(array_map(static fn (array $e): array => [$e['rule_id'], $e['kind'], $e['ts']], $this->db->all('SELECT rule_id, kind, ts FROM alert_events ORDER BY id')))
            ->toBe([['r1', 'fired', 300], ['r2', 'fired', 300], ['r2', 'resolved', 600], ['r1', 'fired', 600]])
        ;
    });

    test('fired, still firing, then resolved: one event each, no event while it keeps firing', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 3]);

        expect($mgr->runPeriodic([$rule], 'live', 300))->toBe(['Test rule']);
        $state = $mgr->states()['test-id-001'];
        expect($state->firing)->toBeTrue()
            ->and($state->firedAt)->toBe(300)
            ->and($state->lastValue)->toBe(2000.0)
            ->and($state->cooldownRemaining)->toBe(3)
            ->and($mgr->firingCount())->toBe(1)
        ;

        expect($mgr->runPeriodic([$rule], 'live', 600))->toBe([]);

        $ds->latestSlot = alertBytes(500.0);
        expect($mgr->runPeriodic([$rule], 'live', 900))->toBe([]);
        $state = $mgr->states()['test-id-001'];

        expect($state->firing)->toBeFalse()
            ->and($state->firedAt)->toBeNull()
            ->and($state->lastValue)->toBe(500.0)
            ->and($mgr->firingCount())->toBe(0)
            ->and(alertEvents($this->db))->toBe([
                ['ts' => 300, 'kind' => 'fired', 'value' => 2000.0, 'threshold' => 1000.0],
                ['ts' => 900, 'kind' => 'resolved', 'value' => 500.0, 'threshold' => 1000.0],
            ])
        ;
    });

    test('respects cooldown: does not re-fire while cooldown is active', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0)));
        $rule = makeRule(['thresholdValue' => 1.0, 'cooldownSlots' => 3]);

        expect($mgr->runPeriodic([$rule], 'live', 300))->toBe(['Test rule'])
            ->and($mgr->runPeriodic([$rule], 'live', 600))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', 900))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', 1200))->toBe(['Test rule'])
        ;
    });

    test('re-notifies after the cooldown while the condition keeps holding', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0)));
        $rule = makeRule(['thresholdValue' => 1.0, 'cooldownSlots' => 2]);

        $fired = [];
        foreach ([300, 600, 900, 1200, 1500] as $slot) {
            $fired[$slot] = $mgr->runPeriodic([$rule], 'live', $slot);
        }

        expect($fired)->toBe([300 => ['Test rule'], 600 => [], 900 => ['Test rule'], 1200 => [], 1500 => ['Test rule']])
            ->and(array_column(alertEvents($this->db), 'ts'))->toBe([300, 900, 1500])
            ->and($mgr->states()['test-id-001']->recentTriggers)->toBe([300, 900, 1500])
            ->and($mgr->states()['test-id-001']->lastTriggeredAt)->toBe(1500)
            ->and($mgr->states()['test-id-001']->firedAt)->toBe(300)
        ;
    });

    test('a cooldown of zero re-notifies every slot', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(9999.0)));
        $rule = makeRule(['cooldownSlots' => 0]);

        foreach ([300, 600, 900] as $slot) {
            expect($mgr->runPeriodic([$rule], 'live', $slot))->toBe(['Test rule']);
        }
    });

    test('firing again inside the cooldown after a resolve records the event', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 3]);

        $mgr->runPeriodic([$rule], 'live', 300);
        $ds->latestSlot = alertBytes(10.0);
        $mgr->runPeriodic([$rule], 'live', 600);
        $ds->latestSlot = alertBytes(9999.0);

        expect($mgr->runPeriodic([$rule], 'live', 900))->toBe(['Test rule'])
            ->and(array_column(alertEvents($this->db), 'kind'))->toBe(['fired', 'resolved', 'fired'])
            ->and($mgr->states()['test-id-001']->cooldownRemaining)->toBe(3)
            ->and($mgr->states()['test-id-001']->firedAt)->toBe(900)
        ;
    });

    test('could not evaluate: a failed read keeps the state and records nothing', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['cooldownSlots' => 3]);
        $mgr->runPeriodic([$rule], 'live', 300);
        $before = $mgr->states()['test-id-001']->toArray();

        $ds->latestSlot = new RuntimeException('rrd_fetch failed');

        expect($mgr->runPeriodic([$rule], 'live', 600))->toBe([])
            ->and($mgr->states()['test-id-001']->toArray())->toBe($before)
            ->and(alertEvents($this->db))->toHaveCount(1)
        ;

        // The slot stays open, so it is evaluated once the read works again.
        $ds->latestSlot = alertBytes(10.0);
        $mgr->runPeriodic([$rule], 'live', 600);

        expect(array_column(alertEvents($this->db), 'kind'))->toBe(['fired', 'resolved']);
    });

    test('could not evaluate: no baseline keeps a firing rule firing, even for a < rule', function (): void {
        $ds = makeDatasource(alertBytes(10.0), alertBytes(1000.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['thresholdType' => 'percent_of_avg', 'thresholdValue' => 50.0, 'operator' => '<']);

        expect($mgr->runPeriodic([$rule], 'live', 300))->toBe(['Test rule']);
        $before = $mgr->states()['test-id-001']->toArray();

        $ds->rollingAvg = alertBytes(0.0);

        expect($mgr->runPeriodic([$rule], 'live', 600))->toBe([])
            ->and($mgr->states()['test-id-001']->toArray())->toBe($before)
            ->and(alertEvents($this->db))->toHaveCount(1)
        ;
    });

    test('a firing rule that gets disabled is resolved with its last value', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $rule = makeRule();
        $mgr->runPeriodic([$rule], 'live', 300);

        expect($mgr->runPeriodic([$rule->withEnabled(false)], 'live', 600))->toBe([])
            ->and($mgr->firingCount())->toBe(0)
            ->and($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([
                ['ts' => 300, 'kind' => 'fired', 'value' => 2000.0, 'threshold' => 1000.0],
                ['ts' => 600, 'kind' => 'resolved', 'value' => 2000.0, 'threshold' => 1000.0],
            ])
        ;
    });

    test('state survives a restart, including the new fields', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $mgr->runPeriodic([makeRule()], 'live', 300);

        $state = alertManager($this, makeDatasource(alertBytes(0.0)))->states()['test-id-001'];

        expect($state->firing)->toBeTrue()
            ->and($state->firedAt)->toBe(300)
            ->and($state->lastValue)->toBe(2000.0)
            ->and($state->lastEvaluatedSlot)->toBe(300)
            ->and($state->cooldownRemaining)->toBe(2)
        ;
    });

    test('getRecentLog() reads fired and test events in the old log shape', function (): void {
        $ds = makeDatasource(alertBytes(9999.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['thresholdValue' => 1.0]);
        $mgr->runPeriodic([$rule], 'live', 300);
        $ds->latestSlot = alertBytes(0.0);
        $mgr->runPeriodic([$rule], 'live', 600);

        $log = $mgr->getRecentLog(5);

        expect($log)->toHaveCount(1)
            ->and($log[0])->toBe([
                'ts' => 300,
                'rule' => 'Test rule',
                'metric' => 'bytes',
                'value' => 9999.0,
                'profile' => 'live',
                'sources' => ['gw1'],
                'kind' => 'fired',
                'threshold' => 1.0,
                'ruleId' => 'test-id-001',
            ])
            ->and(array_column($mgr->recentEvents(), 'kind'))->toBe(['resolved', 'fired'])
            ->and($mgr->lastFired())->toBe(['test-id-001' => 300])
        ;
    });

    test('dispatchNotifications() records a test event by default and the given kind otherwise', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));

        $mgr->dispatchNotifications(makeRule(), alertBytes(1500.0), PHP_FLOAT_MAX, 1_000);
        $mgr->dispatchNotifications(makeRule(), alertBytes(1500.0), 1000.0, 2_000, 'fired');

        expect(alertEvents($this->db))->toBe([
            ['ts' => 1_000, 'kind' => 'test', 'value' => 1500.0, 'threshold' => null],
            ['ts' => 2_000, 'kind' => 'fired', 'value' => 1500.0, 'threshold' => 1000.0],
        ]);
    });
});

// ── Live evaluation and nfdump slots ───────────────────────────────────────

describe('AlertManager live evaluation and nfdump slots', function (): void {
    afterEach(function (): void {
        foreach (NfdumpSlots::CLASSES as $class) {
            NfdumpSlots::release($class, NfdumpSlots::inUse($class));
        }
        putenv('NFDUMP_STUB_STDOUT');
    });

    test('one evaluation takes background slots with LIVE_SLOT_BUDGET_SECONDS for all its rules together', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $scopes = [];
        $firstRead = null;
        $ds->onRead = static function () use (&$scopes, &$firstRead): void {
            $firstRead ??= microtime(true);
            $scopes[] = NfdumpSlots::scope() + ['left' => NfdumpSlots::waitFor(NfdumpSlots::scope())];
            usleep(150_000);
        };
        $started = microtime(true);

        $fired = $mgr->runPeriodic([makeRule(), makeRule(['id' => 'second', 'name' => 'Second'])], 'live', 300);

        // The scope opens between $started and the first read, so its deadline lies between those plus the budget.
        expect($fired)->toBe(['Test rule', 'Second'])
            ->and(array_column($scopes, 'class'))->toBe([NfdumpSlots::BACKGROUND, NfdumpSlots::BACKGROUND])
            ->and($scopes[1]['until'])->toBe($scopes[0]['until'])
            ->and($scopes[0]['until'])->toBeGreaterThanOrEqual($started + AlertManager::LIVE_SLOT_BUDGET_SECONDS)
            ->and($scopes[0]['until'])->toBeLessThanOrEqual($firstRead + AlertManager::LIVE_SLOT_BUDGET_SECONDS)
            ->and($scopes[1]['left'])->toBeLessThan(AlertManager::LIVE_SLOT_BUDGET_SECONDS - 0.14)
            ->and(NfdumpSlots::scope()['until'])->toBeNull()
        ;
    });

    // An outer budget of 1 s stands in for the 60 s one: the first rule waits it out, the second is
    // skipped at once, and neither is evaluated on zeros. The test above pins that rules share one budget.
    test('filtered rules left without a slot are skipped, and a spent budget ends every later wait', function (): void {
        Config::$settings = alertCannedSettings($this, 1, LOG_INFO);
        putenv('NFDUMP_STUB_STDOUT=[{"in_packets":2,"in_bytes":100}]');
        $slot = intdiv(time(), 300) * 300 - 300;
        alertCaptureFile('gw1', $slot);
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));
        $rules = [
            makeRule(['nfdumpFilter' => 'proto tcp', 'thresholdValue' => 1.0]),
            makeRule(['id' => 'second', 'name' => 'Second', 'nfdumpFilter' => 'proto udp', 'thresholdValue' => 1.0]),
        ];
        $seq = Debug::recent(1)[0]['seq'] ?? 0;
        NfdumpSlots::acquire();
        $started = microtime(true);
        // Debug echoes INFO lines on the CLI.
        ob_start();

        try {
            $fired = NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => $mgr->runPeriodic($rules, 'live', $slot), budget: 1.0);
        } finally {
            $elapsed = microtime(true) - $started;
            ob_end_clean();
            NfdumpSlots::release();
        }
        $skipped = array_values(array_filter(
            array_column(Debug::recentSince($seq), 'message'),
            static fn (string $line): bool => str_ends_with($line, 'not evaluated: no free nfdump process'),
        ));

        expect($fired)->toBe([])
            ->and($elapsed)->toBeGreaterThan(0.95)
            ->and($elapsed)->toBeLessThan(1.8)
            ->and($skipped)->toHaveCount(2)
            ->and($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([])
            ->and(NfdumpSlots::waiting())->toBe(0)
        ;
    });

    test('a filtered rule still fires when the shared Nfdump instance is reset while the rule waits for its slot', function (): void {
        Config::$settings = alertCannedSettings($this, 1);
        putenv('NFDUMP_STUB_STDOUT=[{"in_packets":2,"in_bytes":100},{"in_packets":3,"in_bytes":200}]');
        $slot = intdiv(time(), 300) * 300 - 300;
        alertCaptureFile('gw1', $slot);
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));
        $rule = makeRule(['nfdumpFilter' => 'proto tcp', 'thresholdValue' => 250.0]);
        $fired = null;
        $waiting = null;

        Coroutine::run(static function () use ($mgr, $rule, $slot, &$fired, &$waiting): void {
            NfdumpSlots::acquire();
            Coroutine::create(static function () use (&$waiting): void {
                Coroutine::usleep(100_000);
                $waiting = NfdumpSlots::waiting(NfdumpSlots::BACKGROUND);
                // What another caller of the shared instance does between its own runs.
                Nfdump::getInstance()->reset();
                Nfdump::getInstance()->setOption('-o', 'csv');
                NfdumpSlots::release();
            });
            $fired = $mgr->runPeriodic([$rule], 'live', $slot);
        });

        expect($waiting)->toBe(1)
            ->and($fired)->toBe(['Test rule'])
            ->and(alertSlotValues($this->db))->toBe([[$slot, 300.0]])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });
});

// ── AlertManager::onFileImported ──────────────────────────────────────────

describe('AlertManager::onFileImported()', function (): void {
    test('the first source\'s file does not evaluate, the last one\'s evaluates its slot once with both values', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];

        expect(alertImport($mgr, $ds, $rules, 'gw1', 300, 600.0))->toBe([])
            ->and($mgr->states())->toBe([])
        ;

        expect(alertImport($mgr, $ds, $rules, 'gw2', 300, 700.0))->toBe(['Test rule'])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(300)
            // The same interval again (a rewritten file): no second evaluation.
            ->and(alertImport($mgr, $ds, $rules, 'gw2', 300, 700.0))->toBe([])
            ->and($mgr->onFileImported($rules, 'live', 300, true))->toBe([])
        ;

        alertImport($mgr, $ds, $rules, 'gw1', 600, 800.0);
        expect($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(300);
        alertImport($mgr, $ds, $rules, 'gw2', 600, 900.0);

        expect(alertSlotValues($this->db))->toBe([[300, 1300.0], [600, 1700.0]]);
    });

    test('the last configured source arriving first waits for the other source', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        alertImport($mgr, $ds, $rules, 'gw1', 300, 100.0);
        alertImport($mgr, $ds, $rules, 'gw2', 300, 1000.0);

        expect(alertImport($mgr, $ds, $rules, 'gw2', 600, 1000.0))->toBe([])
            ->and(alertImport($mgr, $ds, $rules, 'gw1', 600, 200.0))->toBe(['Test rule'])
            ->and(alertSlotValues($this->db))->toBe([[300, 1100.0], [600, 1200.0]])
        ;
    });

    test('without a source, the last source\'s file stands for every source (D17)', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];

        expect($mgr->onFileImported($rules, 'live', 300, false))->toBe([])
            ->and($mgr->onFileImported($rules, 'live', 300, true))->toBe(['Test rule'])
            ->and($mgr->onFileImported($rules, 'live', 300, true))->toBe([])
            ->and(alertSlotValues($this->db))->toBe([[300, 4000.0]])
        ;
    });

    test('with the last source down, a later interval evaluates the skipped slot, then it no longer waits for it', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        alertImport($mgr, $ds, $rules, 'gw2', 300, 500.0);

        // gw2 stops delivering: gw1's 600 file alone does not evaluate 600 ...
        expect(alertImport($mgr, $ds, $rules, 'gw1', 600, 2000.0))->toBe([])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(300)
        ;

        // ... gw1's 900 file evaluates 600 one interval late, and 900 right away, gw2 being down.
        expect(alertImport($mgr, $ds, $rules, 'gw1', 900, 2000.0))->toBe(['Test rule'])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(900)
            ->and(alertImport($mgr, $ds, $rules, 'gw1', 1200, 2000.0))->toBe(['Test rule'])
        ;

        // gw2 comes back: its file is on disk when gw1's lands, so the slot waits for it.
        alertCaptureFile('gw2', 1500);
        expect(alertImport($mgr, $ds, $rules, 'gw1', 1500, 2000.0))->toBe([])
            ->and(alertImport($mgr, $ds, $rules, 'gw2', 1500, 500.0))->toBe(['Test rule'])
            // A late file of an evaluated interval changes nothing.
            ->and(alertImport($mgr, $ds, $rules, 'gw2', 1200, 500.0))->toBe([])
            ->and(alertSlotValues($this->db))->toBe([[300, 2500.0], [600, 2000.0], [900, 2000.0], [1200, 2000.0], [1500, 2500.0]])
        ;
    });

    test('a catch-up that imports one source after the other evaluates each slot once, with every source\'s value of it', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        $base = 1_790_000_100;
        alertImport($mgr, $ds, $rules, 'gw1', $base, 1000.0);
        alertImport($mgr, $ds, $rules, 'gw2', $base, 10.0);
        foreach ([300, 600, 900] as $offset) {
            alertCaptureFile('gw1', $base + $offset);
            alertCaptureFile('gw2', $base + $offset);
        }

        // gw1's whole directory first: gw2's files are on disk, waiting, so nothing is evaluated.
        foreach ([300, 600, 900] as $i => $offset) {
            expect(alertImport($mgr, $ds, $rules, 'gw1', $base + $offset, 1100.0 + $i * 100))->toBe([]);
        }
        expect($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe($base);

        // gw1's store is at +900 by now, yet each slot gets gw1's value of that slot.
        foreach ([300, 600, 900] as $i => $offset) {
            expect(alertImport($mgr, $ds, $rules, 'gw2', $base + $offset, 20.0 + $i * 10))->toBe(['Test rule']);
        }
        expect(alertSlotValues($this->db))->toBe([[$base, 1010.0], [$base + 300, 1120.0], [$base + 600, 1230.0], [$base + 900, 1340.0]]);
    });

    test('a slot a source missed during a catch-up is evaluated in order with the source that has it', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        $base = 1_790_000_100;
        alertImport($mgr, $ds, $rules, 'gw1', $base, 1000.0);
        alertImport($mgr, $ds, $rules, 'gw2', $base, 10.0);
        foreach ([300, 600, 900] as $offset) {
            alertCaptureFile('gw1', $base + $offset);
        }
        alertCaptureFile('gw2', $base + 300);
        alertCaptureFile('gw2', $base + 900);

        foreach ([300, 600, 900] as $i => $offset) {
            alertImport($mgr, $ds, $rules, 'gw1', $base + $offset, 1100.0 + $i * 100);
        }
        alertImport($mgr, $ds, $rules, 'gw2', $base + 300, 20.0);
        alertImport($mgr, $ds, $rules, 'gw2', $base + 900, 40.0);

        expect(alertSlotValues($this->db))->toBe([[$base, 1010.0], [$base + 300, 1120.0], [$base + 600, 1200.0], [$base + 900, 1340.0]]);
    });

    test('a last source returning with a two-hour backlog evaluates no slot twice', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        $base = 1_790_000_100;
        $down = $base + 1800;
        $back = $base + 10_800;

        for ($ts = $base; $ts <= $back; $ts += 300) {
            alertImport($mgr, $ds, $rules, 'gw1', $ts, 2000.0);
            if ($ts < $down) {
                alertImport($mgr, $ds, $rules, 'gw2', $ts, 500.0);
            }
        }
        expect($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe($back);

        // gw2's backlog lands on disk and is imported file by file, while gw1 goes on.
        for ($ts = $down; $ts <= $back; $ts += 300) {
            alertCaptureFile('gw2', $ts);
        }
        for ($ts = $down; $ts <= $back; $ts += 300) {
            alertImport($mgr, $ds, $rules, 'gw2', $ts, 500.0);
            if ($ts === $down + 3000) {
                alertImport($mgr, $ds, $rules, 'gw1', $back + 300, 2000.0);
            }
        }
        alertImport($mgr, $ds, $rules, 'gw2', $back + 300, 500.0);

        $slots = array_column(alertEvents($this->db), 'ts');
        expect($slots)->toBe(range($base, $back + 300, 300))
            ->and(alertSlotValues($this->db)[6])->toBe([$down, 2000.0])
            ->and(end($slots))->toBe($back + 300)
        ;
    });

    test('a timeline that runs ahead of the clock still evaluates each slot once', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0]);
        $ahead = intdiv(time(), 300) * 300 + 7200;

        alertImport($mgr, $ds, [$rule], 'gw1', $ahead, 2000.0);
        expect(alertImport($mgr, $ds, [$rule], 'gw2', $ahead, 500.0))->toBe(['Test rule'])
            ->and(alertImport($mgr, $ds, [$rule], 'gw2', $ahead, 500.0))->toBe([])
            ->and($mgr->onFileImported([$rule], 'live', $ahead, true))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', $ahead))->toBe([])
            ->and($mgr->runPeriodic([$rule], 'live', $ahead - 300))->toBe([])
        ;

        // gw2 silent: the fallback evaluates +300 at gw1's +600 file; gw2's late +300 file changes nothing.
        alertImport($mgr, $ds, [$rule], 'gw1', $ahead + 300, 2000.0);
        alertImport($mgr, $ds, [$rule], 'gw1', $ahead + 600, 2000.0);
        alertImport($mgr, $ds, [$rule], 'gw2', $ahead + 300, 500.0);

        expect(array_column(alertEvents($this->db), 'ts'))->toBe([$ahead, $ahead + 300, $ahead + 600]);
    });

    test('evaluates again when the clock or NFCAPD_TZ moved the timeline back', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        $now = intdiv(time(), 300) * 300;

        alertImport($mgr, $ds, $rules, 'gw1', $now + 7200, 2000.0);
        alertImport($mgr, $ds, $rules, 'gw2', $now + 7200, 500.0);
        unset($ds->rows['gw1'][$now + 7200], $ds->rows['gw2'][$now + 7200]);
        alertImport($mgr, $ds, $rules, 'gw1', $now - 300, 3000.0);

        expect(alertImport($mgr, $ds, $rules, 'gw2', $now - 300, 500.0))->toBe(['Test rule'])
            ->and(alertSlotValues($this->db))->toBe([[$now + 7200, 2500.0], [$now - 300, 3500.0]])
        ;
    });

    test('a source whose store is already past the slot makes the rule unevaluable, not wrong', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        $ds->rows['gw1'][900] = alertBytes(9000.0);

        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        alertImport($mgr, $ds, $rules, 'gw2', 300, 500.0);

        expect($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('forget() during the import-time read discards the stale rule of the same call', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $r1 = makeRule(['id' => 'r1', 'name' => 'R1', 'sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0]);
        $r2 = makeRule(['id' => 'r2', 'name' => 'R2', 'sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0]);
        $rules = [$r1, $r2];
        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        expect(alertImport($mgr, $ds, $rules, 'gw2', 300, 2000.0))->toBe(['R1', 'R2']);
        alertImport($mgr, $ds, $rules, 'gw1', 600, 2000.0);

        $ds->onRead = static fn () => $mgr->forget('r2', $r2->withEnabled(false));

        expect(alertImport($mgr, $ds, $rules, 'gw2', 600, 2000.0))->toBe(['R1'])
            ->and(array_keys($mgr->states()))->toBe(['r1'])
        ;
    });

    test('an import that stored nothing for its slot makes the rule unevaluable, not stale', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        alertImport($mgr, $ds, $rules, 'gw2', 300, 2000.0);

        // gw1's file of 600 was unreadable: the store still ends at 300 when the callback runs.
        expect($mgr->onFileImported($rules, 'live', 600, false, 'gw1'))->toBe([])
            ->and(alertImport($mgr, $ds, $rules, 'gw2', 600, 2000.0))->toBe([])
            ->and($mgr->states()['test-id-001']->lastEvaluatedSlot)->toBe(300)
            ->and(alertSlotValues($this->db))->toBe([[300, 4000.0]])
        ;
    });

    test('a rule none of whose sources delivered the slot is not evaluated on zeros', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw3'], 'operator' => '<'])];

        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        alertImport($mgr, $ds, $rules, 'gw2', 300, 500.0);

        expect($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('after stop() no slot is evaluated, so a stop fires, resolves and notifies nothing', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(['sources' => ['gw1', 'gw2'], 'cooldownSlots' => 0])];
        alertImport($mgr, $ds, $rules, 'gw1', 300, 2000.0);
        $mgr->stop();

        expect(alertImport($mgr, $ds, $rules, 'gw2', 300, 2000.0))->toBe([])
            ->and($mgr->runPeriodic($rules, 'live', 300))->toBe([])
            ->and($mgr->isStopped())->toBeTrue()
            ->and($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('keeps the profiles apart', function (): void {
        $ds = makeDatasource(alertBytes(2000.0));
        $mgr = alertManager($this, $ds);
        $rules = [makeRule(), makeRule(['id' => 'other-id', 'name' => 'Other rule', 'profile' => 'other'])];

        expect($mgr->onFileImported($rules, 'other', 300, false, 'gw1'))->toBe([])
            ->and($mgr->onFileImported($rules, 'live', 300, false, 'gw1'))->toBe([])
            ->and($mgr->onFileImported($rules, 'other', 300, true, 'gw2'))->toBe(['Other rule'])
            ->and($mgr->onFileImported($rules, 'live', 300, true, 'gw2'))->toBe(['Test rule'])
        ;
    });

    test('drops the state of a deleted rule, resolving it when it was firing', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $rule = makeRule();
        $mgr->onFileImported([$rule], 'live', 300, true);
        expect($mgr->firingCount())->toBe(1);

        $mgr->onFileImported([], 'live', 600, true);

        expect($mgr->states())->toBe([])
            ->and($mgr->firingCount())->toBe(0)
            ->and(alertEvents($this->db))->toBe([
                ['ts' => 300, 'kind' => 'fired', 'value' => 2000.0, 'threshold' => 1000.0],
                ['ts' => 600, 'kind' => 'resolved', 'value' => 2000.0, 'threshold' => 1000.0],
            ])
            ->and($this->db->value("SELECT rule_name FROM alert_events WHERE kind = 'resolved'"))->toBe('Test rule')
        ;
    });
});

// ── AlertManager::testRule ────────────────────────────────────────────────

describe('AlertManager::testRule()', function (): void {
    test('a rule that would not fire: records a test event, keeps the state, renders every template', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(500.0)));
        $rule = makeRule(['sources' => ['gw1', 'gw2']]);
        $before = time();

        $result = $mgr->testRule($rule, 'live');

        expect($result)->toMatchArray([
            'fired' => false,
            'evaluated' => true,
            'value' => 500.0,
            'threshold' => 1000.0,
            'condition' => 'bytes > 1,000.00',
            'reason' => '',
            'notified' => false,
            'title' => 'nfsen-ng alert: Test rule',
            'message' => 'bytes = 500.00 (profile: live, sources: gw1, gw2)',
            'subject' => '[nfsen-ng] Alert: Test rule',
        ])
            ->and(array_keys($result))->toBe(['fired', 'evaluated', 'value', 'threshold', 'condition', 'reason', 'slot', 'notified', 'title', 'message', 'subject', 'body'])
            ->and($result['body'])->toContain("Alert rule \"Test rule\" fired.\n")->toContain('Value:   500.00')
            ->and($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toHaveCount(1)
            ->and(alertEvents($this->db)[0])->toMatchArray(['kind' => 'test', 'value' => 500.0, 'threshold' => 1000.0])
            ->and(alertEvents($this->db)[0]['ts'])->toBeGreaterThanOrEqual($before)
        ;
    });

    test('a firing rule uses the rule and global templates and never touches the state', function (): void {
        Config::$settings = Config::$settings->withDefaultEmailSubjectTemplate('Global subject {value}');
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $mgr->runPeriodic([makeRule(['cooldownSlots' => 5])], 'live', 300);
        $before = $mgr->states();
        $rule = makeRule(['webhookTitleTemplate' => 'Custom {rule} {condition}']);

        $result = $mgr->testRule($rule, 'live');

        expect($result['fired'])->toBeTrue()
            ->and($result['evaluated'])->toBeTrue()
            ->and($result['notified'])->toBeFalse()
            ->and($result['title'])->toBe('Custom Test rule bytes > 1,000.00')
            ->and($result['subject'])->toBe('Global subject 2,000.00')
            ->and($mgr->states())->toEqual($before)
            ->and(array_column(alertEvents($this->db), 'kind'))->toBe(['fired', 'test'])
        ;
    });

    test('notified is true once a webhook is sent', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));

        $result = $mgr->testRule(makeRule(['notifyWebhook' => 'http://127.0.0.1:1/hook']), 'live');

        expect($result['fired'])->toBeTrue()
            ->and($result['notified'])->toBeTrue()
        ;
    });

    test('could not evaluate: no event, but the templates are still rendered', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0), alertBytes(0.0)));
        $rule = makeRule(['thresholdType' => 'percent_of_avg', 'thresholdValue' => 150.0]);

        $result = $mgr->testRule($rule, 'live');

        expect($result)->toMatchArray([
            'fired' => false,
            'evaluated' => false,
            'value' => 2000.0,
            'threshold' => null,
            'condition' => 'bytes > ∞',
            'reason' => 'no baseline yet for the 1h average',
            'notified' => false,
            'title' => 'nfsen-ng alert: Test rule',
        ])
            ->and($result['message'])->toBe('bytes = 2,000.00 (profile: live, sources: gw1)')
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('a filtered rule is tested on the newest slot every source has a capture file for', function (): void {
        $args = $this->dir . '/nfdump-args';
        putenv('NFDUMP_STUB_STDOUT=[{"in_packets":2,"in_bytes":100},{"in_packets":3,"in_bytes":200}]');
        putenv('NFDUMP_STUB_ARGS=' . $args);
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => dirname(__DIR__) . '/Support/bin/nfdump-canned', 'profiles-data' => $this->dir . '/profiles', 'profile' => 'live', 'max-processes' => 2],
            'log' => ['priority' => LOG_ERR],
        ]);
        // gw1 has rotated into the current interval, gw2 not yet.
        $slot = intdiv(time(), 300) * 300 - 600;
        alertCaptureFile('gw1', $slot);
        alertCaptureFile('gw2', $slot);
        alertCaptureFile('gw1', $slot + 300);
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));

        try {
            $result = $mgr->testRule(makeRule(['sources' => ['gw1', 'gw2'], 'nfdumpFilter' => 'proto tcp', 'thresholdValue' => 100.0]), 'live');
            $argv = (string) file_get_contents($args);
        } finally {
            putenv('NFDUMP_STUB_STDOUT');
            putenv('NFDUMP_STUB_ARGS');
        }

        $name = (new DateTimeImmutable('@' . $slot))->setTimezone(Config::nfcapdTimezone())->format('YmdHi');
        expect($result)->toMatchArray(['evaluated' => true, 'fired' => true, 'value' => 300.0, 'reason' => ''])
            ->and($argv)->toContain('/live/gw1:gw2')
            ->and($argv)->toContain('nfcapd.' . $name)
        ;
    });

    test('a source that has been down for an hour does not hold the tested slot back', function (): void {
        $args = $this->dir . '/nfdump-args';
        putenv('NFDUMP_STUB_STDOUT=[{"in_packets":2,"in_bytes":100}]');
        putenv('NFDUMP_STUB_ARGS=' . $args);
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => dirname(__DIR__) . '/Support/bin/nfdump-canned', 'profiles-data' => $this->dir . '/profiles', 'profile' => 'live', 'max-processes' => 2],
            'log' => ['priority' => LOG_ERR],
        ]);
        $slot = intdiv(time(), 300) * 300 - 300;
        alertCaptureFile('gw1', $slot - 300);
        alertCaptureFile('gw1', $slot);
        alertCaptureFile('gw2', $slot - 3600);
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));

        try {
            $result = $mgr->testRule(makeRule(['sources' => ['gw1', 'gw2'], 'nfdumpFilter' => 'proto tcp', 'thresholdValue' => 10.0]), 'live');
            $argv = (string) file_get_contents($args);
        } finally {
            putenv('NFDUMP_STUB_STDOUT');
            putenv('NFDUMP_STUB_ARGS');
        }

        $name = (new DateTimeImmutable('@' . $slot))->setTimezone(Config::nfcapdTimezone())->format('YmdHi');
        expect($result)->toMatchArray(['evaluated' => true, 'fired' => true, 'value' => 100.0, 'slot' => $slot])
            ->and($result['body'])->toContain('Time:    ' . gmdate('Y-m-d H:i:s', $slot) . ' UTC')
            ->and($argv)->toContain('nfcapd.' . $name)
            ->and($argv)->not->toContain('/live/gw1:gw2')
        ;
    });

    test('a rule without a filter is tested on the sources the live evaluation uses, not a down source\'s stale row', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $rule = makeRule(['sources' => ['gw1', 'gw2'], 'thresholdValue' => 2200.0]);
        $t = intdiv(time(), 300) * 300 - 300;
        $ds->rows = ['gw1' => [$t - 300 => alertBytes(2000.0), $t => alertBytes(2000.0)], 'gw2' => [$t - 7200 => alertBytes(500.0)]];

        $result = $mgr->testRule($rule, 'live');

        expect($result)->toMatchArray(['evaluated' => true, 'fired' => false, 'value' => 2000.0, 'slot' => $t, 'reason' => ''])
            ->and($mgr->fetchCurrentSlot($rule, 'live')['bytes'])->toBe(2000.0)
        ;
    });

    test('a rule without a filter is not tested while a source already holds the next interval', function (): void {
        $ds = makeDatasource(alertBytes(0.0));
        $mgr = alertManager($this, $ds);
        $t = intdiv(time(), 300) * 300 - 300;
        $ds->rows = ['gw1' => [$t + 300 => alertBytes(2000.0)], 'gw2' => [$t => alertBytes(500.0)]];

        $result = $mgr->testRule(makeRule(['sources' => ['gw1', 'gw2']]), 'live');

        expect($result)->toMatchArray(['evaluated' => false, 'fired' => false, 'slot' => $t, 'reason' => 'the stored traffic of gw1 is already past this interval'])
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('a filtered rule without a capture file for the slot cannot be evaluated', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));

        $result = $mgr->testRule(makeRule(['nfdumpFilter' => 'proto icmp']), 'live');

        expect($result['evaluated'])->toBeFalse()
            ->and($result['reason'])->toStartWith('no capture file nfcapd.')
            ->and(alertEvents($this->db))->toBe([])
        ;
    });
});

// ── AlertManager::forget, states, firingCount ─────────────────────────────

describe('AlertManager::forget()', function (): void {
    test('clears a firing rule and records resolved with the last value', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $mgr->runPeriodic([makeRule()], 'live', 300);

        $mgr->forget('test-id-001');

        expect($mgr->states())->toBe([])
            ->and($mgr->firingCount())->toBe(0)
            ->and(alertEvents($this->db)[1])->toMatchArray(['kind' => 'resolved', 'value' => 2000.0, 'threshold' => 1000.0])
            ->and(alertManager($this, makeDatasource(alertBytes(0.0)))->states())->toBe([])
        ;
    });

    test('the resolved event goes right after the last evaluated slot, so the history keeps its order', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $rule = makeRule();
        $mgr->runPeriodic([$rule], 'live', 300);
        $mgr->runPeriodic([$rule], 'live', 600);

        $mgr->forget('test-id-001', $rule->withEnabled(false));
        $mgr->runPeriodic([$rule], 'live', 900);

        expect(array_map(static fn (array $event): array => [$event['kind'], $event['ts']], $mgr->recentEvents()))
            ->toBe([['fired', 900], ['resolved', 900], ['fired', 300]])
        ;
    });

    test('names the resolved event after the given rule when there is one', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $mgr->runPeriodic([makeRule()], 'live', 300);

        $mgr->forget('test-id-001', makeRule(['name' => 'Renamed', 'enabled' => false]));

        expect($this->db->value("SELECT rule_name FROM alert_events WHERE kind = 'resolved'"))->toBe('Renamed');
    });

    test('clears a quiet rule without an event, and ignores an unknown id', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(10.0)));
        $mgr->runPeriodic([makeRule()], 'live', 300);

        $mgr->forget('test-id-001');
        $mgr->forget('no-such-rule');

        expect($mgr->states())->toBe([])
            ->and(alertEvents($this->db))->toBe([])
        ;
    });

    test('states() hands out copies', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(2000.0)));
        $mgr->runPeriodic([makeRule()], 'live', 300);

        $mgr->states()['test-id-001']->firing = false;

        expect($mgr->firingCount())->toBe(1);
    });
});

// ── History store availability ────────────────────────────────────────────

describe('AlertManager history store', function (): void {
    test('opens Database::shared() lazily when no repository is given', function (): void {
        $shared = Database::open(':memory:');
        Database::useShared($shared);
        $mgr = new AlertManager(makeDatasource(alertBytes(2000.0)), $this->dir . '/state.json', $this->dir . '/log.json', '');

        $mgr->runPeriodic([makeRule()], 'live', 300);

        expect($mgr->eventsAvailable())->toBeTrue()
            ->and($mgr->eventsError())->toBe('')
            ->and(alertEvents($shared))->toHaveCount(1)
        ;
    });

    test('an unavailable store: the constructor does not throw and rules still evaluate', function (): void {
        Database::resetShared();
        touch($this->dir . '/blocker');
        Config::$stateDir = $this->dir . '/blocker/state';

        $mgr = new AlertManager(makeDatasource(alertBytes(2000.0)), $this->dir . '/state.json', $this->dir . '/log.json', '');

        expect($mgr->eventsAvailable())->toBeFalse()
            ->and($mgr->eventsError())->toContain('cannot be created')
            ->and($mgr->runPeriodic([makeRule()], 'live', 300))->toBe(['Test rule'])
            ->and($mgr->firingCount())->toBe(1)
            ->and($mgr->getRecentLog())->toBe([])
            ->and($mgr->recentEvents())->toBe([])
            ->and($mgr->lastFired())->toBe([])
            ->and($mgr->testRule(makeRule(), 'live')['evaluated'])->toBeTrue()
            ->and($mgr->migrateLegacyLog())->toBe(0)
        ;
    });

    test('a state file that cannot be written does not stop evaluation', function (): void {
        $mgr = new AlertManager(makeDatasource(alertBytes(2000.0)), $this->dir . '/missing/state.json', $this->dir . '/log.json', '', $this->events);

        expect($mgr->runPeriodic([makeRule()], 'live', 300))->toBe(['Test rule'])
            ->and($mgr->runPeriodic([makeRule()], 'live', 600))->toBe([])
            ->and(file_exists($this->dir . '/missing'))->toBeFalse()
        ;
    });
});

// ── AlertManager::migrateLegacyLog ────────────────────────────────────────

describe('AlertManager::migrateLegacyLog()', function (): void {
    beforeEach(function (): void {
        // The old format: newest first.
        file_put_contents($this->dir . '/alerts-log.json', json_encode([
            ['ts' => 400, 'rule' => 'Unknown', 'metric' => 'flows', 'value' => 4.5, 'profile' => 'live', 'sources' => ['gw1']],
            ['ts' => 300, 'rule' => 'Twice', 'metric' => 'bytes', 'value' => 3, 'profile' => 'live', 'sources' => []],
            ['ts' => 200, 'rule' => 'Once', 'metric' => 'packets', 'value' => 2.0, 'profile' => 'test', 'sources' => ['gw1', 'gw2']],
            'not an entry',
        ]));
        $this->rules = [
            makeRule(['id' => 'once', 'name' => 'Once']),
            makeRule(['id' => 'twice-a', 'name' => 'Twice']),
            makeRule(['id' => 'twice-b', 'name' => 'Twice']),
        ];
    });

    test('imports every entry oldest first, sets the meta key and renames the file', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));

        expect($mgr->migrateLegacyLog($this->rules))->toBe(3)
            ->and($this->db->all('SELECT ts, kind, rule_id, rule_name, profile, sources, metric, operator, value, threshold, origin FROM alert_events ORDER BY id'))->toBe([
                ['ts' => 200, 'kind' => 'fired', 'rule_id' => 'once', 'rule_name' => 'Once', 'profile' => 'test', 'sources' => '["gw1","gw2"]', 'metric' => 'packets', 'operator' => '', 'value' => 2.0, 'threshold' => null, 'origin' => 'migrated'],
                ['ts' => 300, 'kind' => 'fired', 'rule_id' => null, 'rule_name' => 'Twice', 'profile' => 'live', 'sources' => '[]', 'metric' => 'bytes', 'operator' => '', 'value' => 3.0, 'threshold' => null, 'origin' => 'migrated'],
                ['ts' => 400, 'kind' => 'fired', 'rule_id' => null, 'rule_name' => 'Unknown', 'profile' => 'live', 'sources' => '["gw1"]', 'metric' => 'flows', 'operator' => '', 'value' => 4.5, 'threshold' => null, 'origin' => 'migrated'],
            ])
            ->and($this->db->metaGet(AlertEventRepository::LEGACY_META_KEY))->toBe('1')
            ->and(file_exists($this->dir . '/alerts-log.json'))->toBeFalse()
            ->and(file_exists($this->dir . '/alerts-log.json.migrated'))->toBeTrue()
            ->and($mgr->getRecentLog(1)[0]['rule'])->toBe('Unknown')
            ->and($mgr->lastFired())->toBe(['once' => 200])
        ;
    });

    test('runs once: a second call and a restored file import nothing', function (): void {
        $mgr = alertManager($this, makeDatasource(alertBytes(0.0)));
        $mgr->migrateLegacyLog($this->rules);
        copy($this->dir . '/alerts-log.json.migrated', $this->dir . '/alerts-log.json');

        expect($mgr->migrateLegacyLog($this->rules))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM alert_events'))->toBe(3)
        ;
    });

    test('reads the rules from the settings by default', function (): void {
        Config::$settings = Config::$settings->withAlerts($this->rules);

        alertManager($this, makeDatasource(alertBytes(0.0)))->migrateLegacyLog();

        expect($this->db->value("SELECT rule_id FROM alert_events WHERE rule_name = 'Once'"))->toBe('once');
    });

    test('does nothing without a log file', function (): void {
        unlink($this->dir . '/alerts-log.json');

        expect(alertManager($this, makeDatasource(alertBytes(0.0)))->migrateLegacyLog($this->rules))->toBe(0)
            ->and($this->db->metaGet(AlertEventRepository::LEGACY_META_KEY))->toBeNull()
        ;
    });

    test('a failure inside the transaction leaves no rows and no key, so the retry does not duplicate', function (): void {
        $file = $this->dir . '/store.sqlite';
        $db = Database::open($file);
        $db->exec("CREATE TRIGGER alert_boom BEFORE INSERT ON alert_events WHEN NEW.rule_name = 'Unknown' BEGIN SELECT RAISE(ABORT, 'disk full'); END");
        $mgr = new AlertManager(makeDatasource(alertBytes(0.0)), $this->dir . '/alerts-state.json', $this->dir . '/alerts-log.json', '', new AlertEventRepository($db));

        expect(fn () => $mgr->migrateLegacyLog($this->rules))->toThrow(PDOException::class, 'disk full');
        expect($db->value('SELECT COUNT(*) FROM alert_events'))->toBe(0)
            ->and($db->metaGet(AlertEventRepository::LEGACY_META_KEY))->toBeNull()
            ->and(file_exists($this->dir . '/alerts-log.json'))->toBeTrue()
        ;

        // The next start retries with a fresh connection.
        $db->exec('DROP TRIGGER alert_boom');
        $restarted = Database::open($file);
        $mgr = new AlertManager(makeDatasource(alertBytes(0.0)), $this->dir . '/alerts-state.json', $this->dir . '/alerts-log.json', '', new AlertEventRepository($restarted));

        expect($mgr->migrateLegacyLog($this->rules))->toBe(3)
            ->and($restarted->value('SELECT COUNT(*) FROM alert_events'))->toBe(3)
            ->and($restarted->metaGet(AlertEventRepository::LEGACY_META_KEY))->toBe('1')
            ->and(file_exists($this->dir . '/alerts-log.json.migrated'))->toBeTrue()
            ->and($mgr->migrateLegacyLog($this->rules))->toBe(0)
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
            'nfdumpFilter' => 'proto icmp',
            'emailSubjectTemplate' => 'Custom subject {rule}',
            'emailBodyTemplate' => 'Custom body {value}',
            'webhookTitleTemplate' => 'Custom title {rule}',
            'webhookMessageTemplate' => 'Custom message {value}',
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

    test('withEnabled preserves template override fields', function (): void {
        $rule = makeRule([
            'webhookTitleTemplate' => 'Custom title {rule}',
            'emailSubjectTemplate' => 'Custom subject {rule}',
        ]);
        $disabled = $rule->withEnabled(false);

        expect($disabled->webhookTitleTemplate)->toBe('Custom title {rule}')
            ->and($disabled->emailSubjectTemplate)->toBe('Custom subject {rule}')
        ;
    });
});

// ── AlertManager::sumDecodedFlowRecords() ──────────────────────────────────
// Regression coverage for #153 follow-up: nfdump's unaggregated `-o json` schema
// uses `in_packets`/`in_bytes` and has no per-record flow-count field. A prior
// version read `ipkt`/`ibyt`/`fl` (the whitespace-aggregation format's field names),
// so packets/bytes always summed to zero while flows "worked" only by accident
// (its `?? 1` fallback happened to equal one-record-per-flow).

describe('AlertManager::sumDecodedFlowRecords()', function (): void {
    test('sums packets/bytes from real nfdump JSON field names, one flow per record', function (): void {
        $decoded = [
            ['in_packets' => 1, 'in_bytes' => 76],
            ['in_packets' => 2, 'in_bytes' => 152],
        ];

        expect(AlertManager::sumDecodedFlowRecords($decoded))->toBe([
            'flows' => 2.0,
            'packets' => 3.0,
            'bytes' => 228.0,
        ]);
    });

    test('returns zeros for an empty result set', function (): void {
        expect(AlertManager::sumDecodedFlowRecords([]))->toBe([
            'flows' => 0.0,
            'packets' => 0.0,
            'bytes' => 0.0,
        ]);
    });

    test('ignores non-array entries and missing fields default to zero', function (): void {
        $decoded = [
            ['in_packets' => 5], // in_bytes missing
            'not-an-array',
        ];

        expect(AlertManager::sumDecodedFlowRecords($decoded))->toBe([
            'flows' => 1.0,
            'packets' => 5.0,
            'bytes' => 0.0,
        ]);
    });
});

// ── AlertManager::buildTemplateVars() / resolveTemplate() ──────────────────
// Notification template customization (issue #153 follow-up).

describe('AlertManager::buildTemplateVars()', function (): void {
    test('builds all 12 tokens with correct formatting', function (): void {
        $rule = makeRule(['name' => 'My Rule', 'metric' => 'bytes', 'operator' => '>', 'profile' => 'live', 'sources' => ['gw1', 'gw2']]);
        $values = ['flows' => 1.0, 'packets' => 2.0, 'bytes' => 12345.678];
        $ts = 1700000000;

        $vars = AlertManager::buildTemplateVars($rule, $values, 1000.0, $ts);

        expect($vars)->toBe([
            '{rule}' => 'My Rule',
            '{metric}' => 'bytes',
            '{value}' => '12,345.68',
            '{threshold}' => '1,000.00',
            '{operator}' => '>',
            '{condition}' => 'bytes > 1,000.00',
            '{flows}' => '1.00',
            '{packets}' => '2.00',
            '{bytes}' => '12,345.68',
            '{profile}' => 'live',
            '{sources}' => 'gw1, gw2',
            '{time}' => gmdate('Y-m-d H:i:s', $ts),
        ]);
    });

    test('flows/packets/bytes are all populated regardless of the rule metric', function (): void {
        $rule = makeRule(['metric' => 'flows']);
        $vars = AlertManager::buildTemplateVars($rule, ['flows' => 5.0, 'packets' => 10.0, 'bytes' => 999.0], 3.0, 1700000000);

        expect($vars['{flows}'])->toBe('5.00')
            ->and($vars['{packets}'])->toBe('10.00')
            ->and($vars['{bytes}'])->toBe('999.00')
        ;
    });

    test('a rule without selected sources names every configured source', function (): void {
        $vars = AlertManager::buildTemplateVars(makeRule(['sources' => []]), ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0], 1.0, 1700000000);

        expect($vars['{sources}'])->toBe('gw1, gw2');
    });

    test('threshold shows infinity symbol for the cold-start sentinel', function (): void {
        $rule = makeRule();
        $vars = AlertManager::buildTemplateVars($rule, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0], PHP_FLOAT_MAX, 1700000000);

        expect($vars['{threshold}'])->toBe('∞');
    });
});

describe('AlertManager::resolveTemplate()', function (): void {
    test('rule override wins when non-empty', function (): void {
        expect(AlertManager::resolveTemplate('rule template', 'global template', 'builtin'))->toBe('rule template');
    });

    test('falls to global default when rule template is null', function (): void {
        expect(AlertManager::resolveTemplate(null, 'global template', 'builtin'))->toBe('global template');
    });

    test('falls to global default when rule template is empty string', function (): void {
        expect(AlertManager::resolveTemplate('', 'global template', 'builtin'))->toBe('global template');
    });

    test('falls to built-in default when both rule and global are unset', function (): void {
        expect(AlertManager::resolveTemplate(null, '', 'builtin'))->toBe('builtin');
    });
});

describe('Notification template backward compatibility', function (): void {
    test('default templates reproduce the exact legacy hardcoded output', function (): void {
        $rule = makeRule(['name' => 'My Rule', 'metric' => 'bytes', 'profile' => 'live', 'sources' => ['gw1', 'gw2']]);
        $values = ['flows' => 1.0, 'packets' => 2.0, 'bytes' => 12345.678];
        $ts = 1700000000;
        $vars = AlertManager::buildTemplateVars($rule, $values, 1000.0, $ts);

        $subject = strtr(AlertManager::resolveTemplate($rule->emailSubjectTemplate, '', AlertManager::DEFAULT_EMAIL_SUBJECT), $vars);
        $body = strtr(AlertManager::resolveTemplate($rule->emailBodyTemplate, '', AlertManager::DEFAULT_EMAIL_BODY), $vars);
        $title = strtr(AlertManager::resolveTemplate($rule->webhookTitleTemplate, '', AlertManager::DEFAULT_WEBHOOK_TITLE), $vars);
        $message = strtr(AlertManager::resolveTemplate($rule->webhookMessageTemplate, '', AlertManager::DEFAULT_WEBHOOK_MESSAGE), $vars);

        expect($subject)->toBe('[nfsen-ng] Alert: My Rule')
            ->and($body)->toBe("Alert rule \"My Rule\" fired.\n\nMetric:  bytes\nValue:   12,345.68\nProfile: live\nSources: gw1, gw2\nTime:    " . gmdate('Y-m-d H:i:s', $ts) . " UTC\n")
            ->and($title)->toBe('nfsen-ng alert: My Rule')
            ->and($message)->toBe('bytes = 12,345.68 (profile: live, sources: gw1, gw2)')
            ->and(AlertManager::renderTemplates($rule, $vars))->toBe(['title' => $title, 'message' => $message, 'subject' => $subject, 'body' => $body])
        ;
    });

    test('custom template substitutes known vars and leaves unknown tokens untouched', function (): void {
        $rule = makeRule(['name' => 'R1', 'metric' => 'flows']);
        $vars = AlertManager::buildTemplateVars($rule, ['flows' => 5.0, 'packets' => 10.0, 'bytes' => 999.0], 3.0, 1700000000);

        $out = strtr('{rule} fired: {flows}f/{packets}p/{bytes}b thr={threshold} unknown={bogus}', $vars);

        expect($out)->toBe('R1 fired: 5.00f/10.00p/999.00b thr=3.00 unknown={bogus}');
    });
});

// ── AlertState ────────────────────────────────────────────────────────────

describe('AlertState', function (): void {
    test('initial() returns zero state', function (): void {
        $state = AlertState::initial();
        expect($state->cooldownRemaining)->toBe(0)
            ->and($state->lastTriggeredAt)->toBe(0)
            ->and($state->recentTriggers)->toBe([])
            ->and($state->firing)->toBeFalse()
            ->and($state->firedAt)->toBeNull()
            ->and($state->lastValue)->toBeNull()
            ->and($state->lastEvaluatedSlot)->toBeNull()
        ;
    });

    test('fromArray → toArray roundtrip', function (): void {
        $data = [
            'cooldownRemaining' => 2,
            'lastTriggeredAt' => 1700000000,
            'recentTriggers' => [1700000000],
            'firing' => true,
            'firedAt' => 1699999700,
            'lastValue' => 12.5,
            'lastEvaluatedSlot' => 1700000000,
        ];
        $state = AlertState::fromArray($data);
        expect($state->toArray())->toBe($data);
    });

    test('fromArray fills the new fields of an old state file with defaults', function (): void {
        $state = AlertState::fromArray(['cooldownRemaining' => 1, 'lastTriggeredAt' => 5, 'recentTriggers' => [5]]);

        expect($state->toArray())->toBe([
            'cooldownRemaining' => 1,
            'lastTriggeredAt' => 5,
            'recentTriggers' => [5],
            'firing' => false,
            'firedAt' => null,
            'lastValue' => null,
            'lastEvaluatedSlot' => null,
        ]);
    });
});
