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

            // writes assume Redis protocol
            // For Simple Strings the first byte of the reply is "+"
            // For Errors the first byte of the reply is "-"
            // For Integers the first byte of the reply is ":"
            // For Bulk Strings the first byte of the reply is "$"
            // For Arrays the first byte of the reply is "*"
            $nfdump = \NfDump::getInstance();
            $nfdump->setOption("-I", null);
            $nfdump->setOption("-r", "2017/03/03/nfcapd.201703030215");
            $nfdump->setOption("-M", "gate");
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

            $query = "+" . implode("|", $fields) . " source=" . $tags["source"] . "\r\n" .
                "+" . $time_first . "\r\n" . // timestamp
                "*" . count($fields) . "\r\n"; // length of following array

            foreach($values as $v) $query .= ":" . $v . "\r\n";


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