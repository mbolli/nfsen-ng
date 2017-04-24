<?php
namespace datasources;

interface Datasource {

    /**
     * Writes a new record to the datasource.
     * Expects an array in the following format:
     * $data => array(
     *  'source' =>         'name_of_souce',
     *  'date_timestamp' => 000000000,
     *  'date_iso' =>       'Ymd\THis',
     *  'fields' =>         array(
     "      'flows',
     "      'flows_tcp',
     "      'flows_udp',
     "      'flows_icmp',
     "      'flows_other',
     "      'packets',
     "      'packets_tcp',
     "      'packets_udp',
     "      'packets_icmp',
     "      'packets_other',
     "      'bytes',
     "      'bytes_tcp',
     "      'bytes_udp',
     "      'bytes_icmp',
     "      'bytes_other')
     *  );
     * @param array $data
     * @return bool TRUE on success or FALSE on failure.
     * @throws \Exception on error
     */
    public function write(array $data);

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
    public function get_graph_data(int $start, int $end, array $sources, array $protocols, string $type);

    /**
     * Gets the timestamps of the first and last entry in the datasource (for this specific source)
     * @param string $source
     * @return array (timestampfirst, timestamplast)
     */
    public function date_boundaries(string $source) : array;

    /**
     * Gets the timestamp of the last update of the datasource (for this specific source)
     * @param string $source
     * @return int
     */
    public function last_update(string $source) : int;

    /**
     * Gets the path where the datasource's data is stored
     * @return string
     */
    public function get_data_path();

}