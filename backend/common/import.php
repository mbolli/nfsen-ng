<?php

namespace nfsen_ng\common;

class Import {
    
    private $d;
    private $cli;
    private $verbose;
    private $force;
    private $quiet;
    private $processPorts;
    private $processPortsBySource;
    private $checkLastUpdate;
    
    private $days_total = 0;
    
    function __construct() {
        $this->d = Debug::getInstance();
        $this->cli = (php_sapi_name() === 'cli');
        $this->force = false;
        $this->verbose = false;
        $this->quiet = false;
        $this->processPorts = false;
        $this->processPortsBySource = false;
        $this->checkLastUpdate = false;
        $this->d->setDebug($this->verbose);
    }
    
    /**
     * @param \DateTime $datestart
     *
     * @throws \Exception
     */
    function start(\DateTime $datestart) {
        
        $sources = Config::$cfg['general']['sources'];
        $processed_sources = 0;
        
        // if in force mode, reset existing data
        if ($this->force === true) {
            if ($this->cli === true) echo "Resetting existing data..." . PHP_EOL;
            Config::$db->reset(array());
        }
        
        // start progress bar (CLI only)
        $this->days_total = ((int)$datestart->diff(new \DateTime())->format('%a') + 1) * count($sources);
        if ($this->cli === true && $this->quiet === false)
            echo PHP_EOL . \vendor\ProgressBar::start($this->days_total, 'Processing ' . count($sources) . ' sources...');
        
        // process each source, e.g. gateway, mailserver, etc.
        foreach ($sources as $nr => $source) {
            $source_path = Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'];
            if (!file_exists($source_path)) throw new \Exception("Could not read nfdump profile directory " . $source_path);
            if ($this->cli === true && $this->quiet === false)
                echo PHP_EOL . "Processing source " . $source . " (" . ($nr + 1) . "/" . count($sources) . ")..." . PHP_EOL;
            
            $date = clone $datestart;
            
            // check if we want to continue a stopped import
            // assumes the last update of a source is similar to the last update of its ports...
            $last_update_db = Config::$db->last_update($source);
            
            $last_update = null;
            if ($last_update_db !== false && $last_update_db !== 0) {
                $last_update = (new \DateTime())->setTimestamp($last_update_db);
            }
            
            if ($this->force === false && isset($last_update)) {
                $days_saved = (int)$date->diff($last_update)->format('%a');
                $this->days_total = $this->days_total - $days_saved;
                if ($this->quiet === false) $this->d->log('Last update: ' . $last_update->format('Y-m-d H:i'), LOG_INFO);
                if ($this->cli === true && $this->quiet === false)
                    \vendor\ProgressBar::setTotal($this->days_total);
                
                // set progress to the date when the import was stopped
                $date->setTimestamp($last_update_db);
                $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            }
            
            // iterate from $datestart until today
            while ((int)$date->format("Ymd") <= (int)(new \DateTime)->format("Ymd")) {
                $scan = array($source_path, $source, $date->format("Y"), $date->format("m"), $date->format("d"));
                $scan_path = implode(DIRECTORY_SEPARATOR, $scan);
                
                // set date to tomorrow for next iteration
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
                
                foreach ($scan_files as $file) {
                    if (in_array($file, array(".", ".."))) continue;
                    
                    try {
                        // parse date of file name to compare against last_update
                        preg_match('/nfcapd\.([0-9]{12})$/', $file, $file_date);
                        if (count($file_date) !== 2) throw new \LengthException('Bad file name format of nfcapd file: ' . $file);
                        $file_datetime = new \DateTime($file_date[1]);
                    } catch (\LengthException $e) {
                        $this->d->log('Caught exception: ' . $e->getMessage(), LOG_DEBUG);
                        continue;
                    }
                    
                    // compare file name date with last update
                    if ($file_datetime <= $last_update) continue;
                    
                    // let nfdump parse each nfcapd file
                    $stats_path = implode(DIRECTORY_SEPARATOR, array_slice($scan, 2, 5)) . DIRECTORY_SEPARATOR . $file;
                    
                    try {
                        
                        // fill source.rrd
                        $this->write_source_data($source, $stats_path);
                        
                        // write general port data (queries data for all sources, should only be executed when data for all sources exists...)
                        if ($this->processPorts === true && $nr === count($sources) - 1)
                            $this->write_ports_data($stats_path);
                        
                        // if enabled, process ports per source as well (source_80.rrd)
                        if ($this->processPortsBySource === true) {
                            $this->write_ports_data($stats_path, $source);
                        }
                        
                    } catch (\Exception $e) {
                        $this->d->log('Caught exception: ' . $e->getMessage(), LOG_WARNING);
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
	 * @throws \Exception
	 */
    private function write_source_data($source, $stats_path) {
        
        // set options and get netflow summary statistics (-I)
        $nfdump = NfDump::getInstance();
        $nfdump->reset();
        $nfdump->setOption("-I", null);
        $nfdump->setOption("-M", $source);
        $nfdump->setOption("-r", $stats_path);
        
        if ($this->db_updateable($stats_path, $source) === false) return false;
        
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
        foreach ($input as $i => $line) {
            if (!is_string($line)) $this->d->log('Got no output of previous command', LOG_DEBUG);
            if ($i === 0) continue; // skip nfdump command
            if (!preg_match('/:/', $line)) continue; // skip invalid lines like error messages
            list($type, $value) = explode(": ", $line);
            
            // we only need flows/packets/bytes values, the source and the timestamp
            if (preg_match("/^(flows|packets|bytes)/i", $type)) {
                $data['fields'][strtolower($type)] = (int)$value;
            }
        }
        
        // write to database
        if (Config::$db->write($data) === false) throw new \Exception('Error writing to ' . $stats_path);
    }
    
    /**
     * @param        $stats_path
     * @param string $source
     *
     * @return bool
     * @throws \Exception
     */
    private function write_ports_data($stats_path, $source = "") {
        $ports = Config::$cfg['general']['ports'];
        
        foreach ($ports as $port) {
            $this->write_port_data($port, $stats_path, $source);
        }
        
        return true;
    }
    
    /**
     * @param        $port
     * @param        $stats_path
     * @param string $source
     *
     * @return bool
     * @throws \Exception
     */
    private function write_port_data($port, $stats_path, $source = "") {
        $sources = Config::$cfg['general']['sources'];
        
        // set options and get netflow statistics
        $nfdump = NfDump::getInstance();
        $nfdump->reset();
        
        if (empty($source)) {
            // if no source is specified, get data for all sources
            $nfdump->setOption("-M", implode(":", $sources));
            if ($this->db_updateable($stats_path, '', $port) === false) return false;
        } else {
            $nfdump->setOption("-M", $source);
            if ($this->db_updateable($stats_path, $source, $port) === false) return false;
        }
        
        $nfdump->setFilter("dst port=" . $port);
        $nfdump->setOption("-s", "dstport:p");
        $nfdump->setOption("-r", $stats_path);
        
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
            if (!is_array($line) && $line instanceof \Countable === false) continue; // skip anything uncountable
            if (count($line) !== 14) continue; // skip anything invalid
            if ($line[0] === "ts") continue; // skip header
            
            $proto = strtolower($line[3]);
            $data['fields']['flows_' . $proto] = (int)$line[5];
            $data['fields']['packets_' . $proto] = (int)$line[7];
            $data['fields']['bytes_' . $proto] = (int)$line[9];
        }
        
        // process summary
        // headers: flows,bytes,packets,avg_bps,avg_pps,avg_bpp
        $lastline = $input[$rows - 1];
        $data['fields']['flows'] = (int)$lastline[0];
        $data['fields']['packets'] = (int)$lastline[2];
        $data['fields']['bytes'] = (int)$lastline[1];
        
        // write to database
        if (Config::$db->write($data) === false) throw new \Exception('Error writing to ' . $stats_path);
    }
    
    /**
     * Import a single nfcapd file
     *
     * @param string $file
     * @param string $source
     * @param bool   $last
     */
    public function import_file(string $file, string $source, bool $last) {
        try {
            
            $this->d->log('Importing file ' . $file . ' (' . $source . '), last=' . (int)$last, LOG_INFO);
            
            // fill source.rrd
            $this->write_source_data($source, $file);
            
            // write general port data (not depending on source, so only executed per port)
            if ($last === true) $this->write_ports_data($file);
            
            // if enabled, process ports per source as well (source_80.rrd)
            if ($this->processPorts === true) {
                $this->write_ports_data($file, $source);
            }
            
        } catch (\Exception $e) {
            $this->d->log('Caught exception: ' . $e->getMessage(), LOG_WARNING);
        }
    }
    
    /**
     * Check if db is free to update (some databases only allow inserting data at the end)
     *
     * @param string $file
     * @param string $source
     * @param int    $port
     *
     * @return bool
     * @throws \Exception
     */
    public function db_updateable(string $file, string $source = '', int $port = 0) {
        if ($this->checkLastUpdate === false) return true;
        
        // parse capture file's datetime. can't use filemtime as we need the datetime in the file name.
        $date = array();
        if (!preg_match('/nfcapd\.([0-9]{12})$/', $file, $date)) return false; // nothing to import
        
        $file_datetime = new \DateTime($date[1]);
        
        // get last updated time from database
        $last_update_db = Config::$db->last_update($source, $port);
        $last_update = null;
        if ($last_update_db !== false && $last_update_db !== 0) {
            $last_update = new \DateTime();
            $last_update->setTimestamp($last_update_db);
        }
        
        // prevent attempt to import the same file again
        return ($file_datetime > $last_update);
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
    public function setProcessPorts(bool $processPorts) {
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
    
    /**
     * @param mixed $processPortsBySource
     */
    public function setProcessPortsBySource($processPortsBySource) {
        $this->processPortsBySource = $processPortsBySource;
    }
    
    /**
     * @param bool $checkLastUpdate
     */
    public function setCheckLastUpdate(bool $checkLastUpdate) {
        $this->checkLastUpdate = $checkLastUpdate;
    }
}

