#!/usr/bin/env php
<?php

/**
 * Import Daemon for nfsen-ng.
 *
 * Uses inotify to watch nfcapd directories for new files and processes them
 * immediately.
 *
 * Benefits:
 * - Instant processing (no polling delay)
 * - Lower CPU usage (no constant scandir() calls)
 * - Non-blocking coroutine execution
 * - Automatic handling of new date directories
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Import;

ini_set('display_errors', '1');
ini_set('error_reporting', (string) E_ALL);

$debug = Debug::getInstance();

try {
    Config::initialize();
} catch (Exception $e) {
    $debug->log('Fatal: ' . $e->getMessage(), LOG_ALERT);

    exit(1);
}

// PID file handling
$pidFile = __DIR__ . '/nfsen-ng.pid';
$lockFile = fopen($pidFile, 'c');
$gotLock = flock($lockFile, LOCK_EX | LOCK_NB, $wouldBlock);

if ($lockFile === false || (!$gotLock && !$wouldBlock)) {
    $debug->log('Failed to acquire lock file', LOG_ALERT);

    exit(128);
}

if (!$gotLock && $wouldBlock) {
    $debug->log('Another instance is already running', LOG_ALERT);

    exit(129);
}

// Write PID
ftruncate($lockFile, 0);
fwrite($lockFile, (string) getmypid() . PHP_EOL);

$debug->log('Import Daemon starting...', LOG_INFO);

// Initial import of any missed data (configurable via settings.php or NFSEN_IMPORT_YEARS env var)
$datasource = Config::$cfg['general']['db'];
$importYears = Config::$cfg['db'][$datasource]['import_years'] ?? 3;

$debug->log("Running initial import (last {$importYears} years)...", LOG_INFO);
$start = new DateTime();
$start->modify('-' . $importYears . ' years');
$sharedImporter = new Import();
$sharedImporter->setQuiet(false);
$sharedImporter->setVerbose(true);
$sharedImporter->setProcessPorts(true);
$sharedImporter->setProcessPortsBySource(true);
$sharedImporter->setCheckLastUpdate(true);
$sharedImporter->start($start);
$debug->log('Initial import completed', LOG_INFO);

// Reconfigure the importer for continuous use (quiet mode)
$sharedImporter->setQuiet(true);
$sharedImporter->setVerbose(false);
$debug->log('Importer reconfigured for file watching', LOG_INFO);

// Start watching for new nfcapd files
// Don't use Swoole coroutines - the Import class doesn't work well with them
// Just use plain PHP with non-blocking inotify
$debug->log('Starting file watcher (plain PHP mode)', LOG_INFO);

$sources = Config::$cfg['general']['sources'];
$profilePath = Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'];

$debug->log('Starting inotify watchers for nfcapd files', LOG_INFO);
$debug->log('Profile path: ' . $profilePath, LOG_INFO);
$debug->log('Sources: ' . implode(', ', $sources), LOG_INFO);

// Initialize inotify
$inotify = @inotify_init();
if ($inotify === false) { // @phpstan-ignore identical.alwaysFalse
    $error = error_get_last();
    $debug->log('FATAL: Failed to initialize inotify: ' . ($error['message'] ?? 'unknown error'), LOG_ALERT);

    exit(1);
}
$debug->log('inotify initialized successfully', LOG_INFO);
stream_set_blocking($inotify, false);

// Track watches: path => [watch_descriptor, source]
$watches = [];

// Function to add a watch for a directory
$addWatch = function (string $path, string $source) use ($inotify, &$watches, $debug): void {
    $debug->log("Attempting to watch: {$path}", LOG_INFO);

    if (isset($watches[$path])) {
        $debug->log("Already watching: {$path}", LOG_INFO);

        return; // Already watching
    }

    // Clear any cached stat information
    clearstatcache(true, $path);

    // Check if directory exists - try multiple methods due to Swoole coroutine issues
    if (!file_exists($path)) {
        $debug->log("Path does not exist (will retry later): {$path}", LOG_INFO);

        return;
    }

    // Skip the is_dir check and just try to add the watch - inotify_add_watch will fail if it's not a dir
    $debug->log("Path exists, adding inotify watch: {$path}", LOG_INFO);

    // Watch for both IN_CREATE and IN_MOVED_TO (nfcapd may write atomically via rename)
    $wd = @inotify_add_watch($inotify, $path, IN_CREATE | IN_MOVED_TO);
    if ($wd === false) {
        $debug->log("Failed to add watch for {$path}", LOG_WARNING);

        return;
    }

    $watches[$path] = ['wd' => $wd, 'source' => $source];
    $debug->log("Successfully watching: {$path} (source: {$source}, wd: {$wd})", LOG_INFO);
};

// Function to get current date paths for all sources
$getCurrentPaths = function () use ($sources, $profilePath): array {
    $paths = [];
    $now = new DateTime();

    foreach ($sources as $source) {
        // Watch today's directory
        $paths[] = [
            'path' => $profilePath . DIRECTORY_SEPARATOR . $source . DIRECTORY_SEPARATOR
                     . $now->format('Y') . DIRECTORY_SEPARATOR
                     . $now->format('m') . DIRECTORY_SEPARATOR
                     . $now->format('d'),
            'source' => $source,
        ];

        // Also watch tomorrow's directory (in case files come early)
        $tomorrow = clone $now;
        $tomorrow->modify('+1 day');
        $paths[] = [
            'path' => $profilePath . DIRECTORY_SEPARATOR . $source . DIRECTORY_SEPARATOR
                     . $tomorrow->format('Y') . DIRECTORY_SEPARATOR
                     . $tomorrow->format('m') . DIRECTORY_SEPARATOR
                     . $tomorrow->format('d'),
            'source' => $source,
        ];
    }

    return $paths;
};

// Add initial watches
$debug->log('Adding initial watches...', LOG_INFO);
$initialPaths = $getCurrentPaths();
$debug->log('Found ' . count($initialPaths) . ' paths to watch', LOG_INFO);
foreach ($initialPaths as $pathInfo) {
    $addWatch($pathInfo['path'], $pathInfo['source']);
}
$debug->log('Initial watches complete, total watches: ' . count($watches), LOG_INFO);

// Don't create Import instance here - will create on-demand for each file
// to avoid blocking in coroutine context
$debug->log('Importer will be created on-demand for each file', LOG_INFO);

$lastPathUpdate = time();
$processedFiles = []; // Track recently processed files to avoid duplicates

$debug->log('Import daemon ready, watching for nfcapd files...', LOG_INFO);    // Main event loop
while (true) { // @phpstan-ignore while.alwaysTrue
    // Read inotify events (non-blocking)
    $events = @inotify_read($inotify);

    if ($events) {
        $debug->log('Received ' . count($events) . ' inotify events', LOG_INFO);
        foreach ($events as $event) {
            $filename = $event['name'];

            // Only process nfcapd files
            if (!preg_match('/^nfcapd\.\d{12}$/', $filename)) {
                continue;
            }

            // Find which path this event came from
            $eventPath = null;
            $eventSource = null;
            foreach ($watches as $path => $watchInfo) {
                if ($watchInfo['wd'] === $event['wd']) {
                    $eventPath = $path;
                    $eventSource = $watchInfo['source'];

                    break;
                }
            }

            if ($eventPath === null) {
                continue;
            }

            $fullPath = $eventPath . DIRECTORY_SEPARATOR . $filename;

            // Avoid processing the same file multiple times (can get multiple events)
            $fileKey = $fullPath . ':' . filemtime($fullPath);
            if (isset($processedFiles[$fileKey])) {
                continue;
            }
            $processedFiles[$fileKey] = time();

            // Clean up old entries from processedFiles (keep last 100)
            if (count($processedFiles) > 100) {
                $processedFiles = array_slice($processedFiles, -50, 50, true);
            }

            $debug->log("New nfcapd file detected: {$filename} (source: {$eventSource})", LOG_INFO);

            // Extract relative path for Import class (should be like: 2025/10/24/nfcapd.202510241025)
            // Remove both the profile path AND the source name
            $relativePath = str_replace($profilePath . DIRECTORY_SEPARATOR . $eventSource . DIRECTORY_SEPARATOR, '', $fullPath);

            // Reuse the shared importer instance
            try {
                $debug->log("Calling importFile for {$filename}...", LOG_INFO);
                $isLastSource = $eventSource === end($sources);
                $sharedImporter->importFile($relativePath, $eventSource, $isLastSource);
                $debug->log("Processed: {$filename}", LOG_INFO);
            } catch (Throwable $e) {
                $debug->log("Error processing {$filename}: " . $e->getMessage(), LOG_ERR);
                $debug->log('Stack: ' . $e->getTraceAsString(), LOG_ERR);
            }
        }
    }

    // Check for new subdirectories every hour (since we watch tomorrow's dir in advance)
    if (time() - $lastPathUpdate >= 3600) {
        $debug->log('Refreshing watches for today and tomorrow...', LOG_INFO);

        // Get current paths (today + tomorrow) and add any that aren't watched yet
        $currentPaths = $getCurrentPaths();
        foreach ($currentPaths as $pathInfo) {
            $addWatch($pathInfo['path'], $pathInfo['source']);
        }

        $lastPathUpdate = time();
        $debug->log('Watch refresh complete, total watches: ' . count($watches), LOG_INFO);
    }

    // Sleep 1 second before next iteration
    sleep(1);
}

// Cleanup (won't reach here normally, but good practice)
ftruncate($lockFile, 0); // @phpstan-ignore deadCode.unreachable
flock($lockFile, LOCK_UN);
