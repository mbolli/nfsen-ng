<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\AlertActions;
use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\AlertState;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\store\AlertEventRepository;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Alerts (4.6): the rules with their state, the recent events from SQLite, the rule form and
 * the default notification templates.
 *
 * @phpstan-import-type TestResult from AlertManager
 * @phpstan-import-type AlertEvent from AlertEventRepository
 *
 * @phpstan-type RuleRow array{id: string, key: string, name: string, enabled: bool, status: string, level: string,
 *                             statusLabel: string, condition: string, threshold: string, sources: string, filter: string,
 *                             lastFired: ?int, lastFiredIso: string, lastFiredLabel: string, form: array<string, mixed>}
 * @phpstan-type EventRow array{id: int, ts: int, iso: string, kind: string, label: string, level: string,
 *                              ruleName: string, profile: string, value: string}
 * @phpstan-type TestView array{title: string, level: string, headline: string, detail: string, notification: string,
 *                              slot: int, slotIso: string, templates: list<array{label: string, text: string}>}
 */
final class AlertsPage implements Page {
    /** Recent alerts shown beside the rules. */
    public const int HISTORY_LIMIT = 50;

    /** Form field => signal name suffix; each field is the signal `alert_form_<field>`. */
    public const array FORM_FIELDS = [
        'id', 'name', 'enabled', 'profile', 'sources', 'metric', 'operator', 'thresholdType', 'thresholdValue',
        'avgWindow', 'cooldownSlots', 'notifyEmail', 'emailSubjectTemplate', 'emailBodyTemplate', 'notifyWebhook',
        'webhookTitleTemplate', 'webhookMessageTemplate', 'nfdumpFilter',
    ];

    public const array METRICS = ['flows' => 'Flows', 'packets' => 'Packets', 'bytes' => 'Bytes'];

    /** The time bases a value can be shown with, see valueBasis(). */
    public const string PER_SECOND = '/s';

    public const string PER_INTERVAL = ' per 5 min';

    public const array OPERATORS = ['>', '>=', '<', '<='];

    public const array THRESHOLD_TYPES = ['absolute' => 'Absolute value', 'percent_of_avg' => '% of rolling average'];

    public const array WINDOWS = ['10m' => '10 min', '30m' => '30 min', '1h' => '1 h', '6h' => '6 h', '12h' => '12 h', '24h' => '24 h'];

    /** The global template signals, keyed by the preference each one saves to. */
    public const array TEMPLATE_SIGNALS = [
        'defaultEmailSubjectTemplate' => 'settings_defaultEmailSubjectTemplate',
        'defaultEmailBodyTemplate' => 'settings_defaultEmailBodyTemplate',
        'defaultWebhookTitleTemplate' => 'settings_defaultWebhookTitleTemplate',
        'defaultWebhookMessageTemplate' => 'settings_defaultWebhookMessageTemplate',
    ];

    private const array EVENT_KINDS = [
        'fired' => ['label' => 'Fired', 'level' => 'error'],
        'resolved' => ['label' => 'Resolved', 'level' => 'success'],
        'test' => ['label' => 'Test', 'level' => 'info'],
    ];

    /** @var null|\WeakMap<Context, array<string, string>> per tab, the default templates it was last given */
    private static ?\WeakMap $givenTemplates = null;

    public static function id(): string {
        return 'alerts';
    }

    public static function title(): string {
        return 'Alerts';
    }

    public static function lede(): string {
        return 'Rules checked after each 5 minute import.';
    }

    public static function icon(): string {
        return 'bell';
    }

    public static function group(): string {
        return 'monitor';
    }

    public static function signals(Context $c): void {
        foreach (self::formDefaults() as $field => $default) {
            $c->signal($default, 'alert_form_' . $field, clientWritable: true);
        }

        // Empty falls back to AlertManager's built-ins; a rule's own template overrides them in turn.
        $given = [];
        foreach (self::TEMPLATE_SIGNALS as $preference => $signal) {
            $given[$preference] = self::templateSetting($preference);
            $c->signal($given[$preference], $signal, clientWritable: true);
        }
        $map = self::givenTemplates();
        $map[$c] = $given;
    }

