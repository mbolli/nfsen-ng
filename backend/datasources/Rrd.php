<?php

namespace mbolli\nfsen_ng\datasources;

use JetBrains\PhpStorm\ExpectedValues;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;

class Rrd implements Datasource {
    private readonly Debug $d;
    private array $fields = [
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
    ];

    private array $layout = [
        '0.5:1:' . ((60 / (1 * 5)) * 24 * 45), // 45 days of 5 min samples
        '0.5:6:' . ((60 / (6 * 5)) * 24 * 90), // 90 days of 30 min samples
        '0.5:24:' . ((60 / (24 * 5)) * 24 * 360), // 360 days of 2 hour samples
        '0.5:288:1080', // 1080 days of daily samples
        // = 3 years of data
    ];

    public function __construct() {
        $this->d = Debug::getInstance();

        if (!\function_exists('rrd_version')) {
            throw new \Exception('Please install the PECL rrd library.');
        }
    }

    /**
     * Gets the timestamps of the first and last entry of this specific source.
     */
    public function date_boundaries(string $source): array {
        $rrdFile = $this->get_data_path($source);

        return [rrd_first($rrdFile), rrd_last($rrdFile)];
    }

    /**
     * Gets the timestamp of the last update of this specific source.
     *
     * @return int timestamp or false
     */
    public function last_update(string $source = '', int $port = 0): int {
        $rrdFile = $this->get_data_path($source, $port);
        $last_update = rrd_last($rrdFile);

        // $this->d->log('Last update of ' . $rrdFile . ': ' . date('d.m.Y H:i', $last_update), LOG_DEBUG);
        return (int) $last_update;
    }

    /**
     * Create a new RRD file for a source.
     *
     * @param string $source e.g. gateway or server_xyz
     * @param bool   $reset  overwrites existing RRD file if true
     */
    public function create(string $source, int $port = 0, bool $reset = false): bool {
        $rrdFile = $this->get_data_path($source, $port);

        // check if folder exists
        if (!file_exists(\dirname($rrdFile))) {
            mkdir(\dirname($rrdFile), 0o755, true);
        }

        // check if folder has correct access rights
        if (!is_writable(\dirname($rrdFile))) {
            $this->d->log('Error creating ' . $rrdFile . ': Not writable', LOG_CRIT);

            return false;
        }
        // check if file already exists
        if (file_exists($rrdFile)) {
            if ($reset === true) {
                unlink($rrdFile);
            } else {
                $this->d->log('Error creating ' . $rrdFile . ': File already exists', LOG_ERR);

                return false;
            }
        }

        $start = strtotime('3 years ago');
        $starttime = (int) $start - ($start % 300);

        $creator = new \RRDCreator($rrdFile, (string) $starttime, 60 * 5);
        foreach ($this->fields as $field) {
            $creator->addDataSource($field . ':ABSOLUTE:600:U:U');
        }
        foreach ($this->layout as $rra) {
            $creator->addArchive('AVERAGE:' . $rra);
            $creator->addArchive('MAX:' . $rra);
        }

        $saved = $creator->save();
        if ($saved === false) {
            $this->d->log('Error saving RRD data structure to ' . $rrdFile, LOG_ERR);
        }

        return $saved;
    }

    /**
     * Write to an RRD file with supplied data.
     *
     * @throws \Exception
     */
    public function write(array $data): bool {
        $rrdFile = $this->get_data_path($data['source'], $data['port']);
        if (!file_exists($rrdFile)) {
            $this->create($data['source'], $data['port'], false);
        }

        $nearest = (int) $data['date_timestamp'] - ($data['date_timestamp'] % 300);
        $this->d->log('Writing to file ' . $rrdFile, LOG_DEBUG);

        // write data
        $updater = new \RRDUpdater($rrdFile);

        return $updater->update($data['fields'], (string) $nearest);
    }

