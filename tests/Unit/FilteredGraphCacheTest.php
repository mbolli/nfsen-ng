<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\FilteredGraphCache;
use mbolli\nfsen_ng\common\QueryCancel;

function series(int $step = 300): array {
    return ['start' => 0, 'end' => 300, 'step' => $step, 'legend' => ['a'], 'data' => [0 => [1.0]]];
}

beforeEach(function (): void {
    FilteredGraphCache::clear();
    QueryCancel::clearAll();
});

describe('FilteredGraphCache::key', function (): void {
    $args = [1000, 2000, ['gateway'], 'proto tcp', 'flows', 'protocols', 150, 'live'];

    test('is stable for identical inputs', function () use ($args): void {
        expect(FilteredGraphCache::key(...$args))->toBe(FilteredGraphCache::key(...$args));
    });

    // Every argument must participate — a key that ignores one serves the wrong graph.
    test('changes when any single input changes', function () use ($args): void {
        $baseline = FilteredGraphCache::key(...$args);
        $mutations = [
            [1001, 2000, ['gateway'], 'proto tcp', 'flows', 'protocols', 150, 'live'],
            [1000, 2001, ['gateway'], 'proto tcp', 'flows', 'protocols', 150, 'live'],
            [1000, 2000, ['swi6'], 'proto tcp', 'flows', 'protocols', 150, 'live'],
            [1000, 2000, ['gateway'], 'proto udp', 'flows', 'protocols', 150, 'live'],
            [1000, 2000, ['gateway'], 'proto tcp', 'bytes', 'protocols', 150, 'live'],
            [1000, 2000, ['gateway'], 'proto tcp', 'flows', 'sources', 150, 'live'],
            [1000, 2000, ['gateway'], 'proto tcp', 'flows', 'protocols', 300, 'live'],
            [1000, 2000, ['gateway'], 'proto tcp', 'flows', 'protocols', 150, 'archive'],
        ];

        foreach ($mutations as $i => $mutation) {
            expect(FilteredGraphCache::key(...$mutation))->not->toBe($baseline, "mutation {$i} collided");
        }
    });

    test('distinguishes source order and membership', function (): void {
        $a = FilteredGraphCache::key(0, 1, ['a', 'b'], '', 'flows', 'sources', 10, 'live');
        $b = FilteredGraphCache::key(0, 1, ['b', 'a'], '', 'flows', 'sources', 10, 'live');
        expect($a)->not->toBe($b);
    });
});

describe('FilteredGraphCache storage', function (): void {
    test('returns null on a miss', function (): void {
        expect(FilteredGraphCache::get('nope'))->toBeNull();
    });

    test('round-trips a stored series', function (): void {
        FilteredGraphCache::put('k', series(600));
        expect(FilteredGraphCache::get('k')['step'])->toBe(600);
    });

    test('has() agrees with get()', function (): void {
        FilteredGraphCache::put('k', series());
        expect(FilteredGraphCache::has('k'))->toBeTrue()
            ->and(FilteredGraphCache::has('other'))->toBeFalse()
        ;
    });

    // Expiry is measured from the last read, not the build: a tab left open re-renders on
    // every import, and the graph must not empty itself under a user who is still looking.
    test('reading an entry keeps it alive past the build-time TTL', function (): void {
        FilteredGraphCache::put('k', series(), now: 1000);

        // Read just inside the window, repeatedly, well past 1000 + TTL.
        for ($t = 1000; $t < 1000 + FilteredGraphCache::TTL * 4; $t += FilteredGraphCache::TTL - 10) {
            expect(FilteredGraphCache::get('k', $t))->not->toBeNull();
        }
    });

    test('a read refreshes the eviction position too', function (): void {
        FilteredGraphCache::put('old', series(), now: 1000);
        for ($i = 0; $i < FilteredGraphCache::MAX_ENTRIES - 1; ++$i) {
            FilteredGraphCache::put('f' . $i, series(), now: 1000);
        }
        FilteredGraphCache::get('old', 1000);          // touch the eviction candidate
        FilteredGraphCache::put('overflow', series(), now: 1000);

        expect(FilteredGraphCache::get('old', 1000))->not->toBeNull()
            ->and(FilteredGraphCache::get('f0', 1000))->toBeNull()
        ;
    });

    // Separate tests, because a read touches the entry — asserting both ends of the
    // boundary in one test would have the first read keep the entry alive for the second.
    test('is still readable at exactly the TTL boundary', function (): void {
        FilteredGraphCache::put('k', series(), now: 1000);

        expect(FilteredGraphCache::get('k', 1000 + FilteredGraphCache::TTL))->not->toBeNull();
    });

    test('expires an entry nobody has read within the TTL', function (): void {
        FilteredGraphCache::put('k', series(), now: 1000);

        expect(FilteredGraphCache::get('k', 1000 + FilteredGraphCache::TTL + 1))->toBeNull();
    });

    test('drops the expired entry rather than leaking it', function (): void {
        FilteredGraphCache::put('k', series(), now: 1000);
        FilteredGraphCache::get('k', 9999999);

        expect(FilteredGraphCache::count())->toBe(0);
    });

    test('evicts oldest first past the entry ceiling', function (): void {
        for ($i = 0; $i < FilteredGraphCache::MAX_ENTRIES + 3; ++$i) {
            FilteredGraphCache::put('k' . $i, series($i + 1), now: 1000 + $i);
        }

        expect(FilteredGraphCache::count())->toBe(FilteredGraphCache::MAX_ENTRIES)
            ->and(FilteredGraphCache::get('k0', 1000))->toBeNull()
            ->and(FilteredGraphCache::get('k2', 1000))->toBeNull()
            ->and(FilteredGraphCache::get('k3', 1000))->not->toBeNull()
        ;
    });

    test('re-writing a key refreshes its eviction position', function (): void {
        FilteredGraphCache::put('old', series(), now: 1000);
        for ($i = 0; $i < FilteredGraphCache::MAX_ENTRIES - 1; ++$i) {
            FilteredGraphCache::put('f' . $i, series(), now: 1000);
        }
        // 'old' is now the eviction candidate; touching it should save it.
        FilteredGraphCache::put('old', series(999), now: 1000);
        FilteredGraphCache::put('overflow', series(), now: 1000);

        expect(FilteredGraphCache::get('old', 1000)['step'])->toBe(999)
            ->and(FilteredGraphCache::get('f0', 1000))->toBeNull()
        ;
    });

    test('clear empties the store', function (): void {
        FilteredGraphCache::put('k', series());
        FilteredGraphCache::clear();

        expect(FilteredGraphCache::count())->toBe(0);
    });
});

describe('QueryCancel', function (): void {
    test('is not requested by default', function (): void {
        expect(QueryCancel::isRequested('ctx'))->toBeFalse();
    });

    test('records and reports a request', function (): void {
        QueryCancel::request('ctx');
        expect(QueryCancel::isRequested('ctx'))->toBeTrue();
    });

    // One tab's Kill must not abort another tab's query.
    test('is scoped per context', function (): void {
        QueryCancel::request('ctx-a');
        expect(QueryCancel::isRequested('ctx-b'))->toBeFalse();
    });

    test('clear removes only that context flag', function (): void {
        QueryCancel::request('ctx-a');
        QueryCancel::request('ctx-b');
        QueryCancel::clear('ctx-a');

        expect(QueryCancel::isRequested('ctx-a'))->toBeFalse()
            ->and(QueryCancel::isRequested('ctx-b'))->toBeTrue()
        ;
    });
});
