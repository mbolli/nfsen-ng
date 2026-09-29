<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use OpenSwoole\Coroutine;

/**
 * How many nfdump processes may run at once, who may take them, and which query owns each.
 *
 * The limit was previously enforced by counting nfdump processes on the machine and throwing
 * if there were too many, which counted other people's nfdump runs, raced between the count
 * and the spawn, and turned "busy" into an error rather than a wait. Ownership was a single
 * static process id, so a second concurrent run made the Kill button ambiguous.
 *
 * Slots come in two classes. `interactive` (a user waits: UI queries, filtered graphs, exact
 * runs, an alert Test) may take every free slot. `background` (import, top-N collector,
 * backfill, live alert evaluation) may hold at most half the slots, only while one more
 * stays free, and never while a user query is waiting. So a user query never queues behind
 * background work; it waits only for a running nfdump to end.
 * Waiters are served in arrival order within those rules.
 *
 * A slot is taken per nfdump invocation. A filtered-graph build holds a pool of them from
 * acquireMany() and, after each bin, gives one back to a waiting user query or to background
 * work that needs it. runInHeldSlot() runs work in a slot taken that way.
 *
 * Process-local, which is one reason nfsen-ng runs one worker: a second would hand out its own
 * slots and could not kill the runs of the first.
 *
 * @phpstan-type Scope array{class: string, held: bool, wait: ?float, waited: float, until: ?float}
 */
final class NfdumpSlots {
    public const string INTERACTIVE = 'interactive';

    public const string BACKGROUND = 'background';

    public const array CLASSES = [self::INTERACTIVE, self::BACKGROUND];

    /** Nfdump's handle for runs that name none: imports and alert checks. */
    public const string SHARED_HANDLE = 'default';

    /** How long an interactive caller waits for a slot before giving up. */
    public const float DEFAULT_WAIT_SECONDS = 30.0;

    /**
     * Background work yields to every user query, and a slot timeout skips an import's file for
     * good, so it waits out a long user query instead.
     */
    public const float BACKGROUND_WAIT_SECONDS = 600.0;

    /** Exception code of an acquire that timed out. */
    public const int TIMED_OUT = 1;

    /** Exception code of an interactive acquire refused because the worker is stopping. */
    public const int CLOSED = 2;

    /** How often a waiter re-checks for a free slot. */
    private const float POLL_INTERVAL_SECONDS = 0.05;

    /** @var array<string, int> class => slots held */
    private static array $inUse = [self::INTERACTIVE => 0, self::BACKGROUND => 0];

    /** @var array<int, string> ticket => class of each waiting caller, oldest first */
    private static array $queue = [];

    private static int $nextTicket = 0;

    /** @var array<int, Scope> coroutine id (-1 outside one) => what its nfdump runs take */
    private static array $scopes = [];

    /**
     * @var array<string, list<int>> query handle => the pids it currently owns
     *
     * A list, not one pid: concurrent runs can share a handle (the import daemon and the alert
     * checks use the default one), and a scalar meant the second run overwrote the first and
     * then erased it on exit, so a kill found nothing while a process was still going
     */
    private static array $pids = [];

    private static bool $closed = false;

    /** Slots in total: the configured or derived process limit. */
    public static function max(): int {
        return max(1, Config::$settings->nfdumpMaxProcesses);
    }

    /** Slots the background class may hold at once: half of them, at least one. */
    public static function backgroundMax(): int {
        return max(1, intdiv(self::max(), 2));
    }

    /**
     * The background rule: a run may start only if a slot stays free for a user query after it
     * took one; with a single slot, only when nothing else runs.
     */
    public static function keepsOneFree(int $inUse, int $max): bool {
        return $max >= 2 ? $inUse <= $max - 2 : $inUse === 0;
    }

    /** Waits for a free slot of $class, up to $waitSeconds. @throws \RuntimeException when none frees up in time */
    public static function acquire(float $waitSeconds = self::DEFAULT_WAIT_SECONDS, string $class = self::INTERACTIVE): void {
        self::acquireMany(1, $class, $waitSeconds);
    }

