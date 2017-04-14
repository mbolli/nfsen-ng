<?php
interface Datasource {

    public function insert();
    public function import(DateTime $timestart);

}