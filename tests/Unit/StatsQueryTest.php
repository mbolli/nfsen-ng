<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\StatsActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\PartitionPlanner;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatisticCatalog;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;

/** A processor that keeps what it was asked for and answers with `$answer`. */
function statsQueryTestProcessor(array $answer = []): Processor {
    return new class($answer) implements Processor {
        /** @var array<string, mixed> */
        public array $options = [];
        public string $filter = '';
        public string $profile = '';
        public string $handle = '';

        public function __construct(private readonly array $answer = []) {}

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
            return $this->answer;
        }
    };
}

/** @param array<string, mixed> $overrides */
function statsQueryTestQuery(array $overrides = []): StatsQuery {
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
        handle: $overrides['handle'] ?? 'ctx-1',
        protocol: $overrides['protocol'] ?? 'any',
        output: $overrides['output'] ?? 'json',
    );
}

/** @return array<string, mixed> StatsParams with the given changes */
function statsQueryTestParams(array $overrides = []): array {
    return [...[
        'element' => 'srcip', 'order' => 'bytes', 'count' => 10, 'filter' => '', 'lower' => '', 'upper' => '',
        'aggregation' => [], 'sources' => ['dmz', 'gw'], 'profile' => 'live', 'protocol' => 'any',
        'start' => 1_000, 'end' => 2_000, 'live' => false,
    ], ...$overrides];
}

const STATS_QUERY_TEST_PROTO_CSV = <<<'CSV'
    ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,TCP,6,11520,53.3,115200,76.2,11520000,78.0,115200,92160000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,UDP,17,5760,26.7,28800,19.0,2880000,19.5,28800,23040000,100
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,ICMP,1,2880,13.3,5760,3.8,288000,1.9,5760,2304000,50
    2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,GRE,47,1440,6.7,1440,1.0,86400,0.6,1440,691200,60
    CSV;

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $processor = new ReflectionProperty(Config::class, 'processorClass');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    $this->processorBefore = $processor->isInitialized() ? Config::$processorClass : null;

    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'dmz'], 'ports' => [], 'max_stats_window' => 0],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/var/nfdump/profiles-data', 'profile' => 'live'],
    ]);
    Config::$processorClass = statsQueryTestProcessor();
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->processorBefore !== null) {
        Config::$processorClass = $this->processorBefore;
    }
});

describe('StatsQuery', function (): void {
    test('takes every element of the catalog and nothing else', function (): void {
        foreach (StatisticCatalog::all() as $entry) {
            expect(statsQueryTestQuery(['for' => $entry['value']])->for)->toBe($entry['value']);
        }

        expect(fn () => statsQueryTestQuery(['for' => 'srcip; rm -rf /']))->toThrow(InvalidArgumentException::class, 'Unknown statistic.')
            ->and(fn () => statsQueryTestQuery(['for' => 'dstport:p']))->toThrow(InvalidArgumentException::class)
        ;
    });

    test('asks for json by default, csv on request, and nothing else', function (): void {
        expect(statsQueryTestQuery()->processor()->options['-o'])->toBe('json')
            ->and(statsQueryTestQuery(['output' => 'csv'])->processor()->options['-o'])->toBe('csv')
            ->and(fn () => statsQueryTestQuery(['output' => 'fmt:%sa']))->toThrow(InvalidArgumentException::class, 'Unknown output, expected one of json, csv.')
        ;
    });

    test('maps the query onto nfdump options, with the protocol and the thresholds in the filter', function (): void {
        $processor = statsQueryTestQuery(['for' => 'dstport', 'orderBy' => 'flows', 'limit' => 50, 'filter' => 'net 10.0.0.0/8', 'lowerLimit' => '1M', 'protocol' => 'udp'])->processor();

        expect($processor->options['-s'])->toBe('dstport/flows')
            ->and($processor->options['-n'])->toBe(50)
            ->and($processor->options['-M'])->toBe('gw:dmz')
            ->and($processor->options['-R'])->toBe([1_000, 2_000])
            ->and($processor->handle)->toBe('ctx-1')
            ->and($processor->filter)->toBe('(bytes > 1M) and (proto udp) and (net 10.0.0.0/8)')
        ;
    });

    test('statRows reads the share column from the raw csv', function (): void {
        $query = statsQueryTestQuery(['for' => 'proto', 'output' => 'csv']);
        $rows = $query->statRows(new QueryResult([], 'nfdump', '', 0.1, $query->window, STATS_QUERY_TEST_PROTO_CSV));

        expect(array_column($rows, 'key'))->toBe(['6', '17', '1', '47'])
            ->and(array_column($rows, 'proto'))->toBe(['TCP', 'UDP', 'ICMP', 'GRE'])
            ->and(array_column($rows, 'bytesPct'))->toBe([78.0, 19.5, 1.9, 0.6])
            ->and($rows[0]['bytes'])->toBe(11_520_000)
            ->and($query->statRows(new QueryResult([], 'nfdump', '', 0.1, $query->window, null)))->toBe([])
            ->and($query->statRows(new QueryResult([], 'nfdump', '', 0.1, $query->window, "No matching flows\nts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n")))->toBe([])
        ;
    });
});

