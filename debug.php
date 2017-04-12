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
     * stopWatch function.
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
     * dpr function.
     * Debug print. Prints the supplied string with the time passed from initialization.
     * @param $mixed
     * @param bool $with_stopwatch
     */
    public function dpr($mixed, bool $with_stopwatch = true) {
        if($this->debug) {
            if($with_stopwatch === true) echo "<br /><span style='color: green;'>" . $this->stopWatch() . "</span> ";
            if(is_array($mixed)) {
                echo "<pre>", var_dump($mixed), "</pre>";
            } else {
                echo $mixed;
            }
        }
    }

}