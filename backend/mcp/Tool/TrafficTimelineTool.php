<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\mcp\Args;
use mbolli\nfsen_ng\mcp\Tier;
use mbolli\nfsen_ng\query\TimelineQuery;
use mbolli\nfsen_ng\query\TimeWindow;

/**
 * The stored series: how much traffic there was, per interval, over a range.
 */
final class TrafficTimelineTool implements ToolInterface {
    /**
     * @param null|list<string> $sources
     * @param null|list<string> $protocols
     * @param null|list<int>    $ports
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        int $start,
        int $end,
        ?array $sources = null,
        ?array $protocols = null,
        ?array $ports = null,
        string $unit = 'flows',
        string $display = 'sources',
        int $resolution = 500,
        string $profile = '',
    ): array {
        $query = new TimelineQuery(
            window: TimeWindow::raw($start, $end),
            sources: Args::stringList($sources) ?: Config::$settings->sources,
            protocols: Args::stringList($protocols),
            ports: Args::intList($ports),
            unit: $unit,
            display: $display,
            resolution: $resolution,
            profile: Args::profile($profile),
        );

        $data = $query->run();

        return [
            'start' => $data['start'],
            'end' => $data['end'],
            'step' => $data['step'],
            'legend' => $data['legend'],
            'points' => $data['data'],
            'last_write' => $query->lastWrite(),
        ];
    }

    public static function name(): string {
        return 'traffic_timeline';
    }

    public static function description(): string {
        return 'Traffic over time from the stored aggregates: flows, packets, bytes or bits per '
            . 'interval, broken down by source, protocol or port. Use this to find when something '
            . 'started and how far above normal it is, before reading any capture files.';
    }

    public static function tier(): Tier {
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
                'protocols' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['tcp', 'udp', 'icmp', 'other']],
                    'description' => 'Protocols to include. Omit for all of them.',
                ],
                'ports' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Ports, only meaningful with display=ports. Omit for the configured ports.',
                ],
                'unit' => [
                    'type' => 'string',
                    'enum' => ['flows', 'packets', 'bytes', 'bits'],
                    'description' => 'What to measure. Defaults to flows.',
                ],
                'display' => [
                    'type' => 'string',
                    'enum' => ['sources', 'protocols', 'ports'],
                    'description' => 'How to break the series down. Defaults to sources.',
                ],
                'resolution' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of points to return. Defaults to 500.',
                ],
                'profile' => ['type' => 'string', 'description' => 'nfdump profile. Omit for the configured one.'],
            ],
            'required' => ['start', 'end'],
        ];
    }
}
