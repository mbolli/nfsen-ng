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
            $settingsMessage = $c->getSignal('settings_message');
            \assert(
                $settingsDefaultView !== null
                && $settingsGraphDisplay !== null
                && $settingsGraphDatatype !== null
                && $settingsGraphProtocols !== null
                && $settingsFlowLimit !== null
                && $settingsStatsOrderBy !== null
                && $settingsFiltersText !== null
                && $settingsLogPriority !== null
                && $settingsMessage !== null
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
                ]);

                $prefs->save(Config::$prefsFile);
                Config::$settings = $prefs->applyTo(Config::$settings);

                $settingsFiltersText->setValue(implode("\n", $prefs->filters), broadcast: false);
            } catch (\Throwable $e) {
                $settingsMessage->setValue(
                    Helpers::makeToast('error', 'Failed to save: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES)),
                    broadcast: false
                );
            }

            $c->sync();
            $settingsMessage->setValue('', broadcast: false);
            if (!empty($app->getClients())) {
                $app->broadcast('settings:saved');
            }
        }, 'save-settings');
    }
}
