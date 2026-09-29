<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\processor\MultiStatCsvParser;
use mbolli\nfsen_ng\processor\NfdumpSlots;
use mbolli\nfsen_ng\processor\NfdumpSummary;
use mbolli\nfsen_ng\processor\Processor;
use mbolli\nfsen_ng\query\TopNStat;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\StoreUnavailableException;
use mbolli\nfsen_ng\store\TopNRepository;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * Collects the per-interval top-N of every capture file into SQLite. Without boot() (CLI, MCP,
 * tests) enqueue() does nothing.
 *
 * @phpstan-import-type ProcessorResult from Processor
 *
 * @phpstan-type Totals array{flows: int, packets: int, bytes: int}
 * @phpstan-type Item array{profile: string, source: string, relPath: string, ts: int, totals: null|Totals}
 */
final class TopNCollector {
    public const int QUEUE_CAPACITY = 4096;
    public const int TOP = TopNRepository::TOP;

    /** Query handle of every collector run, so a user's Kill never reaches one. */
    public const string HANDLE = 'topn';

    /** During a backlog the generation moves on after this many items of a profile. */
    public const int BUMP_EVERY = 50;

    public const int GAP_FILL_FIRST = 60;
    public const int GAP_FILL_EVERY = 600;
    public const int GAP_FILL_MAX = 500;
    public const int PRUNE_FIRST = 300;
    public const int PRUNE_EVERY = 3600;

    /** Between maintenance statements and between gap filler days. */
    private const int MAINTENANCE_PAUSE_US = 10_000;

    /** nfdump takes at most eight `-s` per run. */
    private const array RUNS = [
        [TopNStat::SrcIp, TopNStat::DstIp, TopNStat::SrcPort, TopNStat::DstPort, TopNStat::Proto, TopNStat::SrcAs, TopNStat::DstAs, TopNStat::InIf],
        [TopNStat::OutIf],
    ];

    private static bool $booted = false;
    private static int $retentionDays = 0;
    private static ?TopNRepository $repo = null;

    /** @var list<string> */
    private static array $profiles = [];

    /** @var list<Item> */
    private static array $queue = [];

    /** @var array<string, true> */
    private static array $queuedKeys = [];

    /** boot() lets push() start the worker coroutine; start() alone (tests) does not. */
    private static bool $spawnsWorker = false;

    private static bool $working = false;

    /** @var null|\Closure(): bool true while a bulk import holds a daemon lock */
    private static ?\Closure $importBusy = null;

    /** @var array<string, int> */
    private static array $generations = [];

    /** @var array<string, int> items written per profile since its last bump */
    private static array $unbumped = [];

    private static int $processed = 0;
    private static int $failed = 0;
    private static int $lastTs = 0;
    private static int $lastMs = 0;
    private static int $nextGapFill = 0;
    private static int $nextPrune = 0;
    private static bool $maintaining = false;

    /** From AppStartup::boot() once the daemons exist; stays off when retention is 0 or the store is unavailable. */
    public static function boot(Via $app): void {
        $debug = Debug::getInstance();
        $days = Config::$settings->topnRetentionDays;
        if ($days <= 0) {
            $debug->log('TopN collector off: NFSEN_TOPN_RETENTION_DAYS is 0', LOG_INFO);

            return;
        }

        try {
            $db = Database::shared();
        } catch (StoreUnavailableException $e) {
            $debug->log('TopN collector off: ' . $e->getMessage(), LOG_WARNING);

            return;
        }
        if ($db->isReadOnly()) {
            $debug->log('TopN collector off: the SQLite store is read-only', LOG_WARNING);

            return;
        }

        $daemons = $app->globalState('daemons', []);
        $profiles = \is_array($daemons) && $daemons !== []
            ? array_map(static fn (int|string $profile): string => (string) $profile, array_keys($daemons))
            : array_values(Config::detectProfiles());

        self::start(new TopNRepository($db), $days, time(), $profiles);
        self::$importBusy = static function () use ($app): bool {
            $daemons = $app->globalState('daemons', []);
            foreach (\is_array($daemons) ? $daemons : [] as $daemon) {
                if ($daemon instanceof ImportDaemon && $daemon->isLocked()) {
                    return true;
                }
            }

            return false;
        };
        self::$spawnsWorker = true;
        $app->setInterval(static function (): void {
            Coroutine::create(static function (): void {
                self::maintain(time());
            });
        }, 60_000);

        $debug->log("TopN collector on: {$days} days retention, profiles " . implode(', ', $profiles), LOG_INFO);
    }

