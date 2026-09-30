<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\FilteredSeries;
use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\CostEstimate;
use mbolli\nfsen_ng\query\TimeWindow;
use OpenSwoole\Coroutine;
use Tests\Support\FakeProcessor;

/**
 * A processor whose runs take a while and answer (minute of the day + 1) TCP flows/s, so each
 * value names its bin. Every bin has its own instance, so the state is static: slowBins()::$started.
 */
function slowBins(): Processor {
    static $processor = null;

    return $processor ??= new class implements Processor {
        /** Seconds a run takes unless the delays say otherwise. */
        public static float $seconds = 0.03;

        /** @var array<string, float> seconds by the first file of a bin */
        public static array $delays = [];

        /** The first file of the bin whose run throws an Error. */
        public static string $failOn = '';

        public static int $running = 0;

        /** @var list<int> runs in flight as each run started, itself included */
        public static array $concurrency = [];

        /** @var list<string> first file of each run, in the order they started */
        public static array $started = [];

        /** @var list<string> first file of each run, in the order they ended */
        public static array $ended = [];

        /** Whether every run found its worker's slot held for it, and so took none of its own. */
        public static bool $inHeldSlot = true;

        /** @var array<string, mixed> */
        private array $options = [];

        public static function reset(): void {
            self::$seconds = 0.03;
            self::$delays = [];
            self::$failOn = '';
            self::$running = 0;
            self::$concurrency = [];
            self::$started = [];
            self::$ended = [];
            self::$inHeldSlot = true;
        }

        public static function peak(): int {
            return self::$concurrency === [] ? 0 : max(self::$concurrency);
        }

        public function setOption(string $option, $value): void {
            $this->options[$option] = $value;
        }

        public function setFilter(string $filter): void {}

        public function setQueryHandle(string $handle): void {}

        public function setProfile(string $profile): void {}

        public function execute(): array {
            $file = (string) ($this->options['-r'] ?? strstr((string) $this->options['-R'], ':', true));
            self::$started[] = $file;
            self::$inHeldSlot = self::$inHeldSlot && NfdumpSlots::scope()['held'];
            self::$concurrency[] = ++self::$running;

            try {
                Coroutine::usleep((int) ((self::$delays[$file] ?? self::$seconds) * 1_000_000));
            } finally {
                --self::$running;
                self::$ended[] = $file;
            }

            if ($file === self::$failOn) {
                throw new Error('bin exploded');
            }
            $minute = (int) substr($file, -4, 2) * 60 + (int) substr($file, -2);

            return ['command' => 'slow', 'rawOutput' => '', 'decoded' => [statRow('TCP', ($minute + 1) * 300, 0, 0)], 'notes' => [], 'exitCode' => 0];
        }
    };
}

/**
 * $count 5-minute captures from $base, slowBins() as the processor and $maxProcesses slots.
 *
 * @return array{0: string, 1: list<int>} the capture root and the capture timestamps
 */
function withSlowNfdump(int $base, int $count, int $maxProcesses): array {
    $timestamps = array_map(static fn (int $i): int => $base + $i * 300, range(0, $count - 1));
    $root = withFakeNfdump(['gateway'], $timestamps);
    Config::$settings = Config::$settings->withNfdumpMaxProcesses($maxProcesses);
    Config::$processorClass = slowBins();
    slowBins()::reset();

    return [$root, $timestamps];
}

/** The capture file of $ts, as runBin() passes it. */
function slowBinFile(int $ts): string {
    return gmdate('Y/m/d', $ts) . '/nfcapd.' . gmdate('YmdHi', $ts);
}

/**
 * An nfdump that prints one `-s proto -o csv` TCP row after 0.05 s, or after 5 s for arguments
 * matching the glob in $NFSEN_STUB_SLOW; SIGTERM ends the wait at once.
 */
function stubNfdumpBinary(string $dir): string {
    $bin = $dir . '/nfdump-stub';
    file_put_contents($bin, <<<'SH'
        #!/bin/sh
        [ "$1" = "-V" ] && { echo 'nfdump: Version: 1.7.8-release'; exit 0; }
        d=0.05
        case "$*" in $NFSEN_STUB_SLOW) d=5 ;; esac
        sleep "$d" >/dev/null 2>&1 &
        wait $!
        echo 'ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ibps,ibpp'
        echo '2024-01-01 00:00:00,2024-01-01 00:05:00,300.000,TCP,6,300,100.0,3000,100.0,30000,100.0,10,800,100'
        SH);
    chmod($bin, 0o755);

    return $bin;
}

