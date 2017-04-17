<?php
namespace datasources;

class RRD implements \Datasource {
    private $d;
    private $client;
    private $fields = array(
        'flows',
        'flows_tcp',
        'flows_udp',
        'flows_icmp',
        'flows_other',
        'packets',
        'packets_tcp',
        'packets_udp',
        'packets_icmp',
        'packets_other',
        'traffic',
        'traffic_tcp',
        'traffic_udp',
        'traffic_icmp',
        'traffic_other',
    );

    private $layout = array(
        "0.5:1:" . ((60/(1*5))*24*90), // 90 days of 5 min samples
        "0.5:6:" . ((60/(6*5))*24*180), // 180 days of 30 min samples
        "0.5:24:" . ((60/(24*5))*24*270), // 270 days of 2 hour samples
        "0.5:288:540" // 540 days of daily samples
        // = 1080 days of samples = 3 years
    );

    function __construct() {
        $this->d = \Debug::getInstance();

        if (!function_exists('rrd_version')) {
            throw new \Exception("Please install the PECL rrd library.");
        }


    }


    public function fetch() {
        //$result = rrd_fetch( "mydata.rrd", array( "AVERAGE", "--resolution", "60", "--start", "-1d", "--end", "start+1h" ) );

    }

    /**
     * Create a new RRD file for a source
     * @param string $source e.g. gateway or server_xyz
     * @param bool $force overwrites existing RRD file if true
     * @return bool
     */
    public function create(string $source, bool $force = false) {
        $rrdFile = \Config::$path . DIRECTORY_SEPARATOR . $source . ".rrd";
        if(file_exists($rrdFile)) {
            if($force === true) unlink($rrdFile);
            else return false;
        }

        $creator = new \RRDCreator($rrdFile, "now -3y", (60*5));
        foreach($this->fields as $field) {
            $creator->addDataSource($field . ":ABSOLUTE:600:U:U");
        }
        foreach($this->layout as $rra) {
            $creator->addArchive("AVERAGE:" . $rra);
            $creator->addArchive("MAX:" . $rra);
        }

        return $creator->save();
    }

    /**
     * Write to an RRD file with supplied data
     * @param array $data
     * @return bool
     */
    public function write(array $data) {
        $rrdFile = \Config::$path . DIRECTORY_SEPARATOR . $data['source'] . ".rrd";
        if(!file_exists($rrdFile)) $this->create($data['source']);

        $updater = new \RRDUpdater($rrdFile);
        return $updater->update($data['fields'], $data['timestamp']);
    }

    /**
     * @param int $start
     * @param int $end
     * @param string $type flows/packets/traffic
     * @param array $sources
     */
    public function stats(int $start, int $end, string $type, array $sources) {

        // temporary. maybe use rrd_xport instead? has json export...
        foreach($sources as $source) {
            $rrdFile = \Config::$path . DIRECTORY_SEPARATOR . $source . ".rrd";

            $options = array("AVERAGE", "--resolution", "60", "--start", $start, "--end", $end, "--align-start", true);

            // traffic = 8* (because bits)?
            // value negations? (sign)

            $data = rrd_fetch($rrdFile, $options);

        }

    }

}