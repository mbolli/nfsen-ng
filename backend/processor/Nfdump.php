<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\Misc;

class Nfdump implements Processor {
    public static ?self $_instance = null;

    /** PID of the currently running nfdump process, or null if idle. Safe as static in single-worker OpenSwoole. */
    public static ?int $runningPid = null;
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
                    if (!\in_array($s, Config::$settings->sources, true)) {
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
                // If aggregation is set and format is json, force csv instead
                if ($value === 'json' && isset($this->cfg['option']['-a'])) {
                    $this->cfg['format'] = 'csv';
                    $this->cfg['option'][$option] = 'csv';
                    $this->d->log('Forcing CSV output format because aggregation (-a) is incompatible with JSON', LOG_INFO);
                } else {
                    $this->cfg['format'] = $value;
                    $this->cfg['option'][$option] = $value;
                }

                break;

            case '-a': // set aggregation
                $this->cfg['option'][$option] = $value;
                // If json format is already set, switch to csv
                if (isset($this->cfg['format']) && $this->cfg['format'] === 'json') {
                    $this->cfg['format'] = 'csv';
                    $this->cfg['option']['-o'] = 'csv';
                    $this->d->log('Forcing CSV output format because aggregation (-a) is incompatible with JSON', LOG_INFO);
                }

                break;

            default:
                $this->cfg['option'][$option] = $value;
                // Set default output format to csv if not already set
                if (!isset($this->cfg['option']['-o'])) {
                    $this->cfg['option']['-o'] = 'csv';
                }

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
     * @return array{command: string, rawOutput: string, decoded: array<array<string, mixed>>, stderr?: string}
     *
     * @throws \Exception
     */
    public function execute(): array {
        $output = [];
        $processes = [];
        $return = '';
        $timer = microtime(true);
        $filter = (empty($this->cfg['filter'])) ? '' : ' ' . escapeshellarg((string) $this->cfg['filter']);
        $command = $this->cfg['env']['bin'] . ' ' . $this->flatten($this->cfg['option']) . $filter;
        $this->d->log('Trying to execute ' . $command, LOG_DEBUG);

        // check for already running nfdump processes
        // use pgrep if available, fallback to ps, or skip check if neither available
        $bin_name = basename((string) $this->cfg['env']['bin']);
        $process_count = Misc::countProcessesByName($bin_name);

        if ($process_count > Config::$settings->nfdumpMaxProcesses) {
            throw new \Exception('There already are ' . $process_count . ' processes of NfDump running!');
        }

        // execute nfdump using proc_open to separate stdout and stderr
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],   // stderr
        ];

        // Remove 2>&1 from command since we're handling stderr separately
        $command = str_replace(' 2>&1', '', $command);

        // Prefix with 'exec' so the shell replaces itself with nfdump directly.
        // Without this, proc_open spawns /bin/sh -c "...", and proc_get_status()['pid']
        // returns the shell's PID — killing the shell leaves nfdump running and completing normally.
        $process = proc_open('exec ' . $command, $descriptorspec, $pipes);

        if (!\is_resource($process)) {
            throw new \Exception('Failed to start nfdump process');
        }

        // Close stdin as we don't need it
        fclose($pipes[0]);

        // Track running PID so the kill-nfdump action can send SIGTERM
        self::$runningPid = proc_get_status($process)['pid'];

        // Read stdout and stderr
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        // Get return code
        $return = proc_close($process);
        self::$runningPid = null;

        // Log stderr if present (but don't fail on benign messages)
        if (!empty($stderr)) {
            $stderrTrimmed = trim($stderr);
            // Only log as warning if it's not the benign "read() error ... Success" message
            if (stripos($stderrTrimmed, 'read() error') !== false && stripos($stderrTrimmed, 'Success') !== false) {
                $this->d->log('NfDump benign stderr message: ' . $stderrTrimmed, LOG_DEBUG);
            } else {
                $this->d->log('NfDump stderr: ' . $stderrTrimmed, LOG_WARNING);
            }
        }

        // Split stdout into lines for processing
        $output = explode("\n", $stdout);
        // Remove empty last line if present
        if (end($output) === '') {
            array_pop($output);
        }