function freeAllSlots(): void {
    foreach (NfdumpSlots::CLASSES as $class) {
        NfdumpSlots::release($class, NfdumpSlots::inUse($class));
    }
    foreach (NfdumpSlots::running() as $handle => $pids) {
        foreach ($pids as $pid) {
            NfdumpSlots::unregister($handle, $pid);
        }
    }
}

/** One nfdump `-s proto` CSV row, as Nfdump::execute() decodes it. */
function statRow(string $proto, int $flows, int $packets, int $bytes): array {
    return [
        'ts' => '2024-01-01 00:00:00',
        'te' => '2024-01-01 00:05:00',
        'td' => '300.000',
        'pr' => $proto,
        'val' => $proto,
        'fl' => (string) $flows,
        'flP' => '100.0',
        'ipkt' => (string) $packets,
        'ipktP' => '100.0',
        'ibyt' => (string) $bytes,
        'ibytP' => '100.0',
    ];
}

/** Capture tree + fake processor, wired into Config. */
function withFakeNfdump(array $sources, array $timestamps): string {
    $root = makeCaptureTree($sources, $timestamps);
    Config::$settings = Settings::fromArray([
        'general' => [
            'ports' => [80],
            'sources' => $sources,
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => $root,
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$processorClass = new FakeProcessor();
    FakeProcessor::reset();

    return $root;
}

describe('FilteredSeries::binWidth', function (): void {
    test('never goes below the 5-minute nfcapd rotation', function (): void {
        expect(FilteredSeries::binWidth(0, 600, 1000))->toBe(300);
    });

    test('always lands on a whole multiple of the rotation interval', function (): void {
        $step = FilteredSeries::binWidth(0, 86400, 137);
        expect($step % 300)->toBe(0);
    });

    test('widens to honour the requested point count', function (): void {
        // 24 h across 48 points => 1800 s bins
        expect(FilteredSeries::binWidth(0, 86400, 48))->toBe(1800);
    });

    test('caps the projected nfdump run count', function (): void {
        // 30 days at 5-minute resolution would be 8640 runs; must be widened.
        $span = 30 * 86400;
        $step = FilteredSeries::binWidth(0, $span, 10000);
        expect((int) ceil($span / $step))->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
    });

    test('accounts for per-source runs when display multiplies the work', function (): void {
        $span = 7 * 86400;
        $step = FilteredSeries::binWidth(0, $span, 10000, groupCount: 4);
        expect((int) ceil($span / $step) * 4)->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
    });
});

describe('FilteredSeries::build', function (): void {
    $base = 1704067200; // 2024-01-01 00:00 UTC

    test('throws when the range holds no capture files', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);

        expect(fn () => FilteredSeries::build($base + 86400, $base + 90000, ['gateway'], 'proto tcp'))
            ->toThrow(Exception::class, 'No nfcapd files found')
        ;

        removeTree($root);
    });

    test('invokes nfdump with -s proto and an explicit -n 0', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 10, 100, 1000)];

        FilteredSeries::build($base, $base + 299, ['gateway'], 'proto tcp', profile: 'live');

        $options = FakeProcessor::callOptions();
        expect($options['-s'])->toBe('proto')
            // nfdump defaults -n to 10 for statistics; without this the bin undercounts.
            ->and($options['-n'])->toBe(0)
            ->and(FakeProcessor::$calls[0]['filter'])->toBe('proto tcp')
        ;

        removeTree($root);
    });

    test('passes the bin file range as an already-resolved -R pair', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [];

        // One 900 s bin covering all three files.
        FilteredSeries::build($base, $base + 899, ['gateway'], '', targetPoints: 1);

        expect(FakeProcessor::callOptions()['-R'])
            ->toBe('2024/01/01/nfcapd.202401010000:2024/01/01/nfcapd.202401010010')
        ;

        removeTree($root);
    });

    // nfdump reads a single-path -R as a prefix match, so a one-file bin uses -r instead.
    test('uses -r, not -R, when a bin holds exactly one file', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [];

        FilteredSeries::build($base, $base + 299, ['gateway'], '');

        expect(FakeProcessor::callOptions()['-r'])->toBe('2024/01/01/nfcapd.202401010000')
            ->and(FakeProcessor::callOptions())->not->toHaveKey('-R')
        ;

        removeTree($root);
    });

    test('converts per-bin totals into per-second rates', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 600, 3000, 30000)];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        // 600 flows over a 300 s bin => 2 flows/s, in the tcp series (index 0).
        expect($series['step'])->toBe(300)
            ->and($series['data'][$base][0])->toBe(2.0)
        ;

        removeTree($root);
    });

    test('multiplies bytes by eight for the bits unit', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 1, 1, 30000)];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'bits');

        // 30000 bytes * 8 / 300 s = 800 bits/s
        expect($series['data'][$base][0])->toBe(800.0);

        removeTree($root);
    });

    test('splits counters across the tcp/udp/icmp/other series', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
            statRow('ICMP', 900, 0, 0),
            statRow('GRE', 1200, 0, 0),
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['legend'])->toBe([
            'tcp_flows_gateway', 'udp_flows_gateway', 'icmp_flows_gateway', 'other_flows_gateway',
        ])->and($series['data'][$base])->toBe([1.0, 2.0, 3.0, 4.0]);

        removeTree($root);
    });

    test('folds every unrecognised protocol into "other"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('GRE', 300, 0, 0),
            statRow('ESP', 600, 0, 0),
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['data'][$base][3])->toBe(3.0);   // (300 + 600) / 300

        removeTree($root);
    });

    test('emits one series per source for the sources display', function () use ($base): void {
        $root = withFakeNfdump(['gateway', 'swi6'], [$base]);
        FakeProcessor::$responses = [
            [statRow('TCP', 300, 0, 0)],   // gateway
            [statRow('TCP', 900, 0, 0)],   // swi6
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway', 'swi6'],
            '',
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_any', 'swi6_flows_any'])
            ->and($series['data'][$base])->toBe([1.0, 3.0])
            // one nfdump run per source, each scoped to that source alone
            ->and(FakeProcessor::$calls)->toHaveCount(2)
            ->and(FakeProcessor::callOptions(0)['-M'])->toBe('gateway')
            ->and(FakeProcessor::callOptions(1)['-M'])->toBe('swi6')
        ;

        removeTree($root);
    });

    test('merges sources in a single nfdump run for the protocols display', function () use ($base): void {
        $root = withFakeNfdump(['gateway', 'swi6'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        FilteredSeries::build($base, $base + 299, ['gateway', 'swi6'], '', display: 'protocols');

        expect(FakeProcessor::$calls)->toHaveCount(1)
            ->and(FakeProcessor::callOptions()['-M'])->toBe('gateway:swi6')
        ;

        removeTree($root);
    });

    // A bin with no captures must still occupy a row: omitting it leaves no point at that
    // timestamp and ECharts draws straight across, so a collection outage looked like
    // steady traffic. Stored mode shows a real gap for the same window.
    test('emits bins without capture files as null rows, not as missing rows', function () use ($base): void {
        // Files at 00:00 and 00:10: the 00:05 bin has none.
        $root = withFakeNfdump(['gateway'], [$base, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build($base, $base + 899, ['gateway'], '', unit: 'flows');

        expect(array_keys($series['data']))->toBe([$base, $base + 300, $base + 600])
            ->and($series['data'][$base + 300])->toBe([null, null, null, null])
            ->and($series['data'][$base][0])->toBe(1.0)
            // nfdump is still not run for a bin with no files at all
            ->and(FakeProcessor::$calls)->toHaveCount(2)
        ;

        removeTree($root);
    });

    // nfdump ran and matched nothing: that is a real zero, not absence of data.
    test('reports zeros when nfdump ran and matched nothing', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], 'proto tcp', unit: 'flows');

        expect($series['data'][$base])->toBe([0.0, 0.0, 0.0, 0.0]);

        removeTree($root);
    });

    // A failed invocation is a gap, not a zero: reporting 0 would render a truncated
    // capture or an nfdumpMaxProcesses rejection as "no traffic here".
    test('keeps going when a bin fails, and leaves it a gap rather than a zero', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300]);
        FakeProcessor::$throw = new Exception('nfdump exploded');

        $series = FilteredSeries::build($base, $base + 599, ['gateway'], '', unit: 'flows');

        expect($series['data'])->toHaveCount(2)
            ->and($series['data'][$base])->toBe([null, null, null, null])
            ->and($series['data'][$base + 300])->toBe([null, null, null, null])
        ;

        removeTree($root);
    });

    // The first bin starts at the floored window start, so the capture covering it must be
    // included; listing from the raw start dropped it and under-reported that bin.
    test('includes the capture covering the first partial bin', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        // Window starts 2 minutes after the capture at $base; the bin still starts at $base.
        $series = FilteredSeries::build($base + 120, $base + 299, ['gateway'], '', unit: 'flows');

        expect($series['data'][$base][0])->toBe(1.0)
            ->and(FakeProcessor::$calls)->toHaveCount(1)
        ;

        removeTree($root);
    });

    test('reports progress up to the total run count', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 1, 1, 1)];

        $seen = [];
        FilteredSeries::build(
            $base,
            $base + 899,
            ['gateway'],
            '',
            targetPoints: 3,
            onProgress: function (int $done, int $total) use (&$seen): void { $seen[] = [$done, $total]; }
        );

        expect($seen)->toBe([[1, 3], [2, 3], [3, 3]]);

        removeTree($root);
    });

    // The Flows panel said '144 nfdump runs' while the build counted 145 intervals over 7 days.
    test('lays out as many bins as the cost estimate counts', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 1, 1, 1)];

        foreach ([[$base + 120, $base + 120 + 7 * 86400, 150], [$base, $base + 899, 3], [$base + 7, $base + 86400, 288]] as [$start, $end, $points]) {
            $total = 0;
            FilteredSeries::build($start, $end, ['gateway'], '', targetPoints: $points, onProgress: function (int $done, int $all) use (&$total): void {
                $total = $all;
            });

            expect($total)->toBe(CostEstimate::runsForFilteredSeries(TimeWindow::raw($start, $end), $points));
        }

        removeTree($root);
    });

    test('stops early and returns partial data when cancelled', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build(
            $base,
            $base + 899,
            ['gateway'],
            '',
            targetPoints: 3,
            shouldCancel: static fn (): bool => count(FakeProcessor::$calls) >= 2,
        );

        // Cancelled before the third bin ran: two complete bins survive.
        expect(FakeProcessor::$calls)->toHaveCount(2)
            ->and($series['data'])->toHaveCount(2)
        ;

        removeTree($root);
    });

    test('a cancelled sources display keeps only the bins every source ran for', function () use ($base): void {
        $root = withFakeNfdump(['gateway', 'swi6'], [$base, $base + 300, $base + 600]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build(
            $base,
            $base + 899,
            ['gateway', 'swi6'],
            '',
            unit: 'flows',
            display: 'sources',
            targetPoints: 3,
            shouldCancel: static fn (): bool => count(FakeProcessor::$calls) >= 3,
        );

        // The second bin ran for gateway only, so it is dropped rather than half drawn.
        expect(FakeProcessor::$calls)->toHaveCount(3)
            ->and($series['data'])->toBe([$base => [1.0, 1.0]])
        ;

        removeTree($root);
    });
});

