<?php

declare(strict_types=1);

use Dom\HTMLDocument;
use mbolli\nfsen_ng\actions\AlertActions;
use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\AlertState;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\pages\AlertsPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Twig\Runtime\EscaperRuntime;

/** @param array<string, mixed> $overrides */
function alertsPageTestRule(array $overrides = []): AlertRule {
    return AlertRule::fromArray([
        'id' => 'r1',
        'name' => 'High traffic',
        'profile' => 'live',
        'sources' => ['gw1'],
        'metric' => 'bytes',
        'operator' => '>',
        'thresholdType' => 'absolute',
        'thresholdValue' => 5e9,
        'avgWindow' => '1h',
        'cooldownSlots' => 3,
        ...$overrides,
    ]);
}

/**
 * A channel's delivery as AlertManager reports it.
 *
 * @return array{status: string, detail: string}
 */
function alertsPageTestDelivery(string $status, string $detail = ''): array {
    return ['status' => $status, 'detail' => $detail];
}

/**
 * A TestResult as AlertManager::testRule() returns it; by default both channels were sent.
 *
 * @param array<string, mixed> $overrides
 *
 * @return array{fired: bool, evaluated: bool, value: float, threshold: ?float, condition: string, reason: string, slot: int,
 *               delivery: array{email: array{status: string, detail: string}, webhook: array{status: string, detail: string}},
 *               title: string, message: string, subject: string, body: string}
 */