    /**
     * Takes over the default templates another tab saved, except in a field where this tab
     * holds an edit of its own: a value other than the one it was last given.
     */
    public static function refreshTemplates(Context $c): void {
        $map = self::givenTemplates();
        $given = $map[$c] ?? [];
        foreach (self::TEMPLATE_SIGNALS as $preference => $name) {
            $signal = $c->getSignal($name);
            if ($signal === null) {
                continue;
            }
            $saved = self::templateSetting($preference);
            $current = $signal->string();
            if ($current !== $saved && ($given[$preference] ?? null) === $current) {
                $signal->setValue($saved, broadcast: false);
                $current = $saved;
            }
            if ($current === $saved) {
                $given[$preference] = $saved;
            }
        }
        $map[$c] = $given;
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        AlertActions::register($c, $app, $states);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $manager = $app->globalState('alertManager', null);
        $manager = $manager instanceof AlertManager ? $manager : null;
        $settings = Config::$settings;
        $now = time();
        self::refreshTemplates($c);

        $available = $manager !== null && $manager->eventsAvailable();
        $signals = [];
        foreach (self::FORM_FIELDS as $field) {
            $signals[$field] = $c->getSignal('alert_form_' . $field)?->id() ?? '';
        }

        return [
            'rules' => self::ruleRows($settings->alerts, $manager?->states() ?? [], $available ? $manager->lastFired() : null, $now),
            'history' => [
                'available' => $available,
                'error' => match (true) {
                    $manager === null => 'alerts are not running on this server',
                    $available => '',
                    default => $manager->eventsError(),
                },
                'events' => $available ? self::eventRows($manager->recentEvents(self::HISTORY_LIMIT), $settings->alerts) : [],
            ],
            'form' => [
                'title' => self::formTitle($c->getSignal('alert_form_id')?->string() ?? '', $settings->alerts),
                // id => name, so the title names the rule being edited without a round trip.
                'ruleNames' => json_encode(array_column(array_map(
                    static fn (AlertRule $r): array => ['id' => $r->id, 'name' => $r->name],
                    $settings->alerts,
                ), 'name', 'id'), JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
                'perInterval' => self::storesTotals(),
                'signals' => $signals,
                'defaults' => self::formDefaults(),
                'profiles' => self::profileOptions($c->getSignal('available_profiles')?->array() ?? [], $settings->alerts),
                'sources' => self::sourceOptions($settings->sources, $settings->alerts),
                'metrics' => self::METRICS,
                'operators' => self::OPERATORS,
                'thresholdTypes' => self::THRESHOLD_TYPES,
                'windows' => self::WINDOWS,
                'emailEnabled' => $settings->alertEmailFrom !== '',
                // The filter field's validation answer; Save waits while it says invalid.
                'filterStatus' => $c->getSignal(QueryKit::filterSignal('alert'))?->id() ?? '',
            ],
            'templates' => [
                'tokens' => self::templateTokens(),
                'allSources' => self::allSourcesText(),
                // The bottom of the fallback chain, shown in the default templates' placeholders.
                'builtin' => [
                    'subject' => AlertManager::DEFAULT_EMAIL_SUBJECT,
                    'body' => AlertManager::DEFAULT_EMAIL_BODY,
                    'title' => AlertManager::DEFAULT_WEBHOOK_TITLE,
                    'message' => AlertManager::DEFAULT_WEBHOOK_MESSAGE,
                ],
                // What a rule inherits, shown in the placeholders of its override fields.
                'effective' => [
                    'subject' => AlertManager::resolveTemplate(null, $settings->defaultEmailSubjectTemplate, AlertManager::DEFAULT_EMAIL_SUBJECT),
                    'body' => AlertManager::resolveTemplate(null, $settings->defaultEmailBodyTemplate, AlertManager::DEFAULT_EMAIL_BODY),
                    'title' => AlertManager::resolveTemplate(null, $settings->defaultWebhookTitleTemplate, AlertManager::DEFAULT_WEBHOOK_TITLE),
                    'message' => AlertManager::resolveTemplate(null, $settings->defaultWebhookMessageTemplate, AlertManager::DEFAULT_WEBHOOK_MESSAGE),
                ],
            ],
        ];
    }

    /**
     * The rule form's values for a new rule, keyed by FORM_FIELDS.
     *
     * @return array<string, mixed>
     */
    public static function formDefaults(): array {
        return [
            'id' => '',
            'name' => '',
            'enabled' => true,
            'profile' => isset(Config::$settings) ? Config::$settings->nfdumpProfile : 'live',
            // None: every source, including ones added later.
            'sources' => [],
            'metric' => 'bytes',
            'operator' => '>',
            'thresholdType' => 'absolute',
            'thresholdValue' => 0,
            'avgWindow' => '1h',
            'cooldownSlots' => 3,
            'notifyEmail' => '',
            'emailSubjectTemplate' => '',
            'emailBodyTemplate' => '',
            'notifyWebhook' => '',
            'webhookTitleTemplate' => '',
            'webhookMessageTemplate' => '',
            'nfdumpFilter' => '',
        ];
    }

    /**
     * A saved rule as rule form values, so Edit copies every field.
     *
     * @return array<string, mixed>
     */
    public static function formValues(AlertRule $rule): array {
        $values = $rule->toArray();
        $form = [];
        foreach (self::FORM_FIELDS as $field) {
            $form[$field] = $values[$field] ?? '';
        }
        $form['sources'] = array_values($rule->sources);

        return $form;
    }

    /**
     * @param list<AlertRule>           $rules
     * @param array<string, AlertState> $states    rule ID => runtime state
     * @param null|array<string, int>   $lastFired rule ID => ts of the newest fired event, null while the history is unavailable
     *
     * @return list<RuleRow>
     */
    public static function ruleRows(array $rules, array $states, ?array $lastFired, int $now): array {
        $rows = [];
        foreach ($rules as $rule) {
            $firing = $rule->enabled && ($states[$rule->id] ?? null)?->firing === true;
            [$status, $level, $label] = match (true) {
                !$rule->enabled => ['disabled', '', 'Disabled'],
                $firing => ['firing', 'error', 'Firing'],
                default => ['ok', '', 'OK'],
            };
            $last = $lastFired[$rule->id] ?? null;

            $rows[] = [
                'id' => $rule->id,
                'key' => self::ruleKey($rule->id),
                'name' => $rule->name,
                'enabled' => $rule->enabled,
                'status' => $status,
                'level' => $level,
                'statusLabel' => $label,
                'condition' => self::conditionLabel($rule),
                'threshold' => self::thresholdLabel($rule),
                'sources' => self::sourcesLabel($rule->sources),
                'filter' => $rule->nfdumpFilter ?? '',
                'lastFired' => $last,
                'lastFiredIso' => $last !== null ? gmdate('Y-m-d\TH:i:s\Z', $last) : '',
                'lastFiredLabel' => $last !== null ? self::relativeTime($last, $now) : ($lastFired === null ? 'Unknown' : 'Never'),
                'form' => self::formValues($rule),
            ];
        }

        return $rows;
    }

    /**
     * @param list<AlertEvent> $events
     * @param list<AlertRule>  $rules  the saved rules, for the time base of their events' values
     *
     * @return list<EventRow>
     */
    public static function eventRows(array $events, array $rules = []): array {
        $byId = [];
        foreach ($rules as $rule) {
            $byId[$rule->id] = $rule;
        }

        $rows = [];
        foreach ($events as $event) {
            $kind = self::EVENT_KINDS[$event['kind']] ?? ['label' => ucfirst($event['kind']), 'level' => ''];
            // A deleted rule's time base is unknown, so its figures go without one.
            $rule = $byId[$event['ruleId'] ?? ''] ?? null;
            $value = self::formatValue($event['metric'], $event['value'], $rule !== null ? self::valueBasis($rule) : '');
            $threshold = $event['threshold'] !== null
                ? self::formatValue($event['metric'], $event['threshold'], $rule !== null ? self::thresholdBasis($rule) : '')
                : null;

            $rows[] = [
                'id' => $event['id'],
                'ts' => $event['ts'],
                'iso' => gmdate('Y-m-d\TH:i:s\Z', $event['ts']),
                'kind' => $event['kind'],
                'label' => $kind['label'],
                'level' => $kind['level'],
                'ruleName' => $event['ruleName'],
                'profile' => $event['profile'],
                'value' => $threshold !== null ? $value . ' vs ' . $threshold : $value,
            ];
        }

        return $rows;
    }

    /** "bytes > absolute", "packets > 200% of 1 h average". */
    public static function conditionLabel(AlertRule $rule): string {
        $head = $rule->metric . ' ' . $rule->operator . ' ';

        return $rule->thresholdType === 'percent_of_avg'
            ? $head . self::number($rule->thresholdValue) . '% of ' . self::windowLabel($rule->avgWindow) . ' average'
            : $head . 'absolute';
    }

    /** "5 GB/s" for an absolute threshold; a relative one names its averaging window. */
    public static function thresholdLabel(AlertRule $rule): string {
        return $rule->thresholdType === 'percent_of_avg'
            ? self::windowLabel($rule->avgWindow) . ' window'
            : self::formatValue($rule->metric, $rule->thresholdValue, self::valueBasis($rule));
    }

    /**
     * A value with the metric's unit and a time base: "6.1 GB/s", "1.5k packets per 5 min",
     * or with $per '' none at all.
     */
    public static function formatValue(string $metric, float $value, string $per = self::PER_SECOND): string {
        if ($value === PHP_FLOAT_MAX) {
            return 'no baseline';
        }

        return match ($metric) {
            'bytes' => self::scaled($value, ['B', 'kB', 'MB', 'GB', 'TB', 'PB'], ' ') . $per,
            'packets' => self::scaled($value, ['', 'k', 'M', 'G', 'T', 'P'], '') . ' packets' . $per,
            'flows' => self::scaled($value, ['', 'k', 'M', 'G', 'T', 'P'], '') . ' flows' . $per,
            default => self::number($value) . ' ' . $metric . $per,
        };
    }

    /**
     * What a rule's values are counted over: RRD stores per second rates, while a filtered
     * rule sums one interval's flow records and VictoriaMetrics stores interval totals.
     */
    public static function valueBasis(AlertRule $rule): string {
        return $rule->nfdumpFilter !== null || self::storesTotals() ? self::PER_INTERVAL : self::PER_SECOND;
    }

    /** An absolute threshold is compared with the values as they are; a relative one comes from the stored average. */
    public static function thresholdBasis(AlertRule $rule): string {
        if ($rule->thresholdType !== 'percent_of_avg') {
            return self::valueBasis($rule);
        }

        return self::storesTotals() ? self::PER_INTERVAL : self::PER_SECOND;
    }

    /** Whether the datasource stores totals per interval rather than per second rates. */
    public static function storesTotals(): bool {
        return isset(Config::$settings) && Config::$settings->datasourceName === 'VictoriaMetrics';
    }

    /**
     * "New rule", or "Edit rule: <name>" for the saved rule the form holds.
     *
     * @param list<AlertRule> $rules
     */
    public static function formTitle(string $id, array $rules): string {
        if ($id === '') {
            return 'New rule';
        }
        $rule = AlertActions::findRule($rules, $id);

        return $rule !== null ? 'Edit rule: ' . $rule->name : 'Edit rule';
    }

    /** @param array<string> $sources */
    public static function sourcesLabel(array $sources): string {
        return $sources === [] ? 'All' : implode(', ', $sources);
    }

    public static function windowLabel(string $window): string {
        return self::WINDOWS[$window] ?? $window;
    }

    /** "just now", "12 min ago", "5 h ago" (up to two days), "3 days ago". */
    public static function relativeTime(int $ts, int $now): string {
        $age = $now - $ts;

        return match (true) {
            $age < 60 => 'just now',
            $age < 3600 => intdiv($age, 60) . ' min ago',
            $age < 2 * 86400 => intdiv($age, 3600) . ' h ago',
            default => intdiv($age, 86400) . ' days ago',
        };
    }

    /**
     * The Test dialog (4.6.2): the outcome, the figures or why there are none, what was sent,
     * and the four rendered templates, which are shown whether or not the rule would fire.
     *
     * @param TestResult $result
     *
     * @return TestView
     */
    public static function testResultView(array $result, AlertRule $rule, bool $emailEnabled): array {
        if (!$result['evaluated']) {
            $reason = rtrim(trim($result['reason']), '.');
            $level = 'warning';
            $detail = 'Could not evaluate: ' . ($reason !== '' ? $reason : 'the traffic could not be read') . '.';
        } else {
            $level = $result['fired'] ? 'error' : 'success';
            $threshold = $result['threshold'] ?? PHP_FLOAT_MAX;
            $detail = 'Current ' . $rule->metric . ': ' . self::formatValue($rule->metric, $result['value'], self::valueBasis($rule)) . '.'
                . ' Threshold: ' . $rule->metric . ' ' . $rule->operator . ' ' . self::formatValue($rule->metric, $threshold, self::thresholdBasis($rule))
                . ($rule->thresholdType === 'percent_of_avg'
                    ? ' (' . self::number($rule->thresholdValue) . '% of the ' . self::windowLabel($rule->avgWindow) . ' average)'
                    : '')
                . '.';
        }

        return [
            'title' => 'Test: ' . $rule->name,
            'level' => $level,
            'headline' => $result['fired'] ? 'Would fire' : 'Would not fire',
            'detail' => $detail,
            'notification' => self::notificationLine($result, $rule, $emailEnabled),
            'slot' => $result['slot'],
            'slotIso' => gmdate('Y-m-d\TH:i:s\Z', $result['slot']),
            'templates' => [
                ['label' => 'Webhook title', 'text' => $result['title']],
                ['label' => 'Webhook message', 'text' => $result['message']],
                ['label' => 'Email subject', 'text' => $result['subject']],
                ['label' => 'Email body', 'text' => $result['body']],
            ],
        ];
    }

    /**
     * The template tokens, in the order AlertManager::buildTemplateVars() substitutes them.
     *
     * @return list<string>
     */
    public static function templateTokens(): array {
        return array_keys(self::probeVars());
    }

    /** What {sources} becomes for a rule with no source selected, so the preview shows the same. */
    public static function allSourcesText(): string {
        return self::probeVars()['{sources}'] ?? '';
    }

    /**
     * The profiles a rule can watch: the detected ones, plus any a saved rule still names.
     *
     * @param array<mixed>    $available
     * @param list<AlertRule> $rules
     *
     * @return list<string>
     */
    public static function profileOptions(array $available, array $rules): array {
        $profiles = array_values(array_filter($available, 'is_string'));
        if (isset(Config::$settings)) {
            $profiles[] = Config::$settings->nfdumpProfile;
        }
        foreach ($rules as $rule) {
            $profiles[] = $rule->profile;
        }

        return array_values(array_unique(array_filter($profiles, static fn (string $p): bool => $p !== '')));
    }

    /**
     * The configured sources, plus any a saved rule still names, so editing it keeps them.
     *
     * @param list<string>    $configured
     * @param list<AlertRule> $rules
     *
     * @return list<string>
     */
    public static function sourceOptions(array $configured, array $rules): array {
        $sources = $configured;
        foreach ($rules as $rule) {
            array_push($sources, ...$rule->sources);
        }

        return array_values(array_unique(array_filter($sources, static fn (string $s): bool => $s !== '')));
    }

    /**
     * The rule id as a signal-name fragment for the Test button's indicator and the row id: lower
     * case hex, as HTML attribute names are lower case and Datastar reads "__" as a modifier.
     */
    public static function ruleKey(string $id): string {
        return hash('xxh3', $id);
    }

    /** @return array<string, string> the template variables of a rule with no source selected */
    private static function probeVars(): array {
        $probe = new AlertRule('', '', true, '', [], 'bytes', '>', 'absolute', 0.0, '1h', 0, null, null);

        return AlertManager::buildTemplateVars($probe, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0], 0.0, 0);
    }

