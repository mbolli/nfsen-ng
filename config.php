<?php
abstract class Config {

    static $cfg;
    static $path;
    static $db;
    private static $initialized = false;

    private function __construct() {}

    public static function initialize() {
        if (self::$initialized === true) return;

        include("settings.php");
        self::$cfg = $nfsen_config;
        self::$path = __DIR__;
        self::$initialized = true;

        // find data source
        if(array_key_exists('host', self::$cfg['db']['akumuli'])) {
            self::$db = new datasources\Akumuli();
        } else {
            self::$db = new datasources\RRD();
        }
    }

}