function alertsPageTestResult(array $overrides = []): array {
    return [
        'fired' => true,
        'evaluated' => true,
        'value' => 6.1e9,
        'threshold' => 5e9,
        'condition' => 'bytes > 5,000,000,000.00',
        'reason' => '',
        'slot' => 1_700_000_100,
        'delivery' => ['email' => alertsPageTestDelivery(AlertManager::DELIVERY_SENT), 'webhook' => alertsPageTestDelivery(AlertManager::DELIVERY_SENT)],
        'title' => 'nfsen-ng alert: High traffic',
        'message' => 'bytes = 6,100,000,000.00 (profile: live, sources: gw1)',
        'subject' => '[nfsen-ng] Alert: High traffic',
        'body' => "Alert rule \"High traffic\" fired.\n",
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function alertsPageTestPrefs(array $overrides = []): UserPreferences {
    return UserPreferences::fromArray([
        'defaultView' => 'flows',
        'filters' => ['proto tcp'],
        'logPriority' => LOG_DEBUG,
        'selectedProfile' => 'lab/branch',
        'alerts' => [alertsPageTestRule()->toArray(), alertsPageTestRule(['id' => 'r2', 'name' => 'DNS spike'])->toArray()],
        'displayTimezone' => 'server',
        'defaultEmailSubjectTemplate' => 'old subject',
        'defaultWebhookTitleTemplate' => 'old title',
        'theme' => 'dark',
        'defaultRange' => '7d',
        'defaultUnit' => 'bytes',
        'compactTables' => true,
        'rdnsEnabled' => false,
        ...$overrides,
    ]);
}

/**
 * The Alerts page as the shell renders it, for these rules and no running AlertManager.
 *
 * @param list<AlertRule> $rules
 *
 * @return array{0: Context, 1: array<string, mixed>, 2: string}
 */
function alertsPageTestRender(array $rules): array {
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2']],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-alerts-page-test-missing', 'profile' => 'live'],
    ])->withAlerts($rules);
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-alerts-page-test-missing.json';

    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-alerts', '/', $app);
    $states = new PageStates();
    Shell::signals($c);
    foreach (PageRegistry::MODULES as $module) {
        $module::signals($c);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::signals($c);
    }
    Shell::register($c, $app, $states);
    foreach (PageRegistry::MODULES as $module) {
        $module::register($c, $app, $states);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::register($c, $app, $states);
    }
    $c->getSignal('page')?->setValue('alerts');

    $data = Shell::render($c, $app, $states, false);

    return [$c, $data, $c->render('pages/alerts.html.twig', $data)];
}

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw1', 'gw2'], 'db' => 'RRD'], 'nfdump' => ['profile' => 'live']]);
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('labels', function (): void {
    test('an absolute rule names its value with the metric\'s unit', function (): void {
        $rule = alertsPageTestRule();

        expect(AlertsPage::conditionLabel($rule))->toBe('bytes > absolute')
            ->and(AlertsPage::thresholdLabel($rule))->toBe('5 GB/s')
        ;
    });

    test('a relative rule names its share of the average and the window', function (): void {
        $rule = alertsPageTestRule(['metric' => 'packets', 'thresholdType' => 'percent_of_avg', 'thresholdValue' => 200, 'avgWindow' => '1h']);

        expect(AlertsPage::conditionLabel($rule))->toBe('packets > 200% of 1 h average')
            ->and(AlertsPage::thresholdLabel($rule))->toBe('1 h window')
        ;
    });

    test('a relative rule with a filter says its average is the filter\'s own traffic', function (): void {
        $rule = alertsPageTestRule(['nfdumpFilter' => 'dst port 443', 'thresholdType' => 'percent_of_avg', 'thresholdValue' => 150, 'avgWindow' => '6h']);

        expect(AlertsPage::conditionLabel($rule))->toBe("bytes > 150% of the filter's own 6 h average")
            ->and(AlertsPage::averageLabel($rule))->toBe("the filter's own 6 h average")
            ->and(AlertsPage::thresholdLabel($rule))->toBe('6 h window')
        ;
    });

    test('values scale by 1000 with up to three significant digits', function (string $metric, float $value, string $expected): void {
        expect(AlertsPage::formatValue($metric, $value))->toBe($expected);
    })->with([
        ['bytes', 0.0, '0 B/s'],
        ['bytes', 512.0, '512 B/s'],
        ['bytes', 1536.0, '1.54 kB/s'],
        ['bytes', 6.1e9, '6.1 GB/s'],
        ['bytes', 123.4e6, '123 MB/s'],
        ['packets', 200.0, '200 packets/s'],
        ['packets', 1500.0, '1.5k packets/s'],
        ['flows', 0.25, '0.25 flows/s'],
        ['flows', 42e6, '42M flows/s'],
        ['bytes', PHP_FLOAT_MAX, 'no baseline'],
        ['bytes', 999.4, '999 B/s'],
        ['bytes', 999.7, '1 kB/s'],
        ['bytes', 999_999.0, '1 MB/s'],
        ['packets', 999_999.0, '1M packets/s'],
    ]);

    test('values carry the time base they were counted over', function (): void {
        expect(AlertsPage::formatValue('bytes', 1.8e12, AlertsPage::PER_INTERVAL))->toBe('1.8 TB per 5 min')
            ->and(AlertsPage::formatValue('packets', 450e3, AlertsPage::PER_INTERVAL))->toBe('450k packets per 5 min')
            ->and(AlertsPage::formatValue('bytes', 6.1e9, ''))->toBe('6.1 GB')
        ;
    });

    test('RRD rates are per second; a filtered rule and VictoriaMetrics count per interval', function (): void {
        $plain = alertsPageTestRule();
        $filtered = alertsPageTestRule(['nfdumpFilter' => 'proto icmp']);

        expect(AlertsPage::valueBasis($plain))->toBe(AlertsPage::PER_SECOND)
            ->and(AlertsPage::thresholdLabel($plain))->toBe('5 GB/s')
            ->and(AlertsPage::valueBasis($filtered))->toBe(AlertsPage::PER_INTERVAL)
            ->and(AlertsPage::thresholdLabel($filtered))->toBe('5 GB per 5 min')
        ;

        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw1'], 'db' => 'VictoriaMetrics']]);

        expect(AlertsPage::storesTotals())->toBeTrue()
            ->and(AlertsPage::valueBasis($plain))->toBe(AlertsPage::PER_INTERVAL)
        ;
    });

    test('a relative threshold is shown in its values\' unit: its average is taken in that unit', function (): void {
        $relative = ['thresholdType' => 'percent_of_avg', 'thresholdValue' => 200, 'metric' => 'packets'];
        $events = [
            ['id' => 1, 'ts' => 1_700_000_100, 'kind' => 'fired', 'ruleId' => 'plain', 'ruleName' => 'Plain', 'profile' => 'live', 'sources' => [], 'metric' => 'packets', 'operator' => '>', 'value' => 3000.0, 'threshold' => 2000.0, 'origin' => 'live'],
            ['id' => 2, 'ts' => 1_700_000_100, 'kind' => 'fired', 'ruleId' => 'filtered', 'ruleName' => 'Filtered', 'profile' => 'live', 'sources' => [], 'metric' => 'packets', 'operator' => '>', 'value' => 9e5, 'threshold' => 6e5, 'origin' => 'live'],
        ];
        $rules = [
            alertsPageTestRule(['id' => 'plain', ...$relative]),
            alertsPageTestRule(['id' => 'filtered', 'nfdumpFilter' => 'proto udp', ...$relative]),
        ];

        expect(array_column(AlertsPage::eventRows($events, $rules), 'value'))->toBe([
            '3k packets/s vs 2k packets/s',
            '900k packets per 5 min vs 600k packets per 5 min',
        ]);
    });

    test('the form title names the rule being edited', function (): void {
        $rules = [alertsPageTestRule()];

        expect(AlertsPage::formTitle('', $rules))->toBe('New rule')
            ->and(AlertsPage::formTitle('r1', $rules))->toBe('Edit rule: High traffic')
            ->and(AlertsPage::formTitle('gone', $rules))->toBe('Edit rule')
        ;
    });

    test('a new rule covers every source', function (): void {
        expect(AlertsPage::formDefaults()['sources'])->toBe([]);
    });

    test('sources read All when the rule names none', function (): void {
        expect(AlertsPage::sourcesLabel([]))->toBe('All')
            ->and(AlertsPage::sourcesLabel(['gw1', 'gw2']))->toBe('gw1, gw2')
        ;
    });

    test('a source with a name shows the name (#177)', function (): void {
        $before = Config::$settings;
        Config::$settings = Settings::fromArray(['general' => ['sources' => ['gw1:Main office', 'gw2']]]);

        try {
            expect(AlertsPage::sourcesLabel(['gw1', 'gw2']))->toBe('Main office, gw2');
        } finally {
            Config::$settings = $before;
        }
    });

    // Each text is what the vendored sb-relative-time (format long, numeric auto) shows in Chromium
    // for the same moments and zone. 1_790_686_800 is 2026-09-29 13:00 UTC.
    test('relative times read as sb-relative-time writes them', function (int $now, int $age, string $zone, string $expected): void {
        expect(AlertsPage::relativeTime($now - $age, $now, new DateTimeZone($zone)))->toBe($expected);
    })->with([
        'now' => [1_790_686_800, 0, 'UTC', 'now'],
        'one second' => [1_790_686_800, 1, 'UTC', '1 second ago'],
        'seconds' => [1_790_686_800, 30, 'UTC', '30 seconds ago'],
        'the last second before a minute' => [1_790_686_800, 59, 'UTC', '59 seconds ago'],
        'one minute' => [1_790_686_800, 60, 'UTC', '1 minute ago'],
        'a half rounds up, as Math.round does' => [1_790_686_800, 90, 'UTC', '1 minute ago'],
        'past a half' => [1_790_686_800, 91, 'UTC', '2 minutes ago'],
        'minutes' => [1_790_686_800, 720, 'UTC', '12 minutes ago'],
        '59.5 minutes' => [1_790_686_800, 3570, 'UTC', '59 minutes ago'],
        'what rounds to 60 minutes reads as an hour' => [1_790_686_800, 3571, 'UTC', '1 hour ago'],
        'hours' => [1_790_686_800, 5 * 3600, 'UTC', '5 hours ago'],
        'yesterday' => [1_790_686_800, 26 * 3600, 'UTC', 'yesterday'],
        'days' => [1_790_686_800, 50 * 3600, 'UTC', '2 days ago'],
        'what rounds to 7 days reads as a week' => [1_790_686_800, 570_240, 'UTC', 'last week'],
        'weeks' => [1_790_686_800, 14 * 86_400, 'UTC', '2 weeks ago'],
        'last month' => [1_790_686_800, 31 * 86_400, 'UTC', 'last month'],
        'months' => [1_790_686_800, 95 * 86_400, 'UTC', '3 months ago'],
        'last year' => [1_790_686_800, 400 * 86_400, 'UTC', 'last year'],
        'years' => [1_790_686_800, 800 * 86_400, 'UTC', '2 years ago'],
        'a future moment' => [1_790_686_800, -30, 'UTC', 'in 30 seconds'],
        'tomorrow' => [1_790_686_800, -26 * 3600, 'UTC', 'tomorrow'],
        // 23:00 on the 28th to 00:30 on the 30th in Zurich, 21:00 on the 28th to 22:30 on the 29th in UTC.
        'past midnight in the zone' => [1_790_721_000, 91_800, 'Europe/Zurich', '2 days ago'],
        'before midnight in UTC' => [1_790_721_000, 91_800, 'UTC', 'yesterday'],
        // Zurich falls back on 2026-10-25: 00:10 CEST to 23:50 CET is 24 h 40 min on one date.
        'today, on a 25 hour day' => [1_792_968_600, 88_800, 'Europe/Zurich', 'today'],
        'the same moments in UTC' => [1_792_968_600, 88_800, 'UTC', 'yesterday'],
        'in 2 days across the change' => [1_792_968_600, -88_800, 'Europe/Zurich', 'in 2 days'],
    ]);

    test('rule keys are distinct lower case signal-name fragments without Datastar modifiers', function (): void {
        $keys = array_map(AlertsPage::ruleKey(...), ['a--b', 'a__b', 'A--B', 'a-b', '8319d10a-03e4']);

        expect(array_unique($keys))->toHaveCount(5)
            ->and(AlertsPage::ruleKey('a--b'))->toBe(AlertsPage::ruleKey('a--b'))
        ;
        foreach ($keys as $key) {
            expect($key)->toMatch('/^[0-9a-f]{16}$/');
        }
    });
});

