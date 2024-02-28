<?php

include_once implode(\DIRECTORY_SEPARATOR, [__DIR__, '..', 'vendor', 'autoload.php']);

use mbolli\nfsen_ng\api\Api;

ini_set('display_errors', true);
ini_set('error_reporting', \E_ALL);

if (isset($_GET['request'])) {
    // initialize api
    $api = new Api();
}