        // Store raw output
        $rawOutput = $stdout;

        // prevent logging the command usage description
        if (isset($output[0]) && stripos($output[0], 'usage') === 0) {
            $output = [];
        }

        // No output at all — nfdump likely failed to open the file (stderr already logged above).
        // Return empty decoded result so Import::start() can move on to the next file.
        if (\count($output) === 0) {
            $result = ['command' => $command, 'rawOutput' => '', 'decoded' => []];
            if (!empty($stderr)) {
                $result['stderr'] = trim($stderr);
            }

            return $result;
        }

        // If we only have 1 line of output, it's likely an error message
        // BUT: single-line JSON output is valid (e.g., from -s statistics with -n 1)
        // "No matching flows" is a normal nfdump output, not a fatal error — return empty decoded.
        if (\count($output) === 1) {
            if (trim($output[0]) === 'No matching flows') {
                return ['command' => $command, 'rawOutput' => '', 'decoded' => []];
            }

            // Check if it looks like JSON - if so, let it through
            $firstChar = $output[0][0] ?? '';
            if ($firstChar !== '{' && $firstChar !== '[') {
                throw new \Exception('NfDump error: ' . $output[0] . '<br><b>Command: </b><code>' . $command . '</code>');
            }
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

        // if output format is JSON, decode and return structured data
        if (isset($this->cfg['format']) && $this->cfg['format'] === 'json' && preg_match('/^[\{|\[]/', $output[0])) {
            // Check for "No matching flows" — return empty decoded rather than throwing.
            foreach ($output as $line) {
                if (trim($line) === 'No matching flows') {
                    return ['command' => $command, 'rawOutput' => '', 'decoded' => []];
                }
            }

            // Check if this is NDJSON (newline-delimited JSON) - each line is a separate JSON object
            // This happens with statistics queries (-s flag)
            if (str_starts_with($output[0], '{')) {
                $decodedData = [];
                foreach ($output as $line) {
                    $trimmed = trim($line);
                    if (empty($trimmed)) {
                        continue;
                    }
                    $decoded = json_decode($trimmed, true);
                    if (!\is_array($decoded)) {
                        throw new \Exception('Invalid NDJSON line from nfdump: ' . json_last_error_msg() . '<br><b>Line: </b><code>' . $trimmed . '</code><br><b>nfdump command: </b><code>' . $command . '</code>');
                    }
                    $decodedData[] = $decoded;
                }

                $result = [
                    'command' => $command,
                    'rawOutput' => $rawOutput,
                    'decoded' => $decodedData,
                ];

                // Add stderr if present and not benign
                if (!empty($stderr)) {
                    $result['stderr'] = trim($stderr);
                }

                return $result;
            }

            // Handle regular JSON array or object format
            // Join all output lines into single JSON string
            $jsonOutput = implode("\n", $output);

            // fix common JSON output issue: missing ] at the end
            if (str_starts_with($jsonOutput, '[') && !str_ends_with(trim($jsonOutput), ']')) {
                $jsonOutput .= ']';
            }

            // Decode JSON data
            $decodedData = json_decode($jsonOutput, true);
            if (!\is_array($decodedData)) {
                throw new \Exception('Invalid JSON data from nfdump: ' . json_last_error_msg() . '<br><b>Data: </b><code>' . $jsonOutput . '</code><br><b>nfdump command: </b><code>' . $command . '</code>');
            }

            $result = [
                'command' => $command,
                'rawOutput' => $rawOutput,
                'decoded' => $decodedData,
            ];

            // Add stderr if present and not benign
            if (!empty($stderr)) {
                $result['stderr'] = trim($stderr);
            }

            return $result;
        }

        // Check if aggregation produces fixed-width format (not parseable CSV)
        // - Bidirectional aggregation (-B) ALWAYS produces fixed-width format, even with -o csv
        // - Other aggregations (-A*) without -o csv produce fixed-width format
        // - Other aggregations (-A*) WITH -o csv produce proper parseable CSV
        // Note: setOption() method automatically forces csv when json+aggregation is requested
        $isBidirectional = isset($this->cfg['option']['-B']) || (isset($this->cfg['option']['-a']) && str_contains($this->cfg['option']['-a'], 'B'));
        $isAggregationWithoutCsv = isset($this->cfg['option']['-a']) && $this->cfg['format'] !== 'csv';

        if ($isBidirectional || $isAggregationWithoutCsv) {
            $this->d->log('Aggregation detected (-B=' . (isset($this->cfg['option']['-B']) ? 'yes' : 'no') . ', -a flag=' . ($this->cfg['option']['-a'] ?? 'null') . ') producing fixed-width format (bidirectional=' . ($isBidirectional ? 'yes' : 'no') . ', format=' . ($this->cfg['format'] ?? 'null') . '), returning enhanced raw output', LOG_DEBUG);

            $result = [
                'command' => $command,
                'rawOutput' => $this->beautifyAggregatedOutput($output),
                'decoded' => [], // No structured data for aggregated output
            ];

            // Add stderr if present and not benign
            if (!empty($stderr)) {
                $result['stderr'] = trim($stderr);
            }

            return $result;
        }

        // if last element contains a colon AND no comma, it's not a csv (it's a summary like flows/packets/bytes)
        $lastLine = $output[\count($output) - 1] ?? '';
        if (str_contains($lastLine, ':') && !str_contains($lastLine, ',')) {
            // Parse summary format
            /** @var array<array<string, mixed>> $decoded */
            $decoded = [];
            foreach ($output as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $decoded[] = ['metric' => trim($key), 'value' => trim($value)];
                }
            }
            $result = [
                'command' => $command,
                'rawOutput' => $rawOutput,
                'decoded' => $decoded,
            ];

            // Add stderr if present and not benign
            if (!empty($stderr)) {
                $result['stderr'] = trim($stderr);
            }

            return $result;
        }

