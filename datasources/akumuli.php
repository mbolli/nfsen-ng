<?php
namespace datasources;

class Akumuli implements \Datasource  {

    private $d;
    private $client;

    public function __construct() {
        $this->d = \Debug::getInstance();
        $this->connect();
    }

    /**
     * connects to TCP socket
     */
    function connect() {
        try {
            $this->client = stream_socket_client("tcp://" . \Config::$cfg['db']['akumuli']['host'] . ":" . \Config::$cfg['db']['akumuli']['port'], $errno, $errmsg);

            if ($this->client === false) throw new \Exception("Failed to connect to Akumuli: " . $errmsg);

        } catch (\Exception $e) {
            $this->d->dpr($e);
        }
    }

    /**
     * Reads nfcapd files since $datestart and imports them into Akumuli
     * @param \DateTime $datestart
     * @throws \Exception
     */
    function import(\DateTime $datestart) {

        $source_path = \Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . \Config::$cfg['nfdump']['profile'];
        $sources = scandir($source_path);
        if(!is_array($sources)) throw new \Exception("Could not read nfdump profile directory " . $source_path);

        $c = 0; // only for not flooding the database right now

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
                                $query = $this->get_redis_string($source, $stats_path);

                                // write redis-compatible string to socket
                                if($c == 0) fwrite($this->client, $query);
                                if($c == 0) $this->d->dpr(stream_get_contents($this->client));

                                //  curl localhost:8181/api/query -d "{'select':'flows'}"
                                $c++; // todo remove
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

    /**
     * Gets statistics from nfdump and flattens them to a redis-compatible string
     * @param string $source
     * @param string $path
     * @return string
     */
    function get_redis_string(string $source, string $path) {

        // set options and get netflow summary statistics (-I)
        $nfdump = \NfDump::getInstance();
        $nfdump->setOption("-I", null);
        $nfdump->setOption("-r", $path);
        $nfdump->setOption("-M", $source);
        $input = $nfdump->execute();

        $fields = $tags = $values = array();
        $time_first = "";

        // $input data is an array of lines looking like this:
        // flows_tcp: 323829
        foreach($input as $line) {

            list($type, $value) = explode(": ", $line);

            // we only need flows/packets/bytes values, the source and the timestamp
            if(preg_match("/^(flows|packets|bytes)/i", $type)) {
                $fields[] = "net." . strtolower($type);
                $values[] = (int)$value;
            } elseif("Ident" == $type) {
                $tags["source"] = $value;
            } elseif("First" == $type) {
                $d = new \DateTime();
                $d->setTimestamp((int)$value);
                $time_first = $d->format("Ymd\THis");
            }
        }

        // writes assume redis protocol. first byte identification:
        // "+" simple strings  "-" errors  ":" integers  "$" bulk strings  "*" array
        $query = "+" . implode("|", $fields) . " source=" . $tags["source"] . "\r\n" .
            "+" . $time_first . "\r\n" . // timestamp
            "*" . count($fields) . "\r\n"; // length of following array

        // add the $values corresponding to $fields
        foreach($values as $v) $query .= ":" . $v . "\r\n";

        $this->d->dpr(array($query, $path));
        return $query;
    }

    function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
    }

    public function insert() {

    }
}