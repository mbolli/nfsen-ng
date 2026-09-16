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

    /**
     * @var array<string, list<int>> query handle => the pids it currently owns
     *
     * A list, not one pid: concurrent runs can share a handle — the import daemon and every
     * MCP call use the default one — and a scalar meant the second run overwrote the first and
     * then erased it on exit, so a kill found nothing while a process was still going
     */
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
     * Records a process a query owns, so a kill reaches that run and not another.
     */
    public static function register(string $handle, int $pid): void {
        self::$pids[$handle][] = $pid;
    }

    /**
     * Drops one pid. Without the pid, one run ending cleared a sibling's entry too.
     */
    public static function unregister(string $handle, int $pid): void {
        if (!isset(self::$pids[$handle])) {
            return;
        }

        self::$pids[$handle] = array_values(array_filter(
            self::$pids[$handle],
            static fn (int $known): bool => $known !== $pid
        ));

        if (self::$pids[$handle] === []) {
            unset(self::$pids[$handle]);
        }
    }

    /** The most recent pid this query owns, for a progress sampler following one run. */
    public static function pidFor(string $handle): ?int {
        $pids = self::$pids[$handle] ?? [];

        return $pids === [] ? null : $pids[\count($pids) - 1];
    }

    /**
     * @return array<string, list<int>>
     */
    public static function running(): array {
        return self::$pids;
    }

    /**
     * Sends SIGTERM to every process owned by $handle. A chunked build has one in flight at a
     * time, but a handle shared by concurrent callers can own several.
     *
     * @return ?int the last pid signalled, or null when that query owns nothing right now
     */
    public static function kill(string $handle): ?int {
        $pids = array_filter(self::$pids[$handle] ?? [], static fn (int $pid): bool => $pid > 0);
        if ($pids === []) {
            return null;
        }

        $last = null;
        foreach ($pids as $pid) {
            posix_kill($pid, SIGTERM);
            Debug::getInstance()->log('Sent SIGTERM to nfdump pid ' . $pid . ' for query ' . $handle, LOG_DEBUG);
            $last = $pid;
        }

        return $last;
    }
}
