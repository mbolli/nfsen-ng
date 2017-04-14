<?php
abstract class Config {

    static $cfg;
    private static $initialized = false;

    private function __construct() {}

    public static function initialize() {
        if (self::$initialized === true) return;

        include("settings.php");
        self::$cfg = $nfsen_config;
        self::$initialized = true;
    }

}