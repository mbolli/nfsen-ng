<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\processor;

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;

/**
 * @phpstan-type NfdumpConfig array{
 *     env: array{bin: string, profiles-data: string, profile: string, sources: list<string>, sources-path?: string},
 *     option: array<string, mixed>,
 *     format: null|string,
 *     filter: string,
 * }
 *
 * @phpstan-import-type ProcessorResult from Processor
 */
class Nfdump implements Processor {
    /** How long execute() waits for nfdump to exit once it has closed its output. */
    public const float EXIT_WAIT_SECONDS = 5.0;
    public static ?self $_instance = null;

    /**
     * PID of the most recently started nfdump process, or null if idle.
     *
     * Kept for callers that only ever run one query at a time. Anything that can run
     * concurrently should own a handle and ask NfdumpSlots instead, because this is whichever
     * run started last.
     */
    public static ?int $runningPid = null;

    /** Identifies this processor's runs to NfdumpSlots, so a kill reaches the right one. */
    private string $queryHandle = 'default';

    /** @var NfdumpConfig */
    private array $cfg;

    /** @var NfdumpConfig pristine config restored by reset() */
    private array $clean;
    private readonly Debug $d;

    public function __construct() {
        $this->d = Debug::getInstance();
        $this->reset();
    }

    public static function getInstance(): self {
        if (!self::$_instance instanceof self) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * First line of `nfdump -V`, e.g. "/usr/bin/nfdump: Version: 1.7.8-release options: ...",
     * or '' if the binary could not be executed. Cached per binary path for the lifetime of
     * the worker process; swapping the binary requires a restart to take effect anyway.
     */
    public static function versionString(?string $binary = null): string {
        $binary ??= Config::$settings->nfdumpBinary;

        static $cache = [];
        if (isset($cache[$binary])) {
            return $cache[$binary];
        }

        $out = [];
        $ret = 0;
        exec(escapeshellarg($binary) . ' -V 2>&1', $out, $ret);

        return $cache[$binary] = ($ret !== 127 && \count($out) > 0) ? trim($out[0]) : '';
    }

    /** Numeric version of the nfdump binary (e.g. '1.7.8'), or '' if it could not be determined. */
    public static function version(?string $binary = null): string {
        return self::parseVersion(self::versionString($binary));
    }

    /** Extracts the bare version number from an `nfdump -V` line, dropping any '-release' suffix. */
    public static function parseVersion(string $versionString): string {
        return preg_match('/Version:\s*(\d+(?:\.\d+)*)/', $versionString, $m) === 1 ? $m[1] : '';
    }

    /**
     * Whether this nfdump version needs plain `-o csv` for aggregated output because it
     * rejects a custom `fmt:` format alongside `-A` aggregation ("Can not use print format
     * ... to aggregate flows", exit 1).
     *
     * True for 1.7.5 only. User-selected formats with custom aggregation arrived in 1.7.6
     * (nfdump #597), and 1.7.2-1.7.4 accept `fmt:` but answer `-o csv` with the wide legacy
     * per-record schema that has no `flows` column, so for them `fmt:` is the only option
     * that yields flow counts. An empty/unparseable version keeps the `fmt:` default. See #159.
     */
    public static function needsAggregatedCsv(string $version): bool {
        return $version !== ''
            && version_compare($version, '1.7.5', '>=')
            && version_compare($version, '1.7.6', '<');
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
                // A string is an already-resolved `first[:last]` file pair, relative to the
                // -M source dirs: FilteredSeries has enumerated the bin's files itself and
                // must not have them re-derived (and re-scanned) from timestamps here.
                $this->cfg['option'][$option] = \is_array($value)
                    ? $this->convert_date_to_path($value[0], $value[1])
                    : (string) $value;

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

            case '-B': // bidirectional aggregation
            case '-b':
                $this->cfg['option'][$option] = $value;
                // nfdump prints the biflow table in its own fixed-width format whatever -o
                // says, and scales the counters ("14.8 M") unless asked not to. That table is
                // read back by column, so ask for plain numbers and let this app format them.
                $this->cfg['option']['-N'] = null;
                if (!isset($this->cfg['option']['-o'])) {
                    $this->cfg['option']['-o'] = 'csv';
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
     * Runs nfdump and decodes its output. `notes` holds what nfdump printed beside the data
     * (limit and error lines, "No matching flows") and the execution time.
     *
     * @return array{command: string, rawOutput: string, decoded: array<array<string, mixed>>, stderr?: string, notes: list<string>, exitCode: int}
     *
     * @throws NfdumpException when nfdump fails or answers with something that is not data
     * @throws \Exception      when the process cannot be started
     */
    public function execute(): array {
        $timer = microtime(true);
        $command = $this->commandLine();
        $this->d->log('Trying to execute ' . $command, LOG_DEBUG);

        // Wait for a slot rather than counting nfdump processes on the machine. That count
        // included runs this app never started, raced between counting and spawning, and made
        // "busy" an error instead of a short wait.
        NfdumpSlots::acquire();

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Prefix with 'exec' so the shell replaces itself with nfdump directly.
        // Without this, proc_open spawns /bin/sh -c "...", and proc_get_status()['pid']
        // returns the shell's PID; killing the shell leaves nfdump running and completing normally.
        // try/finally around everything the slot covers: a failed proc_open used to throw
        // straight past release(), and a worker that leaked both slots wedged every later
        // nfdump call behind the acquire timeout until it was restarted.
        $pid = null;
        $process = null;
        $closed = false;
        $stdout = '';
        $stderr = '';
        $exitCode = 0;

        try {
            $process = proc_open('exec ' . $command, $descriptorspec, $pipes);

            if (!\is_resource($process)) {
                throw new \Exception('Failed to start nfdump process');
            }

            fclose($pipes[0]);

            // Track the running PID so a kill can reach it, both by handle and through the
            // single static the existing single-query callers read.
            $pid = proc_get_status($process)['pid'];
            self::$runningPid = $pid;
            NfdumpSlots::register($this->queryHandle, $pid);

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $polled = self::polledExitCode($process);
            $status = proc_close($process);
            $closed = true;
            $exitCode = $polled ?? self::exitCodeFrom($status);
        } finally {
            // A throw while reading the pipes would otherwise leave the child unreaped and
            // its handle open for the life of the worker.
            if (\is_resource($process) && !$closed) {
                proc_terminate($process);
                proc_close($process);
            }
            self::$runningPid = null;
            if ($pid !== null) {
                NfdumpSlots::unregister($this->queryHandle, $pid);
            }
            NfdumpSlots::release();
        }

        return $this->interpret($command, $stdout, $stderr, $exitCode, $timer);
    }

    /**
     * The command line execute() runs, as shown to the user. `--` keeps a filter that starts
     * with a dash from being read as an nfdump option such as `-w <file>`.
     */
    public function commandLine(): string {
        $parts = [$this->cfg['env']['bin']];
        $options = $this->flatten($this->cfg['option']);
        if ($options !== '') {
            $parts[] = $options;
        }
        if ($this->cfg['filter'] !== '') {
            $parts[] = '--';
            $parts[] = escapeshellarg($this->cfg['filter']);
        }

        return implode(' ', $parts);
    }

    /**
     * Under OpenSwoole's process hook proc_close() returns the raw wait status (exit 254
     * arrives as 65024), so the wait status of a normal exit is unpacked here.
     */
    public static function exitCodeFrom(int $status): int {
        if ($status > 255 && ($status & 0x7F) === 0) {
            return ($status >> 8) & 0xFF;
        }

        return $status;
    }

    /**
     * The exit code (or ending signal) once the process has ended, null when it could not be seen.
     * Under OpenSwoole's hooks proc_close() sometimes answers 0 for a failed run.
     *
     * @param resource $process
     */
    public static function polledExitCode($process, float $timeout = self::EXIT_WAIT_SECONDS): ?int {
        $deadline = microtime(true) + $timeout;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                if ($status['signaled']) {
                    return $status['termsig'];
                }

                return $status['exitcode'] >= 0 ? $status['exitcode'] : null;
            }
            usleep(1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Reset config.
     */
    public function reset(): void {
        $this->clean = [
            'env' => [
                'bin' => Config::$settings->nfdumpBinary,
                'profiles-data' => Config::$settings->nfdumpProfilesData,
                'profile' => Config::$settings->nfdumpProfile,
                'sources' => [],
            ],
            'option' => [],
            'format' => null,
            'filter' => '',
        ];
        $this->cfg = $this->clean;
    }

    /**
     * Names this processor's runs, so concurrent callers can kill their own query rather than
     * whichever one started last. Defaults to a shared handle for the single-query case.
     */
    public function setQueryHandle(string $handle): void {
        $this->queryHandle = $handle !== '' ? $handle : 'default';
    }

    public function queryHandle(): string {
        return $this->queryHandle;
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
        $cur = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($startTs);
        $endDay = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($endTs);

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

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1], Config::nfcapdTimezone());
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
        $cur = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($endTs);
        $startDay = (new \DateTime('', Config::nfcapdTimezone()))->setTimestamp($startTs);

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

                    $dt = \DateTime::createFromFormat('YmdHi', $m[1], Config::nfcapdTimezone());
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

    /**
     * Collapse nfdump's address-family-specific JSON keys onto family-agnostic ones.
     *
     * nfdump's `-o json` names every address field after the record's address family:
     * an IPv4 record carries `src4_addr`/`dst4_addr`, an IPv6 record `src6_addr`/`dst6_addr`
     * (likewise `ip4_next_hop`, `bgp6_next_hop`, `src4_tun_ip`, `dst6_xlt_ip`, `ip4_router`, …).
     * A result set holding both families therefore has two different record schemas, so any
     * consumer keying on column names sees the addresses of only one family (#157).
     * Renaming `<prefix><4|6>_<rest>` to `<prefix>_<rest>` gives every record one schema;
     * key order is preserved, and an already-present non-empty value is never overwritten.
     *
     * @param array<array<string, mixed>> $records
     *
     * @return array<array<string, mixed>>
     */
    public static function normalizeAddressFamilyKeys(array $records): array {
        $normalized = [];

        foreach ($records as $record) {
            if (!\is_array($record)) {
                $normalized[] = $record;

                continue;
            }

            $row = [];
            foreach ($record as $key => $value) {
                $newKey = \is_string($key)
                    ? (preg_replace('/^(src|dst|ip|bgp)[46]_/', '$1_', $key) ?? $key)
                    : $key;
                // Both family variants in one record would be a nfdump oddity; keep the filled one.
                if (isset($row[$newKey]) && $row[$newKey] !== '') {
                    continue;
                }
                $row[$newKey] = $value;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * Parse whitespace-delimited aggregated nfdump output (custom 'fmt:' format strings) into
     * structured rows. A line is a row when it has exactly one field per column and every
     * address column (`sa`, `da`) holds an address, so nfdump's header, "No matching flows"
     * and summary lines never become rows, whatever their token count.
     *
     * @param array<string> $lines   Raw output lines from nfdump
     * @param array<string> $headers Column names, in order, as derived from the fmt: string
     *
     * @return array<array<string, string>>
     */
    public static function parseWhitespaceDelimitedAggregation(array $lines, array $headers): array {
        $headerCount = \count($headers);
        $addressColumns = array_keys(array_intersect($headers, ['sa', 'da']));
        $decoded = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }

            $fields = preg_split('/\s+/', $trimmed);
            if ($fields === false || \count($fields) !== $headerCount) {
                continue;
            }
            foreach ($addressColumns as $column) {
                if (filter_var($fields[$column], FILTER_VALIDATE_IP) === false) {
                    continue 2;
                }
            }

            $decoded[] = array_combine($headers, $fields);
        }

        return $decoded;
    }

    /**
     * Column names for one of nfdump's named output formats, or the fields
     * parsed out of a custom `fmt:%a %b` format string.
     *
     * @return list<string>
     */
    public function get_output_format(mixed $format): array {
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
     * nfdump's merged-flow table, parsed into rows.
     *
     * Bi-directional aggregation is the one query whose output format cannot be chosen:
     * nfdump prints its own fixed-width biflow table whatever `-o` is given, csv and json
     * included. It used to be shown as preformatted text, which meant no IP lookups, no byte
     * formatting and no sortable columns for the one query that merges both directions.
     *
     * Read relative to the `<->` that separates the two endpoints rather than by column
     * offset, so a long IPv6 address shifting the layout does not silently misread a row.
     * Anything unexpected returns [] and the caller falls back to the raw text.
     *
     * @param list<string> $output
     *
     * @return array<array<string, mixed>>
     */
    public static function parseBidirectionalOutput(array $output): array {
        $rows = [];

        foreach ($output as $line) {
            $line = trim($line);
            // Only the data rows start with a date; the header, the "Top N flows ordered by"
            // title and the summary block all do not.
            if ($line === '' || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:/', $line) !== 1) {
                continue;
            }

            $tokens = preg_split('/\s+/', $line) ?: [];
            $arrow = array_search('<->', $tokens, true);
            // date, time, duration, proto, src <-> dst, then five counters.
            if ($arrow === false || $arrow < 4 || \count($tokens) !== $arrow + 7) {
                return [];
            }

            [$srcAddr, $srcPort] = self::splitEndpoint($tokens[$arrow - 1]);
            [$dstAddr, $dstPort] = self::splitEndpoint($tokens[$arrow + 1]);

            $rows[] = [
                'firstSeen' => implode(' ', \array_slice($tokens, 0, $arrow - 3)),
                'duration' => $tokens[$arrow - 3],
                'proto' => $tokens[$arrow - 2],
                'srcAddr' => $srcAddr,
                'srcPort' => $srcPort,
                'dstAddr' => $dstAddr,
                'dstPort' => $dstPort,
                'outPackets' => $tokens[$arrow + 2],
                'inPackets' => $tokens[$arrow + 3],
                'outBytes' => $tokens[$arrow + 4],
                'inBytes' => $tokens[$arrow + 5],
                'flows' => $tokens[$arrow + 6],
            ];
        }

        return $rows;
    }

    /**
     * nfdump messages that say nothing about this query's result.
     *
     * Two of them are unavoidable rather than exceptional: a partially written capture file
     * being read while nfcapd still has it open, and the note that `-s` takes precedence over
     * the `-a` flag, which nfdump prints for every aggregated statistic even though it honours
     * the `-A` spec (#174).
     */
    private static function withoutBenignStderr(string $stderr): string {
        $kept = array_filter(
            explode("\n", $stderr),
            static function (string $line): bool {
                $line = trim($line);
                if ($line === '') {
                    return false;
                }
                if (stripos($line, 'read() error') !== false && stripos($line, 'Success') !== false) {
                    return false;
                }

                return stripos($line, 'switch -s overwrites -a') === false;
            }
        );

        return trim(implode("\n", $kept));
    }

    /**
     * The options as command-line arguments. A null or empty value is a bare flag, a list
     * repeats the flag once per element, in order (`-s 'a' -s 'b'`).
     *
     * @param array<string, mixed> $options
     */
    private function flatten(array $options): string {
        $parts = [];

        foreach ($options as $flag => $value) {
            foreach (\is_array($value) ? $value : [$value] as $item) {
                $item = self::scalarString($item);
                $parts[] = $item === '' ? $flag : $flag . ' ' . escapeshellarg($item);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Turns what nfdump printed into the execute() result, or the exception it amounts to.
     *
     * @return array{command: string, rawOutput: string, decoded: array<array<string, mixed>>, stderr?: string, notes: list<string>, exitCode: int}
     *
     * @throws NfdumpException
     */
    private function interpret(string $command, string $stdout, string $stderrRaw, int $exitCode, float $timer): array {
        // What survives the benign filter is what every later `$result['stderr']` reports, and
        // the panels show that to the user, so a message nfdump always prints must not reach it.
        $stderrRaw = trim($stderrRaw);
        $stderr = '';
        if ($stderrRaw !== '') {
            $stderr = self::withoutBenignStderr($stderrRaw);
            if ($stderr === '') {
                $this->d->log('NfDump benign stderr message: ' . $stderrRaw, LOG_DEBUG);
            } else {
                $this->d->log('NfDump stderr: ' . $stderr, LOG_WARNING);
            }
        }

        $output = explode("\n", $stdout);
        if (end($output) === '') {
            array_pop($output);
        }

        // The usage text is not a result, whatever the exit code says.
        if (isset($output[0]) && stripos($output[0], 'usage') === 0) {
            $output = [];
        }

        $blank = trim(implode("\n", $output)) === '';
        if ($exitCode !== 0 && ($blank || \in_array($exitCode, [127, 250, 254, 255], true))) {
            throw $this->failure($command, $output, $stderr, $stderrRaw, $exitCode);
        }

        $result = $this->decode($command, $stdout, $output, $stderr, $stderrRaw, $exitCode, $timer);

        // A rejected option (`-s nevent`, `-s srcip/foo`) exits 1 with its option table on stdout.
        if ($exitCode !== 0 && $result['decoded'] === []) {
            throw $this->failure($command, $output, $stderr, $stderrRaw, $exitCode);
        }

        return $result;
    }

    /**
     * @param list<string> $output stdout split into lines
     *
     * @return array{command: string, rawOutput: string, decoded: array<array<string, mixed>>, stderr?: string, notes: list<string>, exitCode: int}
     *
     * @throws NfdumpException
     */
    private function decode(string $command, string $stdout, array $output, string $stderr, string $stderrRaw, int $exitCode, float $timer): array {
        /** @var list<string> $notes */
        $notes = [];
        if ($exitCode !== 0) {
            $notes[] = 'nfdump exited with code ' . $exitCode;
        }

        // Nothing printed and no failure: an empty result. Import::start() moves on to the
        // next file on one.
        if (trim(implode("\n", $output)) === '') {
            return $this->result($command, '', [], $stderr, $notes, $exitCode, $timer);
        }

        if (\count($output) === 1) {
            $line = trim($output[0]);

            if ($line === 'No matching flows') {
                $notes[] = $line;

                return $this->result($command, '', [], $stderr, $notes, $exitCode, $timer);
            }

            // A CSV header alone is a statistic or listing with no rows.
            if (str_starts_with($line, 'ts,') || str_starts_with($line, 'firstSeen,')) {
                return $this->result($command, $stdout, [], $stderr, $notes, $exitCode, $timer);
            }

            // Single-line JSON is valid (e.g. -s statistics with -n 1); any other lone line
            // is nfdump explaining why it has no data.
            $firstChar = $line[0] ?? '';
            if ($firstChar !== '{' && $firstChar !== '[') {
                throw new NfdumpException($line, $command, $stderr !== '' ? $stderr : $stderrRaw, $exitCode);
            }
        }

        if (isset($this->cfg['format']) && $this->cfg['format'] === 'json' && preg_match('/^[\{|\[]/', $output[0])) {
            foreach ($output as $line) {
                if (trim($line) === 'No matching flows') {
                    $notes[] = 'No matching flows';

                    return $this->result($command, '', [], $stderr, $notes, $exitCode, $timer);
                }
            }

            // NDJSON, one object per line: what statistics queries (-s) print.
            if (str_starts_with($output[0], '{')) {
                $decodedData = [];
                foreach ($output as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    $decoded = json_decode($trimmed, true);
                    if (!\is_array($decoded)) {
                        throw new NfdumpException('Invalid JSON line from nfdump (' . json_last_error_msg() . '): ' . self::excerpt($trimmed), $command, $stderr, $exitCode);
                    }
                    $decodedData[] = $decoded;
                }

                return $this->result($command, $stdout, self::normalizeAddressFamilyKeys($decodedData), $stderr, $notes, $exitCode, $timer);
            }

            $jsonOutput = implode("\n", $output);

            // nfdump can end a JSON array without its closing bracket.
            if (str_starts_with($jsonOutput, '[') && !str_ends_with(trim($jsonOutput), ']')) {
                $jsonOutput .= ']';
            }

            $decodedData = json_decode($jsonOutput, true);
            if (!\is_array($decodedData)) {
                throw new NfdumpException('Invalid JSON from nfdump (' . json_last_error_msg() . '): ' . self::excerpt($jsonOutput), $command, $stderr, $exitCode);
            }

            return $this->result($command, $stdout, self::normalizeAddressFamilyKeys($decodedData), $stderr, $notes, $exitCode, $timer);
        }

        // -B prints nfdump's fixed-width table even with -o csv; other aggregations are CSV
        // only with -o csv, which setOption() already forces once -a is set.
        $isBidirectional = isset($this->cfg['option']['-B']) || (isset($this->cfg['option']['-a']) && str_contains((string) $this->cfg['option']['-a'], 'B'));

        // Custom whitespace-delimited aggregation format (e.g. 'fmt:%sa %da %ibyt %ipkt %fl'),
        // used by the Sankey to get per-pair flow counts, which the fixed -o csv aggregation
        // schema does not include.
        if (isset($this->cfg['option']['-a']) && !$isBidirectional && str_starts_with((string) $this->cfg['format'], 'fmt:')) {
            $decoded = self::parseWhitespaceDelimitedAggregation($output, $this->get_output_format($this->cfg['format']));

            return $this->result($command, $stdout, $decoded, $stderr, $notes, $exitCode, $timer);
        }

        $isAggregationWithoutCsv = isset($this->cfg['option']['-a']) && $this->cfg['format'] !== 'csv';

        if ($isBidirectional || $isAggregationWithoutCsv) {
            $this->d->log('Aggregation detected (-B=' . (isset($this->cfg['option']['-B']) ? 'yes' : 'no') . ', -a flag=' . self::scalarString($this->cfg['option']['-a'] ?? 'null') . ') producing fixed-width format (bidirectional=' . ($isBidirectional ? 'yes' : 'no') . ', format=' . ($this->cfg['format'] ?? 'null') . '), returning the raw output', LOG_DEBUG);

            // The biflow table has a known shape, so it can be read into rows. The raw text
            // still travels with it, untouched: a row the parser does not recognise means an
            // empty list, and the caller shows the output as it came instead of losing it.
            $decoded = $isBidirectional ? self::parseBidirectionalOutput($output) : [];

            return $this->result($command, $stdout, $decoded, $stderr, $notes, $exitCode, $timer);
        }

        // A last line with a colon and no comma is a key: value summary such as `-I`.
        $lastLine = $output[\count($output) - 1];
        if (str_contains($lastLine, ':') && !str_contains($lastLine, ',')) {
            /** @var array<array<string, mixed>> $decoded */
            $decoded = [];
            foreach ($output as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $decoded[] = ['metric' => trim($key), 'value' => trim($value)];
                }
            }

            return $this->result($command, $stdout, $decoded, $stderr, $notes, $exitCode, $timer);
        }

        /** @var array<array<string, mixed>> $csvData */
        $csvData = [];

        /** @var array<string> $headers */
        $headers = [];

        // From the first line that has a delimiter: nfdump can lead with "No matching flows".
        $delimiter = "\t";
        foreach ($output as $line) {
            if (str_contains($line, ',') || str_contains($line, "\t")) {
                $delimiter = str_contains($line, ',') ? ',' : "\t";

                break;
            }
        }
        $aggregationNote = isset($this->cfg['option']['-a']) ? ' (with aggregation -a=' . self::scalarString($this->cfg['option']['-a']) . ')' : '';
        $this->d->log('CSV delimiter detected: ' . ($delimiter === ',' ? 'comma' : 'tab') . $aggregationNote, LOG_DEBUG);

        foreach ($output as $line) {
            $fields = str_getcsv($line, $delimiter, '"', '');

            // A lone field or a limit/error line is nfdump talking, not a row.
            if (\count($fields) === 1 || str_contains((string) $fields[0], 'limit') || str_contains((string) $fields[0], 'error')) {
                $note = trim((string) $fields[0]);
                if ($note !== '') {
                    $notes[] = $note;
                }

                continue;
            }

            if ($headers === []) {
                $headers = array_map(static fn (?string $h): string => (string) $h, $fields);

                continue;
            }

            $row = [];
            foreach ($fields as $fieldId => $value) {
                if (isset($headers[$fieldId])) {
                    $row[$headers[$fieldId]] = $value;
                }
            }

            $csvData[] = $row;
        }

        $this->d->log('CSV parsing complete. Headers: ' . implode(', ', $headers) . '. Rows: ' . \count($csvData), LOG_DEBUG);

        return $this->result($command, $stdout, $csvData, $stderr, $notes, $exitCode, $timer);
    }

    /**
     * @param array<array<string, mixed>> $decoded
     * @param list<string>                $notes
     *
     * @return array{command: string, rawOutput: string, decoded: array<array<string, mixed>>, stderr?: string, notes: list<string>, exitCode: int}
     */
    private function result(string $command, string $rawOutput, array $decoded, string $stderr, array $notes, int $exitCode, float $timer): array {
        $notes[] = 'Execution time: ' . round(microtime(true) - $timer, 3) . ' seconds';

        $result = [
            'command' => $command,
            'rawOutput' => $rawOutput,
            'decoded' => $decoded,
            'notes' => $notes,
            'exitCode' => $exitCode,
        ];
        if ($stderr !== '') {
            $result['stderr'] = $stderr;
        }

        return $result;
    }

    /**
     * The exception for a run that failed, worded from nfdump's own explanation: the `Line N:`
     * message a filter error prints on stdout, else the first stderr line.
     *
     * @param list<string> $output
     */
    private function failure(string $command, array $output, string $stderr, string $stderrRaw, int $exitCode): NfdumpException {
        $text = '';
        foreach ($output as $line) {
            if (preg_match('/^Line \d+: /', $line) === 1) {
                // The line number only helps for a multi-line filter; FilterComposer's "\n)" after a comment adds none.
                $multiLine = str_contains(str_replace("\n)", ')', $this->cfg['filter']), "\n");
                $text = $multiLine ? trim($line) : trim((string) preg_replace('/^Line 1: /', '', $line));

                break;
            }
        }
        $text = $text !== '' ? $text : self::firstLine($stderr);
        $text = $text !== '' ? $text : self::firstLine($stderrRaw);
        $text = $text !== '' ? $text : self::firstLine(implode("\n", $output));

        $message = match ($exitCode) {
            254 => 'Filter syntax error: ' . ($text !== '' ? $text : 'nfdump rejected the filter'),
            127 => 'nfdump could not be started' . ($text !== '' ? ': ' . $text : '') . '. Is it installed at ' . $this->cfg['env']['bin'] . '?',
            255 => 'nfdump initialisation failed' . ($text !== '' ? ': ' . $text : ''),
            250 => 'nfdump internal error' . ($text !== '' ? ': ' . $text : ''),
            // nfdump never exits with these itself: this is a Kill (SIGTERM) or SIGKILL.
            9, 15 => 'nfdump was stopped (signal ' . $exitCode . ')' . ($text !== '' ? ': ' . $text : ''),
            default => $text !== '' ? $text : 'nfdump exited with code ' . $exitCode,
        };

        return new NfdumpException($message, $command, $stderrRaw, $exitCode);
    }

    private static function firstLine(string $text): string {
        foreach (explode("\n", $text) as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return '';
    }

    private static function excerpt(string $text): string {
        return \strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
    }

    private static function scalarString(mixed $value): string {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * "10.0.0.1:443" or "2001:62..e0:fed5.443" into address and port.
     *
     * nfdump separates the two with ':' for IPv4 and '.' for IPv6 (output_fmt.c,
     * String_SrcAddrPort), so the families are told apart by colon count: an IPv6 address
     * always holds at least two, an IPv4 endpoint exactly the one that is the separator.
     * The IPv6 address may be condensed to "2001:62..e0:fed5", which is why the port is
     * looked for after the last colon rather than at the first dot.
     *
     * An ICMP row carries type.code where the port goes, so the port is not always a number.
     *
     * @return array{string, string}
     */
    private static function splitEndpoint(string $endpoint): array {
        if (substr_count($endpoint, ':') >= 2) {
            $lastColon = strrpos($endpoint, ':');
            \assert($lastColon !== false);
            $dot = strpos($endpoint, '.', $lastColon);

            return $dot === false
                ? [$endpoint, '']
                : [substr($endpoint, 0, $dot), substr($endpoint, $dot + 1)];
        }

        $at = strrpos($endpoint, ':');

        return $at === false
            ? [$endpoint, '']
            : [substr($endpoint, 0, $at), substr($endpoint, $at + 1)];
    }
}
