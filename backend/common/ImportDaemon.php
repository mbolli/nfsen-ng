<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

/**
 * ImportDaemon, extracted from listen.php for embedding in app.php.
 *
 * Usage in app.php:
 *   $daemon = new ImportDaemon();
 *
 *   // Long-running bulk catch-up (run in a coroutine)
 *   \OpenSwoole\Coroutine::create(fn() => $daemon->initialImport());
 *
 *   // Ongoing inotify poll: call every second via $app->setInterval()
 *   $app->setInterval(fn() => $daemon->pollOnce(fn() => ImportDaemon::broadcast($app, 'rrd:live')), 1000);
 */
class ImportDaemon {
    /** App-global outcome of the last import pass: complete, cancelled or failed ('' while none). */
    public const string OUTCOME_STATE = 'import_outcome';

    /** Import progress and rrd:live re-render every tab, so each scope goes out at most this often. */
    public const int BROADCAST_EVERY_MS = 250;

    /** App-global Import log: its newest LOG_KEEP entries, and how many of each kind came in. */
    public const string LOG_STATE = 'import_log';

    public const string LOG_COUNTS_STATE = 'import_log_counts';

    public const int LOG_KEEP = 100;

    /** @var array<string, int> scope → hrtime (ns) at which its last broadcast finished rendering */
    private static array $broadcastDone = [];

    /** @var array<string, int> scope → timer of the broadcast a throttled call deferred */
    private static array $broadcastTimers = [];

    /** @var array<string, true> scopes whose fan-out is rendering */
    private static array $broadcastRendering = [];

    /** @var array<string, true> scopes a throttled call asked for while their fan-out rendered */
    private static array $broadcastAfter = [];

    private readonly Debug $debug;

    /** @var false|resource inotify file descriptor */
    private mixed $inotify = false;

    /** @var array<string, array{wd: int, source: string}> path → watch info */
    private array $watches = [];

    /** @var array<string, int> fileKey → timestamp, for dedup */
    private array $processedFiles = [];

    private int $lastPathUpdate = 0;

    private ?Import $importer = null;

    /**
     * Set by UI-triggered import actions to prevent pollOnce() from interleaving
     * mid-scan (critical for force-rescan: reset() sets last_update=0, then a
     * stray poll write would advance last_update to now, breaking all subsequent
     * historical writes).
     */
    private bool $importLocked = false;

    /** Unix timestamp of the last inotify-triggered import, 0 if none yet. */
    private int $lastAutoImportTime = 0;

    /** Timer ticks overlap while an import yields, so a tick that finds one running returns. */
    private bool $polling = false;

    private bool $stopped = false;

    private readonly string $profile;

    public function __construct(string $profile = '') {
        $this->profile = $profile !== '' ? $profile : Config::$settings->nfdumpProfile;
        $this->debug = Debug::getInstance();
    }

    public function lock(): void {
        $this->importLocked = true;
    }

    public function unlock(): void {
        $this->importLocked = false;
    }

    public function isLocked(): bool {
        return $this->importLocked;
    }

    /** True once inotify watches are set up (initial import has completed). */
    public function isDaemonReady(): bool {
        return $this->inotify !== false;
    }

    /** Number of directory paths currently watched by inotify. */
    public function getWatchCount(): int {
        return \count($this->watches);
    }

    /** Unix timestamp of the most recent inotify-triggered import, 0 if none. */
    public function getLastAutoImportTime(): int {
        return $this->lastAutoImportTime;
    }

    /** The nfdump profile this daemon is monitoring. */
    public function getProfile(): string {
        return $this->profile;
    }

    /**
     * From the worker's shutdown: closes the inotify descriptor and turns pollOnce() into a
     * no-op. A running import ends through its cancel check, which also reads isShuttingDown().
     */
    public function stop(): void {
        $this->stopped = true;
        if (\is_resource($this->inotify)) {
            fclose($this->inotify);
        }
        $this->inotify = false;
        $this->watches = [];
    }

