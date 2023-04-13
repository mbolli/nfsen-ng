#!/usr/bin/php
<?php
/**
 * daemon for nfsen-ng.
 *
 * @phpstan-return never-return
 */
spl_autoload_register(function ($class): void {
    $class = mb_strtolower(str_replace('nfsen_ng\\', '', (string) $class));
    include_once __DIR__ . \DIRECTORY_SEPARATOR . str_replace('\\', \DIRECTORY_SEPARATOR, $class) . '.php';
});

use nfsen_ng\common\Config;
use nfsen_ng\common\Debug;
use nfsen_ng\common\Import;

ini_set('display_errors', true);
ini_set('error_reporting', \E_ALL);

$d = Debug::getInstance();
try {
    Config::initialize();
} catch (Exception $e) {
    $d->log('Fatal: ' . $e->getMessage(), \LOG_ALERT);
    exit;
}

$folder = __DIR__;
$lock_file = fopen($folder . '/nfsen-ng.pid', 'c');
$got_lock = flock($lock_file, \LOCK_EX | \LOCK_NB, $wouldblock);
if ($lock_file === false || (!$got_lock && !$wouldblock)) {
    exit(128);
}
if (!$got_lock && $wouldblock) {
    exit(129);
}

// Lock acquired; let's write our PID to the lock file for the convenience
// of humans who may wish to terminate the script.
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . \PHP_EOL);

// first import missed data if available
$start = new DateTime();
$start->setDate(date('Y') - 3, (int) date('m'), (int) date('d'));
$i = new Import();
$i->setQuiet(false);
$i->setVerbose(true);
$i->setProcessPorts(true);
$i->setProcessPortsBySource(true);
$i->setCheckLastUpdate(true);
$i->start($start);

$d->log('Starting periodic execution', \LOG_INFO);

/* @phpstan-ignore-next-line */
while (1) {
    // next import in 30 seconds
    sleep(30);

    // import from last db update
    $i->start($start);
}

// all done; blank the PID file and explicitly release the lock
ftruncate($lock_file, 0);
flock($lock_file, \LOCK_UN);
