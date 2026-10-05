<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

function settingsWithMaxWindow(int $maxWindow): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80],
            'sources' => ['gateway'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
            'max_stats_window' => $maxWindow,
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/none',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => ['priority' => LOG_ERR],
    ]);
}

describe('GraphActions::clampFilteredWindow', function (): void {
    // A filtered build re-reads every capture in the range, so an unbounded window is the
    // same open-ended cost Statistics and Sankey already clamp with this setting.
    test('leaves the window alone when no maximum is configured', function (): void {
        settingsWithMaxWindow(0);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 86400 * 400))
            ->toBe([1000, 1000 + 86400 * 400, false])
        ;
    });

    test('leaves a window that already fits', function (): void {
        settingsWithMaxWindow(86400);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 3600))->toBe([1000, 4600, false]);
    });

    test('leaves a window exactly at the limit', function (): void {
        settingsWithMaxWindow(86400);

        expect(GraphActions::clampFilteredWindow(1000, 1000 + 86400))->toBe([1000, 87400, false]);
    });

    // Keep the end and pull the start forward: the recent end of the range is the part
    // someone looking at a graph almost always means.
    test('shortens an over-long window from the start and reports it', function (): void {
        settingsWithMaxWindow(3600);

        expect(GraphActions::clampFilteredWindow(0, 86400))->toBe([86400 - 3600, 86400, true]);
    });

    test('a clamped window is exactly the maximum length', function (): void {
        settingsWithMaxWindow(7200);
        [$start, $end, $clamped] = GraphActions::clampFilteredWindow(0, 999999);

        expect($end - $start)->toBe(7200)
            ->and($clamped)->toBeTrue()
        ;
    });
});

describe('GraphActions::formatWindow', function (): void {
    // A one-hour cap rendered in days rounds to "0 days", which reads as broken.
    test('uses hours below a day', function (): void {
        expect(GraphActions::formatWindow(3600))->toBe('1 hour')
            ->and(GraphActions::formatWindow(7200))->toBe('2 hours')
            ->and(GraphActions::formatWindow(5400))->toBe('1.5 hours')
        ;
    });

    test('uses minutes below an hour', function (): void {
        expect(GraphActions::formatWindow(60))->toBe('1 minute')
            ->and(GraphActions::formatWindow(900))->toBe('15 minutes')
        ;
    });

    test('never reports zero for a non-zero window', function (): void {
        expect(GraphActions::formatWindow(30))->toBe('1 minute');
    });

    test('uses days at a day and above', function (): void {
        expect(GraphActions::formatWindow(86400))->toBe('1 day')
            ->and(GraphActions::formatWindow(86400 * 7))->toBe('7 days')
            ->and(GraphActions::formatWindow((int) (86400 * 2.5)))->toBe('2.5 days')
        ;
    });

    test('trims a trailing .0 so whole values read naturally', function (): void {
        expect(GraphActions::formatWindow(86400 * 3))->toBe('3 days');
    });
});

describe('GraphActions::filteredParams', function (): void {
    beforeEach(function (): void {
        settingsWithMaxWindow(0);
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-graph-filtered-test-missing.json';
        $this->c = new Context('ctx-filtered', '/', new Via(new ViaConfig()));
        Shell::signals($this->c);
        RangeControls::signals($this->c);
        OverviewPage::signals($this->c);
        $this->c->getSignal('graph_mode')?->setValue('filtered');
        $this->c->getSignal('graph_filter')?->setValue('dst port 443');
    });

    afterEach(function (): void {
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
    });

    // 4.1.2: the global protocol narrows the Sources display, stored or filtered alike.
    test('the Sources display composes the global protocol into the filter', function (): void {
        $this->c->getSignal('graph_display')?->setValue('sources');
        $this->c->getSignal('protocol')?->setValue('udp');

        $p = GraphActions::filteredParams($this->c);

        expect($p['filter'])->toBe('(proto udp) and (dst port 443)')
            ->and($p['protocols'])->toBe(['udp'])
            ->and($p['display'])->toBe('sources')
        ;
    });

    test('the Protocols display splits by protocol, so the global one does not apply', function (): void {
        $this->c->getSignal('graph_display')?->setValue('protocols');
        $this->c->getSignal('protocol')?->setValue('udp');

        $p = GraphActions::filteredParams($this->c);

        expect($p['filter'])->toBe('dst port 443')
            ->and($p['protocols'])->toBe(['any'])
        ;
    });

    test('the Ports display falls back to the protocol split: the filter is the port selection', function (): void {
        $this->c->getSignal('graph_display')?->setValue('ports');

        expect(GraphActions::filteredParams($this->c)['display'])->toBe('protocols');
    });

    test('a different protocol is a different cache key, so a build never answers for another', function (): void {
        $this->c->getSignal('protocol')?->setValue('tcp');
        $tcp = GraphActions::filteredKey($this->c);
        $this->c->getSignal('protocol')?->setValue('udp');

        expect(GraphActions::filteredKey($this->c))->not->toBe($tcp);
    });

    test('a filter nfdump will reject stays as typed, so the run names the problem', function (): void {
        expect(GraphActions::composeFilter('tcp', 'port 53) or (port 80'))->toBe('port 53) or (port 80')
            ->and(GraphActions::composeFilter('any', ' host 10.0.0.1 '))->toBe('host 10.0.0.1')
            ->and(GraphActions::composeFilter('bogus', 'port 53'))->toBe('port 53')
        ;
    });
});

