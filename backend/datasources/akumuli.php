<?php
namespace datasources;

class Akumuli implements Datasource  {

    private $d;
    private $client;

    public function __construct() {
        $this->d = \common\Debug::getInstance();
        $this->connect();
    }

    /**
     * connects to TCP socket
     */
    function connect() {
        try {
            $this->client = stream_socket_client("tcp://" . \common\Config::$cfg['db']['akumuli']['host'] . ":" . \common\Config::$cfg['db']['akumuli']['port'], $errno, $errmsg);

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


    }

    /**
     * Convert data to redis-compatible string and write to Akumuli
     * @param array $data
     * @return string
     */
    function write(array $data) {

        $fields = array_keys($data['fields']);
        $values = array_values($data['fields']);

        // writes assume redis protocol. first byte identification:
        // "+" simple strings  "-" errors  ":" integers  "$" bulk strings  "*" array
        $query = "+" . implode("|", $fields) . " source=" . $data['source'] . "\r\n" .
            "+" . $data['date_iso'] . "\r\n" . // timestamp
            "*" . count($fields) . "\r\n"; // length of following array

        // add the $values corresponding to $fields
        foreach($values as $v) $query .= ":" . $v . "\r\n";

        $this->d->dpr(array($query));

        // write redis-compatible string to socket
        fwrite($this->client, $query);
        return stream_get_contents($this->client);

        // to read:
        // curl localhost:8181/api/query -d "{'select':'flows'}"
    }

    function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
    }

    public function get_graph_data(int $start, int $end, array $sources, array $protocols, string $type) {
        // TODO: Implement stats() method.
    }

    public function date_boundaries(string $source): array {
        // TODO: Implement date_boundaries() method.
    }

    public function last_update(string $source): int {
        // TODO: Implement last_update() method.
    }
}