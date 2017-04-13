<?php
class Config {

    private $cfg = array();
    public static $_instance;

    function __construct() {
        $array = parse_ini_file(getcwd() . DIRECTORY_SEPARATOR . "settings.ini", true, INI_SCANNER_TYPED);

        if ($array !== false) {
            $this->cfg = $array;
        } else {
            throw new Exception("Could not read or parse settings.ini");
        }
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function get(string $key) {
        list($group, $name) = explode(".", $key);

        if (array_key_exists($group, $this->cfg) && !empty($this->cfg[$group])) {
            if (array_key_exists($name, $this->cfg[$group])) {

                if(preg_match('/_list$/', $name)) {
                    // split lists into array
                    return explode("|", $this->cfg[$group][$name]);
                } else {
                    return $this->cfg[$group][$name];
                }
            }
        }
        return false;
    }

    public function getAll() {
        return $this->cfg;
    }
}