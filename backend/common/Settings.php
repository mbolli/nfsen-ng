<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Typed, immutable settings value object for nfsen-ng.
 *
 * Wraps the raw `$nfsen_config` array and exposes well-typed accessors.
 * All values fall back to safe defaults so callers never receive null
 * when a key is missing from an older settings file.
 *
 * Usage:
 *   $s = Settings::fromArray(Config::$cfg);
 *   $s->sources()        // string[]
 *   $s->nfdumpBinary()   // string
 *   $s->logPriority()    // int (LOG_* constant)
 */
final class Settings {
    // ─── Log-level name → PHP LOG_* constant map ─────────────────────────────
    private const LOG_LEVEL_MAP = [
        'LOG_EMERG'   => \LOG_EMERG,
        'LOG_ALERT'   => \LOG_ALERT,
        'LOG_CRIT'    => \LOG_CRIT,
        'LOG_ERR'     => \LOG_ERR,
        'LOG_WARNING' => \LOG_WARNING,
        'LOG_NOTICE'  => \LOG_NOTICE,
        'LOG_INFO'    => \LOG_INFO,
        'LOG_DEBUG'   => \LOG_DEBUG,
        'EMERG'       => \LOG_EMERG,
        'ALERT'       => \LOG_ALERT,
        'CRIT'        => \LOG_CRIT,
        'ERR'         => \LOG_ERR,
        'ERROR'       => \LOG_ERR,
        'WARNING'     => \LOG_WARNING,
        'NOTICE'      => \LOG_NOTICE,
        'INFO'        => \LOG_INFO,
        'DEBUG'       => \LOG_DEBUG,
    ];

    // ─── Datasource class name map ────────────────────────────────────────────
    private const DATASOURCE_MAP = [
        'rrd'             => 'mbolli\\nfsen_ng\\datasources\\Rrd',
        'akumuli'         => 'mbolli\\nfsen_ng\\datasources\\Akumuli',
        'victoriametrics' => 'mbolli\\nfsen_ng\\datasources\\VictoriaMetrics',
    ];

    // ─── Processor class name map ─────────────────────────────────────────────
    private const PROCESSOR_MAP = [
        'nfdump' => 'mbolli\\nfsen_ng\\processor\\Nfdump',
    ];

    private function __construct(
        /** @var array<string, mixed> */
        private readonly array $raw,
    ) {}

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Build from the raw `$nfsen_config` array (typically Config::$cfg).
     */
    public static function fromArray(array $raw): self {
        return new self($raw);
    }

    /**
     * Build from environment variables only (useful for testing / override).
     * Returns a minimal Settings instance backed by env vars and defaults.
     */
    public static function fromEnv(): self {
        return new self([
            'general' => [
                'db'        => getenv('NFSEN_DATASOURCE') ?: 'RRD',
                'processor' => getenv('NFSEN_PROCESSOR') ?: 'NfDump',
                'sources'   => [],
                'ports'     => [],
                'filters'   => [],
            ],
            'nfdump' => [
                'binary'        => getenv('NFSEN_NFDUMP_BINARY') ?: '/usr/bin/nfdump',
                'profiles-data' => getenv('NFSEN_NFDUMP_PROFILES') ?: '/var/nfdump/profiles-data',
                'profile'       => getenv('NFSEN_NFDUMP_PROFILE') ?: 'live',
                'max-processes' => (int) (getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 1),
            ],
            'log' => [
                'priority' => \LOG_INFO,
            ],
        ]);
    }

    // ── General ───────────────────────────────────────────────────────────────

    /** @return string[] Configured NetFlow source names */
    public function sources(): array {
        return (array) ($this->raw['general']['sources'] ?? []);
    }

    /** @return int[] Configured port numbers */
    public function ports(): array {
        return array_map('intval', (array) ($this->raw['general']['ports'] ?? []));
    }

    /** @return string[] Saved nfdump filter expressions */
    public function filters(): array {
        return (array) ($this->raw['general']['filters'] ?? []);
    }

    /** Datasource short name (e.g. "RRD", "VictoriaMetrics") */
    public function datasourceName(): string {
        return (string) ($this->raw['general']['db'] ?? 'RRD');
    }

    /**
     * Fully qualified datasource class name.
     *
     * @throws \InvalidArgumentException if the datasource is not recognised
     */
    public function datasourceClass(): string {
        $key = strtolower($this->datasourceName());
        $class = self::DATASOURCE_MAP[$key] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException("Unknown datasource '{$key}'. Known: " . implode(', ', array_keys(self::DATASOURCE_MAP)));
        }

