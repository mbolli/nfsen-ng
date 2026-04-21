<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * ImportDaemon — extracted from listen.php for embedding in app.php.
 *
 * Usage in app.php:
 *   $daemon = new ImportDaemon();
 *
 *   // Long-running bulk catch-up (run in a coroutine)
 *   \OpenSwoole\Coroutine::create(fn() => $daemon->initialImport());
 *
 *   // Ongoing inotify poll — call every second via $app->setInterval()
 *   $app->setInterval(fn() => $daemon->pollOnce(fn() => $app->broadcast('rrd:live')), 1000);
 */
class ImportDaemon {
    private readonly Debug $debug;

    /** @var resource|false inotify file descriptor */
    private mixed $inotify = false;

    /** @var array<string, array{wd: int, source: string}> path → watch info */
    private array $watches = [];

    /** @var array<string, int> fileKey → timestamp, for dedup */
    private array $processedFiles = [];

    private int $lastPathUpdate = 0;

    private ?Import $importer = null;

    public function __construct() {
        $this->debug = Debug::getInstance();
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Run the initial bulk import (catch-up for missed nfcapd files).
     * Intended to be called once from a Coroutine::create() in onStart().
     *
     * Note: If the Import class proves unsafe in a coroutine context, call this
     * directly in onStart() (blocking) before the server accepts connections.
     */
    public function initialImport(): void {
        $datasource = Config::$cfg['general']['db'];
        $importYears = Config::$cfg['db'][$datasource]['import_years'] ?? 3;

        $this->debug->log("ImportDaemon: running initial import (last {$importYears} years)", LOG_INFO);

        $start = new \DateTime();
        $start->modify('-' . $importYears . ' years');

        $importer = new Import();
        $importer->setQuiet(false);
        $importer->setVerbose(false);
        $importer->setProcessPorts(true);
        $importer->setProcessPortsBySource(true);
        $importer->setCheckLastUpdate(true);
        $importer->start($start);

        $this->debug->log('ImportDaemon: initial import done', LOG_INFO);

        // Prepare the shared importer for ongoing use (quiet mode, no re-init)
        $this->importer = new Import();
        $this->importer->setQuiet(true);
        $this->importer->setVerbose(false);

        // Set up inotify watches after the initial import completes
        $this->initWatches();
    }

    /**
     * Process one inotify poll tick. Call this from $app->setInterval(..., 1000).
     *
     * @param callable $onImportDone Invoked after each successfully imported file.
     */
    public function pollOnce(callable $onImportDone): void {
        if ($this->inotify === false) {
            // Watches not yet initialised (initial import still running)
            return;
        }

        $events = @inotify_read($this->inotify);

        if ($events) {
            $this->debug->log('ImportDaemon: received ' . count($events) . ' inotify event(s)', LOG_DEBUG);

            foreach ($events as $event) {
                $this->handleEvent($event, $onImportDone);
            }
        }

        // Refresh watches for today/tomorrow every hour
        if (time() - $this->lastPathUpdate >= 3600) {
            $this->debug->log('ImportDaemon: refreshing inotify watches', LOG_DEBUG);
            $this->addCurrentPaths();
            $this->lastPathUpdate = time();
        }
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    private function initWatches(): void {
        $inotify = @inotify_init();
        if (!is_resource($inotify)) {
            $error = error_get_last();
            $this->debug->log('ImportDaemon: failed to init inotify: ' . ($error['message'] ?? 'unknown'), LOG_ERR);

            return;
        }

        stream_set_blocking($inotify, false);
        $this->inotify = $inotify;
        $this->lastPathUpdate = time();

        $this->addCurrentPaths();
        $this->debug->log('ImportDaemon: inotify watches ready (' . count($this->watches) . ' dirs)', LOG_INFO);
    }

    private function addCurrentPaths(): void {
        foreach ($this->getCurrentPaths() as ['path' => $path, 'source' => $source]) {
            $this->addWatch($path, $source);
        }
    }

    private function addWatch(string $path, string $source): void {
        if (isset($this->watches[$path])) {
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
        $sources = Config::$cfg['general']['sources'];
        $profilePath = Config::$cfg['nfdump']['profiles-data']
            . \DIRECTORY_SEPARATOR
            . Config::$cfg['nfdump']['profile'];

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
        if (count($this->processedFiles) > 100) {
            $this->processedFiles = array_slice($this->processedFiles, -50, 50, true);
        }

        $this->debug->log("ImportDaemon: new file {$filename} (source: {$eventSource})", LOG_INFO);

        $profilePath = Config::$cfg['nfdump']['profiles-data']
            . \DIRECTORY_SEPARATOR
            . Config::$cfg['nfdump']['profile'];
        $relativePath = str_replace(
            $profilePath . \DIRECTORY_SEPARATOR . $eventSource . \DIRECTORY_SEPARATOR,
            '',
            $fullPath
        );

        $sources = Config::$cfg['general']['sources'];
        $isLastSource = $eventSource === end($sources);

        try {
            // Lazy-init importer if initialImport() hasn't run yet (edge case)
            if ($this->importer === null) {
                $this->importer = new Import();
                $this->importer->setQuiet(true);
                $this->importer->setVerbose(false);
            }

            $this->importer->importFile($relativePath, $eventSource, $isLastSource);
            $this->debug->log("ImportDaemon: processed {$filename}", LOG_INFO);
            $onImportDone();
        } catch (\Throwable $e) {
            $this->debug->log("ImportDaemon: error processing {$filename}: " . $e->getMessage(), LOG_ERR);
            $this->debug->log('ImportDaemon: ' . $e->getTraceAsString(), LOG_DEBUG);
        }
    }
}
