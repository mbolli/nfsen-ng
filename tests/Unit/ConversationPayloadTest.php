<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\ConversationActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\ConversationsPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\state\ConversationsState;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\ConversationPayload;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Tests\Support\FakeProcessor;

/** One fmt row (`%sa %da [%dp] %ibyt %ipkt %fl`). */
function convRow(string $sa, string $da, int $bytes, int $packets = 1, int $flows = 1, ?int $port = null): array {
    $row = ['sa' => $sa, 'da' => $da];
    if ($port !== null) {
        $row['dp'] = (string) $port;
    }

    return $row + ['ibyt' => (string) $bytes, 'ipkt' => (string) $packets, 'fl' => (string) $flows];
}

/** @param list<array<string, string>> $rows */
function convBuild(array $rows, string $groupBy = 'ip', string $direction = 'forward', int $topN = 20, ?array $totals = null, string $metric = 'bytes'): array {
    return ConversationPayload::build($rows, $metric, $groupBy, $direction, $topN, $totals, 'nfdump -M /data');
}

function conversationsTestSettings(): void {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [80, 443], 'max_stats_window' => 0],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-conversations-test-missing', 'profile' => 'live'],
    ]);
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-conversations-test-missing.json';
}

/**
 * The page handler against a Via with the real templates, in the fatal-error state so a render
 * touches no datasource; the Conversations page is the active one.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function conversationsTestCompose(): array {
    conversationsTestSettings();
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-conversations', '/', $app);
    $states = new PageStates();

    Shell::signals($c);
    foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $part) {
        $part::signals($c);
    }
    Shell::register($c, $app, $states);
    foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $part) {
        $part::register($c, $app, $states);
    }
    $c->getSignal('page')?->setValue(ConversationsPage::id(), broadcast: false);

    return [$app, $c, $states];
}

/** A finished run as MatrixQuery::run() returns it, with nfdump's text footer. */
function convResult(array $rows, int $start = 1_000_000, int $end = 1_086_400, int $totalBytes = 10_000): QueryResult {
    $raw = "Src IP Addr Dst IP Addr In Byte In Pkt Flows\n"
        . "Summary: total flows: 40, total bytes: {$totalBytes}, total packets: 400, avg bps: 1, avg pps: 1, avg bpp: 1\n";

    return new QueryResult($rows, "nfdump -M /data/gw1 -R x -- 'proto tcp'", '', 0.1, TimeWindow::raw($start, $end), $raw);
}

