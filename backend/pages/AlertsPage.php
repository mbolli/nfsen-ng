<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\AlertActions;
use mbolli\nfsen_ng\common\AlertManager;
use mbolli\nfsen_ng\common\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Alerts (was Settings > Alerts): rules, their recent events and the notification templates (4.6). */
final class AlertsPage implements Page {
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
        // The rule form: create a rule, or edit the one alert_form_id names.
        $c->signal('', 'alert_form_id', clientWritable: true);
        $c->signal('', 'alert_form_name', clientWritable: true);
        $c->signal(true, 'alert_form_enabled', clientWritable: true);
        $c->signal(Config::$settings->nfdumpProfile, 'alert_form_profile', clientWritable: true);
        $c->signal(Config::$settings->sources, 'alert_form_sources', clientWritable: true);
        $c->signal('bytes', 'alert_form_metric', clientWritable: true);
        $c->signal('>', 'alert_form_operator', clientWritable: true);
        $c->signal('absolute', 'alert_form_thresholdType', clientWritable: true);
        $c->signal(0, 'alert_form_thresholdValue', clientWritable: true);
        $c->signal('1h', 'alert_form_avgWindow', clientWritable: true);
        $c->signal(3, 'alert_form_cooldownSlots', clientWritable: true);
        $c->signal('', 'alert_form_notifyEmail', clientWritable: true);
        $c->signal('', 'alert_form_emailSubjectTemplate', clientWritable: true);
        $c->signal('', 'alert_form_emailBodyTemplate', clientWritable: true);
        $c->signal('', 'alert_form_notifyWebhook', clientWritable: true);
        $c->signal('', 'alert_form_webhookTitleTemplate', clientWritable: true);
        $c->signal('', 'alert_form_webhookMessageTemplate', clientWritable: true);
        $c->signal('', 'alert_form_nfdumpFilter', clientWritable: true);

        // Global notification templates; empty falls back to AlertManager's built-ins, and
        // a rule's own template overrides them in turn.
        $c->signal(Config::$settings->defaultEmailSubjectTemplate, 'settings_defaultEmailSubjectTemplate', clientWritable: true);
        $c->signal(Config::$settings->defaultEmailBodyTemplate, 'settings_defaultEmailBodyTemplate', clientWritable: true);
        $c->signal(Config::$settings->defaultWebhookTitleTemplate, 'settings_defaultWebhookTitleTemplate', clientWritable: true);
        $c->signal(Config::$settings->defaultWebhookMessageTemplate, 'settings_defaultWebhookMessageTemplate', clientWritable: true);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        AlertActions::register($c, $app);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $manager = $app->globalState('alertManager', null);
        $settings = Config::$settings;

        return [
            Shell::LEGACY => [
                'alerts' => $settings->alerts,
                'alertLog' => $manager instanceof AlertManager ? $manager->getRecentLog(10) : [],
                'alertEmailEnabled' => $settings->alertEmailFrom !== '',
                // The bottom of the fallback chain, shown in the global templates' placeholders.
                'alertBuiltinEmailSubject' => AlertManager::DEFAULT_EMAIL_SUBJECT,
                'alertBuiltinEmailBody' => AlertManager::DEFAULT_EMAIL_BODY,
                'alertBuiltinWebhookTitle' => AlertManager::DEFAULT_WEBHOOK_TITLE,
                'alertBuiltinWebhookMessage' => AlertManager::DEFAULT_WEBHOOK_MESSAGE,
                // What a rule inherits, shown in the placeholders of its override fields.
                'alertEffectiveEmailSubject' => AlertManager::resolveTemplate(null, $settings->defaultEmailSubjectTemplate, AlertManager::DEFAULT_EMAIL_SUBJECT),
                'alertEffectiveEmailBody' => AlertManager::resolveTemplate(null, $settings->defaultEmailBodyTemplate, AlertManager::DEFAULT_EMAIL_BODY),
                'alertEffectiveWebhookTitle' => AlertManager::resolveTemplate(null, $settings->defaultWebhookTitleTemplate, AlertManager::DEFAULT_WEBHOOK_TITLE),
                'alertEffectiveWebhookMessage' => AlertManager::resolveTemplate(null, $settings->defaultWebhookMessageTemplate, AlertManager::DEFAULT_WEBHOOK_MESSAGE),
            ],
        ];
    }
}
