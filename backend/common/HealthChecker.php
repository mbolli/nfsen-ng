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
    /** Human-readable elapsed time — handles non-positive (clock skew) gracefully. */
    public static function ageStr(int $seconds): string {
        if ($seconds <= 0) {
            return 'just now';
        }
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return round($seconds / 60) . 'min';
        }
        if ($seconds < 86400) {
            return round($seconds / 3600, 1) . 'h';
        }

        return round($seconds / 86400) . 'd';
    }

    /**
     * @param array<string, array{ready: bool, watchCount: int, lastAutoImport: int}> $daemonsInfo Profile-keyed daemon status map. Empty = not running.
     *
     * @return list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}>
     */
    public static function run(bool $daemonDisabled, array $daemonsInfo = []): array {
        /** @var list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}> $checks */
        $checks = [];
        $settings = Config::$settings;

        /**
         * @param 'error'|'ok'|'warning' $status
         * @param bool                   $code   true → wrap detail in <code> in template (paths, identifiers)
         * @param string                 $hint   optional advisory note shown below the detail
         * @param int                    $epoch  unix timestamp: when > 0, template renders a JS-formatted local date
         */
        $add = static function (
            string $id,
            string $label,
            string $status,
            string $detail,
            string $group,
            bool $code = false,
            string $hint = '',
            int $epoch = 0
        ) use (&$checks): void {
            /** @var list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}> $checks */
            $checks[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail,
                'group' => $group, 'code' => $code, 'hint' => $hint, 'epoch' => $epoch];
        };

        $ageStr = static fn (int $s): string => self::ageStr($s);

        // Determine active datasource once — used throughout all checks below.
        $datasource = strtolower($settings->datasourceName);
        $storageGroup = $datasource === 'victoriametrics' ? 'VictoriaMetrics' : 'RRD Storage';

        // Group display order (index = sort priority)
        $groupOrder = array_flip(['PHP Extensions', 'Timezone', 'nfdump', 'Sources', 'Import Daemon', 'nfcapd Paths', $storageGroup]);

        // ── 1. PHP Extensions ────────────────────────────────────────────────
        $phpVer = PHP_VERSION;
        $phpOk = version_compare($phpVer, '8.4.0', '>=');
        $add(
            'php_version',
            'PHP version',
            $phpOk ? 'ok' : 'error',
            $phpOk ? $phpVer : "{$phpVer} — nfsen-ng requires PHP ≥ 8.4",
            'PHP Extensions'
        );

        $swVer = phpversion('openswoole') ?: null;
        $add(
            'ext_openswoole',
            'PHP ext-openswoole',
            $swVer !== null ? 'ok' : 'error',
            $swVer !== null ? "Loaded ({$swVer})" : 'Not loaded — OpenSwoole is required',
            'PHP Extensions'
        );

        // ext_rrd is only required when the RRD datasource is active.
        $rrdOk = \function_exists('rrd_version');
        $add(
            'ext_rrd',
            'PHP ext-rrd',
            $rrdOk ? 'ok' : ($datasource === 'rrd' ? 'error' : 'warning'),
            $rrdOk ? 'Loaded (' . rrd_version() . ')'
                   : ($datasource === 'rrd' ? 'Not loaded — install php-rrd'
                                           : 'Not loaded (not required for ' . $settings->datasourceName . ')'),
            'PHP Extensions'
        );

        $inotifyOk = \function_exists('inotify_init');
        $add(
            'ext_inotify',
            'PHP ext-inotify',
            $inotifyOk ? 'ok' : ($daemonDisabled ? 'warning' : 'error'),
            $inotifyOk ? 'Loaded' : 'Not loaded — install php-inotify',
            'PHP Extensions'
        );

        // ── 2. Timezone ──────────────────────────────────────────────────────────

        $phpTz = date_default_timezone_get();
        $iniTz = (string) \ini_get('date.timezone');
        $envTz = (string) (getenv('TZ') ?: '');

        // Detect non-IANA abbreviations (e.g. CET, EST) — browsers reject them in Intl APIs
        $isNonIana = $iniTz === '' && !str_contains($phpTz, '/')
            && !\in_array($phpTz, ['UTC', 'GMT'], true);

        $tzStatus = 'ok';
        $tzDetail = $phpTz;
        $tzHint = '';

        if ($iniTz !== '' && $envTz !== '' && strcasecmp($iniTz, $envTz) !== 0) {
            $tzStatus = 'warning';
            $tzDetail = $phpTz;
            $tzHint = "php.ini date.timezone='{$iniTz}' overrides TZ env var '{$envTz}' — set NFCAPD_TZ explicitly if nfcapd uses a different timezone";
        } elseif ($isNonIana) {
            $tzStatus = 'warning';
            $tzHint = "'{$phpTz}' is not an IANA timezone identifier — browser Intl APIs may reject it for date display. Use a region/city form (e.g. Europe/London)";
        }

        $add('tz_php', 'PHP timezone', $tzStatus, $tzDetail, 'Timezone', false, $tzHint);

        // nfcapd timezone
        $nfcapdTzEnv = (string) (getenv('NFCAPD_TZ') ?: '');
        if ($nfcapdTzEnv !== '') {
            try {
                $nfcapdTz = new \DateTimeZone($nfcapdTzEnv);
                $add('tz_nfcapd', 'nfcapd timezone', 'ok', $nfcapdTz->getName(), 'Timezone', false, 'Explicit override active via NFCAPD_TZ');
            } catch (\Exception) {
                $add('tz_nfcapd', 'nfcapd timezone', 'error', $nfcapdTzEnv, 'Timezone', true, 'NFCAPD_TZ is not a valid timezone identifier — nfcapd filenames will be parsed in PHP default timezone instead');
            }
        } else {
            $add('tz_nfcapd', 'nfcapd timezone', 'ok', $phpTz, 'Timezone', false, 'Inherited from PHP timezone — set NFCAPD_TZ if nfcapd runs in a different timezone');
        }

        // nfcapd file time plausibility — parse the most recent filename and check it's not in the future
        $plausibilityDone = false;
        $nfcapdTzResolved = Config::nfcapdTimezone();
        $profilesDataP = rtrim($settings->nfdumpProfilesData, '/\\');
        $sourcesP = $settings->sources;
        if ($profilesDataP !== '' && !empty($sourcesP)) {
            foreach (Config::detectProfiles() as $profileP) {
                foreach ($sourcesP as $sourceP) {
                    $basePath = $profilesDataP . \DIRECTORY_SEPARATOR . $profileP . \DIRECTORY_SEPARATOR . $sourceP;
                    // Check today then yesterday
                    foreach ([0, -86400] as $offset) {
                        $dt = new \DateTimeImmutable('now', $nfcapdTzResolved);
                        if ($offset !== 0) {
                            $dt = $dt->modify('-1 day');
                        }
                        $dayDir = $basePath . \DIRECTORY_SEPARATOR . $dt->format('Y') . \DIRECTORY_SEPARATOR . $dt->format('m') . \DIRECTORY_SEPARATOR . $dt->format('d');
                        $files = glob($dayDir . \DIRECTORY_SEPARATOR . 'nfcapd.[0-9]*') ?: [];
                        if (empty($files)) {
                            continue;
                        }
                        // Find the most recent filename by name (lexicographic = chronological for YYYYMMDDHHII)
                        rsort($files);
                        $newest = basename($files[0]);
                        if (!preg_match('/^nfcapd\.(\d{12})$/', $newest, $fm)) {
                            continue;
                        }
                        $fileDt = \DateTime::createFromFormat('YmdHi', $fm[1], $nfcapdTzResolved);
                        if ($fileDt === false) {
                            continue;
                        }
                        $fileEpoch = $fileDt->getTimestamp();
                        $now = time();
                        $plausibilityDone = true;
                        if ($fileEpoch > $now + 1800) {
                            $minutesAhead = (int) round(($fileEpoch - $now) / 60);
                            $add(
                                'tz_plausibility',
                                'nfcapd file time',
                                'warning',
                                "Most recent file ({$newest}) is {$minutesAhead} min in the future",
                                'Timezone',
                                false,
                                'Check NFCAPD_TZ — nfcapd filenames may be in a different timezone than configured'
                            );
                        } else {
                            $fileAge = $ageStr($now - $fileEpoch);
                            $add('tz_plausibility', 'nfcapd file time', 'ok', "Most recent: {$newest} ({$fileAge} ago)", 'Timezone');
                        }

                        break 3; // Found a file — stop searching
                    }
                }
            }
        }

        if (!$plausibilityDone) {
            // No files found — freshness checks in section 5 will cover this
            $add('tz_plausibility', 'nfcapd file time', 'ok', 'No files found to check', 'Timezone');
        }

        // ── 3. nfdump ────────────────────────────────────────────────────────────
        $binary = $settings->nfdumpBinary;
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
                $nfdumpVer = ($ret !== 127 && \count($out) > 0) ? trim($out[0]) : '';
            }
            // Parse raw output: "/path/nfdump: Version: 1.7.6-release Options: ZSTD BZIP2 Date: ..."
            $vDetail = $nfdumpVer;
            if (preg_match('/Version:\s*(\S+)/', $nfdumpVer, $vm)) {
                $vDetail = 'v' . $vm[1];
                if (preg_match('/Options:\s*([\w ]+?)(?:\s+Date:|$)/', $nfdumpVer, $om)) {
                    $vDetail .= ' (' . trim($om[1]) . ')';
                }
            }
            $add(
                'nfdump_binary',
                'nfdump binary',
                $nfdumpVer !== '' ? 'ok' : 'error',
                $nfdumpVer !== '' ? $vDetail : 'Execution failed',
                'nfdump'
            );

            // Minimum version check.
            // -o json field names (ts, td, pr, sa, …) changed completely in 1.7.0
            // vs the 1.6.x scheme (t_first, t_last, …). 1.7.2 was the first
            // production-recommended 1.7.x release.
            $minNfdump = '1.7.2';
            if ($nfdumpVer !== '' && isset($vm[1])) {
                $numericVer = (string) preg_replace('/-.*$/', '', $vm[1]); // strip -release suffix
                $meetsMin = version_compare($numericVer, $minNfdump, '>=');
                $add(
                    'nfdump_version',
                    'Minimum version',
                    $meetsMin ? 'ok' : 'error',
                    $meetsMin
                        ? "≥ {$minNfdump}"
                        : "v{$numericVer} — nfsen-ng requires nfdump ≥ {$minNfdump}",
                    'nfdump',
                    false,
                    $meetsMin ? '' : 'JSON output field names differ in 1.6.x; upgrade to nfdump ≥ 1.7.2'
                );
            }
        }

        $maxProc = $settings->nfdumpMaxProcesses;
        $add(
            'nfdump_max_processes',
            'Max processes',
            $maxProc >= 1 ? 'ok' : 'error',
            $maxProc >= 1 ? (string) $maxProc : 'max-processes must be ≥ 1',
            'nfdump'
        );

        // Misc::countProcessesByName() (used to enforce nfdumpMaxProcesses) silently
        // returns 0 when neither tool is present, so the concurrency guard never
        // trips — surface that as a warning rather than let it fail invisibly.
        $hasProcTool = Misc::hasProcessInspectionTool();
        $add(
            'nfdump_process_inspection',
            'Process inspection',
            $hasProcTool ? 'ok' : 'warning',
            $hasProcTool ? 'ps/pgrep available' : 'Neither ps nor pgrep found',
            'nfdump',
            false,
            $hasProcTool ? '' : 'Max processes above is not enforced — install procps (provides ps and pgrep) in the container image'
        );

        // ── 4. Sources ───────────────────────────────────────────────────────
        $sources = $settings->sources;
        $add(
            'sources_nonempty',
            'Sources configured',
            !empty($sources) ? 'ok' : 'error',
            !empty($sources) ? implode(', ', $sources) : 'No sources in general.sources',
            'Sources',
            !empty($sources)
        );

        // ── 5. Import Daemon ─────────────────────────────────────────────────
        if ($daemonDisabled) {
            $add(
                'daemon_status',
                'Daemon status',
                'warning',
                'Disabled',
                'Import Daemon',
                false,
                'Import must be triggered manually from this panel'
            );
        } elseif (empty($daemonsInfo)) {
            $add(
                'daemon_status',
                'Daemon status',
                'error',
                'Not running — restart required',
                'Import Daemon'
            );
        } else {
            foreach ($daemonsInfo as $prof => $daemonInfo) {
                $label = \count($daemonsInfo) > 1 ? "Daemon ({$prof})" : 'Daemon status';
                $idPfx = \count($daemonsInfo) > 1 ? "daemon_{$prof}" : 'daemon';
                if (!$daemonInfo['ready']) {
                    $add("{$idPfx}_status", $label, 'warning', 'Initializing…', 'Import Daemon');
                } else {
                    $n = $daemonInfo['watchCount'];
                    $add("{$idPfx}_status", $label, 'ok', 'Watching ' . $n . ' dir' . ($n !== 1 ? 's' : ''), 'Import Daemon');
                    if ($daemonInfo['lastAutoImport'] > 0) {
                        $autoAge = time() - $daemonInfo['lastAutoImport'];
                        $autoDetail = $autoAge <= 0 ? 'Just now' : $ageStr($autoAge) . ' ago';
                        $lastLabel = \count($daemonsInfo) > 1 ? "Last import ({$prof})" : 'Last auto-import';
                        $add("{$idPfx}_last_import", $lastLabel, 'ok', $autoDetail, 'Import Daemon', false, '', $daemonInfo['lastAutoImport']);
                    }
                }
            }
        }

        // ── 6. nfcapd Paths ──────────────────────────────────────────────────
        $profilesData = rtrim($settings->nfdumpProfilesData, '/\\');
        $profiles = Config::detectProfiles();
        $multiProfile = \count($profiles) > 1;

        if ($profilesData === '') {
            $add('profiles_data', 'profiles-data dir', 'error', 'nfdump.profiles-data not set in config', 'nfcapd Paths');
        } elseif (!is_dir($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not found: {$profilesData}", 'nfcapd Paths', true);
        } elseif (!is_readable($profilesData) || !is_executable($profilesData)) {
            $add('profiles_data', 'profiles-data dir', 'error', "Not readable: {$profilesData}", 'nfcapd Paths', true);
        } else {
            $add('profiles_data', 'profiles-data dir', 'ok', $profilesData, 'nfcapd Paths', true);

            foreach ($profiles as $profile) {
                $profilePath = $profilesData . \DIRECTORY_SEPARATOR . $profile;
                $profileLabel = $multiProfile ? "Profile '{$profile}'" : "Profile dir '{$profile}'";
                $profileIdPfx = $multiProfile ? "profile_{$profile}" : 'profile';

                if (!is_dir($profilePath)) {
                    $add("{$profileIdPfx}_dir", $profileLabel, 'error', "Not found: {$profilePath}", 'nfcapd Paths', true);

                    continue;
                }

                $add("{$profileIdPfx}_dir", $profileLabel, 'ok', $profilePath, 'nfcapd Paths', true);

                foreach ($sources as $source) {
                    $sourcePath = $profilePath . \DIRECTORY_SEPARATOR . $source;
                    $sourceIdPfx = $multiProfile ? "{$profile}_{$source}" : $source;
                    $sourceLabel = $multiProfile ? "Source {$source} ({$profile})" : "Source dir: {$source}";

                    if (!is_dir($sourcePath)) {
                        $add(
                            "source_dir_{$sourceIdPfx}",
                            $sourceLabel,
                            'error',
                            "Not found: {$sourcePath}",
                            'nfcapd Paths',
                            true
                        );

                        continue;
                    }

                    // Flat layout detection
                    $flatFiles = glob($sourcePath . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                    $flatFiles = array_filter($flatFiles, static fn ($f) => is_file($f)
                        && !is_link($f)
                        && !str_contains($f, '.current.'));
                    if (\count($flatFiles) > 0) {
                        $add(
                            "source_layout_{$sourceIdPfx}",
                            $multiProfile ? "Layout {$source} ({$profile})" : "Source layout: {$source}",
                            'error',
                            'nfcapd files in flat structure — run reorganize_nfcapd.sh and configure nfcapd with -S 1',
                            'nfcapd Paths'
                        );

                        continue;
                    }

                    // Capture freshness: check today's YYYY/MM/DD dir (in nfcapd timezone)
                    $todayDt = new \DateTimeImmutable('now', Config::nfcapdTimezone());
                    $today = $todayDt->format('Y') . \DIRECTORY_SEPARATOR . $todayDt->format('m') . \DIRECTORY_SEPARATOR . $todayDt->format('d');
                    $todayDir = $sourcePath . \DIRECTORY_SEPARATOR . $today;
                    $freshnessLabel = $multiProfile ? "Freshness {$source} ({$profile})" : "Capture freshness: {$source}";
                    if (!is_dir($todayDir)) {
                        $add(
                            "capture_fresh_{$sourceIdPfx}",
                            $freshnessLabel,
                            'warning',
                            "No data dir for today ({$today})",
                            'nfcapd Paths'
                        );
                    } else {
                        $files = glob($todayDir . \DIRECTORY_SEPARATOR . 'nfcapd.*') ?: [];
                        if ($files === []) {
                            $add(
                                "capture_fresh_{$sourceIdPfx}",
                                $freshnessLabel,
                                'warning',
                                "No nfcapd files in today's dir",
                                'nfcapd Paths'
                            );
                        } else {
                            $mtimes = array_map('filemtime', $files);
                            $newestMtime = max($mtimes);
                            $age = time() - $newestMtime;
                            // nfcapd default rotation is 5 min; warn after 12 min (2.4×) to allow for slow systems
                            $status = $age > 720 ? 'warning' : 'ok';
                            if ($age <= 0) {
                                $detail = 'Just captured';
                            } elseif ($age > 720) {
                                $detail = 'Last file ' . $ageStr($age) . ' ago — nfcapd may have stopped';
                            } else {
                                $detail = 'Last file ' . $ageStr($age) . ' ago';
                            }
                            $add("capture_fresh_{$sourceIdPfx}", $freshnessLabel, $status, $detail, 'nfcapd Paths', false, '', $newestMtime);
                        }
                    }
                }
            }
        }

        // ── 7. Storage ───────────────────────────────────────────────────────
        // Delegated to the active datasource implementation — each knows its
        // own connectivity checks, file paths, and per-source freshness logic.
        if (isset(Config::$db)) {
            array_push($checks, ...Config::$db->healthChecks($storageGroup, $sources));
        }

        // Sort: group order first, then errors-before-warnings-before-ok within each group
        $statusOrder = ['error' => 0, 'warning' => 1, 'ok' => 2];
        usort(
            $checks,
            static fn ($a, $b) => ($groupOrder[$a['group']] ?? 99) <=> ($groupOrder[$b['group']] ?? 99)
            ?: $statusOrder[$a['status']] <=> $statusOrder[$b['status']]
        );

        return $checks;
    }
}
