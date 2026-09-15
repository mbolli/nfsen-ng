<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\CostEstimate;

/**
 * What an expensive query would read, without reading it.
 */
final class EstimateCostTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(int $start, int $end, ?array $sources = null, string $profile = ''): array {
        $window = Guard::window($start, $end);
        $estimate = CostEstimate::forSinglePass(
            $window,
            Args::stringList($sources) ?: Config::$settings->sources,
            Args::profile($profile),
        );

        return $estimate->toArray() + [
            'bytes_human' => Guard::formatBytes($estimate->bytes),
            'window' => ['start' => $window->start, 'end' => $window->end],
            'affordable' => $estimate->bytes <= Guard::maxBytes(),
            'ceiling_human' => Guard::formatBytes(Guard::maxBytes()),
        ];
    }

    public static function name(): string {
        return 'estimate_cost';
    }

    public static function description(): string {
        return 'How much capture data a query over this window would read: file count, total '
            . 'bytes, and whether the window was shortened by the configured maximum. Call this '
            . 'before top_talkers, list_flows or flow_matrix on any window wider than an hour. It '
            . 'walks directory entries rather than reading flows, so it returns quickly.';
    }

    public static function tier(): Tier {
        // Cheap despite belonging to the expensive family: it stats files, it does not read them.
        return Tier::Cheap;
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'start' => ['type' => 'integer', 'description' => 'Unix timestamp of the range start.'],
                'end' => ['type' => 'integer', 'description' => 'Unix timestamp of the range end.'],
                'sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Flow sources. Omit for every configured source.',
                ],
                'profile' => ['type' => 'string', 'description' => 'nfdump profile. Omit for the configured one.'],
            ],
            'required' => ['start', 'end'],
        ];
    }
}