    /**
     * Arms enqueue(), the gap filler and the pruner without a worker. boot() calls it; tests
     * call it directly and drain the queue with next() and process().
     *
     * @param list<string> $profiles
     */
    public static function start(TopNRepository $repo, int $retentionDays, int $now, array $profiles = []): void {
        self::$repo = $repo;
        self::$retentionDays = max(0, $retentionDays);
        self::$profiles = $profiles;
        self::$booted = self::$retentionDays > 0;
        self::$nextGapFill = $now + self::GAP_FILL_FIRST;
        self::$nextPrune = $now + self::PRUNE_FIRST;
    }

    /** Whether enqueue(), the gap filler and the pruner are armed in this process. */
    public static function booted(): bool {
        return self::$booted;
    }

    /** Tests: back to a process that never booted the collector. */
    public static function reset(): void {
        self::$booted = false;
        self::$retentionDays = 0;
        self::$repo = null;
        self::$profiles = [];
        self::$queue = [];
        self::$queuedKeys = [];
        self::$spawnsWorker = false;
        self::$working = false;
        self::$importBusy = null;
        self::$generations = [];
        self::$unbumped = [];
        self::$processed = 0;
        self::$failed = 0;
        self::$lastTs = 0;
        self::$lastMs = 0;
        self::$nextGapFill = 0;
        self::$nextPrune = 0;
        self::$maintaining = false;
    }

    /**
     * Queues an imported capture file. One primary-key lookup decides whether it is needed.
     *
     * @param null|Totals $totals from the import's -I; null makes the collector run -I
     */
    public static function enqueue(string $profile, string $source, string $relPath, int $ts, ?array $totals = null): void {
        if (!self::$booted || $ts < time() - self::$retentionDays * 86400 || isset(self::$queuedKeys[self::itemKey($profile, $source, $ts)])) {
            return;
        }

        try {
            $collected = self::alreadyCollected(self::repository()->intervalState($profile, $source, $ts), self::fileMtime($profile, $source, $relPath));
        } catch (\Throwable $e) {
            Debug::getInstance()->log("TopN: cannot check {$profile}/{$source} {$relPath}: " . $e->getMessage(), LOG_WARNING);

            return;
        }
        if ($collected) {
            Debug::getInstance()->log("TopN: skipped {$profile}/{$source} {$relPath}, collected already", LOG_DEBUG);

            return;
        }

        self::push(['profile' => $profile, 'source' => $source, 'relPath' => $relPath, 'ts' => $ts, 'totals' => $totals]);
    }

    /**
     * Skip rule: an interval collected (ok or empty) from a file at least this new, or failed
     * MAX_ATTEMPTS times on this same file, is not collected again.
     *
     * @param null|array{status: int, attempts: int, fileMtime: int} $state
     */
    public static function alreadyCollected(?array $state, int $fileMtime): bool {
        if ($state === null) {
            return false;
        }
        if ($state['status'] === TopNRepository::STATUS_FAILED) {
            return $state['attempts'] >= TopNRepository::MAX_ATTEMPTS && $state['fileMtime'] === $fileMtime;
        }

        return $state['fileMtime'] >= $fileMtime;
    }

