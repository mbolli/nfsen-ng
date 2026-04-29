<?php

declare(strict_types=1);
use Dom\HTMLDocument;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeValidHtml', function () {
    // Use PHP 8.4's DOM HTML5 parser for proper validation
    $this->toBeString();

    try {
        $doc = HTMLDocument::createFromString(
            $this->value,
            \Dom\HTML_NO_DEFAULT_NS
        );

        // If we get here, the HTML parsed successfully
        return $this->and($doc)->toBeInstanceOf(HTMLDocument::class);
    } catch (Exception $e) {
        // Parsing failed - this will cause the test to fail
        return $this->and(false)->toBeTrue("HTML parsing failed: {$e->getMessage()}");
    }
});

expect()->extend('toContainString', fn (string $needle) => $this->toBeString()
    ->and(str_contains($this->value, $needle))->toBeTrue());

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a mock settings array for testing.
 */
function mockSettings(): array {
    return [
        'general' => [
            'ports' => [80, 443, 22],
            'sources' => ['gateway', 'server1'],
            'db' => 'Rrd',
            'processor' => 'Nfdump',
        ],
        'frontend' => [
            'reload_interval' => 300,
            'defaults' => [],
        ],
        'nfdump' => [
            'binary' => '/usr/bin/nfdump',
            'profiles-data' => '/var/nfdump/profiles-data',
            'profile' => 'live',
            'max-processes' => 4,
        ],
        'db' => [
            'RRD' => [
                'path' => '/var/nfdump/rrd',
                'import_years' => 3,
            ],
        ],
        'log' => [
            'priority' => LOG_WARNING,
        ],
    ];
}
