<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Misc;
use mbolli\nfsen_ng\common\NfdumpProgressWatcher;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\QueryProgress;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;
use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine;

/**
 * Runs a single-shot nfdump query off the request path, reporting estimated progress.
 *
 * The action returns immediately and the work runs in a coroutine, while a second coroutine
 * samples how far nfdump has read and pushes per-mille updates as signal-only patches.
 * Progress is an estimate (bytes read against bytes to read), so the UI marks it as such.
 * Every finished run that was not cancelled is recorded for the query estimates.
 */
final class QueryRunner {
    /** How often to sample nfdump's read position. */
    public const POLL_INTERVAL_US = 250_000;

    public const string CANCELLED_STATUS = 'Query cancelled.';

    /** Kinds whose nfdump stops reading at its record limit (-c), so the window size overstates a run. */
    public const array EARLY_STOP_KINDS = ['flows'];

    /**
     * @param string          $kind       the query_kind (flows, stats, ...): the panel whose button
     *                                    renders the progress, and the kind the run is recorded as
     * @param \Closure(): int $totalBytes size of the nfcapd files the query will read; 0 =
     *                                    unknown, which degrades to an indeterminate indicator.
     *                                    A closure because sizing walks the window, which belongs
     *                                    in the coroutine rather than in front of the response.
     * @param \Closure        $work       performs the query and writes its own result/notifications
     */
    public static function run(Context $c, string $kind, \Closure $totalBytes, string $startStatus, \Closure $work): void {
        $running = $c->getSignal('query_running');
        $permille = $c->getSignal('query_permille');
        $status = $c->getSignal('query_status');
        $eta = $c->getSignal('query_eta');
        $exact = $c->getSignal('query_exact');
        $kindSignal = $c->getSignal('query_kind');
        \assert(
            $running !== null
            && $permille !== null
            && $status !== null
            && $eta !== null
            && $exact !== null
            && $kindSignal !== null
        );

        // One query per tab at a time: Nfdump::$runningPid is a single static, so a second
        // concurrent run would make the Kill button and the progress sampler ambiguous.
        if ($running->bool()) {
            return;
        }

        $contextId = $c->getId();
        QueryCancel::clear($contextId);

        $kindSignal->setValue($kind, broadcast: false);
        $running->setValue(true, broadcast: false);
        $permille->setValue(0, broadcast: false);
        $eta->setValue('', broadcast: false);
        $status->setValue($startStatus, broadcast: false);
        $exact->setValue(false, broadcast: false);
        $c->sync();

        Coroutine::create(static function () use (
            $c,
            $kind,
            $work,
            $totalBytes,
            $contextId,
            $running,
            $permille,
            $status,
            $eta
        ): void {
            $progress = new QueryProgress(static function (int $pm, string $etaText, int $done, int $total) use (
                $c,
                $permille,
                $status,
                $eta
            ): void {
                $permille->setValue($pm, broadcast: false);
                $eta->setValue($etaText, broadcast: false);
                $status->setValue('Read ' . self::formatBytes($done) . ' of ' . self::formatBytes($total), broadcast: false);
                $c->syncSignals();
            });

            $sizeInBytes = 0;
            $workStartedAt = microtime(true);
            $workSeconds = 0.0;
            $slotWait = 0.0;
            $error = null;
            $lastRead = new class {
                /** @var null|array{bytes: int, at: float} nfdump's last sampled read position */
                public ?array $sample = null;

                /** The first nfdump sampled; a graph run in the same tab registers under the same handle. */
                public ?int $pid = null;

                /** When that nfdump was first seen: a wait for a slot before it is no read time. */
                public ?float $seenAt = null;
            };

            // Everything in here is caught: a throw out of a coroutine takes the whole worker down,
            // and sizing touches the filesystem, so it belongs inside too.
            try {
                $sizeInBytes = $totalBytes();
                // forRunningNfdump()'s wiring, plus keeping the last sample for recordedRead().
                $watcher = new NfdumpProgressWatcher(
                    $progress,
                    $sizeInBytes,
                    static fn (): ?int => NfdumpSlots::pidFor($contextId),
                    static function (int $pid) use ($lastRead): ?int {
                        $read = Misc::processReadBytes($pid);
                        $lastRead->seenAt ??= microtime(true);
                        $lastRead->pid ??= $pid;
                        if ($read !== null && $pid === $lastRead->pid) {
                            $lastRead->sample = ['bytes' => $read, 'at' => microtime(true)];
                        }

                        return $read;
                    },
                );

                // Stops once the work is finished, or for good the first time the platform
                // cannot report bytes read.
                Coroutine::create(static function () use ($progress, $watcher): void {
                    try {
                        while (!$progress->isFinished() && $watcher->isTrackable()) {
                            Coroutine::usleep(self::POLL_INTERVAL_US);
                            if (!$watcher->tick()) {
                                return;
                            }
                        }
                    } catch (\Throwable $e) {
                        // The run goes on without a progress bar.
                        Debug::getInstance()->log('Query progress sampling stopped: ' . $e->getMessage(), LOG_WARNING);
                    }
                });

                $workStartedAt = microtime(true);

                // A user waits for it, so every nfdump it starts takes an interactive slot. A wait
                // for one is not read time, which the estimates learn from.
                try {
                    NfdumpSlots::runAs(NfdumpSlots::INTERACTIVE, static fn (): mixed => $work(), waited: $slotWait);
                } finally {
                    $workSeconds = max(0.0, microtime(true) - $workStartedAt - $slotWait);
                }
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                // finish() emits a last tick that rewrites the status from the counts, so it
                // has to run before the outcome is written.
                $progress->finish($sizeInBytes);
                $sample = $lastRead->sample;
                // nfdump started at most one poll interval before it was first seen.
                $readFrom = max($workStartedAt, ($lastRead->seenAt ?? $workStartedAt) - self::POLL_INTERVAL_US / 1e6);
                $read = self::recordedRead(
                    $kind,
                    $sizeInBytes,
                    $workSeconds,
                    $sample === null ? null : ['bytes' => $sample['bytes'], 'seconds' => $sample['at'] - $readFrom],
                );
                $finalStatus = self::finish($kind, $read['bytes'], $read['seconds'], $progress->elapsed(), $error, QueryCancel::isRequested($contextId));
                $status->setValue($finalStatus, broadcast: false);
                $running->setValue(false, broadcast: false);
                QueryCancel::clear($contextId);
                $c->sync();
            }
        });
    }

