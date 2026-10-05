<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\CostEstimate;
use mbolli\nfsen_ng\query\CoverageQuery;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\LoadQuery;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\PartitionPlanner;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatisticCatalog;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimelineQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Tests\Support\FakeProcessor;

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
        public string $handle = '';
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

        public function setQueryHandle(string $handle): void {
            $this->handle = $handle;
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

        /** @var array<string, array{int, int}> */
        public array $boundaries = [];
        public bool $boundariesThrow = false;
        public array $latestSlot = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        public array $rollingAverage = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];

        /** @var list<string> */
        public array $latestSlotSources = [];

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
            if ($this->boundariesThrow) {
                throw new RuntimeException('no database');
            }

            return $this->boundaries[$source] ?? [0, 0];
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
            $this->latestSlotSources = $sources;

            return $this->latestSlot;
        }

        public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds, ?int $end = null): array {
            return $this->rollingAverage;
        }
    };
}

function statsQuerySettings(int $maxStatsWindow = 0): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump', 'max_stats_window' => $maxStatsWindow],
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
        aggregation: $overrides['aggregation'] ?? [],
        protocol: $overrides['protocol'] ?? 'any',
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
        expect(makeStatsQuery(['filter' => 'proto tcp', 'lowerLimit' => '1M'])->effectiveFilter())
            ->toBe('(bytes > 1M) and (proto tcp)')
        ;
    });

    test('uses the threshold alone when there is no user filter', function (): void {
        expect(makeStatsQuery(['lowerLimit' => '1M', 'upperLimit' => '10M'])->effectiveFilter())
            ->toBe('bytes > 1M and bytes < 10M')
        ;
    });

    // `bytes > 1M and src port 53 or dst port 53` applied the threshold to one side only.
    test('keeps an or in the user filter from escaping the threshold', function (): void {
        expect(makeStatsQuery(['filter' => 'src port 53 or dst port 53', 'lowerLimit' => '1M'])->effectiveFilter())
            ->toBe('(bytes > 1M) and (src port 53 or dst port 53)')
        ;
    });

    test('adds the global protocol between the thresholds and the user filter', function (): void {
        expect(makeStatsQuery(['filter' => 'port 53', 'lowerLimit' => '1k', 'protocol' => 'udp'])->effectiveFilter())
            ->toBe('(bytes > 1k) and (proto udp) and (port 53)')
            ->and(makeStatsQuery(['filter' => 'port 443', 'protocol' => 'icmp'])->effectiveFilter())
            ->toBe('(proto icmp or proto icmp6) and (port 443)')
        ;
    });

    test('uses the protocol alone when nothing else is set', function (): void {
        expect(makeStatsQuery(['protocol' => 'other'])->effectiveFilter())
            ->toBe('not (proto tcp or proto udp or proto icmp or proto icmp6)')
        ;
    });

    test('hands the composed filter to the processor', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = makeStatsQuery(['filter' => 'port 53', 'protocol' => 'tcp']);

        expect($query->processor()->filter)->toBe('(proto tcp) and (port 53)');
    });

    // The actions report this like any other failure to build the command.
    test('refuses a user filter that would close its parenthesis early', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = makeStatsQuery(['filter' => 'port 53) or (port 80', 'lowerLimit' => '1M', 'protocol' => 'udp']);

        expect(fn () => $query->processor())->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.');
    });
});

