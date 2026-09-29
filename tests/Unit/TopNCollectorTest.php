<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\TopNCollector;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\FakeProcessor;

// nfdump 1.7.8 -I on a dev capture file.
const TOPNC_SUMMARY = <<<'TXT'
    Ident: none
    Flows: 75
    Flows_tcp: 40
    Flows_udp: 20
    Flows_icmp: 10
    Flows_other: 5
    Packets: 525
    Packets_tcp: 400
    Packets_udp: 100
    Packets_icmp: 20
    Packets_other: 5
    Bytes: 51300
    Bytes_tcp: 40000
    Bytes_udp: 10000
    Bytes_icmp: 1000
    Bytes_other: 300
    First: 1787980673
    Last: 1787980674
    msec_first: 0
    msec_last: 0
    Sequence failures: 0

    TXT;

const TOPNC_HEADER = 'ts,te,td,pr,val,fl,flP,pkt,pktP,byt,bytP,pps,bps,bpp';

/** @param list<array{0: string, 1: string, 2: int}> $rows protocol, val, bytes */
function topncBlock(array $rows): string {
    $out = TOPNC_HEADER . "\n";
    foreach ($rows as [$pr, $val, $bytes]) {
        $out .= "2026-08-29 05:17:53,2026-08-29 05:17:54,1.000,{$pr},{$val},1,1.3,10,1.9,{$bytes},1.9,10,8000,100\n";
    }

    return $out;
}

/** The eight statistic run: the collector's first `-s` batch. */
function topncEightStats(): string {
    return topncBlock([['any', '10.0.0.1', 1000], ['any', '10.0.0.2', 500]])
        . topncBlock([['any', '10.1.0.1', 1500]])
        . topncBlock([['TCP', '10004', 1000], ['ICMP', '0', 100]])
        . topncBlock([['TCP', '443', 40000], ['UDP', '53', 10000], ['GRE', '53', 300], ['ICMP', '20263', 100]])
        . topncBlock([['TCP', '6', 40000], ['UDP', '17', 10000]])
        . topncBlock([['any', '0', 51300]])
        . "No matching flows\n" . TOPNC_HEADER . "\n"
        . topncBlock([['any', '1', 51300]]);
}

/** Writes a rotated capture file for $ts under its nfcapd-timezone day directory and returns its relative path. */
function topncCapture(string $root, string $source, int $ts, ?int $mtime = null, string $profile = 'live'): string {
    $at = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());
    $rel = $at->format('Y/m/d') . '/nfcapd.' . $at->format('YmdHi');
    $path = "{$root}/{$profile}/{$source}/{$rel}";
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    touch($path, $mtime ?? $ts + 300);

    return $rel;
}

function topncRelPath(int $ts): string {
    $at = (new DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());

    return $at->format('Y/m/d') . '/nfcapd.' . $at->format('YmdHi');
}

function topncRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/**
 * A processor that answers the collector's three runs by their options and sleeps inside each,
 * so the collecting coroutines of a booted worker overlap. It records every run with the
 * coroutine it ran in. The state is static because the collector builds an instance per run.
 */
function topncLane(): Processor {
    static $lane = null;

    return $lane ??= new class implements Processor {
        public static int $sleepUs = 20_000;
        public static string $summary = TOPNC_SUMMARY;
        public static ?Exception $throw = null;
        public static int $running = 0;
        public static int $peak = 0;

        /** @var list<array{relPath: string, run: string, cid: int}> */
        public static array $calls = [];

        /** @var array<string, mixed> */
        private array $options = [];

        public static function reset(): void {
            self::$sleepUs = 20_000;
            self::$summary = TOPNC_SUMMARY;
            self::$throw = null;
            self::$running = 0;
            self::$peak = 0;
            self::$calls = [];
        }

        public function setOption(string $option, $value): void {
            $this->options[$option] = $value;
        }

        public function setFilter(string $filter): void {}

        public function setQueryHandle(string $handle): void {}

        public function setProfile(string $profile): void {}

        public function execute(): array {
            $run = array_key_exists('-I', $this->options) ? 'totals' : (count((array) $this->options['-s']) === 8 ? 'eight' : 'outif');
            self::$calls[] = ['relPath' => (string) $this->options['-r'], 'run' => $run, 'cid' => Coroutine::getCid()];
            self::$peak = max(self::$peak, ++self::$running);

            try {
                if (self::$sleepUs > 0) {
                    Coroutine::usleep(self::$sleepUs);
                }
                if (self::$throw !== null) {
                    throw self::$throw;
                }
            } finally {
                --self::$running;
            }

            $raw = match ($run) {
                'totals' => self::$summary,
                'eight' => topncEightStats(),
                default => topncBlock([['any', '2', 51300]]),
            };

            return ['command' => 'fake-nfdump', 'rawOutput' => $raw, 'decoded' => [], 'notes' => [], 'exitCode' => 0];
        }
    };
}

