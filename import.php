<?php

class Import {

    private $cfg;
    private $d;
    private $src;

	function __construct() {
        $this->cfg = \Config::getInstance();
        $this->d = \Debug::getInstance();
        $this->d->dpr($this->cfg->getAll());
        $this->import();
	}

	function import() {
        // find data source
        if($this->cfg->get("db_akumuli.host")) {
            $this->d->dpr("Using Akumuli");
            $this->src = new datasources\Akumuli();
        } elseif ($this->cfg->get("db_rrd.host")) {
            $this->d->dpr("Using RRD");
            $this->src = new datasources\RRD();
        }
    }


}