describe('FilteredSeries::normalizeProtocolSelection', function (): void {
    test('an empty selection means no restriction', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection([]))->toBe(['any']);
    });

    test('"any" anywhere wins over explicit protocols', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['tcp', 'any']))->toBe(['any']);
    });

    test('unrecognised entries are dropped', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['tcp', 'sctp', 'nonsense']))->toBe(['tcp']);
    });

    test('a selection of only unrecognised entries means no restriction', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['sctp']))->toBe(['any']);
    });

    // The legend is built from this, so click order must not reorder the series.
    test('canonical order is restored regardless of input order', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['other', 'udp', 'tcp']))
            ->toBe(['tcp', 'udp', 'other'])
        ;
    });

    test('case is ignored', function (): void {
        expect(FilteredSeries::normalizeProtocolSelection(['TCP', 'Udp']))->toBe(['tcp', 'udp']);
    });
});

describe('FilteredSeries protocol selection', function (): void {
    $base = 1704067200;

    // Regression: the Protocols buttons used to be inert in filtered mode: every
    // selection produced the same four series.
    test('protocols display emits only the selected series', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
            statRow('ICMP', 900, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['udp', 'icmp'],
            unit: 'flows',
            display: 'protocols'
        );

        expect($series['legend'])->toBe(['udp_flows_gateway', 'icmp_flows_gateway'])
            ->and($series['data'][$base])->toBe([2.0, 3.0])
        ;

        removeTree($root);
    });

    test('protocols display falls back to all four for "any"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [statRow('TCP', 300, 0, 0)];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'protocols'
        );

        expect($series['legend'])->toHaveCount(4);

        removeTree($root);
    });

    // Mirrors Rrd::get_graph_data(), which indexes $protocols[0] for the sources display.
    test('sources display narrows every series to the chosen protocol', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['udp'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_udp'])
            ->and($series['data'][$base])->toBe([2.0])   // udp only, not udp+tcp
        ;

        removeTree($root);
    });

    test('sources display sums every protocol for "any"', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            statRow('UDP', 600, 0, 0),
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['legend'])->toBe(['gateway_flows_any'])
            ->and($series['data'][$base])->toBe([3.0])   // 900 flows / 300 s
        ;

        removeTree($root);
    });
});