function topncSettings(string $root, int $maxProcesses): Settings {
    return Settings::fromArray([
        'general' => ['sources' => ['gw', 'core'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $root, 'profile' => 'live', 'max-processes' => $maxProcesses],
        'log' => ['priority' => LOG_ERR],
    ])->withTopnRetentionDays(31);
}

/** Runs $body in a coroutine with the collector booted on $db and the lane processor answering nfdump. */
function topncBooted(Database $db, Closure $body): void {
    Database::useShared($db);
    Config::$processorClass = topncLane();
    Coroutine::run(static function () use ($body): void {
        TopNCollector::boot(new Via(new ViaConfig()));
        $body();
    });
}

/** Waits inside a coroutine until $done holds, at most $seconds. */
function topncWaitFor(Closure $done, float $seconds = 10.0): void {
    $deadline = microtime(true) + $seconds;
    while (!$done() && microtime(true) < $deadline) {
        Coroutine::usleep(5_000);
    }
}

function topncReleaseSlots(): void {
    foreach (NfdumpSlots::CLASSES as $class) {
        NfdumpSlots::release($class, NfdumpSlots::inUse($class));
    }
}

/** Runs the worker's loop body until the queue is empty; returns the results in order. */
function topncDrain(): array {
    $results = [];
    while (($item = TopNCollector::next()) !== null) {
        $results[] = TopNCollector::process($item);
    }

    return $results;
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir() . '/nfsen-ng-topnc-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0o777, true);
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->processorBefore = isset(Config::$processorClass) ? Config::$processorClass : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw', 'core'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => '/nonexistent/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 2],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$processorClass = new FakeProcessor();
    FakeProcessor::reset();
    TopNCollector::reset();
    TopNRepository::clearCache();

    $this->db = Database::open(':memory:');
    $this->repo = new TopNRepository($this->db);
    $this->now = time();
    $this->ts = $this->now - $this->now % 300 - 3600;
});

afterEach(function (): void {
    TopNCollector::reset();
    Database::resetShared();
    FakeProcessor::reset();
    topncLane()::reset();
    topncReleaseSlots();
    Debug::drainBuffer();
    topncRemoveTree($this->root);
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->processorBefore !== null) {
        Config::$processorClass = $this->processorBefore;
    }
});

describe('TopNCollector::collectOne()', function (): void {
    test('runs -I, the eight statistic run and the outif run, each on a fresh processor with handle topn', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
        FakeProcessor::queueRaw(TOPNC_SUMMARY);
        FakeProcessor::queueRaw(topncEightStats());
        FakeProcessor::queueRaw(topncBlock([['any', '2', 51300]]));

        $result = TopNCollector::collectOne('live', 'gw', $rel, $this->ts);

        $calls = FakeProcessor::$calls;
        expect($result)->toBe('ok')
            ->and($calls)->toHaveCount(3)
            ->and(array_unique(array_column($calls, 'handle')))->toBe(['topn'])
            ->and(array_unique(array_column($calls, 'profile')))->toBe(['live'])
            ->and($calls[0]['options'])->toBe(['-M' => 'gw', '-r' => $rel, '-I' => null])
            ->and($calls[1]['options'])->toBe(['-M' => 'gw', '-r' => $rel, '-n' => 50, '-o' => 'csv', '-s' => [
                'srcip/bytes', 'dstip/bytes', 'srcport:p/bytes', 'dstport:p/bytes', 'proto/bytes', 'srcas/bytes', 'dstas/bytes', 'inif/bytes',
            ]])
            ->and($calls[2]['options'])->toBe(['-M' => 'gw', '-r' => $rel, '-n' => 50, '-o' => 'csv', '-s' => ['outif/bytes']])
            ->and($this->repo->intervalState('live', 'gw', $this->ts))->toBe(['status' => 0, 'attempts' => 1, 'fileMtime' => 1_700_000_000])
            ->and($this->db->one('SELECT flows, packets, bytes FROM topn_interval'))->toBe(['flows' => 75, 'packets' => 525, 'bytes' => 51300])
        ;

        $db = $this->db;
        $keys = static fn (TopNStat $stat): array => array_column($db->all('SELECT key FROM topn_5m WHERE stat = ? ORDER BY bytes DESC, key', [$stat->value]), 'key');
        expect($keys(TopNStat::SrcIp))->toBe(['10.0.0.1', '10.0.0.2'])
            ->and($keys(TopNStat::SrcPort))->toBe(['10004/tcp'])
            ->and($keys(TopNStat::DstPort))->toBe(['443/tcp', '53/udp'])
            ->and($keys(TopNStat::Proto))->toBe(['6', '17'])
            ->and($keys(TopNStat::DstAs))->toBe([])
            ->and($keys(TopNStat::InIf))->toBe(['1'])
            ->and($keys(TopNStat::OutIf))->toBe(['2'])
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1h'))->toBe($this->db->value('SELECT COUNT(*) FROM topn_5m'))
        ;
    });

    test('an item with the import totals runs no -I and stores those totals', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        FakeProcessor::queueRaw(topncEightStats());
        FakeProcessor::queueRaw(topncBlock([['any', '2', 51300]]));

        $result = TopNCollector::collectOne('live', 'gw', topncRelPath($this->ts), $this->ts, ['flows' => 3, 'packets' => 4, 'bytes' => 5]);

        expect($result)->toBe('ok')
            ->and(FakeProcessor::$calls)->toHaveCount(2)
            ->and(array_key_exists('-I', FakeProcessor::callOptions(0)))->toBeFalse()
            ->and($this->db->one('SELECT flows, packets, bytes, file_mtime FROM topn_interval'))->toBe(['flows' => 3, 'packets' => 4, 'bytes' => 5, 'file_mtime' => 0])
        ;
    });

    test('a file without flows is stored as empty without statistic runs', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        FakeProcessor::queueRaw("Ident: none\nFlows: 0\nPackets: 0\nBytes: 0\n");

        $fromRun = TopNCollector::collectOne('live', 'gw', topncRelPath($this->ts), $this->ts);
        $fromImport = TopNCollector::collectOne('live', 'core', topncRelPath($this->ts), $this->ts, ['flows' => 0, 'packets' => 0, 'bytes' => 0]);

        expect([$fromRun, $fromImport])->toBe(['empty', 'empty'])
            ->and(FakeProcessor::$calls)->toHaveCount(1)
            ->and(array_column($this->db->all('SELECT status FROM topn_interval'), 'status'))->toBe([1, 1])
            ->and($this->db->value('SELECT COUNT(*) FROM topn_5m'))->toBe(0)
        ;
    });

    test('an nfdump failure stores status 2 and counts attempts per file', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
        FakeProcessor::$throw = new NfdumpException('Unknown statistic: srcas', 'nfdump -s srcas');

        $results = [];
        for ($i = 0; $i < 3; ++$i) {
            $results[] = TopNCollector::collectOne('live', 'gw', $rel, $this->ts, ['flows' => 9, 'packets' => 9, 'bytes' => 9]);
        }

        expect($results)->toBe(['failed', 'failed', 'failed'])
            ->and($this->repo->intervalState('live', 'gw', $this->ts))->toBe(['status' => 2, 'attempts' => 3, 'fileMtime' => 1_700_000_000])
            ->and(TopNCollector::stats())->toMatchArray(['processed' => 3, 'failed' => 3])
        ;
    });

    test('a run that prints fewer statistics than asked for is a failure', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        FakeProcessor::queueRaw(topncBlock([['any', '10.0.0.1', 1]]));

        expect(TopNCollector::collectOne('live', 'gw', topncRelPath($this->ts), $this->ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1]))->toBe('failed')
            ->and($this->db->value('SELECT COUNT(*) FROM topn_5m'))->toBe(0)
        ;
    });

    test('-I output without totals is a failure, not an empty file', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        FakeProcessor::queueRaw('');

        expect(TopNCollector::collectOne('live', 'gw', topncRelPath($this->ts), $this->ts))->toBe('failed');
    });
});

