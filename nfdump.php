<?php

class NfDump {
    private $cfg = array(
        'env' => array(),
        'options' => array(),
        'filter' => array()
    );
    private $clean = array();
    private $d;
    public static $_instance;

    function __construct() {
        $this->d = \Debug::getInstance();
        $this->clean = $this->cfg;
        $this->reset();
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function setOption($option, $value) {
        switch($option) {
            case '-M':
                $this->cfg['option'][$option] = $this->cfg['env']['profiles-data'] . "/" . $this->cfg['env']['profile'] . "/" . $value;
                break;
            default:
                $this->cfg['option'][$option] = $value;
                break;
        }
    }

    public function setFilter($filter, $value) {
        $this->cfg['filter'][$filter] = $value;
    }


    public function execute() {
        $output = array();
        $return = "";
        $command = $this->cfg['env']['bin'] . " " . $this->flatten($this->cfg['option']) . $this->flatten($this->cfg['filter']);
        exec($command, $output, $return);

        switch($return) {
            case 127: throw new Exception("Failed to start process. Is nfdump installed?"); break;
            case 255: throw new Exception("Initialization failed."); break;
            case 254: throw new Exception("Error in filter syntax."); break;
            case 250: throw new Exception("Internal error."); break;
        }
        return $output;
    }

    private function flatten($array) {
        if(!is_array($array)) return false;
        $output = "";

        foreach($array as $key => $value) {
            $output .= $key . " " . $value . " ";
        }
        return $output;
    }

    public function reset() {
        $this->clean['env'] = array(
            'bin' => \Config::$cfg['nfdump']['binary'],
            'profiles-data' => \Config::$cfg['nfdump']['profiles-data'],
            'profile' => \Config::$cfg['nfdump']['profile'],
        );
        $this->cfg = $this->clean;
    }
}