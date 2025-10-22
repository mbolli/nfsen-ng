<?php

namespace mbolli\nfsen_ng\common;

class Debug {
    public static ?self $_instance = null;
    private readonly float $stopwatch;
    private bool $debug = true;
    private readonly bool $cli;

    public function __construct() {
        $this->stopwatch = microtime(true);
        $this->cli = (\PHP_SAPI === 'cli');
    }

    public static function getInstance(): self {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Logs the message if allowed in settings.
     */
    public function log(string $message, int $priority): void {
        if (Config::$cfg['log']['priority'] >= $priority) {
            syslog($priority, 'nfsen-ng: ' . $message);

            if ($this->cli === true && $this->debug === true) {
                echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
            }
        }
    }

    /**
     * Returns the time passed from initialization.
     */
    public function stopWatch(bool $precise = false): float {
        $result = microtime(true) - $this->stopwatch;
        if ($precise === false) {
            $result = round($result, 4);
        }

        return $result;
    }

    /**
     * Debug print. Prints the supplied string with the time passed from initialization.
     */
    public function dpr(...$mixed): void {
        if ($this->debug === false) {
            return;
        }

        foreach ($mixed as $param) {
            echo ($this->cli) ? PHP_EOL . $this->stopWatch() . 's ' : "<br /><span style='color: green;'>" . $this->stopWatch() . '</span> ';
            if (\is_array($param)) {
                echo ($this->cli) ? print_r($mixed, true) : '<pre>', var_export($mixed, true), '</pre>';
            } else {
                echo $param;
            }
        }
    }

    public function setDebug(bool $debug): void {
        $this->debug = $debug;
    }
}