describe('TopNCollector::enqueue()', function (): void {
    test('is a no-op when the collector was not booted', function (): void {
        TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $this->ts), $this->ts);

        expect(TopNCollector::queued())->toBe(0);
    });

    test('is a no-op when retention is 0', function (): void {
        TopNCollector::start($this->repo, 0, $this->now);
        TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $this->ts), $this->ts);

        expect(TopNCollector::queued())->toBe(0);
    });

    test('is a no-op outside retention', function (): void {
        TopNCollector::start($this->repo, 2, $this->now);
        $old = $this->ts - 3 * 86400;
        TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $old), $old);
        TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $this->ts), $this->ts);

        expect(TopNCollector::queued())->toBe(1)
            ->and(TopNCollector::next()['ts'])->toBe($this->ts)
        ;
    });

    test('skips an interval collected from the same file, queues it again once the file is newer', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
        $this->repo->storeInterval('live', 'gw', $this->ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], TopNRepository::STATUS_OK, 1_700_000_000, []);

        TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
        $unchanged = TopNCollector::queued();
        touch($this->root . '/live/gw/' . $rel, 1_700_000_300);
        TopNCollector::enqueue('live', 'gw', $rel, $this->ts, ['flows' => 2, 'packets' => 2, 'bytes' => 2]);

        expect($unchanged)->toBe(0)
            ->and(TopNCollector::queued())->toBe(1)
            ->and(TopNCollector::next())->toBe(['profile' => 'live', 'source' => 'gw', 'relPath' => $rel, 'ts' => $this->ts, 'totals' => ['flows' => 2, 'packets' => 2, 'bytes' => 2]])
        ;
    });

    test('retries a failed interval until the third failure of the same file', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
        $fail = fn () => $this->repo->storeInterval('live', 'gw', $this->ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], TopNRepository::STATUS_FAILED, 1_700_000_000, []);

        $queued = [];
        for ($i = 0; $i < 3; ++$i) {
            $fail();
            TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
            $queued[] = TopNCollector::queued();
            TopNCollector::next();
        }

        expect($queued)->toBe([1, 1, 0]);
    });

    test('queues an interval once while it waits', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts);
        TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
        TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
        TopNCollector::enqueue('live', 'core', $rel, $this->ts);

        expect(TopNCollector::queued())->toBe(2);
    });

    test('the skip rule', function (): void {
        expect(TopNCollector::alreadyCollected(null, 5))->toBeFalse()
            ->and(TopNCollector::alreadyCollected(['status' => 0, 'attempts' => 1, 'fileMtime' => 5], 5))->toBeTrue()
            ->and(TopNCollector::alreadyCollected(['status' => 1, 'attempts' => 1, 'fileMtime' => 6], 5))->toBeTrue()
            ->and(TopNCollector::alreadyCollected(['status' => 0, 'attempts' => 1, 'fileMtime' => 5], 6))->toBeFalse()
            ->and(TopNCollector::alreadyCollected(['status' => 2, 'attempts' => 2, 'fileMtime' => 5], 5))->toBeFalse()
            ->and(TopNCollector::alreadyCollected(['status' => 2, 'attempts' => 3, 'fileMtime' => 5], 5))->toBeTrue()
            ->and(TopNCollector::alreadyCollected(['status' => 2, 'attempts' => 3, 'fileMtime' => 5], 6))->toBeFalse()
        ;
    });
});

