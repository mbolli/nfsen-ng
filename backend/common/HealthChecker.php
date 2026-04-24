<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * HealthChecker — runs a suite of configuration and environment checks.
 *
 * Call HealthChecker::run() on every view render (cached in app.php with a 30s
 * throttle so it doesn't block every SSE re-render).
 *
 * Returns a list sorted errors-first, then warnings, then ok so the template
 * can render without any further sorting.
 *
 * @phpstan-type HealthCheck array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string}
 */
class HealthChecker {
    /** @return list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string}> */
    public static function run(bool $daemonDisabled): array {
        /** @var list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string}> $checks */
        $checks = [];
        $cfg = Config::$cfg;

        /** @param 'ok'|'warning'|'error' $status */
        $add = static function (string $id, string $label, string $status, string $detail) use (&$checks): void {
            /** @var list<array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string}> $checks */
            $checks[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // ── 1. PHP extensions ────────────────────────────────────────────────
        $rrdOk = function_exists('rrd_version');
        $add('ext_rrd', 'PHP ext-rrd', $rrdOk ? 'ok' : 'error',
            $rrdOk ? 'Loaded (' . rrd_version() . ')' : 'Not loaded — install php-rrd');

        $inotifyOk = function_exists('inotify_init');
        $add('ext_inotify', 'PHP ext-inotify',
            $inotifyOk ? 'ok' : ($daemonDisabled ? 'warning' : 'error'),
            $inotifyOk ? 'Loaded' : 'Not loaded — install php-inotify');

        // ── 2. nfdump binary ─────────────────────────────────────────────────
        $binary = $cfg['nfdump']['binary'] ?? '';
        if ($binary === '') {
            $add('nfdump_binary', 'nfdump binary', 'error', 'nfdump.binary not set in config');
        } elseif (!file_exists($binary)) {
            $add('nfdump_binary', 'nfdump binary', 'error', "Not found: {$binary}");
        } elseif (!is_executable($binary)) {
            $add('nfdump_binary', 'nfdump binary', 'error', "Not executable: {$binary}");
        } else {
            // Cache nfdump version for the lifetime of the server process
            static $nfdumpVer = null;
            if ($nfdumpVer === null) {
                $out = [];
                $ret = 0;
                exec(escapeshellarg($binary) . ' -V 2>&1', $out, $ret);
                $nfdumpVer = ($ret !== 127 && count($out) > 0) ? trim($out[0]) : '';
            }
            $add('nfdump_binary', 'nfdump binary', $nfdumpVer !== '' ? 'ok' : 'error',
                $nfdumpVer !== '' ? $nfdumpVer : 'Execution failed');
        }

        $maxProc = (int) ($cfg['nfdump']['max-processes'] ?? 0);
        $add('nfdump_max_processes', 'nfdump max-processes',
            $maxProc >= 1 ? 'ok' : 'error',
            $maxProc >= 1 ? "max-processes = {$maxProc}" : 'max-processes must be ≥ 1');

        // ── 3. Sources ───────────────────────────────────────────────────────
        $sources = $cfg['general']['sources'] ?? [];
        $add('sources_nonempty', 'Sources configured',
            !empty($sources) ? 'ok' : 'error',
            !empty($sources) ? implode(', ', $sources) : 'No sources in general.sources');

        // ── 4. nfcapd paths ──────────────────────────────────────────────────
        $profilesData = rtrim($cfg['nfdump']['profiles-data'] ?? '', '/\\');
        $profile      = $cfg['nfdump']['profile'] ?? 'live';

        if ($profilesData === '') {
            $add('profiles_data', 'profiles-data dir', 'error', 'nfdump.profiles-data not set in config');
        } elseif (!is_dir($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not found: {$profilesData}");
        } elseif (!is_readable($profilesData) || !is_executable($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not readable: {$profilesData}");
        } else {
            $add('profiles_data', 'profiles-data dir', 'ok', $profilesData);

            $profilePath = $profilesData . \DIRECTORY_SEPARATOR . $profile;
            if (!is_dir($profilePath)) {
                $add('profile_dir', "Profile dir '{$profile}'", 'error', "Not found: {$profilePath}");
            } else {
                $add('profile_dir', "Profile dir '{$profile}'", 'ok', $profilePath);

                foreach ($sources as $source) {
                    $sourcePath = $profilePath . \DIRECTORY_SEPARATOR . $source;

                    if (!is_dir($sourcePath)) {
                        $add("source_dir_{$source}", "Source dir: {$source}", 'error',
                            "Not found: {$sourcePath}");
                        continue;
                    }

                    // Flat layout detection
                    $flatFiles = glob($sourcePath . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                    $flatFiles = array_filter($flatFiles, static fn ($f) => is_file($f)
                        && !is_link($f)
                        && !str_contains($f, '.current.'));
                    if (count($flatFiles) > 0) {
                        $add("source_layout_{$source}", "Source layout: {$source}", 'error',
                            "nfcapd files in flat structure — run reorganize_nfcapd.sh and configure nfcapd with -S 1");
                        continue;
                    }

                    // Capture freshness: check today's YYYY/MM/DD dir
                    $today    = date('Y') . \DIRECTORY_SEPARATOR . date('m') . \DIRECTORY_SEPARATOR . date('d');
                    $todayDir = $sourcePath . \DIRECTORY_SEPARATOR . $today;
                    if (!is_dir($todayDir)) {
                        $add("capture_fresh_{$source}", "Capture freshness: {$source}", 'warning',
                            "No data dir for today ({$today})");
                    } else {
                        $files = glob($todayDir . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                        if ($files === []) {
                            $add("capture_fresh_{$source}", "Capture freshness: {$source}", 'warning',
                                "No nfcapd files in today's dir");
                        } else {
                            $mtimes    = array_map('filemtime', $files);
                            $newestMtime = max($mtimes);
                            $age       = time() - $newestMtime;
                            $ageStr    = $age < 60 ? "{$age}s" : round($age / 60) . 'min';
                            // nfcapd default rotation is 5 min; warn after 12 min (2.4×) to allow for slow systems
                            $status = $age > 720 ? 'warning' : 'ok';
                            $detail = $age > 720
                                ? "Last file {$ageStr} ago — nfcapd may have stopped"
                                : "Last file {$ageStr} ago";
                            $add("capture_fresh_{$source}", "Capture freshness: {$source}", $status, $detail);
                        }
                    }
                }
            }
        }

        // ── 5. RRD storage ───────────────────────────────────────────────────
        $datasource = $cfg['general']['db'] ?? 'RRD';
        $rrdPath    = $cfg['db']['RRD']['data_path']
            ?? (Config::$path . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data');

        if (!is_dir($rrdPath)) {
            $add('rrd_dir', 'RRD data dir', 'error', "Not found: {$rrdPath}");
        } elseif (!is_writable($rrdPath)) {
            $add('rrd_dir', 'RRD data dir', 'error', "Not writable: {$rrdPath}");
        } else {
            $add('rrd_dir', 'RRD data dir', 'ok', $rrdPath);
        }

        $importYears = (int) ($cfg['db'][$datasource]['import_years'] ?? 0);
        $add('import_years', 'import_years setting',
            $importYears >= 1 ? 'ok' : 'error',
            $importYears >= 1 ? "import_years = {$importYears}" : 'import_years must be ≥ 1');

        // Per-source RRD freshness (only meaningful for RRD datasource)
        if (strtolower($datasource) === 'rrd' && isset(Config::$db)) {
            foreach ($sources as $source) {
                $rrdFile = Config::$db->get_data_path($source);
                if (!file_exists($rrdFile)) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning',
                        'No RRD file yet — has the import run?');
                    continue;
                }
                try {
                    $lastUpdate = Config::$db->last_update($source);
                } catch (\Throwable) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning', 'Could not read last_update');
                    continue;
                }
                if ($lastUpdate === 0) {
                    $add("rrd_data_{$source}", "RRD data: {$source}", 'warning',
                        'RRD exists but has never been written');
                } else {
                    $age    = time() - $lastUpdate;
                    $ageStr = $age < 60 ? "{$age}s" : round($age / 60) . 'min';
                    $status = $age > 3600 ? 'warning' : 'ok';
                    $detail = $age > 3600
                        ? "Last write {$ageStr} ago — import may be stalled"
                        : "Last write {$ageStr} ago";
                    $add("rrd_data_{$source}", "RRD data: {$source}", $status, $detail);
                }
            }
        }

        // Sort: errors first, then warnings, then ok
        $order = ['error' => 0, 'warning' => 1, 'ok' => 2];
        usort($checks, static fn ($a, $b) => $order[$a['status']] <=> $order[$b['status']]);

        return $checks;
    }
}
