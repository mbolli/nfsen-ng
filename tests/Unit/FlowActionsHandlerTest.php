<?php

/**
 * Tests for the aggregation string building logic in Nfdump::buildAggregationString(), and
 * for how the query actions store their results and failures in the page states.
 */

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\QueryRunner;
use mbolli\nfsen_ng\actions\ShellActions;
use mbolli\nfsen_ng\actions\UtilityActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\TotalsProvider;
use mbolli\nfsen_ng\pages\FlowsPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use mbolli\nfsen_ng\store\Database;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use starfederation\datastar\enums\ElementPatchMode;

/** @param list<array<string, mixed>> $rows */
function flowActionsTestResult(array $rows = [], string $stderr = ''): QueryResult {
    return new QueryResult(
        rows: $rows,
        command: 'nfdump -M /data/live/gw1 -R 2026/09/25/nfcapd.202609250000:nfcapd.202609251200 -o json -c 20',
        stderr: $stderr,
        elapsed: 0.2,
        window: TimeWindow::raw(1_000, 2_000),
    );
}

describe('Aggregation string building', function (): void {
    test('returns empty string for empty aggregation', function (): void {
        expect(Nfdump::buildAggregationString([]))->toBe('');
    });

    test('bidirectional aggregation returns correct string', function (): void {
        expect(Nfdump::buildAggregationString(['bidirectional' => true]))->toBe('bidirectional');
    });

    test('protocol aggregation adds proto', function (): void {
        expect(Nfdump::buildAggregationString(['proto' => true]))->toContain('proto');
    });

    test('source and destination ports build correctly', function (): void {
        expect(Nfdump::buildAggregationString(['srcport' => true, 'dstport' => true]))->toBe('srcport,dstport');
    });

    test('IP with prefix builds correctly', function (): void {
        $result = Nfdump::buildAggregationString(['srcip' => 'srcip4', 'srcipPrefix' => '24']);
        expect($result)->toBe('srcip4/24');
    });

    test('plain IP without prefix builds correctly', function (): void {
        expect(Nfdump::buildAggregationString(['srcip' => 'srcip']))->toBe('srcip');
    });

    test('combined aggregation builds comma-separated string', function (): void {
        $result = Nfdump::buildAggregationString(['proto' => true, 'srcport' => true, 'srcip' => 'srcip']);
        expect($result)->toBe('proto,srcport,srcip');
    });

    test('none srcip is excluded', function (): void {
        expect(Nfdump::buildAggregationString(['srcip' => 'none', 'proto' => true]))->toBe('proto');
    });
});

describe('Threshold filter building', function (): void {
    test('returns empty string for empty inputs', function (): void {
        expect(Nfdump::buildThresholdFilter('', ''))->toBe('');
    });

    test('lower limit only', function (): void {
        expect(Nfdump::buildThresholdFilter('1024', ''))->toBe('bytes > 1024');
    });

    test('upper limit only', function (): void {
        expect(Nfdump::buildThresholdFilter('', '1M'))->toBe('bytes < 1M');
    });

    test('both limits joined with and', function (): void {
        expect(Nfdump::buildThresholdFilter('100', '9999'))->toBe('bytes > 100 and bytes < 9999');
    });

    test('invalid limit is ignored', function (): void {
        expect(Nfdump::buildThresholdFilter('abc', '500'))->toBe('bytes < 500');
    });
});

/**
 * The page handler against a Via with the real templates, in its fatal-error state so
 * rendering touches no datasource; Flows is the active page.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function flowActionsTestCompose(): array {
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-flows-' . bin2hex(random_bytes(3)), '/', $app);
    $states = new PageStates();

    Shell::signals($c);
    foreach (PageRegistry::MODULES as $module) {
        $module::signals($c);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::signals($c);
    }
    Shell::register($c, $app, $states);
    foreach (PageRegistry::MODULES as $module) {
        $module::register($c, $app, $states);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::register($c, $app, $states);
    }
    $c->getSignal('page')?->setValue('flows', broadcast: false);

    return [$app, $c, $states];
}

function flowActionsTestRender(Context $c, Via $app, PageStates $states, bool $isUpdate = false): string {
    return $c->render('pages/flows.html.twig', Shell::render($c, $app, $states, $isUpdate));
}

/** @return array{start: int, end: int, live: bool, profile: string, sources: list<string>, protocol: string, filter: string, lower: string, upper: string, limit: int, aggregation: array<string, mixed>, orderByStart: bool} */
function flowActionsTestInputs(array $overrides = []): array {
    return [...[
        'start' => 1_000_000,
        'end' => 1_086_400,
        'live' => false,
        'profile' => 'live',
        'sources' => ['gw1'],
        'protocol' => 'any',
        'filter' => 'proto tcp',
        'lower' => '',
        'upper' => '',
        'limit' => 50,
        'aggregation' => ['proto' => false],
        'orderByStart' => false,
    ], ...$overrides];
}

