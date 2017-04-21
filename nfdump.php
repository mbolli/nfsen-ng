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

    /**
     * Sets an option's value
     * @param $option
     * @param $value
     */
    public function setOption($option, $value) {
        switch($option) {
            case '-M':
                $this->cfg['option'][$option] = $this->cfg['env']['profiles-data'] . DIRECTORY_SEPARATOR . $this->cfg['env']['profile'] . DIRECTORY_SEPARATOR . $value;
                break;
            case '-R':
                $this->cfg['option'][$option] = $this->convert_date_to_path($value[0], $value[1]);
                break;
            default:
                $this->cfg['option'][$option] = $value;
                break;
        }
    }

    /**
     * Sets a filter's value
     * @param $filter
     */
    public function setFilter($filter) {
        $this->cfg['filter'] = $filter;
    }

    /**
     * Executes the nfdump command, tries to throw an exception based on the return code
     * @return array
     * @throws Exception
     */
    public function execute() {
        $output = array();
        $return = "";
        $command = $this->cfg['env']['bin'] . " " . $this->flatten($this->cfg['option']) . " " . $this->cfg['filter'];
        $this->d->log('Trying to execute ' . $command, LOG_INFO);
        exec($command, $output, $return);

        switch($return) {
            case 127: throw new Exception("NfDump: Failed to start process. Is nfdump installed? " . implode(' ', $output)); break;
            case 255: throw new Exception("NfDump: Initialization failed. " . implode(' ', $output)); break;
            case 254: throw new Exception("NfDump: Error in filter syntax." . implode(' ', $output)); break;
            case 250: throw new Exception("NfDump: Internal error." . implode(' ', $output)); break;
        }
        return $output;
    }

    /**
     * Concatenates key and value of supplied array
     * @param $array
     * @return bool|string
     */
    private function flatten($array) {
        if(!is_array($array)) return false;
        $output = "";

        foreach($array as $key => $value) {
            $output .= escapeshellarg(is_int($key) ?: $key . " " . $value ) . ' ';
        }
        return $output;
    }

    /**
     * Reset config
     */
    public function reset() {
        $this->clean['env'] = array(
            'bin' => \Config::$cfg['nfdump']['binary'],
            'profiles-data' => \Config::$cfg['nfdump']['profiles-data'],
            'profile' => \Config::$cfg['nfdump']['profile'],
        );
        $this->cfg = $this->clean;
    }

    /**
     * Converts a time range to a nfcapd file range
     * @param int $datestart
     * @param int $dateend
     * @return string
     */
    public function convert_date_to_path(int $datestart, int $dateend) {
        $start = $end = new DateTime();
        $start->setTimestamp($datestart);
        $end->setTimestamp($dateend);

        $pathstart = $start->format('Y/m/d') . DIRECTORY_SEPARATOR . 'nfcapd.' . $start->format('YmdHi');
        $pathend = $end->format('Y/m/d') . DIRECTORY_SEPARATOR . 'nfcapd.' . $start->format('YmdHi');

        return $pathstart . ':' . $pathend;
    }
}