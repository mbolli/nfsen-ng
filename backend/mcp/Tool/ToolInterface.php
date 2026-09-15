<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\mcp\Tier;

/**
 * One MCP tool: its identity, its input schema and what it does when called.
 *
 * Tools call the query layer and nothing else. They never reach into the action layer, which
 * is bound to a php-via Context and a browser tab.
 */
interface ToolInterface {
    public static function name(): string;

    /** What the tool does. The tier note is appended by the registry, so it cannot be forgotten. */
    public static function description(): string;

    public static function tier(): Tier;

    /**
     * JSON Schema for the arguments, as the MCP specification expects it.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array;

    // Each tool is also invokable, but its __invoke() signature is not declared here: the SDK
    // binds arguments by parameter name, so every tool's parameters mirror its own schema.
}
