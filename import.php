<?php

class Import {

    private $d;
    private $src;

	function __construct() {
        $this->d = \Debug::getInstance();
        $this->d->dpr(Config::$cfg);

        // find data source
        if(Config::$cfg['db']['akumuli']['host']) {
            $this->d->dpr("Using Akumuli");
            $this->src = new datasources\Akumuli();
        } elseif (Config::$cfg['db']['akumuli']['host']) {
            $this->d->dpr("Using RRD");
            $this->src = new datasources\RRD();
        }
	}

	function start(DateTime $timestart) {
        $this->src->import($timestart);
    }
}

