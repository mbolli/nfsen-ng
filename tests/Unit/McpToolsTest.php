<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\mcp\Tool\ToolInterface;
use mbolli\nfsen_ng\mcp\ToolRegistry;
use Mcp\Exception\ToolCallException;

describe('MCP tool declarations', function (): void {
    test('every registered tool implements the contract', function (): void {
        foreach (ToolRegistry::tools() as $tool) {
            expect(is_subclass_of($tool, ToolInterface::class))->toBeTrue()
                ->and(is_callable(new $tool()))->toBeTrue()
            ;
        }
    });

    test('tool names are unique and snake_case', function (): void {
        $names = array_map(static fn (string $tool): string => $tool::name(), ToolRegistry::tools());

        expect($names)->toBe(array_unique($names));
        foreach ($names as $name) {
            expect($name)->toMatch('/^[a-z][a-z0-9_]*$/');
        }
    });

    // The cost tier is the one thing a caller cannot infer from the name, and forgetting it
    // is how an agent ends up scanning hours of captures for something the graph knew.
    test('every description states its cost tier', function (): void {
        foreach (ToolRegistry::tools() as $tool) {
            expect(ToolRegistry::describe($tool))->toContain('Cost:');
        }
    });

    test('input schemas are object schemas', function (): void {
        foreach (ToolRegistry::tools() as $tool) {
            $schema = $tool::inputSchema();

            expect($schema['type'])->toBe('object')
                ->and($schema)->toHaveKey('properties')
            ;
        }
    });

    // The SDK binds arguments by parameter name, so a schema property with no matching
    // parameter is silently dropped at call time.
    test('every schema property matches a handler parameter', function (): void {
        foreach (ToolRegistry::tools() as $tool) {
            $properties = $tool::inputSchema()['properties'];
            if ($properties instanceof stdClass) {
                continue;
            }

            $parameters = array_map(
                static fn (ReflectionParameter $p): string => $p->getName(),
                (new ReflectionMethod($tool, '__invoke'))->getParameters()
            );

            foreach (array_keys($properties) as $property) {
                expect($parameters)->toContain($property);
            }
        }
    });

    test('required arguments have no default', function (): void {
        foreach (ToolRegistry::tools() as $tool) {
            $required = $tool::inputSchema()['required'] ?? [];

            foreach ((new ReflectionMethod($tool, '__invoke'))->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $required, true)) {
                    expect($parameter->isDefaultValueAvailable())->toBeFalse();
                }
            }
        }
    });
});

describe('Tier', function (): void {
    test('each tier explains what it costs', function (): void {
        expect(Tier::Cheap->note())->toContain('cheap')
            ->and(Tier::Expensive->note())->toContain('expensive')
            ->and(Tier::Expensive->note())->toContain('estimate_cost')
        ;
    });
});

describe('Args', function (): void {
    test('normalises a mixed list into strings', function (): void {
        expect(Args::stringList([' gw ', 42, null, '', 'dmz']))->toBe(['gw', '42', 'dmz']);
    });

    test('drops non-numeric entries from an integer list', function (): void {
        expect(Args::intList([80, '443', 'nonsense', null]))->toBe([80, 443]);
    });

    test('a missing list is an empty list, not a failure', function (): void {
        expect(Args::stringList(null))->toBe([])
            ->and(Args::intList('not a list'))->toBe([])
        ;
    });
});

describe('Guard', function (): void {
    beforeEach(function (): void {
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump', 'max_stats_window' => 86400],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/tmp', 'profile' => 'live', 'max-processes' => 1],
            'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
            'log' => ['priority' => LOG_WARNING],
        ]);
    });

    test('clamps a window to the configured maximum', function (): void {
        $window = Guard::window(0, 86400 * 30);

        expect($window->clamped)->toBeTrue()
            ->and($window->duration())->toBe(86400)
        ;
    });

    // A reversed range would otherwise reach nfdump as an open-ended read.
    test('a reversed range becomes a single interval', function (): void {
        $window = Guard::window(5_000, 1_000);

        expect($window->end)->toBeGreaterThan($window->start)
            ->and($window->duration())->toBe(300)
        ;
    });

    test('caps the row limit and defaults a missing one', function (): void {
        expect(Guard::limit(0))->toBe(Guard::DEFAULT_LIMIT)
            ->and(Guard::limit(-5))->toBe(Guard::DEFAULT_LIMIT)
            ->and(Guard::limit(10))->toBe(10)
            ->and(Guard::limit(100_000))->toBe(Guard::MAX_LIMIT)
        ;
    });

    test('passes a plausible filter through', function (): void {
        expect(Guard::filter('  proto tcp and dst port 443 '))->toBe('proto tcp and dst port 443');
    });

    // Rejected as ToolCallException specifically, because that is the one the SDK turns into
    // an error the caller can read rather than an opaque internal failure.
    test('rejects unbalanced parentheses with a readable error', function (): void {
        expect(fn () => Guard::filter('proto tcp and ('))
            ->toThrow(ToolCallException::class, 'unbalanced parentheses')
        ;
    });

    test('rejects an absurdly long filter', function (): void {
        expect(fn () => Guard::filter(str_repeat('a', 2_001)))->toThrow(ToolCallException::class);
    });

    test('refuses a query that would read more than the ceiling', function (): void {
        expect(fn () => Guard::assertAffordable(Guard::maxBytes() + 1))
            ->toThrow(ToolCallException::class, 'Narrow the time window')
        ;
    });

    test('allows a query inside the ceiling', function (): void {
        Guard::assertAffordable(1_024);

        expect(true)->toBeTrue();
    });

    // Turning the window bound off is an operator opting out of the byte ceiling too.
    test('no configured window means no byte ceiling', function (): void {
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw'], 'ports' => [], 'db' => 'RRD', 'processor' => 'Nfdump', 'max_stats_window' => 0],
            'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => '/tmp', 'profile' => 'live', 'max-processes' => 1],
            'db' => ['RRD' => ['data_path' => sys_get_temp_dir(), 'import_years' => 3]],
            'log' => ['priority' => LOG_WARNING],
        ]);

        Guard::assertAffordable(Guard::maxBytes() * 10);

        expect(true)->toBeTrue();
    });

    test('formats bytes in the unit that reads naturally', function (): void {
        expect(Guard::formatBytes(512))->toBe('512 B')
            ->and(Guard::formatBytes(1536))->toBe('1.5 KiB')
            ->and(Guard::formatBytes(16 * 1024 ** 3))->toBe('16 GiB')
        ;
    });
});
