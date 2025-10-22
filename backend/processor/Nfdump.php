<?php

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Misc;

class Nfdump implements Processor {
    public static ?self $_instance = null;
    private array $cfg = [
        'env' => [],
        'option' => [],
        'format' => null,
        'filter' => [],
    ];
    private array $clean;
    private readonly Debug $d;

    public function __construct() {
        $this->d = Debug::getInstance();
        $this->clean = $this->cfg;
        $this->reset();
    }

    public static function getInstance(): self {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Sets an option's value.
     *
     * @param mixed $value
     */
    public function setOption(string $option, $value): void {
        switch ($option) {
            case '-M': // set sources
                // only sources specified in settings allowed
                $queried_sources = explode(':', (string) $value);
                foreach ($queried_sources as $s) {
                    if (!\in_array($s, Config::$cfg['general']['sources'], true)) {
                        continue;
                    }
                    $this->cfg['env']['sources'][] = $s;
                }

                // cancel if no sources remain
                if (empty($this->cfg['env']['sources'])) {
                    break;
                }

                // set sources path
                $this->cfg['option'][$option] = implode(\DIRECTORY_SEPARATOR, [
                    $this->cfg['env']['profiles-data'],
                    $this->cfg['env']['profile'],
                    implode(':', $this->cfg['env']['sources']),
                ]);

                break;

            case '-R': // set path
                $this->cfg['option'][$option] = $this->convert_date_to_path($value[0], $value[1]);

                break;

            case '-o': // set output format
                $this->cfg['format'] = $value;

                break;

            default:
                $this->cfg['option'][$option] = $value;
                $this->cfg['option']['-o'] = 'csv'; // always get parsable data todo user-selectable? calculations bps/bpp/pps not in csv

                break;
        }
    }

    /**
     * Sets a filter's value.
     */
    public function setFilter(string $filter): void {
        $this->cfg['filter'] = $filter;
    }

    /**
     * Executes the nfdump command, tries to throw an exception based on the return code.
     *
     * @throws \Exception
     */
    public function execute(): array {
        $output = [];
        $processes = [];
        $return = '';
        $timer = microtime(true);
        $filter = (empty($this->cfg['filter'])) ? '' : ' ' . escapeshellarg((string) $this->cfg['filter']);
        $command = $this->cfg['env']['bin'] . ' ' . $this->flatten($this->cfg['option']) . $filter . ' 2>&1';
        $this->d->log('Trying to execute ' . $command, LOG_DEBUG);

        // check for already running nfdump processes
        // use pgrep if available, fallback to ps, or skip check if neither available
        $bin_name = basename($this->cfg['env']['bin']);
        $process_count = Misc::countProcessesByName($bin_name);

        if ($process_count > (int) Config::$cfg['nfdump']['max-processes']) {
            throw new \Exception('There already are ' . $process_count . ' processes of NfDump running!');
        }

        // execute nfdump
        exec($command, $output, $return);

        // prevent logging the command usage description
        if (isset($output[0]) && preg_match('/^usage/i', $output[0])) {
            $output = [];
        }

        switch ($return) {
            case 127:
                throw new \Exception('NfDump: Failed to start process. Is nfdump installed? <br><b>Output:</b> ' . implode(' ', $output));

            case 255:
                throw new \Exception('NfDump: Initialization failed. ' . $command . '<br><b>Output:</b> ' . implode(' ', $output));

            case 254:
                throw new \Exception('NfDump: Error in filter syntax. <br><b>Output:</b> ' . implode(' ', $output));

            case 250:
                throw new \Exception('NfDump: Internal error. <br><b>Output:</b> ' . implode(' ', $output));
        }

        // add command to output
        array_unshift($output, $command);

        // if last element contains a colon, it's not a csv
        if (str_contains($output[\count($output) - 1], ':')) {
            return $output; // return output if it is a flows/packets/bytes dump
        }

        // remove the 3 summary lines at the end of the csv output
        $output = \array_slice($output, 0, -3);

        // slice csv (only return the fields actually wanted)
        $field_ids_active = [];
        $parsed_header = false;
        $format = false;
        if (isset($this->cfg['format'])) {
            $format = $this->get_output_format($this->cfg['format']);
        }

        foreach ($output as $i => &$line) {
            if ($i === 0) {
                continue;
            } // skip nfdump command
            $line = str_getcsv($line, ',');
            $temp_line = [];

            if (\count($line) === 1 || preg_match('/limit/', $line[0]) || preg_match('/error/', $line[0])) { // probably an error message or warning. add to command
                $output[0] .= ' <br><b>' . $line[0] . '</b>';
                unset($output[$i]);

                continue;
            }
            if (!\is_array($format)) {
                $format = $line;
            } // set first valid line as header if not already defined

            foreach ($line as $field_id => $field) {
                // heading has the field identifiers. fill $fields_active with all active fields
                if ($parsed_header === false) {
                    if (\in_array($field, $format, true)) {
                        $field_ids_active[array_search($field, $format, true)] = $field_id;
                    }
                }

                // remove field if not in $fields_active
                if (\in_array($field_id, $field_ids_active, true)) {
                    $temp_line[array_search($field_id, $field_ids_active, true)] = $field;
                }
            }

            $parsed_header = true;
            ksort($temp_line);
            $line = array_values($temp_line);
        }

        // add execution time to output
        $output[0] .= '<br><b>Execution time:</b> ' . round(microtime(true) - $timer, 3) . ' seconds';

        return array_values($output);
    }

    /**
     * Reset config.
     */
    public function reset(): void {
        $this->clean['env'] = [
            'bin' => Config::$cfg['nfdump']['binary'],
            'profiles-data' => Config::$cfg['nfdump']['profiles-data'],
            'profile' => Config::$cfg['nfdump']['profile'],
            'sources' => [],
        ];
        $this->cfg = $this->clean;
    }

    /**
     * Converts a time range to a nfcapd file range
     * Ensures that files actually exist.
     *
     * @throws \Exception
     */
    public function convert_date_to_path(int $datestart, int $dateend): string {
        $start = new \DateTime();
        $end = new \DateTime();
        $start->setTimestamp((int) $datestart - ($datestart % 300));
        $end->setTimestamp((int) $dateend - ($dateend % 300));
        $filestart = $fileend = '-';
        $filestartexists = false;
        $fileendexists = false;
        $sourcepath = $this->cfg['env']['profiles-data'] . \DIRECTORY_SEPARATOR . $this->cfg['env']['profile'] . \DIRECTORY_SEPARATOR;

        // if start file does not exist, increment by 5 minutes and try again
        while ($filestartexists === false) {
            if ($start >= $end) {
                break;
            }

            foreach ($this->cfg['env']['sources'] as $source) {
                if (file_exists($sourcepath . $source . \DIRECTORY_SEPARATOR . $filestart)) {
                    $filestartexists = true;
                }
            }

            $pathstart = $start->format('Y/m/d') . \DIRECTORY_SEPARATOR;
            $filestart = $pathstart . 'nfcapd.' . $start->format('YmdHi');
            $start->add(new \DateInterval('PT5M'));
        }

        // if end file does not exist, subtract by 5 minutes and try again
        while ($fileendexists === false) {
            if ($end === $start) { // strict comparison won't work
                $fileend = $filestart;

                break;
            }

            foreach ($this->cfg['env']['sources'] as $source) {
                if (file_exists($sourcepath . $source . \DIRECTORY_SEPARATOR . $fileend)) {
                    $fileendexists = true;
                }
            }

            $pathend = $end->format('Y/m/d') . \DIRECTORY_SEPARATOR;
            $fileend = $pathend . 'nfcapd.' . $end->format('YmdHi');
            $end->sub(new \DateInterval('PT5M'));
        }

        return $filestart . PATH_SEPARATOR . $fileend;
    }

    public function get_output_format($format): array {
        // todo calculations like bps/pps? flows? concatenate sa/sp to sap?
        return match ($format) {
            'line' => ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'ipkt', 'ibyt', 'fl'],
            'long' => ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'flg', 'stos', 'dtos', 'ipkt', 'ibyt', 'fl'],
            'extended' => ['ts', 'td', 'pr', 'sa', 'sp', 'da', 'dp', 'ipkt', 'ibyt', 'ibps', 'ipps', 'ibpp'],
            'full' => ['ts', 'te', 'td', 'sa', 'da', 'sp', 'dp', 'pr', 'flg', 'fwd', 'stos', 'ipkt', 'ibyt', 'opkt', 'obyt', 'in', 'out', 'sas', 'das', 'smk', 'dmk', 'dtos', 'dir', 'nh', 'nhb', 'svln', 'dvln', 'ismc', 'odmc', 'idmc', 'osmc', 'mpls1', 'mpls2', 'mpls3', 'mpls4', 'mpls5', 'mpls6', 'mpls7', 'mpls8', 'mpls9', 'mpls10', 'cl', 'sl', 'al', 'ra', 'eng', 'exid', 'tr'],
            default => explode(' ', str_replace(['fmt:', '%'], '', (string) $format)),
        };
    }

    /**
     * Concatenates key and value of supplied array.
     */
    private function flatten(array $array): string {
        $output = '';

        foreach ($array as $key => $value) {
            if ($value === null) {
                $output .= $key . ' ';
            } else {
                $output .= \is_int($key) ?: $key . ' ' . escapeshellarg((string) $value) . ' ';
            }
        }

        return $output;
    }
}
