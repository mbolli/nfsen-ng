<?php

class Debug {
    private $stopwatch;
    private $debug;
    public static $_instance;

    function __construct() {
        $this->stopwatch = microtime(true);
        $this->debug = true;
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
        if($this->debug) {
            foreach($mixed as $param) {
                echo "<br /><span style='color: green;'>" . $this->stopWatch() . "</span> ";
                if(is_array($param)) {
                    echo "<pre>", var_dump($mixed), "</pre>";
                } else {
                    echo $param;
                }
            }
        }
    }

}