<?php

class API {

    public function stats(DateTime $datestart, DateTime $dateend) {
        Config::$db->stats();
    }
}