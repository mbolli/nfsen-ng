<?php

namespace nfsen_ng\datasources;

interface Datasource {
    /**
     * Writes a new record to the datasource.
     * Expects an array in the following format:
     * $data => array(
     *  'source' =>         'name_of_souce',
     *  'date_timestamp' => 000000000,
     *  'date_iso' =>       'Ymd\THis',
     *  'fields' =>         array(
     *      'flows',
     *      'flows_tcp',
     *      'flows_udp',
     *      'flows_icmp',
     *      'flows_other',
     *      'packets',
     *      'packets_tcp',
     *      'packets_udp',
     *      'packets_icmp',
     *      'packets_other',
     *      'bytes',
     *      'bytes_tcp',
     *      'bytes_udp',
     *      'bytes_icmp',
     *      'bytes_other')
     *  );.
     *
     * @return bool TRUE on success or FALSE on failure
     *
     * @throws \Exception on error
     */
    public function write(array $data);

    /**
     * Gets data for plotting the graph in the frontend.
     * Each row in $return['data'] will be a time point in the graph.
     * The lines can be
     *   * protocols - $sources must not contain more than one source (legend e.g. gateway_flows_udp, gateway_flows_tcp)
     *   * sources - $protocols must not contain more than one protocol (legend e.g. gateway_traffic_icmp, othersource_traffic_icmp)
     *   * ports.
     *
     * @param int    $start     timestamp
     * @param int    $end       timestamp
     * @param array  $sources   subset of sources specified in settings
     * @param array  $protocols UDP/TCP/ICMP/other
     * @param string $type      flows/packets/traffic
     * @param string $display   protocols/sources/ports
     *
     * @return array in the following format:
     *
     * $return = array(
     *  'start' => 1490484600,      // timestamp of first value
     *  'end' => 1490652000,        // timestamp of last value
     *  'step' => 300,              // resolution of the returned data in seconds. lowest value would probably be 300 = 5 minutes
     *  'legend' => array('swi6_flows_tcp', 'gate_flows_tcp'), // legend describes the graph series
     *  'data' => array(
     *      1490484600 => array(33.998333333333, 22.4), // the values/measurements for this specific timestamp are in an array
     *      1490485200 => array(37.005, 132.8282),
     *      ...
     *   )
     * );
     */
    public function get_graph_data(
        int $start,
        int $end,
        array $sources,
        array $protocols,
        array $ports,
        string $type = 'flows',
        string $display = 'sources'
    );

    /**
     * Removes all existing data for every source in $sources.
     * If $sources is empty, remove all existing data.
     */
    public function reset(array $sources): bool;

    /**
     * Gets the timestamps of the first and last entry in the datasource (for this specific source).
     *
     * @return array (timestampfirst, timestamplast)
     */
    public function date_boundaries(string $source): array;

    /**
     * Gets the timestamp of the last update of the datasource (for this specific source).
     */
    public function last_update(string $source, int $port = 0): int;

    /**
     * Gets the path where the datasource's data is stored.
     */
    public function get_data_path(): string;
}