    /**
     * What a finished run records: the window's size over the work time, except for the
     * EARLY_STOP_KINDS, which record what nfdump had read at the last sample and when.
     * Without a sample those record 0 bytes, which medianThroughput() never counts.
     *
     * @param null|array{bytes: int, seconds: float} $lastRead seconds since the work started
     *
     * @return array{bytes: int, seconds: float}
     */
    public static function recordedRead(string $kind, int $windowBytes, float $workSeconds, ?array $lastRead): array {
        if (!\in_array($kind, self::EARLY_STOP_KINDS, true)) {
            return ['bytes' => $windowBytes, 'seconds' => $workSeconds];
        }
        if ($lastRead === null) {
            return ['bytes' => 0, 'seconds' => $workSeconds];
        }

        // rchar also counts reads outside the capture files.
        return ['bytes' => min($lastRead['bytes'], $windowBytes), 'seconds' => $lastRead['seconds']];
    }

    /**
     * The status line for a finished run. Records the run for the estimates unless it was
     * cancelled, whose timing says nothing about throughput. Never throws.
     *
     * @param int   $bytes        capture bytes the run read, from recordedRead()
     * @param float $readSeconds  the time those bytes took, from recordedRead()
     * @param float $totalSeconds time since the run started, for the status line
     */
    public static function finish(string $kind, int $bytes, float $readSeconds, float $totalSeconds, ?\Throwable $error, bool $cancelRequested): string {
        if (self::wasCancelled($error, $cancelRequested)) {
            Debug::getInstance()->log('Query cancelled (' . $kind . ').', LOG_INFO);

            return self::CANCELLED_STATUS;
        }

        if ($error !== null) {
            Debug::getInstance()->log('Query failed: ' . $error->getMessage(), LOG_ERR);
        }

        try {
            new QueryRunRepository(Database::shared())->record($kind, $bytes, 0, (int) round($readSeconds * 1000), $error === null);
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Query run not recorded: ' . $e->getMessage(), LOG_WARNING);
        }

        return $error === null
            ? 'Done in ' . round($totalSeconds, 1) . 's.'
            : 'Failed: ' . $error->getMessage();
    }

    /**
     * True when the tab pressed Kill, or nfdump was stopped by a signal. Pages use it to skip
     * the error notice for a run the user cancelled.
     */
    public static function wasCancelled(?\Throwable $error, bool $cancelRequested): bool {
        return $cancelRequested || ($error instanceof NfdumpException && $error->wasStopped());
    }

    /** Compact binary size for the progress line, e.g. "1.4 GiB". */
    public static function formatBytes(int $bytes): string {
        return Estimate::humanBytes($bytes);
    }
}
