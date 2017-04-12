<?php
class Config {

    private $cfg = array();
    public static $_instance;

    function __construct() {
        $array = parse_ini_file(getcwd() . DIRECTORY_SEPARATOR . "settings.ini", true, INI_SCANNER_TYPED);
        if ($array !== false) {
            $this->cfg = $array;
        }
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function get(string $key) {
        $identifiers = explode(".", $key);

        if (array_key_exists($identifiers[0], $this->cfg) && !empty($this->cfg[$identifiers[0]])) {
            if (array_key_exists($identifiers[1], $this->cfg[$identifiers[0]]))
                return $this->cfg[$key];
        } else {

        }
        return false;
    }

    public function set(string $key, $value) {

        // todo write back to file?
        $this->cfg[$key] = $value;
    }

    public function getAll() {
        return $this->cfg;
    }
}