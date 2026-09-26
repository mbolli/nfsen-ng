<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\pages\SettingsPage;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/** The save-settings action of the Settings page (4.8.2). */
final class SettingsActions {
    /** Preference field => the signal the form posts it in. Nothing else is written by a save. */
    public const array FIELDS = [
        'defaultView' => 'settings_defaultView',
        'defaultRange' => 'settings_defaultRange',
        'defaultUnit' => 'settings_defaultUnit',
        'theme' => 'settings_theme',
        'compactTables' => 'settings_compactTables',
        'displayTimezone' => 'displayTz',
        'defaultGraphDisplay' => 'settings_graphDisplay',
        'defaultGraphDatatype' => 'settings_graphDatatype',
        'defaultGraphProtocols' => 'settings_graphProtocols',
        'defaultFlowLimit' => 'settings_flowLimit',
        'defaultStatsOrderBy' => 'settings_statsOrderBy',
        'logPriority' => 'settings_logPriority',
        'rdnsEnabled' => 'settings_rdnsEnabled',
    ];

    private const int DEFAULT_FLOW_LIMIT = 50;

    /** Whether a save is between reading preferences.json and applying what it wrote. */
    private static bool $writing = false;

    public static function register(Context $c, Via $app): void {
        $c->action(static function (Context $c) use ($app): void {
            try {
                $scope = $c->input('scope');
                $error = self::save($c, $app, self::fieldsFor(\is_string($scope) ? $scope : ''));
                $c->execScript($error === ''
                    ? "window.showMessage('success', 'Settings saved.', true)"
                    : 'window.showMessage(\'error\', ' . self::js('Settings could not be saved: ' . $error) . ')');
                $c->sync();
                if ($error === '' && $app->getClients() !== []) {
                    $app->broadcast('settings:saved');
                }
            } catch (\Throwable $e) {
                Debug::getInstance()->log('save-settings: ' . $e->getMessage(), LOG_ERR);
            }
        }, 'save-settings');
    }

    /**
     * The fields a Save writes, by the form it came from: 'general' is the General tab, 'rdns'
     * the reverse DNS switch. Without a scope a save writes both.
     *
     * @return array<string, string> preference field => signal
     */
    public static function fieldsFor(string $scope): array {
        return match ($scope) {
            'general' => array_diff_key(self::FIELDS, ['rdnsEnabled' => true]),
            'rdns' => array_intersect_key(self::FIELDS, ['rdnsEnabled' => true]),
            default => self::FIELDS,
        };
    }

    /**
     * The preferences a save writes: the saved ones with the posted fields replaced (3.9).
     * Filters, alert rules, their templates and the selected profile stay as saved; a field
     * that was not posted keeps its saved value.
     *
     * @param array<string, mixed> $existing UserPreferences::toArray() of the saved file, [] when there is none
     * @param array<string, mixed> $input    posted values by preference field; other keys are ignored
     */
    public static function buildPreferences(array $existing, array $input): UserPreferences {
        $fields = [];
        foreach (array_keys(self::FIELDS) as $field) {
            if (($input[$field] ?? null) !== null) {
                $fields[$field] = self::normalize($field, $input[$field]);
            }
        }

        return UserPreferences::fromArray(array_merge($existing, $fields));
    }

    /**
     * The saved preferences a save starts from. Without a file, the ones in effect, so the
     * alert rules and templates survive; a file that cannot be read is not replaced.
     *
     * @return array<string, mixed>
     */
    public static function existingPreferences(string $file, Settings $settings): array {
        $saved = UserPreferences::load($file);
        if ($saved === null && file_exists($file)) {
            throw new \RuntimeException("{$file} could not be read. Fix or remove it, then save again.");
        }

        return ($saved ?? AlertActions::preferencesFromSettings($settings))->toArray();
    }

    /**
     * Runs a read-modify-write of preferences.json alone in this worker: it yields on the
     * file, and one that read the file before another's write would undo that write.
     *
     * @template T
     *
     * @param \Closure(): T $write
     *
     * @return T
     */
    public static function exclusively(\Closure $write): mixed {
        while (self::$writing && Coroutine::getCid() > 0) {
            Coroutine::usleep(1000);
        }
        self::$writing = true;

        try {
            return $write();
        } finally {
            self::$writing = false;
        }
    }

    /**
     * Writes the posted preferences and applies them; '' on success, else the reason.
     *
     * @param array<string, string> $fields preference field => signal, from fieldsFor()
     */
    private static function save(Context $c, Via $app, array $fields): string {
        $input = [];
        foreach ($fields as $field => $signal) {
            $input[$field] = $c->getSignal($signal)?->getValue();
        }

        try {
            $prefs = self::exclusively(static function () use ($input): UserPreferences {
                $prefs = self::buildPreferences(self::existingPreferences(Config::$prefsFile, Config::$settings), $input);
                $prefs->save(Config::$prefsFile);
                // A save does not edit the filter presets: keep the list Config merged at boot.
                Config::$settings = $prefs->applyTo(Config::$settings)->withFilters(Config::$settings->filters);

                return $prefs;
            });
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        // The saved fields show what was written, normalised; the other form keeps its edits.
        $values = SettingsPage::formValues(Config::$settings, $prefs->theme);
        foreach ($fields as $signal) {
            $c->getSignal($signal)?->setValue($values[$signal], broadcast: false);
        }
        SettingsPage::publish($c, $app, $values);

        return '';
    }

    private static function normalize(string $field, mixed $value): mixed {
        return match ($field) {
            'defaultView' => Settings::normalizeView($value),
            'defaultRange' => Settings::normalizeRange($value),
            'defaultUnit' => Settings::normalizeUnit($value),
            'theme' => UserPreferences::normalizeTheme($value),
            'compactTables', 'rdnsEnabled' => self::bool($value),
            'displayTimezone' => self::oneOf($value, SettingsPage::TIMEZONES, 'browser'),
            'defaultGraphDisplay' => self::oneOf($value, SettingsPage::GRAPH_DISPLAYS, 'sources'),
            'defaultGraphDatatype' => Settings::normalizeGraphDatatype($value),
            // A single choice, stored as the one-element list the preference has always been.
            'defaultGraphProtocols' => [self::oneOf(\is_array($value) ? reset($value) : $value, SettingsPage::PROTOCOLS, 'any')],
            'defaultFlowLimit' => is_numeric($value)
                ? max(SettingsPage::FLOW_LIMIT_MIN, min(SettingsPage::FLOW_LIMIT_MAX, (int) $value))
                : self::DEFAULT_FLOW_LIMIT,
            'defaultStatsOrderBy' => self::oneOf($value, SettingsPage::ORDER_BY, 'bytes'),
            'logPriority' => \is_int($value) && $value >= LOG_EMERG && $value <= LOG_DEBUG
                ? $value
                : Settings::logLevelFromString(\is_scalar($value) ? (string) $value : ''),
            default => $value,
        };
    }

    /** @param array<string, string> $choices value => label */
    private static function oneOf(mixed $value, array $choices, string $default): string {
        $v = \is_string($value) ? strtolower(trim($value)) : '';

        return isset($choices[$v]) ? $v : $default;
    }

    private static function bool(mixed $value): bool {
        return \is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function js(string $text): string {
        return json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
