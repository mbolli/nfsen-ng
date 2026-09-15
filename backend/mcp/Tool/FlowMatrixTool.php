<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\MatrixQuery;

/**
 * Who talked to whom, aggregated into pairs.
 */
final class FlowMatrixTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $start,
        int $end,
        string $metric = 'bytes',
        int $top_n = Guard::DEFAULT_LIMIT,
        bool $show_ports = false,
        string $filter = '',
        ?array $sources = null,
        string $profile = '',
    ): array {
        $window = Guard::window($start, $end);
        $topN = Guard::limit($top_n);

        $query = new MatrixQuery(
            window: $window,
            sources: Args::stringList($sources) ?: Config::$settings->sources,
            profile: Args::profile($profile),
            metric: $metric,
            topN: $topN,
            showPorts: $show_ports,
            filter: Guard::filter($filter),
        );

        Guard::assertAffordable($query->totalBytes());
        $result = $query->run();

        return [
            'pairs' => $result->rows,
            'row_count' => $result->count(),
            'metric' => $query->metric(),
            'window' => ['start' => $window->start, 'end' => $window->end, 'clamped' => $window->clamped],
            'command' => $result->command,
            'elapsed_seconds' => $result->elapsed,
        ];
    }

    public static function name(): string {
        return 'flow_matrix';
    }

    public static function description(): string {
        return 'Source to destination pairs in a window, optionally through a destination port, '
            . 'ranked by bytes or packets. Shows the shape of the traffic: one loud host looks '
            . 'very different from thousands of sources hitting one target.';
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
                'metric' => [
                    'type' => 'string',
                    'enum' => ['bytes', 'packets'],
                    'description' => 'What the pair values measure. Defaults to bytes.',
                ],
                'top_n' => [
                    'type' => 'integer',
                    'description' => 'Pairs to return, at most ' . Guard::MAX_LIMIT . '. Defaults to ' . Guard::DEFAULT_LIMIT . '.',
                ],
                'show_ports' => [
                    'type' => 'boolean',
                    'description' => 'Add the destination port to the key, giving source, port, destination.',
                ],
                'filter' => ['type' => 'string', 'description' => 'nfdump filter expression.'],
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