describe('rows', function (): void {
    test('status: firing, ok and disabled, with the last fired time', function (): void {
        $rules = [
            alertsPageTestRule(),
            alertsPageTestRule(['id' => 'r2', 'name' => 'Quiet']),
            alertsPageTestRule(['id' => 'r3', 'name' => 'Off', 'enabled' => false, 'nfdumpFilter' => 'proto icmp']),
        ];
        $firing = AlertState::fromArray(['firing' => true]);
        // A disabled rule is never shown as firing, whatever its stale state says.
        $rows = AlertsPage::ruleRows($rules, ['r1' => $firing, 'r3' => $firing], ['r1' => 1_000_000 - 600], 1_000_000, new DateTimeZone('UTC'));

        expect(array_column($rows, 'status'))->toBe(['firing', 'ok', 'disabled'])
            ->and(array_column($rows, 'statusLabel'))->toBe(['Firing', 'OK', 'Disabled'])
            ->and(array_column($rows, 'level'))->toBe(['error', '', ''])
            ->and($rows[0]['lastFiredLabel'])->toBe('10 minutes ago')
            ->and($rows[0]['lastFired'])->toBe(1_000_000 - 600)
            ->and($rows[1]['lastFired'])->toBeNull()
            ->and($rows[1]['lastFiredLabel'])->toBe('Never')
            ->and($rows[2]['filter'])->toBe('proto icmp')
            ->and($rows[0]['filter'])->toBe('')
        ;
    });

    test('Last triggered says Unknown, not Never, while the history is unavailable', function (): void {
        $rows = AlertsPage::ruleRows([alertsPageTestRule()], [], null, 1_000_000, new DateTimeZone('UTC'));

        expect($rows[0]['lastFiredLabel'])->toBe('Unknown');
    });

    test('Edit gets every field of the rule, the sources included', function (): void {
        $rule = alertsPageTestRule(['sources' => ['gw1', 'gw2'], 'notifyEmail' => 'noc@example.net', 'webhookTitleTemplate' => 'T {rule}']);
        $form = AlertsPage::ruleRows([$rule], [], [], 0, new DateTimeZone('UTC'))[0]['form'];

        expect(array_keys($form))->toBe(AlertsPage::FORM_FIELDS)
            ->and(array_keys(AlertsPage::formDefaults()))->toBe(AlertsPage::FORM_FIELDS)
            ->and($form['sources'])->toBe(['gw1', 'gw2'])
            ->and($form['notifyEmail'])->toBe('noc@example.net')
            ->and($form['notifyWebhook'])->toBe('')
            ->and($form['webhookTitleTemplate'])->toBe('T {rule}')
            ->and($form['thresholdValue'])->toBe(5e9)
            ->and($form['enabled'])->toBeTrue()
        ;
    });

    test('events: kind, glyph level and value against threshold', function (): void {
        $event = ['id' => 7, 'ts' => 1_700_000_000, 'kind' => 'fired', 'ruleId' => 'r1', 'ruleName' => 'High traffic', 'profile' => 'live',
            'sources' => ['gw1'], 'metric' => 'bytes', 'operator' => '>', 'value' => 6.1e9, 'threshold' => 5e9, 'origin' => 'live'];
        $rows = AlertsPage::eventRows([
            $event,
            ['kind' => 'resolved', 'id' => 8, 'value' => 3.2e9] + $event,
            ['kind' => 'test', 'id' => 9] + $event,
            ['id' => 10, 'threshold' => null, 'origin' => 'migrated'] + $event,
            ['id' => 11, 'ruleId' => 'r2'] + $event,
            ['id' => 12, 'ruleId' => 'deleted'] + $event,
        ], [alertsPageTestRule(), alertsPageTestRule(['id' => 'r2', 'nfdumpFilter' => 'proto icmp'])]);

        expect(array_column($rows, 'label'))->toBe(['Fired', 'Resolved', 'Test', 'Fired', 'Fired', 'Fired'])
            ->and(array_column($rows, 'level'))->toBe(['error', 'success', 'info', 'error', 'error', 'error'])
            ->and($rows[0]['value'])->toBe('6.1 GB/s vs 5 GB/s')
            ->and($rows[1]['value'])->toBe('3.2 GB/s vs 5 GB/s')
            ->and($rows[3]['value'])->toBe('6.1 GB/s')
            ->and($rows[4]['value'])->toBe('6.1 GB per 5 min vs 5 GB per 5 min')
            ->and($rows[5]['value'])->toBe('6.1 GB vs 5 GB')
            ->and($rows[0]['iso'])->toBe('2023-11-14T22:13:20Z')
        ;
    });
});

