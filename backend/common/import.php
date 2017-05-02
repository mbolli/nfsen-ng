<?php
namespace common;

class Import {

    private $d;
    private $cli;
    private $verbose;

    private $days_total = 0;

	function __construct() {
        $this->d = Debug::getInstance();
        $this->cli = (php_sapi_name() === 'cli');
        $this->verbose = false;
        $this->d->setDebug($this->verbose);
	}

	function start(\DateTime $datestart) {
        $source_path = Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . Config::$cfg['nfdump']['profile'];
        $sources = @scandir($source_path);
        if(!is_array($sources)) throw new \Exception("Could not read nfdump profile directory " . $source_path);
        $sources = array_diff($sources, array('..', '.'));
        $processed_sources = 0;

        $this->days_total = $datestart->diff(new \DateTime())->format('%a')*count($sources);
        if ($this->cli === true) echo "\n" . \vendor\ProgressBar::start($this->days_total, 'Processing ' . count($sources) . ' sources...');

        // process each source, e.g. gateway, mailserver, etc.
        foreach ($sources as $source) {
            if(!in_array($source, Config::$cfg['general']['sources'])) {
                $this->d->log('Found source ' . $source . ' which is not in the settings file.', LOG_INFO);
                continue;
            }
            if ($this->cli === true) echo "\nProcessing source " . $source . "...\n";

            $today = new \DateTime();
            $date = clone $datestart;

            // iterate from $datestart until today
            while ($date->format("Ymd") != $today->format("Ymd")) {
                $scan = array($source_path, $source, $date->format("Y"), $date->format("m"), $date->format("d"));
                $scan_path = implode(DIRECTORY_SEPARATOR, $scan);

                // if data for current date exists (e.g. .../2017/03/03)
                if(file_exists($scan_path)) {
                    $this->d->log('Scanning path ' . $scan_path, LOG_INFO);
                    $scan_files = scandir($scan_path);

                    if ($this->cli === true) echo \vendor\ProgressBar::next(1, 'Scanning ' . $scan_path . "...");
                    
                    foreach($scan_files as $file) {
                        if(!in_array($file, array(".", ".."))) {

                            // let nfdump parse each nfcapd file
                            $stats_path = implode(DIRECTORY_SEPARATOR, array_slice($scan, 2, 5)) . DIRECTORY_SEPARATOR . $file;

                            // set options and get netflow summary statistics (-I)
                            $nfdump = NfDump::getInstance();
                            $nfdump->setOption("-I", null);
                            $nfdump->setOption("-r", $stats_path);
                            $nfdump->setOption("-M", $source);
                            try {
                                $input = $nfdump->execute();
                            } catch (\Exception $e) {
                                $this->d->log('Exception: ' . $e->getMessage(), LOG_WARNING);
                                continue;
                            }

                            $data = array();

                            // $input data is an array of lines looking like this:
                            // flows_tcp: 323829
                            foreach($input as $line) {
                                if (!is_string($line)) $this->d->log('Got no output of previous command', LOG_DEBUG);
                                list($type, $value) = explode(": ", $line);

                                // we only need flows/packets/bytes values, the source and the timestamp
                                if(preg_match("/^(flows|packets|bytes)/i", $type)) {
                                    $data['fields'][strtolower($type)] = (int)$value;
                                } elseif("Ident" == $type) {
                                    $data['source'] = $value;
                                } elseif("Last" == $type) {
                                    $d = new \DateTime();
                                    $d->setTimestamp((int)$value - ($value % 300));

                                    $data['date_iso'] = $d->format("Ymd\THis");
                                    $data['date_timestamp'] = $d->getTimestamp();
                                }
                            }

                            // write to database
                            Config::$db->write($data);
                        }
                    }
                } else {
                    $this->d->dpr($scan_path . " does not exist!");
                    if ($this->cli === true) echo \vendor\ProgressBar::next(1);
                }

                // set date to tomorrow
                $date->modify("+1 day");
            }
            $processed_sources++;
        }
        if ($processed_sources === 0) $this->d->log('Import did not process any sources.', LOG_WARNING);
        if ($this->cli === true) echo \vendor\ProgressBar::finish();

    }

    /**
     * @param bool $verbose
     */
    public function setVerbose(bool $verbose) {
        if ($verbose === true) $this->d->setDebug(true);
        $this->verbose = $verbose;
    }
}