describe('TopNCollector worker rules', function (): void {
    test('maySpawn() keeps a slot free for users', function (): void {
        $allowed = static fn (int $max): array => array_values(array_filter(range(0, $max), static fn (int $inUse): bool => TopNCollector::maySpawn($inUse, $max)));

        expect($allowed(1))->toBe([0])
            ->and($allowed(2))->toBe([0])
            ->and($allowed(3))->toBe([0, 1])
            ->and($allowed(5))->toBe([0, 1, 2, 3])
        ;
    });

    test('the generation moves every 50 items of a profile and when the queue drains', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $empty = ['flows' => 0, 'packets' => 0, 'bytes' => 0];
        for ($i = 1; $i <= 120; ++$i) {
            $ts = $this->ts - $i * 300;
            TopNCollector::enqueue('live', 'gw', topncRelPath($ts), $ts, $empty);
        }
        TopNCollector::enqueue('other', 'gw', topncRelPath($this->ts), $this->ts, $empty);

        $seen = [];
        $i = 0;
        while (($item = TopNCollector::next()) !== null) {
            TopNCollector::process($item);
            ++$i;
            if (in_array($i, [49, 50, 99, 100, 120], true)) {
                $seen[$i] = TopNCollector::generation('live');
            }
        }

        expect($seen)->toBe([49 => 0, 50 => 1, 99 => 1, 100 => 2, 120 => 2])
            ->and(TopNCollector::generation('live'))->toBe(3)
            ->and(TopNCollector::generation('other'))->toBe(1)
            ->and(FakeProcessor::$calls)->toBe([])
        ;
    });

    test('an item collected while it waited is skipped and moves no generation', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
        TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
        $this->repo->storeInterval('live', 'gw', $this->ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], TopNRepository::STATUS_OK, 1_700_000_000, []);

        expect(topncDrain())->toBe(['skipped'])
            ->and(TopNCollector::generation('live'))->toBe(0)
            ->and(FakeProcessor::$calls)->toBe([])
        ;
    });

    test('drops items beyond the queue capacity', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        for ($i = 0; $i < TopNCollector::QUEUE_CAPACITY + 5; ++$i) {
            $ts = $this->ts - $i * 300;
            TopNCollector::enqueue('live', 'gw', topncRelPath($ts), $ts, ['flows' => 0, 'packets' => 0, 'bytes' => 0]);
        }

        expect(TopNCollector::queued())->toBe(TopNCollector::QUEUE_CAPACITY);
    });
});

describe('TopNCollector::fillGaps()', function (): void {
    test('queues files of the retention window without a usable interval, newest first, up to the limit', function (): void {
        TopNCollector::start($this->repo, 2, $this->now, ['live']);
        $day = 86400;
        $stamps = [$this->ts, $this->ts - 300, $this->ts - $day, $this->ts - $day - 300, $this->ts - 3 * $day];
        foreach ($stamps as $ts) {
            topncCapture($this->root, 'gw', $ts);
        }
        topncCapture($this->root, 'core', $this->ts - 600);
        $store = fn (int $ts, int $status) => $this->repo->storeInterval('live', 'gw', $ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], $status, 1, []);
        $store($this->ts - 300, TopNRepository::STATUS_OK);
        $store($this->ts - $day, TopNRepository::STATUS_FAILED);
        for ($i = 0; $i < 3; ++$i) {
            $store($this->ts - $day - 300, TopNRepository::STATUS_FAILED);
        }

        $queued = TopNCollector::fillGaps('live', 500, $this->now);
        $items = [];
        while (($item = TopNCollector::next()) !== null) {
            $items[] = [$item['source'], $item['ts'], $item['relPath'], $item['totals']];
        }

        expect($queued)->toBe(3)
            ->and($items)->toBe([
                ['gw', $this->ts, topncRelPath($this->ts), null],
                ['core', $this->ts - 600, topncRelPath($this->ts - 600), null],
                ['gw', $this->ts - $day, topncRelPath($this->ts - $day), null],
            ])
            ->and(FakeProcessor::$calls)->toBe([])
        ;
    });

    test('stops at the limit', function (): void {
        TopNCollector::start($this->repo, 31, $this->now, ['live']);
        for ($i = 0; $i < 6; ++$i) {
            topncCapture($this->root, 'gw', $this->ts - $i * 300);
        }

        expect(TopNCollector::fillGaps('live', 4, $this->now))->toBe(4)
            ->and(TopNCollector::next()['ts'])->toBe($this->ts)
            ->and(TopNCollector::fillGaps('live', 0, $this->now))->toBe(0)
        ;
    });

    test('does nothing when not booted', function (): void {
        topncCapture($this->root, 'gw', $this->ts);

        expect(TopNCollector::fillGaps('live', 500, $this->now))->toBe(0)
            ->and(TopNCollector::fillAllGaps(500, $this->now))->toBe(0)
        ;
    });

    test('fillAllGaps() fills the newest day of every profile and source before older days', function (): void {
        TopNCollector::start($this->repo, 31, $this->now, ['live', 'test']);
        $day = 86400;
        $all = [['live', 'gw'], ['live', 'core'], ['test', 'gw']];
        foreach ($all as [$profile, $source]) {
            for ($d = 0; $d < 3; ++$d) {
                topncCapture($this->root, $source, $this->ts - $d * $day, null, $profile);
                topncCapture($this->root, $source, $this->ts - $d * $day - 300, null, $profile);
            }
        }

        $queued = TopNCollector::fillAllGaps(6, $this->now);
        $items = [];
        while (($item = TopNCollector::next()) !== null) {
            $items[] = [$item['profile'], $item['source'], $item['ts']];
        }

        expect($queued)->toBe(6)
            ->and($items)->toBe([
                ['live', 'gw', $this->ts],
                ['live', 'core', $this->ts],
                ['test', 'gw', $this->ts],
                ['live', 'gw', $this->ts - 300],
                ['live', 'core', $this->ts - 300],
                ['test', 'gw', $this->ts - 300],
            ])
        ;
    });
});

