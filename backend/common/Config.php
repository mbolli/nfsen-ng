<?php

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\datasources\Datasource;
use mbolli\nfsen_ng\processor\Processor;

abstract class Config {
    public const VERSION = 'v0.4';

    /**
     * @var array{
     *     general: array{ports: int[], sources: string[], db: string, processor?: string, formats?: array<string>, filters?: array<string>},
     *     frontend: array{reload_interval: int, defaults: array<string, array>},
     *     nfdump: array{binary: string, profiles-data: string, profile: string, max-processes: int},
     *     db: array<string, array>,
     *     log: array{priority: int}
     *     }|array{}
     */
    public static array $cfg = [];
    public static string $path;
    public static Datasource $db;
    public static Processor $processorClass;
    private static bool $initialized = false;

    private function __construct() {}

    public static function initialize(bool $initProcessor = false): void {
        global $nfsen_config;
        if (self::$initialized === true) {
            return;
        }

        $settingsFile = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'settings' . \DIRECTORY_SEPARATOR . 'settings.php';
        if (!file_exists($settingsFile)) {
            throw new \Exception('No settings.php found. Did you rename the distributed settings correctly?');
        }

        include $settingsFile;

        self::$cfg = $nfsen_config;
        self::$path = \dirname(__DIR__);
        self::$initialized = true;

        // Validate directory structure for nfcapd files
        self::validateDirectoryStructure();

        // find data source
        $dbClass = 'mbolli\\nfsen_ng\\datasources\\' . ucfirst(strtolower(self::$cfg['general']['db']));
        if (class_exists($dbClass)) {
            self::$db = new $dbClass();
        } else {
            throw new \Exception('Failed loading class ' . self::$cfg['general']['db'] . '. The class doesn\'t exist.');
        }

        // find processor
        $processorClass = \array_key_exists('processor', self::$cfg['general']) ? ucfirst(strtolower(self::$cfg['general']['processor'])) : 'Nfdump';
        $processorClass = 'mbolli\\nfsen_ng\\processor\\' . $processorClass;
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
     * Validate that nfcapd files are organized in the expected directory structure.
     * nfsen-ng requires nfcapd files to be organized as: profiles-data/profile/source/YYYY/MM/DD/nfcapd.*.
     *
     * @throws \Exception if directory structure is invalid
     */
    private static function validateDirectoryStructure(): void {
        $profilesData = self::$cfg['nfdump']['profiles-data'] ?? null;
        $profile = self::$cfg['nfdump']['profile'] ?? 'live';
        $sources = self::$cfg['general']['sources'] ?? [];

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