        return $class;
    }

    /** Processor short name (e.g. "NfDump") */
    public function processorName(): string {
        return (string) ($this->raw['general']['processor'] ?? 'NfDump');
    }

    /**
     * Fully qualified processor class name.
     *
     * @throws \InvalidArgumentException if the processor is not recognised
     */
    public function processorClass(): string {
        $key = strtolower($this->processorName());
        $class = self::PROCESSOR_MAP[$key] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException("Unknown processor '{$key}'. Known: " . implode(', ', array_keys(self::PROCESSOR_MAP)));
        }

        return $class;
    }

    // ── Frontend defaults ─────────────────────────────────────────────────────

    /** Default view tab (graphs | flows | statistics) */
    public function defaultView(): string {
        return (string) ($this->raw['frontend']['defaults']['view'] ?? 'graphs');
    }

    /** Default graph display mode (sources | protocols | ports) */
    public function defaultGraphDisplay(): string {
        return (string) ($this->raw['frontend']['defaults']['graphs']['display'] ?? 'sources');
    }

    /** Default graph data type (traffic | packets | flows) */
    public function defaultGraphDatatype(): string {
        return (string) ($this->raw['frontend']['defaults']['graphs']['datatype'] ?? 'traffic');
    }

    /** @return string[] Default graph protocols */
    public function defaultGraphProtocols(): array {
        return (array) ($this->raw['frontend']['defaults']['graphs']['protocols'] ?? ['any']);
    }

    /** Default flow record limit */
    public function defaultFlowLimit(): int {
        return (int) ($this->raw['frontend']['defaults']['flows']['limit'] ?? 50);
    }

    /** Default statistics order-by field */
    public function defaultStatsOrderBy(): string {
        return (string) ($this->raw['frontend']['defaults']['statistics']['order_by'] ?? 'bytes');
    }

    // ── Nfdump ────────────────────────────────────────────────────────────────

    /** Absolute path to the nfdump binary */
    public function nfdumpBinary(): string {
        return (string) ($this->raw['nfdump']['binary'] ?? '/usr/bin/nfdump');
    }

    /** Absolute path to the nfcapd profiles-data directory */
    public function nfdumpProfilesData(): string {
        return (string) ($this->raw['nfdump']['profiles-data'] ?? '/var/nfdump/profiles-data');
    }

    /** Active nfdump profile name */
    public function nfdumpProfile(): string {
        return (string) ($this->raw['nfdump']['profile'] ?? 'live');
    }

    /** Maximum concurrent nfdump processes */
    public function nfdumpMaxProcesses(): int {
        return max(1, (int) ($this->raw['nfdump']['max-processes'] ?? 1));
    }

    // ── Logging ───────────────────────────────────────────────────────────────

    /**
     * Effective log priority (PHP LOG_* constant).
     * Respects the NFSEN_LOG_LEVEL environment variable override.
     */
    public function logPriority(): int {
        $envLevel = getenv('NFSEN_LOG_LEVEL');
        if ($envLevel !== false && $envLevel !== '') {
            $mapped = self::LOG_LEVEL_MAP[strtoupper($envLevel)] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return (int) ($this->raw['log']['priority'] ?? \LOG_INFO);
    }

    /**
     * Convert a log-level name string to a PHP LOG_* constant.
     * Returns LOG_INFO for unknown values.
     */
    public static function logLevelFromString(string $name): int {
        return self::LOG_LEVEL_MAP[strtoupper($name)] ?? \LOG_INFO;
    }

    /**
     * Convert a PHP LOG_* constant back to a lower-case string (e.g. "debug").
     */
    public static function logLevelToString(int $priority): string {
        $flip = array_flip([
            'emerg'   => \LOG_EMERG,
            'alert'   => \LOG_ALERT,
            'crit'    => \LOG_CRIT,
            'error'   => \LOG_ERR,
            'warning' => \LOG_WARNING,
            'notice'  => \LOG_NOTICE,
            'info'    => \LOG_INFO,
            'debug'   => \LOG_DEBUG,
        ]);

        return $flip[$priority] ?? 'info';
    }

    // ── Datasource-specific config ────────────────────────────────────────────

    /**
     * Return the raw config sub-array for a given datasource (e.g. 'RRD').
     *
     * @return array<string, mixed>
     */
    public function datasourceConfig(string $name): array {
        return (array) ($this->raw['db'][$name] ?? []);
    }

    // ── Raw access (escape hatch) ─────────────────────────────────────────────

    /**
     * Return the underlying raw config array.
     * Prefer the typed accessors above; use this only when necessary.
     *
     * @return array<string, mixed>
     */
    public function raw(): array {
        return $this->raw;
    }
}