    /**
     * Collects one capture file and stores it, synchronously: 'empty' when it has no flows,
     * 'failed' when nfdump failed (retried until MAX_ATTEMPTS for the same file).
     *
     * @param null|Totals $totals
     *
     * @return 'empty'|'failed'|'ok'
     */
    public static function collectOne(string $profile, string $source, string $relPath, int $ts, ?array $totals = null): string {
        $started = hrtime(true);
        $mtime = self::fileMtime($profile, $source, $relPath);
        $status = TopNRepository::STATUS_OK;
        $rowsByStat = [];

        try {
            $totals ??= self::runTotals($profile, $source, $relPath);
            if ($totals['flows'] <= 0) {
                $status = TopNRepository::STATUS_EMPTY;
            } else {
                foreach (self::RUNS as $stats) {
                    $rowsByStat += self::runStats($profile, $source, $relPath, $stats);
                }
            }
        } catch (\Throwable $e) {
            $status = TopNRepository::STATUS_FAILED;
            $rowsByStat = [];
            Debug::getInstance()->log("TopN: {$profile}/{$source} {$relPath} failed: " . $e->getMessage(), LOG_WARNING);
        }

        self::repository()->storeInterval($profile, $source, $ts, $totals ?? ['flows' => 0, 'packets' => 0, 'bytes' => 0], $status, $mtime, $rowsByStat);

        $ms = intdiv(hrtime(true) - $started, 1_000_000);
        $result = match ($status) {
            TopNRepository::STATUS_EMPTY => 'empty',
            TopNRepository::STATUS_FAILED => 'failed',
            default => 'ok',
        };
        ++self::$processed;
        if ($result === 'failed') {
            ++self::$failed;
        }
        self::$lastTs = time();
        self::$lastMs = $ms;
        Debug::getInstance()->log("TopN: collected {$profile}/{$source} {$relPath} in {$ms} ms ({$result})", LOG_DEBUG);

        return $result;
    }

    /** @return null|Item the oldest queued item */
    public static function next(): ?array {
        $item = array_shift(self::$queue);
        if ($item !== null) {
            unset(self::$queuedKeys[self::itemKey($item['profile'], $item['source'], $item['ts'])]);
        }

        return $item;
    }

    /**
     * One worker step: the skip rule once more (a queued file may have been collected since),
     * the collection, then the generation rule.
     *
     * @param Item $item
     *
     * @return 'empty'|'failed'|'ok'|'skipped'
     */
    public static function process(array $item): string {
        $result = 'skipped';

        try {
            $state = self::repository()->intervalState($item['profile'], $item['source'], $item['ts']);
            if (self::alreadyCollected($state, self::fileMtime($item['profile'], $item['source'], $item['relPath']))) {
                Debug::getInstance()->log("TopN: skipped {$item['profile']}/{$item['source']} {$item['relPath']}, collected already", LOG_DEBUG);
            } else {
                $result = self::collectOne($item['profile'], $item['source'], $item['relPath'], $item['ts'], $item['totals']);
            }
        } catch (\Throwable $e) {
            ++self::$failed;
            $result = 'failed';
            Debug::getInstance()->log("TopN: {$item['profile']}/{$item['source']} {$item['relPath']} not stored: " . $e->getMessage(), LOG_WARNING);
        }

        self::noteProcessed($item['profile'], $result !== 'skipped', self::$queue === []);

        return $result;
    }

    /** fillAllGaps() for one profile. */
    public static function fillGaps(string $profile, int $maxFiles = self::GAP_FILL_MAX, ?int $now = null): int {
        return self::fillDays([$profile], $maxFiles, $now ?? time());
    }

    /**
     * Queues the capture files of the retention window that have no usable interval yet, newest
     * first across every profile and source, so no source waits for another's backlog.
     */
    public static function fillAllGaps(int $maxFiles = self::GAP_FILL_MAX, ?int $now = null): int {
        return self::fillDays(self::$profiles, $maxFiles, $now ?? time());
    }

