<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\pages\AlertsPage;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\processor\FilterValidator;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The Alerts page's actions (4.6.2): save-alert, delete-alert, toggle-alert, test-alert and
 * save-alert-templates. Rules live in preferences.json; every change reaches the other tabs
 * through the alerts:fired broadcast.
 */
final class AlertActions {
    /** The longest cooldown the form accepts: one day of 5 minute intervals. */
    public const int MAX_COOLDOWN = 288;

    public static function register(Context $c, Via $app, PageStates $states): void {
        $c->action(static function (Context $c) use ($app): void {
            $form = [];
            foreach (AlertsPage::FORM_FIELDS as $field) {
                $form[$field] = $c->getSignal('alert_form_' . $field)?->getValue();
            }

            try {
                // nfdump -Z yields, so the filter is checked before the preferences are read: a
                // change another tab saves meanwhile is not overwritten.
                $filter = \is_scalar($form['nfdumpFilter'] ?? null) ? trim((string) $form['nfdumpFilter']) : '';
                if ($filter !== '') {
                    $check = FilterValidator::validate($filter);
                    if (!$check['valid'] && $check['message'] !== FilterValidator::UNCHECKED) {
                        throw new \InvalidArgumentException('The traffic filter is invalid: ' . $check['message']);
                    }
                }

                $existing = null;
                $profiles = $c->getSignal('available_profiles')?->array() ?? [];
                $rule = SettingsActions::exclusively(static function () use ($form, $profiles, &$existing): AlertRule {
                    $prefs = self::currentPreferences();
                    $id = \is_string($form['id'] ?? null) ? trim($form['id']) : '';
                    $existing = $id !== '' ? self::findRule($prefs->alerts, $id) : null;
                    if ($id !== '' && $existing === null) {
                        throw new \InvalidArgumentException('This rule was deleted in another tab.');
                    }
                    if ($existing === null) {
                        $id = bin2hex(random_bytes(16));
                    }

                    $rule = self::ruleFromForm(
                        $form,
                        $id,
                        AlertsPage::profileOptions($profiles, $prefs->alerts),
                        AlertsPage::sourceOptions(Config::$settings->sources, $prefs->alerts),
                    );

                    $rules = $existing !== null
                        ? array_map(static fn (AlertRule $r): AlertRule => $r->id === $rule->id ? $rule : $r, $prefs->alerts)
                        : [...$prefs->alerts, $rule];
                    self::store(self::withRules($prefs, $rules));

                    return $rule;
                });
                if ($existing !== null && $existing->enabled && !$rule->enabled) {
                    self::manager($app)?->forget($rule->id, $existing);
                }

                self::resetForm($c);
                self::toast($c, 'success', ($existing !== null ? 'Rule updated: ' : 'Rule created: ') . $rule->name, true);
            } catch (\Throwable $e) {
                self::toast($c, 'error', 'Save failed: ' . $e->getMessage());
            }

            $c->sync();
            self::broadcast($app, 'alerts:fired');
        }, 'save-alert');

        $c->action(static function (Context $c) use ($app): void {
            $id = self::inputId($c);
            if ($id === '') {
                return;
            }

            try {
                $rule = SettingsActions::exclusively(static function () use ($id): ?AlertRule {
                    $prefs = self::currentPreferences();
                    $rule = self::findRule($prefs->alerts, $id);
                    if ($rule !== null) {
                        self::store(self::withRules($prefs, array_values(array_filter($prefs->alerts, static fn (AlertRule $r): bool => $r->id !== $id))));
                    }

                    return $rule;
                });
                if ($rule !== null) {
                    self::manager($app)?->forget($id, $rule);
                    if ($c->getSignal('alert_form_id')?->string() === $id) {
                        self::resetForm($c);
                    }
                    self::toast($c, 'success', 'Rule deleted: ' . $rule->name, true);
                }
            } catch (\Throwable $e) {
                self::toast($c, 'error', 'Delete failed: ' . $e->getMessage());
            }

            $c->sync();
            self::broadcast($app, 'alerts:fired');
        }, 'delete-alert');

        $c->action(static function (Context $c) use ($app): void {
            $id = self::inputId($c);
            if ($id === '') {
                return;
            }

            try {
                // The switch posts the state it shows, so a tab with a stale row does not flip it back.
                $wanted = $c->input('enabled');
                $rule = SettingsActions::exclusively(static function () use ($id, $wanted): ?AlertRule {
                    $prefs = self::currentPreferences();
                    $rule = self::findRule($prefs->alerts, $id);
                    $enabled = \is_string($wanted) && $wanted !== '' ? $wanted === 'true' : !($rule !== null && $rule->enabled);
                    if ($rule === null || $rule->enabled === $enabled) {
                        return null;
                    }
                    self::store(self::withRules($prefs, array_map(
                        static fn (AlertRule $r): AlertRule => $r->id === $id ? $r->withEnabled($enabled) : $r,
                        $prefs->alerts,
                    )));

                    return $rule;
                });
                if ($rule !== null) {
                    // Either way the rule starts over; a firing rule that is turned off is resolved.
                    self::manager($app)?->forget($id, $rule);
                    // Otherwise Update rule would put the old state back.
                    if ($c->getSignal('alert_form_id')?->string() === $id) {
                        $c->getSignal('alert_form_enabled')?->setValue(!$rule->enabled);
                    }
                }
            } catch (\Throwable $e) {
                self::toast($c, 'error', 'Changing the rule failed: ' . $e->getMessage());
                // The morph leaves a clicked checkbox alone while its checked attribute is unchanged.
                $saved = self::findRule(Config::$settings->alerts, $id);
                if ($saved !== null) {
                    $c->execScript(self::switchScript($id, $saved->enabled));
                }
            }

            $c->sync();
            self::broadcast($app, 'alerts:fired');
        }, 'toggle-alert');

        $c->action(static function (Context $c) use ($app, $states): void {
            $rule = self::findRule(Config::$settings->alerts, self::inputId($c));
            $manager = self::manager($app);
            if ($rule === null || $manager === null) {
                self::toast($c, 'error', $rule === null ? 'This rule no longer exists.' : 'Alerts are not running on this server.');
                $c->sync();

                return;
            }

            try {
                $view = AlertsPage::testResultView($manager->testRule($rule, $rule->profile), $rule, Config::$settings->alertEmailFrom !== '');
            } catch (\Throwable $e) {
                self::toast($c, 'error', 'Test failed: ' . $e->getMessage());
                $c->sync();

                return;
            }

            Shell::openModal($c, $states->shell, $c->render('pages/alert-test-result.html.twig', ['view' => $view]), 'alertTestResult');
            $c->sync();
            // The test event belongs in every tab's Recent alerts.
            self::broadcast($app, 'alerts:fired');
        }, 'test-alert');

        $c->action(static function (Context $c) use ($app): void {
            $templates = [];
            foreach (AlertsPage::TEMPLATE_SIGNALS as $preference => $signal) {
                $templates[$preference] = $c->getSignal($signal)?->string() ?? '';
            }

            try {
                SettingsActions::exclusively(static fn () => self::store(self::buildTemplatePreferences(self::currentPreferences(), $templates)));
                self::toast($c, 'success', 'Default templates saved.', true);
            } catch (\Throwable $e) {
                self::toast($c, 'error', 'Saving the default templates failed: ' . $e->getMessage());
            }

            $c->sync();
            self::broadcast($app, 'settings:saved');
        }, 'save-alert-templates');
    }

