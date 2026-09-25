<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\QueryRunner;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;

/** A processor that answers every run with the given result. */
function queryRunnerTestProcessor(array $result): Processor {
    return new class($result) implements Processor {
        public function __construct(private readonly array $result) {}

        public function setOption(string $option, $value): void {}

        public function setFilter(string $filter): void {}

        public function setQueryHandle(string $handle): void {}

        public function setProfile(string $profile): void {}

        public function execute(): array {
            return $this->result;
        }
    };
}

/** @return list<array<string, mixed>> */
function queryRunnerTestRuns(): array {
    return Database::shared()->all('SELECT kind, bytes, files, elapsed_ms, ok FROM query_runs ORDER BY id');
}

describe('QueryRunner::formatBytes', function (): void {
    test('leaves sub-kilobyte counts in bytes', function (): void {
        expect(QueryRunner::formatBytes(0))->toBe('0 B')
            ->and(QueryRunner::formatBytes(1023))->toBe('1023 B')
        ;
    });

    test('switches to binary units at 1024', function (): void {
        expect(QueryRunner::formatBytes(1024))->toBe('1 KiB')
            ->and(QueryRunner::formatBytes(1536))->toBe('1.5 KiB')
        ;
    });

    test('scales through mebi, gibi and tebi', function (): void {
        expect(QueryRunner::formatBytes(5 * 1024 ** 2))->toBe('5 MiB')
            ->and(QueryRunner::formatBytes(3 * 1024 ** 3))->toBe('3 GiB')
            ->and(QueryRunner::formatBytes(2 * 1024 ** 4))->toBe('2 TiB')
        ;
    });

    // One decimal is noise once the number is large enough to read at a glance.
    test('drops the decimal above ten units', function (): void {
        expect(QueryRunner::formatBytes((int) (42.7 * 1024 ** 2)))->toBe('43 MiB');
    });

    test('saturates at the largest unit rather than inventing one', function (): void {
        expect(QueryRunner::formatBytes(5000 * 1024 ** 4))->toEndWith(' TiB');
    });
});

describe('QueryRunner::finish', function (): void {
    beforeEach(function (): void {
        $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
        Database::useShared(Database::open(':memory:'));
        Debug::drainBuffer();
        // Failures are logged, and Debug echoes on the CLI.
        ob_start();
    });

    afterEach(function (): void {
        ob_end_clean();
        Database::resetShared();
        Config::$stateDir = $this->stateDirBefore ?? '';
    });

    test('records a successful run and reports the total time', function (): void {
        $status = QueryRunner::finish('flows', 64 * 1024 ** 2, 1.2345, 1.46, null, false);

        expect($status)->toBe('Done in 1.5s.')
            ->and(queryRunnerTestRuns())->toBe([['kind' => 'flows', 'bytes' => 64 * 1024 ** 2, 'files' => 0, 'elapsed_ms' => 1235, 'ok' => 1]])
        ;
    });

    test('records a failed run as not ok and reports the error', function (): void {
        $status = QueryRunner::finish('stats', 1000, 0.1, 0.2, new NfdumpException('Filter syntax error: bad', 'nfdump', '', 254), false);

        expect($status)->toBe('Failed: Filter syntax error: bad')
            ->and(queryRunnerTestRuns())->toBe([['kind' => 'stats', 'bytes' => 1000, 'files' => 0, 'elapsed_ms' => 100, 'ok' => 0]])
        ;
    });

    test('does not record a run the tab cancelled, even one that returned rows', function (): void {
        $status = QueryRunner::finish('flows', 1000, 0.1, 0.2, null, true);

        expect($status)->toBe(QueryRunner::CANCELLED_STATUS)
            ->and($status)->toBe('Query cancelled.')
            ->and(queryRunnerTestRuns())->toBe([])
        ;
    });

    test('reads a run that nfdump stopped on a signal as cancelled, not failed', function (int $exitCode): void {
        $stopped = new NfdumpException('nfdump was stopped (signal ' . $exitCode . ')', 'nfdump', '', $exitCode);

        expect(QueryRunner::finish('stats', 1000, 0.1, 0.2, $stopped, false))->toBe('Query cancelled.')
            ->and(queryRunnerTestRuns())->toBe([])
        ;
    })->with([15, 9]);

    test('routes only real failures to the error log', function (): void {
        QueryRunner::finish('flows', 1000, 0.1, 0.2, new NfdumpException('nfdump was stopped (signal 15)', 'nfdump', '', 15), false);
        QueryRunner::finish('flows', 1000, 0.1, 0.2, new RuntimeException('disk gone'), false);

        $errors = array_column(array_filter(Debug::drainBuffer(), static fn (array $e): bool => $e['level'] === LOG_ERR), 'msg');

        expect($errors)->toBe(['Query failed: disk gone']);
    });

    test('a store failure is logged and never fails the query', function (): void {
        $db = Database::open(':memory:');
        $db->exec('DROP TABLE query_runs');
        Database::useShared($db);

        $status = QueryRunner::finish('flows', 1000, 0.1, 0.2, null, false);

        expect($status)->toBe('Done in 0.2s.')
            ->and(array_filter(Debug::drainBuffer(), static fn (array $e): bool => $e['level'] === LOG_WARNING && str_starts_with($e['msg'], 'Query run not recorded: ')))->toHaveCount(1)
        ;
    });

    test('an unavailable store is logged and never fails the query', function (): void {
        Database::resetShared();
        Config::$stateDir = '';

        expect(QueryRunner::finish('flows', 1000, 0.1, 0.2, null, false))->toBe('Done in 0.2s.')
            ->and(array_column(Debug::drainBuffer(), 'msg'))->toContain('Query run not recorded: the state directory is not configured yet')
        ;
    });

    test('Flows runs that stopped at their record limit measure the read rate, not the window over the time', function (): void {
        // A 50 GiB window where nfdump hit -c after 256 MiB, sampled at 0.25 s of a 0.3 s run.
        foreach ([0.30, 0.31, 0.29] as $workSeconds) {
            $read = QueryRunner::recordedRead('flows', 50 * 1024 ** 3, $workSeconds, ['bytes' => 256 * 1024 ** 2, 'seconds' => 0.25]);
            QueryRunner::finish('flows', $read['bytes'], $read['seconds'], $workSeconds, null, false);
        }

        expect(new QueryRunRepository(Database::shared())->medianThroughput('flows'))->toBe((float) 1024 ** 3);
    });

    test('Flows runs without a read sample never count as measured', function (): void {
        foreach ([0.30, 0.31, 0.29] as $workSeconds) {
            $read = QueryRunner::recordedRead('flows', 50 * 1024 ** 3, $workSeconds, null);
            QueryRunner::finish('flows', $read['bytes'], $read['seconds'], $workSeconds, null, false);
        }

        expect(array_column(queryRunnerTestRuns(), 'bytes'))->toBe([0, 0, 0])
            ->and(new QueryRunRepository(Database::shared())->medianThroughput('flows'))->toBeNull()
        ;
    });
});