describe('StatsQuery validation', function (): void {
    // stats_for is client-writable and reaches nfdump's -s option.
    test('rejects a statistic that is not in the catalog', function (): void {
        expect(fn () => makeStatsQuery(['for' => 'srcport:p']))->toThrow(InvalidArgumentException::class, 'Unknown statistic.')
            ->and(fn () => makeStatsQuery(['for' => 'bogus']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => makeStatsQuery(['for' => '']))->toThrow(InvalidArgumentException::class)
        ;
    });

    // The panels render the message as markup, so a crafted value must not come back in it.
    test('leaves the rejected value out of the message', function (): void {
        foreach (['for', 'orderBy', 'protocol'] as $key) {
            try {
                makeStatsQuery([$key => '<img src=x onerror=alert(1)>']);
                $message = '';
            } catch (InvalidArgumentException $e) {
                $message = $e->getMessage();
            }

            expect($message)->not->toBe('')->not->toContain('<');
        }
    });

    // stats_orderBy is client-writable too and reaches `-s <for>/<orderBy>`.
    test('rejects an order nfdump does not rank by', function (): void {
        expect(fn () => makeStatsQuery(['orderBy' => 'bogus']))->toThrow(InvalidArgumentException::class, 'Unknown order, expected one of flows, packets, bytes, pps, bps, bpp.')
            ->and(fn () => makeStatsQuery(['orderBy' => 'bytes/flows']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => makeStatsQuery(['orderBy' => '']))->toThrow(InvalidArgumentException::class)
        ;
    });

    test('accepts every order the picker offers', function (): void {
        foreach (StatisticCatalog::ORDER_BY as $order) {
            expect(makeStatsQuery(['orderBy' => $order])->orderBy)->toBe($order);
        }
    });

    test('accepts every statistic in the catalog', function (): void {
        foreach (StatisticCatalog::all() as $entry) {
            expect(makeStatsQuery(['for' => $entry['value']])->for)->toBe($entry['value']);
        }
    });

    test('rejects an unknown protocol', function (): void {
        expect(fn () => makeStatsQuery(['protocol' => 'sctp']))->toThrow(InvalidArgumentException::class, 'Unknown protocol, expected one of any, tcp, udp, icmp, other.');
    });
});

describe('StatsQuery aggregation (#174)', function (): void {
    /** @return array<string, mixed> the options a query of this shape hands the processor */
    function statsOptions(array $overrides): array {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        return makeStatsQuery($overrides)->processor()->options;
    }

    // nfdump ranks aggregated records for -s record, and the aggregation decides what a
    // record is. Verified against nfdump 1.7.8: -A dstport collapses every flow to that port.
    test('passes the spec for the flow-records statistic', function (): void {
        $options = statsOptions(['for' => 'record', 'aggregation' => ['proto' => true, 'dstport' => true]]);

        expect($options['-a'])->toBe('-Aproto,dstport');
    });

    // "Warning: Aggregation ignored for element statistics": nfdump aggregates these by their
    // own element, so a spec would be dropped by nfdump anyway, silently changing nothing.
    test('drops the spec for an element statistic', function (): void {
        $options = statsOptions(['for' => 'srcip', 'aggregation' => ['proto' => true]]);

        expect($options)->not->toHaveKey('-a')
            ->and(makeStatsQuery(['for' => 'srcip', 'aggregation' => ['proto' => true]])->aggregationString())->toBe('')
        ;
    });

    test('bidirectional is its own flag, not a spec', function (): void {
        $options = statsOptions(['for' => 'record', 'aggregation' => ['bidirectional' => true]]);

        expect($options)->toHaveKey('-B')
            ->and($options)->not->toHaveKey('-a')
        ;
    });

    test('an empty aggregation leaves the query alone', function (): void {
        $options = statsOptions(['for' => 'record']);

        expect($options)->not->toHaveKey('-a')
            ->and($options)->not->toHaveKey('-B')
        ;
    });

    test('carries an IP prefix length into the spec', function (): void {
        $options = statsOptions(['for' => 'record', 'aggregation' => ['srcip' => 'srcip4', 'srcipPrefix' => '24']]);

        expect($options['-a'])->toBe('-Asrcip4/24');
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

        expect($query->effectiveFilter())->toBe('(bytes < 10M) and (proto udp)');
    });

    test('parenthesises thresholds, protocol and user filter', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = new FlowsQuery(
            window: TimeWindow::raw(10, 20),
            sources: ['gw'],
            profile: 'live',
            limit: 10,
            filter: 'dst port 80 or dst port 443',
            lowerLimit: '100',
            protocol: 'tcp',
        );

        expect($query->effectiveFilter())->toBe('(bytes > 100) and (proto tcp) and (dst port 80 or dst port 443)')
            ->and($query->processor()->filter)->toBe($query->effectiveFilter())
        ;
    });

    test('refuses a user filter that would close its parenthesis early', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = new FlowsQuery(window: TimeWindow::raw(10, 20), sources: ['gw'], profile: 'live', limit: 10, filter: 'host 10.0.0.1) or (host 10.0.0.2', protocol: 'tcp');

        expect(fn () => $query->processor())->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.');
    });

    test('defaults to any protocol, which adds nothing', function (): void {
        statsQuerySettings();

        $query = new FlowsQuery(window: TimeWindow::raw(10, 20), sources: ['gw'], profile: 'live', limit: 10, filter: 'host 10.0.0.1');

        expect($query->protocol)->toBe('any')
            ->and($query->effectiveFilter())->toBe('host 10.0.0.1')
        ;
    });

    test('rejects an unknown protocol', function (): void {
        statsQuerySettings();

        expect(fn () => new FlowsQuery(window: TimeWindow::raw(10, 20), sources: ['gw'], profile: 'live', limit: 10, protocol: 'TCP '))
            ->toThrow(InvalidArgumentException::class)
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
    test('aggregates on the key of the grouping (4.4.2)', function (string $groupBy, bool $showPorts, string $spec): void {
        statsQuerySettings();
        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, showPorts: $showPorts, groupBy: $groupBy);

        expect($query->aggregation())->toBe($spec)
            ->and($query->groupBy())->toBe($showPorts ? 'port' : $groupBy)
        ;
    })->with([
        ['ip', false, 'srcip,dstip'],
        ['net24', false, 'srcip4/24,dstip4/24'],
        ['net16', false, 'srcip4/16,dstip4/16'],
        ['port', false, 'srcip,dstport,dstip'],
        // The MCP tool's flag is the port grouping.
        ['ip', true, 'srcip,dstport,dstip'],
    ]);

    test('rejects an unknown grouping or direction, and both directions with the port', function (): void {
        statsQuerySettings();

        expect(fn () => new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, groupBy: 'net8'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, direction: 'backward'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, groupBy: 'port', direction: 'both'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, showPorts: true, direction: 'both'))->toThrow(InvalidArgumentException::class)
        ;
    });

    // D15: both directions merge the directed pairs, so it fetches four times as many.
    test('over-fetches for both directions, capped', function (): void {
        statsQuerySettings();
        $pairs = static fn (int $topN, string $direction): int => (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', $topN, direction: $direction))->fetchLimit();

        expect($pairs(20, 'forward'))->toBe(20)
            ->and($pairs(20, 'both'))->toBe(80)
            ->and($pairs(500, 'both'))->toBe(MatrixQuery::MAX_FETCH)
            ->and($pairs(900, 'forward'))->toBe(MatrixQuery::MAX_TOP_N)
            ->and($pairs(0, 'forward'))->toBe(1)
        ;
    });

    test('maps the grouping onto nfdump options, always with full IPv6 addresses', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $processor = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'packets', 20, groupBy: 'net24', direction: 'both', handle: 'ctx'))->processor();

        expect($processor->options)->toMatchArray([
            '-M' => 'gw',
            '-a' => '-Asrcip4/24,dstip4/24',
            '-O' => 'packets',
            '-n' => 80,
            '-N' => null,
            '-6' => null,
        ])->and($processor->options)->toHaveKey('-6')
            ->and($processor->options['-o'])->toStartWith('fmt:')
            ->and($processor->handle)->toBe('ctx')
            ->and($processor->filter)->toBe('ipv4')
        ;
    });

    test('limits a subnet grouping to IPv4, between the protocol and the user filter', function (string $groupBy, string $expected): void {
        statsQuerySettings();

        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, filter: 'port 53', protocol: 'udp', groupBy: $groupBy);

        expect($query->effectiveFilter())->toBe($expected);
    })->with([
        ['net24', '(proto udp) and (ipv4) and (port 53)'],
        ['net16', '(proto udp) and (ipv4) and (port 53)'],
        ['ip', '(proto udp) and (port 53)'],
        ['port', '(proto udp) and (port 53)'],
    ]);

    // #159: 1.7.5 rejects a custom fmt: alongside -A, so it gets plain aggregated csv.
    test('falls back to csv on the nfdump version that cannot combine fmt with aggregation', function (): void {
        statsQuerySettings();
        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10);

        expect($query->outputFormat('1.7.5'))->toBe('csv')
            ->and($query->outputFormat('1.7.6'))->toStartWith('fmt:')
        ;
    });

    test('asks for the port field only when grouping by port', function (): void {
        statsQuerySettings();

        $with = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, groupBy: 'port'))->outputFormat('1.7.6');
        $flag = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, showPorts: true))->outputFormat('1.7.6');
        $without = (new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, groupBy: 'net24'))->outputFormat('1.7.6');

        expect($with)->toBe('fmt:%sa %da %dp %ibyt %ipkt %fl')
            ->and($flag)->toBe($with)
            ->and($without)->toBe('fmt:%sa %da %ibyt %ipkt %fl')
        ;
    });

    test('parenthesises thresholds, protocol and user filter', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = new MatrixQuery(
            TimeWindow::raw(0, 10),
            ['gw'],
            'live',
            'bytes',
            10,
            filter: 'net 10.0.0.0/8 or net 192.168.0.0/16',
            upperLimit: '1G',
            protocol: 'udp',
        );

        expect($query->effectiveFilter())->toBe('(bytes < 1G) and (proto udp) and (net 10.0.0.0/8 or net 192.168.0.0/16)')
            ->and($query->processor()->filter)->toBe($query->effectiveFilter())
        ;
    });

    test('rejects an unknown protocol', function (): void {
        statsQuerySettings();

        expect(fn () => new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, protocol: 'gre'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    test('refuses a user filter that would close its parenthesis early', function (): void {
        statsQuerySettings();
        Config::$processorClass = recordingProcessor();

        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10, filter: 'net 10.0.0.0/8) or (net 192.168.0.0/16', protocol: 'udp');

        expect(fn () => $query->processor())->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.');
    });

    test('rejects a processor answer that is not a table', function (): void {
        statsQuerySettings();
        $query = new MatrixQuery(TimeWindow::raw(0, 10), ['gw'], 'live', 'bytes', 10);

        expect(fn () => $query->run(recordingProcessor(['decoded' => 'not a table'])))
            ->toThrow(RuntimeException::class)
        ;
    });
});