    /**
     * The saved preferences with the four global templates replaced; everything else, the
     * selected profile and the rules included, stays as it was.
     *
     * @param array<string, mixed> $templates preference name => template, see AlertsPage::TEMPLATE_SIGNALS
     */
    public static function buildTemplatePreferences(UserPreferences $existing, array $templates): UserPreferences {
        $changes = [];
        foreach (array_keys(AlertsPage::TEMPLATE_SIGNALS) as $preference) {
            if (\array_key_exists($preference, $templates)) {
                $value = $templates[$preference];
                $changes[$preference] = \is_scalar($value) ? (string) $value : '';
            }
        }

        return UserPreferences::fromArray(array_merge($existing->toArray(), $changes));
    }

    /**
     * The saved preferences with a new rule list.
     *
     * @param list<AlertRule> $rules
     */
    public static function withRules(UserPreferences $prefs, array $rules): UserPreferences {
        return UserPreferences::fromArray(array_merge(
            $prefs->toArray(),
            ['alerts' => array_map(static fn (AlertRule $r): array => $r->toArray(), $rules)],
        ));
    }

    /**
     * preferences.json, or before its first save the settings in effect, so that saving a rule
     * does not reset every other preference to its default.
     */
    public static function currentPreferences(): UserPreferences {
        return UserPreferences::load(Config::$prefsFile) ?? self::preferencesFromSettings(Config::$settings);
    }

