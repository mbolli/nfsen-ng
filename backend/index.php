<?php
spl_autoload_extensions(".php");
spl_autoload_register();

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

\common\Config::initialize();

if (isset($_GET['request'])) {

    // initialize api
    $api = new \api\API();
}

if (isset($_GET['import'])) {

    // perform import of last 3 years
    ini_set('max_execution_time', 3600);
    $start = new DateTime();
    $start->setDate(date('Y')-3, date('m'), date('d'));
    $i = new \common\Import();
    $i->start($start);
}
