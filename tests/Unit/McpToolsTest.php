<?php

declare(strict_types=1);

use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\mcp\Tool\ToolInterface;
use mbolli\nfsen_ng\mcp\ToolRegistry;

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
