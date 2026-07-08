<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Save-settings action registration.
 */
final class SettingsActions {
    /** Register the save-settings action. */
    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c) use ($app): void {
            $settingsDefaultView = $c->getSignal('settings_defaultView');
            $settingsGraphDisplay = $c->getSignal('settings_graphDisplay');
            $settingsGraphDatatype = $c->getSignal('settings_graphDatatype');
            $settingsGraphProtocols = $c->getSignal('settings_graphProtocols');
            $settingsFlowLimit = $c->getSignal('settings_flowLimit');
            $settingsStatsOrderBy = $c->getSignal('settings_statsOrderBy');
            $settingsFiltersText = $c->getSignal('settings_filtersText');
            $settingsLogPriority = $c->getSignal('settings_logPriority');
            $displayTz = $c->getSignal('displayTz');
            $settingsDefaultEmailSubjectTemplate = $c->getSignal('settings_defaultEmailSubjectTemplate');
            $settingsDefaultEmailBodyTemplate = $c->getSignal('settings_defaultEmailBodyTemplate');
            $settingsDefaultWebhookTitleTemplate = $c->getSignal('settings_defaultWebhookTitleTemplate');
            $settingsDefaultWebhookMessageTemplate = $c->getSignal('settings_defaultWebhookMessageTemplate');
            \assert(
                $settingsDefaultView !== null
                && $settingsGraphDisplay !== null
                && $settingsGraphDatatype !== null
                && $settingsGraphProtocols !== null
                && $settingsFlowLimit !== null
                && $settingsStatsOrderBy !== null
                && $settingsFiltersText !== null
                && $settingsLogPriority !== null
                && $displayTz !== null
                && $settingsDefaultEmailSubjectTemplate !== null
                && $settingsDefaultEmailBodyTemplate !== null
                && $settingsDefaultWebhookTitleTemplate !== null
                && $settingsDefaultWebhookMessageTemplate !== null
            );

            $rawFilters = array_values(array_filter(
                array_map('trim', explode("\n", $settingsFiltersText->string()))
            ));

            try {
                $existingPrefs = UserPreferences::load(Config::$prefsFile);

                $prefs = UserPreferences::fromArray([
                    'defaultView' => $settingsDefaultView->string(),
                    'defaultGraphDisplay' => $settingsGraphDisplay->string(),
                    'defaultGraphDatatype' => $settingsGraphDatatype->string(),
                    'defaultGraphProtocols' => $settingsGraphProtocols->array(),
                    'defaultFlowLimit' => $settingsFlowLimit->int(),
                    'defaultStatsOrderBy' => $settingsStatsOrderBy->string(),
                    'filters' => $rawFilters,
                    'logPriority' => Settings::logLevelFromString($settingsLogPriority->string()),
                    'alerts' => array_map(fn (AlertRule $r) => $r->toArray(), $existingPrefs !== null ? $existingPrefs->alerts : []),
                    'displayTimezone' => $displayTz->string(),
                    'defaultEmailSubjectTemplate' => $settingsDefaultEmailSubjectTemplate->string(),
                    'defaultEmailBodyTemplate' => $settingsDefaultEmailBodyTemplate->string(),
                    'defaultWebhookTitleTemplate' => $settingsDefaultWebhookTitleTemplate->string(),
                    'defaultWebhookMessageTemplate' => $settingsDefaultWebhookMessageTemplate->string(),
                ]);

                $prefs->save(Config::$prefsFile);
                Config::$settings = $prefs->applyTo(Config::$settings);

                $settingsFiltersText->setValue(implode("\n", $prefs->filters), broadcast: false);
            } catch (\Throwable $e) {
                $errJs = json_encode('Failed to save: ' . $e->getMessage(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $c->execScript("window.showMessage('error', {$errJs})");
            }

            $c->sync();
            if (!empty($app->getClients())) {
                $app->broadcast('settings:saved');
            }
        }, 'save-settings');
    }
}
