<?php

namespace nfsen_ng\common;

class Debug {
    private $stopwatch;
    private $debug;
    private $cli;
    public static $_instance;

    function __construct() {
        $this->stopwatch = microtime(true);
        $this->debug = true;
        $this->cli = (php_sapi_name() === 'cli');
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Logs the message if allowed in settings
     * @param string $message
     * @param int $priority
     */
    public function log(string $message, int $priority) {
        if (Config::$cfg['log']['priority'] >= $priority) {
            syslog($priority, 'nfsen-ng: ' . $message);

            if ($this->cli === true && $this->debug === true) echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        }
    }

    /**
     * Returns the time passed from initialization.
     * @param bool $precise
     * @return float|mixed
     */
    public function stopWatch(bool $precise = false) {
        $result = microtime(true) - $this->stopwatch;
        if ($precise === false) $result = round($result, 4);
        return $result;
    }

    /**
     * Debug print. Prints the supplied string with the time passed from initialization.
     * @param $mixed
     */
    public function dpr(...$mixed) {
        if($this->debug === false) return;

        foreach($mixed as $param) {
            echo ($this->cli) ? PHP_EOL . $this->stopWatch() . "s " : "<br /><span style='color: green;'>" . $this->stopWatch() . "</span> ";
            if(is_array($param)) {
                echo ($this->cli) ? print_r($mixed, true) : "<pre>", var_dump($mixed), "</pre>";
            } else {
                echo $param;
            }
        }
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug) {
        $this->debug = $debug;
    }

}