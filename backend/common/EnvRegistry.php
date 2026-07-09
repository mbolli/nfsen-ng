<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * The single source of truth for every environment variable nfsen-ng reads.
 *
 * Each variable is one {@see EnvVar} record describing its type, default,
 * validation, deprecation alias, and documentation. All consumers —
 * {@see Settings}, the bootstrap in app.php, {@see Config}, {@see AppStartup} —
 * resolve values through {@see value()} instead of calling getenv() directly,
 * which removes the duplicate reads and the per-code-path default drift that
 * accumulated while these vars were scattered across the codebase.
 *
 * {@see issues()} powers the health page's configuration checks: it reports
 * invalid values (that silently fell back to a default), deprecated aliases in
 * use, and unknown NFSEN_-prefixed variables (likely typos that do nothing).
 *
 * @phpstan-type EnvIssue array{name: string, level: 'warning', message: string}
 */
final class EnvRegistry {
    /** @var null|array<string, EnvVar> memoized name → record table */
    private static ?array $table = null;

    /** @return array<string, EnvVar> canonical name → record */
    public static function table(): array {
        if (self::$table !== null) {
            return self::$table;
        }

        $vars = [
            // ── Core / general ────────────────────────────────────────────────
            new EnvVar('NFSEN_LOG_LEVEL', 'core', 'enum', 'info', 'Minimum log level.', enum: Settings::logLevelNames()),
            new EnvVar('NFSEN_DEFAULT_THEME', 'core', 'enum', 'auto', 'Default UI theme for browsers with no saved toggle.', enum: ['auto', 'dark', 'light']),
            new EnvVar('NFSEN_MAX_STATS_WINDOW', 'core', 'int', 0, 'Max statistics time window in seconds (0 = unlimited).', min: 0),
            new EnvVar('NFSEN_DEV_MODE', 'core', 'bool', false, 'Development mode: verbose errors, relaxed origin checks.'),

            // ── Sources / data selection ──────────────────────────────────────
            new EnvVar('NFSEN_SOURCES', 'sources', 'csv', [], 'NetFlow source names, comma-separated (e.g. "gw1,gw2").'),
            new EnvVar('NFSEN_PORTS', 'sources', 'csv_int', [], 'Port numbers to track, comma-separated (e.g. "80,443,22").'),
            new EnvVar('NFSEN_FILTERS', 'sources', 'json_array', [], 'nfdump filter expressions as a JSON array.'),

            // ── Datasource ────────────────────────────────────────────────────
            new EnvVar('NFSEN_DATASOURCE', 'datasource', 'enum', 'RRD', 'Time-series backend.', enum: Settings::datasourceNames()),
            new EnvVar('NFSEN_PROCESSOR', 'datasource', 'enum', 'NfDump', 'Flow processor implementation.', enum: Settings::processorNames()),
            new EnvVar('NFSEN_IMPORT_YEARS', 'datasource', 'int', 3, 'Years of history to import/retain.', min: 1),
            new EnvVar('NFSEN_RRD_PATH', 'datasource', 'string', '', 'RRD data directory (empty = built-in default).', format: 'path'),
            new EnvVar('NFSEN_VM_HOST', 'datasource', 'string', 'victoriametrics', 'VictoriaMetrics host.', alias: 'VM_HOST'),
            new EnvVar('NFSEN_VM_PORT', 'datasource', 'int', 8428, 'VictoriaMetrics port.', min: 1, alias: 'VM_PORT'),

            // ── nfdump ────────────────────────────────────────────────────────
            new EnvVar('NFSEN_NFDUMP_BINARY', 'nfdump', 'string', '/usr/local/nfdump/bin/nfdump', 'Path to the nfdump binary.', format: 'path'),
            new EnvVar('NFSEN_NFDUMP_PROFILES', 'nfdump', 'string', '/var/nfdump/profiles-data', 'nfdump profiles-data directory.', format: 'path'),
            new EnvVar('NFSEN_NFDUMP_PROFILE', 'nfdump', 'string', 'live', 'nfdump profile name.'),
            new EnvVar('NFSEN_NFDUMP_MAX_PROCESSES', 'nfdump', 'int', 1, 'Max concurrent nfdump processes.', min: 1),

            // ── Integrations ──────────────────────────────────────────────────
            new EnvVar('NFSEN_NETBOX_URL', 'integrations', 'string', '', 'NetBox base URL for IP enrichment.', format: 'url'),
            new EnvVar('NFSEN_NETBOX_TOKEN', 'integrations', 'string', '', 'NetBox API token.', secret: true),
            new EnvVar('NFSEN_ALERT_EMAIL_FROM', 'integrations', 'string', '', 'From address for alert emails.', format: 'email'),

            // ── Import daemon ─────────────────────────────────────────────────
            new EnvVar('NFSEN_SKIP_DAEMON', 'daemon', 'bool', false, 'Disable the embedded import daemon.'),
            new EnvVar('NFSEN_SKIP_INITIAL_IMPORT', 'daemon', 'bool', false, 'Skip startup catch-up import; only set up inotify watches.'),

            // ── State & config file paths ─────────────────────────────────────
            new EnvVar('NFSEN_STATE_DIR', 'files', 'string', '', 'Directory for mutable runtime state (preferences.json + alert rules/state/log). Empty = backend/settings. Mount a volume here in Docker so it survives upgrades.', format: 'path'),
            new EnvVar('NFSEN_SETTINGS_FILE', 'files', 'string', '', 'Override path to settings.php (deprecated file-based config).', format: 'path'),
            new EnvVar('NFSEN_PREFERENCES_FILE', 'files', 'string', '', 'Override path to preferences.json (default: <state dir>/preferences.json).', format: 'path'),

            // ── OpenSwoole runtime (library-native names, kept un-prefixed) ────
            new EnvVar('SWOOLE_WORKER_NUM', 'runtime', 'int', 1, 'OpenSwoole worker processes.', min: 1),
            new EnvVar('SWOOLE_MAX_REQUEST', 'runtime', 'int', 0, 'Requests per worker before recycle (0 = unlimited).', min: 0),
            new EnvVar('SWOOLE_MAX_COROUTINE', 'runtime', 'int', 10000, 'Max concurrent coroutines.', min: 1),

            // ── Timezone (OS / sibling-container conventions) ─────────────────
            new EnvVar('TZ', 'timezone', 'string', '', 'Container timezone.'),
            new EnvVar('NFCAPD_TZ', 'timezone', 'string', '', 'Timezone of the nfcapd capture container (health cross-check).'),
        ];

        $table = [];
        foreach ($vars as $var) {
            $table[$var->name] = $var;
        }

        return self::$table = $table;
    }