    /**
     * Takes up to $n slots of $class at once. Waits only until at least one is free, then takes
     * as many as the class may hold right now, so a caller sizes its pool by the answer and
     * gives each slot back with release(). Runs in a held slot go through runInHeldSlot().
     *
     * @param null|float $waitSeconds null: the class default, see defaultWait()
     *
     * @return int<1, max> the slots taken, 1 to $n
     *
     * @throws \RuntimeException with code TIMED_OUT when no slot frees up in time
     */
    public static function acquireMany(int $n, string $class = self::INTERACTIVE, ?float $waitSeconds = null): int {
        $class = self::known($class);
        $n = max(1, $n);
        $started = microtime(true);
        $deadline = $started + ($waitSeconds ?? self::defaultWait($class));
        $ticket = null;

        try {
            while (true) {
                if (self::$closed && $class === self::INTERACTIVE) {
                    throw new \RuntimeException('nfsen-ng is stopping, so no new query starts.', self::CLOSED);
                }

                $free = self::grantable($class);
                if ($free > 0 && !self::queuedAhead($class, $ticket)) {
                    $granted = max(1, min($n, $free));
                    self::$inUse[$class] += $granted;

                    return $granted;
                }

                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(\sprintf(
                        'Timed out waiting for a free nfdump slot: %d of %d in use (%d interactive, %d background). Raise NFSEN_NFDUMP_MAX_PROCESSES or retry.',
                        self::inUse(),
                        self::max(),
                        self::$inUse[self::INTERACTIVE],
                        self::$inUse[self::BACKGROUND],
                    ), self::TIMED_OUT);
                }

                $ticket ??= self::enqueue($class);

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
        } finally {
            if ($ticket !== null) {
                unset(self::$queue[$ticket]);
            }
            $cid = Coroutine::getCid();
            if (isset(self::$scopes[$cid])) {
                self::$scopes[$cid]['waited'] += microtime(true) - $started;
            }
        }
    }

    /** Whether $e is an acquire that timed out, rather than nfdump failing. */
    public static function timedOut(\Throwable $e): bool {
        return $e::class === \RuntimeException::class && $e->getCode() === self::TIMED_OUT;
    }

    /**
     * From shutdown: interactive callers, waiting or new, get CLOSED since the tab is gone; an
     * import keeps its background slots to finish its file. False reopens (tests).
     */
    public static function close(bool $closed = true): void {
        self::$closed = $closed;
    }

    public static function isClosed(): bool {
        return self::$closed;
    }

    /** Gives back $n slots of $class. */
    public static function release(string $class = self::INTERACTIVE, int $n = 1): void {
        $class = self::known($class);
        self::$inUse[$class] = max(0, self::$inUse[$class] - max(0, $n));
    }

    /** Slots held, by one class or by both. */
    public static function inUse(?string $class = null): int {
        return $class === null ? array_sum(self::$inUse) : self::$inUse[self::known($class)];
    }

    /** Callers waiting for a slot, of one class or of both. */
    public static function waiting(?string $class = null): int {
        if ($class === null) {
            return \count(self::$queue);
        }
        $class = self::known($class);

        return \count(array_filter(self::$queue, static fn (string $waiter): bool => $waiter === $class));
    }

    /** Slots a caller of $class could take now, ignoring who waits: the capacity rules only. */
    public static function grantable(string $class): int {
        $max = self::max();
        $total = self::inUse();
        if (self::known($class) === self::INTERACTIVE) {
            return max(0, $max - $total);
        }

        $room = self::backgroundMax() - self::$inUse[self::BACKGROUND];
        $granted = 0;
        while ($granted < $room && self::keepsOneFree($total + $granted, $max)) {
            ++$granted;
        }

        return $granted;
    }

    /**
     * Slots a newcomer of $class would get now: grantable() unless a caller that goes first is
     * already waiting. A best-effort worker polls this rather than queueing in front of others.
     */
    public static function available(string $class): int {
        return self::queuedAhead(self::known($class), null) ? 0 : self::grantable($class);
    }

