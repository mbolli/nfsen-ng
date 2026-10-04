<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\GraphActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// normalizeSources()/normalizePorts() read Config::$settings for their fallbacks.
beforeAll(function (): void {
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80, 443],
            'sources' => ['gateway', 'swi6', 'core'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/tmp/test-profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ]);
});

describe('GraphActions::normalizeSources', function (): void {
    test('honours an explicit selection', function (): void {
        expect(GraphActions::normalizeSources(['swi6'], 'sources'))->toBe(['swi6']);
        expect(GraphActions::normalizeSources(['gateway', 'core'], 'sources'))->toBe(['gateway', 'core']);
    });

    // Regression test for issue #160: a malformed client value must never reach
    // the RRD path builder, where '' turns into a bare "<profile>/.rrd" filename.
    test('drops empty entries', function (): void {
        expect(GraphActions::normalizeSources(['', 'swi6'], 'sources'))->toBe(['swi6']);
        expect(GraphActions::normalizeSources([' '], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    test('falls back to all configured sources for an empty selection', function (): void {
        expect(GraphActions::normalizeSources([], 'sources'))->toBe(['gateway', 'swi6', 'core']);
        // (array) null and (array) '' are what Signal::array() yields for a scalar signal
        expect(GraphActions::normalizeSources([''], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    test('expands the "any" sentinel outside the ports view', function (): void {
        expect(GraphActions::normalizeSources(['any'], 'sources'))->toBe(['gateway', 'swi6', 'core']);
        expect(GraphActions::normalizeSources(['any'], 'protocols'))->toBe(['gateway', 'swi6', 'core']);
        expect(GraphActions::normalizeSources(['any', 'core'], 'sources'))->toBe(['gateway', 'swi6', 'core']);
    });

    // In the ports view "any" is a real selection: it maps to the cross-source
    // aggregate RRD (<profile>/<port>.rrd), so it must be preserved.
    test('keeps the "any" sentinel in the ports view', function (): void {
        expect(GraphActions::normalizeSources(['any'], 'ports'))->toBe(['any']);
    });

    test('coerces non-string entries', function (): void {
        expect(GraphActions::normalizeSources([1, 'core'], 'sources'))->toBe(['1', 'core']);
    });
});

describe('GraphActions::normalizePorts', function (): void {
    test('honours an explicit selection', function (): void {
        expect(GraphActions::normalizePorts([443]))->toBe([443]);
        expect(GraphActions::normalizePorts([80, 443]))->toBe([80, 443]);
    });

    // Regression test for issue #160: a <select>'s option values are strings, so the
    // client-writable graph_ports signal can hand back ["80","443"]. Those used to go
    // straight into Rrd::get_data_path(string $source, int $port) and take down the
    // whole ports view with a TypeError.
    test('coerces numeric strings to int', function (): void {
        expect(GraphActions::normalizePorts(['80', '443']))->toBe([80, 443]);
        expect(GraphActions::normalizePorts(['25']))->toBe([25]);
    });

    test('drops entries that are not a usable port', function (): void {
        expect(GraphActions::normalizePorts(['', '443']))->toBe([443]);
        expect(GraphActions::normalizePorts([null, 'any', 443]))->toBe([443]);
        expect(GraphActions::normalizePorts([0, -1, 443]))->toBe([443]);
    });

    test('falls back to all configured ports for an empty selection', function (): void {
        expect(GraphActions::normalizePorts([]))->toBe([80, 443]);
        expect(GraphActions::normalizePorts(['']))->toBe([80, 443]);
    });
});

describe('the Overview graph and the explicit live window', function (): void {
    beforeEach(function (): void {
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;
        $db = new ReflectionProperty(Config::class, 'db');
        $this->dbBefore = $db->isInitialized() ? Config::$db : null;
        $this->series = ['data' => [1_000 => [1.0]], 'start' => 1_000, 'end' => 1_300, 'step' => 300, 'legend' => ['gateway']];
        $stub = $this->createStub(Datasource::class);
        $stub->method('get_graph_data')->willReturn($this->series);
        Config::$db = $stub;
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-graph-actions-test-missing.json';
        putenv('VIA_TEST_MODE=1');
        $this->c = new Context('ctx-graph', '/', new Via(new ViaConfig()));
        Shell::signals($this->c);
        RangeControls::signals($this->c);
        OverviewPage::signals($this->c);
        GraphActions::register($this->c);
        FilteredGraphCache::clear();
    });

    afterEach(function (): void {
        putenv('VIA_TEST_MODE');
        FilteredGraphCache::clear();
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
        if ($this->dbBefore !== null) {
            Config::$db = $this->dbBefore;
        }
    });

    test('change-profile moved to the range actions', function (): void {
        expect(array_keys($this->c->getNamedActions()))->toBe(['run-filtered-graph', 'refresh-graphs']);
    });

    test('refresh-graphs reads no datasource: the render that follows fetches once', function (): void {
        $c = $this->c;
        $calls = 0;
        $stub = $this->createStub(Datasource::class);
        $stub->method('get_graph_data')->willReturnCallback(function () use (&$calls): array {
            ++$calls;

            return $this->series;
        });
        Config::$db = $stub;

        $c->executeAction((string) $c->getAction('refresh-graphs')?->id());
        // Apply in stored mode is a refresh as well.
        $c->executeAction((string) $c->getAction('run-filtered-graph')?->id());

        expect($calls)->toBe(0);
    });

    test('refresh-graphs leaves a live window to the render, which advances it', function (string $mode): void {
        $c = $this->c;
        $c->getSignal('graph_mode')?->setValue($mode);
        $c->getSignal('range_live')?->setValue(true);
        $c->getSignal('datestart')?->setValue(time() - 3_720);
        $c->getSignal('dateend')?->setValue(time() - 120);
        $window = [$c->getSignal('datestart')?->int(), $c->getSignal('dateend')?->int()];

        $c->executeAction((string) $c->getAction('refresh-graphs')?->id());

        expect([$c->getSignal('datestart')?->int(), $c->getSignal('dateend')?->int()])->toBe($window);
    })->with(['stored', 'filtered']);

    test('a stored series is live exactly while the window is', function (bool $live): void {
        $c = $this->c;
        $c->getSignal('range_live')?->setValue($live);
        $c->getSignal('graph_isLive')?->setValue(!$live);

        expect(GraphActions::fetchGraphData($c))->toBe($this->series)
            ->and($c->getSignal('graph_isLive')?->bool())->toBe($live)
        ;
    })->with([true, false]);

    test('a filtered series is never live, and a fetch clears only the graph\'s own banner', function (): void {
        $c = $this->c;
        $c->getSignal('graph_mode')?->setValue('filtered');
        $c->getSignal('range_live')?->setValue(true);
        $series = ['start' => 1_000, 'end' => 1_600, 'step' => 300, 'legend' => ['all'], 'data' => [1_000 => [1.0], 1_300 => [2.0]]];
        FilteredGraphCache::put(GraphActions::filteredKey($c), $series);

        $c->getSignal('_error')?->setValue('Filtered graph: nfdump failed');
        expect(GraphActions::fetchGraphData($c))->toBe($series)
            ->and($c->getSignal('graph_isLive')?->bool())->toBeFalse()
            ->and($c->getSignal('_error')?->string())->toBe('')
        ;

        $c->getSignal('_error')?->setValue('Range: Enter a duration between 1 and 9999.');
        GraphActions::fetchGraphData($c);
        expect($c->getSignal('_error')?->string())->toBe('Range: Enter a duration between 1 and 9999.');
    });

    test('the step of the drawn series is graph_step, 0 without one', function (): void {
        $c = $this->c;
        GraphActions::fetchGraphData($c);
        $withData = $c->getSignal('graph_step')?->int();

        $empty = $this->createStub(Datasource::class);
        $empty->method('get_graph_data')->willReturn(['data' => [], 'start' => 0, 'end' => 0, 'step' => 300, 'legend' => []]);
        Config::$db = $empty;
        GraphActions::fetchGraphData($c);

        expect($withData)->toBe(300)
            ->and($c->getSignal('graph_step')?->int())->toBe(0)
        ;
    });

    test('each display reads its protocols: the global one, or all four for Protocols (4.1.2)', function (string $display, string $protocol, array $expected): void {
        $c = $this->c;
        $seen = [];
        $stub = $this->createStub(Datasource::class);
        $stub->method('get_graph_data')->willReturnCallback(function (int $start, int $end, array $sources, array $protocols, array $ports, string $type, string $shown) use (&$seen): array {
            $seen = ['sources' => $sources, 'protocols' => $protocols, 'type' => $type, 'display' => $shown];

            return $this->series;
        });
        Config::$db = $stub;
        $c->getSignal('graph_display')?->setValue($display);
        $c->getSignal('protocol')?->setValue($protocol);
        $c->getSignal('graph_sources')?->setValue(['swi6', 'core']);

        GraphActions::fetchGraphData($c);

        expect($seen['protocols'])->toBe($expected)
            ->and($seen['display'])->toBe($display)
            // The Display control no longer narrows the global sources (4.1.1).
            ->and($seen['sources'])->toBe(['swi6', 'core'])
            ->and($c->getSignal('graph_sources')?->array())->toBe(['swi6', 'core'])
        ;
    })->with([
        ['sources', 'udp', ['udp']],
        ['sources', 'any', ['any']],
        ['protocols', 'udp', ['tcp', 'udp', 'icmp', 'other']],
        ['ports', 'tcp', ['tcp']],
    ]);

    test('the unit is the datatype, or the global unit for traffic', function (): void {
        expect(GraphActions::unit('traffic', 'bits'))->toBe('bits')
            ->and(GraphActions::unit('traffic', 'bytes'))->toBe('bytes')
            ->and(GraphActions::unit('packets', 'bytes'))->toBe('packets')
            ->and(GraphActions::unit('flows', 'bits'))->toBe('flows')
            ->and(GraphActions::unit('bogus', 'bogus'))->toBe('bits')
        ;
    });

    test('a client-written display or resolution is brought back into range', function (): void {
        $c = $this->c;
        $c->getSignal('graph_display')?->setValue('bogus');
        GraphActions::fetchGraphData($c);

        expect($c->getSignal('graph_display')?->string())->toBe('sources')
            ->and(GraphActions::resolution(0))->toBe(50)
            ->and(GraphActions::resolution(9999))->toBe(2000)
            ->and(GraphActions::resolution(512))->toBe(500)
            ->and(GraphActions::displayProtocols('sources', 'bogus'))->toBe(['any'])
        ;
    });
});
