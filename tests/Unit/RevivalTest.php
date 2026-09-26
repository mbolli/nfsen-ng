<?php

declare(strict_types=1);

use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

function revivalTestStates(): PageStates {
    $states = new PageStates();
    $states->flows->setResult('<table id="flowTable"></table>', 3);
    $states->flows->notify('success', 'nfdump: done in 0.1s.', 'nfdump -M /data');
    $states->conversations->setResult('{"nodes":[],"links":[]}');

    return $states;
}

describe('snapshots', function (): void {
    test('only non-empty states are snapshotted', function (): void {
        $snapshot = Revival::snapshotOf(revivalTestStates());

        expect(array_keys($snapshot))->toBe(['flows', 'conversations'])
            ->and($snapshot['flows']['count'])->toBe(3)
            ->and(Revival::snapshotOf(new PageStates()))->toBe([])
        ;
    });

    test('restoreInto fills empty states and leaves the others alone', function (): void {
        $snapshot = Revival::snapshotOf(revivalTestStates());
        $states = new PageStates();
        $states->conversations->setResult('{"nodes":["newer"],"links":[]}');
        Revival::restoreInto($states, $snapshot);

        expect($states->flows->tableHtml)->toBe('<table id="flowTable"></table>')
            ->and($states->flows->count)->toBe(3)
            ->and($states->flows->notifications[0]['code'])->toBe('nfdump -M /data')
            ->and($states->conversations->payload)->toBe('{"nodes":["newer"],"links":[]}')
            ->and($states->talkers->isEmpty())->toBeTrue()
        ;
    });
});

describe('the snapshot map', function (): void {
    test('an unchanged snapshot writes nothing', function (): void {
        $current = Revival::snapshotOf(revivalTestStates());

        expect(Revival::merge(['ctx' => $current], 'ctx', $current))->toBeNull()
            ->and(Revival::merge([], 'ctx', []))->toBeNull()
        ;
    });

    test('a changed snapshot replaces the entry and moves it to the end', function (): void {
        $map = Revival::merge(['a' => ['flows' => ['x' => 1]], 'b' => ['flows' => ['x' => 2]]], 'a', ['flows' => ['x' => 3]]);

        expect($map)->toBe(['b' => ['flows' => ['x' => 2]], 'a' => ['flows' => ['x' => 3]]]);
    });

    test('a context whose states emptied is dropped', function (): void {
        expect(Revival::merge(['a' => ['flows' => ['x' => 1]], 'b' => ['flows' => ['x' => 2]]], 'a', []))
            ->toBe(['b' => ['flows' => ['x' => 2]]])
        ;
    });

    test('the map is capped at 200 contexts, evicting the longest idle', function (): void {
        $map = [];
        foreach (range(1, 200) as $i) {
            $map = Revival::merge($map, "ctx{$i}", ['flows' => ['i' => $i]]) ?? $map;
        }
        // ctx1 is updated, so ctx2 is now the one idle the longest.
        $map = Revival::merge($map, 'ctx1', ['flows' => ['i' => 'again']]) ?? $map;
        $map = Revival::merge($map, 'ctx201', ['flows' => ['i' => 201]]) ?? $map;

        expect($map)->toHaveCount(Revival::MAX_CONTEXTS)
            ->and($map)->not->toHaveKey('ctx2')
            ->and($map)->toHaveKey('ctx1')
            ->and(array_key_last($map))->toBe('ctx201')
            ->and(array_key_first($map))->toBe('ctx3')
        ;
    });
});

describe('restore and persist in app-global state', function (): void {
    beforeEach(function (): void {
        $this->app = new Via(new ViaConfig());
    });

    test('a revived context gets its results and flows_count back, a new one does not', function (): void {
        $c = new Context('ctx-revive', '/', $this->app);
        Revival::persist($c, $this->app, revivalTestStates());

        $revived = new Context('ctx-revive', '/', $this->app);
        $count = $revived->signal(0, 'flows_count');
        $states = new PageStates();
        Revival::restore($revived, $this->app, $states);

        $fresh = new PageStates();
        Revival::restore(new Context('ctx-other', '/', $this->app), $this->app, $fresh);

        expect($states->flows->tableHtml)->toBe('<table id="flowTable"></table>')
            ->and($count->int())->toBe(3)
            ->and($fresh->flows->isEmpty())->toBeTrue()
        ;
    });

    test('restore runs on the first render only', function (): void {
        $c = new Context('ctx-once', '/', $this->app);
        Revival::persist($c, $this->app, revivalTestStates());

        $states = new PageStates();
        Revival::restore($c, $this->app, $states);
        // A failed rerun empties the table; the next render must not bring the old one back.
        $states->flows->clearResult();
        Revival::restore($c, $this->app, $states);

        expect($states->flows->tableHtml)->toBe('');
    });

    test('persist keeps the entry in step with the states', function (): void {
        $c = new Context('ctx-persist', '/', $this->app);
        $states = revivalTestStates();
        Revival::persist($c, $this->app, $states);
        expect($this->app->globalState(Revival::KEY)['ctx-persist'])->toHaveKeys(['flows', 'conversations']);

        $states->flows->clearResult();
        $states->conversations->clearResult();
        Revival::persist($c, $this->app, $states);
        expect($this->app->globalState(Revival::KEY))->not->toHaveKey('ctx-persist');
    });
});