describe('FilteredSeries row guards', function (): void {
    $base = 1704067200;

    // nfdump before 1.7.8 prints a trailing Summary block in CSV stat output: it has a
    // value in the 'pr' column but no counters, and keying on 'pr' alone let its numbers
    // land in whichever series matched. Import::writePortData() checks all four keys.
    test('ignores a summary row that carries no counters', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            ['pr' => 'Summary: total flows', 'val' => '99999'],   // no fl/ipkt/ibyt
        ];

        $series = FilteredSeries::build($base, $base + 299, ['gateway'], '', ['any'], unit: 'flows');

        // tcp only; nothing leaked into "other"
        expect($series['data'][$base])->toBe([1.0, 0.0, 0.0, 0.0]);

        removeTree($root);
    });

    test('a summary row does not inflate the sources-display total', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base]);
        FakeProcessor::$defaultResponse = [
            statRow('TCP', 300, 0, 0),
            ['pr' => 'Summary', 'val' => '1', 'fl' => '99999'],   // still missing ipkt/ibyt
        ];

        $series = FilteredSeries::build(
            $base,
            $base + 299,
            ['gateway'],
            '',
            ['any'],
            unit: 'flows',
            display: 'sources'
        );

        expect($series['data'][$base])->toBe([1.0]);

        removeTree($root);
    });
});

