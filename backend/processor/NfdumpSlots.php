<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use OpenSwoole\Coroutine;

/**
 * How many nfdump processes may run at once, and which query owns each of them.
 *
 * Two jobs that have to be one thing. The limit was previously enforced by counting nfdump
 * processes on the machine and throwing if there were too many, which counted other people's
 * nfdump runs, raced between the count and the spawn, and turned "busy" into an error rather
 * than a wait. Ownership was a single static process id, so a second concurrent run made the
 * Kill button ambiguous: it killed whichever run started last.
 *
 * A slot is taken per nfdump invocation rather than per query on purpose. A filtered-graph
 * build runs hundreds of nfdump processes in sequence, and holding one slot for the whole
 * build would starve every other caller for minutes.
 *
 * Single-worker only, like the rest of php-via's process-local state. Raising the worker count
 * would need the ceiling in shared memory to mean anything across processes.
 */
final class NfdumpSlots {
    /** How long a caller waits for a slot before giving up. */
    public const DEFAULT_WAIT_SECONDS = 30.0;

    /** How often a waiter re-checks for a free slot. */
    private const POLL_INTERVAL_SECONDS = 0.05;

    private static int $inUse = 0;

    /** @var array<string, int> query handle => running nfdump pid */
    private static array $pids = [];

    /**
     * Waits for a free slot, up to $waitSeconds.
     *
     * @throws \RuntimeException when no slot frees up in time
     */
    public static function acquire(float $waitSeconds = self::DEFAULT_WAIT_SECONDS): void {
        $max = max(1, Config::$settings->nfdumpMaxProcesses);
        $deadline = microtime(true) + $waitSeconds;

        while (self::$inUse >= $max) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException(\sprintf(
                    'Timed out waiting for a free nfdump slot: %d of %d in use. Raise NFSEN_NFDUMP_MAX_PROCESSES or retry.',
                    self::$inUse,
                    $max,
                ));
            }

            // Yielding rather than sleeping, so the worker keeps serving other requests while
            // this one waits. Outside a coroutine (CLI import, tests) there is nothing to yield
            // to, and Coroutine::usleep() there takes the process down with it.
            $micros = (int) (self::POLL_INTERVAL_SECONDS * 1_000_000);
            if (Coroutine::getCid() > 0) {
                Coroutine::usleep($micros);
            } else {
                usleep($micros);
            }
        }

        ++self::$inUse;
    }

    public static function release(): void {
        self::$inUse = max(0, self::$inUse - 1);
    }

    public static function inUse(): int {
        return self::$inUse;
    }

    /**
     * Records the process a query owns, so a kill reaches that run and not another.
     */
    public static function register(string $handle, int $pid): void {
        self::$pids[$handle] = $pid;
    }

    public static function unregister(string $handle): void {
        unset(self::$pids[$handle]);
    }

    public static function pidFor(string $handle): ?int {
        return self::$pids[$handle] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public static function running(): array {
        return self::$pids;
    }

    /**
     * Sends SIGTERM to the process owned by $handle.
     *
     * @return ?int the pid signalled, or null when that query owns nothing right now
     */
    public static function kill(string $handle): ?int {
        $pid = self::$pids[$handle] ?? null;
        if ($pid === null || $pid <= 0) {
            return null;
        }

        posix_kill($pid, SIGTERM);
        Debug::getInstance()->log('Sent SIGTERM to nfdump pid ' . $pid . ' for query ' . $handle, LOG_DEBUG);

        return $pid;
    }
}
