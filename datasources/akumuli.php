<?php
namespace datasources;

class Akumuli implements \Datasource  {

    private $cfg;
    private $d;
    private $client;

    public function __construct() {
        $this->cfg = \Config::getInstance();
        $this->d = \Debug::getInstance();
        $this->connect();
    }

    function connect() {
        try {
            $this->client = stream_socket_client("tcp://" . $this->cfg->get('db_akumuli.host') . ":" . $this->cfg->get('db_akumuli.port'), $errno, $errmsg);

            if ($this->client === false) throw new \Exception("Failed to connect to Akumuli: " . $errmsg);

            // writes assume Redis protocol
            // For Simple Strings the first byte of the reply is "+"
            // For Errors the first byte of the reply is "-"
            // For Integers the first byte of the reply is ":"
            // For Bulk Strings the first byte of the reply is "$"
            // For Arrays the first byte of the reply is "*"

            $types = array("flows", "packets", "traffic");
            $fields = array();
            $tags = array("source" => "machine1", "port" => 47785);
            $ts = "20141210T074343";

            foreach($types as $type) {
                $fields[] = "net." . $type;
                foreach ($this->cfg->get('general.protocol_list') as $proto) {
                    $fields[] = "net." . $type . "_" . $proto;
                }
            }

            $query = "+" . implode("|", $fields) . " " . implode(" ", $tags) . "\r\n" .
                "+" . $ts . "\r\n" . // timestamp
                "*" . count($fields) . "\r\n"; // length of following array


            $this->d->dpr(array($query));

            // fwrite($this->client, "+net.flows|");
            // $this->d->dpr(stream_get_contents($this->client));

        } catch (\Exception $e) {
            $this->d->dpr($e);
        }
    }

    function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
    }

    public function insert() {

    }
}