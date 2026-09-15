<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\LoadQuery;

/**
 * Traffic right now against its recent average.
 */
final class CurrentLoadTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(?array $sources = null, int $window_seconds = LoadQuery::DEFAULT_WINDOW_SECONDS, string $profile = ''): array {
        return (new LoadQuery(
            sources: Args::stringList($sources),
            profile: Args::profile($profile),
            windowSeconds: $window_seconds,
        ))->run();
    }

    public static function name(): string {
        return 'current_load';
    }

    public static function description(): string {
        return 'The most recent stored interval next to its rolling average, with the multiple '
            . 'between them. Answers whether something is still happening and how far above normal '
            . 'it is. A ratio of 0 means the average is zero, not that traffic stopped.';
    }

    public static function tier(): Tier {
        return Tier::Cheap;
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Flow sources. Omit for every configured source.',
                ],
                'window_seconds' => [
                    'type' => 'integer',
                    'description' => 'How far back the average reaches. Defaults to one hour.',
                ],
                'profile' => ['type' => 'string', 'description' => 'nfdump profile. Omit for the configured one.'],
            ],
        ];
    }
}
