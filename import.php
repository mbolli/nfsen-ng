<?php

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

