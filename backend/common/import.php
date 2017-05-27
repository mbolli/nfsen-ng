<?php
namespace common;

class Import {

    private $d;
    private $cli;
    private $verbose;
    private $force;
    private $quiet;
    private $processPorts;

    private $days_total = 0;

	function __construct() {
        $this->d = Debug::getInstance();
        $this->cli = (php_sapi_name() === 'cli');
        $this->force = false;
        $this->verbose = false;
        $this->quiet = false;
        $this->processPorts = false;
        $this->d->setDebug($this->verbose);
	}

	function start(\DateTime $datestart) {

        $sources = Config::$cfg['general']['sources'];
        $processed_sources = 0;

        // if in force mode, reset existing data
        if ($this->force === true) {
            if ($this->cli === true) echo "Resetting existing data..." . PHP_EOL;
            Config::$db->reset(array());
        }

        // start progress bar (CLI only)
        $this->days_total = ((int)$datestart->diff(new \DateTime())->format('%a')+1)*count($sources);
        if ($this->cli === true && $this->quiet === false)
            echo PHP_EOL . \vendor\ProgressBar::start($this->days_total, 'Processing ' . count($sources) . ' sources...');

        // process each source, e.g. gateway, mailserver, etc.
        foreach ($sources as $source) {
            $source_path = Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'];
            if (!file_exists($source_path)) throw new \Exception("Could not read nfdump profile directory " . $source_path);
            if ($this->cli === true && $this->quiet === false)
                echo PHP_EOL . "Processing source " . $source . "..." . PHP_EOL;

            $today = new \DateTime();
            $date = clone $datestart;

            // check if we want to continue a stopped import
            $last_update_db = Config::$db->last_update($source);
            $last_update = null;
            if ($last_update_db !== false && $last_update_db !== 0) {
                $last_update = new \DateTime();
                $last_update->setTimestamp($last_update_db);
            }

            if ($this->force === false && isset($last_update)) {
                $days_saved = (int)$date->diff($last_update)->format('%a');
                $this->days_total = $this->days_total-$days_saved;
                if ($this->quiet === false) $this->d->log('Last update: ' . $last_update->format('Y-m-d H:i'), LOG_INFO);
                if ($this->cli === true && $this->quiet === false)
                    \vendor\ProgressBar::setTotal($this->days_total);

                // set progress to the date when the import was stopped
                $date->setTimestamp($last_update_db);
            }

            // iterate from $datestart until today
            while ((int)$date->format("Ymd") <= (int)$today->format("Ymd")) {
                $scan = array($source_path, $source, $date->format("Y"), $date->format("m"), $date->format("d"));
                $scan_path = implode(DIRECTORY_SEPARATOR, $scan);

                // set date to tomorrow
                $date->modify("+1 day");

                // if no data exists for current date  (e.g. .../2017/03/03)
                if (!file_exists($scan_path)) {
                    $this->d->dpr($scan_path . " does not exist!");
                    if ($this->cli === true && $this->quiet === false)
                        echo \vendor\ProgressBar::next(1);
                    continue;
                }

                // scan path
                $this->d->log('Scanning path ' . $scan_path, LOG_INFO);
                $scan_files = scandir($scan_path);

                if ($this->cli === true && $this->quiet === false)
                    echo \vendor\ProgressBar::next(1, 'Scanning ' . $scan_path . "...");

                foreach($scan_files as $file) {
                    if (in_array($file, array(".", ".."))) continue;

                    // parse date of file name to compare against last_update
                    preg_match('/nfcapd\.([0-9]{12})$/', $file, $file_date);
                    $file_datetime = new \DateTime($file_date[1]);
                    if ($file_datetime <= $last_update) continue;

                    // let nfdump parse each nfcapd file
                    $stats_path = implode(DIRECTORY_SEPARATOR, array_slice($scan, 2, 5)) . DIRECTORY_SEPARATOR . $file;

                    try {

                        // fill source.rrd
                        $this->write_sources_data($source, $stats_path);

                        // write general port data (not depending on source, so only executed per port)
                        if ($source === $sources[0])
                            $this->write_ports_data($stats_path);

                        // if enabled, process ports per source as well (source_80.rrd)
                        if ($this->processPorts === true) {
                            $this->write_ports_data($stats_path, $source);
                        }

                    } catch (\Exception $e) {
                        $this->d->log('Catched exception: ' . $e->getMessage(), LOG_WARNING);
                    }
                }
            }
            $processed_sources++;
        }
        if ($processed_sources === 0) $this->d->log('Import did not process any sources.', LOG_WARNING);
        if ($this->cli === true && $this->quiet === false)
            echo \vendor\ProgressBar::finish();

    }

