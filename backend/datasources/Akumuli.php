<?php

namespace mbolli\nfsen_ng\datasources;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;

class Akumuli implements Datasource {
    private readonly Debug $d;
    private $client;

    public function __construct() {
        $this->d = Debug::getInstance();
        $this->connect();
    }

    public function __destruct() {
        if (\is_resource($this->client)) {
            fclose($this->client);
        }
    }

    /**
     * connects to TCP socket.
     */
    public function connect(): void {
        try {
            $this->client = stream_socket_client('tcp://' . Config::$cfg['db']['akumuli']['host'] . ':' . Config::$cfg['db']['akumuli']['port'], $errno, $errmsg);

            if ($this->client === false) {
                throw new \Exception('Failed to connect to Akumuli: ' . $errmsg);
            }
        } catch (\Exception $e) {
            $this->d->dpr($e);
        }
    }

    /**
     * Convert data to redis-compatible string and write to Akumuli.
     */
    public function write(array $data): bool {
        $fields = array_keys($data['fields']);
        $values = array_values($data['fields']);

        // writes assume redis protocol. first byte identification:
        // "+" simple strings  "-" errors  ":" integers  "$" bulk strings  "*" array
        $query = '+' . implode('|', $fields) . ' source=' . $data['source'] . "\r\n"
            . '+' . $data['date_iso'] . "\r\n" // timestamp
            . '*' . \count($fields) . "\r\n"; // length of following array

        // add the $values corresponding to $fields
        foreach ($values as $v) {
            $query .= ':' . $v . "\r\n";
        }

        $this->d->dpr([$query]);

        // write redis-compatible string to socket
        fwrite($this->client, $query);

        return stream_get_contents($this->client);
        // to read:
        // curl localhost:8181/api/query -d "{'select':'flows'}"
    }

    /**
     * Gets data for plotting the graph in the frontend.
     * Each row in $return['data'] will be one line in the graph.
     * The lines can be
     *   * protocols - $sources must not contain more than one source (legend e.g. gateway_flows_udp, gateway_flows_tcp)
     *   * sources - $protocols must not contain more than one protocol (legend e.g. gateway_traffic_icmp, othersource_traffic_icmp).
     *
     * @param int    $start     timestamp
     * @param int    $end       timestamp
     * @param array  $sources   subset of sources specified in settings
     * @param array  $protocols UDP/TCP/ICMP/other
     * @param string $type      flows/packets/traffic
     *
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
    public function get_graph_data(int $start, int $end, array $sources, array $protocols, array $ports, string $type = 'flows', string $display = 'sources'): array|string {
        // TODO: Implement get_graph_data() method.
        return [];
    }

    /**
     * Gets the timestamps of the first and last entry in the datasource (for this specific source).
     *
     * @return array (timestampfirst, timestamplast)
     */
    public function date_boundaries(string $source): array {
        // TODO: Implement date_boundaries() method.
        return [];
    }

    /**
     * Gets the timestamp of the last update of the datasource (for this specific source).
     */
    public function last_update(string $source, int $port = 0): int {
        // TODO: Implement last_update() method.
        return 0;
    }

    /**
     * Gets the path where the datasource's data is stored.
     */
    public function get_data_path(): string {
        // TODO: Implement get_data_path() method.
        return '';
    }

    /**
     * Removes all existing data for every source in $sources.
     * If $sources is empty, remove all existing data.
     */
    public function reset(array $sources): bool {
        // TODO: Implement reset() method.
        return true;
    }
}