        // Parse CSV into array of associative arrays (similar to JSON structure)
        /** @var array<array<string, mixed>> $csvData */
        $csvData = [];

        /** @var array<string> $headers */
        $headers = [];

        // Detect delimiter once: use comma if first line contains commas, otherwise tab
        $delimiter = str_contains($output[0], ',') ? ',' : "\t";
        $aggregationNote = isset($this->cfg['option']['-a']) ? ' (with aggregation -a=' . $this->cfg['option']['-a'] . ')' : '';
        $this->d->log('CSV delimiter detected: ' . ($delimiter === ',' ? 'comma' : 'tab') . $aggregationNote, LOG_DEBUG);

        foreach ($output as $i => $line) {
            $fields = str_getcsv($line, $delimiter, '"', '');

            if (\count($fields) === 1 || str_contains((string) $fields[0], 'limit') || str_contains((string) $fields[0], 'error')) {
                // probably an error message or warning. add to command
                $command .= ' <br><b>' . $fields[0] . '</b>';

                continue;
            }

            // First valid line is the header
            if (empty($headers)) {
                $headers = $fields;

                continue;
            }

            // Convert CSV row to associative array
            $row = [];
            foreach ($fields as $field_id => $value) {
                if (isset($headers[$field_id])) {
                    $row[$headers[$field_id]] = $value;
                }
            }

            $csvData[] = $row;
        }

        $this->d->log('CSV parsing complete. Headers: ' . implode(', ', $headers) . '. Rows: ' . \count($csvData), LOG_DEBUG);

        // add execution time to command
        $executionMsg = '<br><b>Execution time:</b> ' . round(microtime(true) - $timer, 3) . ' seconds';
        $command .= $executionMsg;

        $result = [
            'command' => $command,
            'rawOutput' => $rawOutput,
            'decoded' => $csvData,
        ];

        // Add stderr if present and not benign
        if (!empty($stderr)) {
            $result['stderr'] = trim($stderr);
        }

