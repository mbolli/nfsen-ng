<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\CoverageQuery;

/**
 * What data exists to ask about.
 */
final class DataCoverageTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     *
     * @return array<string, mixed>
     */
    public function __invoke(?array $sources = null, string $profile = ''): array {
        return (new CoverageQuery(
            sources: Args::stringList($sources),
            profile: Args::profile($profile),
        ))->run();
    }

    public static function name(): string {
        return 'data_coverage';
    }

    public static function description(): string {
        return 'First sample, last sample and last import time per source. Call this before '
            . 'choosing a time range: a range outside the stored data returns an empty series, and '
            . 'an import that has not caught up looks exactly like a quiet network.';
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
                'profile' => ['type' => 'string', 'description' => 'nfdump profile. Omit for the configured one.'],
            ],
        ];
    }
}
