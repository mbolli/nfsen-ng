<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\Settings;

/**
 * How the per-port import queries a port, and how it reads the result (#173).
 *
 * Asserted against the source rather than by running an import: the alternative needs capture
 * files, an RRD extension and a writable datasource, and what matters here is which query is
 * issued and which rows are counted, not what nfdump returns.
 */
function importSource(): string {
    return file_get_contents((new ReflectionClass(Import::class))->getFileName());
}

describe('per-port import direction', function (): void {
    test('the direction drives both the filter and the statistic', function (): void {
        $source = importSource();

        expect($source)->toContain('$direction = Config::$settings->portDirection')
            ->and($source)->toContain("setFilter(\$prefix . 'port ' . \$port)")
        ;
    });

    // Counting only this port's rows is what stops -s port:p double counting: it reports both
    // ports of every matching flow, so a request from 10007 to 443 yields a row for each.
    test('counts only the rows belonging to the port being written', function (): void {
        expect(importSource())->toContain("(int) \$row['val'] !== \$port");
    });
});

describe('Settings::normalizePortDirection()', function (): void {
    // The default has to stay what every release so far counted: widening it silently would
    // step every existing port series upward, against data already written under the old
    // meaning.
    test('defaults to the destination port', function (): void {
        expect(Settings::normalizePortDirection(null))->toBe('dst')
            ->and(Settings::normalizePortDirection(''))->toBe('dst')
            ->and(Settings::normalizePortDirection('nonsense'))->toBe('dst')
        ;
    });

    test('accepts either-direction and source counting when asked', function (): void {
        expect(Settings::normalizePortDirection('any'))->toBe('any')
            ->and(Settings::normalizePortDirection('SRC'))->toBe('src')
            ->and(Settings::normalizePortDirection(' any '))->toBe('any')
        ;
    });
});