describe('the Top Talkers queries (StatsActions)', function (): void {
    test('a run clamps its window to NFSEN_MAX_STATS_WINDOW and keeps its inputs', function (): void {
        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw'], 'max_stats_window' => 600]]);
        $query = StatsActions::query(statsQueryTestParams(['element' => 'record', 'count' => 20, 'aggregation' => ['proto' => true]]), 'ctx-9');

        expect($query->window->start)->toBe(1_400)
            ->and($query->window->clamped)->toBeTrue()
            ->and($query->for)->toBe('record')
            ->and($query->limit)->toBe(20)
            ->and($query->aggregationString())->toBe('proto')
            ->and($query->handle)->toBe('ctx-9')
        ;
    });

    test('a side panel ranks its own element by bytes in csv, over the same window, sources and filter', function (string $panel, string $element): void {
        $params = statsQueryTestParams(['element' => 'record', 'order' => 'flows', 'count' => 500, 'filter' => 'port 53', 'upper' => '10M', 'protocol' => 'tcp', 'aggregation' => ['bidirectional' => true]]);
        $query = StatsActions::panelQuery($params, $panel, 'ctx-2');
        $processor = $query->processor();

        expect($processor->options['-s'])->toBe($element . '/bytes')
            ->and($processor->options['-n'])->toBe(StatsActions::PANEL_ROWS)
            ->and($processor->options['-o'])->toBe('csv')
            ->and($processor->options)->not->toHaveKeys(['-a', '-B'])
            ->and($processor->filter)->toBe('(bytes < 10M) and (proto tcp) and (port 53)')
            ->and($processor->handle)->toBe('ctx-2')
            ->and($query->window->start)->toBe(1_000)
        ;
    })->with([['proto', 'proto'], ['as', 'as']]);

    test('an unknown panel or statistic is refused before anything runs', function (): void {
        expect(fn () => StatsActions::panelQuery(statsQueryTestParams(), 'geo'))->toThrow(InvalidArgumentException::class, 'Unknown panel.')
            ->and(fn () => StatsActions::query(statsQueryTestParams(['element' => '<b>x</b>'])))->toThrow(InvalidArgumentException::class, 'Unknown statistic.')
            ->and(fn () => StatsActions::query(statsQueryTestParams(['order' => 'tos'])))->toThrow(InvalidArgumentException::class)
        ;
    });
});

/**
 * A capture tree of $intervals 5-minute files per source from 2026-09-29 00:00 UTC; returns its root.
 *
 * @param list<string> $sources
 */
function statsQueryTestTree(array $sources, int $intervals): string {
    $root = sys_get_temp_dir() . '/nfsen-stats-split-' . bin2hex(random_bytes(4));
    foreach ($sources as $source) {
        for ($i = 0; $i < $intervals; ++$i) {
            $ts = 1_790_640_000 + $i * 300;
            $dir = $root . '/live/' . $source . '/' . gmdate('Y/m/d', $ts);
            if (!is_dir($dir)) {
                mkdir($dir, 0o777, true);
            }
            file_put_contents($dir . '/nfcapd.' . gmdate('YmdHi', $ts), 'x');
        }
    }

    return $root;
}

/**
 * A processor that keeps the options and filter of every run and answers with the stat lines
 * $answer returns for them.
 *
 * @param Closure(array<string, mixed>, string): string $answer
 */
