<?php

declare(strict_types=1);

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
     * Guards against Config not being initialised yet (early-boot calls).
     */
    public function log(string $message, int $priority): void {
        // Config::$cfg may be empty before Config::initialize() is called.
        $cfgPriority = Config::$cfg['log']['priority'] ?? \LOG_INFO;
        if ($cfgPriority >= $priority) {
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
     * Only emits output in CLI mode; in HTTP/SSE contexts HTML output would corrupt the stream.
     */
    public function dpr(...$mixed): void {
        if ($this->debug === false || $this->cli === false) {
            return;
        }

        foreach ($mixed as $param) {
            echo PHP_EOL . $this->stopWatch() . 's ';
            if (\is_array($param) || \is_object($param)) {
                echo print_r($param, true);
            } else {
                echo $param;
            }
        }
    }

    public function setDebug(bool $debug): void {
        $this->debug = $debug;
    }
}