describe('TopNCollector::prune()', function (): void {
    test('removes intervals, 5 minute rows and rollups that start before the cutoff, and moves the generation', function (): void {
        TopNCollector::start($this->repo, 2, $this->now);
        $cutoff = $this->now - 2 * 86400;
        $rows = [TopNStat::SrcIp->value => [['key' => 'a', 'flows' => 1, 'packets' => 1, 'bytes' => 1]], TopNStat::OutIf->value => [['key' => '3', 'flows' => 1, 'packets' => 1, 'bytes' => 1]]];
        $old = [];
        for ($ts = $cutoff - $cutoff % 300 - 5 * 86400; $ts < $cutoff; $ts += 3600) {
            $old[] = $ts;
        }
        foreach ([...$old, $cutoff - $cutoff % 300 + 300, $this->ts] as $ts) {
            $this->repo->storeInterval('live', 'gw', $ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], 0, 1, $rows);
        }
        $this->repo->storeInterval('other', 'gw', $this->ts, ['flows' => 1, 'packets' => 1, 'bytes' => 1], 0, 1, $rows);

        $removed = TopNCollector::prune($this->now);

        expect($removed)->toBe(count($old))
            ->and($this->db->value('SELECT MIN(ts) FROM topn_interval'))->toBeGreaterThanOrEqual($cutoff)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_5m WHERE ts < ?', [$cutoff]))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1h WHERE ts < ?', [$cutoff]))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1d WHERE ts < ?', [$cutoff]))->toBe(0)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_5m'))->toBe(6)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_1h WHERE ts >= ?', [$cutoff]))->toBeGreaterThan(0)
            ->and(TopNCollector::generation('live'))->toBe(1)
            ->and(TopNCollector::generation('other'))->toBe(0)
            ->and(TopNCollector::prune($this->now))->toBe(0)
            ->and(TopNCollector::generation('live'))->toBe(1)
        ;
    });
});

describe('TopNCollector::maintain()', function (): void {
    test('fills gaps from 60 s after start and prunes from 300 s after start', function (): void {
        TopNCollector::start($this->repo, 2, $this->now, ['live']);
        topncCapture($this->root, 'gw', $this->ts);
        $old = $this->now - 3 * 86400;
        $this->repo->storeInterval('live', 'gw', $old - $old % 300, ['flows' => 1, 'packets' => 1, 'bytes' => 1], 0, 1, []);

        TopNCollector::maintain($this->now + 59);
        $early = [TopNCollector::queued(), $this->db->value('SELECT COUNT(*) FROM topn_interval')];
        TopNCollector::maintain($this->now + 60);
        $filled = TopNCollector::queued();
        TopNCollector::maintain($this->now + 300);

        expect($early)->toBe([0, 1])
            ->and($filled)->toBe(1)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval'))->toBe(0)
        ;
    });
});

describe('TopNCollector backfill passes', function (): void {
    beforeEach(function (): void {
        TopNCollector::start($this->repo, 31, $this->now, ['live']);
        for ($i = 0; $i < 5; ++$i) {
            topncCapture($this->root, 'gw', $this->ts - $i * 300);
        }
    });

    test('while a backfill has files left, the minute tick starts the next pass without waiting 10 minutes', function (): void {
        $first = TopNCollector::fillAllGaps(2, $this->now);
        FakeProcessor::queueRaw("Ident: none\nFlows: 0\nPackets: 0\nBytes: 0\n");
        FakeProcessor::queueRaw("Ident: none\nFlows: 0\nPackets: 0\nBytes: 0\n");
        topncDrain();
        TopNCollector::maintain($this->now + 1);

        expect([$first, TopNCollector::queued()])->toBe([2, 3]);
    });

    test('a failure since the last pass leaves the next one to the 10 minute timer', function (): void {
        TopNCollector::fillAllGaps(2, $this->now);
        FakeProcessor::$throw = new NfdumpException('nfdump: not found', 'nfdump -I');
        $results = topncDrain();
        TopNCollector::maintain($this->now + 1);
        $early = TopNCollector::queued();
        TopNCollector::maintain($this->now + TopNCollector::GAP_FILL_FIRST);

        expect($results)->toBe(['failed', 'failed'])
            ->and($early)->toBe(0)
            ->and(TopNCollector::queued())->toBe(5)
        ;
    });
});