describe('storing a flows run', function (): void {
    test('a finished run stores the table, the row count and the command', function (): void {
        $state = new FlowsState();
        $state->notify('warning', 'nfdump process (PID 3) was killed.');
        FlowActions::storeResult($state, flowActionsTestResult([['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => 42]]), 0.123, '/_action/ip-info-x');

        expect($state->tableHtml)->toContain('id="flowTable"', '10.0.0.1')
            ->and($state->count)->toBe(1)
            ->and($state->resultId)->toMatch('/^[0-9a-f]{8}$/')
            ->and($state->notifications)->toHaveCount(1)
            ->and($state->notifications[0]['message'])->toBe('nfdump: done in 0.123s.')
            ->and($state->notifications[0]['code'])->toStartWith('nfdump -M /data/live/gw1')
        ;
    });

    test('the run keeps what the Raw output and Summary tabs show (4.3.3)', function (): void {
        $state = new FlowsState();
        $rows = [
            ['cnt' => 1, 'first' => '2026-08-29T05:17:53.000', 'last' => '2026-08-29T05:17:54.000', 'in_packets' => 10, 'in_bytes' => 1000, 'src_addr' => '10.0.0.1'],
            ['cnt' => 2, 'first' => '2026-08-29T05:17:53.000', 'last' => '2026-08-29T05:17:55.000', 'in_packets' => 10, 'in_bytes' => 1000, 'src_addr' => '10.0.0.2'],
        ];
        $result = new QueryResult($rows, 'nfdump -M /data/live/gw1 -c 2', '', 0.1, TimeWindow::raw(1_700_000_000, 1_700_086_400), "[\n{\"cnt\": 1}\n]", ['Execution time: 0.1 seconds']);
        $range = ['available' => true, 'reason' => '', 'totals' => ['any' => ['flows' => 5.0, 'packets' => 50.0, 'bytes' => 5000.0]]];

        FlowActions::storeResult($state, $result, 0.1, '', ['limit' => 2, 'fingerprint' => 'f1', 'totalsFingerprint' => 't1', 'live' => true, 'ranAt' => 42, 'rangeSummary' => $range]);

        expect($state->limit)->toBe(2)
            ->and($state->command)->toBe('nfdump -M /data/live/gw1 -c 2')
            ->and($state->notes)->toBe(['Execution time: 0.1 seconds'])
            ->and($state->rawOutput())->toBe("[\n{\"cnt\": 1}\n]")
            ->and([$state->rawChunks, $state->rawKept, $state->rawTruncated()])->toBe([1, 14, false])
            ->and($state->returnedSummary)->toMatchArray(['records' => 2, 'flows' => 2, 'aggregated' => false, 'packets' => 20, 'bytes' => 2000])
            ->and($state->rangeSummary)->toBe($range)
            ->and($state->filteredSummary)->toBeNull()
            ->and([$state->fingerprint, $state->totalsFingerprint, $state->live, $state->ranAt])->toBe(['f1', 't1', true, 42])
            ->and([$state->windowStart, $state->windowEnd])->toBe([1_700_000_000, 1_700_086_400])
            // HIDDEN_FIELDS applies: FlowActions passes no hiddenFields any more.
            ->and($state->tableHtml)->not->toContain('data-original-title="cnt"')
            ->and($state->tableHtml)->toContain('data-page-size="50"', 'data-limit="2"', 'data-caption="Flows"', 'data-export-name="flows-')
            ->and($state->tableHtml)->not->toContain('class="original"')
        ;
    });

    test('the page keeps at most 5 MiB of nfdump output, in pieces cut at line ends', function (): void {
        $state = new FlowsState();
        $line = str_repeat('x', 1023) . "\n";
        $state->setResult('<table></table>', 1, ['rawOutput' => str_repeat($line, 6 * 1024)]);

        expect($state->rawKept)->toBe(FlowsState::RAW_OUTPUT_LIMIT)
            ->and($state->rawChunks)->toBe(FlowsState::RAW_OUTPUT_LIMIT / FlowsState::RAW_CHUNK_BYTES)
            ->and($state->rawOutput())->toEndWith("\n")
            ->and(strlen($state->rawOutput()))->toBe(FlowsState::RAW_OUTPUT_LIMIT)
            ->and($state->rawBytes)->toBe(6 * 1024 * 1024)
            ->and($state->rawTruncated())->toBeTrue()
            ->and($state->rawChunk(0))->toStartWith('<span data-chunk="0">xxx')
            ->and($state->rawChunk(10))->toBeNull()
        ;
    });

    test('a large run keeps its first page in the state and the other rows as chunks in the shared store', function (): void {
        $state = new FlowsState();
        $rows = array_map(static fn (int $i): array => ['src_addr' => '10.0.' . intdiv($i, 250) . '.' . $i % 250, 'in_bytes' => $i], range(1, 2_600));
        FlowActions::storeResult($state, flowActionsTestResult($rows), 0.5, '/_action/ip-info-x', ['limit' => 10_000, 'rowsUrl' => '/_action/flows-rows-x']);

        $send = $state->tableForSend();

        expect($state->rowChunks)->toBe(3)
            ->and($state->hasPayload())->toBeTrue()
            ->and(substr_count($state->tableHtml, '<tr>'))->toBe(51)
            ->and($state->tableHtml)->toContain("data-result=\"{$state->resultId}\"", 'data-chunks="3"', 'data-total="2600"', '/_action/flows-rows-x?result=')
            ->and(substr_count($state->rowChunk(2) ?? '', '<tr>'))->toBe(550)
            ->and($state->rowChunk(3))->toBeNull()
            // The render sends the first chunk inside the table, which saves a round trip.
            ->and($send)->toContain('<template class="table-rows" data-chunk="0">')
            ->and(strpos($send, 'data-chunk="0"'))->toBeLessThan(strrpos($send, '</nfsen-table>'))
            ->and(substr_count($send, '<tr>'))->toBe(1_051)
        ;
    });

    test('the snapshot stays small: the chunks stay in the store, under its budget', function (): void {
        $state = new FlowsState();
        $rows = array_map(static fn (int $i): array => ['src_addr' => '10.1.' . intdiv($i, 250) . '.' . $i % 250, 'in_bytes' => $i], range(1, 5_000));
        FlowActions::storeResult($state, new QueryResult($rows, 'nfdump -M /data', '', 0.1, TimeWindow::raw(0, 300), str_repeat("{\"k\": 1}\n", 50_000)), 0.1, '');

        $snapshot = $state->snapshot();
        $revived = new FlowsState();
        $revived->restore($snapshot);

        expect(strlen(serialize($snapshot)))->toBeLessThan(64 * 1024)
            ->and($revived->tableHtml)->toBe($state->tableHtml)
            ->and($revived->rowChunk(4))->toBe($state->rowChunk(4))
            ->and($revived->rawOutput())->toBe($state->rawOutput())
            ->and(FlowsState::storedBytes())->toBeLessThanOrEqual(FlowsState::PAYLOAD_BUDGET)
        ;
    });

    test('over its budget the store drops the least recently used result first', function (): void {
        FlowsState::makeRoom(static fn (): bool => false);
        // Random bytes do not compress, so four results of a quarter each overrun the budget.
        $quarter = intdiv(FlowsState::PAYLOAD_BUDGET, 4);
        $states = [];
        foreach (range(0, 2) as $i) {
            $states[$i] = new FlowsState();
            $states[$i]->setResult('<table></table>', 1, ['command' => 'nfdump', 'rawOutput' => random_bytes($quarter)]);
        }
        // Reading the first result makes it the most recently used, so the second goes.
        $states[0]->rawChunk(0);
        $fourth = new FlowsState();
        $fourth->setResult('<table></table>', 1, ['command' => 'nfdump', 'rawOutput' => random_bytes($quarter)]);

        expect([$states[0]->hasPayload(), $states[1]->hasPayload(), $states[2]->hasPayload(), $fourth->hasPayload()])->toBe([true, false, true, true])
            ->and(FlowsState::storedBytes())->toBeLessThanOrEqual(FlowsState::PAYLOAD_BUDGET)
            ->and($states[1]->rawOutput())->toBe('')
        ;
    });

    test('a large listing takes the one large-run slot and makes room, or says why it cannot run', function (): void {
        $state = new FlowsState();
        $state->setResult('<table></table>', 1, ['command' => 'nfdump', 'rawOutput' => "line\n"]);
        $roomy = FlowActions::expectedPeak(20_000) + FlowActions::MEMORY_MARGIN;

        expect(FlowActions::claimMemory(FlowActions::LARGE_ROWS))->toBeFalse()
            ->and(FlowActions::claimMemory(10_000, $roomy))->toBeTrue()
            ->and($state->hasPayload())->toBeTrue()
        ;
        FlowActions::releaseMemory();

        expect(static fn () => FlowActions::claimMemory(10_000, memory_get_usage(true) + FlowActions::MEMORY_MARGIN))
            ->toThrow(RuntimeException::class, 'The server has too little memory free for 10,000 rows right now; about ')
            ->and($state->hasPayload())->toBeFalse()
            // No memory_limit: nothing to guard.
            ->and(FlowActions::claimMemory(10_000, 0))->toBeTrue()
        ;
        FlowActions::releaseMemory();
    });

    test('the heap a parse reaches: the text on top of the heap, the records in its free pages first', function (): void {
        $heap = memory_get_usage(true);
        $free = $heap - memory_get_usage();
        $rows = intdiv($free, FlowActions::RECORD_BYTES_PER_ROW) + 1_000;

        // memory_get_usage() moves by a few bytes between the calls.
        expect(abs(FlowActions::expectedPeak(0) - $heap))->toBeLessThan(65_536)
            ->and(abs(FlowActions::expectedPeak($rows) - ($heap + $rows * (FlowActions::TEXT_BYTES_PER_ROW + FlowActions::RECORD_BYTES_PER_ROW) - $free)))->toBeLessThan(65_536)
            ->and(FlowActions::expectedPeak(1) - FlowActions::expectedPeak(0))->toBeGreaterThanOrEqual(FlowActions::TEXT_BYTES_PER_ROW - 1_024)
        ;
    });

    test('settling copies what a result keeps and changes nothing it holds', function (): void {
        $state = new FlowsState();
        $rows = array_map(static fn (int $i): array => ['in_bytes' => $i], range(1, 1_200));
        FlowActions::storeResult($state, new QueryResult($rows, 'nfdump', '', 0.1, TimeWindow::raw(0, 300), "a\nb\n"), 0.1, '');
        $before = [$state->tableHtml, $state->rowChunk(0), $state->rawOutput()];

        $state->settle();

        expect([$state->tableHtml, $state->rowChunk(0), $state->rawOutput()])->toBe($before);
    });

    test('a snapshot restores every part of the result', function (): void {
        $state = new FlowsState();
        FlowActions::storeResult($state, flowActionsTestResult([['in_bytes' => 5]]), 0.2, '', ['limit' => 20, 'fingerprint' => 'f', 'live' => true, 'ranAt' => 7]);
        $state->rowsLost = true;
        $state->setFilteredSummary(['totals' => ['flows' => 1, 'packets' => 2, 'bytes' => 3, 'protocols' => []], 'command' => 'c', 'elapsed' => 0.5, 'fingerprint' => 't', 'ranAt' => 8]);

        $revived = new FlowsState();
        $revived->restore($state->snapshot());

        expect($revived->snapshot())->toBe($state->snapshot())
            ->and($revived->isEmpty())->toBeFalse()
            ->and((new FlowsState())->snapshot())->toBe([])
        ;
    });

    test('a failed run clears the table and shows nfdump\'s message as text with the command apart', function (): void {
        $state = new FlowsState();
        $state->setResult('<table id="flowTable"></table>', 5);
        FlowActions::storeFailure($state, new NfdumpException('Unknown protocol: <b>x</b>', 'nfdump -- proto <b>x</b>', exitCode: 254), false);

        expect($state->tableHtml)->toBe('')
            ->and($state->hasPayload())->toBeFalse()
            ->and($state->count)->toBe(0)
            ->and($state->notifications)->toBe([[
                'id' => $state->notifications[0]['id'],
                'type' => 'error',
                'message' => 'Error: Unknown protocol: <b>x</b>',
                'code' => 'nfdump -- proto <b>x</b>',
            ]])
        ;
    });

    test('a cancelled run keeps the Kill notice instead of a red error', function (): void {
        $state = new FlowsState();
        $state->setResult('<table id="flowTable"></table>', 5);
        $state->notify('warning', 'nfdump process (PID 3) was killed.');
        $stopped = new NfdumpException('nfdump was stopped (signal 15)', 'nfdump -M /data', exitCode: 15);

        FlowActions::storeFailure($state, $stopped, QueryRunner::wasCancelled($stopped, false));

        expect($state->tableHtml)->toBe('')
            ->and(array_column($state->notifications, 'message'))->toBe(['nfdump process (PID 3) was killed.'])
        ;
    });
});

describe('the filtered totals (D13)', function (): void {
    test('a run stores the summed protocol rows with the totals fingerprint', function (): void {
        $state = new FlowsState();
        $csv = "ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,40,53.3,400,76.2,40000,78.0,400,320000,100\n";
        $result = new QueryResult([], 'nfdump -s proto/bytes', '', 0.4, TimeWindow::raw(0, 300), $csv);

        FlowActions::storeSummary($state, $result, 0.4, 'totals-print', 99);

        expect($state->filteredSummary)->toBe([
            'totals' => ['flows' => 40, 'packets' => 400, 'bytes' => 40000, 'protocols' => [['proto' => 'TCP', 'number' => '6', 'flows' => 40, 'packets' => 400, 'bytes' => 40000]]],
            'command' => 'nfdump -s proto/bytes',
            'elapsed' => 0.4,
            'fingerprint' => 'totals-print',
            'ranAt' => 99,
        ]);
    });

    test('a failed totals run adds its notice and keeps the flows and their notices', function (): void {
        $state = new FlowsState();
        FlowActions::storeResult($state, flowActionsTestResult([['in_bytes' => 5]]), 0.2, '');

        FlowActions::storeSummaryFailure($state, new NfdumpException('Filter syntax error: <x>', 'nfdump -s proto/bytes'), false);
        FlowActions::storeSummaryFailure($state, new RuntimeException('stopped'), true);

        expect($state->count)->toBe(1)
            ->and(array_column($state->notifications, 'type'))->toBe(['error', 'success'])
            ->and($state->notifications[0]['message'])->toBe('Filtered totals: Filter syntax error: <x>')
            ->and($state->notifications[0]['code'])->toBe('nfdump -s proto/bytes')
        ;
    });

    test('a new flows run drops the totals of the previous one', function (): void {
        $state = new FlowsState();
        $state->setFilteredSummary(['totals' => ['flows' => 1, 'packets' => 1, 'bytes' => 1, 'protocols' => []], 'command' => '', 'elapsed' => 0.0, 'fingerprint' => '', 'ranAt' => 0]);
        FlowActions::storeResult($state, flowActionsTestResult(), 0.1, '');

        expect($state->filteredSummary)->toBeNull();
    });

    test('the estimate reads the Flows run\'s files at the totals kind\'s rate', function (): void {
        $root = sys_get_temp_dir() . '/nfsen-flows-estimate-' . bin2hex(random_bytes(4));
        $settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        $stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => []],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $root, 'profile' => 'live'],
            'log' => ['priority' => LOG_ERR],
        ]);
        Config::$stateDir = '';
        Database::resetShared();
        QueryEstimator::resetCache();

        try {
            $start = 1_704_067_200;
            foreach ([$start, $start + 300, $start + 600] as $ts) {
                $dt = new DateTimeImmutable('@' . $ts)->setTimezone(Config::nfcapdTimezone());
                $dir = $root . '/live/gw/' . $dt->format('Y/m/d');
                is_dir($dir) || mkdir($dir, 0o777, true);
                file_put_contents($dir . '/nfcapd.' . $dt->format('YmdHi'), str_repeat('x', 1000));
            }

            $estimate = FlowActions::summaryEstimate(TimeWindow::raw($start, $start + 900), ['gw'], 'live');

            expect([$estimate->files, $estimate->bytes, $estimate->runs, $estimate->clamped, $estimate->measured])->toBe([3, 3000, 1, false, false])
                ->and($estimate->seconds)->toBe((int) ceil(3000 / QueryEstimator::DEFAULT_THROUGHPUT['flows-summary']))
            ;
        } finally {
            QueryEstimator::resetCache();
            Database::resetShared();
            Config::$stateDir = $stateDirBefore ?? '';
            if ($settingsBefore !== null) {
                Config::$settings = $settingsBefore;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    });
});

describe('range totals from the stored series', function (): void {
    test('come from the datasource over the query window and sources, per protocol', function (): void {
        $provider = new class implements TotalsProvider {
            /** @var list<array<mixed>> */
            public array $calls = [];

            public function fetchTotals(array $sources, string $profile, int $start, int $end, string $protocol = 'any'): array {
                return ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
            }

            public function fetchProtocolTotals(array $sources, string $profile, int $start, int $end): array {
                $this->calls[] = [$sources, $profile, $start, $end];
                $t = ['flows' => 1.0, 'packets' => 2.0, 'bytes' => 3.0];

                return ['any' => $t, 'tcp' => $t, 'udp' => $t, 'icmp' => $t, 'other' => $t];
            }
        };
        $query = new FlowsQuery(TimeWindow::raw(600, 1200), ['gw1', 'gw2'], 'live', 10);

        $summary = FlowActions::rangeSummary($query, $provider);

        expect($provider->calls)->toBe([[['gw1', 'gw2'], 'live', 600, 1200]])
            ->and($summary['available'])->toBeTrue()
            ->and(array_keys($summary['totals']))->toBe(['any', 'tcp', 'udp', 'icmp', 'other'])
        ;
    });

    test('say why when the datasource keeps none or fails', function (): void {
        $failing = new class implements TotalsProvider {
            public function fetchTotals(array $sources, string $profile, int $start, int $end, string $protocol = 'any'): array {
                throw new RuntimeException('down');
            }

            public function fetchProtocolTotals(array $sources, string $profile, int $start, int $end): array {
                throw new RuntimeException('down');
            }
        };
        $query = new FlowsQuery(TimeWindow::raw(0, 300), ['gw1'], 'live', 10);

        expect(FlowActions::rangeSummary($query, $failing))->toBe(['available' => false, 'reason' => 'The stored totals could not be read.', 'totals' => []]);
    });

    test('the Summary view names the protocols with the picker graph\'s series slots', function (): void {
        $t = static fn (float $b): array => ['flows' => 1.0, 'packets' => 2.0, 'bytes' => $b];
        $view = FlowsPage::rangeView(['available' => true, 'reason' => '', 'totals' => ['any' => $t(2048.0), 'tcp' => $t(1024.0), 'udp' => $t(0.0), 'icmp' => $t(0.0), 'other' => $t(1024.0)]]);

        expect(array_column($view['protocols'] ?? [], 'name'))->toBe(['All protocols', 'TCP', 'UDP', 'ICMP', 'Other'])
            ->and(array_column($view['protocols'] ?? [], 'series'))->toBe([null, 1, 2, 3, 4])
            ->and($view['protocols'][0]['bytes'] ?? '')->toBe('2.000 KiB')
        ;
    });
});

describe('the query as a fingerprint (1.7)', function (): void {
    test('a live window is its width, so the clock does not make a result stale', function (): void {
        $a = FlowActions::fingerprintOf(flowActionsTestInputs(['live' => true]));
        $b = FlowActions::fingerprintOf(flowActionsTestInputs(['live' => true, 'start' => 1_000_600, 'end' => 1_087_000]));
        $wider = FlowActions::fingerprintOf(flowActionsTestInputs(['live' => true, 'end' => 1_090_000]));

        expect($a)->toBe($b)->and($a)->not->toBe($wider);
    });

    test('a fixed window is rounded to the capture interval', function (): void {
        expect(FlowActions::fingerprintOf(flowActionsTestInputs(['start' => 1_000_210])))
            ->toBe(FlowActions::fingerprintOf(flowActionsTestInputs(['start' => 1_000_490])))
            ->and(FlowActions::fingerprintOf(flowActionsTestInputs(['start' => 1_000_510])))
            ->not->toBe(FlowActions::fingerprintOf(flowActionsTestInputs()))
        ;
    });

    test('every query input counts, and the totals ignore the limit, order and aggregation', function (string $key, mixed $value, bool $totalsToo): void {
        $base = flowActionsTestInputs();
        $changed = flowActionsTestInputs([$key => $value]);

        expect(FlowActions::fingerprintOf($changed))->not->toBe(FlowActions::fingerprintOf($base))
            ->and(FlowActions::fingerprintOf($changed, totalsOnly: true) !== FlowActions::fingerprintOf($base, totalsOnly: true))->toBe($totalsToo)
        ;
    })->with([
        ['filter', 'proto udp', true],
        ['sources', ['gw2'], true],
        ['profile', 'test', true],
        ['protocol', 'udp', true],
        ['lower', '1M', true],
        ['upper', '1G', true],
        ['limit', 100, false],
        ['aggregation', ['proto' => true], false],
        ['orderByStart', true, false],
    ]);
});

describe('the Flows page', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        $this->prefsBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-flows-page-missing.json';
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [80]],
            'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-flows-page-missing', 'profile' => 'live'],
            'frontend' => ['defaults' => ['view' => 'flows']],
        ]);
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
    });

    test('before the first run: the query, the estimate and the Run button, no results', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $html = flowActionsTestRender($c, $app, $states);

        expect($html)->toContain(
            'id="filterNfdumpTextarea"',
            'id="filterFlowsLimit"',
            'id="filterFlowAggregation"',
            'data-estimate="flows"',
            'id="flowsRunSubmit"',
            'data-run="flows"',
            '$' . $c->getSignal('flows_count_label')?->id() . ' + &#039; returned&#039;',
            'Set a filter and press Run.',
        )
            ->and($html)->not->toContain('role="tablist"', 'flowTableHost', 'id="filterFlowSources"')
            ->and($html)->not->toMatch('/class="[^"]*\b(muted|mono|strong|text-end|nowrap|upper|cluster-between|spacer|text-(danger|warning|success|info))\b/')
        ;
    });

    test('after a run: the tabs, and the table and raw output hosts, sent once', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        FlowActions::storeResult($states->flows, flowActionsTestResult([['src_addr' => '10.0.0.1', 'in_bytes' => 5]]), 0.2, '/_action/ip-info-x', ['limit' => 20]);
        $states->flows->setResult($states->flows->tableHtml, 1, ['resultId' => $states->flows->resultId, 'command' => 'nfdump -M x', 'rawOutput' => "[\n]\n"]);
        $id = $states->flows->resultId;

        $first = flowActionsTestRender($c, $app, $states);
        $second = flowActionsTestRender($c, $app, $states, true);

        expect($first)->toContain(
            'id="flowsTab-flows"',
            'id="flowsTab-raw"',
            'id="flowsTab-summary"',
            'Flows (1)',
            "id=\"flowTableHost-{$id}\" data-ignore-morph><nfsen-table id=\"flowTable\"",
            "id=\"flowRawHost-{$id}\" data-ignore-morph>",
            "id=\"flowsRawOutput-{$id}\"",
            'data-chunks="1"',
            '/_action/flows-raw',
            "?result={$id}&chunk=",
            'Returned rows',
            'Range totals',
            'Compute filtered totals',
            'data-run="flows-summary"',
            'data-estimate="flows-summary"',
            'flows-summary-estimate?target=flows-summary',
        )
            // nfdump's output itself comes in chunks, once its tab is open.
            ->and($first)->not->toContain("[\n]")
            ->and(substr_count($first, 'id="flowTable"'))->toBe(1)
            ->and($second)->toContain(
                "<div class=\"result-host\" id=\"flowTableHost-{$id}\" data-ignore-morph></div>",
                "<div class=\"result-host\" id=\"flowRawHost-{$id}\" data-ignore-morph></div>",
            )
            ->and($second)->not->toContain('<nfsen-table', 'flowsRawOutput-')
        ;
    });

    test('a chunk goes out as its own append event, and a dropped result says so', function (): void {
        putenv('VIA_TEST_MODE=1');

        try {
            [$app, $c, $states] = flowActionsTestCompose();
        } finally {
            putenv('VIA_TEST_MODE');
        }
        $c->view(static fn (bool $isUpdate): string => flowActionsTestRender($c, $app, $states, $isUpdate), cacheUpdates: false);
        $flows = $states->flows;
        $rows = array_map(static fn (int $i): array => ['in_bytes' => $i], range(1, 1_200));
        FlowActions::storeResult($flows, new QueryResult($rows, 'nfdump', '', 0.1, TimeWindow::raw(0, 300), "{\"a\": \"<i>&\"}\n"), 0.1, '');
        $id = $flows->resultId;
        flowActionsTestRender($c, $app, $states);
        $patches = static function () use ($c): array {
            $all = [];
            while (($patch = $c->getPatch()) !== null) {
                $all[] = $patch;
            }

            return $all;
        };

        $c->setRequestInput(['result' => $id, 'chunk' => '1'], []);
        FlowActions::sendChunk($c, $flows, 'rows');
        $c->setRequestInput(['result' => $id, 'chunk' => '0'], []);
        FlowActions::sendChunk($c, $flows, 'raw');
        $c->setRequestInput(['result' => 'older', 'chunk' => '0'], []);
        FlowActions::sendChunk($c, $flows, 'rows');
        $sent = $patches();

        expect($sent)->toHaveCount(2)
            ->and($sent[0])->toMatchArray(['type' => 'elements', 'selector' => "#flowTableHost-{$id} > nfsen-table", 'mode' => ElementPatchMode::Append])
            ->and($sent[0]['content'])->toStartWith('<template class="table-rows" data-chunk="1">')
            ->and(substr_count($sent[0]['content'], '<tr>'))->toBe(150)
            ->and($sent[1])->toMatchArray(['selector' => "#flowsRawOutput-{$id}", 'content' => "<span data-chunk=\"0\">{\"a\": \"&lt;i&gt;&amp;\"}\n</span>"])
        ;

        // The store dropped the result: the client asking for a chunk learns it from a sync.
        FlowsState::makeRoom(static fn (): bool => false);
        $c->setRequestInput(['result' => $id, 'chunk' => '1'], []);
        FlowActions::sendChunk($c, $flows, 'rows');
        $synced = implode('', array_map(static fn (array $p): string => is_string($p['content']) ? $p['content'] : '', $patches()));

        expect($flows->rowsLost)->toBeTrue()
            ->and($flows->rawLost)->toBeFalse()
            ->and($synced)->toContain('The server dropped these rows to free memory for other results. Run again to see them.')
            ->and($synced)->not->toContain("flowTableHost-{$id}")
        ;
    });

    test('a dropped result keeps the table the client has, and says so when it would have to go out again', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $rows = array_map(static fn (int $i): array => ['in_bytes' => $i], range(1, 1_200));
        FlowActions::storeResult($states->flows, new QueryResult($rows, 'nfdump', '', 0.1, TimeWindow::raw(0, 300), "x\n"), 0.1, '');
        $id = $states->flows->resultId;
        flowActionsTestRender($c, $app, $states);
        FlowsState::makeRoom(static fn (): bool => false);

        $kept = flowActionsTestRender($c, $app, $states, true);
        $c->getSignal('page')?->setValue('health', broadcast: false);
        Shell::render($c, $app, $states, true);
        $c->getSignal('page')?->setValue('flows', broadcast: false);
        $back = flowActionsTestRender($c, $app, $states, true);

        expect($kept)->toContain("<div class=\"result-host\" id=\"flowTableHost-{$id}\" data-ignore-morph></div>")
            ->and($kept)->not->toContain('The server dropped')
            ->and($back)->toContain('The server dropped these rows', "The server dropped nfdump's output")
            ->and($back)->not->toContain("flowTableHost-{$id}", "flowRawHost-{$id}")
        ;
    });

    test('aggregated rows: the count says rows, and the limit counts the flows nfdump read', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $rows = array_map(static fn (int $i): array => ['firstSeen' => '2026-08-29 05:17:53.000', 'proto' => '6', 'flows' => '4', 'bytes' => '10'], range(1, 25));
        FlowActions::storeResult($states->flows, flowActionsTestResult($rows), 0.1, '', ['limit' => 100]);

        $html = flowActionsTestRender($c, $app, $states);

        expect($states->flows->countLabel())->toBe('rows')
            ->and($html)->toContain(
                '25 rows returned, aggregated from the first 100 flows (the limit)',
                'Returned rows (limited to 100 by the row limit)',
                'data-limit-reached="true"',
                'nfdump cannot skip rows: raise the limit to see more.',
                '<td data-kind="time" data-raw="2026-08-29 05:17:53.000"><time data-epoch=',
            )
        ;
    });

    test('the Summary times and the covered window carry their result\'s id, so a new result replaces them', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $row = ['first' => 1_700_000_000, 'last' => 1_700_000_060, 'in_bytes' => 5];
        FlowActions::storeResult($states->flows, flowActionsTestResult([$row]), 0.1, '', ['live' => true, 'ranAt' => time() - 3600]);
        $first = $states->flows->resultId;
        $before = flowActionsTestRender($c, $app, $states);
        FlowActions::storeResult($states->flows, flowActionsTestResult([$row]), 0.1, '', ['live' => true, 'ranAt' => time() - 3600]);
        $after = flowActionsTestRender($c, $app, $states, true);
        $second = $states->flows->resultId;

        expect($before)->toContain("id=\"flowsFigure-4-{$first}\"", "id=\"flowsFigure-5-{$first}\"", "id=\"flowsCovers-{$first}\"")
            ->and($after)->toContain("id=\"flowsFigure-4-{$second}\"", "id=\"flowsCovers-{$second}\"")
            ->and($after)->not->toContain("flowsFigure-4-{$first}")
        ;
    });

    test('the range totals arrive after the table, for the result they were read for', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        FlowActions::storeResult($states->flows, flowActionsTestResult([['in_bytes' => 5]]), 0.1, '', ['rangePending' => true]);
        $pending = flowActionsTestRender($c, $app, $states);
        $range = ['available' => true, 'reason' => '', 'totals' => ['any' => ['flows' => 5.0, 'packets' => 50.0, 'bytes' => 5000.0]]];

        expect($pending)->toContain('Reading the stored totals')
            ->and($states->flows->setRangeSummary('older', $range))->toBeFalse()
            ->and($states->flows->rangePending)->toBeTrue()
            ->and($states->flows->setRangeSummary($states->flows->resultId, $range))->toBeTrue()
            ->and(flowActionsTestRender($c, $app, $states, true))->toContain('All protocols')->not->toContain('Reading the stored totals')
        ;
    });

    test('nfdump\'s messages, command and output stay text (D21)', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $result = new QueryResult([['in_bytes' => 5]], "nfdump -M /data -- 'proto \"<b>x</b>\"'", '', 0.1, TimeWindow::raw(0, 300), '<img src=x onerror=alert(1)>', ['<i>note</i>']);
        FlowActions::storeResult($states->flows, $result, 0.1, '');
        $states->flows->notify('error', "Error: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'", "nfdump -M /data -- 'proto \"<b>x</b>\"'");

        $html = flowActionsTestRender($c, $app, $states);

        expect($html)->not->toContain('<b>x</b>', '<img src=x', '<i>note</i>')
            ->and($html)->toContain(
                'Error: Unknown protocol: &lt;b&gt;x&lt;/b&gt;',
                'nfdump -M /data -- &#039;proto &quot;&lt;b&gt;x&lt;/b&gt;&quot;&#039;</code>',
                '<li>&lt;i&gt;note&lt;/i&gt;</li>',
                '?page=flows&id=' . $states->flows->notifications[0]['id'],
            )
            ->and($states->flows->rawChunk(0))->toBe('<span data-chunk="0">&lt;img src=x onerror=alert(1)&gt;</span>')
        ;
    });

    test('a changed query marks the result stale; a live result says when the window moved on', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        $inputs = FlowActions::inputs($c);
        FlowActions::storeResult($states->flows, flowActionsTestResult([['in_bytes' => 5]]), 0.1, '', [
            'fingerprint' => FlowActions::fingerprintOf($inputs),
            'live' => true,
            'ranAt' => time() - 3600,
        ]);

        $moved = flowActionsTestRender($c, $app, $states);
        $c->getSignal('flows_filter')?->setValue('proto udp', broadcast: false);
        $stale = flowActionsTestRender($c, $app, $states, true);

        expect($moved)->toContain('The live window has moved on; Run again to include newer data.')
            ->and($moved)->not->toContain('These results are for an earlier query.')
            ->and($stale)->toContain('These results are for an earlier query. Run again to update.')
            ->and($stale)->not->toContain('The live window has moved on')
        ;
    });

    test('the Limit select offers the preference\'s own value, and a posted limit is clamped', function (): void {
        [, $c] = flowActionsTestCompose();
        $c->getSignal('flows_limit')?->setValue(250, broadcast: false);
        expect(FlowsPage::limits(250))->toBe([20, 50, 100, 250, 500, 1000, 10_000])
            ->and(FlowActions::inputs($c)['limit'])->toBe(250)
        ;

        $c->getSignal('flows_limit')?->setValue(10_000_000, broadcast: false);
        expect(FlowActions::inputs($c)['limit'])->toBe(FlowActions::MAX_LIMIT);
    });

    test('a snapshot of a run is revived into a fresh tab', function (): void {
        [$app, $c, $states] = flowActionsTestCompose();
        FlowActions::storeResult($states->flows, flowActionsTestResult([['in_bytes' => 5]]), 0.1, '', ['limit' => 20]);

        $revived = new PageStates();
        Revival::restoreInto($revived, Revival::snapshotOf($states));

        expect($revived->flows->snapshot())->toBe($states->flows->snapshot());
    });
});

