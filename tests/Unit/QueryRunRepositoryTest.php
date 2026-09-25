<?php

declare(strict_types=1);

use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;

const QUERY_RUN_MIB = 1_048_576;

beforeEach(function (): void {
    $this->db = Database::open(':memory:');
    $this->runs = new QueryRunRepository($this->db);
});

describe('QueryRunRepository::record()', function (): void {
    test('stores the run', function (): void {
        $this->runs->record('flows', 64 * QUERY_RUN_MIB, 12, 1500, true, 1_700_000_000);
        $this->runs->record('stats', 10, 1, 5, false, 1_700_000_300);

        expect($this->db->all('SELECT kind, ts, bytes, files, elapsed_ms, ok FROM query_runs ORDER BY id'))->toBe([
            ['kind' => 'flows', 'ts' => 1_700_000_000, 'bytes' => 64 * QUERY_RUN_MIB, 'files' => 12, 'elapsed_ms' => 1500, 'ok' => 1],
            ['kind' => 'stats', 'ts' => 1_700_000_300, 'bytes' => 10, 'files' => 1, 'elapsed_ms' => 5, 'ok' => 0],
        ]);
    });

    test('defaults the timestamp to now and never stores negative counts', function (): void {
        $before = time();
        $this->runs->record('flows', -1, -2, -3, true);

        $row = $this->db->one('SELECT ts, bytes, files, elapsed_ms FROM query_runs');

        expect($row['ts'])->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual(time())
            ->and([$row['bytes'], $row['files'], $row['elapsed_ms']])->toBe([0, 0, 0])
        ;
    });

    test('keeps the newest 200 runs per kind and leaves other kinds alone', function (): void {
        for ($i = 1; $i <= QueryRunRepository::KEEP_PER_KIND + 5; ++$i) {
            $this->runs->record('flows', $i, 0, 1, true, 1_000 + $i);
        }
        $this->runs->record('stats', 1, 0, 1, true, 1);

        expect($this->db->value("SELECT COUNT(*) FROM query_runs WHERE kind = 'flows'"))->toBe(QueryRunRepository::KEEP_PER_KIND)
            ->and($this->db->value("SELECT MIN(bytes) FROM query_runs WHERE kind = 'flows'"))->toBe(6)
            ->and($this->db->value("SELECT MAX(bytes) FROM query_runs WHERE kind = 'flows'"))->toBe(QueryRunRepository::KEEP_PER_KIND + 5)
            ->and($this->db->value("SELECT COUNT(*) FROM query_runs WHERE kind = 'stats'"))->toBe(1)
        ;
    });
});

describe('QueryRunRepository::medianThroughput()', function (): void {
    test('is null below three qualifying runs', function (): void {
        $this->runs->record('flows', 100 * QUERY_RUN_MIB, 0, 1000, true, 1);
        $this->runs->record('flows', 100 * QUERY_RUN_MIB, 0, 1000, true, 2);

        expect($this->runs->medianThroughput('flows'))->toBeNull();

        $this->runs->record('flows', 100 * QUERY_RUN_MIB, 0, 1000, true, 3);

        expect($this->runs->medianThroughput('flows'))->toBe(100.0 * QUERY_RUN_MIB);
    });

    test('ignores failed runs and runs under 32 MiB or 200 ms', function (): void {
        $this->runs->record('flows', 64 * QUERY_RUN_MIB, 0, 1000, true, 1);
        $this->runs->record('flows', 64 * QUERY_RUN_MIB, 0, 1000, true, 2);
        $this->runs->record('flows', 64 * QUERY_RUN_MIB, 0, 1000, false, 3);
        $this->runs->record('flows', QueryRunRepository::MIN_BYTES - 1, 0, 1000, true, 4);
        $this->runs->record('flows', 64 * QUERY_RUN_MIB, 0, QueryRunRepository::MIN_ELAPSED_MS - 1, true, 5);
        $this->runs->record('stats', 64 * QUERY_RUN_MIB, 0, 1000, true, 6);

        expect($this->runs->medianThroughput('flows'))->toBeNull();

        // Exactly on both thresholds counts.
        $this->runs->record('flows', QueryRunRepository::MIN_BYTES, 0, QueryRunRepository::MIN_ELAPSED_MS, true, 7);

        expect($this->runs->medianThroughput('flows'))->toBe(64.0 * QUERY_RUN_MIB);
    });

    test('takes the middle rate of an odd sample and the mean of the two middle rates of an even one', function (): void {
        // 50, 100 and 400 MiB/s
        $this->runs->record('stats', 100 * QUERY_RUN_MIB, 0, 2000, true, 1);
        $this->runs->record('stats', 100 * QUERY_RUN_MIB, 0, 1000, true, 2);
        $this->runs->record('stats', 100 * QUERY_RUN_MIB, 0, 250, true, 3);

        expect($this->runs->medianThroughput('stats'))->toBe(100.0 * QUERY_RUN_MIB);

        // plus 200 MiB/s
        $this->runs->record('stats', 100 * QUERY_RUN_MIB, 0, 500, true, 4);

        expect($this->runs->medianThroughput('stats'))->toBe(150.0 * QUERY_RUN_MIB);
    });

    test('reads only the newest 20 qualifying runs', function (): void {
        // Ten old runs at 400 MiB/s, then ten at 100 and ten at 200: with the old ones the
        // median would be 200.
        for ($i = 1; $i <= 30; ++$i) {
            $this->runs->record('graph', 100 * QUERY_RUN_MIB, 0, match (true) {
                $i <= 10 => 250,
                $i % 2 === 0 => 1000,
                default => 500,
            }, true, $i);
        }

        expect($this->runs->medianThroughput('graph'))->toBe(150.0 * QUERY_RUN_MIB);
    });

    test('searches the kind index', function (): void {
        $plan = implode("\n", array_column($this->db->all(
            'EXPLAIN QUERY PLAN SELECT bytes, elapsed_ms FROM query_runs
             WHERE kind = ? AND ok = 1 AND bytes >= ? AND elapsed_ms >= ?
             ORDER BY ts DESC, id DESC LIMIT ?',
            ['flows', 1, 1, 20],
        ), 'detail'));

        expect($plan)->toContain('USING INDEX query_runs_by_kind (kind=?)');
    });
});
