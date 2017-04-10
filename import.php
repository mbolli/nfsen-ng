<?php
ini_set('display_errors', E_ALL);
$i = new Import();


class Import {

    private $cfg;
    private $d;
    private $client;

	function __construct() {
        $this->cfg = Config::getInstance();
        $this->d = Debug::getInstance();
        $this->d->dpr($this->cfg->getAll());
	}

	function connect() {
        $this->client = stream_socket_client("tcp://" . $this->cfg->get('host') . ":" . $this->cfg->get('tcpport'), $errno, $errmsg);
        if ($this->client === false) {
            throw new UnexpectedValueException("Failed to connect: " . $errmsg);
        }

        fwrite($this->client, "some stuff");
        echo stream_get_contents($this->client);
    }

    function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
        }
    }
}

class Config {

	private $cfg = array();
    public static $_instance;

    function __construct() {
        $array = parse_ini_file(getcwd() . DIRECTORY_SEPARATOR . "settings.ini", true, INI_SCANNER_TYPED);
        if ($array !== false) {
            $this->cfg = $array;
        }
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

	public function get(string $key) {
        // todo explode .
		if (array_key_exists($key, $this->cfg)) {
			return $this->cfg[$key];
		}
		return false;
	}

	public function set(string $key, $value) {

        // todo write back to file?
		$this->cfg[$key] = $value;
	}

	public function getAll() {
        return $this->cfg;
    }
}

class Debug {
    private $stopwatch;
    private $debug;
    public static $_instance;

    function __construct() {
        $this->stopwatch = microtime(true);
        $this->debug = true;
    }

    public static function getInstance() {
        if (!(self::$_instance instanceof self)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * stopWatch function.
     * Returns the time passed from initialization.
     * @param bool $precise
     * @return float|mixed
     */
    public function stopWatch(bool $precise = false) {
        $result = microtime(true) - $this->stopwatch;
        if ($precise === false) $result = round($result, 4);
        return $result;
    }

    /**
     * dpr function.
     * Debug print. Prints the supplied string with the time passed from initialization.
     * @param $mixed
     * @param bool $with_stopwatch
     */
    public function dpr($mixed, bool $with_stopwatch = true) {
        if($this->debug) {
            if($with_stopwatch === true) echo "<br /><span style='color: green;'>" . $this->stopWatch() . "</span> ";
            if(is_array($mixed)) var_dump($mixed);
            else echo $mixed;
        }
    }

}
