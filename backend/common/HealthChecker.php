<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * HealthChecker — runs a suite of configuration and environment checks.
 *
 * Call HealthChecker::run() on every view render (cached in app.php with a 30s
 * throttle so it doesn't block every SSE re-render).
 *
 * Returns checks sorted by group (defined order), then errors-first within
 * each group, so the template can render with visual group separators.
 *
 * @phpstan-type HealthCheck array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}
 * @phpstan-type DaemonInfo array{ready: bool, watchCount: int, lastAutoImport: int}
 */
class HealthChecker {
    /**
     * @param array{ready: bool, watchCount: int, lastAutoImport: int}|null $daemonInfo
     * @return list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}>
     */
    public static function run(bool $daemonDisabled, ?array $daemonInfo = null): array {
        /** @var list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}> $checks */
        $checks = [];
        $cfg = Config::$cfg;

        // Group display order (index = sort priority)
        $groupOrder = array_flip(['PHP Extensions', 'nfdump', 'Sources', 'Import Daemon', 'nfcapd Paths', 'RRD Storage']);

        /**
         * @param 'ok'|'warning'|'error' $status
         * @param bool   $code   true → wrap detail in <code> in template (paths, identifiers)
         * @param string $hint   optional advisory note shown below the detail
         * @param int    $epoch  unix timestamp: when > 0, template renders a JS-formatted local date
         */
        $add = static function (
            string $id, string $label, string $status, string $detail,
            string $group, bool $code = false, string $hint = '', int $epoch = 0
        ) use (&$checks): void {
            /** @var list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}> $checks */
            $checks[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail,
                         'group' => $group, 'code' => $code, 'hint' => $hint, 'epoch' => $epoch];
        };

        // Human-readable elapsed time — handles non-positive (clock skew) gracefully
        $ageStr = static function (int $seconds): string {
            if ($seconds <= 0) return 'just now';
            if ($seconds < 60) return "{$seconds}s";
            if ($seconds < 3600) return round($seconds / 60) . 'min';
            if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
            return round($seconds / 86400) . 'd';
        };

        // ── 1. PHP Extensions ────────────────────────────────────────────────
        $phpVer = PHP_VERSION;
        $phpOk  = version_compare($phpVer, '8.1.0', '>=');
        $add('php_version', 'PHP version', $phpOk ? 'ok' : 'error',
            $phpOk ? $phpVer : "{$phpVer} — nfsen-ng requires PHP ≥ 8.1",
            'PHP Extensions');

        $swVer = phpversion('openswoole') ?: null;
        $add('ext_openswoole', 'PHP ext-openswoole', $swVer !== null ? 'ok' : 'error',
            $swVer !== null ? "Loaded ({$swVer})" : 'Not loaded — OpenSwoole is required',
            'PHP Extensions');

        $rrdOk = function_exists('rrd_version');
        $add('ext_rrd', 'PHP ext-rrd', $rrdOk ? 'ok' : 'error',
            // @phpstan-ignore-next-line (rrd_version is a dynamic extension function)
            $rrdOk ? 'Loaded (' . \rrd_version() . ')' : 'Not loaded — install php-rrd',
            'PHP Extensions');

        $inotifyOk = function_exists('inotify_init');
        $add('ext_inotify', 'PHP ext-inotify',
            $inotifyOk ? 'ok' : ($daemonDisabled ? 'warning' : 'error'),
            $inotifyOk ? 'Loaded' : 'Not loaded — install php-inotify',
            'PHP Extensions');

        // ── 2. nfdump ────────────────────────────────────────────────────────
        $binary = $cfg['nfdump']['binary'] ?? '';
        if ($binary === '') {
            $add('nfdump_binary', 'nfdump binary', 'error', 'nfdump.binary not set in config', 'nfdump');
        } elseif (!file_exists($binary)) {
            $add('nfdump_binary', 'nfdump binary', 'error', "Not found: {$binary}", 'nfdump', true);
        } elseif (!is_executable($binary)) {
            $add('nfdump_binary', 'nfdump binary', 'error', "Not executable: {$binary}", 'nfdump', true);
        } else {
            // Cache nfdump version for the lifetime of the server process
            static $nfdumpVer = null;
            if ($nfdumpVer === null) {
                $out = [];
                $ret = 0;
                exec(escapeshellarg($binary) . ' -V 2>&1', $out, $ret);
                $nfdumpVer = ($ret !== 127 && count($out) > 0) ? trim($out[0]) : '';
            }
            // Parse raw output: "/path/nfdump: Version: 1.7.6-release Options: ZSTD BZIP2 Date: ..."
            $vDetail = $nfdumpVer;
            if (preg_match('/Version:\s*(\S+)/', $nfdumpVer, $vm)) {
                $vDetail = 'v' . $vm[1];
                if (preg_match('/Options:\s*([\w ]+?)(?:\s+Date:|$)/', $nfdumpVer, $om)) {
                    $vDetail .= ' (' . trim($om[1]) . ')';
                }
            }
            $add('nfdump_binary', 'nfdump binary', $nfdumpVer !== '' ? 'ok' : 'error',
                $nfdumpVer !== '' ? $vDetail : 'Execution failed',
                'nfdump');
        }

        $maxProc = (int) ($cfg['nfdump']['max-processes'] ?? 0);
        $add('nfdump_max_processes', 'Max processes',
            $maxProc >= 1 ? 'ok' : 'error',
            $maxProc >= 1 ? (string) $maxProc : 'max-processes must be ≥ 1',
            'nfdump');

        // ── 3. Sources ───────────────────────────────────────────────────────
        $sources = $cfg['general']['sources'] ?? [];
        $add('sources_nonempty', 'Sources configured',
            !empty($sources) ? 'ok' : 'error',
            !empty($sources) ? implode(', ', $sources) : 'No sources in general.sources',
            'Sources', !empty($sources));

        // ── 4. Import Daemon ─────────────────────────────────────────────────
        if ($daemonDisabled) {
            $add('daemon_status', 'Daemon status', 'warning',
                'Disabled', 'Import Daemon', false,
                'Import must be triggered manually from this panel');
        } elseif ($daemonInfo === null) {
            $add('daemon_status', 'Daemon status', 'error',
                'Not running — restart required', 'Import Daemon');
        } elseif (!$daemonInfo['ready']) {
            $add('daemon_status', 'Daemon status', 'warning',
                'Initializing…', 'Import Daemon');
        } else {
            $n = $daemonInfo['watchCount'];
            $add('daemon_status', 'Daemon status', 'ok',
                'Watching ' . $n . ' dir' . ($n !== 1 ? 's' : ''), 'Import Daemon');
                    if ($daemonInfo['lastAutoImport'] > 0) {
                        $autoAge = time() - $daemonInfo['lastAutoImport'];
                        $autoDetail = $autoAge <= 0 ? 'Just now' : $ageStr($autoAge) . ' ago';
                        $add('daemon_last_import', 'Last auto-import', 'ok',
                            $autoDetail, 'Import Daemon', false, '', $daemonInfo['lastAutoImport']);
                    }
        }

        // ── 5. nfcapd Paths ──────────────────────────────────────────────────
        $profilesData = rtrim($cfg['nfdump']['profiles-data'] ?? '', '/\\');
        $profile      = $cfg['nfdump']['profile'] ?? 'live';

        if ($profilesData === '') {
            $add('profiles_data', 'profiles-data dir', 'error', 'nfdump.profiles-data not set in config', 'nfcapd Paths');
        } elseif (!is_dir($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not found: {$profilesData}", 'nfcapd Paths', true);
        } elseif (!is_readable($profilesData) || !is_executable($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not readable: {$profilesData}", 'nfcapd Paths', true);
        } else {
            $add('profiles_data', 'profiles-data dir', 'ok', $profilesData, 'nfcapd Paths', true);

            $profilePath = $profilesData . \DIRECTORY_SEPARATOR . $profile;
            if (!is_dir($profilePath)) {
                $add('profile_dir', "Profile dir '{$profile}'", 'error', "Not found: {$profilePath}", 'nfcapd Paths', true);
            } else {
                $add('profile_dir', "Profile dir '{$profile}'", 'ok', $profilePath, 'nfcapd Paths', true);

                foreach ($sources as $source) {
                    $sourcePath = $profilePath . \DIRECTORY_SEPARATOR . $source;

                    if (!is_dir($sourcePath)) {
                        $add("source_dir_{$source}", "Source dir: {$source}", 'error',
                            "Not found: {$sourcePath}", 'nfcapd Paths', true);
                        continue;
                    }

                    // Flat layout detection
                    $flatFiles = glob($sourcePath . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                    $flatFiles = array_filter($flatFiles, static fn ($f) => is_file($f)
                        && !is_link($f)
                        && !str_contains($f, '.current.'));
                    if (count($flatFiles) > 0) {
                        $add("source_layout_{$source}", "Source layout: {$source}", 'error',
                            "nfcapd files in flat structure — run reorganize_nfcapd.sh and configure nfcapd with -S 1",
                            'nfcapd Paths');
                        continue;
                    }

                    // Capture freshness: check today's YYYY/MM/DD dir
                    $today    = date('Y') . \DIRECTORY_SEPARATOR . date('m') . \DIRECTORY_SEPARATOR . date('d');
                    $todayDir = $sourcePath . \DIRECTORY_SEPARATOR . $today;
                    if (!is_dir($todayDir)) {
                        $add("capture_fresh_{$source}", "Capture freshness: {$source}", 'warning',
                            "No data dir for today ({$today})", 'nfcapd Paths');
                    } else {
                        $files = glob($todayDir . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                        if ($files === []) {
                            $add("capture_fresh_{$source}", "Capture freshness: {$source}", 'warning',
                                "No nfcapd files in today's dir", 'nfcapd Paths');
                        } else {
                            $mtimes      = array_map('filemtime', $files);
                            $newestMtime = max($mtimes);
                            $age         = time() - $newestMtime;
                            // nfcapd default rotation is 5 min; warn after 12 min (2.4×) to allow for slow systems
                            $status = $age > 720 ? 'warning' : 'ok';
                            if ($age <= 0) {
                                $detail = 'Just captured';
                            } elseif ($age > 720) {
                                $detail = 'Last file ' . $ageStr($age) . ' ago — nfcapd may have stopped';
                            } else {
                                $detail = 'Last file ' . $ageStr($age) . ' ago';
                            }
                            $add("capture_fresh_{$source}", "Capture freshness: {$source}", $status, $detail, 'nfcapd Paths', false, '', $newestMtime);
                        }
                    }
                }
            }
        }

        // ── 6. RRD Storage ───────────────────────────────────────────────────
        $datasource = $cfg['general']['db'] ?? 'RRD';
        $rrdPath    = $cfg['db']['RRD']['data_path']
            ?? (Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data');

        if (!is_dir($rrdPath)) {
            $add('rrd_dir', 'RRD data dir', 'error', "Not found: {$rrdPath}", 'RRD Storage', true);
        } elseif (!is_writable($rrdPath)) {
            $add('rrd_dir', 'RRD data dir', 'error', "Not writable: {$rrdPath}", 'RRD Storage', true);
        } else {
            $add('rrd_dir', 'RRD data dir', 'ok', $rrdPath, 'RRD Storage', true);
        }

        $importYears = (int) ($cfg['db'][$datasource]['import_years'] ?? 0);
        $add('import_years', 'Import years',
            $importYears >= 1 ? 'ok' : 'error',
            $importYears >= 1 ? (string) $importYears : 'import_years must be ≥ 1',
            'RRD Storage', false,
            'Set via NFSEN_IMPORT_YEARS env var (default: 3). Changing requires a force-rescan.');

        // Per-source RRD freshness (only meaningful for RRD datasource)
        if (strtolower($datasource) === 'rrd' && isset(Config::$db)) {
            foreach ($sources as $source) {
                $rrdFile = Config::$db->get_data_path($source);
                if (!file_exists($rrdFile)) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning',
                        'No RRD file yet — has the import run?', 'RRD Storage');
                    continue;
                }
                try {
                    $lastUpdate = Config::$db->last_update($source);
                } catch (\Throwable) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning', 'Could not read last_update', 'RRD Storage');
                    continue;
                }
                if ($lastUpdate === 0) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning',
                        'RRD exists but has never been written', 'RRD Storage');
                } else {
                    $age    = time() - $lastUpdate;
                    $status = $age > 3600 ? 'warning' : 'ok';
                    if ($age <= 0) {
                        $detail = 'Just imported';
                    } elseif ($age > 3600) {
                        $detail = 'Last import ' . $ageStr($age) . ' ago — may be stalled';
                    } else {
                        $detail = 'Last import ' . $ageStr($age) . ' ago';
                    }
                    $add("rrd_data_{$source}", "RRD data: {$source}", $status, $detail, 'RRD Storage', false, '', $lastUpdate);
                }
            }
        }

        // Sort: group order first, then errors-before-warnings-before-ok within each group
        $statusOrder = ['error' => 0, 'warning' => 1, 'ok' => 2];
        usort($checks, static fn ($a, $b) =>
            ($groupOrder[$a['group']] ?? 99) <=> ($groupOrder[$b['group']] ?? 99)
            ?: $statusOrder[$a['status']] <=> $statusOrder[$b['status']]
        );

        return $checks;
    }
}
