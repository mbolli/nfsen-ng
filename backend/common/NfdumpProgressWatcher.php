<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\processor\Nfdump;

/**
 * Estimates how far a single long-running nfdump has got.
 *
 * The Flows and Statistics tabs run one nfdump over the whole window, so unlike the
 * filtered-graph build there is no bin counter to report — nfdump itself emits no
 * progress. What can be observed is how much it has read: /proc/<pid>/io's `rchar`
 * against the total size of the nfcapd files the range covers.
 *
 * This is an estimate, not a measurement. nfdump's reads include more than the capture
 * files, the files can be rotated mid-query, and a filter that matches nothing still
 * reads everything. Callers surface it as such (query_exact = false).
 *
 * procfs is Linux-only. Off Linux the reader answers null, tick() reports that it can no
 * longer track, and the caller falls back to an indeterminate spinner (cf. #143).
 *
 * The pid lookup and byte reader are injected so this is testable without spawning a
 * real process.
 */
final class NfdumpProgressWatcher {
    private bool $trackable = true;

    /**
     * @param \Closure(): ?int    $pidProvider current nfdump pid, or null when idle
     * @param \Closure(int): ?int $byteReader  bytes read by that pid, or null if unknowable
     */
    public function __construct(
        private readonly QueryProgress $progress,
        private readonly int $totalBytes,
        private readonly \Closure $pidProvider,
        private readonly \Closure $byteReader,
    ) {}

    /** Build a watcher wired to the live nfdump process and procfs. */
    public static function forRunningNfdump(QueryProgress $progress, int $totalBytes): self {
        return new self(
            $progress,
            $totalBytes,
            static fn (): ?int => Nfdump::$runningPid,
            static fn (int $pid): ?int => Misc::processReadBytes($pid),
        );
    }

    /**
     * Sample once and report.
     *
     * @return bool false once there is no point sampling again — either this platform
     *              cannot answer, or the total is unknown, so the caller should stop
     *              polling and leave the UI on its indeterminate indicator
     */
    public function tick(): bool {
        if (!$this->trackable || $this->totalBytes <= 0) {
            return false;
        }

        $pid = ($this->pidProvider)();
        if ($pid === null) {
            // nfdump has not started yet (or already exited) — nothing to sample, but
            // that is not a reason to give up on the next tick.
            return true;
        }

        $read = ($this->byteReader)($pid);
        if ($read === null) {
            $this->trackable = false;

            return false;
        }

        // Clamp: rchar counts every read the process makes, not only capture files, so it
        // can overshoot the total. Reporting >100% would be worse than saturating.
        $this->progress->update(min($read, $this->totalBytes), $this->totalBytes);

        return true;
    }

    /** Whether progress can still be estimated on this platform. */
    public function isTrackable(): bool {
        return $this->trackable && $this->totalBytes > 0;
    }
}
