<?php

declare(strict_types=1);

use mbolli\nfsen_ng\pages\state\ConversationsState;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\pages\state\OverviewState;
use mbolli\nfsen_ng\pages\state\PageState;
use mbolli\nfsen_ng\pages\state\ShellState;
use mbolli\nfsen_ng\pages\state\TalkersState;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;

function pageStateTestResult(string $command = 'nfdump -M /data -R x', string $stderr = ''): QueryResult {
    return new QueryResult(rows: [], command: $command, stderr: $stderr, elapsed: 0.1, window: TimeWindow::raw(1_000, 2_000));
}

describe('notifications', function (): void {
    test('notify puts the newest first and keeps at most five', function (): void {
        $state = new OverviewState();
        foreach (range(1, 7) as $i) {
            $state->notify('info', "n{$i}");
        }

        expect(array_column($state->notifications, 'message'))->toBe(['n7', 'n6', 'n5', 'n4', 'n3'])
            ->and($state->notifications[0]['id'])->toMatch('/^[0-9a-f]{8}$/')
            ->and(count(array_unique(array_column($state->notifications, 'id'))))->toBe(5)
        ;
    });

    test('notify keeps the code apart and maps an unknown type to info', function (): void {
        $state = new FlowsState();
        $state->notify('success', 'nfdump: done.', 'nfdump -M /data');
        $state->notify('bogus', 'hello');

        expect($state->notifications[0])->toMatchArray(['type' => 'info', 'message' => 'hello', 'code' => ''])
            ->and($state->notifications[1])->toMatchArray(['type' => 'success', 'message' => 'nfdump: done.', 'code' => 'nfdump -M /data'])
        ;
    });

    test('dismiss removes only the given id, clearNotifications removes all', function (): void {
        $state = new TalkersState();
        $state->notify('warning', 'a');
        $state->notify('error', 'b');
        $state->dismiss($state->notifications[1]['id']);

        expect(array_column($state->notifications, 'message'))->toBe(['b']);

        $state->dismiss('unknown');
        expect($state->notifications)->toHaveCount(1);

        $state->clearNotifications();
        expect($state->notifications)->toBe([]);
    });

    test('notifyResult replaces the notices with the command, stderr below it', function (): void {
        $state = new FlowsState();
        $state->notify('warning', 'nfdump process (PID 7) was killed.');
        $state->notifyResult(pageStateTestResult(stderr: 'Skipped a file'), 0.25, 'Flows');

        expect($state->notifications)->toHaveCount(2)
            ->and($state->notifications[0])->toMatchArray(['type' => 'success', 'message' => 'nfdump: done in 0.25s.', 'code' => 'nfdump -M /data -R x'])
            ->and($state->notifications[1])->toMatchArray(['type' => 'warning', 'message' => 'nfdump warning:', 'code' => 'Skipped a file'])
        ;
    });

    test('notifyResult without a command names what was processed', function (): void {
        $state = new ConversationsState();
        $state->notifyResult(pageStateTestResult(command: ''), 1.5, 'Sankey data');

        expect($state->notifications[0])->toMatchArray(['type' => 'success', 'message' => 'Sankey data processed in 1.5s.', 'code' => ''])
            ->and($state->notifications)->toHaveCount(1)
        ;
    });

    test('notifyFailure keeps nfdump\'s message as plain text and the command apart (D21)', function (): void {
        $state = new FlowsState();
        $state->notify('success', 'old');
        $state->notifyFailure(new NfdumpException(
            "Filter syntax error: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'",
            'nfdump -M /data -- \'proto "<b>x</b>"\'',
        ));

        expect($state->notifications)->toHaveCount(1)
            ->and($state->notifications[0]['type'])->toBe('error')
            ->and($state->notifications[0]['message'])->toBe("Error: Filter syntax error: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'")
            ->and($state->notifications[0]['code'])->toBe('nfdump -M /data -- \'proto "<b>x</b>"\'')
        ;

        $state->notifyFailure(new RuntimeException('Unbalanced parentheses in the filter.'));
        expect($state->notifications[0]['code'])->toBe('');
    });
});