    /**
     * Typed, validated, defaulted value for a known variable.
     *
     * @throws \InvalidArgumentException if $name is not a registered variable (a programming error)
     */
    public static function value(string $name): mixed {
        $var = self::table()[$name] ?? throw new \InvalidArgumentException("Unknown env var '{$name}' — not in EnvRegistry.");

        return $var->parse(self::raw($var)[0]);
    }

    /**
     * Whether the variable (or its deprecated alias) is set to a non-empty value.
     * Lets callers distinguish "explicitly configured" from "using the default"
     * — e.g. to let an env override win over a settings.php value.
     *
     * @throws \InvalidArgumentException if $name is not a registered variable (a programming error)
     */
    public static function isSet(string $name): bool {
        $var = self::table()[$name] ?? throw new \InvalidArgumentException("Unknown env var '{$name}' — not in EnvRegistry.");

        return self::raw($var)[0] !== null;
    }

    /** @return list<string> canonical variable names */
    public static function names(): array {
        return array_keys(self::table());
    }

    /** @return array<string, string> deprecated alias → canonical name */
    public static function aliasMap(): array {
        $map = [];
        foreach (self::table() as $var) {
            if ($var->alias !== null) {
                $map[$var->alias] = $var->name;
            }
        }

        return $map;
    }

    /**
     * Configuration problems to surface on the health page: values that were
     * set but invalid (and thus ignored), deprecated aliases still in use, and
     * unknown NFSEN_-prefixed variables that silently do nothing.
     *
     * @return list<array{name: string, level: 'warning', message: string}>
     */
    public static function issues(): array {
        $issues = [];

        foreach (self::table() as $var) {
            [$raw, $source] = self::raw($var);

            if ($source === 'alias') {
                $issues[] = [
                    'name' => $var->alias ?? '',
                    'level' => 'warning',
                    'message' => "{$var->alias} is deprecated — rename it to {$var->name} (still honoured for now).",
                ];
            }

            $error = $var->validationError($raw);
            if ($error !== null) {
                $issues[] = ['name' => $var->name, 'level' => 'warning', 'message' => "{$var->name}: {$error}"];
            }
        }

        // Typo detection: only for the NFSEN_ namespace, which the app owns.
        // SWOOLE_*, TZ, and other shared-namespace vars are left alone.
        $known = array_flip(self::names()) + array_flip(array_keys(self::aliasMap()));
        foreach (self::environment() as $key => $_value) {
            if (str_starts_with($key, 'NFSEN_') && !isset($known[$key])) {
                $issues[] = [
                    'name' => $key,
                    'level' => 'warning',
                    'message' => "{$key} is not a recognised nfsen-ng variable — possible typo; it has no effect.",
                ];
            }
        }

        return $issues;
    }

    /**
     * Resolve the raw env string for a variable, honouring its deprecated alias.
     *
     * @return array{0: ?string, 1: null|'alias'|'name'} the value (null if unset/empty) and where it came from
     */
    private static function raw(EnvVar $var): array {
        $value = getenv($var->name);
        if ($value !== false && $value !== '') {
            return [$value, 'name'];
        }
        if ($var->alias !== null) {
            $aliasValue = getenv($var->alias);
            if ($aliasValue !== false && $aliasValue !== '') {
                return [$aliasValue, 'alias'];
            }
        }

        return [null, null];
    }

    /** @return array<string, string> the full process environment (seam for testing/typo scan) */
    private static function environment(): array {
        $env = getenv();

        return \is_array($env) ? $env : [];
    }
}