describe('FilteredSeries::binWidth termination', function (): void {
    // graph_sources is client-writable: a source list longer than MAX_RUNS used to spin the worker forever.
    test('terminates when the group count alone exceeds the run ceiling', function (): void {
        $step = FilteredSeries::binWidth(0, 86400, 500, FilteredSeries::MAX_RUNS + 1);

        expect($step)->toBeGreaterThanOrEqual(FilteredSeries::MIN_BIN);
    });

    test('terminates for an absurd group count', function (): void {
        expect(FilteredSeries::binWidth(0, 86400, 500, 100000))
            ->toBeGreaterThanOrEqual(FilteredSeries::MIN_BIN)
        ;
    });

    test('still respects the ceiling for reasonable group counts', function (): void {
        $span = 7 * 86400;
        foreach ([1, 2, 4, 8] as $groups) {
            $runs = (int) ceil($span / FilteredSeries::binWidth(0, $span, 10000, $groups)) * $groups;
            expect($runs)->toBeLessThanOrEqual(FilteredSeries::MAX_RUNS);
        }
    });
});

describe('FilteredSeries::build in a coroutine', function (): void {
    $base = 1704067200;

    beforeEach(function (): void {
        freeAllSlots();
    });

    afterEach(function (): void {
        freeAllSlots();
        slowBins()::reset();
    });

    test('runs as many bins at once as there are slots, each in a slot its worker holds', function () use ($base): void {
        [$root, $timestamps] = withSlowNfdump($base, 12, maxProcesses: 4);

        $series = null;
        Coroutine::run(static function () use ($base, &$series): void {
            $series = FilteredSeries::build($base, $base + 12 * 300 - 1, ['gateway'], 'port 443', targetPoints: 100);
        });

        expect(slowBins()::peak())->toBe(4)
            ->and(slowBins()::$inHeldSlot)->toBeTrue()
            ->and(slowBins()::$started)->toHaveCount(12)
            ->and(NfdumpSlots::inUse())->toBe(0)
            ->and(array_keys($series['data']))->toBe($timestamps)
        ;
        foreach ($timestamps as $i => $ts) {
            expect($series['data'][$ts])->toBe([(float) ($i * 5 + 1), 0.0, 0.0, 0.0]);
        }

        removeTree($root);
    });

    test('writes every result to its own bin when later bins finish first', function () use ($base): void {
        [$root, $timestamps] = withSlowNfdump($base, 6, maxProcesses: 6);
        foreach ($timestamps as $i => $ts) {
            slowBins()::$delays[slowBinFile($ts)] = 0.15 - $i * 0.02;
        }

        $series = null;
        Coroutine::run(static function () use ($base, &$series): void {
            $series = FilteredSeries::build($base, $base + 6 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect(slowBins()::$ended)->toBe(array_reverse(slowBins()::$started))
            ->and(array_keys($series['data']))->toBe($timestamps)
            ->and(array_column($series['data'], 0))->toBe([1.0, 6.0, 11.0, 16.0, 21.0, 26.0])
        ;

        removeTree($root);
    });

    test('takes slots that free up while it runs', function () use ($base): void {
        [$root] = withSlowNfdump($base, 16, maxProcesses: 4);
        NfdumpSlots::acquireMany(3);

        Coroutine::run(static function () use ($base): void {
            Coroutine::create(static function (): void {
                Coroutine::usleep(80_000);
                NfdumpSlots::release(NfdumpSlots::INTERACTIVE, 3);
            });
            FilteredSeries::build($base, $base + 16 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect(slowBins()::$concurrency[0])->toBe(1)
            ->and(slowBins()::peak())->toBe(4)
            ->and(slowBins()::$started)->toHaveCount(16)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('near the end, takes a freed slot for every job not yet taken', function () use ($base): void {
        [$root, $timestamps] = withSlowNfdump($base, 3, maxProcesses: 4);
        slowBins()::$delays[slowBinFile($timestamps[0])] = 0.15;
        NfdumpSlots::acquireMany(3);

        Coroutine::run(static function () use ($base): void {
            Coroutine::create(static function (): void {
                Coroutine::usleep(50_000);
                NfdumpSlots::release(NfdumpSlots::INTERACTIVE, 3);
            });
            FilteredSeries::build($base, $base + 3 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        // One worker was busy and two jobs were left when the slots freed up: both run at once.
        expect(slowBins()::peak())->toBe(2)
            ->and(slowBins()::$started)->toHaveCount(3)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    // A background run needs two free slots, so a build that kept every slot made an import or
    // alert evaluation wait for all of it.
    test('background work queued during a build gets a slot after one bin', function () use ($base): void {
        [$root] = withSlowNfdump($base, 24, maxProcesses: 4);
        slowBins()::$seconds = 0.1;

        $waited = null;
        $whileHeld = [];
        Coroutine::run(static function () use ($base, &$waited, &$whileHeld): void {
            Coroutine::create(static function () use (&$waited, &$whileHeld): void {
                Coroutine::usleep(50_000);
                $t = microtime(true);
                NfdumpSlots::acquire(5.0, NfdumpSlots::BACKGROUND);
                $waited = microtime(true) - $t;
                for ($i = 0; $i < 4; ++$i) {
                    $whileHeld[] = slowBins()::$running;
                    Coroutine::usleep(60_000);
                }
                NfdumpSlots::release(NfdumpSlots::BACKGROUND);
            });
            FilteredSeries::build($base, $base + 24 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect($waited)->toBeLessThan(0.25)
            ->and(max($whileHeld))->toBeLessThanOrEqual(2)
            ->and(slowBins()::peak())->toBe(4)
            ->and(slowBins()::$started)->toHaveCount(24)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('background work that runs one nfdump after another starts each run at once beside a build', function () use ($base): void {
        [$root] = withSlowNfdump($base, 40, maxProcesses: 4);
        slowBins()::$seconds = 0.05;

        $waits = [];
        Coroutine::run(static function () use ($base, &$waits): void {
            Coroutine::create(static function () use (&$waits): void {
                Coroutine::usleep(30_000);
                for ($i = 0; $i < 5; ++$i) {
                    $t = microtime(true);
                    NfdumpSlots::acquire(5.0, NfdumpSlots::BACKGROUND);
                    $waits[] = microtime(true) - $t;
                    Coroutine::usleep(80_000);
                    NfdumpSlots::release(NfdumpSlots::BACKGROUND);
                }
            });
            FilteredSeries::build($base, $base + 40 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect($waits)->toHaveCount(5)
            ->and($waits[0])->toBeLessThan(0.2)
            ->and(max(array_slice($waits, 1)))->toBeLessThan(0.02)
            ->and(slowBins()::$started)->toHaveCount(40)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('a build started beside background work keeps one slot free for its next run', function () use ($base): void {
        [$root] = withSlowNfdump($base, 8, maxProcesses: 4);
        NfdumpSlots::acquire(0.0, NfdumpSlots::BACKGROUND);

        Coroutine::run(static function () use ($base): void {
            FilteredSeries::build($base, $base + 8 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect(slowBins()::peak())->toBe(2)
            ->and(slowBins()::$started)->toHaveCount(8)
            ->and(NfdumpSlots::inUse(NfdumpSlots::BACKGROUND))->toBe(1)
            ->and(NfdumpSlots::inUse(NfdumpSlots::INTERACTIVE))->toBe(0)
        ;

        removeTree($root);
    });

    // With a slot per run, a user query waited for one bin at most; a pool must not make it wait for the whole build.
    test('hands a slot to a user query that waits for one, and takes it back afterwards', function () use ($base): void {
        [$root] = withSlowNfdump($base, 24, maxProcesses: 2);

        $waited = null;
        $whileHeld = [];
        $startedBeforeRelease = 0;
        Coroutine::run(static function () use ($base, &$waited, &$whileHeld, &$startedBeforeRelease): void {
            Coroutine::create(static function () use (&$waited, &$whileHeld, &$startedBeforeRelease): void {
                Coroutine::usleep(50_000);
                $t = microtime(true);
                NfdumpSlots::acquire(2.0);
                $waited = microtime(true) - $t;
                $whileHeld[] = slowBins()::$running;
                Coroutine::usleep(60_000);
                $whileHeld[] = slowBins()::$running;
                $startedBeforeRelease = count(slowBins()::$started);
                NfdumpSlots::release();
            });
            FilteredSeries::build($base, $base + 24 * 300 - 1, ['gateway'], '', targetPoints: 100);
        });

        expect($waited)->toBeLessThan(0.15)
            ->and(max($whileHeld))->toBeLessThanOrEqual(1)
            ->and(max(array_slice(slowBins()::$concurrency, $startedBeforeRelease)))->toBe(2)
            ->and(slowBins()::$started)->toHaveCount(24)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('a cancelled build keeps the bins that ran, in order, and starts no more', function () use ($base): void {
        [$root, $timestamps] = withSlowNfdump($base, 12, maxProcesses: 3);

        $series = null;
        Coroutine::run(static function () use ($base, &$series): void {
            $series = FilteredSeries::build(
                $base,
                $base + 12 * 300 - 1,
                ['gateway'],
                '',
                targetPoints: 100,
                shouldCancel: static fn (): bool => count(slowBins()::$ended) >= 4,
            );
        });

        $kept = count($series['data']);
        expect($kept)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(6)
            ->and(slowBins()::$started)->toHaveCount($kept)
            ->and(array_keys($series['data']))->toBe(array_slice($timestamps, 0, $kept))
            ->and(array_filter(array_column($series['data'], 0), static fn (?float $v): bool => $v === null))->toBe([])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('reports progress once per bin, counting up to the total', function () use ($base): void {
        [$root] = withSlowNfdump($base, 10, maxProcesses: 4);

        $seen = [];
        Coroutine::run(static function () use ($base, &$seen): void {
            FilteredSeries::build(
                $base,
                $base + 10 * 300 - 1,
                ['gateway'],
                '',
                targetPoints: 100,
                onProgress: static function (int $done, int $total) use (&$seen): void { $seen[] = [$done, $total]; },
            );
        });

        expect($seen)->toBe(array_map(static fn (int $d): array => [$d, 10], range(1, 10)));

        removeTree($root);
    });

    test('an error in one run fails the build once every other run has ended, and frees every slot', function () use ($base): void {
        [$root, $timestamps] = withSlowNfdump($base, 9, maxProcesses: 3);
        slowBins()::$failOn = slowBinFile($timestamps[2]);

        $error = null;
        Coroutine::run(static function () use ($base, &$error): void {
            try {
                FilteredSeries::build($base, $base + 9 * 300 - 1, ['gateway'], '', targetPoints: 100);
            } catch (Throwable $e) {
                $error = $e;
            }
        });

        expect($error)->toBeInstanceOf(Error::class)
            ->and($error?->getMessage())->toBe('bin exploded')
            ->and(slowBins()::$running)->toBe(0)
            ->and(count(slowBins()::$started))->toBeLessThan(9)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('in a slot the caller holds, the bins share it one after another', function () use ($base): void {
        [$root] = withSlowNfdump($base, 5, maxProcesses: 4);

        $series = null;
        $inUse = null;
        Coroutine::run(static function () use ($base, &$series, &$inUse): void {
            NfdumpSlots::acquireMany(1);
            $series = NfdumpSlots::runInHeldSlot(
                NfdumpSlots::INTERACTIVE,
                static fn (): array => FilteredSeries::build($base, $base + 5 * 300 - 1, ['gateway'], '', targetPoints: 100),
            );
            $inUse = NfdumpSlots::inUse();
            NfdumpSlots::release();
        });

        expect(slowBins()::peak())->toBe(1)
            ->and(slowBins()::$started)->toHaveCount(5)
            ->and($inUse)->toBe(1)
            ->and($series['data'])->toHaveCount(5)
        ;

        removeTree($root);
    });

    // Each bin is a real process here, so this is the Kill button's path: every run registers
    // under the build's handle, and one kill() reaches all of them.
    test('a Kill ends every bin in flight at once and keeps the bins that finished', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], array_map(static fn (int $i): int => $base + $i * 300, range(0, 5)));
        Config::$settings = Config::$settings->withNfdumpMaxProcesses(2)->withNfdumpBinary(stubNfdumpBinary($root));
        Config::$processorClass = new Nfdump();
        putenv('NFSEN_STUB_SLOW=*nfcapd.2024010100[1-5]*');

        $series = null;
        $inFlight = [];
        $elapsed = 0.0;

        try {
            Coroutine::run(static function () use ($base, &$series, &$inFlight, &$elapsed): void {
                $cancel = false;
                $done = 0;
                Coroutine::create(static function () use (&$cancel, &$done, &$inFlight): void {
                    for ($i = 0; $i < 300 && (count(NfdumpSlots::running()['ctx-kill'] ?? []) < 2 || $done < 2); ++$i) {
                        Coroutine::usleep(10_000);
                    }
                    $inFlight = NfdumpSlots::running()['ctx-kill'] ?? [];
                    $cancel = true;
                    NfdumpSlots::kill('ctx-kill');
                });

                $t = microtime(true);
                $series = FilteredSeries::build(
                    $base,
                    $base + 6 * 300 - 1,
                    ['gateway'],
                    'port 443',
                    targetPoints: 100,
                    onProgress: static function (int $d) use (&$done): void { $done = $d; },
                    shouldCancel: static function () use (&$cancel): bool { return $cancel; },
                    handle: 'ctx-kill',
                );
                $elapsed = microtime(true) - $t;
            });
        } finally {
            putenv('NFSEN_STUB_SLOW');
        }

        expect($inFlight)->toHaveCount(2)
            ->and($elapsed)->toBeLessThan(4.0)
            // The killed bins are dropped rather than drawn as an outage.
            ->and($series['data'])->toBe([$base => [1.0, 0.0, 0.0, 0.0], $base + 300 => [1.0, 0.0, 0.0, 0.0]])
            ->and(NfdumpSlots::running())->not->toHaveKey('ctx-kill')
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });

    test('a Kill of a slow early bin ends the partial series before it, although later bins finished', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], array_map(static fn (int $i): int => $base + $i * 300, range(0, 5)));
        Config::$settings = Config::$settings->withNfdumpMaxProcesses(2)->withNfdumpBinary(stubNfdumpBinary($root));
        Config::$processorClass = new Nfdump();
        putenv('NFSEN_STUB_SLOW=*nfcapd.202401010000*');

        $series = null;
        $finished = 0;

        try {
            Coroutine::run(static function () use ($base, &$series, &$finished): void {
                $cancel = false;
                $done = 0;
                Coroutine::create(static function () use (&$cancel, &$done, &$finished): void {
                    for ($i = 0; $i < 300 && $done < 3; ++$i) {
                        Coroutine::usleep(10_000);
                    }
                    $finished = $done;
                    $cancel = true;
                    NfdumpSlots::kill('ctx-kill-early');
                });

                $series = FilteredSeries::build(
                    $base,
                    $base + 6 * 300 - 1,
                    ['gateway'],
                    'port 443',
                    targetPoints: 100,
                    onProgress: static function (int $d) use (&$done): void { $done = $d; },
                    shouldCancel: static function () use (&$cancel): bool { return $cancel; },
                    handle: 'ctx-kill-early',
                );
            });
        } finally {
            putenv('NFSEN_STUB_SLOW');
        }

        expect($finished)->toBeGreaterThanOrEqual(3)
            ->and($series['data'])->toBe([])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;

        removeTree($root);
    });
});

describe('FilteredSeries::build waiting for a slot', function (): void {
    $base = 1704067200;

    beforeEach(function (): void {
        freeAllSlots();
    });

    afterEach(function (): void {
        freeAllSlots();
    });

    // As when every run took its own slot: a bin that got none in time is a gap, the next one tries again.
    test('a bin that finds no slot in time is a gap, and the build goes on', function () use ($base): void {
        $root = withFakeNfdump(['gateway'], [$base, $base + 300, $base + 600]);
        NfdumpSlots::acquireMany(4);

        $series = NfdumpSlots::runAs(
            NfdumpSlots::INTERACTIVE,
            static fn (): array => FilteredSeries::build($base, $base + 899, ['gateway'], '', targetPoints: 3),
            0.05,
        );

        expect(FakeProcessor::$calls)->toBe([])
            ->and($series['data'])->toBe([
                $base => [null, null, null, null],
                $base + 300 => [null, null, null, null],
                $base + 600 => [null, null, null, null],
            ])
            ->and(NfdumpSlots::inUse())->toBe(4)
        ;

        removeTree($root);
    });

    test("the caller's budget bounds every slot wait of the build together", function () use ($base): void {
        $root = withFakeNfdump(['gateway'], array_map(static fn (int $i): int => $base + $i * 300, range(0, 5)));
        NfdumpSlots::acquireMany(4);

        $t = microtime(true);
        $series = NfdumpSlots::runAs(
            NfdumpSlots::INTERACTIVE,
            static fn (): array => FilteredSeries::build($base, $base + 6 * 300 - 1, ['gateway'], '', targetPoints: 100),
            1.0,
            budget: 0.1,
        );

        expect(microtime(true) - $t)->toBeLessThan(0.6)
            ->and($series['data'])->toHaveCount(6)
            ->and(FakeProcessor::$calls)->toBe([])
        ;

        removeTree($root);
    });
});
