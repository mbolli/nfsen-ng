<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowGraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\FlowsPage;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

describe('FlowGraphActions::effectiveFilter()', function (): void {
    test('passes a plain filter through', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto tcp and dst port 443', '', ''))
            ->toBe('proto tcp and dst port 443')
        ;
    });

    // The table prepends its byte thresholds to the query it runs, so the graph has to plot
    // the same expression or it draws more traffic than the table lists.
    test('includes the byte thresholds the table applies', function (): void {
        expect(FlowGraphActions::effectiveFilter('dst port 22', '1M', ''))->toBe('(bytes > 1M) and (dst port 22)');
    });

    test('uses the thresholds alone when there is no filter text', function (): void {
        expect(FlowGraphActions::effectiveFilter('', '1M', '100M'))->toBe('bytes > 1M and bytes < 100M');
    });

    test('ignores a malformed threshold rather than passing it to nfdump', function (): void {
        expect(FlowGraphActions::effectiveFilter('proto udp', 'not-a-size', ''))->toBe('proto udp');
    });

    test('trims stray whitespace from the filter box', function (): void {
        expect(FlowGraphActions::effectiveFilter('   host 10.0.0.1   ', '', ''))->toBe('host 10.0.0.1');
    });

    // `bytes > 1M and dst port 80 or dst port 443` plotted every flow to 443, whatever its size.
    test('keeps an or in the filter from escaping the threshold', function (): void {
        expect(FlowGraphActions::effectiveFilter('dst port 80 or dst port 443', '1M', ''))
            ->toBe('(bytes > 1M) and (dst port 80 or dst port 443)')
        ;
    });

    test('adds the global protocol', function (): void {
        expect(FlowGraphActions::effectiveFilter('port 53', '', '', 'udp'))->toBe('(proto udp) and (port 53)')
            ->and(FlowGraphActions::effectiveFilter('', '', '', 'icmp'))->toBe('proto icmp or proto icmp6')
        ;
    });

    // The value comes from a client-writable signal and this runs on every render.
    test('reads an unknown protocol as any instead of failing', function (): void {
        expect(FlowGraphActions::effectiveFilter('port 53', '', '', 'bogus'))->toBe('port 53');
    });

    // params() catches this on render and the build refuses the filter.
    test('refuses a filter whose parenthesis would close the wrapper', function (): void {
        expect(fn () => FlowGraphActions::effectiveFilter('port 53) or (port 80', '1M', '', 'udp'))
            ->toThrow(InvalidArgumentException::class, 'Unbalanced parentheses in the filter.')
        ;
    });

    test('plots exactly the filter the flow table runs', function (): void {
        Config::$settings = Settings::fromArray(mockSettings());

        foreach (['any', 'tcp', 'other'] as $protocol) {
            $query = new FlowsQuery(
                window: TimeWindow::raw(0, 300),
                sources: ['gateway'],
                profile: 'live',
                limit: 10,
                filter: 'dst port 80 or dst port 443',
                lowerLimit: '1k',
                upperLimit: '1G',
                protocol: $protocol,
            );

            expect(FlowGraphActions::effectiveFilter('dst port 80 or dst port 443', '1k', '1G', $protocol))
                ->toBe($query->effectiveFilter())
            ;
        }
    });
});

describe('FlowGraphActions::cost()', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        Config::$settings = Settings::fromArray(mockSettings());
        $c = new Context('ctx-flow-graph-cost', '/', new Via(new ViaConfig()));
        $c->signal(1_700_000_000, 'datestart');
        $c->signal(1_700_086_400, 'dateend');
        $c->signal('live', 'selected_profile');
        $c->signal('', 'flows_filter');
        $c->signal('', 'flows_lower_limit');
        $c->signal('', 'flows_upper_limit');
        $c->signal(['gateway'], 'graph_sources');
        $c->signal('bytes', 'flows_graph_unit');
        $this->c = $c;
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
    });

    // A render must not walk the capture tree: the files and bytes are the Flows estimate's.
    test('reads the files and bytes from the Flows estimate, the runs from arithmetic', function (): void {
        $this->c->signal([...QueryKit::ESTIMATE_DEFAULT, 'pending' => false, 'files' => 288, 'bytes' => 3_000_000, 'bytesHuman' => '2.9 MiB', 'window' => '1 day'], QueryKit::estimateSignal('flows'));

        expect(FlowGraphActions::cost($this->c))->toMatchArray([
            'files' => 288,
            'bytes' => '2.9 MiB',
            'estimated' => true,
            'clamped' => false,
        ])
            ->and(FlowGraphActions::cost($this->c)['intervals'])->toBeGreaterThan(0)
        ;
    });

    test('says nothing about files before the estimate has answered', function (): void {
        $this->c->signal(QueryKit::ESTIMATE_DEFAULT, QueryKit::estimateSignal('flows'));

        expect(FlowGraphActions::cost($this->c))->toMatchArray(['files' => 0, 'bytes' => '', 'estimated' => false]);
    });
});

