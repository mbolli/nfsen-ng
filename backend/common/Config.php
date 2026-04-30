<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\Processor;

abstract class Config {
    public const VERSION = 'v1.0-alpha';

    public static Settings $settings;
    public static string $path;
    public static string $prefsFile;
    public static Datasource $db;
    public static Processor $processorClass;
    private static bool $initialized = false;

    private function __construct() {}

    public static function initialize(bool $initProcessor = false): void {
        global $nfsen_config;
        if (self::$initialized === true) {
            return;
        }

        // Allow custom settings file via environment variable
        $explicitFile = getenv('NFSEN_SETTINGS_FILE');
        $defaultFile = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'settings' . \DIRECTORY_SEPARATOR . 'settings.php';
        $settingsFile = $explicitFile ?: $defaultFile;

        if (!file_exists($settingsFile)) {
            if ($explicitFile !== false && $explicitFile !== '') {
                throw new \Exception('Settings file not found: ' . $settingsFile . '. Check NFSEN_SETTINGS_FILE.');
            }
            // No settings file — use environment variables (standard Docker deployment)
            self::$settings = Settings::fromEnv();
        } else {
            include $settingsFile;
            self::$settings = Settings::fromArray($nfsen_config);

            // Warn when env vars that are only honoured via Settings::fromEnv() are set
            // while a settings file is also present — they will be silently ignored.
            $envOnlyVars = ['NFSEN_SOURCES', 'NFSEN_PORTS', 'NFSEN_FILTERS', 'NFSEN_PROCESSOR'];
            foreach ($envOnlyVars as $var) {
                $val = getenv($var);
                if ($val !== false && $val !== '') {
                    trigger_error(
                        "{$var} is set but a settings file is also loaded ({$settingsFile}) — "
                        . "the env var will be ignored. Remove settings.php or wire {$var} in it.",
                        E_USER_WARNING,
                    );
                }
            }
        }

        self::$path = \dirname(__DIR__);
        self::$initialized = true;

        // Load user preferences (preferences.json) and overlay on base settings.
        // Silently ignored if the file doesn't exist yet.
        self::$prefsFile = getenv('NFSEN_PREFERENCES_FILE')
            ?: self::$path . \DIRECTORY_SEPARATOR . 'settings' . \DIRECTORY_SEPARATOR . 'preferences.json';
        $prefs = UserPreferences::load(self::$prefsFile);
        if ($prefs !== null) {
            // Capture settings.php filter presets before preferences overlay them.
            // Merge: settings.php filters first (deployment defaults), then user-saved
            // filters on top, deduplicated. This ensures the settings tab textarea and
            // the flow/stats filter dropdowns always include the deployment presets.
            $baseFilters = self::$settings->filters;
            self::$settings = $prefs->applyTo(self::$settings);
            $merged = array_values(array_unique(array_merge($baseFilters, self::$settings->filters)));
            self::$settings = self::$settings->withFilters($merged);
        }

        // Validate directory structure for nfcapd files
        self::validateDirectoryStructure();

        // find data source
        $dbClass = self::$settings->datasourceClass();
        if (class_exists($dbClass)) {
            self::$db = new $dbClass();
        } else {
            throw new \Exception('Failed loading class ' . self::$settings->datasourceName . '. The class doesn\'t exist.');
        }

        // find processor
        $processorClass = self::$settings->processorClass();
        if (!class_exists($processorClass)) {
            throw new \Exception('Failed loading class ' . $processorClass . '. The class doesn\'t exist.');
        }

        if (!\in_array(Processor::class, class_implements($processorClass), true)) {
            throw new \Exception('Processor class ' . $processorClass . ' doesn\'t implement ' . Processor::class . '.');
        }

        if ($initProcessor === true) {
            try {
                self::$processorClass = new $processorClass();
            } catch (\Exception $e) {
                throw new \Exception('Failed initializing processor class ' . $processorClass . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Detect available nfdump profiles by scanning the profiles-data directory.
     * Supports flat profiles (profiles-data/live/source/YYYY/...) and
     * nested groups (profiles-data/group/subprofile/source/YYYY/...).
     *
     * Returns a sorted array of profile name strings, e.g. ['live', 'work', 'group/sub'].
     * Falls back to ['live'] (the configured default) if the directory is unreadable or empty.
     *
     * @return string[]
     */
    public static function detectProfiles(): array {
        $profilesData = self::$settings->nfdumpProfilesData;

        if (!is_dir($profilesData)) {
            return [self::$settings->nfdumpProfile];
        }

        $profiles = [];

        foreach (scandir($profilesData) ?: [] as $entry) {
            if ($entry[0] === '.') {
                continue;
            }
            $entryPath = $profilesData . \DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($entryPath)) {
                continue;
            }

            if (self::dirContainsSources($entryPath)) {
                // Flat profile: $entry directly contains source dirs with date hierarchy
                $profiles[] = $entry;
            } else {
                // Possibly a group — check each child as a potential sub-profile
                foreach (scandir($entryPath) ?: [] as $child) {
                    if ($child[0] === '.') {
                        continue;
                    }
                    $childPath = $entryPath . \DIRECTORY_SEPARATOR . $child;
                    if (!is_dir($childPath)) {
                        continue;
                    }
                    if (self::dirContainsSources($childPath)) {
                        $profiles[] = $entry . '/' . $child;
                    }
                }
            }
        }

        if (empty($profiles)) {
            return [self::$settings->nfdumpProfile];
        }

        sort($profiles);

        return $profiles;
    }

    /**
     * Return true if $dirPath contains at least one source directory
     * (a subdirectory that itself contains a 4-digit YYYY subdirectory).
     */
    private static function dirContainsSources(string $dirPath): bool {
        foreach (scandir($dirPath) ?: [] as $child) {
            if ($child[0] === '.') {
                continue;
            }
            $childPath = $dirPath . \DIRECTORY_SEPARATOR . $child;
            if (!is_dir($childPath)) {
                continue;
            }
            // If child contains YYYY directories, it looks like a source directory
            foreach (scandir($childPath) ?: [] as $yyyyCandidate) {
                if (preg_match('/^\d{4}$/', $yyyyCandidate)
                    && is_dir($childPath . \DIRECTORY_SEPARATOR . $yyyyCandidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validate that nfcapd files are organized in the expected directory structure.
     * nfsen-ng requires nfcapd files to be organized as: profiles-data/profile/source/YYYY/MM/DD/nfcapd.*.
     *
     * @throws \Exception if directory structure is invalid
     */
    private static function validateDirectoryStructure(): void {
        $profilesData = self::$settings->nfdumpProfilesData;
        $profile = self::$settings->nfdumpProfile;
        $sources = self::$settings->sources;

        if (empty($profilesData) || empty($sources)) {
            return; // Skip validation if config is incomplete
        }

        $errors = [];

        foreach ($sources as $source) {
            $sourcePath = $profilesData . \DIRECTORY_SEPARATOR . $profile . \DIRECTORY_SEPARATOR . $source;

            // Check if source directory exists
            if (!is_dir($sourcePath)) {
                $errors[] = "Source directory does not exist: {$sourcePath}";

                continue;
            }

            // Check if files are organized in date hierarchy (YYYY/MM/DD)
            // by looking for flat nfcapd files directly in source directory
            $flatFiles = glob($sourcePath . \DIRECTORY_SEPARATOR . 'nfcapd.*');

            if ($flatFiles !== false && \count($flatFiles) > 0) {
                // Filter out symlinks and .current files
                $actualFiles = array_filter($flatFiles, fn ($file) => is_file($file)
                           && !is_link($file)
                           && !preg_match('/\.current\./', $file));

                if (\count($actualFiles) > 0) {
                    $errors[] = "Source '{$source}' has nfcapd files in flat structure at: {$sourcePath}";
                    $errors[] = "  nfsen-ng requires hierarchical structure: {$sourcePath}/YYYY/MM/DD/nfcapd.*";
                    $errors[] = "  Configure nfcapd with: -w {$sourcePath} -S 1";
                    $errors[] = "  Run 'reorganize_nfcapd.sh' script to move existing files into proper structure.";
                }
            }
        }

        if (!empty($errors)) {
            $errorMsg = "Invalid nfcapd directory structure detected:\n\n" . implode("\n", $errors);
            $errorMsg .= "\n\nFor more information, see INSTALL.md";

            throw new \Exception($errorMsg);
        }
    }
}