describe('ConversationPayload::build()', function (): void {
    test('ranks IP pairs by the metric, with shares and series slots', function (): void {
        $payload = convBuild([
            convRow('10.0.0.1', '10.1.0.1', 300, 3, 2),
            convRow('10.0.0.2', '10.1.0.1', 500, 5, 1),
            convRow('10.0.0.1', '10.1.0.2', 200, 2, 1),
        ], totals: ['flows' => 10, 'packets' => 20, 'bytes' => 2000]);

        expect(array_column($payload['pairs'], 'rank'))->toBe([1, 2, 3])
            ->and(array_column($payload['pairs'], 'src'))->toBe(['10.0.0.2', '10.0.0.1', '10.0.0.1'])
            ->and($payload['pairs'][0])->toMatchArray(['dst' => '10.1.0.1', 'port' => null, 'bytes' => 500, 'packets' => 5, 'flows' => 1, 'share' => 0.25, 'reverse' => null])
            // Slots follow the first appearance of the source node in the ranked result.
            ->and(array_column($payload['pairs'], 'series'))->toBe([1, 2, 2])
            ->and($payload['meta'])->toBe(['metric' => 'bytes', 'groupBy' => 'ip', 'direction' => 'forward', 'topN' => 20, 'approximate' => false, 'command' => 'nfdump -M /data'])
            ->and($payload['totals'])->toBe(['flows' => 10, 'packets' => 20, 'bytes' => 2000])
        ;
    });

    test('ranks by packets when asked, and shares are of packets', function (): void {
        $payload = convBuild([convRow('10.0.0.1', '10.1.0.1', 900, 1), convRow('10.0.0.2', '10.1.0.1', 100, 9)], totals: ['flows' => 2, 'packets' => 10, 'bytes' => 1000], metric: 'packets');

        expect($payload['pairs'][0]['src'])->toBe('10.0.0.2')
            ->and($payload['pairs'][0]['share'])->toBe(0.9)
            ->and($payload['meta']['metric'])->toBe('packets')
        ;
    });

    test('drops a host talking to itself when grouping by address', function (): void {
        $payload = convBuild([convRow('10.0.0.1', '10.0.0.1', 900), convRow('10.0.0.1', '10.0.0.2', 100)]);

        expect($payload['pairs'])->toHaveCount(1)
            ->and($payload['pairs'][0]['dst'])->toBe('10.0.0.2')
        ;
    });

    test('labels subnets with their prefix and keeps traffic inside one subnet', function (string $group, string $suffix): void {
        $payload = convBuild([convRow('10.0.37.0', '10.1.37.0', 500), convRow('10.0.37.0', '10.0.37.0', 100)], $group);

        expect(array_column($payload['pairs'], 'src'))->toBe(['10.0.37.0' . $suffix, '10.0.37.0' . $suffix])
            ->and(array_column($payload['pairs'], 'dst'))->toBe(['10.1.37.0' . $suffix, '10.0.37.0' . $suffix])
            ->and($payload['meta']['groupBy'])->toBe($group)
        ;
    })->with([['net24', '/24'], ['net16', '/16']]);

    test('carries the destination port when grouping by port', function (): void {
        $payload = convBuild([
            convRow('10.0.0.1', '10.1.0.1', 500, port: 443),
            convRow('10.0.0.1', '10.1.0.1', 100, port: 53),
            ['sa' => '10.0.0.1', 'da' => '10.1.0.1', 'dp' => 'Dst', 'ibyt' => '5', 'ipkt' => '1', 'fl' => '1'],
            ['sa' => '10.0.0.1', 'da' => '10.1.0.1', 'dp' => '', 'ibyt' => '5', 'ipkt' => '1', 'fl' => '1'],
        ], 'port');

        expect(array_column($payload['pairs'], 'port'))->toBe([443, 53]);
    });

    // nfdump prints an ICMP flow's destination port as type.code, in fmt and in csv alike.
    test('keeps ICMP flows, with their type.code as the port', function (): void {
        $totals = ['flows' => 4, 'packets' => 40, 'bytes' => 20_000];
        $payload = convBuild([
            ['sa' => '10.0.69.1', 'da' => '10.1.69.2', 'dp' => '8.0', 'ibyt' => '18400', 'ipkt' => '368', 'fl' => '184'],
            ['srcAddr' => '10.0.0.1', 'dstAddr' => '10.1.0.1', 'dstPort' => '0.0', 'bytes' => '600', 'packets' => '6', 'flows' => '2'],
            convRow('10.0.0.1', '10.1.0.1', 1000, port: 443),
        ], 'port', totals: $totals);

        expect(array_column($payload['pairs'], 'port'))->toBe(['8.0', 443, '0.0'])
            ->and($payload['others'])->toMatchArray(['bytes' => 0])
            ->and(array_column(ConversationActions::tableRows($payload), 'dstport'))->toBe(['ICMP 8.0', 443, 'ICMP 0.0'])
        ;
    });

    test('never merges directions when the port is in the key', function (): void {
        $payload = convBuild([convRow('10.0.0.1', '10.1.0.1', 500, port: 443), convRow('10.1.0.1', '10.0.0.1', 100, port: 443)], 'port', 'both');

        expect($payload['meta']['direction'])->toBe('forward')
            ->and($payload['pairs'])->toHaveCount(2)
        ;
    });

    test('keeps only rows whose ends are addresses', function (): void {
        $payload = convBuild([
            ['sa' => 'Src', 'da' => 'Dst', 'ibyt' => 'In', 'ipkt' => 'Pkt', 'fl' => 'Flows'],
            ['sa' => 'Summary:', 'da' => 'total', 'ibyt' => '1', 'ipkt' => '1', 'fl' => '1'],
            convRow('2001:db8::1', '2001:db8::2', 10),
        ]);

        expect($payload['pairs'])->toHaveCount(1)
            ->and($payload['pairs'][0]['src'])->toBe('2001:db8::1')
        ;
    });

    test('reads the aggregated csv of nfdump 1.7.5', function (): void {
        $rows = [
            ['srcAddr' => '10.0.0.1', 'dstAddr' => '10.1.0.1', 'dstPort' => '443', 'bytes' => '700', 'packets' => '7', 'flows' => '3'],
            ['srcAddr' => '10.0.0.2', 'dstAddr' => '10.1.0.1', 'dstPort' => '80', 'bytes' => '100', 'packets' => '1', 'flows' => '1'],
        ];

        expect(convBuild($rows)['pairs'][0])->toMatchArray(['src' => '10.0.0.1', 'dst' => '10.1.0.1', 'port' => null, 'bytes' => 700, 'packets' => 7, 'flows' => 3])
            ->and(convBuild($rows, 'port')['pairs'][1])->toMatchArray(['port' => 80, 'bytes' => 100])
            // No text footer on that path, so no totals and no shares.
            ->and(convBuild($rows)['pairs'][0]['share'])->toBeNull()
            ->and(convBuild($rows)['others'])->toBeNull()
        ;
    });

    test('slices to the top N after sorting', function (): void {
        $rows = array_map(static fn (int $i): array => convRow('10.0.0.' . $i, '10.1.0.1', $i * 10), range(1, 30));
        $payload = convBuild($rows, topN: 5);

        expect($payload['pairs'])->toHaveCount(5)
            ->and($payload['pairs'][0]['src'])->toBe('10.0.0.30')
            ->and($payload['meta']['topN'])->toBe(5)
        ;
    });

    test('gives slots to the first eight source nodes only', function (): void {
        $rows = array_map(static fn (int $i): array => convRow('10.0.0.' . $i, '10.1.0.1', 1000 - $i), range(1, 10));

        expect(array_column(convBuild($rows)['pairs'], 'series'))->toBe([1, 2, 3, 4, 5, 6, 7, 8, null, null]);
    });

    test('says what the listed pairs leave of the totals', function (): void {
        $payload = convBuild([convRow('10.0.0.1', '10.1.0.1', 600, 6, 2), convRow('10.0.0.2', '10.1.0.1', 200, 2, 1)], totals: ['flows' => 10, 'packets' => 20, 'bytes' => 1000]);

        expect($payload['others'])->toBe(['bytes' => 200, 'packets' => 12, 'flows' => 7, 'share' => 0.2]);
    });
});

