<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Guard;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\StatsQuery;

/**
 * Who and what: the top entries in a window, by whichever counter matters.
 */
final class TopTalkersTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $start,
        int $end,
        string $by = 'srcip',
        string $order_by = 'bytes',
        int $limit = Guard::DEFAULT_LIMIT,
        string $filter = '',
        ?array $sources = null,
        string $profile = '',
    ): array {
        $window = Guard::window($start, $end);
        $limit = Guard::limit($limit);

        $query = new StatsQuery(
            window: $window,
            sources: Args::stringList($sources) ?: Config::$settings->sources,
            profile: Args::profile($profile),
            for: $by,
            orderBy: $order_by,
            limit: $limit,
            filter: Guard::filter($filter),
        );

        Guard::assertAffordable($query->totalBytes());
        $result = $query->run();

        return [
            'rows' => $result->rows,
            'row_count' => $result->count(),
            'truncated' => $result->count() >= $limit,
            'window' => ['start' => $window->start, 'end' => $window->end, 'clamped' => $window->clamped],
            'command' => $result->command,
            'stderr' => $result->stderr,
            'elapsed_seconds' => $result->elapsed,
        ];
    }

    public static function name(): string {
        return 'top_talkers';
    }

    public static function description(): string {
        return 'Top sources, destinations, ports or protocols in a time window, ordered by '
            . 'flows, packets, bytes or rate. This is the tool that answers who is generating '
            . 'traffic. Narrow the window first: it reads every capture file in range.';
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
                'by' => [
                    'type' => 'string',
                    'enum' => ['srcip', 'dstip', 'srcport', 'dstport', 'proto', 'record'],
                    'description' => 'What to aggregate by. Defaults to srcip.',
                ],
                'order_by' => [
                    'type' => 'string',
                    'enum' => ['flows', 'packets', 'bytes', 'pps', 'bps', 'bpp'],
                    'description' => 'Which counter to rank on. Defaults to bytes.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Rows to return, at most ' . Guard::MAX_LIMIT . '. Defaults to ' . Guard::DEFAULT_LIMIT . '.',
                ],
                'filter' => [
                    'type' => 'string',
                    'description' => 'nfdump filter expression, e.g. "proto tcp and dst port 443". '
                        . 'Narrowing with a filter does not reduce what is read from disk.',
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
