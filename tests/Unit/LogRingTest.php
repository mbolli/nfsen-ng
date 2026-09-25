<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\LogRing;

describe('LogRing', function (): void {
    test('keeps only the newest entries up to its capacity', function (): void {
        $ring = new LogRing(3);
        foreach (['one', 'two', 'three', 'four', 'five'] as $message) {
            $ring->push(LOG_INFO, $message);
        }

        expect(array_column($ring->recent(), 'message'))->toBe(['five', 'four', 'three'])
            ->and(array_column($ring->recent(), 'seq'))->toBe([5, 4, 3])
            ->and($ring->lastSeq())->toBe(5)
        ;
    });

    test('returns entries newest first with level names and millisecond timestamps', function (): void {
        $ring = new LogRing();
        $before = (int) floor(microtime(true) * 1000);
        $ring->push(LOG_ERR, 'disk full');
        $ring->push(LOG_DEBUG, 'nfdump -M /data');
        $after = (int) ceil(microtime(true) * 1000);

        $entries = $ring->recent();

        expect($entries)->toHaveCount(2)
            ->and($entries[0])->toMatchArray(['seq' => 2, 'level' => LOG_DEBUG, 'levelName' => 'debug', 'message' => 'nfdump -M /data'])
            ->and($entries[1])->toMatchArray(['seq' => 1, 'level' => LOG_ERR, 'levelName' => 'error', 'message' => 'disk full'])
            ->and(array_keys($entries[0]))->toBe(['seq', 'ts', 'level', 'levelName', 'message'])
            ->and($entries[1]['ts'])->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after)
        ;
    });

    test('limits the number of entries returned', function (): void {
        $ring = new LogRing();
        for ($i = 1; $i <= 10; ++$i) {
            $ring->push(LOG_INFO, "line {$i}");
        }

        expect(array_column($ring->recent(2), 'message'))->toBe(['line 10', 'line 9']);
    });

    test('filters by the most verbose priority to include', function (): void {
        $ring = new LogRing();
        $ring->push(LOG_DEBUG, 'debug');
        $ring->push(LOG_WARNING, 'warning');
        $ring->push(LOG_INFO, 'info');
        $ring->push(LOG_ERR, 'error');
        $ring->push(LOG_CRIT, 'critical');

        expect(array_column($ring->recent(200, LOG_WARNING), 'message'))->toBe(['critical', 'error', 'warning'])
            ->and(array_column($ring->recent(200, LOG_ERR), 'message'))->toBe(['critical', 'error'])
            ->and(array_column($ring->recent(1, LOG_WARNING), 'message'))->toBe(['critical'])
        ;
    });

    test('since() returns only newer entries, oldest first', function (): void {
        $ring = new LogRing();
        foreach (['a', 'b', 'c', 'd'] as $message) {
            $ring->push(LOG_NOTICE, $message);
        }

        expect(array_column($ring->since(2), 'message'))->toBe(['c', 'd'])
            ->and(array_column($ring->since(0), 'seq'))->toBe([1, 2, 3, 4])
            ->and($ring->since(4))->toBe([])
            ->and($ring->since(99))->toBe([])
        ;
    });

    test('since() a sequence that already fell out returns what is still held', function (): void {
        $ring = new LogRing(2);
        foreach (['a', 'b', 'c', 'd', 'e'] as $message) {
            $ring->push(LOG_NOTICE, $message);
        }

        expect(array_column($ring->since(1), 'message'))->toBe(['d', 'e']);
    });

    test('is empty before the first push', function (): void {
        $ring = new LogRing();

        expect($ring->recent())->toBe([])
            ->and($ring->since(0))->toBe([])
            ->and($ring->lastSeq())->toBe(0)
        ;
    });

    test('clips long messages to 4000 characters without splitting a multibyte character', function (): void {
        $ring = new LogRing();
        $ring->push(LOG_INFO, str_repeat('ä', 5000));
        $ring->push(LOG_INFO, str_repeat('x', 4000));

        [$exact, $clipped] = $ring->recent();

        expect(mb_strlen($clipped['message']))->toBe(LogRing::MAX_MESSAGE_LENGTH)
            ->and(mb_check_encoding($clipped['message'], 'UTF-8'))->toBeTrue()
            ->and($clipped['message'])->toEndWith('ä…')
            ->and($exact['message'])->toBe(str_repeat('x', 4000))
        ;
    });

    test('stores messages raw', function (): void {
        $ring = new LogRing();
        $ring->push(LOG_WARNING, '<script>alert(1)</script> & "quotes"');

        expect($ring->recent()[0]['message'])->toBe('<script>alert(1)</script> & "quotes"');
    });

    test('rejects a capacity below one', function (): void {
        new LogRing(0);
    })->throws(InvalidArgumentException::class);
});