describe('TopNCollector worker coroutine', function (): void {
    test('exists only while the queue has items', function (): void {
        Database::useShared($this->db);
        Config::$settings = Config::$settings->withTopnRetentionDays(31);
        $rel = topncCapture($this->root, 'gw', $this->ts);
        $coroutines = [];

        Coroutine::run(function () use ($rel, &$coroutines): void {
            TopNCollector::boot(new Via(new ViaConfig()));
            $coroutines[] = Coroutine::stats()['coroutine_num'];
            TopNCollector::enqueue('live', 'gw', $rel, $this->ts, ['flows' => 0, 'packets' => 0, 'bytes' => 0]);
            Coroutine::usleep(50_000);
            $coroutines[] = Coroutine::stats()['coroutine_num'];
        });

        expect($coroutines)->toBe([1, 1])
            ->and(TopNCollector::stats()['processed'])->toBe(1)
            ->and($this->repo->intervalState('live', 'gw', $this->ts)['status'] ?? null)->toBe(TopNRepository::STATUS_EMPTY)
        ;
    });
});

describe('TopNCollector parallel collection', function (): void {
    beforeEach(function (): void {
        topncLane()::reset();
        topncReleaseSlots();
    });

    test('collects up to backgroundMax() files at once, each file\'s runs in one coroutine, and writes from one other coroutine', function (int $maxProcesses, int $lanes): void {
        Config::$settings = topncSettings($this->root, $maxProcesses);
        $writers = [];
        $this->db->pdo()->sqliteCreateFunction('topnc_writer', static function () use (&$writers): int {
            $writers[] = Coroutine::getCid();

            return 0;
        }, 0);
        $this->db->exec('CREATE TEMP TRIGGER topnc_writes AFTER INSERT ON topn_interval BEGIN SELECT topnc_writer(); END');
        $stamps = array_map(fn (int $i): int => $this->ts - $i * 300, range(0, 7));
        $coroutinesAfter = null;

        topncBooted($this->db, function () use ($stamps, &$coroutinesAfter): void {
            foreach ($stamps as $ts) {
                TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $ts), $ts);
            }
            topncWaitFor(static fn (): bool => TopNCollector::stats()['processed'] === 8);
            Coroutine::usleep(20_000);
            $coroutinesAfter = Coroutine::stats()['coroutine_num'];
        });

        $byFile = [];
        foreach (topncLane()::$calls as $call) {
            $byFile[$call['relPath']]['runs'][] = $call['run'];
            $byFile[$call['relPath']]['cids'][$call['cid']] = true;
        }
        $laneCids = array_unique(array_column(topncLane()::$calls, 'cid'));

        expect(topncLane()::$peak)->toBe($lanes)
            ->and(NfdumpSlots::backgroundMax())->toBe($lanes)
            ->and($laneCids)->toHaveCount($lanes)
            ->and($byFile)->toHaveCount(8)
            ->and(array_unique(array_map(static fn (array $file): string => implode(',', $file['runs']), $byFile)))->toBe([array_key_first($byFile) => 'totals,eight,outif'])
            ->and(array_map(static fn (array $file): int => count($file['cids']), array_values($byFile)))->toBe(array_fill(0, 8, 1))
            ->and($writers)->toHaveCount(8)
            ->and(array_unique($writers))->toHaveCount(1)
            ->and(in_array($writers[0], $laneCids, true))->toBeFalse()
            ->and(array_column($this->db->all('SELECT status FROM topn_interval'), 'status'))->toBe(array_fill(0, 8, TopNRepository::STATUS_OK))
            ->and($this->db->value('SELECT SUM(bytes) FROM topn_1h'))->toBe($this->db->value('SELECT SUM(bytes) FROM topn_5m'))
            ->and(TopNCollector::stats())->toMatchArray(['queued' => 0, 'processed' => 8, 'failed' => 0])
            ->and(TopNCollector::generation('live'))->toBeGreaterThan(0)
            ->and(NfdumpSlots::inUse())->toBe(0)
            ->and($coroutinesAfter)->toBe(1)
        ;
    })->with([
        'one slot' => [1, 1],
        'two slots' => [2, 1],
        'four slots' => [4, 2],
        'eight slots' => [8, 4],
    ]);

    // The collector polls for a slot instead of queueing, so an import that waits in the slot
    // queue gets the slot a user query frees before the collector's first run.
    test('waits for a slot outside the slot queue, so a waiting import goes first', function (): void {
        Config::$settings = topncSettings($this->root, 2);
        $order = [];
        $waiting = null;

        topncBooted($this->db, function () use (&$order, &$waiting): void {
            NfdumpSlots::acquire(1.0);
            TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $this->ts), $this->ts);
            Coroutine::create(static function () use (&$order): void {
                NfdumpSlots::acquire(5.0, NfdumpSlots::BACKGROUND);
                $order[] = ['import', topncLane()::$calls];
                Coroutine::usleep(200_000);
                NfdumpSlots::release(NfdumpSlots::BACKGROUND);
            });
            Coroutine::usleep(100_000);
            $waiting = [NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE), NfdumpSlots::waiting(NfdumpSlots::BACKGROUND), count(topncLane()::$calls)];
            NfdumpSlots::release();
            topncWaitFor(static fn (): bool => TopNCollector::stats()['processed'] === 1);
        });

        expect($waiting)->toBe([0, 1, 0])
            ->and($order)->toBe([['import', []]])
            ->and(topncLane()::$calls)->toHaveCount(3)
            ->and($this->repo->intervalState('live', 'gw', $this->ts)['status'] ?? null)->toBe(TopNRepository::STATUS_OK)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('a user query that waits for a slot gets the one a collector run frees before the collector\'s next run', function (): void {
        Config::$settings = topncSettings($this->root, 2);
        topncLane()::$sleepUs = 300_000;
        $during = null;
        $granted = null;

        topncBooted($this->db, function () use (&$during, &$granted): void {
            TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $this->ts), $this->ts);
            Coroutine::usleep(50_000);
            Coroutine::create(static function (): void {
                NfdumpSlots::acquire(1.0);
                Coroutine::usleep(600_000);
                NfdumpSlots::release();
            });
            Coroutine::create(static function () use (&$granted): void {
                NfdumpSlots::acquire(5.0);
                $granted = [count(topncLane()::$calls), topncLane()::$running];
                Coroutine::usleep(100_000);
                NfdumpSlots::release();
            });
            Coroutine::usleep(50_000);
            $during = [NfdumpSlots::waiting(NfdumpSlots::INTERACTIVE), NfdumpSlots::inUse(NfdumpSlots::BACKGROUND), count(topncLane()::$calls)];
            topncWaitFor(static fn (): bool => TopNCollector::stats()['processed'] === 1);
        });

        expect($during)->toBe([1, 1, 1])
            ->and($granted)->toBe([1, 0])
            ->and(array_column(topncLane()::$calls, 'run'))->toBe(['totals', 'eight', 'outif'])
            ->and($this->repo->intervalState('live', 'gw', $this->ts)['status'] ?? null)->toBe(TopNRepository::STATUS_OK)
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });

    test('a file queued again while it is being collected waits for that collection, then the skip rule drops it', function (): void {
        Config::$settings = topncSettings($this->root, 8);
        $during = null;

        topncBooted($this->db, function () use (&$during): void {
            $rel = topncCapture($this->root, 'gw', $this->ts, 1_700_000_000);
            TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
            Coroutine::usleep(5_000);
            TopNCollector::enqueue('live', 'gw', $rel, $this->ts);
            Coroutine::usleep(5_000);
            $during = [TopNCollector::queued(), TopNCollector::stats()['queued'], topncLane()::$running, count(topncLane()::$calls)];
            topncWaitFor(static fn (): bool => TopNCollector::stats()['processed'] === 1 && TopNCollector::queued() === 0);
            Coroutine::usleep(20_000);
        });

        expect($during)->toBe([1, 1, 1, 1])
            ->and(topncLane()::$calls)->toHaveCount(3)
            ->and(TopNCollector::stats())->toMatchArray(['queued' => 0, 'processed' => 1, 'failed' => 0])
            ->and($this->repo->intervalState('live', 'gw', $this->ts))->toBe(['status' => 0, 'attempts' => 1, 'fileMtime' => 1_700_000_000])
        ;
    });

    test('a gap filler pass does not queue the files being collected again, and stats() counts them as queued', function (): void {
        // One day of retention keeps a pass short next to the 300 ms runs.
        Config::$settings = topncSettings($this->root, 8)->withTopnRetentionDays(1);
        topncLane()::$sleepUs = 300_000;
        topncLane()::$summary = "Ident: none\nFlows: 0\nPackets: 0\nBytes: 0\n";
        for ($i = 0; $i < 6; ++$i) {
            topncCapture($this->root, 'gw', $this->ts - $i * 300);
        }
        $passes = [];
        $during = null;

        topncBooted($this->db, function () use (&$passes, &$during): void {
            $passes[] = TopNCollector::fillAllGaps();
            topncWaitFor(static fn (): bool => topncLane()::$running === 4);
            $passes[] = TopNCollector::fillAllGaps();
            $during = [TopNCollector::queued(), TopNCollector::stats()['queued'], topncLane()::$running];
            topncWaitFor(static fn (): bool => TopNCollector::stats()['processed'] === 6);
            Coroutine::usleep(20_000);
        });

        expect($passes)->toBe([6, 0])
            ->and($during)->toBe([2, 6, 4])
            ->and(topncLane()::$calls)->toHaveCount(6)
            ->and(TopNCollector::stats())->toMatchArray(['queued' => 0, 'processed' => 6, 'failed' => 0])
        ;
    });

    test('a backfill starts the next gap filler pass before the queue runs dry', function (): void {
        Config::$settings = topncSettings($this->root, 8);
        topncLane()::$sleepUs = 0;
        topncLane()::$summary = "Ident: none\nFlows: 0\nPackets: 0\nBytes: 0\n";
        $files = TopNCollector::GAP_FILL_MAX + 100;
        for ($i = 0; $i < $files; ++$i) {
            topncCapture($this->root, 'gw', $this->ts - $i * 300);
        }
        $firstPass = null;

        topncBooted($this->db, function () use ($files, &$firstPass): void {
            $firstPass = TopNCollector::fillAllGaps();
            topncWaitFor(fn (): bool => $this->db->value('SELECT COUNT(*) FROM topn_interval') === $files);
            Coroutine::usleep(50_000);
        });

        expect($firstPass)->toBe(TopNCollector::GAP_FILL_MAX)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval WHERE status = ?', [TopNRepository::STATUS_EMPTY]))->toBe($files)
            ->and(topncLane()::$calls)->toHaveCount($files)
            ->and(TopNCollector::stats())->toMatchArray(['queued' => 0, 'processed' => $files, 'failed' => 0])
        ;
    });

    test('after a failure the next gap filler pass waits for the 10 minute timer', function (): void {
        Config::$settings = topncSettings($this->root, 8);
        topncLane()::$sleepUs = 0;
        topncLane()::$throw = new NfdumpException('nfdump: not found', 'nfdump -I');
        for ($i = 0; $i < TopNCollector::GAP_FILL_MAX + 100; ++$i) {
            topncCapture($this->root, 'gw', $this->ts - $i * 300);
        }

        topncBooted($this->db, function (): void {
            TopNCollector::fillAllGaps();
            topncWaitFor(static fn (): bool => TopNCollector::stats()['failed'] === TopNCollector::GAP_FILL_MAX);
            Coroutine::usleep(200_000);
        });

        expect($this->db->value('SELECT COUNT(*) FROM topn_interval WHERE status = ?', [TopNRepository::STATUS_FAILED]))->toBe(TopNCollector::GAP_FILL_MAX)
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval'))->toBe(TopNCollector::GAP_FILL_MAX)
            ->and(TopNCollector::queued())->toBe(0)
        ;
    });
});

