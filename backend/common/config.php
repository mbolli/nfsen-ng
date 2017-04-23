<?php
namespace common;

abstract class Config {

    static $cfg;
    static $path;
    static $db;
    private static $initialized = false;

    private function __construct() {}

    public static function initialize() {
        if (self::$initialized === true) return;

        $settings_file = getcwd() . DIRECTORY_SEPARATOR . "settings" . DIRECTORY_SEPARATOR . "settings.php";
        if (!file_exists($settings_file)) throw new \Exception('No settings.php found.');
        include($settings_file);
        self::$cfg = $nfsen_config;
        self::$path = __DIR__;
        self::$initialized = true;

        // find data source
        if(array_key_exists('host', self::$cfg['db']['akumuli'])) {
            self::$db = new \datasources\Akumuli();
        } else {
            self::$db = new \datasources\RRD();
        }
    }

}