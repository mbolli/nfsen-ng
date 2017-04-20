<?php

class API {
    private $method;
    private $request;
    private $input;
    private $d;

    public function __construct() {
        $this->d = Debug::getInstance();

        // get the HTTP method, path and body of the request
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->request = explode('/', trim($_GET['request'],'/'));
        $this->input = json_decode(file_get_contents('php://input'),true);

        header('Content-type: application/json');

        // call correct method
        if(!method_exists($this, $this->request[0])) $this->error(404);

        // remove method name from $_REQUEST
        $_REQUEST = array_filter($_REQUEST, function($x) { return $x !== $this->request[0]; });

        $method = new ReflectionMethod($this, $this->request[0]);

        // check number of parameters
        if ($method->getNumberOfRequiredParameters() > count($_REQUEST)) $this->error(400);

        $args = array();
        // iterate over each parameter
        foreach($method->getParameters() as $arg) {
            if(!isset($_REQUEST[$arg->name])) $this->error(400);

            // make sure the data types are correct
            switch($arg->getType()) {
                case 'int':
                    if (!is_numeric($_REQUEST[$arg->name])) $this->error(400);
                    $args[$arg->name] = intval($_REQUEST[$arg->name]);
                    break;
                case 'array':
                    if (!is_array($_REQUEST[$arg->name])) $this->error(400);
                    $args[$arg->name] = $_REQUEST[$arg->name];
                    break;
                case 'string':
                    if (!is_string($_REQUEST[$arg->name])) $this->error(400);
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

        $response = array('code' => $code, 'error' => '');
        switch($code) {
            case 400: $response['error'] = '400 - Bad Request. Probably wrong or not enough arguments. ' . $msg; break;
            case 401: $response['error'] = '400 - Unauthorized. ' . $msg; break;
            case 403: $response['error'] = '400 - Forbidden. ' . $msg; break;
            case 404: $response['error'] = '400 - Not found. ' . $msg; break;
        }
        echo json_encode($response);
        exit();
    }

    public function stats(int $top, string $for, string $order, string $limit, array $output) {}

    public function flows(int $datestart, int $dateend, string $filter, int $limit, array $aggregate, string $sort, array $output) {}

    /**
     * Get data to build a graph
     * @param int $datestart
     * @param int $dateend
     * @param array $sources
     * @param array $protocols
     * @param string $type
     * @return array|string
     */
    public function graph(int $datestart, int $dateend, array $sources, array $protocols, string $type) {
        $graph = Config::$db->stats($datestart, $dateend, $sources, $protocols, $type);
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

        $definedSources = Config::$cfg['general']['sources'];
        $sources = array();
        foreach ($definedSources as $source) {
            $sources[$source] = $rrd->date_boundaries($source);
        }

        $stored_output_formats = array(); // todo implement

        $stored_filters = array(); // todo implement
        return array('sources' => $sources, 'stored_output_formats' => $stored_output_formats, 'stored_filters' => $stored_filters);
    }
}