describe('TopNCollector::stats()', function (): void {
    test('reports the queue and the last collection', function (): void {
        TopNCollector::start($this->repo, 31, $this->now);
        TopNCollector::collectOne('live', 'gw', topncRelPath($this->ts), $this->ts, ['flows' => 0, 'packets' => 0, 'bytes' => 0]);
        TopNCollector::enqueue('live', 'gw', topncRelPath($this->ts - 300), $this->ts - 300);

        $stats = TopNCollector::stats();

        expect($stats['queued'])->toBe(1)
            ->and($stats['processed'])->toBe(1)
            ->and($stats['failed'])->toBe(0)
            ->and($stats['lastTs'])->toBeGreaterThanOrEqual($this->now)
            ->and($stats['lastMs'])->toBeGreaterThanOrEqual(0)
        ;
    });
});

describe('TopNCollector::stop()', function (): void {
    beforeEach(function (): void {
        topncLane()::reset();
        topncReleaseSlots();
    });

    test('disarms the queue, the gap filler and the pruner', function (): void {
        TopNCollector::start($this->repo, 31, $this->now, ['live']);
        topncCapture($this->root, 'gw', $this->ts);
        TopNCollector::enqueue('live', 'gw', topncRelPath($this->ts - 300), $this->ts - 300);

        TopNCollector::stop();
        TopNCollector::enqueue('live', 'gw', topncRelPath($this->ts - 600), $this->ts - 600);

        expect(TopNCollector::booted())->toBeFalse()
            ->and(TopNCollector::queued())->toBe(0)
            ->and(TopNCollector::fillAllGaps())->toBe(0)
            ->and(TopNCollector::prune($this->now + 400 * 86400))->toBe(0)
        ;
    });

    // The shutdown kills the collector's nfdump, which the lane processor stands in for by throwing.
    test('the worker ends after the files in flight, and a file the stop cut short is left for the next start', function (): void {
        Config::$settings = topncSettings($this->root, 4);
        topncLane()::$sleepUs = 150_000;
        $stamps = array_map(fn (int $i): int => $this->ts - $i * 300, range(0, 5));
        $coroutinesAfter = null;

        topncBooted($this->db, function () use ($stamps, &$coroutinesAfter): void {
            foreach ($stamps as $ts) {
                TopNCollector::enqueue('live', 'gw', topncCapture($this->root, 'gw', $ts), $ts);
            }
            Coroutine::usleep(50_000);
            TopNCollector::stop();
            topncLane()::$throw = new NfdumpException('nfdump was killed', 'fake-nfdump', '', 143);
            topncWaitFor(static fn (): bool => Coroutine::stats()['coroutine_num'] === 1, 3.0);
            $coroutinesAfter = Coroutine::stats()['coroutine_num'];
        });

        expect($coroutinesAfter)->toBe(1)
            ->and(topncLane()::$calls)->toHaveCount(NfdumpSlots::backgroundMax())
            ->and($this->db->value('SELECT COUNT(*) FROM topn_interval'))->toBe(0)
            ->and(TopNCollector::stats())->toMatchArray(['queued' => 0, 'processed' => 0, 'failed' => 0])
            ->and(NfdumpSlots::inUse())->toBe(0)
        ;
    });
});
