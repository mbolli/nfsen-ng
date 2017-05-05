<?php
namespace api;

class API {
    private $method;
    private $request;
    private $input;
    private $d;

    public function __construct() {
        $this->d = \common\Debug::getInstance();

        // get the HTTP method, path and body of the request
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->request = explode('/', trim($_GET['request'],'/'));
        $this->input = json_decode(file_get_contents('php://input'),true);

        header('Content-type: application/json');

        // call correct method
        if(!method_exists($this, $this->request[0])) $this->error(404);

        // remove method name from $_REQUEST
        $_REQUEST = array_filter($_REQUEST, function($x) { return $x !== $this->request[0]; });

        $method = new \ReflectionMethod($this, $this->request[0]);

        // check number of parameters
        if ($method->getNumberOfRequiredParameters() > count($_REQUEST)) $this->error(400, 'Not enough parameters');

        $args = array();
        // iterate over each parameter
        foreach($method->getParameters() as $arg) {
            if(!isset($_REQUEST[$arg->name])) $this->error(400, 'Expected parameter ' . $arg->name);

            // make sure the data types are correct
            switch($arg->getType()) {
                case 'int':
                    if (!is_numeric($_REQUEST[$arg->name])) $this->error(400, 'Expected type int for ' . $arg->name);
                    $args[$arg->name] = intval($_REQUEST[$arg->name]);
                    break;
                case 'array':
                    if (!is_array($_REQUEST[$arg->name])) $this->error(400, 'Expected type array for ' . $arg->name);
                    $args[$arg->name] = $_REQUEST[$arg->name];
                    break;
                case 'string':
                    if (!is_string($_REQUEST[$arg->name])) $this->error(400, 'Expected type string for ' . $arg->name);
                    $args[$arg->name] = $_REQUEST[$arg->name];
                    break;
                default:
                    $args[$arg->name] = $_REQUEST[$arg->name];
            }
        }

        // write output
        echo json_encode($this->{$this->request[0]}(...array_values($args)));

    }

    /**
     * Helper function, returns the http status and exits the application.
     * @param int $code
     * @param string $msg
     */
    public function error(int $code, $msg = '') {
        http_response_code($code);
        $debug = \common\Debug::getInstance();

        $response = array('code' => $code, 'error' => '');
        switch($code) {
            case 400:
                $response['error'] = '400 - Bad Request. ' . (empty($msg) ? 'Probably wrong or not enough arguments.' : $msg);
                $debug->log($response['error'], LOG_INFO);
                break;
            case 401:
                $response['error'] = '401 - Unauthorized. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 403:
                $response['error'] = '403 - Forbidden. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 404:
                $response['error'] = '404 - Not found. ' . $msg;
                $debug->log($response['error'], LOG_WARNING);
                break;
            case 503:
                $response['error'] = '503 - Service unavailable. ' . $msg;
                $debug->log($response['error'], LOG_ERR);
                break;
        }
        echo json_encode($response);
        exit();
    }

    /**
     * Execute the nfdump command to get statistics
     * @param int $datestart
     * @param int $dateend
     * @param array $sources
     * @param string $filter
     * @param int $top
     * @param string $for
     * @param string $limit
     * @param array $output
     * @return array
     */
    public function stats(int $datestart, int $dateend, array $sources, string $filter, int $top, string $for, string $limit, array $output) {
        $sources = implode(':', $sources);

        $nfdump = new \common\NfDump();
        $nfdump->setOption('-M', $sources); // multiple sources
        $nfdump->setOption('-T', null); // output = flows
        $nfdump->setOption('-R', array($datestart, $dateend)); // date range
        $nfdump->setOption('-n', $top);
        $nfdump->setOption('-s', $for);
        $nfdump->setOption('-l', $limit); // todo -L for traffic, -l for packets
        if (isset($output['IPv6'])) $nfdump->setOption('-6', null);
        $nfdump->setFilter($filter);

        try {
            $return = $nfdump->execute();
        } catch (\Exception $e) {
            $this->error(503, $e->getMessage());
        }

        return $return;
    }

    /**
     * Execute the nfdump command to get flows
     * @param int $datestart
     * @param int $dateend
     * @param array $sources
     * @param string $filter
     * @param int $limit
     * @param array $aggregate
     * @param string $sort
     * @param array $output
     * @return array
     */
    public function flows(int $datestart, int $dateend, array $sources, string $filter, int $limit, array $aggregate, string $sort, array $output) {
        // nfdump -M /srv/nfsen/profiles-data/live/tiber:n048:gate:swibi:n055:swi6  -T  -r 2017/04/10/nfcapd.201704101150 -c 20
        $sources = implode(':', $sources);
        $aggregate_command = '';
        foreach($aggregate as $aggregation => $option) {
            if (empty($option)) continue;
            if ($option === 'bidirectional') {
                $aggregate_command = '-B';
                break;
            } else {
                $aggregate_command = '-A' . $option; // no space inbetween
            }
        }

        $nfdump = new \common\NfDump();
        $nfdump->setOption('-M', $sources); // multiple sources
        $nfdump->setOption('-T', null); // output = flows
        $nfdump->setOption('-R', array($datestart, $dateend)); // date range
        $nfdump->setOption('-c', $limit); // limit
        $nfdump->setOption('-o', $output['format']);
        if (!empty($sort)) $nfdump->setOption('-O tstart', $sort); // todo other sorting mechanisms?
        if (isset($output['IPv6'])) $nfdump->setOption('-6', $output['IPv6']);
        if (!empty($aggregate_command)) $nfdump->setOption('-a', $aggregate_command);
        $nfdump->setFilter($filter);

        try {
            $return = $nfdump->execute();
        } catch (\Exception $e) {
            $this->error(503, $e->getMessage());
        }

        return $return;
    }

    /**
     * Get data to build a graph
     * @param int $datestart
     * @param int $dateend
     * @param array $sources
     * @param array $protocols
     * @param array $ports
     * @param string $type
     * @param string $display
     * @return array|string
     */
    public function graph(int $datestart, int $dateend, array $sources, array $protocols, array $ports, string $type, string $display) {
        $graph = \common\Config::$db->get_graph_data($datestart, $dateend, $sources, $protocols, $ports, $type, $display);
        if (!is_array($graph)) $this->error(400, $graph);
        return $graph;
    }

    public function graph_stats() {}

    /**
     * Get config info
     * @return array
     */
    public function config() {
        http_response_code(200);

        $rrd = new \datasources\RRD();

        $definedSources = \common\Config::$cfg['general']['sources'];
        $sources = array();
        foreach ($definedSources as $source) {
            $sources[$source] = $rrd->date_boundaries($source);
        }

        $stored_output_formats = array(); // todo implement

        $stored_filters = array(); // todo implement
        return array('sources' => $sources, 'stored_output_formats' => $stored_output_formats, 'stored_filters' => $stored_filters);
    }
}