describe('ConversationPayload::build() with both directions', function (): void {
    test('adds up the two directions and makes the heavier sender the source', function (): void {
        $payload = convBuild([
            convRow('10.1.0.1', '10.0.0.1', 900, 9, 3),
            convRow('10.0.0.1', '10.1.0.1', 100, 1, 1),
            convRow('10.0.0.2', '10.1.0.9', 500, 5, 2),
        ], direction: 'both', totals: ['flows' => 6, 'packets' => 15, 'bytes' => 1500]);

        expect($payload['meta']['direction'])->toBe('both')
            ->and($payload['pairs'][0])->toMatchArray([
                'rank' => 1, 'src' => '10.1.0.1', 'dst' => '10.0.0.1', 'bytes' => 1000, 'packets' => 10, 'flows' => 4,
                'reverse' => ['bytes' => 100, 'packets' => 1, 'flows' => 1],
            ])
            // Seen in one direction only: nothing is known about its reverse.
            ->and($payload['pairs'][1])->toMatchArray(['src' => '10.0.0.2', 'dst' => '10.1.0.9', 'bytes' => 500, 'reverse' => null])
        ;
    });

    test('orients by the metric of the run', function (): void {
        $rows = [convRow('10.0.0.1', '10.1.0.1', 900, 1), convRow('10.1.0.1', '10.0.0.1', 100, 9)];

        expect(convBuild($rows, direction: 'both')['pairs'][0]['src'])->toBe('10.0.0.1')
            ->and(convBuild($rows, direction: 'both', metric: 'packets')['pairs'][0]['src'])->toBe('10.1.0.1')
            ->and(convBuild($rows, direction: 'both', metric: 'packets')['pairs'][0]['reverse'])->toBe(['bytes' => 900, 'packets' => 1, 'flows' => 1])
        ;
    });

    test('merges subnets too, and leaves a subnet talking to itself one pair', function (): void {
        $payload = convBuild([
            convRow('10.0.0.0', '10.1.0.0', 300),
            convRow('10.1.0.0', '10.0.0.0', 100),
            convRow('10.0.0.0', '10.0.0.0', 50),
        ], 'net24', 'both');

        expect(array_map(static fn (array $p): string => $p['src'] . '>' . $p['dst'], $payload['pairs']))->toBe(['10.0.0.0/24>10.1.0.0/24', '10.0.0.0/24>10.0.0.0/24'])
            ->and($payload['pairs'][0]['bytes'])->toBe(400)
            ->and($payload['pairs'][1]['reverse'])->toBeNull()
        ;
    });

    test('is approximate once nfdump returned as many rows as it was asked for', function (): void {
        $rows = array_map(static fn (int $i): array => convRow('10.0.0.' . $i, '10.1.0.1', 1000 - $i), range(1, 8));

        expect(convBuild($rows, direction: 'both', topN: 2)['meta']['approximate'])->toBeTrue()
            ->and(convBuild(array_slice($rows, 0, 7), direction: 'both', topN: 2)['meta']['approximate'])->toBeFalse()
            ->and(convBuild($rows, topN: 2)['meta']['approximate'])->toBeFalse()
        ;
    });
});

