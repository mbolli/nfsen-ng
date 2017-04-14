<?php
/**
 * config file for nfsen-ng
 */

$nfsen_config = array(
    'general' => array(

    ),
    'nfdump' => array(
        'binary' => '/usr/bin/nfdump',
        'profiles-data' => '/srv/nfsen/profiles-data',
        'profile' => 'live',
    ),
    'db' => array(
        'akumuli' => array(
            'host' => 'localhost',
            'port' => 8282,
        ),
        'rrd' => array()
    )
);
