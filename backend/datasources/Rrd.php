<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\datasources;

use JetBrains\PhpStorm\ExpectedValues;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Debug;
use mbolli\nfsen_ng\common\HealthChecker;

class Rrd implements Datasource {
    private readonly Debug $d;
    private readonly int $importYears;
    private readonly array $layout;
    private array $fields = [
        'flows',
        'flows_tcp',
        'flows_udp',
        'flows_icmp',
        'flows_other',
        'packets',
        'packets_tcp',
        'packets_udp',
        'packets_icmp',
        'packets_other',
        'bytes',
        'bytes_tcp',
        'bytes_udp',
        'bytes_icmp',
        'bytes_other',
    ];

    public function __construct() {
        $this->d = Debug::getInstance();

        if (!\function_exists('rrd_version')) {
            throw new \Exception('Please install the PECL rrd library.');
        }

        $this->importYears = Config::$settings->importYears();

        $this->migrateRrdsToProfileSubdir();

        // Calculate layout based on import years
        // Structure maintains same resolution levels but extends daily samples
        $this->layout = [
            '0.5:1:' . ((60 / (1 * 5)) * 24 * 45), // 45 days of 5 min samples
            '0.5:6:' . ((60 / (6 * 5)) * 24 * 90), // 90 days of 30 min samples
            '0.5:24:' . ((60 / (24 * 5)) * 24 * 365), // 365 days of 2 hour samples
            '0.5:288:' . $this->importYears * 365, // N years of daily samples (based on import_years)
        ];
    }

    /**
     * Gets the timestamps of the first and last entry of this specific source.
     */
    public function date_boundaries(string $source, string $profile = ''): array {
        $rrdFile = $this->get_data_path($source, 0, $profile);

        // Use sidecar .first file for the lower bound — rrd_first() returns the
        // RRD creation start (now - importYears), not the first actual data point.
        $sidecar = $rrdFile . '.first';
        $first = file_exists($sidecar) ? (int) trim((string) file_get_contents($sidecar)) : 0;

        return [$first, rrd_last($rrdFile)];
    }

    /**
     * Gets the timestamp of the last update of this specific source.
     *
     * @return int timestamp or false
     */
    public function last_update(string $source = '', int $port = 0, string $profile = ''): int {
        $rrdFile = $this->get_data_path($source, $port, $profile);
        $last_update = rrd_last($rrdFile);

        // $this->d->log('Last update of ' . $rrdFile . ': ' . date('d.m.Y H:i', $last_update), LOG_DEBUG);
        return (int) $last_update;
    }

