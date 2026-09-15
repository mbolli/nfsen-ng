<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\HealthChecker;
use mbolli\nfsen_ng\mcp\Tier;

/**
 * Whether the installation itself is healthy.
 */
final class StatusTool implements ToolInterface {
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array {
        // The daemon runs in the web worker, so a separate process cannot see it: reported as
        // disabled rather than pretending to know.
        $checks = HealthChecker::run(true);

        $problems = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] !== 'ok'
        ));

        return [
            'datasource' => Config::$settings->datasourceName,
            'sources' => Config::$settings->sources,
            'ok' => $problems === [],
            'problems' => array_map(static fn (array $check): array => [
                'id' => $check['id'],
                'label' => $check['label'],
                'status' => $check['status'],
                'detail' => strip_tags($check['detail']),
                'hint' => strip_tags($check['hint']),
            ], $problems),
            'checks_run' => \count($checks),
        ];
    }

    public static function name(): string {
        return 'status';
    }

    public static function description(): string {
        return 'Health of the installation: datasource reachability, capture collection and '
            . 'configuration checks. Call this when data looks missing, so an infrastructure '
            . 'failure is not reported as a change in traffic.';
    }

    public static function tier(): Tier {
        return Tier::Cheap;
    }

    public static function inputSchema(): array {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }
}
