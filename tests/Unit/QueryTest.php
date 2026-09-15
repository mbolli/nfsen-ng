<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimelineQuery;
use mbolli\nfsen_ng\query\TimeWindow;

/**
 * Records what a query asks for instead of running nfdump, so option building can be
 * asserted without capture files or the binary.
 */
function recordingProcessor(array $executeResult = []): Processor {
    return new class($executeResult) implements Processor {
        /** @var array<string, mixed> */
        public array $options = [];
        public string $filter = '';
        public string $profile = '';
        public int $executed = 0;

        public function __construct(private readonly array $executeResult = []) {}

        public function setOption(string $option, $value): void {
            $this->options[$option] = $value;
        }

        public function setFilter(string $filter): void {
            $this->filter = $filter;
        }

        public function setProfile(string $profile): void {
            $this->profile = $profile;
        }

        public function execute(): array {
            ++$this->executed;

            return $this->executeResult;
        }
    };
}

/**
 * Records what the timeline asks the datasource for, with canned answers. Implementing the
 * interface keeps this honest: a signature change breaks the double rather than the assertions.
 */
function recordingDatasource(): Datasource {
    return new class implements Datasource {
        /** @var list<mixed> */
        public array $graphArgs = [];
        public array|string $graphReturn = ['data' => [], 'start' => 0, 'end' => 0, 'step' => 300, 'legend' => []];

        /** @var array<string, int> */
        public array $lastUpdates = [];

        public function write(array $data): bool {
            return true;
        }

        public function get_graph_data(
            int $start,
            int $end,
            array $sources,
            array $protocols,
            array $ports,
            string $type = 'flows',
            string $display = 'sources',
            ?int $maxrows = 500,
            string $profile = '',
        ): array|string {
            $this->graphArgs = [$start, $end, $sources, $protocols, $ports, $type, $display, $maxrows, $profile];

            return $this->graphReturn;
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

        public function last_update(string $source, int $port = 0, string $profile = ''): int {
            return $this->lastUpdates[$source] ?? 0;
        }

        public function get_data_path(string $source = '', int $port = 0, string $profile = ''): string {
            return '';
        }

        public function healthChecks(string $group, array $sources): array {
            return [];
        }

        public function fetchLatestSlot(array $sources, string $profile): array {
            return ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        }

        public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds): array {
            return ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        }
    };
}

function statsQuerySettings(): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump', 'max_stats_window' => 0],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile' => 'live',
            'max-processes' => 1,
        ],
        'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
        'log' => ['priority' => LOG_WARNING],
    ]);
}

function makeStatsQuery(array $overrides = []): StatsQuery {
    statsQuerySettings();

    return new StatsQuery(
        window: $overrides['window'] ?? TimeWindow::raw(1_000, 2_000),
        sources: $overrides['sources'] ?? ['gw', 'dmz'],
        profile: $overrides['profile'] ?? 'live',
        for: $overrides['for'] ?? 'srcip',
        orderBy: $overrides['orderBy'] ?? 'bytes',
        limit: $overrides['limit'] ?? 10,
        filter: $overrides['filter'] ?? '',
        lowerLimit: $overrides['lowerLimit'] ?? '',
        upperLimit: $overrides['upperLimit'] ?? '',
    );
}

describe('StatsQuery::effectiveFilter()', function (): void {
    test('passes a plain filter through untouched', function (): void {
        expect(makeStatsQuery(['filter' => 'proto tcp'])->effectiveFilter())->toBe('proto tcp');
    });

    test('is empty when nothing was given', function (): void {
        expect(makeStatsQuery()->effectiveFilter())->toBe('');
    });

    // Thresholds cannot use nfdump -l/-L in statistics mode, so they join the expression.
    test('combines a byte threshold with the user filter', function (): void {
        $filter = makeStatsQuery(['filter' => 'proto tcp', 'lowerLimit' => '1M'])->effectiveFilter();

        expect($filter)->toContain('proto tcp')
            ->and($filter)->toContain('and')
        ;
    });

    test('uses the threshold alone when there is no user filter', function (): void {
        $filter = makeStatsQuery(['lowerLimit' => '1M'])->effectiveFilter();

        expect($filter)->not->toBe('')
            ->and($filter)->not->toContain('and')
        ;
    });
});

describe('StatsQuery::processor()', function (): void {
    test('maps its arguments onto nfdump options', function (): void {
        statsQuerySettings();
        $recorder = recordingProcessor();
        Config::$processorClass = $recorder;

        $query = makeStatsQuery(['for' => 'dstport', 'orderBy' => 'flows', 'limit' => 25]);
        $processor = $query->processor();

        expect($processor->options['-s'])->toBe('dstport/flows')
            ->and($processor->options['-n'])->toBe(25)
            ->and($processor->options['-M'])->toBe('gw:dmz')
            ->and($processor->options['-R'])->toBe([1_000, 2_000])
            ->and($processor->options['-o'])->toBe('json')
            ->and($processor->profile)->toBe('live')
        ;
    });
});

