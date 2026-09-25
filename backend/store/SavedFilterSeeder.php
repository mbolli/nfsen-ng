<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\store;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\UserPreferences;

/**
 * Brings the filter presets into the saved filters: the deployment presets (NFSEN_FILTERS or
 * settings.php) on every boot, each one once, and the preferences.json presets one time only.
 */
final class SavedFilterSeeder {
    public const string META_PREFERENCES_MIGRATED = 'migrated.preference_filters';

    /** JSON list of the deployment preset keys already imported, deleted ones included. */
    public const string META_DEPLOYMENT_SEEN = 'deployment_presets.seen';

    public static function seed(Database $db): void {
        // Read preferences.json before any transaction: file reads yield.
        $preferenceFilters = $db->metaGet(self::META_PREFERENCES_MIGRATED) === null ? self::preferenceFilters() : null;
        $repository = new SavedFilterRepository($db);

        // Deployment first: the old Preferences form saved the merged list, so preferences.json
        // can hold deployment presets too, and they should keep their deployment origin.
        self::seedDeployment($db, $repository, Config::$deploymentFilters);

        if ($preferenceFilters !== null) {
            $db->transaction(static function (Database $db) use ($repository, $preferenceFilters): void {
                // A seen deployment preset is in the table or was deleted; a retried migration must not revive it.
                $seen = self::seenDeploymentKeys($db);
                $own = array_values(array_filter(
                    $preferenceFilters,
                    static fn (string $expression): bool => !\in_array(SavedFilterRepository::key($expression), $seen, true),
                ));
                $repository->import(self::items($own), 'preference');
                $db->metaSet(self::META_PREFERENCES_MIGRATED, '1');
            });
        }
    }

    /**
     * @return list<string>
     */
    public static function seenDeploymentKeys(Database $db): array {
        $seen = json_decode($db->metaGet(self::META_DEPLOYMENT_SEEN) ?? '[]', true);

        return \is_array($seen) ? array_values(array_filter($seen, 'is_string')) : [];
    }

    /**
     * @param list<string> $expressions
     */
    private static function seedDeployment(Database $db, SavedFilterRepository $repository, array $expressions): void {
        $seen = self::seenDeploymentKeys($db);
        $keys = [];
        $new = [];
        foreach ($expressions as $expression) {
            $key = SavedFilterRepository::key($expression);
            if ($key === '' || \in_array($key, $keys, true)) {
                continue;
            }
            $keys[] = $key;
            if (!\in_array($key, $seen, true)) {
                $new[] = $expression;
            }
        }
        if ($new === []) {
            return;
        }

        $db->transaction(static function (Database $db) use ($repository, $new, $seen): void {
            $repository->import(self::items($new), 'deployment');
            $db->metaSet(
                self::META_DEPLOYMENT_SEEN,
                json_encode([...$seen, ...array_map(SavedFilterRepository::key(...), $new)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
        });
    }

    /**
     * Null when preferences.json exists but cannot be read, so the migration is retried later.
     *
     * @return null|list<string>
     */
    private static function preferenceFilters(): ?array {
        if (!isset(Config::$prefsFile) || Config::$prefsFile === '' || !file_exists(Config::$prefsFile)) {
            return [];
        }

        $preferences = UserPreferences::load(Config::$prefsFile);
        if ($preferences === null) {
            Debug::getInstance()->log('Filter presets in ' . Config::$prefsFile . ' not imported yet: the file cannot be read.', LOG_WARNING);

            return null;
        }

        return $preferences->filters;
    }

    /**
     * @param list<string> $expressions
     *
     * @return list<array{name: string, expression: string}>
     */
    private static function items(array $expressions): array {
        return array_map(
            static fn (string $expression): array => ['name' => SavedFilterRepository::defaultName($expression), 'expression' => $expression],
            $expressions,
        );
    }
}
