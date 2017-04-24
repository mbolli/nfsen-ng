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


    /**
     * Gets data for plotting the graph in the frontend.
     * Each row in $return['data'] will be one line in the graph.
     * The lines can be
     *   * protocols - $sources must not contain more than one source (legend e.g. gateway_flows_udp, gateway_flows_tcp)
     *   * sources - $protocols must not contain more than one protocol (legend e.g. gateway_traffic_icmp, othersource_traffic_icmp)
     * @param int $start timestamp
     * @param int $end timestamp
     * @param array $sources subset of sources specified in settings
     * @param array $protocols UDP/TCP/ICMP/other
     * @param string $type flows/packets/traffic
     * @return array in the following format:
     *
     * $return = array(
     *  'start' => 1490484600,      // timestamp of first value
     *  'end' => 1490652000,        // timestamp of last value
     *  'step' => 300,              // resolution of the returned data in seconds. lowest value would probably be 300 = 5 minutes
     *  'data' => array(
     *      0 => array(
     *          'legend' => 'source_type_protocol',
     *          'data' => array(
     *          1490484600 => 33.998333333333,
     *          1490485200 => 37.005, ...
     *          )
     *      ),
     *      1 => array( e.g. gateway_flows_udp ...)
     *  )
     * );
     */
    public function get_graph_data(int $start, int $end, array $sources, array $protocols, string $type)
    {
        // TODO: Implement get_graph_data() method.
    }

    /**
     * Gets the timestamps of the first and last entry in the datasource (for this specific source)
     * @param string $source
     * @return array (timestampfirst, timestamplast)
     */
    public function date_boundaries(string $source): array
    {
        // TODO: Implement date_boundaries() method.
    }

    /**
     * Gets the timestamp of the last update of the datasource (for this specific source)
     * @param string $source
     * @return int
     */
    public function last_update(string $source): int
    {
        // TODO: Implement last_update() method.
    }

    /**
     * Gets the path where the datasource's data is stored
     * @return string
     */
    public function get_data_path()
    {
        // TODO: Implement get_data_path() method.
    }
}