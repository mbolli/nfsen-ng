<?php

spl_autoload_register(function ($class): void {
    $class = mb_strtolower(str_replace('nfsen_ng\\', '', (string) $class));
    include_once __DIR__ . \DIRECTORY_SEPARATOR . str_replace('\\', \DIRECTORY_SEPARATOR, $class) . '.php';
});

use nfsen_ng\api\Api;

ini_set('display_errors', true);
ini_set('error_reporting', \E_ALL);

if (isset($_GET['request'])) {
    // initialize api
    $api = new Api();
}