    public static function preferencesFromSettings(Settings $settings): UserPreferences {
        return UserPreferences::fromArray([
            'defaultView' => $settings->defaultView,
            'defaultGraphDisplay' => $settings->defaultGraphDisplay,
            'defaultGraphDatatype' => $settings->defaultGraphDatatype,
            'defaultGraphProtocols' => $settings->defaultGraphProtocols,
            'defaultFlowLimit' => $settings->defaultFlowLimit,
            'defaultStatsOrderBy' => $settings->defaultStatsOrderBy,
            'filters' => $settings->filters,
            'logPriority' => $settings->logPriority,
            'selectedProfile' => $settings->nfdumpProfile,
            'alerts' => array_map(static fn (AlertRule $r): array => $r->toArray(), $settings->alerts),
            'displayTimezone' => $settings->displayTimezone,
            'defaultEmailSubjectTemplate' => $settings->defaultEmailSubjectTemplate,
            'defaultEmailBodyTemplate' => $settings->defaultEmailBodyTemplate,
            'defaultWebhookTitleTemplate' => $settings->defaultWebhookTitleTemplate,
            'defaultWebhookMessageTemplate' => $settings->defaultWebhookMessageTemplate,
            // '' is the deployment theme, which is what applies while nothing is saved.
            'theme' => '',
            'defaultRange' => $settings->defaultRange,
            'defaultUnit' => $settings->defaultUnit,
            'compactTables' => $settings->compactTables,
            'rdnsEnabled' => $settings->rdnsEnabled,
        ]);
    }