describe('run-filtered-graph', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
        $this->processorBefore = isset(Config::$processorClass) ? Config::$processorClass : null;
        foreach (NfdumpSlots::CLASSES as $class) {
            NfdumpSlots::release($class, NfdumpSlots::inUse($class));
        }
        FilteredGraphCache::clear();
        QueryCancel::clearAll();
        $base = 1704067200;

        $this->root = sys_get_temp_dir() . '/nfsen-graph-filtered-' . bin2hex(random_bytes(6));
        $this->day = $this->root . '/live/gateway/2024/01/01';
        mkdir($this->day, 0o777, true);
        for ($i = 0; $i < 12; ++$i) {
            file_put_contents($this->day . '/nfcapd.' . gmdate('YmdHi', $base + $i * 300), 'x');
        }
        Config::$settings = Settings::fromArray([
            'general' => ['ports' => [80], 'sources' => ['gateway'], 'db' => 'Rrd', 'processor' => 'Nfdump'],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 3],
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
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-graph-filtered-test-missing.json';

        putenv('VIA_TEST_MODE=1');
        $c = new Context('ctx-filtered-build', '/', new Via(new ViaConfig()));
        Shell::signals($c);
        RangeControls::signals($c);
        OverviewPage::signals($c);
        GraphActions::register($c);
        $c->getSignal('graph_mode')?->setValue('filtered');
        $c->getSignal('graph_filter')?->setValue('dst port 443');
        $c->getSignal('graph_display')?->setValue('protocols');
        $c->getSignal('graph_sources')?->setValue(['gateway']);
        $c->getSignal('selected_profile')?->setValue('live');
        $c->getSignal('datestart')?->setValue($base);
        $c->getSignal('dateend')?->setValue($base + 12 * 300 - 1);
        $this->c = $c;
        $this->apply = (string) $c->getAction('run-filtered-graph')?->id();
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

    test('Apply runs the bins in parallel and caches the complete series', function (): void {
        $c = $this->c;
        $apply = $this->apply;
        Coroutine::run(static fn () => $c->executeAction($apply));

        expect($this->bins::$peak)->toBe(3)
            ->and($c->getSignal('query_status')?->string())->toStartWith('Done in ')
            ->and($c->getSignal('query_running')?->bool())->toBeFalse()
            ->and(FilteredGraphCache::has(GraphActions::filteredKey($c)))->toBeTrue()
            ->and(FilteredGraphCache::get(GraphActions::filteredKey($c))['data'] ?? [])->toHaveCount(12)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('Kill caches the bins that finished as partial, so the next Apply rebuilds', function (): void {
        $c = $this->c;
        $apply = $this->apply;
        $bins = $this->bins;
        Coroutine::run(static function () use ($c, $apply, $bins): void {
            $c->executeAction($apply);
            while ($bins::$ended < 3) {
                Coroutine::usleep(5_000);
            }
            QueryCancel::request($c->getId());
            NfdumpSlots::kill($c->getId());
        });

        $key = GraphActions::filteredKey($c);
        expect($c->getSignal('query_status')?->string())->toBe('Cancelled, showing partial results. Apply again to finish.')
            ->and(FilteredGraphCache::has($key))->toBeFalse()
            ->and(count(FilteredGraphCache::get($key)['data'] ?? []))->toBeGreaterThanOrEqual(3)->toBeLessThan(12)
        ;

        Coroutine::run(static fn () => $c->executeAction($apply));

        expect(FilteredGraphCache::has($key))->toBeTrue()
            ->and(FilteredGraphCache::get($key)['data'] ?? [])->toHaveCount(12)
        ;
    });
});
