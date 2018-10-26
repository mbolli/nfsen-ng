#!/usr/bin/php
<?php
/**
 *
 *  daemon for nfsen-ng
 *  to be used with systemd:
 *  todo: test this
 *
    [Unit]
    Description=nfsen-ng daemon
    Requires=syslog.target network.target remote-fs.target apache2.service

    [Service]
    PIDFile=/var/run/apache2/nfsen-ng.pid
    WorkingDirectory=/var/www/html/nfsen-ng/backend/
    ExecStart=/usr/bin/php /var/www/apache2/nfsen-ng/backend/listen.php
    Restart=always
    Type=simple
    KillMode=process
    User=www-data
    Group=www-data
    StandardOutput=null
    StandardError=syslog
    ProtectSystem=full
    ProtectHome=true
    PrivateTmp=true

    [Install]
    WantedBy=multi-user.target

 */

spl_autoload_register(function($class) {
	$class = strtolower(str_replace('nfsen_ng\\', '', $class));
	include_once __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
});

use \nfsen_ng\common\{Debug, Config, Import};

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

$d = Debug::getInstance();
try {
	Config::initialize();
} catch (Exception $e) {
	$d->log('Fatal: ' . $e->getMessage(), LOG_ALERT);
	exit();
}

$folder = dirname(__FILE__);
$lock_file = fopen($folder . '/nfsen-ng.pid', 'c');
$got_lock = flock($lock_file, LOCK_EX | LOCK_NB, $wouldblock);
if ($lock_file === false || (!$got_lock && !$wouldblock)) {
    exit(128);
} elseif (!$got_lock && $wouldblock) {
    exit(129);
}

// Lock acquired; let's write our PID to the lock file for the convenience
// of humans who may wish to terminate the script.
ftruncate($lock_file, 0);
fwrite($lock_file, getmypid() . PHP_EOL);

// first import missed data if available
$start = new DateTime();
$start->setDate(date('Y') - 3, date('m'), date('d'));
$i = new Import();
$i->setQuiet(false);
$i->setVerbose(true);
$i->setProcessPorts(true);
$i->setProcessPortsBySource(true);
$i->setCheckLastUpdate(true);
$i->start($start);

/**
 * remove non-interesting files from folder list
 * @param $x
 * @return bool
 */
$clean_folder = function($x) { return is_numeric($x) || preg_match('/nfcapd\.([0-9]{12})$/', $x); };
$last_import = 0;

$d->log('Starting periodic execution', LOG_INFO);

while (1) {
 
	// next import in 30 seconds
	sleep(30);

    // import from last db update
    $i->start($start);
    
}

// all done; blank the PID file and explicitly release the lock
ftruncate($lock_file, 0);
flock($lock_file, LOCK_UN);