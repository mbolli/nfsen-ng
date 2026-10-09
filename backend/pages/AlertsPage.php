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
 *                             lastFired: ?int, lastFiredLabel: string, form: array<string, mixed>}
 * @phpstan-type EventRow array{id: int, ts: int, iso: string, kind: string, label: string, level: string,
 *                              ruleName: string, profile: string, value: string}
 * @phpstan-type ChannelRow array{label: string, level: string, text: string}
 * @phpstan-type TestView array{title: string, level: string, headline: string, detail: string, notification: string,
 *                              channels: list<ChannelRow>, slot: int, slotIso: string, templates: list<array{label: string, text: string}>}
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

    /** relativeTime()'s units, largest first, in seconds: a month is a twelfth of a 365 day year, as in sb-relative-time. */
    private const array UNITS = [
        'year' => 31_536_000,
        'month' => 2_628_000,
        'week' => 604_800,
        'day' => 86_400,
        'hour' => 3_600,
        'minute' => 60,
        'second' => 1,
    ];

    /** The words Intl.RelativeTimeFormat('en', {numeric: 'auto'}) says instead of a count. */
    private const array RELATIVE_WORDS = [
        'second' => [0 => 'now'],
        'minute' => [0 => 'this minute'],
        'hour' => [0 => 'this hour'],
        'day' => [-1 => 'yesterday', 0 => 'today', 1 => 'tomorrow'],
        'week' => [-1 => 'last week', 0 => 'this week', 1 => 'next week'],
        'month' => [-1 => 'last month', 0 => 'this month', 1 => 'next month'],
        'year' => [-1 => 'last year', 0 => 'this year', 1 => 'next year'],
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
                $signal->setValue($saved);
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
            // Days count in nfcapd's timezone: the display's for "server", and the browser's is unknown here.
            'rules' => self::ruleRows($settings->alerts, $manager?->states() ?? [], $available ? $manager->lastFired() : null, $now, Config::nfcapdTimezone()),
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
     * @param \DateTimeZone             $zone      the calendar the Last triggered days are counted in
     *
     * @return list<RuleRow>
     */
    public static function ruleRows(array $rules, array $states, ?array $lastFired, int $now, \DateTimeZone $zone): array {
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
                'lastFiredLabel' => $last !== null ? self::relativeTime($last, $now, $zone) : ($lastFired === null ? 'Unknown' : 'Never'),
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
            $basis = $rule !== null ? self::valueBasis($rule) : '';
            $value = self::formatValue($event['metric'], $event['value'], $basis);
            $threshold = $event['threshold'] !== null ? self::formatValue($event['metric'], $event['threshold'], $basis) : null;

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

    /** "bytes > absolute", "packets > 200% of 1 h average", "bytes > 200% of the filter's own 1 h average". */
    public static function conditionLabel(AlertRule $rule): string {
        $head = $rule->metric . ' ' . $rule->operator . ' ';

        return $rule->thresholdType === 'percent_of_avg'
            ? $head . self::number($rule->thresholdValue) . '% of ' . self::averageLabel($rule)
            : $head . 'absolute';
    }

    /** What a relative rule averages: "1 h average", or for a filtered rule "the filter's own 1 h average". */
    public static function averageLabel(AlertRule $rule): string {
        $average = self::windowLabel($rule->avgWindow) . ' average';

        return $rule->nfdumpFilter !== null ? "the filter's own " . $average : $average;
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
     * The time base of a rule's values, threshold and average: RRD stores rates per second, a filtered
     * rule sums one interval's flow records and VictoriaMetrics stores interval totals.
     */
    public static function valueBasis(AlertRule $rule): string {
        return $rule->nfdumpFilter !== null || self::storesTotals() ? self::PER_INTERVAL : self::PER_SECOND;
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
        return $sources === [] ? 'All' : implode(', ', array_map(Config::$settings->sourceName(...), $sources));
    }

    public static function windowLabel(string $window): string {
        return self::WINDOWS[$window] ?? $window;
    }

    /**
     * "12 minutes ago", "yesterday": sb-relative-time's text (format long, numeric auto), so nothing
     * changes when it upgrades. A port of its relative(), counting calendar days in $zone.
     */
    public static function relativeTime(int $ts, int $now, \DateTimeZone $zone): string {
        $diff = $ts - $now;
        $units = array_keys(self::UNITS);
        $i = 0;
        while ($i < \count($units) - 1 && abs($diff) < self::UNITS[$units[$i]]) {
            ++$i;
        }
        $unit = $units[$i];
        // "60 minutes ago" reads as "1 hour ago".
        if ($i > 0 && abs(self::jsRound($diff / self::UNITS[$unit])) * self::UNITS[$unit] >= self::UNITS[$units[$i - 1]]) {
            $unit = $units[$i - 1];
        }
        $n = $unit === 'day'
            ? self::calendarDay($ts, $zone) - self::calendarDay($now, $zone)
            : self::jsRound($diff / self::UNITS[$unit]);

        if (isset(self::RELATIVE_WORDS[$unit][$n])) {
            return self::RELATIVE_WORDS[$unit][$n];
        }
        $count = number_format(abs($n)) . ' ' . $unit . (abs($n) === 1 ? '' : 's');

        return $n < 0 ? $count . ' ago' : 'in ' . $count;
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
            $average = self::averageLabel($rule);
            $detail = 'Current ' . $rule->metric . ': ' . self::formatValue($rule->metric, $result['value'], self::valueBasis($rule)) . '.'
                . ' Threshold: ' . $rule->metric . ' ' . $rule->operator . ' ' . self::formatValue($rule->metric, $threshold, self::valueBasis($rule))
                . ($rule->thresholdType === 'percent_of_avg'
                    ? ' (' . self::number($rule->thresholdValue) . '% of ' . ($rule->nfdumpFilter !== null ? $average : 'the ' . $average) . ')'
                    : '')
                . '.';
        }

        return [
            'title' => 'Test: ' . $rule->name,
            'level' => $level,
            'headline' => $result['fired'] ? 'Would fire' : 'Would not fire',
            'detail' => $detail,
            'notification' => self::notificationLine($result, $rule, $emailEnabled),
            'channels' => self::channelRows($result, $rule, $emailEnabled),
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
     * What the Test sent, in one line: the channels that got the notification, then the ones that
     * failed or are off on this server, as channelRows() lists them.
     *
     * @param TestResult $result
     */
    private static function notificationLine(array $result, AlertRule $rule, bool $emailEnabled): string {
        if (!$result['fired']) {
            return 'No notification sent.';
        }

        $sent = [];
        $problems = [];
        foreach (self::channels($rule) as $channel => $target) {
            $delivery = $result['delivery'][$channel];
            if ($delivery['status'] === AlertManager::DELIVERY_SENT) {
                $sent[] = $target;
            } elseif ($delivery['status'] === AlertManager::DELIVERY_FAILED) {
                $problems[] = 'sending to ' . $target . ' failed (' . self::failure($delivery['detail']) . ')';
            }
        }
        if ($rule->notifyEmail !== null && !$emailEnabled) {
            $problems[] = 'email is off on this server (NFSEN_ALERT_EMAIL_FROM is not set)';
        }

        if ($sent === []) {
            return 'No notification sent: ' . ($problems !== [] ? implode(', and ', $problems) : 'the rule has no email or webhook set up') . '.';
        }
        $line = (\count($sent) > 1 ? 'Notifications sent to ' : 'Notification sent to ') . implode(' and to ', $sent) . '.';

        return $problems !== [] ? $line . ' ' . ucfirst(implode(', and ', $problems)) . '.' : $line;
    }

    /**
     * Each channel's delivery for the dialog: sent, failed and why, not configured, or not sent
     * because the rule did not fire.
     *
     * @param TestResult $result
     *
     * @return list<ChannelRow>
     */
    private static function channelRows(array $result, AlertRule $rule, bool $emailEnabled): array {
        $rows = [];
        foreach (self::channels($rule) as $channel => $target) {
            $delivery = $result['delivery'][$channel];
            [$level, $text] = match ($delivery['status']) {
                AlertManager::DELIVERY_SENT => ['success', $channel === 'email' ? 'Sent to ' . $target : 'Sent'],
                AlertManager::DELIVERY_FAILED => ['error', 'Failed: ' . self::failure($delivery['detail'])],
                AlertManager::DELIVERY_UNCONFIGURED => ['', $channel === 'email' && $rule->notifyEmail !== null && !$emailEnabled
                    ? 'Not configured on this server (NFSEN_ALERT_EMAIL_FROM is not set)'
                    : 'Not configured'],
                default => ['', $result['evaluated'] ? 'Not sent: the rule would not fire' : 'Not sent: the rule could not be evaluated'],
            };
            $rows[] = ['label' => $channel === 'email' ? 'Email' : 'Webhook', 'level' => $level, 'text' => $text];
        }

        return $rows;
    }

    /**
     * The channels in the order the dialog lists them, with how a sentence names each.
     *
     * @return array{webhook: string, email: string}
     */
    private static function channels(AlertRule $rule): array {
        return ['webhook' => 'the webhook', 'email' => $rule->notifyEmail ?? 'the email address'];
    }

    private static function failure(string $detail): string {
        return $detail !== '' ? rtrim($detail, '.') : 'no reason given';
    }

    /** Math.round(): halves round towards positive infinity. */
    private static function jsRound(float $value): int {
        return (int) floor($value + 0.5);
    }

    /** The calendar date $ts falls on in $zone, as days since 1970-01-01. */
    private static function calendarDay(int $ts, \DateTimeZone $zone): int {
        return (int) floor(($ts + $zone->getOffset(new \DateTimeImmutable('@' . $ts))) / 86_400);
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
