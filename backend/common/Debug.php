<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

class Debug {
    private const LOG_BUFFER_MAX = 200;
    public static ?self $_instance = null;
    private readonly float $stopwatch;
    private bool $debug = true;
    private readonly bool $cli;

    /** @var array<int,array{ts:int,level:int,msg:string}> Ring buffer for admin UI log drain. */
    private static array $logBuffer = [];

    private static ?LogRing $recent = null;

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
        // Config::$settings may not be initialized before Config::initialize() is called.
        $cfgPriority = isset(Config::$settings) ? Config::$settings->logPriority : LOG_INFO;
        if ($cfgPriority >= $priority) {
            syslog($priority, 'nfsen-ng: ' . $message);
            self::ring()->push($priority, $message);

            if ($this->cli === true && $this->debug === true) {
                echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
            }
        }

        // Always buffer LOG_WARNING and above so the admin UI can show them.
        if ($priority <= LOG_WARNING) {
            self::$logBuffer[] = ['ts' => time(), 'level' => $priority, 'msg' => $message];
            if (\count(self::$logBuffer) > self::LOG_BUFFER_MAX) {
                array_shift(self::$logBuffer);
            }
        }
    }

    /**
     * Drain and return all buffered log entries, clearing the buffer.
     *
     * @return array<int,array{ts:int,level:int,msg:string}>
     */
    public static function drainBuffer(): array {
        $entries = self::$logBuffer;
        self::$logBuffer = [];

        return $entries;
    }

    /**
     * Lines that passed the configured log level, newest first. `ts` is in milliseconds.
     *
     * @return list<array{seq: int, ts: int, level: int, levelName: string, message: string}>
     */
    public static function recent(int $limit = 200, int $maxPriority = LOG_DEBUG): array {
        return self::ring()->recent($limit, $maxPriority);
    }

    /**
     * Lines logged after $seq, oldest first.
     *
     * @return list<array{seq: int, ts: int, level: int, levelName: string, message: string}>
     */
    public static function recentSince(int $seq): array {
        return self::ring()->since($seq);
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
    public function dpr(mixed ...$mixed): void {
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

    private static function ring(): LogRing {
        return self::$recent ??= new LogRing();
    }
}
