<?php
namespace nfsen_ng\common;

abstract class Config {

    static $cfg;
    static $path;
	/**
	 * @var \datasources\Datasource
	 */
    static $db;
    private static $initialized = false;

    private function __construct() {}

    public static function initialize() {
        if (self::$initialized === true) return;

        $settings_file = getcwd() . DIRECTORY_SEPARATOR . 'settings' . DIRECTORY_SEPARATOR . 'settings.php';
        if (!file_exists($settings_file)) throw new \Exception('No settings.php found. Did you rename the distributed settings correctly?');
        include $settings_file;
        self::$cfg = $nfsen_config;
        self::$path = getcwd();
        self::$initialized = true;

        // find data source
        $db_class = 'nfsen_ng\\datasources\\' . self::$cfg['general']['db'];
        if (class_exists($db_class)) {
            self::$db = new $db_class();
        } else {
            throw new \Exception('Failed loading class ' . self::$cfg['general']['db'] . '. The class doesn\'t exist.');
        }
    }

}