        return $result;
    }

    /**
     * Reset config.
     */
    public function reset(): void {
        $this->clean['env'] = [
            'bin' => Config::$settings->nfdumpBinary,
            'profiles-data' => Config::$settings->nfdumpProfilesData,
            'profile' => Config::$settings->nfdumpProfile,
            'sources' => [],
        ];
        $this->cfg = $this->clean;
    }

    /**
     * Override the nfdump profile used for path construction.
     * Must be called before setOption('-M', ...) to take effect.
     */
    public function setProfile(string $profile): void {
        $this->cfg['env']['profile'] = $profile;
    }

    /**
     * Converts a time range to a nfcapd file range
     * Ensures that files actually exist.
     *
     * @throws \Exception
     */
    public function convert_date_to_path(int $datestart, int $dateend): string {
        $startTs = $datestart - ($datestart % 300);
        $endTs = $dateend - ($dateend % 300);

        $sourcepath = $this->cfg['env']['profiles-data']
            . \DIRECTORY_SEPARATOR
            . $this->cfg['env']['profile']
            . \DIRECTORY_SEPARATOR;

        $filestart = '-';
        $fileend = '-';

        // ── Find start file: iterate day directories forward ──────────────────
        // O(days) rather than O(5-min-slots): avoids the old 10 000-iteration cap
        // that caused silent failures when data started months into the range.
        $cur = (new \DateTime())->setTimestamp($startTs);
        $endDay = (new \DateTime())->setTimestamp($endTs);

        while ($cur->format('Ymd') <= $endDay->format('Ymd')) {
            $dayPath = $cur->format('Y/m/d');
            $found = [];

            foreach ($this->cfg['env']['sources'] as $source) {
                $dirPath = $sourcepath . $source . \DIRECTORY_SEPARATOR . $dayPath;
                if (!is_dir($dirPath)) {
                    continue;
                }

                foreach (scandir($dirPath) ?: [] as $file) {
                    if (!preg_match('/^nfcapd\.(\d{12})$/', (string) $file, $m)) {
                        continue;
                    }

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1]);
                    if ($dt === false) {
                        continue;
                    }

                    $fileTs = $dt->getTimestamp();
                    if ($fileTs >= $startTs) {
                        $found[] = ['ts' => $fileTs, 'path' => $dayPath . \DIRECTORY_SEPARATOR . $file];
                    }
                }
            }

            if (!empty($found)) {
                usort($found, fn ($a, $b) => $a['ts'] <=> $b['ts']);
                $filestart = $found[0]['path'];

                break;
            }

            $cur->modify('+1 day');
        }

        if ($filestart === '-') {
            throw new \Exception('No nfcapd data files found for the requested time range.');
        }

        // ── Find end file: iterate day directories backward ───────────────────
        $cur = (new \DateTime())->setTimestamp($endTs);
        $startDay = (new \DateTime())->setTimestamp($startTs);

        while ($cur->format('Ymd') >= $startDay->format('Ymd')) {
            $dayPath = $cur->format('Y/m/d');
            $found = [];

            foreach ($this->cfg['env']['sources'] as $source) {
                $dirPath = $sourcepath . $source . \DIRECTORY_SEPARATOR . $dayPath;
                if (!is_dir($dirPath)) {
                    continue;
                }

                foreach (scandir($dirPath) ?: [] as $file) {
                    if (!preg_match('/^nfcapd\.(\d{12})$/', (string) $file, $m)) {
                        continue;
                    }

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1]);
                    if ($dt === false) {
                        continue;
                    }

                    $fileTs = $dt->getTimestamp();
                    if ($fileTs <= $endTs) {
                        $found[] = ['ts' => $fileTs, 'path' => $dayPath . \DIRECTORY_SEPARATOR . $file];
                    }
                }
            }

            if (!empty($found)) {
                usort($found, fn ($a, $b) => $b['ts'] <=> $a['ts']);
                $fileend = $found[0]['path'];

                break;
            }

            $cur->modify('-1 day');
        }

        if ($fileend === '-') {
            throw new \Exception('No nfcapd data files found for the requested time range.');
        }

        return $filestart . PATH_SEPARATOR . $fileend;
    }

    /**
     * Build the nfdump aggregation string from individual UI aggregation flags.
     *
     * Accepted keys in $agg:
     *   bidirectional (bool), proto (bool), srcport (bool), dstport (bool),
     *   srcip (string: 'none'|'srcip'|'srcip4'|'srcip6'), srcipPrefix (string),
     *   dstip (string: 'none'|'dstip'|'dstip4'|'dstip6'), dstipPrefix (string)
     *
     * Returns '' (no aggregation), 'bidirectional', or a comma-separated list
     * such as 'proto,srcport,srcip4/24'.
     *
     * @param array<string, mixed> $agg
     */
    public static function buildAggregationString(array $agg): string {
        if (!empty($agg['bidirectional'])) {
            return 'bidirectional';
        }

        $parts = [];
        foreach (['proto', 'srcport', 'dstport'] as $k) {
            if (!empty($agg[$k])) {
                $parts[] = $k;
            }
        }
        foreach (['srcip', 'dstip'] as $k) {
            $v = $agg[$k] ?? '';
            if ($v === '' || $v === 'none') {
                continue;
            }
            if (\in_array($v, ['srcip4', 'srcip6', 'dstip4', 'dstip6'], true) && ($p = trim((string) ($agg[$k . 'Prefix'] ?? ''))) !== '') {
                $parts[] = $v . '/' . $p;
            } else {
                $parts[] = $v;
            }
        }

        return implode(',', $parts);
    }

    /**
     * Build a nfdump byte-threshold filter expression from lower/upper limit strings.
     *
     * Each limit must be a non-empty string matching /^\d+[kMG]?$/i; invalid or
     * empty values are silently ignored.  Returns '' when neither limit applies.
     */
    public static function buildThresholdFilter(string $lower, string $upper): string {
        $parts = [];
        if ($lower !== '' && preg_match('/^\d+[kMG]?$/i', $lower)) {
            $parts[] = 'bytes > ' . $lower;
        }
        if ($upper !== '' && preg_match('/^\d+[kMG]?$/i', $upper)) {
            $parts[] = 'bytes < ' . $upper;
        }

        return implode(' and ', $parts);
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

    /**
     * Beautifies aggregated nfdump output by bolding headers and summary labels.
     * Aggregated output (-a flag) produces fixed-width space-padded format that
     * cannot be reliably parsed as CSV.
     *
     * @param array<string> $output Raw output lines from nfdump
     *
     * @return string Enhanced output with HTML bold tags
     */
    private function beautifyAggregatedOutput(array $output): string {
        $enhancedLines = [];

        foreach ($output as $i => $line) {
            // Bold first line (headers)
            if ($i === 0) {
                $enhancedLines[] = '<b>' . $line . '</b>';

                continue;
            }

            $trim = trim($line);

            // For the "Summary:" line which contains comma-separated key: value pairs
            if (str_starts_with($trim, 'Summary:')) {
                $after = trim(substr($trim, \strlen('Summary:')));
                $parts = array_map('trim', explode(',', $after));
                $newParts = [];
                foreach ($parts as $p) {
                    if (str_contains($p, ':')) {
                        [$k, $v] = explode(':', $p, 2);
                        $newParts[] = '<b>' . trim($k) . ':</b> ' . trim($v);
                    } else {
                        $newParts[] = $p;
                    }
                }
                $enhancedLines[] = '<b>Summary:</b> ' . implode(', ', $newParts);

                continue;
            }

            // Flow data rows start with a timestamp like "2026-04-22 10:43:..." — don't split on colon
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:/', $trim)) {
                $enhancedLines[] = $line;

                continue;
            }

            // Lines like "Time window: ..." or "Total records processed: ..."
            if (str_contains($line, ':')) {
                // Check if it's a summary-style line (not data with port notation like "IP:Port")
                if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches)) {
                    $key = trim($matches[1]);
                    $value = trim($matches[2]);
                    // Only bold if the key doesn't look like an IP or port (no dots or pure numbers)
                    if (!str_contains($key, '.') && !is_numeric($key)) {
                        $enhancedLines[] = '<b>' . $key . ':</b> ' . $value;

                        continue;
                    }
                }
            }

            // Default: keep the line as-is
            $enhancedLines[] = $line;
        }

        return implode("\n", $enhancedLines);
    }
}
