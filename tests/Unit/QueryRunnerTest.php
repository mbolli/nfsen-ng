<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\QueryRunner;

describe('QueryRunner::formatBytes', function (): void {
    test('leaves sub-kilobyte counts in bytes', function (): void {
        expect(QueryRunner::formatBytes(0))->toBe('0 B')
            ->and(QueryRunner::formatBytes(1023))->toBe('1023 B')
        ;
    });

    test('switches to binary units at 1024', function (): void {
        expect(QueryRunner::formatBytes(1024))->toBe('1 KiB')
            ->and(QueryRunner::formatBytes(1536))->toBe('1.5 KiB')
        ;
    });

    test('scales through mebi, gibi and tebi', function (): void {
        expect(QueryRunner::formatBytes(5 * 1024 ** 2))->toBe('5 MiB')
            ->and(QueryRunner::formatBytes(3 * 1024 ** 3))->toBe('3 GiB')
            ->and(QueryRunner::formatBytes(2 * 1024 ** 4))->toBe('2 TiB')
        ;
    });

    // One decimal is noise once the number is large enough to read at a glance.
    test('drops the decimal above ten units', function (): void {
        expect(QueryRunner::formatBytes((int) (42.7 * 1024 ** 2)))->toBe('43 MiB');
    });

    test('saturates at the largest unit rather than inventing one', function (): void {
        expect(QueryRunner::formatBytes(5000 * 1024 ** 4))->toEndWith(' TiB');
    });
});
