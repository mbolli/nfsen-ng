<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Debug;
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

describe('the no-traffic port report', function (): void {
    /** Runs the end-of-import report over a set of silent ports and returns what it logged. */
    function silentPortMessage(array $silent): string {
        $r = new ReflectionClass(Import::class);
        $import = $r->newInstanceWithoutConstructor();
        foreach (['d' => Debug::getInstance(), 'portsWithData' => [8080 => true], 'portsWithoutData' => array_fill_keys($silent, true)] as $name => $value) {
            $p = $r->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($import, $value);
        }

        Debug::drainBuffer();
        $m = $r->getMethod('reportPortsWithoutData');
        $m->setAccessible(true);
        $m->invoke($import);

        $entries = Debug::drainBuffer();

        return $entries === [] ? '' : $entries[0]['msg'];
    }

    // A monitored service-port list runs to seventy entries. Naming all of them buried the
    // sentence that explains what the message means, which is the only useful part.
    test('leads with the count and names at most a handful', function (): void {
        $message = silentPortMessage(range(20, 60));

        expect($message)->toStartWith('41 of the configured ports saw no traffic')
            ->and($message)->toContain('(20, 21, 22, 23, 24, 25, 26, 27 and 33 more)')
        ;
    });

    // "and 1 more" is longer than the port it stands for.
    test('lists one over the cap rather than hiding it', function (): void {
        expect(silentPortMessage(range(20, 28)))->toContain('(20, 21, 22, 23, 24, 25, 26, 27, 28),');
    });

    test('reads as a sentence for a single port', function (): void {
        expect(silentPortMessage([443]))->toStartWith('Port 443 saw no traffic in this run, so its graph stays flat.');
    });

    // Nothing at all came through: the capture files or the filter are the problem, and a list
    // of every configured port is noise on top of an already obvious failure.
    test('says nothing when no port had traffic', function (): void {
        $r = new ReflectionClass(Import::class);
        $import = $r->newInstanceWithoutConstructor();
        foreach (['d' => Debug::getInstance(), 'portsWithData' => [], 'portsWithoutData' => [80 => true]] as $name => $value) {
            $p = $r->getProperty($name);
            $p->setAccessible(true);
            $p->setValue($import, $value);
        }
        Debug::drainBuffer();
        $m = $r->getMethod('reportPortsWithoutData');
        $m->setAccessible(true);
        $m->invoke($import);

        expect(Debug::drainBuffer())->toBe([]);
    });
});