    /** Deletes what fell out of retention in small per-statistic chunks; returns the intervals removed. */
    public static function prune(int $now): int {
        if (!self::$booted) {
            return 0;
        }
        $repo = self::repository();
        $cutoff = $now - self::$retentionDays * 86400;
        $removed = 0;

        foreach ($repo->profiles() as $profile) {
            $deleted = 0;
            $oldest = $repo->oldestTs($profile);
            if ($oldest !== null && $oldest < $cutoff) {
                for ($t = $oldest - $oldest % 3600; $t < $cutoff; $t += 3600) {
                    $to = min($t + 3600, $cutoff);
                    foreach (TopNStat::cases() as $stat) {
                        $deleted += $repo->pruneChunk($profile, $t, $to, $stat);
                        self::pause(self::MAINTENANCE_PAUSE_US);
                    }
                    $intervals = $repo->pruneChunk($profile, $t, $to);
                    $removed += $intervals;
                    $deleted += $intervals;
                    self::pause(self::MAINTENANCE_PAUSE_US);
                }
            }

            foreach ([TopNRepository::TIER_1H, TopNRepository::TIER_1D] as $tier) {
                foreach (TopNStat::cases() as $stat) {
                    $first = $repo->oldestTs($profile, $stat, $tier);
                    if ($first === null || $first >= $cutoff) {
                        continue;
                    }
                    for ($t = $first - $first % 86400; $t < $cutoff; $t += 86400) {
                        $deleted += $repo->pruneChunk($profile, $t, min($t + 86400, $cutoff), $stat, $tier);
                        self::pause(self::MAINTENANCE_PAUSE_US);
                    }
                }
            }

            if ($deleted > 0) {
                self::bump($profile);
                Debug::getInstance()->log("TopN: pruned {$deleted} rows of {$profile} before " . date('Y-m-d H:i', $cutoff), LOG_DEBUG);
            }
        }

        return $removed;
    }

    /** The minute tick: the pruner hourly, the gap filler every 10 minutes while the queue is empty. */
    public static function maintain(int $now): void {
        if (!self::$booted || self::$maintaining) {
            return;
        }
        self::$maintaining = true;

        try {
            if ($now >= self::$nextPrune) {
                self::$nextPrune = $now + self::PRUNE_EVERY;
                self::prune($now);
            }
            if ($now >= self::$nextGapFill && self::$queue === []) {
                self::$nextGapFill = $now + self::GAP_FILL_EVERY;
                self::fillAllGaps(self::GAP_FILL_MAX, $now);
            }
        } catch (\Throwable $e) {
            Debug::getInstance()->log('TopN: maintenance failed: ' . $e->getMessage(), LOG_WARNING);
        } finally {
            self::$maintaining = false;
        }
    }

    /**
     * Slot rule: the worker starts nfdump only when a slot stays free for a user query after
     * it took one; with a single slot, only when nothing else runs.
     */
    public static function maySpawn(int $inUse, int $maxProcesses): bool {
        return NfdumpSlots::keepsOneFree($inUse, $maxProcesses);
    }

    /** Moves when stored top-N data of the profile changed, which invalidates cached range results. */
    public static function generation(string $profile): int {
        return self::$generations[$profile] ?? 0;
    }

    public static function queued(): int {
        return \count(self::$queue);
    }

    /**
     * For Health: `lastTs` is when the last file was collected, `lastMs` how long that took.
     *
     * @return array{queued: int, processed: int, failed: int, lastTs: int, lastMs: int}
     */
    public static function stats(): array {
        return [
            'queued' => self::queued(),
            'processed' => self::$processed,
            'failed' => self::$failed,
            'lastTs' => self::$lastTs,
            'lastMs' => self::$lastMs,
        ];
    }