/** A capture tree of $intervals 5-minute files of one source from 2026-09-29 00:00 UTC; returns its root. */
function queryTestTree(int $intervals): string {
    $root = sys_get_temp_dir() . '/nfsen-matrix-split-' . bin2hex(random_bytes(4));
    for ($i = 0; $i < $intervals; ++$i) {
        $ts = 1_790_640_000 + $i * 300;
        $dir = $root . '/live/gw/' . gmdate('Y/m/d', $ts);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir . '/nfcapd.' . gmdate('YmdHi', $ts), 'x');
    }

    return $root;
}

/** One pair as a Conversations part prints it: the query's columns, then out bytes and packets. */
function queryTestPair(string $src, string $dst, int $bytes, ?int $port = null): string {
    return sprintf('%16s %16s', $src, $dst) . ($port === null ? '' : sprintf(' %6d', $port)) . sprintf(' %8d %8d %5d %8d %8d', $bytes, 1, 1, 0, 0);
}

describe('MatrixQuery in time slices', function (): void {
    beforeEach(function (): void {
        $this->root = queryTestTree(12);
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'max_stats_window' => 0],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 8],
            'log' => ['priority' => LOG_ERR],
        ]);
        PartitionPlanner::useFetchLimit(null);
        $this->window = TimeWindow::raw(1_790_640_000, 1_790_640_000 + 12 * 300 - 1);
        FakeProcessor::reset();
        Config::$processorClass = new FakeProcessor();
    });

    afterEach(function (): void {
        PartitionPlanner::useFetchLimit(null);
        FakeProcessor::reset();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    });

    test('a part prints the query\'s pairs with the out counters its ranking needs', function (): void {
        $header = '     Src IP Addr      Dst IP Addr  In Byte   In Pkt Flows Out Byte  Out Pkt';
        FakeProcessor::queueRaw($header . "\n" . queryTestPair('10.0.0.1', '10.0.0.2', 500) . "\nSummary: total flows: 1, total bytes: 500, total packets: 1\n");
        FakeProcessor::queueRaw($header . "\n" . queryTestPair('10.0.0.1', '10.0.0.2', 700) . "\nSummary: total flows: 1, total bytes: 700, total packets: 1\n");

        $result = new MatrixQuery($this->window, ['gw'], 'live', 'bytes', 20, handle: 'ctx', direction: 'forward')->runPartitioned(parts: 2);

        expect($result->parts)->toBe(2)
            ->and(FakeProcessor::callOptions(0))->toMatchArray(['-R' => '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290025', '-n' => 10_000, '-O' => 'bytes', '-o' => 'fmt:%sa %da %ibyt %ipkt %fl %obyt %opkt'])
            ->and(FakeProcessor::$calls[1]['handle'])->toBe('ctx')
            ->and($result->rows)->toBe([['sa' => '10.0.0.1', 'da' => '10.0.0.2', 'ibyt' => '1200', 'ipkt' => '2', 'fl' => '2']])
            ->and((string) $result->rawOutput)->toContain("Summary: total flows: 2, total bytes: 1200, total packets: 2\n")
        ;
    });

    test('pairs a part did not list are looked up by their subnets, or their ports, under the query\'s filter', function (string $group, array $first, array $second, array $filters): void {
        PartitionPlanner::useFetchLimit(2);
        $header = '     Src IP Addr      Dst IP Addr' . ($group === 'port' ? ' Dst Pt' : '') . '  In Byte   In Pkt Flows Out Byte  Out Pkt';
        FakeProcessor::queueRaw($header . "\n" . implode("\n", array_map(static fn (array $pair): string => queryTestPair(...$pair), $first)) . "\n");
        FakeProcessor::queueRaw($header . "\n" . implode("\n", array_map(static fn (array $pair): string => queryTestPair(...$pair), $second)) . "\n");

        $result = new MatrixQuery($this->window, ['gw'], 'live', 'bytes', 1, groupBy: $group, direction: 'forward')->runPartitioned(parts: 2);

        expect(array_column(array_slice(FakeProcessor::$calls, 2), 'filter'))->toBe($filters)
            ->and(array_column(array_column(array_slice(FakeProcessor::$calls, 2), 'options'), '-n'))->toBe([0, 0])
            ->and($result->parts)->toBe(2)
            ->and($result->rows[0]['ibyt'] ?? null)->toBe('100')
        ;
    })->with([
        'subnets' => ['net24', [['10.1.2.0', '10.9.9.0', 100], ['10.1.3.0', '10.9.9.0', 50]], [['10.1.4.0', '10.9.8.0', 90], ['10.1.5.0', '10.9.8.0', 40]], [
            '(ipv4) and (src ip in [10.1.4.0/24] and dst ip in [10.9.8.0/24])',
            '(ipv4) and (src ip in [10.1.2.0/24] and dst ip in [10.9.9.0/24])',
        ]],
        'ports' => ['port', [['10.0.0.1', '10.0.0.9', 100, 443], ['10.0.0.2', '10.0.0.9', 50, 53]], [['10.0.0.3', '10.0.0.8', 90, 22], ['10.0.0.4', '10.0.0.8', 40, 80]], [
            'src ip in [10.0.0.3] and dst ip in [10.0.0.8] and dst port in [22]',
            'src ip in [10.0.0.1] and dst ip in [10.0.0.9] and dst port in [443]',
        ]],
    ]);

    test('nfdump 1.7.5, which needs the plain csv, runs as one process', function (): void {
        $query = new MatrixQuery($this->window, ['gw'], 'live', 'bytes', 20);

        expect($query->outputFormat('1.7.5'))->toBe('csv')
            ->and($query->outputFormat())->toStartWith('fmt:')
        ;
    });
});