describe('testResultView()', function (): void {
    test('a rule that fires names the figures and where the notifications went', function (): void {
        $rule = alertsPageTestRule(['notifyEmail' => 'noc@example.net', 'notifyWebhook' => 'https://hooks.example.net/x']);
        $view = AlertsPage::testResultView(alertsPageTestResult(), $rule, true);

        expect($view['title'])->toBe('Test: High traffic')
            ->and($view['level'])->toBe('error')
            ->and($view['headline'])->toBe('Would fire')
            ->and($view['detail'])->toBe('Current bytes: 6.1 GB/s. Threshold: bytes > 5 GB/s.')
            ->and($view['notification'])->toBe('Notifications sent to the webhook and to noc@example.net.')
            ->and($view['channels'])->toBe([
                ['label' => 'Webhook', 'level' => 'success', 'text' => 'Sent'],
                ['label' => 'Email', 'level' => 'success', 'text' => 'Sent to noc@example.net'],
            ])
            ->and($view['slotIso'])->toBe('2023-11-14T22:15:00Z')
            ->and(array_column($view['templates'], 'label'))->toBe(['Webhook title', 'Webhook message', 'Email subject', 'Email body'])
            ->and(array_column($view['templates'], 'text'))->toBe([
                'nfsen-ng alert: High traffic',
                'bytes = 6,100,000,000.00 (profile: live, sources: gw1)',
                '[nfsen-ng] Alert: High traffic',
                "Alert rule \"High traffic\" fired.\n",
            ])
        ;
    });

    test('a rule that does not fire still shows the four rendered templates', function (): void {
        $rule = alertsPageTestRule(['metric' => 'packets', 'thresholdType' => 'percent_of_avg', 'thresholdValue' => 200, 'notifyWebhook' => 'https://h.example/x']);
        $skipped = ['email' => alertsPageTestDelivery(AlertManager::DELIVERY_UNCONFIGURED), 'webhook' => alertsPageTestDelivery(AlertManager::DELIVERY_SKIPPED)];
        $view = AlertsPage::testResultView(alertsPageTestResult(['fired' => false, 'delivery' => $skipped, 'value' => 900.0, 'threshold' => 2400.0]), $rule, true);

        expect($view['level'])->toBe('success')
            ->and($view['headline'])->toBe('Would not fire')
            ->and($view['detail'])->toBe('Current packets: 900 packets/s. Threshold: packets > 2.4k packets/s (200% of the 1 h average).')
            ->and($view['notification'])->toBe('No notification sent.')
            ->and($view['channels'])->toBe([
                ['label' => 'Webhook', 'level' => '', 'text' => 'Not sent: the rule would not fire'],
                ['label' => 'Email', 'level' => '', 'text' => 'Not configured'],
            ])
            ->and($view['templates'])->toHaveCount(4)
            ->and($view['templates'][0]['text'])->toBe('nfsen-ng alert: High traffic')
        ;
    });

    test('a filtered rule shows its figures per interval', function (): void {
        $rule = alertsPageTestRule(['nfdumpFilter' => 'proto udp']);
        $view = AlertsPage::testResultView(alertsPageTestResult(['value' => 1.8e12]), $rule, true);

        expect($view['detail'])->toBe('Current bytes: 1.8 TB per 5 min. Threshold: bytes > 5 GB per 5 min.');
    });

    test('a filtered relative rule compares with the filter\'s own average, per interval', function (): void {
        $rule = alertsPageTestRule(['nfdumpFilter' => 'proto udp', 'thresholdType' => 'percent_of_avg', 'thresholdValue' => 200, 'avgWindow' => '10m']);
        $view = AlertsPage::testResultView(alertsPageTestResult(['value' => 1.8e12, 'threshold' => 1.2e12]), $rule, true);

        expect($view['detail'])->toBe("Current bytes: 1.8 TB per 5 min. Threshold: bytes > 1.2 TB per 5 min (200% of the filter's own 10 min average).");
    });

    test('a rule that could not be evaluated says why', function (): void {
        $skipped = ['email' => alertsPageTestDelivery(AlertManager::DELIVERY_SKIPPED), 'webhook' => alertsPageTestDelivery(AlertManager::DELIVERY_UNCONFIGURED)];
        $result = alertsPageTestResult(['fired' => false, 'evaluated' => false, 'delivery' => $skipped, 'threshold' => null, 'reason' => 'no baseline yet for the 1 h average']);
        $view = AlertsPage::testResultView($result, alertsPageTestRule(['notifyEmail' => 'noc@example.net']), true);

        expect($view['level'])->toBe('warning')
            ->and($view['headline'])->toBe('Would not fire')
            ->and($view['detail'])->toBe('Could not evaluate: no baseline yet for the 1 h average.')
            ->and($view['notification'])->toBe('No notification sent.')
            ->and(array_column($view['channels'], 'text'))->toBe(['Not configured', 'Not sent: the rule could not be evaluated'])
            ->and($view['templates'])->toHaveCount(4)
            ->and(AlertsPage::testResultView(['reason' => ''] + $result, alertsPageTestRule(), true)['detail'])
            ->toBe('Could not evaluate: the traffic could not be read.')
        ;
    });

    test('what was sent names each channel, and why one failed or is off', function (): void {
        $both = alertsPageTestRule(['notifyEmail' => 'noc@example.net', 'notifyWebhook' => 'https://h.example/x']);
        $emailOnly = alertsPageTestRule(['notifyEmail' => 'a@b.example']);
        $sent = alertsPageTestDelivery(AlertManager::DELIVERY_SENT);
        $none = alertsPageTestDelivery(AlertManager::DELIVERY_UNCONFIGURED);
        $refused = alertsPageTestDelivery(AlertManager::DELIVERY_FAILED, 'the mail system did not accept it');
        $http500 = alertsPageTestDelivery(AlertManager::DELIVERY_FAILED, 'HTTP 500');
        $view = static fn (array $email, array $webhook, AlertRule $rule, bool $emailEnabled): array => AlertsPage::testResultView(alertsPageTestResult(['delivery' => ['email' => $email, 'webhook' => $webhook]]), $rule, $emailEnabled);
        $line = static fn (array $email, array $webhook, AlertRule $rule, bool $emailEnabled): string => $view($email, $webhook, $rule, $emailEnabled)['notification'];

        expect($line($none, $sent, $both, false))->toBe('Notification sent to the webhook. Email is off on this server (NFSEN_ALERT_EMAIL_FROM is not set).')
            ->and($line($sent, $none, $emailOnly, true))->toBe('Notification sent to a@b.example.')
            ->and($line($refused, $sent, $both, true))->toBe('Notification sent to the webhook. Sending to noc@example.net failed (the mail system did not accept it).')
            ->and($line($sent, $http500, $both, true))->toBe('Notification sent to noc@example.net. Sending to the webhook failed (HTTP 500).')
            ->and($line($none, $none, alertsPageTestRule(), true))->toBe('No notification sent: the rule has no email or webhook set up.')
            ->and($line($refused, $http500, $both, true))
            ->toBe('No notification sent: sending to the webhook failed (HTTP 500), and sending to noc@example.net failed (the mail system did not accept it).')
            ->and($line($none, $none, $emailOnly, false))->toBe('No notification sent: email is off on this server (NFSEN_ALERT_EMAIL_FROM is not set).')
            ->and($line($none, $http500, $both, false))
            ->toBe('No notification sent: sending to the webhook failed (HTTP 500), and email is off on this server (NFSEN_ALERT_EMAIL_FROM is not set).')
            ->and($view($none, $http500, $both, false)['channels'])->toBe([
                ['label' => 'Webhook', 'level' => 'error', 'text' => 'Failed: HTTP 500'],
                ['label' => 'Email', 'level' => '', 'text' => 'Not configured on this server (NFSEN_ALERT_EMAIL_FROM is not set)'],
            ])
            ->and($view($refused, $none, $emailOnly, true)['channels'])->toBe([
                ['label' => 'Webhook', 'level' => '', 'text' => 'Not configured'],
                ['label' => 'Email', 'level' => 'error', 'text' => 'Failed: the mail system did not accept it'],
            ])
        ;
    });
});

