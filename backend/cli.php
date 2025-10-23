#!/usr/bin/env php
<?php

include_once dirname(__DIR__) . '/vendor/autoload.php';

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Import;
use mbolli\nfsen_ng\common\Misc;

$d = Debug::getInstance();

try {
    Config::initialize();
} catch (Exception $e) {
    $d->log('Fatal: ' . $e->getMessage(), \LOG_ALERT);

    exit;
}

if ($argc < 2 || in_array($argv[1], ['--help', '-help', '-h', '-?'], true)) {
    $script = $argv[0];
    $help = <<<EOT

        This is the command line interface to nfsen-ng.

        Usage:
        {$script} [options] import
        {$script} start|stop|status

        Options:
        -v  Show verbose output
        -p  Import ports data
        -ps Import ports data per source
        -f  Force overwriting database and start at the beginning

        Commands:
        import  - Import existing nfdump data to nfsen-ng.
        Notice: If you have existing nfcapd files, better do this overnight.
        start   - Start the daemon for continuous reading of new data
        stop    - Stop the daemon
        status  - Get the daemon's status

        Examples:
        {$script} -f import
        Imports fresh data for sources

        {$script} -s -p import
        Imports data for ports only

        {$script} start
        Start the daemon

        EOT;
    echo $help;
} else {
    $folder = __DIR__;
    $pidfile = $folder . '/nfsen-ng.pid';

    if (in_array('import', $argv, true)) {
        // import N years of data if available (configurable via NFSEN_IMPORT_YEARS)

        $d->log('CLI: Starting import', \LOG_INFO);
        $importYears = (int) (getenv('NFSEN_IMPORT_YEARS') ?: 3);
        $forceImport = in_array('-f', $argv, true);
        $d->log('CLI: NFSEN_IMPORT_YEARS=' . $importYears . ', force=' . ($forceImport ? 'true' : 'false'), \LOG_INFO);

        $start = new DateTime();
        $start->modify('-' . $importYears . ' years');
        $d->log('CLI: Import start date: ' . $start->format('Y-m-d H:i:s'), \LOG_INFO);

        $i = new Import();
        if (in_array('-v', $argv, true)) {
            $i->setVerbose(true);
        }
        if (in_array('-p', $argv, true)) {
            $i->setProcessPorts(true);
        }
        if (in_array('-ps', $argv, true)) {
            $i->setProcessPortsBySource(true);
        }
        if ($forceImport) {
            $i->setForce(true);
        }
        $i->start($start);
    } elseif (in_array('start', $argv, true)) {
        // start the daemon

        // Check if already running
        if (file_exists($pidfile)) {
            $pid = trim(file_get_contents($pidfile));

            if (Misc::daemonIsRunning($pid)) {
                echo 'Daemon already running, pid=' . $pid . \PHP_EOL;

                exit(0);
            }
            // Clean up stale PID file
            unlink($pidfile);
        }

        $d->log('CLI: Starting daemon...', \LOG_INFO);
        $phpBinary = trim(shell_exec('which php'));
        if (empty($phpBinary)) {
            echo 'Failed to start daemon. PHP binary not found in PATH.' . \PHP_EOL;

            exit(1);
        }
        $pid = exec('nohup ' . escapeshellarg($phpBinary) . ' ' . escapeshellarg($folder . '/listen.php') . ' > /dev/null 2>&1 & echo $!', $op, $exit);

        // Validate PID
        if (!is_numeric($pid) || (int) $pid <= 0) {
            echo 'Failed to start daemon. Could not retrieve valid PID.' . \PHP_EOL;

            exit(1);
        }

        // todo: get exit code of background process. possible at all?
        echo match ((int) $exit) {
            128 => 'Unexpected error opening or locking lock file. Perhaps you don\'t have permission to write to the lock file or its containing directory?',
            129 => 'Another instance is already running; terminating.',
            default => 'Daemon running, pid=' . $pid,
        };
        echo \PHP_EOL;
    } elseif (in_array('stop', $argv, true)) {
        // stop the daemon

        if (!file_exists($pidfile)) {
            echo 'Not running' . \PHP_EOL;

            exit;
        }
        $pid = file_get_contents($pidfile);
        $d->log('CLI: Stopping daemon', \LOG_INFO);
        exec('kill ' . $pid);
        unlink($pidfile);

        echo 'Stopped.' . \PHP_EOL;
    } elseif (in_array('status', $argv, true)) {
        // print the daemon status

        if (!file_exists($pidfile)) {
            echo 'Not running' . \PHP_EOL;

            exit;
        }
        $pid = trim(file_get_contents($pidfile));

        if (Misc::daemonIsRunning($pid)) {
            echo 'Running: ' . $pid . \PHP_EOL;
        } else {
            echo 'Not running (stale PID file: ' . $pid . ')' . \PHP_EOL;
            // Clean up stale PID file
            unlink($pidfile);
        }
    }
}