    /** @return \WeakMap<Context, array<string, string>> */
    private static function givenTemplates(): \WeakMap {
        return self::$givenTemplates ??= new \WeakMap();
    }

    private static function templateSetting(string $preference): string {
        if (!isset(Config::$settings)) {
            return '';
        }

        return match ($preference) {
            'defaultEmailSubjectTemplate' => Config::$settings->defaultEmailSubjectTemplate,
            'defaultEmailBodyTemplate' => Config::$settings->defaultEmailBodyTemplate,
            'defaultWebhookTitleTemplate' => Config::$settings->defaultWebhookTitleTemplate,
            default => Config::$settings->defaultWebhookMessageTemplate,
        };
    }

    /**
     * What the Test sent. AlertManager reports one result for both channels, true when either
     * of them worked, so with two channels the line cannot say which.
     *
     * @param TestResult $result
     */
    private static function notificationLine(array $result, AlertRule $rule, bool $emailEnabled): string {
        $channels = [];
        if ($rule->notifyWebhook !== null) {
            $channels[] = 'the webhook';
        }
        if ($rule->notifyEmail !== null && $emailEnabled) {
            $channels[] = $rule->notifyEmail;
        }
        $emailOff = $rule->notifyEmail !== null && !$emailEnabled ? 'email is off on this server (NFSEN_ALERT_EMAIL_FROM is not set)' : '';

        if ($result['notified']) {
            $line = match (\count($channels)) {
                0 => 'Notification sent.',
                1 => 'Notification sent to ' . $channels[0] . '.',
                default => 'Notification sent to at least one of ' . implode(' and ', $channels) . '.',
            };

            return $emailOff !== '' ? $line . ' ' . ucfirst($emailOff) . '.' : $line;
        }
        if (!$result['fired']) {
            return 'No notification sent.';
        }

        $reasons = $channels !== [] ? ['sending failed'] : [];
        if ($emailOff !== '') {
            $reasons[] = $emailOff;
        }

        return 'No notification sent: ' . ($reasons !== [] ? implode(', and ', $reasons) : 'the rule has no email or webhook set up') . '.';
    }

    /**
     * Scaled by 1000 with up to three significant digits.
     *
     * @param list<string> $prefixes
     */
    private static function scaled(float $value, array $prefixes, string $gap): string {
        $i = 0;
        $abs = abs($value);
        // Compared as number() rounds it, so 999.7 reads "1 k" and not "1,000".
        while (round($abs) >= 1000 && $i < \count($prefixes) - 1) {
            $abs /= 1000;
            $value /= 1000;
            ++$i;
        }

        return self::number($value) . ($prefixes[$i] === '' ? '' : $gap . $prefixes[$i]);
    }

    /** Up to three significant digits, no trailing zeros: "5", "6.1", "0.25", "123". */
    private static function number(float $value): string {
        $abs = abs($value);
        $decimals = match (true) {
            $abs >= 100 || $abs === 0.0 => 0,
            $abs >= 10 => 1,
            default => 2,
        };
        $text = number_format($value, $decimals, '.', ',');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