describe('an nfdump run over sources with a capture gap', function (): void {
    beforeEach(function (): void {
        $this->root = queryTestTree(6);
        rename($this->root . '/live/gw', $this->root . '/live/dmz');
        mkdir($this->root . '/live/gw/2026/09/29', 0o777, true);
        foreach (['0005', '0010', '0015', '0020', '0025'] as $time) {
            file_put_contents($this->root . '/live/gw/2026/09/29/nfcapd.20260929' . $time, 'x');
        }
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw', 'dmz'], 'ports' => [], 'max_stats_window' => 0],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 8],
            'log' => ['priority' => LOG_ERR],
        ]);
    });

    afterEach(function (): void {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    // nfdump -M reads nothing at all when its first directory lacks the first -R file.
    test('names first in -M a source that holds the first file of -R', function (): void {
        $command = static function (string $sources, array|string $range): string {
            $nfdump = new Nfdump();
            $nfdump->setOption('-M', $sources);
            $nfdump->setOption('-R', $range);

            return $nfdump->commandLine();
        };
        $live = $this->root . '/live/';

        expect($command('gw:dmz', [1_790_640_000, 1_790_640_000 + 6 * 300 - 1]))->toContain("-M '{$live}dmz:gw' -R '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290025'")
            ->and($command('gw:dmz', '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290000'))->toContain("-M '{$live}dmz:gw'")
            ->and($command('gw:dmz', '2026/09/29/nfcapd.202609290005:2026/09/29/nfcapd.202609290025'))->toContain("-M '{$live}gw:dmz'")
            ->and($command('dmz:gw', '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290025'))->toContain("-M '{$live}dmz:gw'")
            ->and($command('gw', '2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290025'))->toContain("-M '{$live}gw'")
            ->and($command('gw:dmz', '2026/09/29/nfcapd.202609290030:2026/09/29/nfcapd.202609290035'))->toContain("-M '{$live}gw:dmz'")
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

describe('LoadQuery', function (): void {
    test('reports how many times the average the current slot is', function (): void {
        statsQuerySettings();
        $db = recordingDatasource();
        $db->latestSlot = ['flows' => 200.0, 'packets' => 50.0, 'bytes' => 1000.0];
        $db->rollingAverage = ['flows' => 100.0, 'packets' => 50.0, 'bytes' => 4000.0];
        Config::$db = $db;

        $result = (new LoadQuery(['gw']))->run();

        expect($result['ratio']['flows'])->toBe(2.0)
            ->and($result['ratio']['packets'])->toBe(1.0)
            ->and($result['ratio']['bytes'])->toBe(0.25)
        ;
    });

    // A quiet source has no meaningful multiple, and infinity is not a useful answer.
    test('a zero average reports no multiple rather than infinity', function (): void {
        statsQuerySettings();
        $db = recordingDatasource();
        $db->latestSlot = ['flows' => 5.0, 'packets' => 0.0, 'bytes' => 0.0];
        $db->rollingAverage = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        Config::$db = $db;

        expect((new LoadQuery(['gw']))->run()['ratio']['flows'])->toBe(0.0);
    });

    test('falls back to the configured sources when given none', function (): void {
        statsQuerySettings();
        $db = recordingDatasource();
        Config::$db = $db;

        (new LoadQuery([]))->run();

        expect($db->latestSlotSources)->toBe(['gw']);
    });
});

describe('CoverageQuery', function (): void {
    test('summarises the first and last sample across sources', function (): void {
        statsQuerySettings();
        $db = recordingDatasource();
        $db->boundaries = ['gw' => [100, 500], 'dmz' => [50, 400]];
        $db->lastUpdates = ['gw' => 500, 'dmz' => 400];
        Config::$db = $db;

        $result = (new CoverageQuery(['gw', 'dmz']))->run();

        expect($result['first'])->toBe(50)
            ->and($result['last'])->toBe(500)
            ->and($result['sources'])->toHaveCount(2)
            ->and($result['sources'][0]['last_update'])->toBe(500)
        ;
    });

    // A configured source that was never imported has no database to ask.
    test('a source whose datasource throws is reported as empty, not fatal', function (): void {
        statsQuerySettings();
        $db = recordingDatasource();
        $db->boundariesThrow = true;
        Config::$db = $db;

        $result = (new CoverageQuery(['gw']))->run();

        expect($result['first'])->toBe(0)
            ->and($result['sources'][0]['first'])->toBe(0)
        ;
    });

    test('offers the retention depth as a lower bound when nothing is imported', function (): void {
        statsQuerySettings();

        expect(CoverageQuery::fallbackFirst())->toBeLessThan(time());
    });
});

describe('CostEstimate', function (): void {
    test('a single pass is one run regardless of the window', function (): void {
        statsQuerySettings();

        $estimate = CostEstimate::forSinglePass(TimeWindow::raw(0, 86400), [], 'live');

        expect($estimate->runs)->toBe(1);
    });

    // A filtered series runs one nfdump per bin per group, which is what makes it expensive.
    test('a filtered series scales its run count with the group count', function (): void {
        statsQuerySettings();
        $window = TimeWindow::raw(0, 86400);

        $one = CostEstimate::runsForFilteredSeries($window, 288, 1);
        $three = CostEstimate::runsForFilteredSeries($window, 288, 3);

        expect($three)->toBeGreaterThan($one);
    });

    test('never reports fewer than one run', function (): void {
        statsQuerySettings();

        expect(CostEstimate::runsForFilteredSeries(TimeWindow::raw(100, 100), 1, 1))->toBeGreaterThanOrEqual(1);
    });

    // The whole point of the split: the panel calls this on every render.
    test('the run count needs no filesystem access', function (): void {
        statsQuerySettings();
        $window = TimeWindow::raw(0, 3600);

        expect(CostEstimate::runsForFilteredSeries($window, 60, 1))->toBeInt();
    });

    test('carries the clamp flag through to its array form', function (): void {
        statsQuerySettings();

        $estimate = new CostEstimate(3, 400, 2, TimeWindow::clamped(0, 86400 * 400, 86400));

        expect($estimate->toArray())->toBe([
            'files' => 3,
            'bytes' => 400,
            'runs' => 2,
            'window_clamped' => true,
        ]);
    });
});

describe('TimeWindow::clampNotice()', function (): void {
    // "clamped to 0 days" was the exact wording bug the window formatter was added to fix,
    // and the notice was still rounding to days on its own.
    test('names a sub-day bound in a unit that reads', function (): void {
        statsQuerySettings(3600);

        expect(TimeWindow::clamped(0, 86400)->clampNotice())->toContain('1 hour')
            ->and(TimeWindow::clamped(0, 86400)->clampNotice())->not->toContain('0 days')
        ;
    });

    test('names the bound the caller passed, not the configured one', function (): void {
        statsQuerySettings(86400 * 30);

        expect(TimeWindow::clamped(0, 86400 * 10, 86400)->clampNotice())->toContain('1 day');
    });

    test('humanises days, hours and minutes', function (): void {
        expect(TimeWindow::humanize(86400 * 3))->toBe('3 days')
            ->and(TimeWindow::humanize(86400))->toBe('1 day')
            ->and(TimeWindow::humanize(7200))->toBe('2 hours')
            ->and(TimeWindow::humanize(300))->toBe('5 minutes')
        ;
    });
});

describe('FilteredSeries protocol bucketing', function (): void {
    // Stored and filtered mode read the same nfdump output; when they used separate maps,
    // ICMPv6 counted as icmp in one graph and other in the other for the same window.
    test('buckets ICMPv6 with icmp, as the import does', function (): void {
        expect(Import::protocolBucket('ICMP6'))->toBe('icmp')
            ->and(Import::protocolBucket('IPv6-ICMP'))->toBe('icmp')
            ->and(Import::protocolBucket('icmp'))->toBe('icmp')
            ->and(Import::protocolBucket('GRE'))->toBe('other')
            ->and(Import::protocolBucket('TCP'))->toBe('tcp')
        ;
    });
});

describe('FakeProcessor', function (): void {
    test('replays queued raw output in order, then an empty string', function (): void {
        FakeProcessor::reset();
        FakeProcessor::queueRaw('first');
        FakeProcessor::queueRaw('second');
        $raw = static fn (): string => (new FakeProcessor())->execute()['rawOutput'];

        expect([$raw(), $raw(), $raw()])->toBe(['first', 'second', '']);

        FakeProcessor::queueRaw('dropped');
        FakeProcessor::reset();

        expect(FakeProcessor::$rawResponses)->toBe([])
            ->and($raw())->toBe('')
        ;
    });
});
