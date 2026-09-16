<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\mcp\Tool;

use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\mcp\Tier;

/**
 * The alerting rules this installation already has.
 */
final class ListAlertsTool implements ToolInterface {
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array {
        $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);

        return [
            'rules' => array_map(static fn (AlertRule $rule): array => [
                'id' => $rule->id,
                'name' => $rule->name,
                'enabled' => $rule->enabled,
                'profile' => $rule->profile,
                'sources' => $rule->sources,
                'metric' => $rule->metric,
                'operator' => $rule->operator,
                'threshold_type' => $rule->thresholdType,
                'threshold_value' => $rule->thresholdValue,
                'average_window' => $rule->avgWindow,
                'filter' => $rule->nfdumpFilter,
                'notifies' => array_values(array_filter([
                    $rule->notifyEmail !== null && $rule->notifyEmail !== '' ? 'email' : null,
                    $rule->notifyWebhook !== null && $rule->notifyWebhook !== '' ? 'webhook' : null,
                ])),
            ], $prefs->alerts),
            'rule_count' => \count($prefs->alerts),
        ];
    }

    public static function name(): string {
        return 'list_alerts';
    }

    public static function description(): string {
        return 'Configured alert rules: what each one watches, its threshold and whether it is '
            . 'enabled. Use it to say whether traffic you have found is already covered by a rule, '
            . 'or whether nothing would have caught it.';
    }

    public static function tier(): Tier {
        return Tier::Cheap;
    }

    public static function inputSchema(): array {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }
}