function statsQueryTestRecorder(Closure $answer): Processor {
    return new class($answer) implements Processor {
        /** @var list<array{options: array<string, mixed>, filter: string, handle: string}> */
        public static array $runs = [];

        public static ?Closure $answer = null;

        /** @var array<string, mixed> */
        private array $options = [];

        private string $filter = '';

        private string $handle = '';

        public function __construct(?Closure $answer = null) {
            self::$answer = $answer ?? self::$answer;
        }

        public function setOption(string $option, $value): void {
            $this->options[$option] = $value;
        }

        public function setFilter(string $filter): void {
            $this->filter = $filter;
        }

        public function setProfile(string $profile): void {}

        public function setQueryHandle(string $handle): void {
            $this->handle = $handle;
        }

        public function execute(): array {
            self::$runs[] = ['options' => $this->options, 'filter' => $this->filter, 'handle' => $this->handle];

            return ['command' => 'nfdump ' . (is_string($this->options['-R'] ?? null) ? $this->options['-R'] : 'window'), 'rawOutput' => (self::$answer)($this->options, $this->filter), 'decoded' => [], 'exitCode' => 0];
        }
    };
}

/** One line of nfdump's json element statistic. */
function statsQueryTestLine(string $name, string $value, int $bytes, int $proto = 0): string {
    return '{ "first" : "2026-09-29T00:00:00.000", "last" : "2026-09-29T00:05:00.000", "proto" : ' . $proto . ', "' . $name . '" : "' . $value . '", "flows" : 1, "packets" : 1, "bytes" : ' . $bytes . ', "pps" : 0, "bps" : 0, "bpp" : ' . $bytes . '}';
}