describe('StatsQuery::run()', function (): void {
    test('re-keys an object response into a list and keeps provenance', function (): void {
        $query = makeStatsQuery();
        // nfdump can answer with string keys; consumers index the first row positionally.
        $processor = recordingProcessor([
            'decoded' => ['a' => ['val' => 1], 'b' => ['val' => 2]],
            'command' => 'nfdump -s srcip/bytes',
            'stderr' => 'a warning',
            'rawOutput' => 'raw',
        ]);

        $result = $query->run($processor);

        expect($result)->toBeInstanceOf(QueryResult::class)
            ->and($result->rows)->toBe([['val' => 1], ['val' => 2]])
            ->and($result->command)->toBe('nfdump -s srcip/bytes')
            ->and($result->stderr)->toBe('a warning')
            ->and($result->rawOutput)->toBe('raw')
            ->and($result->count())->toBe(2)
            ->and($result->window->start)->toBe(1_000)
        ;
    });

    test('an empty response is an empty result, not an error', function (): void {
        $result = makeStatsQuery()->run(recordingProcessor([]));

        expect($result->isEmpty())->toBeTrue()
            ->and($result->command)->toBe('')
            ->and($result->stderr)->toBe('')
        ;
    });
});

describe('FlowsQuery', function (): void {
    test('maps its arguments onto nfdump options', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $processor = (new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 500,
            orderByStart: true,
        ))->processor();

        expect($processor->options['-c'])->toBe(500)
            ->and($processor->options['-R'])->toBe([10, 20])
            ->and($processor->options['-O'])->toBe('tstart')
            ->and($processor->options['-o'])->toBe('json')
        ;
    });

    test('leaves the ordering option off when not ordering by start', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $processor = (new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 10,
        ))->processor();

        expect($processor->options)->not->toHaveKey('-O');
    });

    // Bidirectional is a flag of its own, every other aggregation is a spec passed to -a.
    test('bidirectional aggregation uses its own flag', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $processor = (new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 10,
            aggregation: ['bidirectional' => true],
        ))->processor();

        expect($processor->options)->toHaveKey('-B')
            ->and($processor->options)->not->toHaveKey('-a')
        ;
    });

    test('field aggregation is passed to -a', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $processor = (new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 10,
            aggregation: ['srcport' => true, 'dstport' => true],
        ))->processor();

        expect($processor->options['-a'])->toBe('-Asrcport,dstport');
    });

    test('combines byte thresholds with the user filter', function (): void {
        statsQuerySettings();

        $query = new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 10,
            filter: 'proto udp',
            upperLimit: '10M',
        );

        expect($query->effectiveFilter())->toContain('proto udp')
            ->and($query->effectiveFilter())->toContain('and')
        ;
    });
});

describe('MatrixQuery', function (): void {
    test('reads anything that is not packets as bytes', function (): void {
        statsQuerySettings();

        $bytes = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'nonsense', 10);
        $packets = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'packets', 10);

        expect($bytes->metric())->toBe('bytes')
            ->and($packets->metric())->toBe('packets')
        ;
    });

    // Ports add the middle column of the diagram, so they join the aggregation key.
    test('adds the destination port to the key only when showing ports', function (): void {
        statsQuerySettings();

        $without = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10))->aggregation();
        $with = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, showPorts: true))->aggregation();

        expect($without)->not->toHaveKey('dstport')
            ->and($with)->toHaveKey('dstport')
        ;
    });

    // #159: 1.7.5 rejects a custom fmt: alongside -A, so it gets plain aggregated csv.
    test('falls back to csv on the nfdump version that cannot combine fmt with aggregation', function (): void {
        statsQuerySettings();
        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10);

        expect($query->outputFormat('1.7.5'))->toBe('csv')
            ->and($query->outputFormat('1.7.6'))->toStartWith('fmt:')
        ;
    });

    test('asks for the port field only when showing ports', function (): void {
        statsQuerySettings();

        $with = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, showPorts: true))->outputFormat('1.7.6');
        $without = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10))->outputFormat('1.7.6');

        expect($with)->toContain('%dp')
            ->and($without)->not->toContain('%dp')
        ;
    });

    test('rejects a processor answer that is not a table', function (): void {
        statsQuerySettings();
        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10);

        expect(fn () => $query->run(recordingProcessor(['decoded' => 'not a table'])))
            ->toThrow(RuntimeException::class)
        ;
    });
});

describe('TimelineQuery', function (): void {
    beforeEach(function (): void {
        statsQuerySettings();
        Config::$db = recordingDatasource();
    });

    test('passes the window and display options to the datasource', function (): void {
        $query = new TimelineQuery(
            window: TimeWindow::raw(300, 900),
            sources: ['gw'],
            protocols: ['tcp'],
            ports: [80],
            unit: 'bits',
            display: 'ports',
            resolution: 250,
            profile: 'live',
        );
        $query->run();

        expect(Config::$db->graphArgs)->toBe([300, 900, ['gw'], ['tcp'], [80], 'bits', 'ports', 250, 'live']);
    });

    // RRD reports failures by returning a string rather than throwing, so the query
    // normalises both into one exception for callers.
    test('turns a datasource error string into an exception', function (): void {
        Config::$db->graphReturn = 'rrd_xport failed';

        $query = new TimelineQuery(window: TimeWindow::raw(0, 300), sources: ['gw']);

        expect(fn () => $query->run())->toThrow(RuntimeException::class, 'rrd_xport failed');
    });

    test('ignores the any pseudo-source when asking for the last write', function (): void {
        Config::$db->lastUpdates = ['gw' => 500, 'dmz' => 900];

        $query = new TimelineQuery(window: TimeWindow::raw(0, 300), sources: ['any']);

        // 'any' is not a source, so it falls back to everything configured.
        expect($query->lastWrite())->toBe(500);
    });

    test('reports the newest write across the selected sources', function (): void {
        Config::$db->lastUpdates = ['gw' => 500, 'dmz' => 900];

        $query = new TimelineQuery(window: TimeWindow::raw(0, 300), sources: ['gw', 'dmz']);

        expect($query->lastWrite())->toBe(900);
    });
});
