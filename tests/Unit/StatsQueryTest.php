<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\StatsQuery;
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