describe('default templates in other tabs', function (): void {
    beforeEach(function (): void {
        $this->app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    });

    test('a tab takes over templates another tab saved', function (): void {
        $c = new Context('ctx-alert-tpl', '/', $this->app);
        AlertsPage::signals($c);
        $title = $c->getSignal('settings_defaultWebhookTitleTemplate');
        $subject = $c->getSignal('settings_defaultEmailSubjectTemplate');

        Config::$settings = Config::$settings->withDefaultWebhookTitleTemplate('Saved {rule}')->withDefaultEmailSubjectTemplate('Saved subject');
        AlertsPage::refreshTemplates($c);

        expect($title?->string())->toBe('Saved {rule}')
            ->and($title?->hasChanged())->toBeTrue()
            ->and($subject?->string())->toBe('Saved subject')
        ;

        // Taken over, so a later save reaches this tab too.
        Config::$settings = Config::$settings->withDefaultWebhookTitleTemplate('Again');
        AlertsPage::refreshTemplates($c);

        expect($title?->string())->toBe('Again');
    });

    test('a tab keeps its own edit, and follows again once it saved', function (): void {
        $c = new Context('ctx-alert-tpl-edit', '/', $this->app);
        AlertsPage::signals($c);
        $title = $c->getSignal('settings_defaultWebhookTitleTemplate');
        $title?->setValue('My edit');

        Config::$settings = Config::$settings->withDefaultWebhookTitleTemplate('Other tab');
        AlertsPage::refreshTemplates($c);

        expect($title?->string())->toBe('My edit');

        // This tab saves its edit; then another tab's save reaches it again.
        Config::$settings = Config::$settings->withDefaultWebhookTitleTemplate('My edit');
        AlertsPage::refreshTemplates($c);
        Config::$settings = Config::$settings->withDefaultWebhookTitleTemplate('Later');
        AlertsPage::refreshTemplates($c);

        expect($title?->string())->toBe('Later');
    });
});

