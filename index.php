<?php
spl_autoload_extensions(".php");
spl_autoload_register();

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

Config::initialize();

if (isset($_GET['request'])) {
    $api = new API();
} else {
    $start = new DateTime();
    $start->setDate(2017, 03, 25);
    $i = new Import();
    $i->start($start);
}
