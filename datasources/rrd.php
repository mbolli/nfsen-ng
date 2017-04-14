<?php
namespace datasources;

class RRD implements \Datasource {
    private $d;
    private $client;

    function __construct() {
        $this->d = \Debug::getInstance();
    }


    public function fetch() {
        //$result = rrd_fetch( "mydata.rrd", array( "AVERAGE", "--resolution", "60", "--start", "-1d", "--end", "start+1h" ) );

    }

    public function insert() {

    }


}