describe('notices across pages', function (): void {
    beforeEach(function (): void {
        $settings = new ReflectionProperty(Config::class, 'settings');
        $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
        Config::$settings = Settings::fromArray(['frontend' => ['defaults' => ['view' => 'sankey']]]);
        $this->c = new Context('ctx-notices', '/', new Via(new ViaConfig()));
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    test('a Kill notice goes to the page whose query kind was running, else to the active page', function (): void {
        $kind = $this->c->signal('flows', 'query_kind');
        $page = $this->c->signal('talkers', 'page');

        expect(UtilityActions::killNoticePage($this->c))->toBe('flows');

        $kind->setValue('graph');
        expect(UtilityActions::killNoticePage($this->c))->toBe('overview');

        $kind->setValue('');
        expect(UtilityActions::killNoticePage($this->c))->toBe('talkers');

        $page->setValue('nonsense');
        expect(UtilityActions::killNoticePage($this->c))->toBe('conversations');
    });

    test('the old layout shows no Overview notices, so a Kill there lands in Flows and Top Talkers', function (): void {
        $kind = $this->c->signal('graph', 'query_kind');
        $this->c->signal('overview', 'page');

        expect(UtilityActions::killNoticePages($this->c))->toBe(PageRegistry::lazy() ? ['overview'] : ['flows', 'talkers']);

        $kind->setValue('stats');
        expect(UtilityActions::killNoticePages($this->c))->toBe(['talkers']);

        $kind->setValue('conversations');
        expect(UtilityActions::killNoticePages($this->c))->toBe(['conversations']);
    });

    test('dismiss-notification removes the id from the named page, or from every page', function (): void {
        $states = new PageStates();
        $states->flows->notify('info', 'flows');
        $states->talkers->notify('info', 'talkers');
        $flowsId = $states->flows->notifications[0]['id'];

        ShellActions::dismiss($states, 'talkers', $flowsId);
        expect($states->flows->notifications)->toHaveCount(1);

        ShellActions::dismiss($states, 'flows', $flowsId);
        expect($states->flows->notifications)->toBe([]);

        ShellActions::dismiss($states, '', $states->talkers->notifications[0]['id']);
        expect($states->talkers->notifications)->toBe([]);
    });

    test('navigate resets an unknown page to the default one and keeps a known one', function (): void {
        $page = $this->c->signal('flows', 'page');
        ShellActions::navigate($this->c);
        expect($page->string())->toBe('flows');

        $page->setValue('graphs');
        ShellActions::navigate($this->c);
        expect($page->string())->toBe('conversations');
    });
});
