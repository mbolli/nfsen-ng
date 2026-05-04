<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\UserPreferences;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Alert CRUD action registrations: save-alert, delete-alert, toggle-alert, test-alert.
 */
final class AlertActions {
    /** Register all alert management actions. */
    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c): void {
            $alertFormId = $c->getSignal('alert_form_id');
            $alertFormName = $c->getSignal('alert_form_name');
            $alertFormEnabled = $c->getSignal('alert_form_enabled');
            $alertFormProfile = $c->getSignal('alert_form_profile');
            $alertFormSources = $c->getSignal('alert_form_sources');
            $alertFormMetric = $c->getSignal('alert_form_metric');
            $alertFormOperator = $c->getSignal('alert_form_operator');
            $alertFormThresholdType = $c->getSignal('alert_form_thresholdType');
            $alertFormThresholdValue = $c->getSignal('alert_form_thresholdValue');
            $alertFormAvgWindow = $c->getSignal('alert_form_avgWindow');
            $alertFormCooldownSlots = $c->getSignal('alert_form_cooldownSlots');
            $alertFormNotifyEmail = $c->getSignal('alert_form_notifyEmail');
            $alertFormNotifyWebhook = $c->getSignal('alert_form_notifyWebhook');
            $settingsMessage = $c->getSignal('settings_message');
            \assert(
                $alertFormId !== null
                && $alertFormName !== null
                && $alertFormEnabled !== null
                && $alertFormProfile !== null
                && $alertFormSources !== null
                && $alertFormMetric !== null
                && $alertFormOperator !== null
                && $alertFormThresholdType !== null
                && $alertFormThresholdValue !== null
                && $alertFormAvgWindow !== null
                && $alertFormCooldownSlots !== null
                && $alertFormNotifyEmail !== null
                && $alertFormNotifyWebhook !== null
                && $settingsMessage !== null
            );
            $id = trim($alertFormId->string());

            try {
                $rule = AlertRule::fromArray([
                    'id' => $id !== '' ? $id : bin2hex(random_bytes(16)),
                    'name' => $alertFormName->string(),
                    'enabled' => $alertFormEnabled->bool(),
                    'profile' => $alertFormProfile->string(),
                    'sources' => $alertFormSources->array(),
                    'metric' => $alertFormMetric->string(),
                    'operator' => $alertFormOperator->string(),
                    'thresholdType' => $alertFormThresholdType->string(),
                    'thresholdValue' => (float) $alertFormThresholdValue->string(),
                    'avgWindow' => $alertFormAvgWindow->string(),
                    'cooldownSlots' => $alertFormCooldownSlots->int(),
                    'notifyEmail' => $alertFormNotifyEmail->string(),
                    'notifyWebhook' => $alertFormNotifyWebhook->string(),
                ]);

                $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
                $found = false;
                $updatedAlerts = array_map(function (AlertRule $r) use ($rule, &$found) {
                    if ($r->id === $rule->id) {
                        $found = true;

                        return $rule;
                    }

                    return $r;
                }, $prefs->alerts);
                if (!$found) {
                    $updatedAlerts[] = $rule;
                }
                $newPrefs = UserPreferences::fromArray(
                    array_merge($prefs->toArray(), ['alerts' => array_map(fn (AlertRule $r) => $r->toArray(), $updatedAlerts)])
                );
                $newPrefs->save(Config::$prefsFile);
                Config::$settings = $newPrefs->applyTo(Config::$settings);

                $alertFormId->setValue('', broadcast: false);
                $alertFormName->setValue('', broadcast: false);
            } catch (\Throwable $e) {
                $settingsMessage->setValue(
                    Helpers::makeToast('error', 'Save failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
                    broadcast: false
                );
            }
            $c->sync();
            $settingsMessage->setValue('', broadcast: false);
        }, 'save-alert');

        $c->action(static function (Context $c): void {
            $settingsMessage = $c->getSignal('settings_message');
            \assert($settingsMessage !== null);
            $id = $c->input('id') ?? '';
            if ($id === '') {
                return;
            }

            try {
                $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
                $updatedAlerts = array_values(array_filter($prefs->alerts, fn (AlertRule $r) => $r->id !== $id));
                $newPrefs = UserPreferences::fromArray(
                    array_merge($prefs->toArray(), ['alerts' => array_map(fn (AlertRule $r) => $r->toArray(), $updatedAlerts)])
                );
                $newPrefs->save(Config::$prefsFile);
                Config::$settings = $newPrefs->applyTo(Config::$settings);
            } catch (\Throwable $e) {
                $settingsMessage->setValue(
                    Helpers::makeToast('error', 'Delete failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
                    broadcast: false
                );
            }
            $c->sync();
            $settingsMessage->setValue('', broadcast: false);
        }, 'delete-alert');

        $c->action(static function (Context $c): void {
            $settingsMessage = $c->getSignal('settings_message');
            \assert($settingsMessage !== null);
            $id = $c->input('id') ?? '';
            if ($id === '') {
                return;
            }

            try {
                $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
                $updatedAlerts = array_map(
                    fn (AlertRule $r) => $r->id === $id ? $r->withEnabled(!$r->enabled) : $r,
                    $prefs->alerts
                );
                $newPrefs = UserPreferences::fromArray(
                    array_merge($prefs->toArray(), ['alerts' => array_map(fn (AlertRule $r) => $r->toArray(), $updatedAlerts)])
                );
                $newPrefs->save(Config::$prefsFile);
                Config::$settings = $newPrefs->applyTo(Config::$settings);
            } catch (\Throwable $e) {
                $settingsMessage->setValue(
                    Helpers::makeToast('error', 'Toggle failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
                    broadcast: false
                );
            }
            $c->sync();
            $settingsMessage->setValue('', broadcast: false);
        }, 'toggle-alert');

        $c->action(static function (Context $c) use ($app): void {
            $id = $c->input('id') ?? '';
            if ($id === '') {
                return;
            }

            $prefs = UserPreferences::load(Config::$prefsFile) ?? UserPreferences::fromArray([]);
            $rule = null;
            foreach ($prefs->alerts as $r) {
                if ($r->id === $id) {
                    $rule = $r;

                    break;
                }
            }

            if ($rule === null) {
                $c->execScript("window.showMessage('error', 'Rule not found.')");

                return;
            }

            /** @var null|AlertManager $alertMgr */
            $alertMgr = $app->globalState('alertManager', null);
            if ($alertMgr === null) {
                $c->execScript("window.showMessage('error', 'AlertManager not running (NFSEN_SKIP_DAEMON set?).', true)");

                return;
            }

            try {
                $current = Config::$db->fetchLatestSlot($rule->sources, $rule->profile);
                $threshold = $alertMgr->computeThreshold($rule, $current);
                $value = $current[$rule->metric] ?? 0.0;

                $thresholdDisplay = $threshold === PHP_FLOAT_MAX
                    ? '∞ (no baseline yet)'
                    : number_format($threshold, 2);

                $conditionStr = $rule->metric . ' ' . $rule->operator . ' ' . $thresholdDisplay;
                $fired = match ($rule->operator) {
                    '>' => $value > $threshold,
                    '>=' => $value >= $threshold,
                    '<' => $value < $threshold,
                    '<=' => $value <= $threshold,
                    default => false,
                };

                $resultLabel = $fired ? '✅ Would FIRE' : '⬜ Would NOT fire';
                $msg = "{$resultLabel} — {$rule->name}: current {$rule->metric} = " . number_format($value, 2) . ", threshold ({$conditionStr})";

                if ($fired) {
                    $alertMgr->dispatchNotifications($rule, $current, time());
                    $msg .= '. Notifications dispatched.';
                }

                $type = $fired ? 'warning' : 'success';
                $msgJs = json_encode($msg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $c->execScript("window.showMessage('{$type}', {$msgJs}, true)");
            } catch (\Throwable $e) {
                $errJs = json_encode('Test failed: ' . $e->getMessage(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $c->execScript("window.showMessage('error', {$errJs})");
            }

            $c->sync();
        }, 'test-alert');
    }
}