    /**
     * @param $source
     * @param $stats_path
     * @return bool
     */
    private function write_sources_data($source, $stats_path) {
        // set options and get netflow summary statistics (-I)
        $nfdump = NfDump::getInstance();
        $nfdump->reset();
        $nfdump->setOption("-I", null);
        $nfdump->setOption("-r", $stats_path);
        $nfdump->setOption("-M", $source);

        try {
            $input = $nfdump->execute();
        } catch (\Exception $e) {
            $this->d->log('Exception: ' . $e->getMessage(), LOG_WARNING);
            return false;
        }

        $date = new \DateTime(substr($stats_path, -12));
        $data = array(
            'fields' => array(),
            'source' => $source,
            'port' => 0,
            'date_iso' => $date->format("Ymd\THis"),
            'date_timestamp' => $date->getTimestamp()
        );
        // $input data is an array of lines looking like this:
        // flows_tcp: 323829
        foreach($input as $i => $line) {
            if (!is_string($line)) $this->d->log('Got no output of previous command', LOG_DEBUG);
            if ($i === 0) continue; // skip nfdump command
            list($type, $value) = explode(": ", $line);

            // we only need flows/packets/bytes values, the source and the timestamp
            if (preg_match("/^(flows|packets|bytes)/i", $type)) {
                $data['fields'][strtolower($type)] = (int)$value;
            }
        }

        // write to database
        Config::$db->write($data);
    }

    /**
     * @param $stats_path
     * @param $source
     * @return bool
     */
    private function write_ports_data($stats_path, $source = "") {
	    $ports = \common\Config::$cfg['general']['ports'];
	    $sources = \common\Config::$cfg['general']['sources'];

	    foreach ($ports as $port) {

            // set options and get netflow statistics
            $nfdump = NfDump::getInstance();
            $nfdump->reset();
            $nfdump->setFilter("dst port=" . $port);
            $nfdump->setOption("-s", "dstport:p");
            $nfdump->setOption("-r", $stats_path);
            $nfdump->setOption("-M", $source);

            // if no source is specified, get data for all sources
            if (empty($source)) $nfdump->setOption("-M", implode(":", $sources));

            try {
                $input = $nfdump->execute();
            } catch (\Exception $e) {
                $this->d->log('Exception: ' . $e->getMessage(), LOG_WARNING);
                return false;
            }

            // parse and turn into usable data

            $date = new \DateTime(substr($stats_path, -12));
            $data = array(
                'fields' => array(),
                'source' => $source,
                'port' => $port,
                'date_iso' => $date->format("Ymd\THis"),
                'date_timestamp' => $date->getTimestamp()
            );
            $rows = count($input);

            // process protocols
            // headers: ts,te,td,pr,val,fl,flP,ipkt,ipktP,ibyt,ibytP,ipps,ipbs,ibpp
            foreach ($input as $i => $line) {
                if ($i === 0 || $i === 1) continue; // skip headers and executed command
                if ($i === $rows-4) { break; }

                $proto = strtolower($line[3]);
                $data['fields']['flows_' . $proto] = (int)$line[5];
                $data['fields']['packets_' . $proto] = (int)$line[7];
                $data['fields']['bytes_' . $proto] = (int)$line[9];
            }

            // process summary
            // headers: flows,bytes,packets,avg_bps,avg_pps,avg_bpp
            $lastline = $input[$rows-1];
            $data['fields']['flows'] = (int)$lastline[0];
            $data['fields']['packets'] = (int)$lastline[2];
            $data['fields']['bytes'] = (int)$lastline[1];

            // write to database
            Config::$db->write($data);
        }

        return true;
    }

    /**
     * @param string $file
     * @param string $source
     */
    public function import_file(string $file, string $source) {
        try {

            // fill source.rrd
            $this->write_sources_data($source, $file);

            // write general port data (not depending on source, so only executed per port)
            $this->write_ports_data($file);

            // if enabled, process ports per source as well (source_80.rrd)
            if ($this->processPorts === true) {
                $this->write_ports_data($file, $source);
            }

        } catch (\Exception $e) {
            $this->d->log('Catched exception: ' . $e->getMessage(), LOG_WARNING);
        }
    }

    /**
     * @param bool $verbose
     */
    public function setVerbose(bool $verbose) {
        if ($verbose === true) $this->d->setDebug(true);
        $this->verbose = $verbose;
    }

    /**
     * @param bool $processPorts
     */
    public function setProcessPorts(bool $processPorts)     {
        $this->processPorts = $processPorts;
    }

    /**
     * @param bool $force
     */
    public function setForce(bool $force) {
        $this->force = $force;
    }

    /**
     * @param bool $quiet
     */
    public function setQuiet(bool $quiet) {
        $this->quiet = $quiet;
    }
}