describe('AlertActions', function (): void {
    test('buildTemplatePreferences() replaces the four templates and keeps everything else', function (): void {
        $existing = alertsPageTestPrefs();
        $saved = AlertActions::buildTemplatePreferences($existing, [
            'defaultEmailSubjectTemplate' => 'New {rule}',
            'defaultEmailBodyTemplate' => "Body\n{value}",
            'defaultWebhookTitleTemplate' => '',
            'defaultWebhookMessageTemplate' => '{condition}',
            'selectedProfile' => 'live',
            'alerts' => [],
        ]);

        $expected = [
            'defaultEmailSubjectTemplate' => 'New {rule}',
            'defaultEmailBodyTemplate' => "Body\n{value}",
            'defaultWebhookTitleTemplate' => '',
            'defaultWebhookMessageTemplate' => '{condition}',
        ] + $existing->toArray();

        expect($saved->toArray())->toEqual($expected)
            ->and($saved->selectedProfile)->toBe('lab/branch')
            ->and(array_map(static fn (AlertRule $r): string => $r->id, $saved->alerts))->toBe(['r1', 'r2'])
            ->and($saved->theme)->toBe('dark')
            ->and($saved->filters)->toBe(['proto tcp'])
        ;
    });

    test('buildTemplatePreferences() leaves a template it was not given alone', function (): void {
        $saved = AlertActions::buildTemplatePreferences(alertsPageTestPrefs(), ['defaultEmailBodyTemplate' => 'only this']);

        expect($saved->defaultEmailSubjectTemplate)->toBe('old subject')
            ->and($saved->defaultWebhookTitleTemplate)->toBe('old title')
            ->and($saved->defaultEmailBodyTemplate)->toBe('only this')
        ;
    });

    test('withRules() swaps the rules only', function (): void {
        $prefs = alertsPageTestPrefs();
        $saved = AlertActions::withRules($prefs, [alertsPageTestRule(['id' => 'r9', 'name' => 'New'])]);

        expect(array_map(static fn (AlertRule $r): string => $r->name, $saved->alerts))->toBe(['New'])
            ->and(['alerts' => []] + $saved->toArray())->toEqual(['alerts' => []] + $prefs->toArray())
        ;
    });

    test('before preferences.json exists the settings in effect are the starting point', function (): void {
        Config::$settings = Config::$settings->withAlerts([alertsPageTestRule()])->withDefaultView('flows')->withFilters(['proto udp']);
        $prefs = AlertActions::preferencesFromSettings(Config::$settings);
        $applied = $prefs->applyTo(Config::$settings);

        expect($prefs->selectedProfile)->toBe('live')
            ->and($prefs->theme)->toBe('')
            ->and($prefs->filters)->toBe(['proto udp'])
            ->and($prefs->alerts[0]->name)->toBe('High traffic')
            ->and($applied->defaultView)->toBe(Config::$settings->defaultView)
            ->and($applied->defaultTheme)->toBe(Config::$settings->defaultTheme)
            ->and($applied->filters)->toBe(['proto udp'])
        ;
    });

    test('ruleFromForm() builds the rule the form describes', function (): void {
        $form = ['name' => '  High traffic ', 'sources' => ['gw2', 'gw1', 'gw2'], 'thresholdValue' => '5000000000', 'cooldownSlots' => 6,
            'notifyWebhook' => 'https://hooks.example.net/a', 'nfdumpFilter' => ' proto icmp ', 'emailSubjectTemplate' => ''] + AlertsPage::formDefaults();
        $rule = AlertActions::ruleFromForm($form, 'abc', ['live'], ['gw1', 'gw2']);

        expect($rule->id)->toBe('abc')
            ->and($rule->name)->toBe('High traffic')
            ->and($rule->sources)->toBe(['gw2', 'gw1'])
            ->and($rule->thresholdValue)->toBe(5e9)
            ->and($rule->cooldownSlots)->toBe(6)
            ->and($rule->notifyWebhook)->toBe('https://hooks.example.net/a')
            ->and($rule->notifyEmail)->toBeNull()
            ->and($rule->nfdumpFilter)->toBe('proto icmp')
            ->and($rule->emailSubjectTemplate)->toBeNull()
            ->and($rule->enabled)->toBeTrue()
        ;
    });

    test('ruleFromForm() refuses a form it cannot save', function (array $change, string $message): void {
        $form = [...AlertsPage::formDefaults(), 'name' => 'Rule', ...$change];

        expect(fn () => AlertActions::ruleFromForm($form, 'abc', ['live'], ['gw1', 'gw2']))
            ->toThrow(InvalidArgumentException::class, $message)
        ;
    })->with([
        'no name' => [['name' => ' '], 'The rule needs a name.'],
        'unknown profile' => [['profile' => '../etc'], 'Unknown profile: ../etc.'],
        'unknown source' => [['sources' => ['gw3']], 'Unknown source: gw3.'],
        'unknown metric' => [['metric' => 'bits'], 'Unknown metric: bits.'],
        'unknown operator' => [['operator' => '=='], 'Unknown operator: ==.'],
        'negative threshold' => [['thresholdValue' => -1], 'The threshold must be a number of 0 or more.'],
        'text threshold' => [['thresholdValue' => 'lots'], 'The threshold must be a number of 0 or more.'],
        'cooldown too long' => [['cooldownSlots' => 289], 'The cooldown must be a whole number of intervals from 0 to 288.'],
        'webhook scheme' => [['notifyWebhook' => 'file:///etc/passwd'], 'The webhook URL must start with http:// or https://.'],
    ]);

    test('findRule() by id', function (): void {
        $rules = [alertsPageTestRule(), alertsPageTestRule(['id' => 'r2'])];

        expect(AlertActions::findRule($rules, 'r2')?->id)->toBe('r2')
            ->and(AlertActions::findRule($rules, 'nope'))->toBeNull()
            ->and(AlertActions::findRule($rules, ''))->toBeNull()
        ;
    });

    test('a failed toggle puts the row switch back to the saved state', function (): void {
        $key = AlertsPage::ruleKey('r1');

        expect(AlertActions::switchScript('r1', true))->toBe("document.querySelectorAll('#alertRule-{$key} input[role=\"switch\"]').forEach((s) => { s.checked = true; })")
            ->and(AlertActions::switchScript('r1', false))->toEndWith('s.checked = false; })')
        ;
    });
});