describe('ConversationPayload helpers', function (): void {
    test('totals come from the text footer, and not without one', function (): void {
        expect(ConversationPayload::totalsFrom(['flows' => 3, 'bytes' => 30, 'packets' => 6, 'avgBps' => 1]))->toBe(['flows' => 3, 'packets' => 6, 'bytes' => 30])
            ->and(ConversationPayload::totalsFrom(null))->toBeNull()
        ;
    });

    test('labels subnets only', function (): void {
        expect(ConversationPayload::label('10.0.0.0', 'net24'))->toBe('10.0.0.0/24')
            ->and(ConversationPayload::label('10.0.0.0', 'net16'))->toBe('10.0.0.0/16')
            ->and(ConversationPayload::label('10.0.0.1', 'ip'))->toBe('10.0.0.1')
            ->and(ConversationPayload::label('10.0.0.1', 'port'))->toBe('10.0.0.1')
        ;
    });
});

describe('ConversationActions', function (): void {
    beforeEach(function (): void {
        conversationsTestSettings();
    });

    $params = static fn (array $overrides = []): array => $overrides + [
        'group' => 'ip', 'direction' => 'both', 'fallback' => false, 'metric' => 'bytes', 'topN' => 20, 'filter' => '',
        'lower' => '', 'upper' => '', 'start' => 1_000_000, 'end' => 1_086_400, 'live' => false, 'sources' => ['gw1', 'gw2'],
        'protocol' => 'any', 'profile' => 'live',
    ];

    test('a live result keeps its fingerprint while the window slides (1.7)', function () use ($params): void {
        $live = ConversationActions::fingerprint($params(['live' => true]));

        expect(ConversationActions::fingerprint($params(['live' => true, 'start' => 1_000_600, 'end' => 1_087_000])))->toBe($live)
            ->and(ConversationActions::fingerprint($params(['live' => true, 'start' => 1_000_600])))->not->toBe($live)
            ->and(ConversationActions::fingerprint($params(['start' => 1_000_010])))->toBe(ConversationActions::fingerprint($params()))
            ->and(ConversationActions::fingerprint($params(['start' => 1_000_600])))->not->toBe(ConversationActions::fingerprint($params()))
        ;
    });

    test('every query input is part of the fingerprint, the source order is not', function (string $key, mixed $value) use ($params): void {
        expect(ConversationActions::fingerprint($params([$key => $value])))->not->toBe(ConversationActions::fingerprint($params()));
    })->with([
        ['group', 'net24'], ['direction', 'forward'], ['metric', 'packets'], ['topN', 50], ['filter', 'proto tcp'],
        ['lower', '1M'], ['upper', '1G'], ['sources', ['gw1']], ['protocol', 'udp'], ['profile', 'other'],
    ]);

    test('the source order does not change the fingerprint', function () use ($params): void {
        expect(ConversationActions::fingerprint($params(['sources' => ['gw2', 'gw1']])))->toBe(ConversationActions::fingerprint($params()));
    });

    test('reads the signals, falling back from both directions for the port grouping', function (): void {
        [, $c] = conversationsTestCompose();
        $c->getSignal('conv_group')?->setValue('port', broadcast: false);
        $c->getSignal('sankey_topN')?->setValue('9999', broadcast: false);
        $c->getSignal('sankey_metric')?->setValue('nonsense', broadcast: false);

        expect(ConversationActions::params($c))->toMatchArray([
            'group' => 'port', 'direction' => 'forward', 'fallback' => true, 'topN' => MatrixQuery::MAX_TOP_N, 'metric' => 'bytes',
        ]);

        $c->getSignal('conv_group')?->setValue('bogus', broadcast: false);
        expect(ConversationActions::params($c))->toMatchArray(['group' => 'ip', 'direction' => 'both', 'fallback' => false]);
    });

    test('stores a run: payload, table, summary and fingerprint', function () use ($params): void {
        $p = $params();
        $query = new MatrixQuery(TimeWindow::raw($p['start'], $p['end']), ['gw1'], 'live', 'bytes', 20, direction: 'both');
        $state = new ConversationsState();
        ConversationActions::storeResult($state, $query, convResult([
            convRow('10.0.0.1', '10.1.0.1', 6000, 60, 20),
            convRow('10.1.0.1', '10.0.0.1', 1000, 10, 5),
            convRow('10.0.0.2', '10.1.0.2', 1000, 10, 5),
        ]), $p, 0.2, '/_action/ip-info-abc', now: 1_100_000);

        $payload = json_decode($state->payload, true);
        expect($payload['pairs'])->toHaveCount(2)
            ->and($payload['totals'])->toBe(['flows' => 40, 'packets' => 400, 'bytes' => 10_000])
            ->and($state->info)->toMatchArray([
                'pairs' => 2, 'share' => 0.8, 'metric' => 'bytes', 'groupBy' => 'ip', 'direction' => 'both', 'topN' => 20,
                'approximate' => false, 'start' => 1_000_000, 'end' => 1_086_400, 'live' => false, 'at' => 1_100_000,
                'fingerprint' => ConversationActions::fingerprint($p),
            ])
            ->and($state->info['others'])->toBe(['bytes' => 2000, 'packets' => 320, 'flows' => 10, 'share' => 0.2])
            ->and($state->tableHtml)->toContain('conversationsTable', '10.0.0.1', '10.1.0.2', '<td data-num data-raw="0.7">70.00%</td>', '<th scope="col" data-original-title="share_pct" data-num>')
            ->and($state->notifications[0]['code'])->toBe("nfdump -M /data/gw1 -R x -- 'proto tcp'")
        ;
    });

    test('names subnet columns as networks and adds the port column for the port grouping', function (): void {
        $subnet = ConversationPayload::build([convRow('10.0.0.0', '10.1.0.0', 10)], 'bytes', 'net24', 'forward', 5, null, '');
        $port = ConversationPayload::build([convRow('10.0.0.1', '10.1.0.1', 10, port: 443)], 'bytes', 'port', 'forward', 5, ['flows' => 1, 'packets' => 1, 'bytes' => 40], '');

        expect(ConversationActions::tableRows($subnet))->toBe([['rank' => 1, 'srcNet' => '10.0.0.0/24', 'dstNet' => '10.1.0.0/24', 'bytes' => 10, 'packets' => 1, 'flows' => 1, 'share_pct' => '']])
            ->and(ConversationActions::tableRows($port))->toBe([['rank' => 1, 'srcip' => '10.0.0.1', 'dstip' => '10.1.0.1', 'dstport' => 443, 'bytes' => 10, 'packets' => 1, 'flows' => 1, 'share_pct' => 0.25]])
        ;
    });

    test('formats shares like the charts', function (): void {
        expect(ConversationActions::percent(0.643))->toBe('64%')
            ->and(ConversationActions::percent(0.0512))->toBe('5.1%')
            ->and(ConversationActions::percent(0.0))->toBe('0%')
            ->and(ConversationActions::percent(1.0))->toBe('100%')
        ;
    });

    test('says the port fallback when it stores the run', function () use ($params): void {
        $p = $params(['group' => 'port', 'direction' => 'forward', 'fallback' => true]);
        $query = new MatrixQuery(TimeWindow::raw(0, 600), ['gw1'], 'live', 'bytes', 20, groupBy: 'port');
        $state = new ConversationsState();
        ConversationActions::storeResult($state, $query, convResult([convRow('10.0.0.1', '10.1.0.1', 10, port: 80)]), $p, 0.1, '');

        expect(array_column($state->notifications, 'message'))->toContain(ConversationActions::FALLBACK_NOTICE);
    });

    test('a failure clears the result, a cancelled run keeps it with its notices', function (): void {
        $state = new ConversationsState();
        $state->setResult('{"pairs":[]}', '', ['pairs' => 0]);
        $state->notify('warning', 'Time window clamped');
        $state->notify('success', 'nfdump: done in 0.2s.', 'nfdump -M /data');
        $kept = $state->notifications;

        // What run() and kill-nfdump leave: the notices cleared, then the Kill notice.
        $state->clearNotifications();
        $state->notify('warning', 'nfdump process (PID 42) was killed.');
        ConversationActions::storeFailure($state, new NfdumpException('nfdump was stopped (signal 15)', exitCode: 15), true, $kept);
        expect($state->isEmpty())->toBeFalse()
            ->and(array_column($state->notifications, 'message'))->toBe(['nfdump process (PID 42) was killed.', 'nfdump: done in 0.2s.', 'Time window clamped'])
        ;

        // A Kill before nfdump started killed no process, so only the kept notices come back.
        $state->clearNotifications();
        ConversationActions::restoreNotices($state, $kept);
        expect($state->notifications)->toBe($kept);

        ConversationActions::storeFailure($state, new NfdumpException('Filter syntax error: x', 'nfdump -- x', exitCode: 254), false);
        expect($state->isEmpty())->toBeTrue()
            ->and($state->notifications[0])->toMatchArray(['type' => 'error', 'message' => 'Error: Filter syntax error: x', 'code' => 'nfdump -- x'])
        ;
    });

    test('a Kill before nfdump starts, or while it runs, leaves no result to store', function (): void {
        conversationsTestSettings();
        FakeProcessor::reset();
        FakeProcessor::$defaultResponse = [convRow('10.0.0.1', '10.1.0.1', 10)];
        $query = new MatrixQuery(TimeWindow::raw(0, 600), ['gw1'], 'live', 'bytes', 20);

        expect(ConversationActions::runUnlessCancelled($query, new FakeProcessor(), static fn (): bool => true))->toBeNull()
            ->and(FakeProcessor::$calls)->toBe([])
        ;

        $checks = 0;
        $killedDuringRun = static function () use (&$checks): bool {
            return ++$checks > 1;
        };
        expect(ConversationActions::runUnlessCancelled($query, new FakeProcessor(), $killedDuringRun))->toBeNull()
            ->and(FakeProcessor::$calls)->toHaveCount(1)
            ->and(ConversationActions::runUnlessCancelled($query, new FakeProcessor(), static fn (): bool => false)?->rows)->toBe([convRow('10.0.0.1', '10.1.0.1', 10)])
        ;
    });

    test('marks the result stale once an input differs', function (): void {
        [, $c, $states] = conversationsTestCompose();
        $state = $states->conversations;
        $state->setResult('{"pairs":[]}', '', ['fingerprint' => ConversationActions::fingerprint(ConversationActions::params($c))]);

        expect(ConversationActions::markStale($c, $state))->toBeFalse()
            ->and($c->getSignal('_conv_stale')?->bool())->toBeFalse()
        ;

        $c->getSignal('conv_group')?->setValue('net16', broadcast: false);
        expect(ConversationActions::markStale($c, $state))->toBeTrue()
            ->and($c->getSignal('_conv_stale')?->bool())->toBeTrue()
        ;
    });
});