    /** How long a caller of $class waits for a slot unless it says otherwise. */
    public static function defaultWait(string $class): float {
        return self::known($class) === self::BACKGROUND ? self::BACKGROUND_WAIT_SECONDS : self::DEFAULT_WAIT_SECONDS;
    }

    /**
     * How long a run in $scope waits for its slot: the scope's wait or the class default, cut to
     * what is left of the scope's budget.
     *
     * @param Scope $scope
     */
    public static function waitFor(array $scope): float {
        $wait = $scope['wait'] ?? self::defaultWait($scope['class']);

        return $scope['until'] === null ? $wait : max(0.0, min($wait, $scope['until'] - microtime(true)));
    }

    /**
     * Runs $work with every nfdump it starts in this coroutine taking a $class slot, waiting up to
     * $waitSeconds for each (null: the class default) and, with a $budget, no more than $budget
     * seconds for all of them together. $waited receives the time spent waiting.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function runAs(string $class, \Closure $work, ?float $waitSeconds = null, ?float &$waited = null, ?float $budget = null): mixed {
        $until = $budget === null ? null : microtime(true) + max(0.0, $budget);

        return self::scoped(['class' => self::known($class), 'held' => false, 'wait' => $waitSeconds, 'waited' => 0.0, 'until' => $until], $work, $waited);
    }

    /**
     * Runs $work in a slot of $class the caller already took with acquireMany(): the nfdump runs
     * it starts in this coroutine take no slot of their own. The caller still releases the slot.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public static function runInHeldSlot(string $class, \Closure $work): mixed {
        return self::scoped(['class' => self::known($class), 'held' => true, 'wait' => null, 'waited' => 0.0, 'until' => null], $work);
    }

    /**
     * What an nfdump started in this coroutine takes: interactive, with its own slot, unless
     * runAs() or runInHeldSlot() says otherwise.
     *
     * @return Scope
     */
    public static function scope(): array {
        return self::$scopes[Coroutine::getCid()] ?? ['class' => self::INTERACTIVE, 'held' => false, 'wait' => null, 'waited' => 0.0, 'until' => null];
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
     * Sends SIGTERM to every process owned by $handle: a filtered-graph build has one in flight
     * per slot it holds, and a handle shared by concurrent callers can own several too.
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

    /**
     * @template T
     *
     * @param Scope         $scope
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function scoped(array $scope, \Closure $work, ?float &$waited = null): mixed {
        $cid = Coroutine::getCid();
        $outer = self::$scopes[$cid] ?? null;
        if (isset($outer['until'])) {
            // Runs in an inner scope spend the outer budget too.
            $scope['until'] = min($scope['until'] ?? $outer['until'], $outer['until']);
        }
        self::$scopes[$cid] = $scope;

        try {
            return $work();
        } finally {
            $waited = self::$scopes[$cid]['waited'];
            if ($outer === null) {
                unset(self::$scopes[$cid]);
            } else {
                // An inner wait is also the outer scope's.
                self::$scopes[$cid] = ['waited' => $outer['waited'] + $waited] + $outer;
            }
        }
    }

    /**
     * Whether a caller of $class must let a waiter go first: a user query waits only behind
     * earlier user queries; background work waits behind every user query and earlier
     * background work. $ticket null is a newcomer, behind everyone.
     */
    private static function queuedAhead(string $class, ?int $ticket): bool {
        foreach (self::$queue as $other => $otherClass) {
            if ($other === $ticket) {
                continue;
            }
            $earlier = $ticket === null || $other < $ticket;
            if ($otherClass === self::INTERACTIVE && ($earlier || $class === self::BACKGROUND)) {
                return true;
            }
            if ($otherClass === self::BACKGROUND && $class === self::BACKGROUND && $earlier) {
                return true;
            }
        }

        return false;
    }

    private static function enqueue(string $class): int {
        $ticket = self::$nextTicket++;
        self::$queue[$ticket] = $class;

        return $ticket;
    }

    /** @throws \InvalidArgumentException for a class that is neither interactive nor background */
    private static function known(string $class): string {
        if (!\in_array($class, self::CLASSES, true)) {
            throw new \InvalidArgumentException("Unknown nfdump slot class '{$class}'.");
        }

        return $class;
    }
}
