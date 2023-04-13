<?php

namespace nfsen_ng\common;

abstract class config {
    public static $cfg;
    public static $path;
    /**
     * @var \nfsen_ng\datasources\Datasource
     */
    public static $db;
    /**
     * @var \nfsen_ng\processor\Processor
     */
    public static $processorClass;
    private static $initialized = false;

    private function __construct() {
    }

    public static function initialize(): void {
        if (self::$initialized === true) {
            return;
        }

        $settings_file = \dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'settings' . \DIRECTORY_SEPARATOR . 'settings.php';
        if (!file_exists($settings_file)) {
            throw new \Exception('No settings.php found. Did you rename the distributed settings correctly?');
        }
        include $settings_file;
        self::$cfg = $nfsen_config;
        self::$path = \dirname(__DIR__);
        self::$initialized = true;

        // find data source
        $db_class = 'nfsen_ng\\datasources\\' . self::$cfg['general']['db'];
        if (class_exists($db_class)) {
            self::$db = new $db_class();
        } else {
            throw new \Exception('Failed loading class ' . self::$cfg['general']['db'] . '. The class doesn\'t exist.');
        }

        // find processor
        $proc_class = \array_key_exists('processor', self::$cfg['general']) ? self::$cfg['general']['processor'] : 'NfDump';
        self::$processorClass = 'nfsen_ng\\processor\\' . $proc_class;
        if (!class_exists(self::$processorClass)) {
            throw new \Exception('Failed loading class ' . self::$processorClass . '. The class doesn\'t exist.');
        }

        $proc_iface = 'nfsen_ng\\processor\\Processor';
        if (!\in_array($proc_iface, class_implements(self::$processorClass), true)) {
            throw new \Exception('Processor class ' . self::$processorClass . ' doesn\'t implement ' . $proc_iface . '.');
        }
    }
}
