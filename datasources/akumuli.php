<?php
namespace datasources;

class Akumuli implements \Datasource  {

    private $d;
    private $client;

    public function __construct() {
        $this->d = \Debug::getInstance();
        $this->connect();
    }

    function connect() {
        try {
            $this->client = stream_socket_client("tcp://" . \Config::$cfg['db']['akumuli']['host'] . ":" . \Config::$cfg['db']['akumuli']['port'], $errno, $errmsg);

            if ($this->client === false) throw new \Exception("Failed to connect to Akumuli: " . $errmsg);

        } catch (\Exception $e) {
            $this->d->dpr($e);
        }
    }

    function import(\DateTime $datestart) {

        $source_path = \Config::$cfg['nfdump']['profiles-data'] . DIRECTORY_SEPARATOR . \Config::$cfg['nfdump']['profile'];
        $sources = scandir($source_path);
        foreach ($sources as $source) {
            if(in_array($source, \Config::$cfg['general']['sources'])) {
                $today = new \DateTime();
                $date = clone $datestart;
                while ($date != $today) {
                    $scan = array($source_path, $source, $date->format("Y"), $date->format("m"), $date->format("d"));
                    $scan_path = implode(DIRECTORY_SEPARATOR, $scan);

                    if(file_exists($scan_path)) {
                        $scan_files = scandir($scan_path);

                        foreach($scan_files as $file) {
                            if(!in_array($file, array(".", ".."))) {
                                $stats_path = implode(DIRECTORY_SEPARATOR, array_slice($scan, 2, 5)) . DIRECTORY_SEPARATOR . $file;
                                $query = $this->get_redis_string($source, $stats_path);

                                // fwrite($this->client, "+net.flows|");
                                // $this->d->dpr(stream_get_contents($this->client));
                            }
                        }
                    } else {
                        $this->d->dpr($scan_path . " does not exist!");
                    }
                    $date->modify("+1 day");
                }
            }
        }
    }

    function get_redis_string(string $source, string $path) {
        $nfdump = \NfDump::getInstance();
        $nfdump->setOption("-I", null);
        $nfdump->setOption("-r", $path);
        $nfdump->setOption("-M", $source);
        $input = $nfdump->execute();

        $fields = $tags = $values = array();
        $time_first = "";
        foreach($input as $line) {
            list($type, $value) = explode(": ", $line);
            if(preg_match("/^(flows|packets|bytes)/i", $type)) {
                $fields[] = "net." . strtolower($type);
                $values[] = (int)$value;
            } elseif("Ident" == $type) {
                $tags["source"] = $value;
            } elseif("First" == $type) {
                $d = new \DateTime();
                $d->setTimestamp((int)$value);
                $time_first = $d->format(DATE_ATOM);
            }
        }

        // writes assume redis protocol. first byte identification:
        // "+" simple strings  "-" errors  ":" integers  "$" bulk strings  "*" array
        $query = "+" . implode("|", $fields) . " source=" . $tags["source"] . "\r\n" .
            "+" . $time_first . "\r\n" . // timestamp
            "*" . count($fields) . "\r\n"; // length of following array

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