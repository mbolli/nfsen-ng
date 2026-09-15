<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\FlowsQuery;

/**
 * Individual flow records, for when an aggregate is ambiguous.
 */
final class ListFlowsTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $start,
        int $end,
        string $filter = '',
        int $limit = Guard::DEFAULT_LIMIT,
        bool $order_by_start = true,
        ?array $sources = null,
        string $profile = '',
    ): array {
        $window = Guard::window($start, $end);
        $limit = Guard::limit($limit);

        $query = new FlowsQuery(
            window: $window,
            sources: Args::stringList($sources) ?: Config::$settings->sources,
            profile: Args::profile($profile),
            limit: $limit,
            filter: Guard::filter($filter),
            orderByStart: $order_by_start,
        );

        Guard::assertAffordable($query->totalBytes());
        $result = $query->run();

        return [
            'records' => $result->rows,
            'row_count' => $result->count(),
            'truncated' => $result->count() >= $limit,
            'window' => ['start' => $window->start, 'end' => $window->end, 'clamped' => $window->clamped],
            'command' => $result->command,
            'elapsed_seconds' => $result->elapsed,
        ];
    }

    public static function name(): string {
        return 'list_flows';
    }

    public static function description(): string {
        return 'Individual flow records matching a filter, newest first. Use this only after '
            . 'top_talkers or flow_matrix has narrowed the question, when the individual '
            . 'connections matter rather than the totals.';
    }

    public static function tier(): Tier {
        return Tier::Expensive;
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'start' => ['type' => 'integer', 'description' => 'Unix timestamp of the range start.'],
                'end' => ['type' => 'integer', 'description' => 'Unix timestamp of the range end.'],
                'filter' => [
                    'type' => 'string',
                    'description' => 'nfdump filter expression, e.g. "host 10.0.0.1 and dst port 22". '
                        . 'Strongly recommended: without one this returns whatever comes first.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Records to return, at most ' . Guard::MAX_LIMIT . '. Defaults to ' . Guard::DEFAULT_LIMIT . '.',
                ],
                'order_by_start' => [
                    'type' => 'boolean',
                    'description' => 'Order by flow start time rather than nfdump\'s file order.',
                ],
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