describe('snapshot and restore', function (): void {
    test('every state round-trips its results and notifications', function (PageState $state, Closure $fill): void {
        expect($state->isEmpty())->toBeTrue()
            ->and($state->snapshot())->toBe([])
        ;

        $fill($state);
        $state->notify('warning', 'kept');
        $snapshot = $state->snapshot();
        $copy = new ($state::class)();
        $copy->restore($snapshot);

        expect($state->isEmpty())->toBeFalse()
            ->and($copy->snapshot())->toBe($snapshot)
            ->and($copy->isEmpty())->toBeFalse()
        ;
    })->with([
        'overview' => [new OverviewState(), static function (OverviewState $s): void {}],
        'talkers' => [new TalkersState(), static fn (TalkersState $s) => $s->setResult('<table></table>')],
        'flows' => [new FlowsState(), static fn (FlowsState $s) => $s->setResult('<table></table>', 12)],
        'conversations' => [new ConversationsState(), static fn (ConversationsState $s) => $s->setResult('{"nodes":[1],"links":[]}')],
        'shell' => [new ShellState(), static function (ShellState $s): void {
            $s->modalHtml = '<dialog></dialog>';
        }],
    ]);

    test('restore re-validates what comes back from global state', function (): void {
        $state = new FlowsState();
        $state->restore([
            'tableHtml' => ['not', 'a', 'string'],
            'count' => '12',
            'notifications' => [
                ['id' => 'aa', 'type' => 'evil', 'message' => 'ok'],
                ['id' => 3, 'message' => 'dropped'],
                'dropped too',
            ],
        ]);

        expect($state->tableHtml)->toBe('')
            ->and($state->count)->toBe(0)
            ->and($state->notifications)->toBe([['id' => 'aa', 'type' => 'info', 'message' => 'ok', 'code' => '']])
        ;
    });

    test('a result with only notifications is not worth reviving', function (): void {
        $state = new FlowsState();
        $state->notify('error', 'Error: boom');

        expect($state->isEmpty())->toBeTrue()
            ->and($state->snapshot())->toBe([])
        ;
    });

    test('the conversations state is empty until a run stored a payload', function (): void {
        $state = new ConversationsState();

        expect($state->isEmpty())->toBeTrue()
            ->and($state->snapshot())->toBe([])
        ;

        $state->setResult('{"pairs":[]}', '<table></table>', ['pairs' => 0]);
        expect($state->isEmpty())->toBeFalse()
            ->and($state->payload)->toBe('{"pairs":[]}')
        ;
    });

    test('every stored result gets a new id, also for identical content', function (): void {
        $state = new TalkersState();
        $state->setResult('<table></table>');
        $first = $state->resultId;
        $state->setResult('<table></table>');

        expect($first)->toMatch('/^[0-9a-f]{8}$/')
            ->and($state->resultId)->not->toBe($first)
        ;
    });
});

describe('sendResult (D26)', function (): void {
    test('is true on the initial GET', function (): void {
        $state = new FlowsState();

        expect($state->sendResult('table', 'r1', false))->toBeTrue();
        $state->markRendered(true);
        expect($state->sendResult('table', 'r1', false))->toBeTrue();
    });

    test('is false for an unchanged result id on an active page', function (): void {
        $state = new FlowsState();
        $state->sendResult('table', 'r1', false);
        $state->markRendered(true);

        expect($state->sendResult('table', 'r1', true))->toBeFalse();
        $state->markRendered(true);
        expect($state->sendResult('table', 'r1', true))->toBeFalse();
    });

    test('is true for a new result id', function (): void {
        $state = new FlowsState();
        $state->sendResult('table', 'r1', false);
        $state->markRendered(true);

        expect($state->sendResult('table', 'r2', true))->toBeTrue();
        $state->markRendered(true);
        expect($state->sendResult('table', 'r2', true))->toBeFalse();
    });

    test('is true after the page was inactive', function (): void {
        $state = new FlowsState();
        $state->sendResult('table', 'r1', false);
        $state->markRendered(true);
        $state->markRendered(false);

        expect($state->sendResult('table', 'r1', true))->toBeTrue();
    });

    test('is true for a slot the previous render did not carry', function (): void {
        $state = new FlowsState();
        $state->sendResult('table', 'r1', false);
        $state->markRendered(true);

        expect($state->sendResult('raw', 'r1', true))->toBeTrue();
        $state->markRendered(true);
        expect($state->sendResult('table', 'r1', true))->toBeTrue();
    });
});

describe('ShellState', function (): void {
    test('the graph refreshes on every render, and at most every 10 s during an import', function (): void {
        $shell = new ShellState();
        $shell->graphFetchedAt = 1_000;

        expect($shell->graphDue(false, 1_001))->toBeTrue()
            ->and($shell->graphDue(true, 1_009))->toBeFalse()
            ->and($shell->graphDue(true, 1_010))->toBeTrue()
        ;
    });

    test('the data range throttle does not depend on a graph fetch', function (): void {
        $shell = new ShellState();
        $shell->rangeFetchedAt = 1_000;

        expect($shell->rangeDue(false, 1_001))->toBeTrue()
            ->and($shell->rangeDue(true, 1_009))->toBeFalse()
            ->and($shell->rangeDue(true, 1_010))->toBeTrue()
            ->and($shell->graphDue(true, 1_009))->toBeTrue()
        ;
    });

    test('a data range read makes the graph due, so it shows the window the read may have moved', function (): void {
        $shell = new ShellState();
        $shell->graphFetchedAt = 1_000;
        $shell->rangeFetchedAt = 1_000;
        $sameRender = $shell->graphDue(true, 1_005);
        $shell->rangeFetchedAt = 1_005;

        expect($sameRender)->toBeFalse()
            ->and($shell->graphDue(true, 1_005))->toBeTrue()
        ;
    });
});
