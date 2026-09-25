<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\processor\FilterValidator;

/** @return array<string, array{valid: bool, message: string}> */
function filterValidatorCache(): array {
    return (new ReflectionProperty(FilterValidator::class, 'cache'))->getValue();
}

function clearFilterValidatorCache(): void {
    (new ReflectionProperty(FilterValidator::class, 'cache'))->setValue(null, []);
}

describe('FilterValidator', function (): void {
    $bin = static fn (string $name): string => dirname(__DIR__) . '/Support/bin/' . $name;
    $real = '/usr/local/nfdump/bin/nfdump';

    afterEach(function (): void {
        foreach (['NFDUMP_STUB_STDOUT', 'NFDUMP_STUB_STDERR', 'NFDUMP_STUB_EXIT', 'NFDUMP_STUB_ARGS'] as $name) {
            putenv($name);
        }
    });

    test('an empty filter is valid without running nfdump', function (): void {
        expect(FilterValidator::validate('', '/nonexistent/nfdump'))->toBe(['valid' => true, 'message' => ''])
            ->and(FilterValidator::validate("  \n ", '/nonexistent/nfdump'))->toBe(['valid' => true, 'message' => ''])
        ;
    });

    test('a filter nfdump accepts is valid', function () use ($bin): void {
        expect(FilterValidator::validate('proto tcp', $bin('nfdump-z-valid')))->toBe(['valid' => true, 'message' => '']);
    });

    test('a syntax error reads without the line prefix of a one-line filter', function () use ($bin): void {
        expect(FilterValidator::validate('src ip x', $bin('nfdump-z-syntax-error')))
            ->toBe(['valid' => false, 'message' => "syntax error at 'x'"])
        ;
    });

    test('a multi-line filter keeps the line number', function () use ($bin): void {
        expect(FilterValidator::validate("proto tcp\nand src ip x", $bin('nfdump-z-syntax-error')))
            ->toBe(['valid' => false, 'message' => "Line 2: syntax error at 'x'"])
        ;
    });

    test('drops the protocol table printed after an unknown protocol', function () use ($bin): void {
        expect(FilterValidator::validate('proto foobar', $bin('nfdump-z-unknown-proto')))
            ->toBe(['valid' => false, 'message' => "Unknown protocol: foobar at 'foobar'"])
        ;
    });

    // 1.7.8 quotes a quoted string whole, so whoever shows the message has to escape it.
    test('returns nfdump\'s text verbatim, markup from the filter included', function () use ($bin): void {
        putenv('NFDUMP_STUB_STDOUT=' . "Line 1: Unknown protocol: <b>x</b> at '\"<b>x</b>\"'\nValid protocols:\n  0: 0\n");
        putenv('NFDUMP_STUB_EXIT=254');

        expect(FilterValidator::validate('proto "<b>x</b>"', $bin('nfdump-canned')))
            ->toBe(['valid' => false, 'message' => "Unknown protocol: <b>x</b> at '\"<b>x</b>\"'"])
        ;
    });

    test('a failure reported on stderr only gives the first stderr line', function () use ($bin): void {
        expect(FilterValidator::validate('host nonexistent.invalid', $bin('nfdump-z-dns-failure')))
            ->toBe(['valid' => false, 'message' => 'Failed to resolve IP address for nonexistent.invalid: Unknown error'])
        ;
    });

    test('a check that runs out of time is not valid, says so and is not cached', function () use ($bin): void {
        if (!is_executable('/usr/bin/timeout')) {
            $this->markTestSkipped('/usr/bin/timeout is not installed here');
        }

        $start = microtime(true);
        $answer = FilterValidator::validate('host slow.example', $bin('nfdump-z-slow'));

        expect($answer)->toBe(['valid' => false, 'message' => FilterValidator::UNCHECKED])
            ->and(microtime(true) - $start)->toBeLessThan(FilterValidator::TIMEOUT_SECONDS + 2)
            ->and(filterValidatorCache())->not->toHaveKey($bin('nfdump-z-slow') . "\0host slow.example")
        ;
    });

    test('a NUL byte is rejected without running nfdump', function (): void {
        expect(FilterValidator::validate("proto tcp\0", '/nonexistent/nfdump')['valid'])->toBeFalse();
    });

    test('a binary that cannot be run is not a valid answer', function (): void {
        expect(FilterValidator::validate('proto tcp', '/nonexistent/nfdump')['valid'])->toBeFalse();
    });

    // A filter such as `-V` or `-w/tmp/x` must reach nfdump as a filter, not as an option.
    test('passes the filter after the end of the options', function () use ($bin): void {
        $argsFile = (string) tempnam(sys_get_temp_dir(), 'nfdump-args');
        putenv('NFDUMP_STUB_ARGS=' . $argsFile);

        FilterValidator::validate('-w/tmp/x', $bin('nfdump-canned'));
        $args = file($argsFile, FILE_IGNORE_NEW_LINES);
        unlink($argsFile);

        expect($args)->toBe(['-Z', '--', '-w/tmp/x']);
    });

    test('remembers an answer per binary and filter', function () use ($bin): void {
        clearFilterValidatorCache();
        $canned = $bin('nfdump-canned');

        expect(FilterValidator::validate('cached filter', $canned)['valid'])->toBeTrue();

        putenv('NFDUMP_STUB_STDOUT=' . "Line 1: syntax error at 'x'\n");
        putenv('NFDUMP_STUB_EXIT=254');

        expect(FilterValidator::validate('cached filter', $canned)['valid'])->toBeTrue()
            ->and(FilterValidator::validate('another filter', $canned))->toBe(['valid' => false, 'message' => "syntax error at 'x'"])
            ->and(FilterValidator::validate('cached filter', $bin('nfdump-z-syntax-error'))['valid'])->toBeFalse()
        ;
    });

    test('keeps the most recently used answers up to the cache size', function () use ($bin): void {
        clearFilterValidatorCache();
        $canned = $bin('nfdump-canned');

        FilterValidator::validate('lru kept', $canned);
        putenv('NFDUMP_STUB_EXIT=254');
        for ($i = 0; $i < FilterValidator::CACHE_SIZE - 1; ++$i) {
            FilterValidator::validate('lru ' . $i, $canned);
        }

        // A hit makes 'lru kept' the newest, so the next miss evicts 'lru 0' instead.
        expect(FilterValidator::validate('lru kept', $canned)['valid'])->toBeTrue();
        FilterValidator::validate('lru overflow', $canned);

        expect(filterValidatorCache())->toHaveCount(FilterValidator::CACHE_SIZE)
            ->and(filterValidatorCache())->not->toHaveKey($canned . "\0lru 0")
            ->and(FilterValidator::validate('lru kept', $canned)['valid'])->toBeTrue()
        ;
    });

    test('uses the configured binary by default', function () use ($bin): void {
        Config::$settings = Settings::fromArray(array_replace_recursive(mockSettings(), ['nfdump' => ['binary' => $bin('nfdump-z-syntax-error')]]));

        expect(FilterValidator::validate('default binary check')['valid'])->toBeFalse();

        Config::$settings = Settings::fromArray(mockSettings());
    });

    test('reports what the real nfdump says', function () use ($real): void {
        if (!is_executable($real)) {
            $this->markTestSkipped('nfdump is not installed here');
        }

        expect(FilterValidator::validate('proto tcp and dst port 443', $real))->toBe(['valid' => true, 'message' => ''])
            ->and(FilterValidator::validate('proto tcp and', $real))->toBe(['valid' => false, 'message' => "syntax error at ''"])
            ->and(FilterValidator::validate('proto foobar', $real))->toBe(['valid' => false, 'message' => "Unknown protocol: foobar at 'foobar'"])
            ->and(FilterValidator::validate("proto tcp\nand port 80\nand bogus", $real)['message'])->toStartWith('Line 3: ')
            ->and(FilterValidator::validate('-V', $real)['valid'])->toBeFalse()
            ->and(FilterValidator::validate('proto "<b>x</b>"', $real))->toBe(['valid' => false, 'message' => "Unknown protocol: <b>x</b> at '\"<b>x</b>\"'"])
        ;
    });
});
