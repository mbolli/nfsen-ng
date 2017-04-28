<?php
namespace datasources;

class RRD implements Datasource {
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
        'bytes',
        'bytes_tcp',
        'bytes_udp',
        'bytes_icmp',
        'bytes_other',
    );

    private $layout = array(
        "0.5:1:" . ((60/(1*5))*24*90), // 90 days of 5 min samples
        "0.5:6:" . ((60/(6*5))*24*180), // 180 days of 30 min samples
        "0.5:24:" . ((60/(24*5))*24*270), // 270 days of 2 hour samples
        "0.5:288:540" // 540 days of daily samples
        // = 1080 days of samples = 3 years
    );

    function __construct() {
        $this->d = \common\Debug::getInstance();

        if (!function_exists('rrd_version')) {
            throw new \Exception("Please install the PECL rrd library.");
        }

    }

    /**
     * Gets the timestamps of the first and last entry of this specific source
     * @param string $source
     * @return array
     */
    public function date_boundaries(string $source) : array {
        $rrdFile = $this->get_data_path($source);
        return array(rrd_first($rrdFile), rrd_last($rrdFile));
    }

    /**
     * Gets the timestamp of the last update of this specific source
     * @param string $source
     * @return int timestamp
     */
    public function last_update(string $source) : int {
        $rrdFile = $this->get_data_path($source);
        return rrd_last($rrdFile);
    }


    /**
     * Create a new RRD file for a source
     * TODO: extend for ports?
     * @param string $source e.g. gateway or server_xyz
     * @param bool $force overwrites existing RRD file if true
     * @return bool
     */
    public function create(string $source, bool $force = false) {
        $rrdFile = $this->get_data_path($source);
        if(file_exists($rrdFile)) {
            if($force === true) unlink($rrdFile);
            else {
                $this->d->log('Error creating ' . $rrdFile, LOG_ERR);
                return false;
            }
        }

        $creator = new \RRDCreator($rrdFile, "now -3y", (60*5));
        foreach($this->fields as $field) {
            $creator->addDataSource($field . ":ABSOLUTE:300:U:U");
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
        $rrdFile = $this->get_data_path($data['source']);
        $ts_last = rrd_last($rrdFile);
        $nearest = (int)ceil(($data['date_timestamp'])/300)*300;

        // create new database if not existing
        if(!file_exists($rrdFile)) $this->create($data['source']);

        // return false if the database's last entry is newer
        if($data['date_timestamp'] <= $ts_last) return false;

        // write data
        $updater = new \RRDUpdater($rrdFile);
        return $updater->update($data['fields'], $nearest);
    }

    /**
     * @param int $start
     * @param int $end
     * @param array $sources
     * @param array $protocols
     * @param string $type flows/packets/traffic
     * @return array|string
     */
    public function get_graph_data(int $start, int $end, array $sources, array $protocols, string $type) {

        $options = array(
            '--start', $start,
            '--end', $end,
            '--maxrows', 300, // number of values. works like the width value (in pixels) in rrd_graph
            // '--step', 1200, // by default, rrdtool tries to get data for each row. if you want rrdtool to get data at a one-hour resolution, set step to 3600.
            '--json'
        );

        if (empty($protocols)) $protocols = array('tcp', 'udp', 'icmp', 'other');
        if (empty($sources)) $sources = array('swi6', 'gate');

        if (count($sources) === 1 && count($protocols) > 1) {
            foreach ($protocols as $protocol) {
                foreach ($sources as $source) {
                    $rrdFile = $this->get_data_path($source);
                    $options[] = 'DEF:data' . $source . $protocol . '=' . $rrdFile . ':' . $type . '_' . $protocol . ':AVERAGE';
                    //$options[] = 'CDEF:' . $source . '=data' . $source . ',1,*';
                    $options[] = 'XPORT:data' . $source . $protocol . ':' . $source . '_' . $type . '_' . $protocol;
                }
            }
        } elseif (count($sources) > 1 && count($protocols) === 1) {
            foreach ($sources as $source) {
                $rrdFile = $this->get_data_path($source);
                $options[] = 'DEF:data' . $source . '=' . $rrdFile . ':' . $type . ':AVERAGE';
                //$options[] = 'CDEF:' . $source . '=data' . $source . ',1,*';
                $options[] = 'XPORT:data' . $source . ':' . $source . '_' . $type . '_' . $protocols[0];
            }
        }

        $data = rrd_xport($options);

        if (!is_array($data)) return rrd_error();

        // remove invalid numbers and create processable array
        $output = array(
            'data' => array(),
            'start' => $data['start'],
            'end' => $data['end'],
            'step' => $data['step'],
            'legend' => array(),
        );
        foreach ($data['data'] as $source) {
            $output['legend'][] = $source['legend'];
            foreach ($source['data'] as $date => $measure) {
                // ignore non-valid measures
                if (is_nan($measure)) $measure = null;

                // add measure to output array
                if (array_key_exists($date, $output['data'])) {
                    $output['data'][$date][] = $measure;
                } else {
                    $output['data'][$date] = array($measure);
                }
            }
        }

        return $output;
    }

    /**
     * Concatenates the path to the source's rrd file
     * If source is empty, returns the path to the data folder.
     * @param $source
     * @return string
     */
    public function get_data_path($source = '') {
        if (!empty($source)) $source = DIRECTORY_SEPARATOR . $source . '.rrd';
        $path = \common\Config::$path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'data' . $source;

        if (!file_exists($path)) $this->d->log('Was not able to find ' . $path, LOG_INFO);

        return $path;
    }
}