<?php
namespace datasources;

class RRD implements Datasource {
    private $d;
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
     * @return int timestamp or false
     */
    public function last_update(string $source) : int {
        $rrdFile = $this->get_data_path($source);
        return rrd_last($rrdFile);
    }


    /**
     * Create a new RRD file for a source
     * @param string $source e.g. gateway or server_xyz
     * @param int $port
     * @param bool $reset overwrites existing RRD file if true
     * @return bool
     */
    public function create(string $source, int $port = 0, bool $reset = false) {
        $rrdFile = $this->get_data_path($source, $port);

        // check if folder has correct access rights
        if (!is_writable(dirname($rrdFile))) {
            $this->d->log('Error creating ' . $rrdFile . ': Not writable', LOG_CRIT);
            return false;
        }
        // check if file already exists
        if (file_exists($rrdFile)) {
            if ($reset === true) unlink($rrdFile);
            else {
                $this->d->log('Error creating ' . $rrdFile . ': File already exists', LOG_ERR);
                return false;
            }
        }

        $start = strtotime("3 years ago");
        $starttime = (int)$start - ($start % 300);

        $creator = new \RRDCreator($rrdFile, $starttime, (60*5));
        foreach ($this->fields as $field) {
            $creator->addDataSource($field . ":ABSOLUTE:600:U:U");
        }
        foreach ($this->layout as $rra) {
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
        $rrdFile = $this->get_data_path($data['source'], $data['port']);

        $nearest = (int)$data['date_timestamp'] - ($data['date_timestamp'] % 300);

        // write data
        $updater = new \RRDUpdater($rrdFile);
        return $updater->update($data['fields'], $nearest);
    }

    /**
     * @param int $start
     * @param int $end
     * @param array $sources
     * @param array $protocols
     * @param array $ports
     * @param string $type flows/packets/traffic
     * @param string $display protocols/sources/ports
     * @return array|string
     */
    public function get_graph_data(int $start, int $end, array $sources, array $protocols, array $ports, string $type = 'flows', string $display = 'sources') {

        $options = array(
            '--start', $start-($start%300),
            '--end', $end-($end%300),
            '--maxrows', 300, // number of values. works like the width value (in pixels) in rrd_graph
            // '--step', 1200, // by default, rrdtool tries to get data for each row. if you want rrdtool to get data at a one-hour resolution, set step to 3600.
            '--json'
        );

        if (empty($protocols)) $protocols = array('tcp', 'udp', 'icmp', 'other');
        if (empty($sources)) $sources = \common\Config::$cfg['general']['sources'];
        if (empty($ports)) $ports = \common\Config::$cfg['general']['ports'];

        switch ($display) {
            case 'protocols':
                foreach ($protocols as $protocol) {
                    $rrdFile = $this->get_data_path($sources[0]);
                    $proto = ($protocol === 'any') ? '' : '_' . $protocol;
                    $legend = array_filter(array($protocol, $type, $sources[0]));
                    $options[] = 'DEF:data' . $sources[0] . $protocol . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $sources[0] . $protocol . ':' . implode('_', $legend);
                }
                break;
            case 'sources':
                foreach ($sources as $source) {
                    $rrdFile = $this->get_data_path($source);
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter(array($source, $type, $protocols[0]));
                    $options[] = 'DEF:data' . $source . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . ':' . implode('_', $legend);
                }
                break;
            case 'ports':
                foreach ($ports as $port) {
                    $source = ($sources[0] === 'any') ? '' : $sources[0];
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter(array($port, $type, $source, $protocols[0]));
                    $rrdFile = $this->get_data_path($source, $port);
                    $options[] = 'DEF:data' . $source . $port . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . $port . ':' . implode('_', $legend);
                }
        }

        ob_start();
        $data = rrd_xport($options);
        $error = ob_get_clean(); // rrd_xport weirdly prints stuff on error

        if (!is_array($data)) return $error . ". " . rrd_error();

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
     * Creates a new database for every source/port combination
     * @param array $sources
     * @return bool
     */
    public function reset(array $sources) {
        if (empty($sources)) $sources = \Common\Config::$cfg['general']['sources'];
        $ports = \Common\Config::$cfg['general']['ports'];
        $ports[] = 0;
        foreach ($ports as $port) {
            if ($port !== 0) $return = $this->create('', $port, true);
            if ($return === false) return false;

            foreach ($sources as $source) {
                $return = $this->create($source, $port, true);
                if ($return === false) return false;
            }
        }
        return true;
    }

    /**
     * Concatenates the path to the source's rrd file
     * @param string $source
     * @param int $port
     * @return string
     */
    public function get_data_path($source = '', $port = 0) {
        if ((int)$port === 0) $port = '';
        else {
            $port = (empty($source)) ? $port : '_' . $port;
        }
        $path = \common\Config::$path . DIRECTORY_SEPARATOR . 'datasources' . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $source . $port . '.rrd';

        if (!file_exists($path)) $this->d->log('Was not able to find ' . $path, LOG_INFO);

        return $path;
    }
}