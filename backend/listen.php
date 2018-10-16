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

spl_autoload_extensions(".php");
spl_autoload_register();

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

\common\Config::initialize();
$dbg = \common\Debug::getInstance();

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
$i = new \common\Import();
$i->setQuiet(true);
$i->setProcessPorts(true);
$i->start($start);

/**
 * remove non-interesting files from folder list
 * @param $x
 * @return bool
 */
$clean_folder = function($x) { return is_numeric($x) || preg_match('/nfcapd\.([0-9]{12})$/', $x); };
$last_import = 0;

$dbg->log('Starting periodic execution', LOG_INFO);

while (1) {

    foreach(\common\Config::$cfg['general']['sources'] as $key => $source) {
        $source_path = \common\Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . \common\Config::$cfg['nfdump']['profile'] . DIRECTORY_SEPARATOR . $source;

        $years = scandir($source_path);
        $years = array_filter($years, $clean_folder);
        $year = array_pop($years); // most recent year

        $months = scandir($source_path . DIRECTORY_SEPARATOR . $year);
        $months = array_filter($months, $clean_folder);
        $month = array_pop($months); // most recent month

        $days = scandir($source_path . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month);
        $days = array_filter($days, $clean_folder);
        $day = array_pop($days); // most recent day

        if ($year === null || $month === null || $day === null) {
            // nothing to import, try next source
            continue;
        }

        $captures = scandir($source_path . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $day);
        $captures = array_filter($captures, $clean_folder);
        $capture = array_pop($captures);

        // parse last file's datetime. can't use filemtime as we need the datetime in the file name.
        $date = array();
        if (!preg_match('/nfcapd\.([0-9]{12})$/', $capture, $date)) continue; // nothing to import

        $file_datetime = new \DateTime($date[1]);

        // get last updated time from database
        $last_update_db = \common\Config::$db->last_update($source);
        $last_update = null;
        if ($last_update_db !== false && $last_update_db !== 0) {
            $last_update = new \DateTime();
            $last_update->setTimestamp($last_update_db);
        }

        // prevent attempting to import the same file again
        if ($file_datetime <= $last_update) continue;

        $dbg->log('Importing from ' . $source . ': ' . $file_datetime->format('Y-m-d H:i'), LOG_INFO);

        // import current nfcapd file
        $last = ($key === count(\common\Config::$cfg['general']['sources'])-1);
        $i->import_file($capture, $source, $last);
    }

    sleep(10);
}

// all done; we blank the PID file and explicitly release the lock
ftruncate($lock_file, 0);
flock($lock_file, LOCK_UN);