describe('ConversationsState', function (): void {
    test('snapshots and restores a result, re-validating what comes back', function (): void {
        $state = new ConversationsState();
        $state->setResult('{"pairs":[1]}', '<table></table>', ['pairs' => 3, 'share' => 0.5, 'live' => true, 'others' => ['bytes' => 1, 'packets' => 2, 'flows' => 3, 'share' => 0.1]]);
        $state->notify('success', 'done');

        $restored = new ConversationsState();
        $restored->restore($state->snapshot());
        $garbage = new ConversationsState();
        $garbage->restore(['payload' => '{}', 'info' => ['pairs' => 'many', 'share' => 'half', 'others' => 'none']]);

        expect($restored->payload)->toBe('{"pairs":[1]}')
            ->and($restored->tableHtml)->toBe('<table></table>')
            ->and($restored->info)->toBe($state->info)
            ->and($restored->resultId)->toBe($state->resultId)
            ->and($restored->notifications)->toBe($state->notifications)
            ->and($garbage->info['pairs'])->toBe(0)
            ->and($garbage->info['share'])->toBeNull()
            ->and($garbage->info['others'])->toBeNull()
            ->and((new ConversationsState())->snapshot())->toBe([])
        ;
    });

    test('a revived tab gets its pair count back', function (): void {
        conversationsTestSettings();
        $app = new Via(new ViaConfig());
        $states = new PageStates();
        $states->conversations->setResult('{"pairs":[]}', '', ['pairs' => 7]);
        Revival::persist(new Context('ctx-conv-revive', '/', $app), $app, $states);

        $revived = new Context('ctx-conv-revive', '/', $app);
        $pairs = $revived->signal(0, '_conv_pairs');
        Revival::restore($revived, $app, new PageStates());

        expect($pairs->int())->toBe(7);
    });
});