describe('build-flows-graph', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        $this->processorBefore = isset(Config::$processorClass) ? Config::$processorClass : null;
        foreach (NfdumpSlots::CLASSES as $class) {
            NfdumpSlots::release($class, NfdumpSlots::inUse($class));
        }
        FilteredGraphCache::clear();
        QueryCancel::clearAll();
        $this->base = 1704067200;

        $this->root = sys_get_temp_dir() . '/nfsen-flow-graph-' . bin2hex(random_bytes(6));
        $this->day = $this->root . '/live/gateway/2024/01/01';
        mkdir($this->day, 0o777, true);
        for ($i = 0; $i < 12; ++$i) {
            file_put_contents($this->day . '/nfcapd.' . gmdate('YmdHi', $this->base + $i * 300), 'x');
        }
        Config::$settings = Settings::fromArray([
            'general' => ['ports' => [80], 'sources' => ['gateway'], 'db' => 'Rrd', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 4],
            'log' => ['priority' => LOG_ERR],
        ]);
        // Bins that take 30 ms, so they overlap, and count how many ran at once and how many ended.
        Config::$processorClass = $this->bins = new class implements Processor {
            public static int $running = 0;

            public static int $peak = 0;

            public static int $ended = 0;

            public function setOption(string $option, $value): void {}

            public function setFilter(string $filter): void {}

            public function setQueryHandle(string $handle): void {}

            public function setProfile(string $profile): void {}

            public function execute(): array {
                self::$peak = max(self::$peak, ++self::$running);

                try {
                    Coroutine::usleep(30_000);
                } finally {
                    --self::$running;
                    ++self::$ended;
                }

                return ['command' => 'slow', 'rawOutput' => '', 'decoded' => [['pr' => 'TCP', 'fl' => '300', 'ipkt' => '0', 'ibyt' => '0']], 'notes' => [], 'exitCode' => 0];
            }
        };
        $this->bins::$running = $this->bins::$peak = $this->bins::$ended = 0;

        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-flow-graph-test-missing.json';

        putenv('VIA_TEST_MODE=1');
        $c = new Context('ctx-flow-graph-build', '/', new Via(new ViaConfig()));
        Shell::signals($c);
        RangeControls::signals($c);
        FlowsPage::signals($c);
        FlowGraphActions::register($c);
        $c->getSignal('datestart')?->setValue($this->base);
        $c->getSignal('dateend')?->setValue($this->base + 12 * 300 - 1);
        $c->getSignal('graph_sources')?->setValue(['gateway']);
        $c->getSignal('selected_profile')?->setValue('live');
        $c->getSignal('flows_filter')?->setValue('port 443');
        $this->c = $c;
        $this->build = (string) $c->getAction('build-flows-graph')?->id();
    });

    afterEach(function (): void {
        putenv('VIA_TEST_MODE');
        foreach (NfdumpSlots::CLASSES as $class) {
            NfdumpSlots::release($class, NfdumpSlots::inUse($class));
        }
        FilteredGraphCache::clear();
        QueryCancel::clearAll();
        array_map(unlink(...), glob($this->day . '/*') ?: []);
        for ($dir = $this->day; $dir !== dirname($this->root); $dir = dirname($dir)) {
            rmdir($dir);
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->processorBefore !== null) {
            Config::$processorClass = $this->processorBefore;
        }
    });

    test('runs the bins in parallel, with the same progress, status and cache as before', function (): void {
        $c = $this->c;
        $statuses = [];
        Coroutine::run(static function () use ($c, &$statuses): void {
            $c->executeAction((string) $c->getAction('build-flows-graph')?->id());
            while ($c->getSignal('query_running')?->bool()) {
                $statuses[] = $c->getSignal('query_status')?->string();
                Coroutine::usleep(5_000);
            }
        });

        $key = $c->getSignal('flows_graph_key')?->string() ?? '';
        expect($this->bins::$peak)->toBe(4)
            ->and(preg_grep('#^Scanning \d+ / 12 intervals$#', $statuses))->not->toBe([])
            ->and($c->getSignal('query_status')?->string())->toStartWith('Done in ')
            ->and($c->getSignal('query_permille')?->int())->toBe(1000)
            ->and($c->getSignal('query_kind')?->string())->toBe('flowsgraph')
            ->and(FilteredGraphCache::has($key))->toBeTrue()
            ->and(FlowGraphActions::cached($c)['data'] ?? [])->toHaveCount(12)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('Kill keeps the bins that finished as a partial series, and Build again finishes it', function (): void {
        $c = $this->c;
        $build = $this->build;
        $bins = $this->bins;
        Coroutine::run(static function () use ($c, $build, $bins): void {
            $c->executeAction($build);
            while ($bins::$ended < 3) {
                Coroutine::usleep(5_000);
            }
            // What the kill-nfdump action does.
            QueryCancel::request($c->getId());
            NfdumpSlots::kill($c->getId());
        });

        $key = $c->getSignal('flows_graph_key')?->string() ?? '';
        $partial = FilteredGraphCache::get($key);
        expect($c->getSignal('query_status')?->string())->toBe('Cancelled: showing partial results. Build again to finish.')
            ->and($c->getSignal('query_running')?->bool())->toBeFalse()
            ->and(FilteredGraphCache::has($key))->toBeFalse()
            ->and(count($partial['data'] ?? []))->toBeGreaterThanOrEqual(3)->toBeLessThan(12)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        Coroutine::run(static fn () => $c->executeAction($build));

        expect(FilteredGraphCache::has($key))->toBeTrue()
            ->and(FilteredGraphCache::get($key)['data'] ?? [])->toHaveCount(12)
            ->and($c->getSignal('query_status')?->string())->toStartWith('Done in ')
        ;
    });
});