    /** The worker lives only while the queue has items, so an idle collector leaves no coroutine asleep at worker exit. */
    private static function wake(): void {
        if (!self::$spawnsWorker || self::$working) {
            return;
        }
        self::$working = true;
        $cid = Coroutine::create(static function (): void {
            try {
                while (self::$booted && ($item = self::next()) !== null) {
                    self::process($item);
                    self::pause(1_000);
                }
            } finally {
                self::$working = false;
            }
        });
        if ($cid === false) {
            self::$working = false;
        }
    }

    /**
     * Day by day from today back to the retention start, the day's missing files of all given
     * profiles and sources, newest first. Returns how many were queued.
     *
     * @param list<string> $profiles
     */
    private static function fillDays(array $profiles, int $maxFiles, int $now): int {
        if (!self::$booted || $maxFiles <= 0 || $profiles === []) {
            return 0;
        }
        $cutoff = $now - self::$retentionDays * 86400;
        $repo = self::repository();
        $enqueued = 0;

        $day = (new \DateTimeImmutable('@' . $now))->setTimezone(Config::nfcapdTimezone())->setTime(0, 0);
        while (true) {
            $dayStart = $day->getTimestamp();
            $dayEnd = $day->modify('+1 day')->getTimestamp();
            if ($dayEnd <= $cutoff) {
                break;
            }
            $from = max($dayStart, $cutoff);
            $missing = [];
            foreach ($profiles as $profile) {
                foreach (Config::$settings->sources as $source) {
                    $names = NfcapdFiles::names($from, $dayEnd - 1, $source, $profile);
                    if ($names === []) {
                        continue;
                    }
                    $done = array_flip($repo->collectedTs($profile, $source, $from, $dayEnd));
                    foreach ($names as $ts) {
                        if (!isset($done[$ts]) && $ts <= $now) {
                            $missing[] = ['profile' => $profile, 'source' => $source, 'ts' => $ts];
                        }
                    }
                }
            }
            usort($missing, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
            foreach ($missing as $file) {
                if ($enqueued >= $maxFiles || \count(self::$queue) >= self::QUEUE_CAPACITY) {
                    return $enqueued;
                }
                if (self::push([...$file, 'relPath' => self::relPath($file['ts']), 'totals' => null])) {
                    ++$enqueued;
                }
            }
            $day = $day->modify('-1 day');
            self::pause(self::MAINTENANCE_PAUSE_US);
        }

        return $enqueued;
    }

    /** @param Item $item */
    private static function push(array $item): bool {
        $key = self::itemKey($item['profile'], $item['source'], $item['ts']);
        if (isset(self::$queuedKeys[$key])) {
            return false;
        }
        if (\count(self::$queue) >= self::QUEUE_CAPACITY) {
            Debug::getInstance()->log("TopN: queue full, dropped {$item['profile']}/{$item['source']} {$item['relPath']}", LOG_DEBUG);

            return false;
        }

        self::$queue[] = $item;
        self::$queuedKeys[$key] = true;
        self::wake();

        return true;
    }

    /** Generation rule: a bump every BUMP_EVERY written items of a profile, and for every profile with unbumped writes once the queue drains. */
    private static function noteProcessed(string $profile, bool $wrote, bool $drained): void {
        if ($wrote) {
            self::$unbumped[$profile] = (self::$unbumped[$profile] ?? 0) + 1;
            if (self::$unbumped[$profile] >= self::BUMP_EVERY) {
                self::bump($profile);
            }
        }
        if ($drained) {
            foreach (self::$unbumped as $unbumped => $count) {
                if ($count > 0) {
                    self::bump((string) $unbumped);
                }
            }
        }
    }

    private static function bump(string $profile): void {
        self::$generations[$profile] = (self::$generations[$profile] ?? 0) + 1;
        self::$unbumped[$profile] = 0;
    }

    /** @return Totals */
    private static function runTotals(string $profile, string $source, string $relPath): array {
        $processor = self::processor($profile, $source, $relPath);
        $processor->setOption('-I', null);
        $raw = self::execute($processor)['rawOutput'];
        if (preg_match('/^Flows:/mi', $raw) !== 1) {
            throw new \RuntimeException('nfdump -I printed no totals');
        }
        $dump = NfdumpSummary::fromStatDump($raw);

        return ['flows' => $dump['flows'], 'packets' => $dump['packets'], 'bytes' => $dump['bytes']];
    }

    /**
     * @param list<TopNStat> $stats
     *
     * @return array<int, list<array{key: string, flows: int, packets: int, bytes: int}>> keyed by TopNStat value
     */
    private static function runStats(string $profile, string $source, string $relPath, array $stats): array {
        $elements = array_map(static fn (TopNStat $stat): string => $stat->nfdumpElement(), $stats);
        $processor = self::processor($profile, $source, $relPath);
        $processor->setOption('-n', self::TOP);
        $processor->setOption('-o', 'csv');
        $processor->setOption('-s', array_map(static fn (string $element): string => $element . '/bytes', $elements));
        $raw = self::execute($processor)['rawOutput'];

        $found = MultiStatCsvParser::blockCount($raw);
        if ($found < \count($stats)) {
            throw new \RuntimeException("nfdump printed {$found} of " . \count($stats) . ' statistics');
        }

        $rows = [];
        foreach (MultiStatCsvParser::parse($raw, $elements) as $i => $block) {
            $rows[$stats[$i]->value] = array_map(
                static fn (array $row): array => ['key' => $row['key'], 'flows' => $row['flows'], 'packets' => $row['packets'], 'bytes' => $row['bytes']],
                $block,
            );
        }

        return $rows;
    }

    /** A fresh processor per run: the shared Nfdump instance races between coroutines. */
    private static function processor(string $profile, string $source, string $relPath): Processor {
        $processor = new Config::$processorClass();
        $processor->setQueryHandle(self::HANDLE);
        $processor->setProfile($profile);
        $processor->setOption('-M', $source);
        $processor->setOption('-r', $relPath);

        return $processor;
    }

    /**
     * A background run. A user query waiting for a slot always goes first, so the worker needs
     * no pause between runs to let one in.
     *
     * @return ProcessorResult
     */
    private static function execute(Processor $processor): array {
        self::awaitSlot();

        return NfdumpSlots::runAs(NfdumpSlots::BACKGROUND, static fn (): array => $processor->execute());
    }

    /**
     * Waits in 1 s steps, outside the slot queue, until a background slot is free with nobody
     * waiting before it and no bulk import holds a daemon lock: imports and alerts go first.
     */
    private static function awaitSlot(): void {
        if (Coroutine::getCid() <= 0) {
            return;
        }
        while (self::$booted
            && (NfdumpSlots::available(NfdumpSlots::BACKGROUND) === 0
                || (self::$importBusy !== null && (self::$importBusy)()))) {
            Coroutine::sleep(1);
        }
    }

    private static function pause(int $micros): void {
        if (Coroutine::getCid() > 0) {
            Coroutine::usleep($micros);
        }
    }

    private static function repository(): TopNRepository {
        return self::$repo ?? new TopNRepository(Database::shared());
    }

    private static function fileMtime(string $profile, string $source, string $relPath): int {
        $path = NfcapdFiles::sourcePath($profile, $source) . \DIRECTORY_SEPARATOR . $relPath;
        clearstatcache(true, $path);
        $mtime = is_file($path) ? @filemtime($path) : false;

        return $mtime === false ? 0 : $mtime;
    }

    /** `YYYY/MM/DD/nfcapd.YYYYMMDDHHII` of an interval start, in the nfcapd timezone. */
    private static function relPath(int $ts): string {
        $at = (new \DateTimeImmutable('@' . $ts))->setTimezone(Config::nfcapdTimezone());

        return $at->format('Y/m/d') . '/nfcapd.' . $at->format('YmdHi');
    }

    private static function itemKey(string $profile, string $source, int $ts): string {
        return $profile . "\0" . $source . "\0" . $ts;
    }
}