    /**
     * A rule from the posted form, checked field by field.
     *
     * @param array<string, mixed> $form     AlertsPage::FORM_FIELDS => posted value
     * @param list<string>         $profiles the profiles a rule may name
     * @param list<string>         $sources  the sources a rule may name
     *
     * @throws \InvalidArgumentException naming the first field that is wrong
     */
    public static function ruleFromForm(array $form, string $id, array $profiles, array $sources): AlertRule {
        $text = static fn (string $field): string => \is_scalar($form[$field] ?? null) ? trim((string) $form[$field]) : '';

        $name = $text('name');
        if ($name === '') {
            throw new \InvalidArgumentException('The rule needs a name.');
        }
        $profile = $text('profile');
        if (!\in_array($profile, $profiles, true)) {
            throw new \InvalidArgumentException("Unknown profile: {$profile}.");
        }
        $chosen = array_values(array_unique(array_map(
            static fn (mixed $s): string => \is_scalar($s) ? (string) $s : '',
            \is_array($form['sources'] ?? null) ? $form['sources'] : [],
        )));
        foreach ($chosen as $source) {
            if (!\in_array($source, $sources, true)) {
                throw new \InvalidArgumentException("Unknown source: {$source}.");
            }
        }
        foreach ([
            'metric' => array_keys(AlertsPage::METRICS),
            'operator' => AlertsPage::OPERATORS,
            'thresholdType' => array_keys(AlertsPage::THRESHOLD_TYPES),
            'avgWindow' => array_keys(AlertsPage::WINDOWS),
        ] as $field => $allowed) {
            if (!\in_array($text($field), $allowed, true)) {
                throw new \InvalidArgumentException("Unknown {$field}: {$text($field)}.");
            }
        }
        $threshold = $text('thresholdValue');
        if (!is_numeric($threshold) || !is_finite((float) $threshold) || (float) $threshold < 0) {
            throw new \InvalidArgumentException('The threshold must be a number of 0 or more.');
        }
        $cooldown = $text('cooldownSlots');
        if (preg_match('/^\d+$/', $cooldown) !== 1 || (int) $cooldown > self::MAX_COOLDOWN) {
            throw new \InvalidArgumentException('The cooldown must be a whole number of intervals from 0 to ' . self::MAX_COOLDOWN . '.');
        }
        $webhook = $text('notifyWebhook');
        if ($webhook !== '' && preg_match('#^https?://.#i', $webhook) !== 1) {
            throw new \InvalidArgumentException('The webhook URL must start with http:// or https://.');
        }

        $enabled = $form['enabled'] ?? true;

        return AlertRule::fromArray([
            'id' => $id,
            'name' => $name,
            'enabled' => \is_bool($enabled) ? $enabled : filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            'profile' => $profile,
            'sources' => $chosen,
            'metric' => $text('metric'),
            'operator' => $text('operator'),
            'thresholdType' => $text('thresholdType'),
            'thresholdValue' => (float) $threshold,
            'avgWindow' => $text('avgWindow'),
            'cooldownSlots' => (int) $cooldown,
            'notifyEmail' => $text('notifyEmail'),
            'notifyWebhook' => $webhook,
            'nfdumpFilter' => $text('nfdumpFilter'),
            'emailSubjectTemplate' => \is_string($form['emailSubjectTemplate'] ?? null) ? $form['emailSubjectTemplate'] : '',
            'emailBodyTemplate' => \is_string($form['emailBodyTemplate'] ?? null) ? $form['emailBodyTemplate'] : '',
            'webhookTitleTemplate' => \is_string($form['webhookTitleTemplate'] ?? null) ? $form['webhookTitleTemplate'] : '',
            'webhookMessageTemplate' => \is_string($form['webhookMessageTemplate'] ?? null) ? $form['webhookMessageTemplate'] : '',
        ]);
    }

    /** Puts the row's enabled switch back to the saved state. */
    public static function switchScript(string $id, bool $enabled): string {
        return \sprintf(
            "document.querySelectorAll('#alertRule-%s input[role=\"switch\"]').forEach((s) => { s.checked = %s; })",
            AlertsPage::ruleKey($id),
            $enabled ? 'true' : 'false',
        );
    }

    /** @param list<AlertRule> $rules */
    public static function findRule(array $rules, string $id): ?AlertRule {
        foreach ($rules as $rule) {
            if ($id !== '' && $rule->id === $id) {
                return $rule;
            }
        }

        return null;
    }

    private static function store(UserPreferences $prefs): void {
        $prefs->save(Config::$prefsFile);
        Config::$settings = $prefs->applyTo(Config::$settings);
    }

    private static function resetForm(Context $c): void {
        foreach (AlertsPage::formDefaults() as $field => $default) {
            $c->getSignal('alert_form_' . $field)?->setValue($default);
        }
        $c->getSignal(QueryKit::filterSignal('alert'))?->setValue(QueryKit::FILTER_DEFAULT);
    }

    private static function inputId(Context $c): string {
        $id = $c->input('id');

        return \is_string($id) ? $id : '';
    }

    private static function manager(Via $app): ?AlertManager {
        $manager = $app->globalState('alertManager', null);

        return $manager instanceof AlertManager ? $manager : null;
    }

    private static function broadcast(Via $app, string $scope): void {
        if (!empty($app->getClients())) {
            $app->broadcast($scope);
        }
    }

    /** A toast; the message is set as text, never parsed as markup. */
    private static function toast(Context $c, string $level, string $message, bool $autoDismiss = false): void {
        $json = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $c->execScript("window.showMessage('{$level}', {$json}" . ($autoDismiss ? ', true' : '') . ')');
    }
}
