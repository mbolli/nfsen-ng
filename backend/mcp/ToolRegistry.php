<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp;

use mbolli\nfsen_ng\mcp\Tool\CurrentLoadTool;
use mbolli\nfsen_ng\mcp\Tool\DataCoverageTool;
use mbolli\nfsen_ng\mcp\Tool\StatusTool;
use mbolli\nfsen_ng\mcp\Tool\ToolInterface;
use mbolli\nfsen_ng\mcp\Tool\TrafficTimelineTool;
use Mcp\Server;
use Mcp\Server\Builder;

/**
 * Assembles the MCP server from the tool classes.
 *
 * Every tool's description gets its cost tier appended here rather than written by hand, so
 * a tool cannot ship without declaring what it costs to answer.
 */
final class ToolRegistry {
    /** @var list<class-string<ToolInterface>> */
    private const TOOLS = [
        TrafficTimelineTool::class,
        CurrentLoadTool::class,
        DataCoverageTool::class,
        StatusTool::class,
    ];

    /**
     * @return list<class-string<ToolInterface>>
     */
    public static function tools(): array {
        return self::TOOLS;
    }

    public static function describe(string $tool): string {
        /** @var class-string<ToolInterface> $tool */
        return $tool::description() . "\n\n" . $tool::tier()->note();
    }

    public static function build(string $version): Builder {
        $builder = Server::builder()
            ->setServerInfo('nfsen-ng', $version)
            ->setInstructions(
                'Read-only access to NetFlow data collected by nfsen-ng. Two tiers of tools exist '
                . 'and the difference matters: cheap tools read stored five-minute aggregates and '
                . 'return immediately, while expensive tools read capture files with nfdump and cost '
                . 'time proportional to the window. Establish when something happened with the cheap '
                . 'tools first, then narrow the window before reaching for the expensive ones.'
            )
        ;

        foreach (self::TOOLS as $tool) {
            $builder->addTool(
                // The SDK resolves a handler as a closure, an [object, method] pair or a
                // class-string; an invokable object alone is not accepted.
                handler: [new $tool(), '__invoke'],
                name: $tool::name(),
                description: self::describe($tool),
                inputSchema: $tool::inputSchema(),
            );
        }

        return $builder;
    }
}