describe('ConversationsPage', function (): void {
    test('says how much of the metric the listed pairs cover', function (): void {
        expect(ConversationsPage::summary(['pairs' => 20, 'share' => 0.643, 'metric' => 'bytes']))->toBe('Top 20 pairs = 64% of bytes')
            ->and(ConversationsPage::summary(['pairs' => 1, 'share' => 0.05, 'metric' => 'packets']))->toBe('Top pair = 5.0% of packets')
            ->and(ConversationsPage::summary(['pairs' => 1200, 'share' => null, 'metric' => 'bytes']))->toBe('Top 1,200 pairs by bytes')
            ->and(ConversationsPage::summary(['pairs' => 0, 'share' => null, 'metric' => 'bytes']))->toBe('No pairs')
        ;
    });

    test('names what the pairs leave over, and nothing when that is nothing', function (): void {
        $info = ['topN' => 20, 'metric' => 'bytes', 'others' => ['bytes' => 1_288_490_189, 'packets' => 4512, 'flows' => 310, 'share' => 0.36]];

        expect(ConversationsPage::others($info))->toBe('Others (not in top 20): 1.200 GiB, 4,512 packets, 310 flows, 36% of bytes')
            ->and(ConversationsPage::others(['others' => ['bytes' => 0, 'packets' => 0, 'flows' => 0, 'share' => 0.0]] + $info))->toBe('')
            ->and(ConversationsPage::others(['others' => null] + $info))->toBe('')
        ;
    });

    test('a live result says the window has moved on after five minutes', function (): void {
        $state = new ConversationsState();
        $state->setResult('{"pairs":[]}', '', ['live' => true, 'at' => 1_000]);

        expect(ConversationsPage::aged($state, 1_000 + ConversationsPage::LIVE_AGE - 1))->toBeFalse()
            ->and(ConversationsPage::aged($state, 1_000 + ConversationsPage::LIVE_AGE))->toBeTrue()
        ;

        $state->setResult('{"pairs":[]}', '', ['live' => false, 'at' => 1_000]);
        expect(ConversationsPage::aged($state, 1_000_000))->toBeFalse();
    });

    test('renders the empty state before the first run', function (): void {
        [$app, $c, $states] = conversationsTestCompose();
        $html = $c->render('pages/conversations.html.twig', Shell::render($c, $app, $states, false));

        expect($html)->toContain('Choose how to group conversations and press Run.', 'id="convGroupBy"', 'id="convDirBoth"', 'id="sankeyTopN"', 'data-run="conversations"')
            ->and($html)->not->toContain('role="tablist"', '<nfsen-sankey')
            // A filter applied from the drawer writes the signal without an input event.
            ->and($html)->toMatch('/id="conversationsQueryFields"\s+data-effect="const moved = window\.nfsenChanged\(el, [^"]*String\(\$' . preg_quote((string) $c->getSignal('sankey_filter')?->id(), '/') . ' /')
        ;
    });

    test('renders one result three ways, sending the payload once (D26)', function (): void {
        [$app, $c, $states] = conversationsTestCompose();
        $query = new MatrixQuery(TimeWindow::raw(1_000_000, 1_086_400), ['gw1'], 'live', 'bytes', 20);
        ConversationActions::storeResult($states->conversations, $query, convResult([convRow('10.0.0.1', '10.1.0.1', 6000)]), ConversationActions::params($c), 0.1, '/ip');

        $first = $c->render('pages/conversations.html.twig', Shell::render($c, $app, $states, false));
        $second = $c->render('pages/conversations.html.twig', Shell::render($c, $app, $states, true));
        $id = $states->conversations->resultId;

        expect($first)->toContain('id="convView-sankey"', 'id="convView-matrix"', 'id="convView-pairs"', '<nfsen-sankey data-conversation="', '<nfsen-matrix data-conversation="', 'id="conversationsTable"', 'Top pair = 60% of bytes', 'id="convExportMenu"')
            ->and($first)->toContain(
                'role="img" aria-label="Sankey of the top pair by bytes, and the rest as Others. The IP pairs view lists the same pairs as a table."',
                'role="img" aria-label="Matrix of the top pair by bytes, sources against destinations. The IP pairs view lists the same pairs as a table."',
            )
            ->and($first)->toContain("id=\"conv-sankey-{$id}\" data-ignore-morph><nfsen-sankey")
            ->and($second)->toContain("id=\"conv-sankey-{$id}\" data-ignore-morph></div>")
            ->and($second)->not->toContain('<nfsen-sankey')
            ->and('<!DOCTYPE html><html lang="en"><head><title>t</title></head><body>' . $first . '</body></html>')->toBeValidHtml()
        ;
    });

    test('escapes nfdump\'s messages (D21)', function (): void {
        [$app, $c, $states] = conversationsTestCompose();
        $state = $states->conversations;
        $state->notifyFailure(new NfdumpException("Unknown protocol: <b>x</b> at '\"<b>x</b>\"'", "nfdump -M /data -- 'proto \"<b>x</b>\"'"));

        $html = $c->render('pages/conversations.html.twig', Shell::render($c, $app, $states, false));

        expect($html)->not->toContain('<b>x</b>')
            ->and($html)->toContain(
                'Error: Unknown protocol: &lt;b&gt;x&lt;/b&gt;',
                '<code>nfdump -M /data -- &#039;proto &quot;&lt;b&gt;x&lt;/b&gt;&quot;&#039;</code>',
                "?page=conversations&id={$state->notifications[0]['id']}')",
            )
        ;
    });
});
