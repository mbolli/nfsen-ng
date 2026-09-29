<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

use mbolli\nfsen_ng\processor\Nfdump;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migrator;

/**
 * Runs a suite of configuration and environment checks. The Health page caches the result
 * for 30 s (HealthPage::CACHE); the MCP status tool calls it directly.
 *
 * Returns checks sorted by group (defined order), then errors-first within
 * each group, so the template can render with visual group separators.
 * Details and hints are plain text: the template escapes them.
 *
 * @phpstan-type HealthCheck array{id: string, label: string, status: 'ok'|'warning'|'error', detail: string, group: string, code: bool, hint: string, epoch: int}
 * @phpstan-type DaemonInfo array{ready: bool, watchCount: int, lastAutoImport: int}
 *
 * @phpstan-import-type DiskUsage from HealthMetrics
 * @phpstan-import-type ProcessBudget from HealthMetrics
 * @phpstan-import-type ActiveQueries from HealthMetrics
 * @phpstan-import-type StoreFacts from Database
 */
class HealthChecker {
    public const string STORE_GROUP = 'Storage (SQLite)';

    public const string DISK_GROUP = 'Disk space';

    /** Used share of a filesystem at which its row and usage bar turn warning, then error (2.5). */
    public const float DISK_WARNING = 85.0;

    public const float DISK_ERROR = 95.0;

    /** Oldest SQLite that runs the top-N rollups (`UPDATE ... FROM`). */
    public const string SQLITE_MIN = '3.33.0';

    /** -o json field names (ts, td, pr, sa, ...) differ in 1.6.x; 1.7.2 was the first production-ready 1.7. */
    public const string NFDUMP_MIN = '1.7.2';

    /** 1.7.9 fixed a series of security issues in the collectors and in reading capture files. */
    public const string NFDUMP_RECOMMENDED = '1.7.9';

