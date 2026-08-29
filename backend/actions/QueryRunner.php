<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\actions;

use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\NfdumpProgressWatcher;
use mbolli\nfsen_ng\common\QueryCancel;
use mbolli\nfsen_ng\common\QueryProgress;
use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine;

/**
 * Runs a single-shot nfdump query off the request path, reporting estimated progress.
 *
 * The Flows and Statistics tabs used to block their action until nfdump returned, which
 * left the button on an indeterminate spinner for the whole query with no way to tell a
 * slow window from a stuck one. Here the action returns immediately and the work runs in
 * a coroutine (the trigger-import shape), while a second coroutine samples how far nfdump
 * has read and pushes per-mille updates as signal-only patches.
 *
 * Progress is an *estimate* — bytes read against bytes to read — so the UI marks it as
 * such. The filtered-graph build knows its bin count up front and reports exactly.
 */
final class QueryRunner {
    /** How often to sample nfdump's read position. */
    public const POLL_INTERVAL_US = 250_000;

    /**
     * @param string          $kind       which panel owns this query ('flows'|'stats'), so only
     *                                    that panel's button renders the progress
     * @param \Closure(): int $totalBytes size of the nfcapd files the query will read; 0 =
     *                                    unknown, which degrades to an indeterminate indicator.
     *                                    A closure, not a value: sizing walks the window and
     *                                    stat()s every file, which belongs in the coroutine
     *                                    rather than in front of the action's response.
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

            // Sized here rather than before the action returned: walking the window and
            // stat()-ing every file is thousands of syscalls at a wide range.
            $sizeInBytes = $totalBytes();
            $watcher = NfdumpProgressWatcher::forRunningNfdump($progress, $sizeInBytes);

            // Sampler runs alongside the query. It stops once the work is finished, or
            // permanently the first time the platform cannot report bytes read.
            Coroutine::create(static function () use ($progress, $watcher): void {
                while (!$progress->isFinished() && $watcher->isTrackable()) {
                    Coroutine::usleep(self::POLL_INTERVAL_US);
                    if (!$watcher->tick()) {
                        return;
                    }
                }
            });

            $finalStatus = '';

            try {
                $work();
                $finalStatus = 'Done in ' . round($progress->elapsed(), 1) . 's.';
            } catch (\Throwable $e) {
                // An uncaught throw inside a coroutine takes the whole worker down, not
                // just this request — this catch is load-bearing, not decoration.
                Debug::getInstance()->log('Query failed: ' . $e->getMessage(), LOG_ERR);
                $finalStatus = 'Failed: ' . $e->getMessage();
            } finally {
                // finish() emits a last tick that rewrites the status from the counts, so it
                // has to run before the outcome message rather than after it.
                $progress->finish($sizeInBytes);
                $status->setValue($finalStatus, broadcast: false);
                $running->setValue(false, broadcast: false);
                QueryCancel::clear($contextId);
                $c->sync();
            }
        });
    }

    /** Compact binary size for the progress line, e.g. "1.4 GiB". */
    public static function formatBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < \count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return round($value, $value < 10 ? 1 : 0) . ' ' . $units[$unit];
    }
}