describe('QueryRunner::recordedRead', function (): void {
    test('a kind that reads its whole window records the window size over the work time', function (string $kind): void {
        expect(QueryRunner::recordedRead($kind, 1000, 1.5, ['bytes' => 10, 'seconds' => 0.5]))->toBe(['bytes' => 1000, 'seconds' => 1.5])
            ->and(QueryRunner::recordedRead($kind, 1000, 1.5, null))->toBe(['bytes' => 1000, 'seconds' => 1.5])
        ;
    })->with(['stats', 'flows-summary', 'conversations']);

    test('Flows records what nfdump had read at the last sample, and when', function (): void {
        expect(QueryRunner::EARLY_STOP_KINDS)->toBe(['flows'])
            ->and(QueryRunner::recordedRead('flows', 50 * 1024 ** 3, 0.31, ['bytes' => 256 * 1024 ** 2, 'seconds' => 0.25]))
            ->toBe(['bytes' => 256 * 1024 ** 2, 'seconds' => 0.25])
        ;
    });

    test('Flows caps the read position at the window size', function (): void {
        expect(QueryRunner::recordedRead('flows', 1000, 1.0, ['bytes' => 1500, 'seconds' => 0.75]))->toBe(['bytes' => 1000, 'seconds' => 0.75]);
    });

    test('Flows without a sample records no bytes', function (): void {
        expect(QueryRunner::recordedRead('flows', 50 * 1024 ** 3, 0.3, null))->toBe(['bytes' => 0, 'seconds' => 0.3]);
    });
});

describe('QueryRunner::wasCancelled', function (): void {
    test('is true for a Kill request or a signal stop only', function (): void {
        expect(QueryRunner::wasCancelled(null, true))->toBeTrue()
            ->and(QueryRunner::wasCancelled(new RuntimeException('x'), true))->toBeTrue()
            ->and(QueryRunner::wasCancelled(new NfdumpException('stopped', 'nfdump', '', 15), false))->toBeTrue()
            ->and(QueryRunner::wasCancelled(new NfdumpException('failed', 'nfdump', '', 254), false))->toBeFalse()
            ->and(QueryRunner::wasCancelled(new RuntimeException('x', 15), false))->toBeFalse()
            ->and(QueryRunner::wasCancelled(null, false))->toBeFalse()
        ;
    });
});

describe('query results carry the processor notes and exit code', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        Config::$settings = Settings::fromArray(mockSettings());
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    test('for every query that runs through a processor', function (callable $query): void {
        $processor = queryRunnerTestProcessor([
            'command' => 'nfdump -R x',
            'rawOutput' => '',
            'decoded' => [['val' => '10.0.0.1']],
            'notes' => ['nfdump exited with code 250', 'Execution time: 0.1 seconds'],
            'exitCode' => 250,
        ]);

        $result = $query()->run($processor);

        expect($result->notes)->toBe(['nfdump exited with code 250', 'Execution time: 0.1 seconds'])
            ->and($result->exitCode)->toBe(250)
            ->and($result->rows)->toBe([['val' => '10.0.0.1']])
        ;
    })->with([
        'flows' => [static fn (): FlowsQuery => new FlowsQuery(TimeWindow::raw(0, 300), ['gateway'], 'live', 10)],
        'stats' => [static fn (): StatsQuery => new StatsQuery(TimeWindow::raw(0, 300), ['gateway'], 'live', 'srcip', 'bytes', 10)],
        'matrix' => [static fn (): MatrixQuery => new MatrixQuery(TimeWindow::raw(0, 300), ['gateway'], 'live', 'bytes', 10)],
    ]);

    test('default to no notes and exit code 0 when the processor has none', function (): void {
        $result = new FlowsQuery(TimeWindow::raw(0, 300), ['gateway'], 'live', 10)
            ->run(queryRunnerTestProcessor(['command' => 'nfdump', 'rawOutput' => '', 'decoded' => []]))
        ;

        expect($result->notes)->toBe([])
            ->and($result->exitCode)->toBe(0)
        ;
    });
});
