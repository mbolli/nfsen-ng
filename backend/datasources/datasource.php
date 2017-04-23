<?php
interface Datasource {

    public function write(array $data);
    public function stats(int $start, int $end, array $sources, array $protocols, string $type);
    public function date_boundaries(string $source) : array;
    public function last_update(string $source) : int;

}