    public function isStopped(): bool {
        return $this->stopped;
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Adds Debug entries to the Import log. A pass can warn about every file, so only the newest
     * LOG_KEEP stay and the rest are counted.
     *
     * @param array<int, array{ts: int, level: int, msg: string}> $entries
     */
    public static function appendLog(Via $app, array $entries): void {
        if ($entries === []) {
            return;
        }
        $counts = self::logCounts($app);
        $app->setGlobalState(self::LOG_STATE, \array_slice([...self::log($app), ...array_values($entries)], -self::LOG_KEEP));
        $app->setGlobalState(self::LOG_COUNTS_STATE, [
            'total' => $counts['total'] + \count($entries),
            'errors' => $counts['errors'] + \count(array_filter($entries, static fn (array $e): bool => $e['level'] <= LOG_ERR)),
        ]);
    }

    /** Empties the Import log for a new pass. */
    public static function clearLog(Via $app): void {
        $app->setGlobalState(self::LOG_STATE, []);
        $app->setGlobalState(self::LOG_COUNTS_STATE, ['total' => 0, 'errors' => 0]);
    }

    /**
     * The entries the Import log keeps, oldest first.
     *
     * @return list<array<mixed>>
     */
    public static function log(Via $app): array {
        $log = $app->globalState(self::LOG_STATE, []);

        return \is_array($log) ? array_values(array_filter($log, \is_array(...))) : [];
    }

    /**
     * Every entry of the pass, kept or not, and its errors.
     *
     * @return array{total: int, errors: int}
     */
    public static function logCounts(Via $app): array {
        $counts = $app->globalState(self::LOG_COUNTS_STATE, null);
        if (\is_array($counts) && \is_int($counts['total'] ?? null) && \is_int($counts['errors'] ?? null)) {
            return ['total' => $counts['total'], 'errors' => $counts['errors']];
        }
        $log = self::log($app);

        return ['total' => \count($log), 'errors' => \count(array_filter($log, static fn (array $e): bool => (int) ($e['level'] ?? LOG_WARNING) <= LOG_ERR))];
    }

    /**
     * At most one broadcast of $scope per BROADCAST_EVERY_MS after the last one rendered; a call
     * inside that window defers one that renders the state at its end. $now sends at once.
     */
    public static function broadcast(Via $app, string $scope, bool $now = false): void {
        // Without an event loop (CLI, tests) nothing would send a deferred broadcast.
        if ($now || Coroutine::getCid() <= 0) {
            self::dropDeferred($scope);
            self::send($app, $scope);

            return;
        }
        if (isset(self::$broadcastRendering[$scope])) {
            self::$broadcastAfter[$scope] = true;

            return;
        }
        $wait = (self::$broadcastDone[$scope] ?? 0) + self::BROADCAST_EVERY_MS * 1_000_000 - (int) hrtime(true);
        if ($wait <= 0) {
            self::dropDeferred($scope);
            self::send($app, $scope);

            return;
        }
        self::defer($app, $scope, $wait);
    }

    /** From the worker's shutdown, and for tests: drops every deferred broadcast and the history. */
    public static function resetBroadcasts(): void {
        foreach (array_keys(self::$broadcastTimers) as $scope) {
            self::dropDeferred($scope);
        }
        self::$broadcastDone = [];
        self::$broadcastAfter = [];
    }

    /** Whether a throttled call left a broadcast of $scope waiting for its window. */
    public static function broadcastDeferred(string $scope): bool {
        return isset(self::$broadcastTimers[$scope]) || isset(self::$broadcastAfter[$scope]);
    }

    /**
     * Run the initial bulk import (catch-up for missed nfcapd files).
     * Intended to be called once from a Coroutine::create() in onStart().
     *
     * Note: If the Import class proves unsafe in a coroutine context, call this
     * directly in onStart() (blocking) before the server accepts connections.
     */
    public function initialImport(?callable $onProgress = null, ?callable $shouldCancel = null): void {
        $this->lock();

        try {
            $importYears = Config::$settings->importYears();

            $this->debug->log("ImportDaemon: running initial import (last {$importYears} years)", LOG_INFO);

            $start = new \DateTime();
            $start->modify('-' . $importYears . ' years');

            $importer = new Import();
            $importer->setQuiet(false);
            $importer->setVerbose(false);
            $importer->setProcessPorts(true);
            $importer->setProcessPortsBySource(true);
            $importer->setCheckLastUpdate(true);
            $importer->setProfile($this->profile);
            $importer->start($start, $onProgress, null, $shouldCancel);

            $this->debug->log('ImportDaemon: initial import done', LOG_INFO);
        } finally {
            $this->unlock();
        }

        // Prepare the shared importer for ongoing use (quiet mode, no re-init)
        // (only reached if start() did not throw)
        $this->importer = $this->newOngoingImporter();

        // Set up inotify watches after the initial import completes
        $this->initWatches();
    }

    /**
     * Set up inotify watches without running any import.
     * Used on startup when the database already has data (gap-fill handled by
     * initialImport) OR when the user has explicitly skipped the initial import.
     * After this returns, pollOnce() will begin responding to inotify events.
     */
    public function setupWatchesOnly(): void {
        if ($this->stopped) {
            return;
        }
        $this->importer = $this->newOngoingImporter();

        $this->initWatches();
        $this->debug->log('ImportDaemon: inotify watches ready (startup import skipped)', LOG_INFO);
    }

    /**
     * Process one inotify poll tick. Call this from $app->setInterval(..., 1000).
     *
     * @param callable(string $source, int $fileTs, bool $isLastSource): void $onImportDone invoked after each imported file
     */
    public function pollOnce(callable $onImportDone): void {
        if ($this->inotify === false || $this->stopped) {
            // No watches yet (the initial import is still running), or stopped.
            return;
        }

        if ($this->importLocked) {
            // A UI-triggered import is running; consume and discard inotify
            // events to avoid advancing RRD last_update ahead of the scan.
            @inotify_read($this->inotify);

            return;
        }

        if ($this->polling) {
            return;
        }
        $this->polling = true;

        try {
            $this->poll($onImportDone);
        } finally {
            $this->polling = false;
        }
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    private static function dropDeferred(string $scope): void {
        unset(self::$broadcastAfter[$scope]);
        if (isset(self::$broadcastTimers[$scope])) {
            Timer::clear(self::$broadcastTimers[$scope]); // @phpstan-ignore arguments.count (OpenSwoole 26.2's arginfo leaves out the timer id)
            unset(self::$broadcastTimers[$scope]);
        }
    }

    private static function defer(Via $app, string $scope, int $waitNs): void {
        if (isset(self::$broadcastTimers[$scope]) || $app->isShuttingDown()) {
            return;
        }
        $timer = Timer::after(intdiv($waitNs + 999_999, 1_000_000), static function () use ($app, $scope): void {
            unset(self::$broadcastTimers[$scope]);
            if ($app->isShuttingDown()) {
                return;
            }

            // Nothing above this timer would catch it, and an uncaught throw ends the worker.
            try {
                // OpenSwoole's timers can fire a millisecond or two early, so the window is checked again.
                self::broadcast($app, $scope);
            } catch (\Throwable $e) {
                Debug::getInstance()->log("Broadcast of {$scope} failed: " . $e->getMessage(), LOG_WARNING);
            }
        });
        if (\is_int($timer)) {
            self::$broadcastTimers[$scope] = $timer;
        } else {
            self::send($app, $scope);
        }
    }

    private static function send(Via $app, string $scope): void {
        if ($app->getClients() === []) {
            return;
        }
        // php-via renders the scope once more when the running fan-out ends.
        if (isset(self::$broadcastRendering[$scope])) {
            $app->broadcast($scope);

            return;
        }
        self::$broadcastRendering[$scope] = true;

        try {
            $app->broadcast($scope);
        } finally {
            unset(self::$broadcastRendering[$scope]);
            self::$broadcastDone[$scope] = (int) hrtime(true);
            if (isset(self::$broadcastAfter[$scope])) {
                unset(self::$broadcastAfter[$scope]);
                self::defer($app, $scope, self::BROADCAST_EVERY_MS * 1_000_000);
            }
        }
    }

    private function poll(callable $onImportDone): void {
        if ($this->inotify === false) {
            return;
        }

        $events = @inotify_read($this->inotify);

        if ($events) {
            $this->debug->log('ImportDaemon: received ' . \count($events) . ' inotify event(s)', LOG_DEBUG);

            foreach ($events as $event) {
                if ($this->stopped) {
                    return;
                }
                $this->handleEvent($event, $onImportDone);
            }
        }

        // Refresh watches for today/tomorrow every hour, or immediately when
        // no directories are watched yet (e.g. today's dir appeared after startup).
        if (\count($this->watches) === 0 || time() - $this->lastPathUpdate >= 3600) {
            $this->debug->log('ImportDaemon: refreshing inotify watches', LOG_DEBUG);
            $before = $this->watches;
            $this->addCurrentPaths();
            $this->lastPathUpdate = time();

            // Import any files that arrived in a newly-watched directory before
            // the watch was registered (e.g. the first nfcapd slot(s) after midnight).
            foreach (array_diff_key($this->watches, $before) as $path => $info) {
                $this->catchUpDirectory($path, $info['source'], $onImportDone);
            }
        }
    }

    /**
     * Scan a directory for nfcapd files that already exist on disk and import
     * any that have not been processed yet. Called after a new inotify watch is
     * registered so that files written between directory creation and watch
     * registration are not silently skipped.
     */
    private function catchUpDirectory(string $path, string $source, callable $onImportDone): void {
        $profilePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . $this->profile;
        $sources = Config::$settings->sources;
        $isLastSource = $source === end($sources);

        foreach (scandir($path) ?: [] as $filename) {
            if ($this->stopped) {
                return;
            }
            if (!preg_match('/^nfcapd\.\d{12}$/', $filename)) {
                continue;
            }

            $fullPath = $path . \DIRECTORY_SEPARATOR . $filename;
            $fileKey = $fullPath . ':' . @filemtime($fullPath);
            if (isset($this->processedFiles[$fileKey])) {
                continue;
            }
            $this->processedFiles[$fileKey] = time();

            $relativePath = str_replace(
                $profilePath . \DIRECTORY_SEPARATOR . $source . \DIRECTORY_SEPARATOR,
                '',
                $fullPath
            );

            try {
                $this->importTimed($relativePath, $source, $isLastSource);
                $this->debug->log("ImportDaemon: catch-up imported {$filename} (source: {$source})", LOG_INFO);
                $this->lastAutoImportTime = time();
                $onImportDone($source, self::fileTs($filename), $isLastSource);
            } catch (\Throwable $e) {
                $this->debug->log("ImportDaemon: catch-up error {$filename}: " . $e->getMessage(), LOG_ERR);
            }
        }
    }

    /** Interval start of an nfcapd.YYYYMMDDHHII file, read in the nfcapd timezone. */
    private static function fileTs(string $filename): int {
        $dt = \DateTimeImmutable::createFromFormat('!YmdHi', substr($filename, -12), Config::nfcapdTimezone());

        return $dt === false ? 0 : $dt->getTimestamp();
    }

    /**
     * Imports one file with the ongoing importer (created lazily when initialImport() has not
     * run) and records the import rate when the file was written.
     */
    private function importTimed(string $relativePath, string $source, bool $isLastSource): void {
        $this->importer ??= $this->newOngoingImporter();
        $started = hrtime(true);
        if ($this->importer->importFile($relativePath, $source, $isLastSource)) {
            ImportStats::record($this->profile, $source, $relativePath, intdiv(hrtime(true) - $started, 1_000_000));
        }
    }

    /**
     * Importer used for every ongoing (inotify-driven) import.
     *
     * Port processing must be enabled here exactly as it is for the bulk import in
     * initialImport(): without it importFile() writes source.rrd and the all-sources
     * port.rrd but never source_port.rrd, so the per-source port graphs (which is
     * what the ports view reads) stop at the last bulk import and stay empty (#173).
     */
    private function newOngoingImporter(): Import {
        $importer = new Import();
        $importer->setQuiet(true);
        $importer->setVerbose(false);
        $importer->setProcessPorts(true);
        $importer->setProcessPortsBySource(true);
        $importer->setProfile($this->profile);

        return $importer;
    }

    private function initWatches(): void {
        // An initial import the stop cancelled returns here; it must not watch again.
        if ($this->stopped) {
            return;
        }

        $inotify = @inotify_init();
        if (!\is_resource($inotify)) {
            $error = error_get_last();
            $this->debug->log('ImportDaemon: failed to init inotify: ' . ($error['message'] ?? 'unknown'), LOG_ERR);

            return;
        }

        stream_set_blocking($inotify, false);
        $this->inotify = $inotify;
        $this->lastPathUpdate = time();

        $this->addCurrentPaths();
        $this->debug->log('ImportDaemon: inotify watches ready (' . \count($this->watches) . ' dirs)', LOG_INFO);
    }

    private function addCurrentPaths(): void {
        foreach ($this->getCurrentPaths() as ['path' => $path, 'source' => $source]) {
            $this->addWatch($path, $source);
        }
    }

    private function addWatch(string $path, string $source): void {
        if ($this->inotify === false || isset($this->watches[$path])) {
            return;
        }

        clearstatcache(true, $path);
        if (!file_exists($path)) {
            $this->debug->log("ImportDaemon: path does not exist yet: {$path}", LOG_DEBUG);

            return;
        }

        $wd = @inotify_add_watch($this->inotify, $path, IN_CREATE | IN_MOVED_TO);
        if ($wd === false) {
            $this->debug->log("ImportDaemon: failed to watch {$path}", LOG_WARNING);

            return;
        }

        $this->watches[$path] = ['wd' => $wd, 'source' => $source];
        $this->debug->log("ImportDaemon: watching {$path} (source: {$source})", LOG_DEBUG);
    }

    /** @return array<int, array{path: string, source: string}> */
    private function getCurrentPaths(): array {
        $sources = Config::$settings->sources;
        $profilePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . $this->profile;

        $paths = [];
        $now = new \DateTime();
        $tomorrow = (clone $now)->modify('+1 day');

        foreach ($sources as $source) {
            $base = $profilePath . \DIRECTORY_SEPARATOR . $source . \DIRECTORY_SEPARATOR;
            $paths[] = ['path' => $base . $now->format('Y/m/d'), 'source' => $source];
            $paths[] = ['path' => $base . $tomorrow->format('Y/m/d'), 'source' => $source];
        }

        return $paths;
    }

    /** @param array{wd: int, mask: int, cookie: int, name: string} $event */
    private function handleEvent(array $event, callable $onImportDone): void {
        $filename = $event['name'];

        if (!preg_match('/^nfcapd\.\d{12}$/', $filename)) {
            return;
        }

        // Resolve event to a source path
        $eventPath = null;
        $eventSource = null;
        foreach ($this->watches as $path => $info) {
            if ($info['wd'] === $event['wd']) {
                $eventPath = $path;
                $eventSource = $info['source'];

                break;
            }
        }

        if ($eventPath === null || $eventSource === null) {
            return;
        }

        $fullPath = $eventPath . \DIRECTORY_SEPARATOR . $filename;

        // Dedup: same file can trigger multiple inotify events
        $fileKey = $fullPath . ':' . @filemtime($fullPath);
        if (isset($this->processedFiles[$fileKey])) {
            return;
        }
        $this->processedFiles[$fileKey] = time();

        // Trim dedup cache to avoid unbounded growth
        if (\count($this->processedFiles) > 100) {
            $this->processedFiles = \array_slice($this->processedFiles, -50, 50, true);
        }

        $this->debug->log("ImportDaemon: new file {$filename} (source: {$eventSource})", LOG_INFO);

        $profilePath = Config::$settings->nfdumpProfilesData
            . \DIRECTORY_SEPARATOR
            . $this->profile;
        $relativePath = str_replace(
            $profilePath . \DIRECTORY_SEPARATOR . $eventSource . \DIRECTORY_SEPARATOR,
            '',
            $fullPath
        );

        $sources = Config::$settings->sources;
        $isLastSource = $eventSource === end($sources);

        try {
            $this->importTimed($relativePath, $eventSource, $isLastSource);
            $this->debug->log("ImportDaemon: processed {$filename}", LOG_INFO);
            $this->lastAutoImportTime = time();
            $onImportDone($eventSource, self::fileTs($filename), $isLastSource);
        } catch (\Throwable $e) {
            $this->debug->log("ImportDaemon: error processing {$filename}: " . $e->getMessage(), LOG_ERR);
            $this->debug->log('ImportDaemon: ' . $e->getTraceAsString(), LOG_DEBUG);
        }
    }
}
