<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\SettingsActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Settings (was Settings > Preferences and System): instance preferences and the deployment (4.8). */
final class SettingsPage implements Page {
    public static function id(): string {
        return 'settings';
    }

    public static function title(): string {
        return 'Settings';
    }

    public static function lede(): string {
        return 'Preferences for this instance, and how it is deployed.';
    }

    public static function icon(): string {
        return 'gear';
    }

    public static function group(): string {
        return 'system';
    }

    public static function signals(Context $c): void {
        $settings = Config::$settings;

        // The old form lists view ids, so it gets the legacy id of the default page.
        $c->signal(PageRegistry::toLegacy($settings->defaultView), 'settings_defaultView', clientWritable: true);
        $c->signal($settings->defaultGraphDisplay, 'settings_graphDisplay', clientWritable: true);
        // The old form still offers the legacy 'bytes' datatype, so a traffic default in bytes shows as that.
        $c->signal(
            $settings->defaultGraphDatatype === 'traffic' && $settings->defaultUnit === 'bytes' ? 'bytes' : $settings->defaultGraphDatatype,
            'settings_graphDatatype',
            clientWritable: true
        );
        $c->signal($settings->defaultGraphProtocols, 'settings_graphProtocols', clientWritable: true);
        $c->signal($settings->defaultFlowLimit, 'settings_flowLimit', clientWritable: true);
        $c->signal($settings->defaultStatsOrderBy, 'settings_statsOrderBy', clientWritable: true);
        // One filter per line, parsed on save.
        $c->signal(implode("\n", $settings->filters), 'settings_filtersText', clientWritable: true);
        $c->signal(Settings::logLevelToString($settings->logPriority), 'settings_logPriority', clientWritable: true);
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        SettingsActions::register($c, $app);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        $settings = Config::$settings;

        return [
            Shell::LEGACY => [
                // The deployment, shown read-only.
                'deployDatasource' => $settings->datasourceName,
                'deployImportYears' => $settings->importYears,
                'deployDefaultTheme' => $settings->deploymentTheme,
                'deployNfdumpBinary' => $settings->nfdumpBinary,
                'deployNfdumpProfiles' => $settings->nfdumpProfilesData,
                'deployPrefsFile' => Config::$prefsFile,
            ],
        ];
    }
}