    /**
     * Create a new RRD file for a source.
     *
     * @param string $source e.g. gateway or server_xyz
     * @param bool   $reset  overwrites existing RRD file if true
     */
    public function create(string $source, int $port = 0, bool $reset = false, string $profile = ''): bool {
        $rrdFile = $this->get_data_path($source, $port, $profile);

        // check if folder exists
        if (!file_exists(\dirname($rrdFile))) {
            if (!mkdir($concurrentDirectory = \dirname($rrdFile), 0o755, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(\sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }

        // check if folder has correct access rights
        if (!is_writable(\dirname($rrdFile))) {
            $this->d->log('Error creating ' . $rrdFile . ': Not writable', LOG_CRIT);

            return false;
        }
        // check if file already exists
        if (file_exists($rrdFile)) {
            if ($reset === true) {
                unlink($rrdFile);
                // Remove sidecar so the next write recreates it with the new first timestamp.
                $sidecar = $rrdFile . '.first';
                if (file_exists($sidecar)) {
                    unlink($sidecar);
                }
            } else {
                $this->d->log('Error creating ' . $rrdFile . ': File already exists', LOG_ERR);

                return false;
            }
        }

        $start = strtotime('-' . $this->importYears . ' years');
        $starttime = (int) $start - ($start % 300);

        $creator = new \RRDCreator($rrdFile, (string) $starttime, 60 * 5);
        foreach ($this->fields as $field) {
            $creator->addDataSource($field . ':ABSOLUTE:600:U:U');
        }
        foreach ($this->layout as $rra) {
            $creator->addArchive('AVERAGE:' . $rra);
            $creator->addArchive('MAX:' . $rra);
        }

        $saved = $creator->save();
        if ($saved === false) {
            $this->d->log('Error saving RRD data structure to ' . $rrdFile, LOG_ERR);
        }

        return $saved;
    }

    /**
     * Validate if an existing RRD file matches the expected structure.
     * Returns true if valid, false if invalid or doesn't exist.
     *
     * @param string $source       e.g. gateway or server_xyz
     * @param int    $port         port number (0 for no port)
     * @param bool   $showWarnings whether to display CLI warnings when validation fails
     * @param bool   $quiet        suppress all output
     *
     * @return array{valid: bool, message: string, expected_rows: null|int, actual_rows: null|int}
     */
    public function validateStructure(string $source, int $port = 0, bool $showWarnings = false, bool $quiet = false, string $profile = ''): array {
        $rrdFile = $this->get_data_path($source, $port, $profile);

        if (!file_exists($rrdFile)) {
            return [
                'valid' => false,
                'message' => 'RRD file does not exist',
                'expected_rows' => null,
                'actual_rows' => null,
            ];
        }

        // Get RRD info
        $info = rrd_info($rrdFile);
        if ($info === false) { // @phpstan-ignore identical.alwaysFalse
            return [
                'valid' => false,
                'message' => 'Could not read RRD file info: ' . rrd_error(),
                'expected_rows' => null,
                'actual_rows' => null,
            ];
        }

        // Check the last RRA (daily samples) which contains the year-dependent data
        // RRAs are indexed as rra[0], rra[1], etc. We have 2 RRAs per layout entry (AVERAGE and MAX)
        // So for N layout entries, we have 2*N RRAs total. The last AVERAGE RRA is index (count($this->layout) - 1) * 2.
        $expectedDailyRows = $this->importYears * 365;
        $lastAverageRraIndex = (\count($this->layout) - 1) * 2;

        // Check if the RRA exists and get its rows
        $rraKey = "rra[{$lastAverageRraIndex}].rows";
        if (!isset($info[$rraKey])) {
            return [
                'valid' => false,
                'message' => 'Could not find expected RRA structure in RRD file',
                'expected_rows' => $expectedDailyRows,
                'actual_rows' => null,
            ];
        }

        $actualRows = (int) $info[$rraKey];

        if ($actualRows !== $expectedDailyRows) {
            $message = "RRD structure mismatch: Expected {$expectedDailyRows} daily rows (import_years={$this->importYears}), but RRD has {$actualRows} rows";

            // Display warning if requested and we're in CLI mode
            if ($showWarnings && \PHP_SAPI === 'cli') {
                $actualYears = round($actualRows / 365, 1);

                echo <<<WARNING

\033[1;33mWARNING: RRD Structure Mismatch\033[0m
Your existing RRD files were created with a different import_years
setting than what is currently configured.

Current setting:  import_years={$this->importYears} (~{$this->importYears} years)
Existing RRD has: ~{$actualYears} years of storage ({$actualRows} daily rows)

This mismatch can cause:
  • Data loss if new setting stores less data than before
  • Import errors or incomplete data coverage

\033[1mTo fix this:\033[0m
  1. Use Admin panel → Force Rescan to recreate RRD files, OR
  2. Adjust import_years in settings.php to match existing structure (~{$actualYears})

Continuing import with existing structure...


WARNING;
            }

            return [
                'valid' => false,
                'message' => $message,
                'expected_rows' => $expectedDailyRows,
                'actual_rows' => $actualRows,
            ];
        }

        // Display success message if requested
        if ($showWarnings && !$quiet && \PHP_SAPI === 'cli') {
            echo '✓ RRD structure is valid (configured for ' . $this->importYears . ' years)' . PHP_EOL;
        }

        return [
            'valid' => true,
            'message' => 'RRD structure is valid',
            'expected_rows' => $expectedDailyRows,
            'actual_rows' => $actualRows,
        ];
    }

    /**
     * Write to an RRD file with supplied data.
     *
     * @throws \Exception
     */
    public function write(array $data): bool {
        $profile = $data['profile'] ?? '';
        $rrdFile = $this->get_data_path($data['source'], $data['port'], $profile);
        if (!file_exists($rrdFile)) {
            $this->create($data['source'], $data['port'], false, $profile);
        } else {
            $lastTs = rrd_last($rrdFile);
            if ($lastTs > time() + 86400 * 365) {
                // Corrupted far-future timestamp — recreate the file.
                $this->d->log('Recreating RRD with corrupted timestamp (' . $lastTs . '): ' . $rrdFile, LOG_WARNING);
                $this->create($data['source'], $data['port'], true, $profile);
            } else {
                $nearest = (int) $data['date_timestamp'] - ($data['date_timestamp'] % 300);
                if ($nearest <= $lastTs) {
                    // Timestamp already covered — silently skip to avoid "illegal attempt to update" noise
                    // when the import restarts mid-way and port RRDs are already ahead.
                    return true;
                }
            }
        }

        $nearest = (int) $data['date_timestamp'] - ($data['date_timestamp'] % 300);
        $this->d->log('Writing to file ' . $rrdFile, LOG_DEBUG);

        $result = (new \RRDUpdater($rrdFile))->update($data['fields'], (string) $nearest);

        // Write sidecar on first successful write (if missing) so date_boundaries()
        // knows the actual first data point rather than the RRD creation start.
        // Checked every write so it is also created for pre-existing RRDs that
        // predate this feature, and after a force-rescan that deleted the sidecar.
        if ($result) {
            $sidecar = $rrdFile . '.first';
            if (!file_exists($sidecar)) {
                file_put_contents($sidecar, (string) $nearest);
            }
        }

        return $result;
    }

    /**
     * @param string   $type    flows/packets/traffic
     * @param string   $display protocols/sources/ports
     * @param null|int $maxrows optional maximum rows to return (null = 500 default, let RRDtool calculate step)
     */
    public function get_graph_data(
        int $start,
        int $end,
        array $sources,
        array $protocols,
        array $ports,
        #[ExpectedValues(['flows', 'packets', 'bytes', 'bits'])]
        string $type = 'flows',
        #[ExpectedValues(['protocols', 'sources', 'ports'])]
        string $display = 'sources',
        ?int $maxrows = 500,
        string $profile = '',
    ): array|string {
        $options = [
            '--start',
            $start - ($start % 300),
            '--end',
            $end - ($end % 300),
            '--maxrows',
            $maxrows,
            // number of values. works like the width value (in pixels) in rrd_graph
            // '--step', 1200, // by default, rrdtool tries to get data for each row. if you want rrdtool to get data at a one-hour resolution, set step to 3600.
            '--json',
        ];

        $useBits = false;
        if ($type === 'bits') {
            $type = 'bytes';
            $useBits = true;
        }

        if (empty($protocols)) {
            $protocols = ['tcp', 'udp', 'icmp', 'other'];
        }
        if (empty($sources)) {
            $sources = Config::$settings->sources;
        }
        if (empty($ports)) {
            $ports = Config::$settings->ports;
        }

        switch ($display) {
            case 'protocols':
                foreach ($protocols as $protocol) {
                    $rrdFile = $this->get_data_path($sources[0], 0, $profile);
                    $proto = ($protocol === 'any') ? '' : '_' . $protocol;
                    $legend = array_filter([$protocol, $type, $sources[0]]);
                    $options[] = 'DEF:data' . $sources[0] . $protocol . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $sources[0] . $protocol . ':' . implode('_', $legend);
                }

                break;

            case 'sources':
                foreach ($sources as $source) {
                    $rrdFile = $this->get_data_path($source, 0, $profile);
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter([$source, $type, $protocols[0]]);
                    $options[] = 'DEF:data' . $source . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . ':' . implode('_', $legend);
                }

                break;

            case 'ports':
                foreach ($ports as $port) {
                    $source = ($sources[0] === 'any') ? '' : $sources[0];
                    $proto = ($protocols[0] === 'any') ? '' : '_' . $protocols[0];
                    $legend = array_filter([$port, $type, $source, $protocols[0]]);
                    $rrdFile = $this->get_data_path($source, $port, $profile);
                    $options[] = 'DEF:data' . $source . $port . '=' . $rrdFile . ':' . $type . $proto . ':AVERAGE';
                    $options[] = 'XPORT:data' . $source . $port . ':' . implode('_', $legend);
                }
        }

        ob_start();
        $data = rrd_xport($options);
        $error = ob_get_clean(); // rrd_xport weirdly prints stuff on error

        if (!\is_array($data)) { // @phpstan-ignore-line function.alreadyNarrowedType (probably wrong rrd stubs)
            throw new \Exception($error . '. ' . rrd_error());
        }

        // remove invalid numbers and create processable array
        $output = [
            'data' => [],
            'start' => $data['start'],
            'end' => $data['end'],
            'step' => $data['step'],
            'legend' => [],
        ];
        foreach ($data['data'] as $source) {
            $output['legend'][] = $source['legend'];
            foreach ($source['data'] as $date => $measure) {
                // ignore non-valid measures
                if (is_nan($measure)) {
                    $measure = null;
                }

                if ($type === 'bytes' && $useBits) {
                    $measure *= 8;
                }

                // add measure to output array
                if (\array_key_exists($date, $output['data'])) {
                    $output['data'][$date][] = $measure;
                } else {
                    $output['data'][$date] = [$measure];
                }
            }
        }

        return $output;
    }

    /**
     * Creates a new database for every source/port combination.
     */
    public function reset(array $sources, string $profile = ''): bool {
        $return = true;
        if (empty($sources)) {
            $sources = Config::$settings->sources;
        }
        $ports = Config::$settings->ports;
        $ports[] = 0;
        foreach ($ports as $port) {
            if ($port !== 0) {
                $return = $this->create('', $port, true, $profile);
            }
            if ($return === false) {
                return false;
            }

            foreach ($sources as $source) {
                $return = $this->create($source, $port, true, $profile);
                if ($return === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Returns health check entries for this datasource.
     *
     * @param string[] $sources
     *
     * @return list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}>
     */
    public function healthChecks(string $group, array $sources): array {
        $checks = [];
        $importYears = Config::$settings->importYears();

        $rrdPath = Config::$settings->datasourceConfig('RRD')['data_path']
            ?? (Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data');

        if (!is_dir($rrdPath)) {
            $checks[] = ['id' => 'rrd_dir', 'label' => 'RRD data dir', 'status' => 'error',
                'detail' => "Not found: {$rrdPath}", 'group' => $group, 'code' => true, 'hint' => '', 'epoch' => 0];
        } elseif (!is_writable($rrdPath)) {
            $checks[] = ['id' => 'rrd_dir', 'label' => 'RRD data dir', 'status' => 'error',
                'detail' => "Not writable: {$rrdPath}", 'group' => $group, 'code' => true, 'hint' => '', 'epoch' => 0];
        } else {
            $checks[] = ['id' => 'rrd_dir', 'label' => 'RRD data dir', 'status' => 'ok',
                'detail' => $rrdPath, 'group' => $group, 'code' => true, 'hint' => '', 'epoch' => 0];
        }

        $checks[] = ['id' => 'import_years', 'label' => 'Import years',
            'status' => $importYears >= 1 ? 'ok' : 'error',
            'detail' => $importYears >= 1 ? (string) $importYears : 'import_years must be ≥ 1',
            'group' => $group, 'code' => false,
            'hint' => 'Set via NFSEN_IMPORT_YEARS env var (default: 3). Changing requires a force-rescan.',
            'epoch' => 0];

        $profiles = Config::detectProfiles();
        $multiProfile = \count($profiles) > 1;

        foreach ($profiles as $profile) {
            $profileDir = $rrdPath . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $profile);
            $profileLabel = $multiProfile ? "RRD dir ({$profile})" : 'RRD profile dir';
            $profileId = $multiProfile ? "rrd_profile_dir_{$profile}" : 'rrd_profile_dir';

            if (!is_dir($profileDir)) {
                $checks[] = ['id' => $profileId, 'label' => $profileLabel, 'status' => 'warning',
                    'detail' => "Not found: {$profileDir}", 'group' => $group, 'code' => true,
                    'hint' => 'Created automatically when the first nfcapd file is imported for this profile', 'epoch' => 0];

                continue;
            }
            if (!is_writable($profileDir)) {
                $checks[] = ['id' => $profileId, 'label' => $profileLabel, 'status' => 'error',
                    'detail' => "Not writable: {$profileDir}", 'group' => $group, 'code' => true, 'hint' => '', 'epoch' => 0];

                continue;
            }

            foreach ($sources as $source) {
                $rrdFile = $this->get_data_path($source, 0, $profile);
                $sourceLabel = $multiProfile ? "RRD data: {$source} ({$profile})" : "RRD data: {$source}";
                $sourceId = $multiProfile ? "rrd_data_{$profile}_{$source}" : "rrd_data_{$source}";

                if (!file_exists($rrdFile)) {
                    $checks[] = ['id' => $sourceId, 'label' => $sourceLabel,
                        'status' => 'warning', 'detail' => 'No RRD file yet', 'group' => $group,
                        'code' => false, 'hint' => 'Go to Admin → click "Initial Import" to populate the database', 'epoch' => 0];

                    continue;
                }

                try {
                    $lastUpdate = $this->last_update($source, 0, $profile);
                } catch (\Throwable) {
                    $checks[] = ['id' => $sourceId, 'label' => $sourceLabel,
                        'status' => 'warning', 'detail' => 'Could not read last_update',
                        'group' => $group, 'code' => false, 'hint' => '', 'epoch' => 0];

                    continue;
                }
                if ($lastUpdate === 0) {
                    $checks[] = ['id' => $sourceId, 'label' => $sourceLabel,
                        'status' => 'warning', 'detail' => 'RRD exists but has never been written',
                        'group' => $group, 'code' => false, 'hint' => '', 'epoch' => 0];
                } else {
                    $age = time() - $lastUpdate;
                    $status = $age > 3600 ? 'warning' : 'ok';
                    $ageStr = HealthChecker::ageStr($age);
                    $detail = $age <= 0 ? 'Just imported'
                        : ($age > 3600 ? "Last import {$ageStr} ago — may be stalled"
                                       : "Last import {$ageStr} ago");
                    $checks[] = ['id' => $sourceId, 'label' => $sourceLabel,
                        'status' => $status, 'detail' => $detail,
                        'group' => $group, 'code' => false, 'hint' => '', 'epoch' => $lastUpdate];
                }
            }
        }

        return $checks;
    }

    /**
     * Concatenates the path to the source's rrd file.
     * Path: {data_path}/{profile}/{source}{_port}.rrd.
     */
    public function get_data_path(string $source = '', int $port = 0, string $profile = ''): string {
        if ($port === 0) {
            $port = '';
        } else {
            $port = empty($source) ? $port : '_' . $port;
        }

        // Get RRD data path from config or use default
        $rrdPath = Config::$settings->datasourceConfig('RRD')['data_path']
            ?? Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data';

        $p = $profile !== '' ? $profile : Config::$settings->nfdumpProfile;
        // Support nested profiles like 'group/sub' — convert to OS path separator
        $p = str_replace('/', \DIRECTORY_SEPARATOR, $p);

        $path = $rrdPath . \DIRECTORY_SEPARATOR . $p . \DIRECTORY_SEPARATOR . $source . $port . '.rrd';

        if (!file_exists($path)) {
            $this->d->log('Was not able to find ' . $path, LOG_INFO);
        }

        return $path;
    }

    /**
     * Returns summed flows/packets/bytes for the most recently completed 5-min slot
     * across all given sources. Skips missing/unreadable RRD files silently.
     *
     * @param string[] $sources
     *
     * @return array{flows: float, packets: float, bytes: float}
     */
    public function fetchLatestSlot(array $sources, string $profile): array {
        $result = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];

        foreach ($sources as $source) {
            $file = $this->get_data_path($source, 0, $profile);
            if (!file_exists($file)) {
                continue;
            }

            $ts = rrd_last($file);
            if ($ts <= 0) {
                continue;
            }

            $fetchResult = @rrd_fetch($file, ['AVERAGE', '--start', (string) ($ts - 300), '--end', (string) ($ts + 1), '--resolution', '300']);
            if (!isset($fetchResult['data']) || !\is_array($fetchResult['data'])) {
                continue;
            }

            foreach (['flows', 'packets', 'bytes'] as $metric) {
                $metricData = $fetchResult['data'][$metric] ?? null;
                if (!\is_array($metricData)) {
                    continue;
                }

                foreach ($metricData as $val) {
                    if (\is_float($val) && !is_nan($val)) {
                        $result[$metric] += $val;

                        break; // first non-NaN slot is the target slot
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Returns average flows/packets/bytes over a rolling window, summed across sources.
     * Returns [0.0, 0.0, 0.0] when no data is available (cold-start safe).
     *
     * @param string[] $sources
     *
     * @return array{flows: float, packets: float, bytes: float}
     */
    public function fetchRollingAverage(array $sources, string $profile, int $windowSeconds): array {
        $result = ['flows' => 0.0, 'packets' => 0.0, 'bytes' => 0.0];
        $now = time();

        foreach ($sources as $source) {
            $file = $this->get_data_path($source, 0, $profile);
            if (!file_exists($file)) {
                continue;
            }

            $fetchResult = @rrd_fetch($file, ['AVERAGE', '--start', (string) ($now - $windowSeconds), '--end', (string) $now, '--resolution', '300']);
            if (!isset($fetchResult['data']) || !\is_array($fetchResult['data'])) {
                continue;
            }

            foreach (['flows', 'packets', 'bytes'] as $metric) {
                $metricData = $fetchResult['data'][$metric] ?? null;
                if (!\is_array($metricData)) {
                    continue;
                }

                $vals = array_filter(
                    $metricData,
                    static fn ($v) => \is_float($v) && !is_nan($v)
                );

                if (!empty($vals)) {
                    $result[$metric] += array_sum($vals) / \count($vals);
                }
            }
        }

        return $result;
    }

    /**
     * One-time startup migration: moves any flat *.rrd files directly inside
     * {data_path}/ into the {data_path}/{nfdumpProfile}/ subdirectory.
     * This handles upgrading from the old single-profile layout to per-profile subdirs.
     */
    private function migrateRrdsToProfileSubdir(): void {
        $rrdPath = Config::$settings->datasourceConfig('RRD')['data_path']
            ?? Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data';

        $flatFiles = glob($rrdPath . \DIRECTORY_SEPARATOR . '*.rrd') ?: [];
        if (empty($flatFiles)) {
            return;
        }

        $profile = Config::$settings->nfdumpProfile;
        $profileDir = $rrdPath . \DIRECTORY_SEPARATOR . $profile;

        if (!is_dir($profileDir)) {
            if (!mkdir($profileDir, 0o755, true) && !is_dir($profileDir)) {
                $this->d->log('RRD migration: could not create profile dir ' . $profileDir, LOG_ERR);

                return;
            }
        }

        foreach ($flatFiles as $file) {
            $dest = $profileDir . \DIRECTORY_SEPARATOR . basename($file);
            if (rename($file, $dest)) {
                $this->d->log('RRD migration: moved ' . basename($file) . ' → ' . $profile . '/', LOG_WARNING);
            } else {
                $this->d->log('RRD migration: failed to move ' . $file . ' → ' . $profileDir, LOG_ERR);
            }
        }
    }
}
