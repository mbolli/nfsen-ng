<?php

class Import {

    private $d;
    private $src;

	function __construct() {
        $this->d = \Debug::getInstance();
        $this->d->dpr(Config::$cfg);

        // find data source
        if(array_key_exists('host', Config::$cfg['db']['akumuli'])) {
            $this->d->dpr("Using Akumuli");
            $this->src = new datasources\Akumuli();
        } else {
            $this->d->dpr("Using RRD");
            $this->src = new datasources\RRD();
        }

        $this->d->dpr(Config::$path);
	}

	function start(DateTime $datestart) {
        $source_path = \Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . \Config::$cfg['nfdump']['profile'];
        $sources = @scandir($source_path);
        if(!is_array($sources)) throw new \Exception("Could not read nfdump profile directory " . $source_path);

        // process each source, e.g. gateway, mailserver, etc.
        foreach ($sources as $source) {
            if(in_array($source, \Config::$cfg['general']['sources'])) {
                $today = new \DateTime();
                $date = clone $datestart;

                // iterate from $datestart until today
                while ($date->format("Ymd") != $today->format("Ymd")) {
                    $scan = array($source_path, $source, $date->format("Y"), $date->format("m"), $date->format("d"));
                    $scan_path = implode(DIRECTORY_SEPARATOR, $scan);

                    // if data for current date exists (e.g. .../2017/03/03)
                    if(file_exists($scan_path)) {
                        $scan_files = scandir($scan_path);

                        foreach($scan_files as $file) {
                            if(!in_array($file, array(".", ".."))) {

                                // let nfdump parse each nfcapd file
                                $stats_path = implode(DIRECTORY_SEPARATOR, array_slice($scan, 2, 5)) . DIRECTORY_SEPARATOR . $file;

                                // set options and get netflow summary statistics (-I)
                                $nfdump = \NfDump::getInstance();
                                $nfdump->setOption("-I", null);
                                $nfdump->setOption("-r", $stats_path);
                                $nfdump->setOption("-M", $source);
                                $input = $nfdump->execute();

                                $data = array();

                                // $input data is an array of lines looking like this:
                                // flows_tcp: 323829
                                foreach($input as $line) {
                                    list($type, $value) = explode(": ", $line);

                                    // we only need flows/packets/bytes values, the source and the timestamp
                                    if(preg_match("/^(flows|packets|bytes)/i", $type)) {
                                        $data['fields'][strtolower($type)] = (int)$value;
                                    } elseif("Ident" == $type) {
                                        $data['source'] = $value;
                                    } elseif("First" == $type) {
                                        $d = new \DateTime();
                                        $d->setTimestamp((int)$value);
                                        $data['timestamp'] = $d->format("Ymd\THis");
                                    }
                                }

                                // write to database
                                $this->src->write($data);

                            }
                        }
                    } else {
                        $this->d->dpr($scan_path . " does not exist!");
                    }

                    // set date to tomorrow
                    $date->modify("+1 day");
                }
            }
        }
    }
}

