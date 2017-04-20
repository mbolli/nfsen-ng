<?php
/**
 *
 *  daemon for nfsen-ng
 *  to use with systemd:
 *
    [Unit]
    Description=nfsen-ng daemon
    Requires=syslog.target network.target remote-fs.target apache2.service

    [Service]
    PIDFile=/var/run/apache2/nfsen-ng.pid
    WorkingDirectory=/var/www/html/nfsen-ng
    ExecStart=/usr/bin/php /var/www/apache2/nfsen-ng/listen.php
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

Config::initialize();
$dbg = Debug::getInstance();

$clean_folder = function($x) { return !in_array($x, array('.', '..')); };

while (1) {

    foreach(Config::$cfg['general']['sources'] as $source) {
        $source_path = Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'] . DIRECTORY_SEPARATOR . $source;

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
            // nothing to import
            continue;
        }

        $captures = scandir($source_path . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month . DIRECTORY_SEPARATOR . $day);
        $captures = array_filter($captures, $clean_folder);
        $capture = array_pop($captures);

        // parse last file's datetime. can't use filemtime as we need the datetime in the file name.
        $date = array();
        if (!preg_match('/nfcapd\.([0-9]{12})$/', $capture, $date)) continue; // nothing to import

        $file_datetime = new DateTime($date[1]);
        $db_datetime = new DateTime();
        $db_datetime->setTimestamp(Config::$db->last_update($source));
        $diff = $file_datetime->diff($db_datetime);

        echo $diff->format('%R%a days %h h %i min');

        ob_flush();
        flush();
    }

    sleep(10);
}