describe('StatsQuery in time slices', function (): void {
    beforeEach(function (): void {
        $this->root = statsQueryTestTree(['gw', 'dmz'], 6);
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw', 'dmz'], 'ports' => [], 'max_stats_window' => 0],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 8],
            'log' => ['priority' => LOG_ERR],
        ]);
        PartitionPlanner::useFetchLimit(null);
        $recorder = statsQueryTestRecorder(static fn (array $options, string $filter): string => implode("\n", [
            statsQueryTestLine('srcip', '10.0.0.1', 300),
            statsQueryTestLine('proto', '6', 300, 6),
        ]) . "\n");
        $recorder::$runs = [];
        Config::$processorClass = $recorder;
        $this->window = TimeWindow::raw(1_790_640_000, 1_790_640_000 + 6 * 300 - 1);
    });

    afterEach(function (): void {
        PartitionPlanner::useFetchLimit(null);
        removeTree($this->root);
    });

    test('a part reads its slice in json, listing its share of the line budget, with the protocol list when it needs shares', function (): void {
        $runs = Config::$processorClass::class;
        $result = statsQueryTestQuery(['window' => $this->window, 'filter' => 'port 53', 'output' => 'csv'])->runPartitioned('stats', parts: 2);
        $ranges = ['2026/09/29/nfcapd.202609290000:2026/09/29/nfcapd.202609290010', '2026/09/29/nfcapd.202609290015:2026/09/29/nfcapd.202609290025'];

        expect($result->parts)->toBe(2)
            ->and(array_column(array_column($runs::$runs, 'options'), '-R'))->toBe($ranges)
            ->and($runs::$runs[0]['options'])->toMatchArray(['-M' => 'gw:dmz', '-n' => 10_000, '-o' => 'json', '-s' => ['srcip/bytes', 'proto/bytes']])
            ->and($runs::$runs[0]['filter'])->toBe('port 53')
            ->and($runs::$runs[0]['handle'])->toBe('ctx-1')
            ->and($result->rawOutput)->toBe("ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp\n2026-09-29 00:00:00,2026-09-29 00:05:00,300.000,any,10.0.0.1,2,100.0,2,100.0,600,100.0,0,16,300\n")
            ->and($result->rows[0]['val'] ?? null)->toBe('10.0.0.1')
            ->and($result->partCommands)->toBe(array_map(static fn (string $range): string => 'nfdump ' . $range, $ranges))
            ->and(array_filter($result->notes, static fn (string $note): bool => str_starts_with($note, 'Part ')))->toBe([])
        ;
    });

    test('json needs no protocol list, and -s proto takes its totals from -s dir', function (): void {
        $runs = Config::$processorClass::class;
        statsQueryTestQuery(['window' => $this->window, 'limit' => 200])->runPartitioned('stats', parts: 2);
        statsQueryTestQuery(['window' => $this->window, 'for' => 'proto', 'output' => 'csv'])->runPartitioned('stats', parts: 2);
        statsQueryTestQuery(['window' => $this->window, 'for' => 'dstport', 'limit' => 500])->runPartitioned('stats', parts: 2);

        expect($runs::$runs[0]['options'])->toMatchArray(['-s' => 'srcip/bytes', '-n' => 10_000])
            ->and($runs::$runs[2]['options'])->toMatchArray(['-s' => ['proto/bytes', 'dir/bytes'], '-n' => 10_000])
            ->and($runs::$runs[4]['options'])->toMatchArray(['-s' => 'dstport/bytes', '-n' => 10_000])
            ->and($runs::$runs)->toHaveCount(6)
        ;
    });

    test('Flow Records with -A read their slice in a csv with raw times and the out counters', function (): void {
        $runs = Config::$processorClass::class;
        statsQueryTestQuery(['window' => $this->window, 'for' => 'record', 'aggregation' => ['proto' => true, 'srcip' => 'srcip4', 'srcipPrefix' => '24']])->runPartitioned('stats', parts: 2);

        expect($runs::$runs[0]['options'])->toMatchArray([
            '-s' => 'record/bytes',
            '-a' => '-Aproto,srcip4/24',
            '-o' => 'csv:%ts,%tsr,%ter,%pr,%sa,%ipkt,%ibyt,%opkt,%obyt,%fl',
        ]);
    });

    test('a rate ranking, a 5-tuple, a bidirectional aggregation or a slot the caller holds run as one process', function (): void {
        $runs = Config::$processorClass::class;
        statsQueryTestQuery(['window' => $this->window, 'orderBy' => 'bps'])->runPartitioned('stats', parts: 2);
        statsQueryTestQuery(['window' => $this->window, 'for' => 'record'])->runPartitioned('stats', parts: 2);
        statsQueryTestQuery(['window' => $this->window, 'for' => 'record', 'aggregation' => ['bidirectional' => true]])->runPartitioned('stats', parts: 2);
        NfdumpSlots::acquire(0.0);
        $window = $this->window;

        try {
            NfdumpSlots::runInHeldSlot(NfdumpSlots::INTERACTIVE, static fn (): QueryResult => statsQueryTestQuery(['window' => $window])->runPartitioned('stats', parts: 2));
        } finally {
            NfdumpSlots::release();
        }

        expect(array_column(array_column($runs::$runs, 'options'), '-R'))->toBe(array_fill(0, 4, $this->window->toRangeOption()));
    });

    test('keys a part did not list are looked up in it with a filter on exactly those, under the query\'s own', function (): void {
        PartitionPlanner::useFetchLimit(2);
        $runs = Config::$processorClass::class;
        $runs::$answer = static function (array $options, string $filter): string {
            if (!str_contains($filter, ' in [')) {
                return str_contains((string) $options['-R'], '202609290000')
                    ? statsQueryTestLine('srcip', '10.0.0.1', 100) . "\n" . statsQueryTestLine('srcip', '10.0.0.2', 50) . "\n"
                    : statsQueryTestLine('srcip', '10.0.0.3', 90) . "\n" . statsQueryTestLine('srcip', '10.0.0.4', 40) . "\n";
            }

            return '';
        };

        $result = statsQueryTestQuery(['window' => $this->window, 'limit' => 1, 'protocol' => 'tcp'])->runPartitioned('stats', parts: 2);

        expect(array_column(array_slice($runs::$runs, 2), 'filter'))->toBe(['(proto tcp) and (src ip in [10.0.0.3])', '(proto tcp) and (src ip in [10.0.0.1])'])
            ->and(array_column(array_column(array_slice($runs::$runs, 2), 'options'), '-n'))->toBe([0, 0])
            ->and(array_column($result->rows, 'srcip'))->toBe(['10.0.0.1'])
            ->and($result->parts)->toBe(2)
        ;
    });
});
