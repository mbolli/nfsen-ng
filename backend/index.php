<?php
spl_autoload_extensions(".php");
spl_autoload_register();

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

\common\Config::initialize();

if (isset($_GET['request'])) {
    $api = new \api\API();
} else {
    ini_set('max_execution_time', 600);
    $start = new DateTime();
    $start->setDate(2017, 03, 25);
    $i = new \common\Import();
    $i->start($start);
}