describe('templates', function (): void {
    test('the token buttons and the JS preview know every token AlertManager substitutes', function (): void {
        $rule = alertsPageTestRule();
        $tokens = array_keys(AlertManager::buildTemplateVars($rule, ['flows' => 1.0, 'packets' => 2.0, 'bytes' => 3.0], 4.0, 0));
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/frontend/js/components/alert-template-preview.js');

        expect(AlertsPage::templateTokens())->toBe($tokens);
        foreach ($tokens as $token) {
            expect($js)->toContain("'{$token}':");
        }
    });

    test('the preview shows a rule with no source selected the way the notification does', function (): void {
        $rule = alertsPageTestRule(['sources' => []]);

        expect(AlertsPage::allSourcesText())->toBe(AlertManager::buildTemplateVars($rule, ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0], 0.0, 0)['{sources}'])
            ->and((string) file_get_contents(dirname(__DIR__, 2) . '/frontend/js/components/alert-template-preview.js'))->toContain('form.allSources')
        ;
    });

    test('the page renders from its view data, with no utility classes and escaped rule names', function (): void {
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

        try {
            [$c, $data, $html] = alertsPageTestRender([
                alertsPageTestRule(['name' => '<b>High</b> traffic', 'nfdumpFilter' => 'proto icmp']),
                alertsPageTestRule(['id' => 'r2', 'name' => 'Quiet', 'enabled' => false, 'sources' => []]),
                alertsPageTestRule(['id' => 'r3', 'name' => "Spike @gw1(core) it's \\"]),
            ]);

            // Datastar rewrites "@name(" into an action call even inside string literals (engine.ts genRx).
            preg_match_all('/\sdata-(?:on:[a-z]+|text|attr:[a-z-]+|signals__ifmissing)="([^"]*)"/', $html, $expressions);
            $actions = [];
            foreach ($expressions[1] as $expression) {
                preg_match_all('/@([A-Za-z_$][\w$]*)\(/', html_entity_decode($expression, ENT_QUOTES | ENT_HTML5), $calls);
                array_push($actions, ...$calls[1]);
            }

            expect(array_keys($c->getNamedActions()))->toContain('save-alert', 'delete-alert', 'toggle-alert', 'test-alert', 'save-alert-templates')
                ->and(array_values(array_unique($actions)))->toBe(['post'])
                ->and($data['pages']['alerts']['rules'])->toHaveCount(3)
                ->and($html)->toContain(
                    'id="alertRules"',
                    'id="alertHistory"',
                    'id="alert-form-card"',
                    'id="alert-default-templates-card"',
                    '<h2 id="alertTemplatesTitle">',
                    'aria-controls="alertTemplatesBody"',
                    'data-signals__ifmissing="{_alert_testing_' . AlertsPage::ruleKey('r1') . ': false}"',
                    "allSources: '" . (new EscaperRuntime())->escape(AlertsPage::allSourcesText(), 'js') . "'",
                    '3 rules',
                    'History unavailable: alerts are not running on this server',
                    'aria-label="Delete &lt;b&gt;High&lt;/b&gt; traffic"',
                    'aria-label="Edit Quiet"',
                    'placeholder="e.g. High traffic on gw1"',
                    'id="alertNfdumpFilter"',
                    'data-alert-token="{rule}"',
                    '>New rule</h2>',
                    'Threshold (bytes/s)',
                )
                ->and($html)->not->toContain('<b>High</b>')
                ->and($html)->not->toMatch('/class="[^"]*\b(muted|mono|strong|text-end|nowrap|upper|cluster-between|spacer|text-(danger|warning|success|info))\b/')
                ->and($html)->not->toMatch('/[\x{2013}\x{2014}]/u')
            ;
        } finally {
            if ($prefsBefore !== null) {
                Config::$prefsFile = $prefsBefore;
            }
        }
    });

    test('the Average over help says where the baseline comes from, the filter\'s own traffic once there is a filter', function (): void {
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

        try {
            [$c, $data, $html] = alertsPageTestRender([]);
            $help = static fn (string $page): string => trim(HTMLDocument::createFromString($page, LIBXML_NOERROR)->getElementById('alertFormAvgWindowHelp')?->textContent ?? '');
            $c->getSignal('alert_form_nfdumpFilter')?->setValue('dst port 443');
            $filtered = $c->render('pages/alerts.html.twig', $data);
            $select = HTMLDocument::createFromString($filtered, LIBXML_NOERROR)->getElementById('alertFormAvgWindow');

            expect($help($html))->toBe("The average of the stored traffic of the rule's sources over this window, before the interval checked.")
                ->and($help($filtered))->toBe("The average of the filter's own traffic over this window, before the interval checked. An enabled rule records that traffic at each check, so there is no baseline until it has checked one interval, or while the filter matched nothing in the window.")
                ->and($select?->getAttribute('aria-describedby'))->toBe('alertFormAvgWindowHelp')
                ->and($html)->toContain("a percentage of the average compares with the filter's own recent traffic")
            ;
        } finally {
            if ($prefsBefore !== null) {
                Config::$prefsFile = $prefsBefore;
            }
        }
    });

    test('without rules the Rules card shows the empty state with a New rule button', function (): void {
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

        try {
            [, , $html] = alertsPageTestRender([]);
            $state = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('#alertRules .empty-state');
            $children = [];
            for ($child = $state?->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
                $children[] = $child->localName;
            }

            expect($state?->textContent)->toContain('No alert rules yet', 'New rule')
                ->and($children)->toBe(['svg', 'h2', 'p', 'button'])
                ->and($html)->toContain('0 rules')
            ;
        } finally {
            if ($prefsBefore !== null) {
                Config::$prefsFile = $prefsBefore;
            }
        }
    });

    test('Last triggered is an sb-relative-time around the server label, its zone following displayTz', function (): void {
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

        try {
            $rules = [alertsPageTestRule(), alertsPageTestRule(['id' => 'r2', 'name' => 'Quiet'])];
            [$c, $data] = alertsPageTestRender($rules);
            $now = 1_790_686_800;
            $data['pages']['alerts']['rules'] = AlertsPage::ruleRows($rules, [], ['r1' => $now - 720], $now, new DateTimeZone('UTC'));
            $cells = HTMLDocument::createFromString($c->render('pages/alerts.html.twig', $data), LIBXML_NOERROR)
                ->querySelectorAll('#alertRules tbody td[data-kind="time"]')
            ;
            $host = $cells->item(0)?->querySelector('sb-relative-time');
            $displayTz = $c->getSignal('displayTz')?->id();
            $nfcapdTz = $c->getSignal('nfcapdTz')?->id();

            expect($host)->not->toBeNull()
                ->and($host?->getAttribute('datetime'))->toBe((string) ($now - 720))
                ->and($host?->textContent)->toBe('12 minutes ago')
                ->and($host?->childElementCount)->toBe(0)
                ->and(array_map(static fn (string $name): ?string => $host?->getAttribute($name), ['format', 'numeric', 'title-lang', 'title-style']))
                ->toBe(['long', 'auto', 'auto', 'numeric'])
                ->and($host?->getAttribute('data-attr:time-zone'))->toBe("\${$displayTz} === 'server' ? \${$nfcapdTz} : ''")
                ->and($host?->getAttribute('data-preserve-attr'))->toBe('time-zone')
                ->and($host?->hasAttribute('data-init'))->toBeFalse()
                ->and(trim($cells->item(1)->textContent ?? ''))->toBe('Never')
                ->and($cells->item(1)?->querySelector('sb-relative-time'))->toBeNull()
            ;
        } finally {
            if ($prefsBefore !== null) {
                Config::$prefsFile = $prefsBefore;
            }
        }
    });

    test('the Test dialog escapes what the templates render', function (): void {
        $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $c = new Context('ctx-alert-test', '/', $app);
        $c->signal('browser', 'displayTz');
        $c->signal('UTC', 'nfcapdTz');
        $delivery = ['email' => alertsPageTestDelivery(AlertManager::DELIVERY_UNCONFIGURED), 'webhook' => alertsPageTestDelivery(AlertManager::DELIVERY_FAILED, '<b>HTTP 500</b>')];
        $view = AlertsPage::testResultView(alertsPageTestResult(['title' => '<img src=x onerror=alert(1)>', 'delivery' => $delivery]), alertsPageTestRule(['notifyWebhook' => 'https://h.example/x']), true);

        $html = $c->render('pages/alert-test-result.html.twig', ['view' => $view]);
        $channels = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('#alertTestResult .alert-test-channels');
        $rows = [];
        foreach ($channels?->querySelectorAll('dt') ?? [] as $term) {
            $rows[trim($term->textContent)] = [trim((string) $term->nextElementSibling?->textContent), $term->nextElementSibling?->querySelector('.status-dot')?->getAttribute('data-level')];
        }

        expect($html)->toContain('id="alertTestResult"', 'data-preserve-attr="open"', 'Test: High traffic', 'Would fire', 'Webhook title', 'Email subject', '&lt;img src=x onerror=alert(1)&gt;')
            ->and($html)->not->toContain('<img', '<b>')
            ->and($rows)->toBe(['Webhook' => ['Failed: <b>HTTP 500</b>', 'error'], 'Email' => ['Not configured', null]])
        ;
    });
});
