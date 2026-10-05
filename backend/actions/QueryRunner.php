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
 * Progress is an estimate (bytes read against bytes to read), so the UI marks it as such;
 * a split query (PartitionPlanner) counts files instead. Every finished run that was not
 * cancelled is recorded for the query estimates.
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
     * @param \Closure        $work       performs the query and writes its own result/notifications,
     *                                    given the onSplit of PartitionPlanner::run()
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

        $kindSignal->setValue($kind);
        $running->setValue(true);
        $permille->setValue(0);
        $eta->setValue('');
        $status->setValue($startStatus);
        $exact->setValue(false);
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
            $split = new class {
                /** Processes the query runs as now: 1 again once a split falls back to one. */
                public int $parts = 1;

                /** Processes its first pass split into. */
                public int $width = 1;

                /** Whether a split read its files a second time. */
                public bool $again = false;

                /** @var null|\Closure(): array{int, int} files read and to read, once split */
                public ?\Closure $sample = null;

                public int $files = 0;

                public float $startedAt = 0.0;

                /** Work seconds when the first pass ended, and when the one process it fell back to started. */
                public ?float $firstPass = null;

                public ?float $singleFrom = null;
            };
            $progress = new QueryProgress(static function (int $pm, string $etaText, int $done, int $total) use (
                $c,
                $permille,
                $status,
                $eta,
                $split
            ): void {
                $permille->setValue($pm);
                $eta->setValue($etaText);
                $status->setValue($split->sample === null
                    ? 'Read ' . self::formatBytes($done) . ' of ' . self::formatBytes($total)
                    : self::splitStatus($done, $total, $split->parts, $split->again));
                $c->syncSignals();
            });
            $onSplit = static function (int $parts, \Closure $sample, bool $again, bool $single = false) use ($split): void {
                $at = microtime(true) - $split->startedAt - NfdumpSlots::scope()['waited'];
                if ($again) {
                    $split->firstPass ??= $at;
                }
                if ($single) {
                    $split->singleFrom = $at;
                }
                $split->files = $again ? $split->files : $sample()[1];
                $split->width = max($split->width, $parts);
                $split->parts = $parts;
                $split->again = $split->again || $again;
                $split->sample = $sample;
            };

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

                // Stops once the work is finished. The bytes of one nfdump are no longer sampled
                // once the platform cannot report them; a split counts the files of its parts.
                Coroutine::create(static function () use ($progress, $watcher, $split): void {
                    try {
                        $watching = true;
                        while (!$progress->isFinished()) {
                            Coroutine::usleep(self::POLL_INTERVAL_US);
                            if ($split->sample !== null) {
                                [$done, $total] = ($split->sample)();
                                $progress->update($done, $total);
                            } elseif ($watching && !$watcher->tick()) {
                                $watching = false;
                            }
                        }
                    } catch (\Throwable $e) {
                        // The run goes on without a progress bar.
                        Debug::getInstance()->log('Query progress sampling stopped: ' . $e->getMessage(), LOG_WARNING);
                    }
                });

                $workStartedAt = $split->startedAt = microtime(true);

                // A user waits for it, so every nfdump it starts takes an interactive slot. A wait
                // for one is not read time, which the estimates learn from.
                try {
                    NfdumpSlots::runAs(NfdumpSlots::INTERACTIVE, static fn (): mixed => $work($onSplit), waited: $slotWait);
                } finally {
                    $workSeconds = max(0.0, microtime(true) - $workStartedAt - $slotWait);
                }
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                // finish() emits a last tick that rewrites the status from the counts, so it
                // has to run before the outcome is written.
                $progress->finish($split->sample === null ? $sizeInBytes : ($split->sample)()[1]);
                $sample = $lastRead->sample;
                // nfdump started at most one poll interval before it was first seen.
                $readFrom = max($workStartedAt, ($lastRead->seenAt ?? $workStartedAt) - self::POLL_INTERVAL_US / 1e6);
                $read = self::recordedRead(
                    $kind,
                    $sizeInBytes,
                    $workSeconds,
                    $sample === null ? null : ['bytes' => $sample['bytes'], 'seconds' => $sample['at'] - $readFrom],
                );
                if ($split->again) {
                    $read['seconds'] = self::splitReadSeconds($workSeconds, $split->firstPass, $split->singleFrom);
                }
                $resultParts = $split->singleFrom === null ? $split->width : 1;
                $finalStatus = self::finish($kind, $read['bytes'], $read['seconds'], $progress->elapsed(), $error, QueryCancel::isRequested($contextId), $resultParts, $split->files, $split->again);
                $status->setValue($finalStatus);
                $running->setValue(false);
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
     * The status line for a finished run, recorded for the estimates unless it was cancelled.
     * Never throws.
     *
     * @param int   $bytes        capture bytes the run read, from recordedRead()
     * @param float $readSeconds  the time one read of those bytes took
     * @param float $totalSeconds time since the run started, for the status line
     * @param int   $parts        nfdump processes the result came from, side by side (1 after a fallback)
     * @param int   $files        capture files the run read, when known
     * @param bool  $again        a split read files a second time: recorded as 2 passes
     */
    public static function finish(string $kind, int $bytes, float $readSeconds, float $totalSeconds, ?\Throwable $error, bool $cancelRequested, int $parts = 1, int $files = 0, bool $again = false): string {
        if (self::wasCancelled($error, $cancelRequested)) {
            Debug::getInstance()->log('Query cancelled (' . $kind . ').', LOG_INFO);

            return self::CANCELLED_STATUS;
        }

        if ($error !== null) {
            Debug::getInstance()->log('Query failed: ' . $error->getMessage(), LOG_ERR);
        }

        try {
            new QueryRunRepository(Database::shared())->record($kind, $bytes, $files, (int) round($readSeconds * 1000), $error === null, parts: $parts, passes: $again ? 2 : 1);
        } catch (\Throwable $e) {
            Debug::getInstance()->log('Query run not recorded: ' . $e->getMessage(), LOG_WARNING);
        }

        if ($error !== null) {
            return 'Failed: ' . $error->getMessage();
        }

        return 'Done in ' . round($totalSeconds, 1) . 's' . ($parts > 1 ? ' with ' . $parts . ' nfdump processes.' : '.');
    }

    /**
     * The seconds of one complete read in a split that read files again: the one process it fell
     * back to, else its first pass.
     */
    public static function splitReadSeconds(float $workSeconds, ?float $firstPass, ?float $singleFrom): float {
        if ($singleFrom !== null) {
            return max(0.0, $workSeconds - $singleFrom);
        }

        return min($workSeconds, $firstPass ?? $workSeconds);
    }

    /** The progress line of a split query: files, since its parts read side by side. */
    public static function splitStatus(int $done, int $total, int $parts, bool $again = false): string {
        return 'Read ' . number_format($done) . ' of ' . number_format($total) . ' files'
            . ($parts > 1 ? ' in ' . $parts . ' nfdump processes' : '') . ($again ? ', second pass' : '');
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