    /**
     * @param string $type    flows/packets/traffic
     * @param string $display protocols/sources/ports
     */
    public function get_graph_data(
        int $start,
        int $end,
        array $sources,
        array $protocols,
        array $ports,
        #[ExpectedValues(['flows', 'packets', 'bytes', 'bits'])]
        string $type = 'flows',
        #[ExpectedValues(['protocols', 'sources', 'ports'])]
        string $display = 'sources',
    ): array|string {
        $options = [
            '--start',
            $start - ($start % 300),
            '--end',
            $end - ($end % 300),
            '--maxrows',
            300,
            // number of values. works like the width value (in pixels) in rrd_graph
            // '--step', 1200, // by default, rrdtool tries to get data for each row. if you want rrdtool to get data at a one-hour resolution, set step to 3600.
            '--json',
        ];

        $useBits = false;
        if ($type === 'bits') {
            $type = 'bytes';
            $useBits = true;
        }

        if (empty($protocols)) {
            $protocols = ['tcp', 'udp', 'icmp', 'other'];
        }
        if (empty($sources)) {
            $sources = Config::$cfg['general']['sources'];
        }
        if (empty($ports)) {
            $ports = Config::$cfg['general']['ports'];
        }

        switch ($display) {
            case 'protocols':
                foreach ($protocols as $protocol) {
                    $rrdFile = $this->get_data_path($sources[0]);
                    $proto = ($protocol === 'any') ? '' : '_' . $protocol;
                    $legend = array_filter([$protocol, $type, $sources[0]]);
                    $options[] = 'DEF:data' . $sources[0] . $protocol . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $sources[0] . $protocol . ':' . implode('_', $legend);
                }

                break;

            case 'sources':
                foreach ($sources as $source) {
                    $rrdFile = $this->get_data_path($source);
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter([$source, $type, $protocols[0]]);
                    $options[] = 'DEF:data' . $source . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . ':' . implode('_', $legend);
                }

                break;

            case 'ports':
                foreach ($ports as $port) {
                    $source = ($sources[0] === 'any') ? '' : $sources[0];
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter([$port, $type, $source, $protocols[0]]);
                    $rrdFile = $this->get_data_path($source, $port);
                    $options[] = 'DEF:data' . $source . $port . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . $port . ':' . implode('_', $legend);
                }
        }

        ob_start();
        $data = rrd_xport($options);
        $error = ob_get_clean(); // rrd_xport weirdly prints stuff on error

        if (!\is_array($data)) { // @phpstan-ignore-line function.alreadyNarrowedType (probably wrong rrd stubs)
            return $error . '. ' . rrd_error();
        }

        // remove invalid numbers and create processable array
        $output = [
            'data' => [],
            'start' => $data['start'],
            'end' => $data['end'],
            'step' => $data['step'],
            'legend' => [],
        ];
        foreach ($data['data'] as $source) {
            $output['legend'][] = $source['legend'];
            foreach ($source['data'] as $date => $measure) {
                // ignore non-valid measures
                if (is_nan($measure)) {
                    $measure = null;
                }

                if ($type === 'bytes' && $useBits) {
                    $measure *= 8;
                }

                // add measure to output array
                if (\array_key_exists($date, $output['data'])) {
                    $output['data'][$date][] = $measure;
                } else {
                    $output['data'][$date] = [$measure];
                }
            }
        }

        return $output;
    }

    /**
     * Creates a new database for every source/port combination.
     */
    public function reset(array $sources): bool {
        $return = false;
        if (empty($sources)) {
            $sources = Config::$cfg['general']['sources'];
        }
        $ports = Config::$cfg['general']['ports'];
        $ports[] = 0;
        foreach ($ports as $port) {
            if ($port !== 0) {
                $return = $this->create('', $port, true);
            }
            if ($return === false) {
                return false;
            }

            foreach ($sources as $source) {
                $return = $this->create($source, $port, true);
                if ($return === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Concatenates the path to the source's rrd file.
     */
    public function get_data_path(string $source = '', int $port = 0): string {
        if ((int) $port === 0) {
            $port = '';
        } else {
            $port = (empty($source)) ? $port : '_' . $port;
        }
        $path = Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data' . \DIRECTORY_SEPARATOR . $source . $port . '.rrd';

        if (!file_exists($path)) {
            $this->d->log('Was not able to find ' . $path, LOG_INFO);
        }

        return $path;
    }
}