    /** Human-readable elapsed time; non-positive values (clock skew) read as "just now". */
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
     * @param null|StoreFacts                                                         $storeFacts  Database::inspect() of the store when the caller already has it
     *
     * @return list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}>
     */
    public static function run(bool $daemonDisabled, array $daemonsInfo = [], ?array $storeFacts = null): array {
        /** @var list<array{id: string, label: string, status: 'error'|'ok'|'warning', detail: string, group: string, code: bool, hint: string, epoch: int}> $checks */
        $checks = [];
        $settings = Config::$settings;
        // The plausibility and freshness checks both want today's or yesterday's newest file.
        $newestCache = [];
        $newestOf = static function (string $profile, string $source) use (&$newestCache): ?array {
            $key = "{$profile}/{$source}";
            if (!\array_key_exists($key, $newestCache)) {
                $newestCache[$key] = NfcapdFiles::newest($profile, $source, 1);
            }

            return $newestCache[$key];
        };

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

        // Determine the active datasource once; every check below uses it.
        $datasource = strtolower($settings->datasourceName);
        $storageGroup = $datasource === 'victoriametrics' ? 'VictoriaMetrics' : 'RRD Storage';

        // Group display order (index = sort priority)
        $groupOrder = array_flip(['PHP Extensions', 'Configuration', 'Timezone', 'nfdump', 'Sources', 'Import Daemon', 'nfcapd Paths', $storageGroup, self::STORE_GROUP, self::DISK_GROUP]);

        // ── 1. PHP Extensions ────────────────────────────────────────────────
        $phpVer = PHP_VERSION;
        $phpOk = version_compare($phpVer, '8.4.0', '>=');
        $add(
            'php_version',
            'PHP version',
            $phpOk ? 'ok' : 'error',
            $phpOk ? $phpVer : "{$phpVer}. nfsen-ng requires PHP 8.4 or later.",
            'PHP Extensions'
        );

        $swVer = phpversion('openswoole') ?: null;
        $add(
            'ext_openswoole',
            'PHP ext-openswoole',
            $swVer !== null ? 'ok' : 'error',
            $swVer !== null ? "Loaded ({$swVer})" : 'Not loaded. OpenSwoole is required.',
            'PHP Extensions'
        );

        // ext_rrd is only required when the RRD datasource is active.
        $rrdOk = \function_exists('rrd_version');
        $add(
            'ext_rrd',
            'PHP ext-rrd',
            $rrdOk ? 'ok' : ($datasource === 'rrd' ? 'error' : 'warning'),
            $rrdOk ? 'Loaded (' . rrd_version() . ')'
                   : ($datasource === 'rrd' ? 'Not loaded. Install php-rrd.'
                                           : 'Not loaded (not required for ' . $settings->datasourceName . ')'),
            'PHP Extensions'
        );

        $inotifyOk = \function_exists('inotify_init');
        $add(
            'ext_inotify',
            'PHP ext-inotify',
            $inotifyOk ? 'ok' : ($daemonDisabled ? 'warning' : 'error'),
            $inotifyOk ? 'Loaded' : 'Not loaded. Install php-inotify.',
            'PHP Extensions'
        );

        // SQLite holds the top-N store, saved filters and alert events; without it those degrade.
        $sqliteVer = ProcessInfo::sqliteVersion();
        $add(
            'ext_pdo_sqlite',
            'PHP ext-pdo_sqlite',
            $sqliteVer !== '' ? 'ok' : 'error',
            $sqliteVer !== '' ? "Loaded (SQLite {$sqliteVer})" : 'Not loaded. Install php-sqlite3 (pdo_sqlite).',
            'PHP Extensions',
            false,
            $sqliteVer !== '' ? '' : 'Top-N lists, saved filters and alert history are unavailable without it.'
        );

        // ── 2. Timezone ──────────────────────────────────────────────────────────

        $phpTz = date_default_timezone_get();
        $iniTz = (string) \ini_get('date.timezone');
        $envTz = (string) (getenv('TZ') ?: '');

        // Detect non-IANA abbreviations (e.g. CET, EST): browsers reject them in Intl APIs
        $isNonIana = $iniTz === '' && !str_contains($phpTz, '/')
            && !\in_array($phpTz, ['UTC', 'GMT'], true);

        $tzStatus = 'ok';
        $tzDetail = $phpTz;
        $tzHint = '';

        if ($iniTz !== '' && $envTz !== '' && strcasecmp($iniTz, $envTz) !== 0) {
            $tzStatus = 'warning';
            $tzDetail = $phpTz;
            $tzHint = "php.ini date.timezone='{$iniTz}' overrides TZ env var '{$envTz}'. Set NFCAPD_TZ explicitly if nfcapd uses a different timezone.";
        } elseif ($isNonIana) {
            $tzStatus = 'warning';
            $tzHint = "'{$phpTz}' is not an IANA timezone identifier, so browser Intl APIs may reject it for date display. Use a region/city form (e.g. Europe/London).";
        }

        $add('tz_php', 'PHP timezone', $tzStatus, $tzDetail, 'Timezone', false, $tzHint);

        // nfcapd timezone
        $nfcapdTzEnv = (string) (getenv('NFCAPD_TZ') ?: '');
        if ($nfcapdTzEnv !== '') {
            try {
                $nfcapdTz = new \DateTimeZone($nfcapdTzEnv);
                $add('tz_nfcapd', 'nfcapd timezone', 'ok', $nfcapdTz->getName(), 'Timezone', false, 'Explicit override active via NFCAPD_TZ');
            } catch (\Exception) {
                $add('tz_nfcapd', 'nfcapd timezone', 'error', $nfcapdTzEnv, 'Timezone', true, 'NFCAPD_TZ is not a valid timezone identifier. nfcapd file names are parsed in the PHP default timezone instead.');
            }
        } else {
            $add('tz_nfcapd', 'nfcapd timezone', 'ok', $phpTz, 'Timezone', false, 'Inherited from the PHP timezone. Set NFCAPD_TZ if nfcapd runs in a different timezone.');
        }

        // nfcapd file time plausibility: the newest file name must match its write time (a
        // file is written when its 5 minute interval ends) and must not lie in the future.
        $plausibilityDone = false;
        if (rtrim($settings->nfdumpProfilesData, '/\\') !== '' && !empty($settings->sources)) {
            foreach (Config::detectProfiles() as $profileP) {
                foreach ($settings->sources as $sourceP) {
                    // Today and yesterday in the nfcapd timezone
                    $newestP = $newestOf($profileP, $sourceP);
                    if ($newestP === null) {
                        continue;
                    }

                    $now = time();
                    $plausibilityDone = true;
                    $written = @filemtime($newestP['path']);
                    $skew = $written !== false ? $written - ($newestP['ts'] + 300) : 0;
                    if (abs($skew) > 1800) {
                        $hours = round(abs($skew) / 3600, 1);
                        $add(
                            'tz_plausibility',
                            'nfcapd file time',
                            'warning',
                            "File names are {$hours} h " . ($skew > 0 ? 'behind' : 'ahead of') . " their write time ({$newestP['name']})",
                            'Timezone',
                            false,
                            'Check NFCAPD_TZ: nfcapd file names may be in a different timezone than configured.'
                        );
                    } elseif ($newestP['ts'] > $now + 1800) {
                        $minutesAhead = (int) round(($newestP['ts'] - $now) / 60);
                        $add(
                            'tz_plausibility',
                            'nfcapd file time',
                            'warning',
                            "Most recent file ({$newestP['name']}) is {$minutesAhead} min in the future",
                            'Timezone',
                            false,
                            'Check NFCAPD_TZ: nfcapd file names may be in a different timezone than configured.'
                        );
                    } else {
                        $fileAge = $ageStr($now - $newestP['ts']);
                        $add('tz_plausibility', 'nfcapd file time', 'ok', "Most recent: {$newestP['name']} ({$fileAge} ago)", 'Timezone');
                    }

                    break 2;
                }
            }
        }

        if (!$plausibilityDone) {
            // The freshness checks under nfcapd Paths report the missing files.
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
            // Cached per binary in Nfdump, which needs the same probe for its own
            // version-dependent option handling (see Nfdump::needsAggregatedCsv()).
            $nfdumpVer = Nfdump::versionString($binary);
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

            $numericVer = Nfdump::parseVersion($nfdumpVer);
            if ($numericVer !== '') {
                $version = self::nfdumpVersionCheck($numericVer);
                $add('nfdump_version', 'Minimum version', $version['status'], $version['detail'], 'nfdump', false, $version['hint']);
            }
        }

        foreach (self::processBudgetChecks(HealthMetrics::processBudget(), HealthMetrics::activeQueries()) as $check) {
            $checks[] = $check;
        }

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
            // The MCP status tool passes true because it does not see the daemons.
            $skipped = (bool) EnvRegistry::value('NFSEN_SKIP_DAEMON');
            $add(
                'daemon_status',
                'Daemon status',
                'warning',
                $skipped ? 'Disabled' : 'Not visible to the status tool',
                'Import Daemon',
                false,
                $skipped
                    ? 'NFSEN_SKIP_DAEMON is set, so no capture files are imported and alerts are not evaluated. Unset it and restart nfsen-ng.'
                    : 'The Health page shows the state of the import daemon.'
            );
        } elseif (empty($daemonsInfo)) {
            $add(
                'daemon_status',
                'Daemon status',
                'error',
                'Not running. Restart required.',
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
                            'nfcapd files in a flat structure. Run reorganize_nfcapd.sh and configure nfcapd with -S 1.',
                            'nfcapd Paths'
                        );

                        continue;
                    }

                    // Capture freshness: the newest rotated file of today or yesterday in the nfcapd
                    // timezone, so the minutes after local midnight still find the last rotation.
                    // An nfcapd.current.* file is rewritten continuously and says nothing about rotation.
                    $todayDt = new \DateTimeImmutable('now', Config::nfcapdTimezone());
                    $today = $todayDt->format('Y') . \DIRECTORY_SEPARATOR . $todayDt->format('m') . \DIRECTORY_SEPARATOR . $todayDt->format('d');
                    $freshnessLabel = $multiProfile ? "Freshness {$source} ({$profile})" : "Capture freshness: {$source}";
                    $newest = $newestOf($profile, $source);
                    // filemtime() is false when nfcapd rotated the file away between scan and stat.
                    $newestMtime = $newest === null ? false : @filemtime($newest['path']);
                    if ($newestMtime === false) {
                        $add(
                            "capture_fresh_{$sourceIdPfx}",
                            $freshnessLabel,
                            'warning',
                            is_dir($sourcePath . \DIRECTORY_SEPARATOR . $today)
                                ? 'No nfcapd files since yesterday'
                                : "No data dir for today ({$today})",
                            'nfcapd Paths'
                        );
                    } else {
                        $age = time() - $newestMtime;
                        // nfcapd default rotation is 5 min; warn after 12 min (2.4×) to allow for slow systems
                        $status = $age > HealthMetrics::STALE_AFTER ? 'warning' : 'ok';
                        if ($age <= 0) {
                            $detail = 'Just captured';
                        } elseif ($age > HealthMetrics::STALE_AFTER) {
                            $detail = 'Last file ' . $ageStr($age) . ' ago. nfcapd may have stopped.';
                        } else {
                            $detail = 'Last file ' . $ageStr($age) . ' ago';
                        }
                        $add("capture_fresh_{$sourceIdPfx}", $freshnessLabel, $status, $detail, 'nfcapd Paths', false, '', $newestMtime);
                    }
                }
            }
        }

        // ── 7. Storage ───────────────────────────────────────────────────────
        // Delegated to the active datasource implementation: each knows its
        // own connectivity checks, file paths, and per-source freshness logic.
        if (isset(Config::$db)) {
            foreach (Config::$db->healthChecks($storageGroup, $sources) as $check) {
                $check['detail'] = self::plainText($check['detail']);
                $check['hint'] = self::plainText($check['hint']);
                $checks[] = $check;
            }
        }

        // Read through Database::inspect() only: never creates, migrates or throws, so the
        // MCP process works even where it cannot write the state directory.
        $storePath = isset(Config::$stateDir) && Config::$stateDir !== ''
            ? rtrim(Config::$stateDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . Database::FILENAME
            : '';
        array_push($checks, ...self::storeChecks($storePath, $storePath === '' ? null : ($storeFacts ?? Database::inspect($storePath)), ProcessInfo::sqliteVersion()));
        array_push($checks, ...self::diskChecks(HealthMetrics::disks()));

        // ── 8. Configuration (environment variables) ─────────────────────────
        // Config source: the env-var baseline vs the deprecated settings.php file.
        if (Config::$settingsFileLoaded !== null) {
            $add(
                'config_source',
                'Config source',
                'warning',
                'settings.php (deprecated)',
                'Configuration',
                false,
                'File-based config is deprecated. Migrate to environment variables (NFSEN_*), see the Configuration docs.'
            );
        } else {
            $add('config_source', 'Config source', 'ok', 'Environment variables', 'Configuration');
        }

        // Registry-detected problems: values that were set but invalid (so the
        // default silently applied), deprecated aliases still in use, and unknown
        // NFSEN_-prefixed variables (typos that have no effect).
        $envIssues = EnvRegistry::issues();
        foreach ($envIssues as $i => $issue) {
            $add('env_issue_' . $i, $issue['name'], $issue['level'], $issue['message'], 'Configuration');
        }
        if ($envIssues === []) {
            $add('env_vars_ok', 'Environment variables', 'ok', 'All recognised and valid', 'Configuration');
        }

        // Soft format checks the registry defers to the health page. Path
        // existence is already covered by the dedicated nfdump/nfcapd checks, so
        // only url/email, which need no filesystem access, are validated here.
        foreach (EnvRegistry::table() as $var) {
            if ($var->format === null || !EnvRegistry::isSet($var->name)) {
                continue;
            }
            $val = (string) EnvRegistry::value($var->name);
            $bad = match ($var->format) {
                'url' => filter_var($val, FILTER_VALIDATE_URL) === false,
                'email' => filter_var($val, FILTER_VALIDATE_EMAIL) === false,
                default => false,
            };
            if ($bad) {
                $add(
                    'env_format_' . strtolower($var->name),
                    $var->name,
                    'warning',
                    $var->display($val) . " is not a valid {$var->format}",
                    'Configuration'
                );
            }
        }

        // The geolocation URL and its API key are only useful together: a {token}
        // placeholder with nothing to put in it sends an empty key, and a key with
        // no placeholder to land in is never sent at all. Both fail as a plain
        // "lookup didn't work", so name them here instead.
        $geoUrl = (string) EnvRegistry::value('NFSEN_IPINFO_URL');
        $geoToken = (string) EnvRegistry::value('NFSEN_IPINFO_TOKEN');
        if (str_contains($geoUrl, '{token}') && $geoToken === '') {
            $add(
                'env_ipinfo_token_missing',
                'NFSEN_IPINFO_TOKEN',
                'warning',
                'NFSEN_IPINFO_URL contains {token} but NFSEN_IPINFO_TOKEN is empty, so the lookup sends an empty key',
                'Configuration'
            );
        } elseif ($geoToken !== '' && !str_contains($geoUrl, '{token}')) {
            $add(
                'env_ipinfo_token_unused',
                'NFSEN_IPINFO_TOKEN',
                'warning',
                'NFSEN_IPINFO_TOKEN is set but NFSEN_IPINFO_URL has no {token} placeholder, so the key is never sent',
                'Configuration'
            );
        }

        // Log level is the one field that both an env var (NFSEN_LOG_LEVEL) and a
        // saved preference (preferences.json → logPriority) control. The preference
        // is overlaid last and wins, so flag when it silently overrides the env var.
        if (EnvRegistry::isSet('NFSEN_LOG_LEVEL') && Config::$preferencesFileLoaded) {
            $envLevel = Settings::logLevelFromString((string) EnvRegistry::value('NFSEN_LOG_LEVEL'));
            if ($settings->logPriority !== $envLevel) {
                $add(
                    'env_pref_loglevel',
                    'NFSEN_LOG_LEVEL',
                    'warning',
                    'Overridden by a saved log-level preference (' . Settings::logLevelToString($settings->logPriority) . ')',
                    'Configuration',
                    false,
                    'A saved log level preference wins over NFSEN_LOG_LEVEL. Change it in Settings (General) to match the env var.'
                );
            }
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

    /**
     * The "Minimum version" row: an error below NFDUMP_MIN, a warning below NFDUMP_RECOMMENDED.
     *
     * @return array{status: 'error'|'ok'|'warning', detail: string, hint: string}
     */
    public static function nfdumpVersionCheck(string $version): array {
        if (version_compare($version, self::NFDUMP_MIN, '<')) {
            return [
                'status' => 'error',
                'detail' => 'v' . $version . '. nfsen-ng requires nfdump ' . self::NFDUMP_MIN . ' or later.',
                'hint' => 'JSON output field names differ in 1.6.x. Upgrade to nfdump 1.7.10.',
            ];
        }
        if (version_compare($version, self::NFDUMP_RECOMMENDED, '<')) {
            return [
                'status' => 'warning',
                'detail' => 'v' . $version . '. nfdump ' . self::NFDUMP_RECOMMENDED . ' or later is recommended.',
                'hint' => 'nfdump 1.7.9 fixes security issues in the NetFlow v9, IPFIX and sFlow collectors and in reading'
                    . ' malformed capture files (out-of-bounds reads, a use-after-free, integer overflows). Upgrade to nfdump 1.7.10.',
            ];
        }

        return ['status' => 'ok', 'detail' => self::NFDUMP_RECOMMENDED . ' or later', 'hint' => ''];
    }

    /**
     * The process budget rows of the nfdump group: detected cores and where they came from, the
     * process limit, -W, and the slots in use by class. Slots are counted by NfdumpSlots, which
     * counts the processes this app started rather than every nfdump on the machine.
     *
     * @param ProcessBudget $budget
     * @param ActiveQueries $active
     *
     * @return list<HealthCheck>
     */
    public static function processBudgetChecks(array $budget, array $active): array {
        $row = static fn (string $id, string $label, string $detail, string $hint = ''): array => [
            'id' => $id, 'label' => $label, 'status' => 'ok', 'detail' => $detail, 'group' => 'nfdump', 'code' => false, 'hint' => $hint, 'epoch' => 0,
        ];

        $cores = $budget['cores'] . ' ' . ($budget['cores'] === 1 ? 'core' : 'cores') . ', from ' . $budget['coresFrom'] . ' (' . $budget['coresDetail'] . ')';
        $processes = $budget['auto']
            ? $budget['processes'] . ', auto: a third of ' . $budget['cores'] . ' cores, between ' . CpuBudget::AUTO_MIN . ' and ' . CpuBudget::AUTO_MAX
            : $budget['processes'] . ', set by NFSEN_NFDUMP_MAX_PROCESSES or settings.php';

        return [
            $row('nfdump_cpu_cores', 'CPU cores', $cores),
            $row('nfdump_max_processes', 'Parallel processes', $processes, 'Each nfdump process uses about 2 to 3 CPU cores.'),
            $row('nfdump_workers', 'Filter threads', HealthMetrics::workersText($budget)),
            self::slotsCheck($active),
        ];
    }

    /**
     * The "Slots in use" row. The checks are cached for up to IDLE_TTL, so a page shows it
     * through withLiveSlots() rather than as cached.
     *
     * @param ActiveQueries $active
     *
     * @return HealthCheck
     */
    public static function slotsCheck(array $active): array {
        return [
            'id' => 'nfdump_slots',
            'label' => 'Slots in use',
            'status' => 'ok',
            'detail' => $active['inUse'] . ' of ' . $active['max'] . ': ' . HealthMetrics::slotSplit($active),
            'group' => 'nfdump',
            'code' => false,
            'hint' => $active['max'] >= 2
                ? 'Background work (import, top-N, alerts) holds at most ' . $active['backgroundMax'] . ' and leaves a slot free for user queries.'
                : 'With one slot, background work (import, top-N, alerts) starts only while no user query runs or waits.',
            'epoch' => 0,
        ];
    }

    /**
     * The checks with the "Slots in use" row recounted from $active.
     *
     * @param list<HealthCheck> $checks
     * @param ActiveQueries     $active
     *
     * @return list<HealthCheck>
     */
    public static function withLiveSlots(array $checks, array $active): array {
        return array_map(
            static fn (array $check): array => $check['id'] === 'nfdump_slots' ? self::slotsCheck($active) : $check,
            $checks,
        );
    }

    /**
     * The "Storage (SQLite)" group from the facts of Database::inspect(); $facts is null when
     * the state directory is not configured.
     *
     * @param null|StoreFacts $facts
     *
     * @return list<HealthCheck>
     */
    public static function storeChecks(string $path, ?array $facts, string $sqliteVersion): array {
        /** @var list<HealthCheck> $checks */
        $checks = [];

        /** @param 'error'|'ok'|'warning' $status */
        $add = static function (string $id, string $label, string $status, string $detail, bool $code = false, string $hint = '') use (&$checks): void {
            /** @var list<HealthCheck> $checks */
            $checks[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail,
                'group' => self::STORE_GROUP, 'code' => $code, 'hint' => $hint, 'epoch' => 0];
        };
        $needs = 'Top-N lists, saved filters and alert history are kept there.';

        if ($facts === null) {
            $add('sqlite_file', 'Database file', 'error', 'The state directory is not configured', false, 'Set NFSEN_STATE_DIR. ' . $needs);
        } elseif ($facts['exists']) {
            $add('sqlite_file', 'Database file', 'ok', $path, true);
            $add(
                'sqlite_writable',
                'Writable',
                $facts['writable'] ? 'ok' : 'error',
                $facts['writable'] ? 'Yes' : 'No, the file or its directory is read-only for this user',
                false,
                $facts['writable'] ? '' : 'SQLite also writes its journal next to the file. ' . $needs
            );
        } elseif ($facts['writable']) {
            $add('sqlite_file', 'Database file', 'warning', 'Not created yet: ' . $path, false, 'The server creates it when it starts.');
        } else {
            $add('sqlite_file', 'Database file', 'error', 'Cannot be created: ' . $path, false, 'Make NFSEN_STATE_DIR writable for the user nfsen-ng runs as. ' . $needs);
        }

        if ($facts !== null && $facts['journalMode'] !== '') {
            $mode = strtolower($facts['journalMode']);
            $add(
                'sqlite_journal',
                'Journal mode',
                'ok',
                self::journalLabel($mode),
                false,
                $mode === 'delete' ? 'The filesystem refused WAL, which is common on FUSE mounts such as Unraid appdata. Everything works; reads wait while a write runs.' : ''
            );
        }

        if ($facts !== null && $facts['exists']) {
            $version = $facts['schemaVersion'];
            $latest = Migrator::latestVersion();
            $add(
                'sqlite_schema',
                'Schema version',
                $version === $latest ? 'ok' : 'warning',
                match (true) {
                    $version === $latest => (string) $version,
                    $version > $latest => "{$version}, newer than this nfsen-ng knows ({$latest})",
                    default => "{$version}, expected {$latest}",
                },
                false,
                match (true) {
                    $version === $latest => '',
                    $version > $latest => 'The store is opened read-only, so nothing is lost. Update nfsen-ng or restore a matching backup.',
                    default => 'The server migrates it when it starts.',
                }
            );
            $add('sqlite_size', 'Size', 'ok', Estimate::humanBytes($facts['sizeBytes']));
        }

        if ($sqliteVersion === '') {
            $add('sqlite_version', 'SQLite library', 'error', 'Not available, pdo_sqlite is not loaded');
        } else {
            $recent = version_compare($sqliteVersion, self::SQLITE_MIN, '>=');
            $add(
                'sqlite_version',
                'SQLite library',
                $recent ? 'ok' : 'error',
                $recent ? $sqliteVersion : "{$sqliteVersion}. SQLite 3.33 or later is required.",
                false,
                $recent ? '' : 'The top-N rollups use UPDATE ... FROM, which older SQLite versions reject.'
            );
        }

        if ($facts !== null && $facts['error'] !== '') {
            $add('sqlite_error', 'Store', 'error', $facts['error']);
        }

        return $checks;
    }

    /**
     * The "Disk space" group: one row per measured filesystem of HealthMetrics::disks(). A
     * missing directory has its own row elsewhere and VictoriaMetrics data is not measured here.
     *
     * @param list<DiskUsage> $disks
     *
     * @return list<HealthCheck>
     */
    public static function diskChecks(array $disks): array {
        $checks = [];
        foreach ($disks as $disk) {
            if ($disk['usedPct'] === null || $disk['free'] === null || $disk['total'] === null) {
                continue;
            }
            $level = self::diskLevel($disk['usedPct']);
            $checks[] = [
                'id' => 'disk_' . strtolower((string) preg_replace('/\W+/', '_', $disk['label'])),
                'label' => 'Disk space: ' . $disk['label'],
                'status' => $level === '' ? 'ok' : $level,
                'detail' => round($disk['usedPct']) . '% used, ' . Estimate::humanBytes($disk['free']) . ' free of ' . Estimate::humanBytes($disk['total']),
                'group' => self::DISK_GROUP,
                'code' => false,
                'hint' => match ($level) {
                    'error' => "Free space under {$disk['path']} now: nfcapd, the import and the SQLite store fail once it is full.",
                    'warning' => "Free space under {$disk['path']} or grow the filesystem before it fills up.",
                    default => '',
                },
                'epoch' => 0,
            ];
        }

        return $checks;
    }

    /**
     * The level of a filesystem's usage: '' below DISK_WARNING or when it was not measured.
     *
     * @return ''|'error'|'warning'
     */
    public static function diskLevel(?float $usedPct): string {
        return match (true) {
            $usedPct === null => '',
            $usedPct >= self::DISK_ERROR => 'error',
            $usedPct >= self::DISK_WARNING => 'warning',
            default => '',
        };
    }

    /** An SQLite journal mode as the Health page names it; '' stays ''. */
    public static function journalLabel(string $mode): string {
        return match (strtolower($mode)) {
            'wal' => 'WAL',
            'delete' => 'Rollback journal (DELETE)',
            default => strtolower($mode),
        };
    }

    /**
     * Datasource rows may carry markup (the VictoriaMetrics UI link): a link becomes its
     * address, every other tag goes.
     */
    public static function plainText(string $html): string {
        if (!str_contains($html, '<') && !str_contains($html, '&')) {
            return $html;
        }

        $text = preg_replace('~<a\b[^>]*\bhref\s*=\s*"([^"]*)"[^>]*>.*?</a>~is', '$1', $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
