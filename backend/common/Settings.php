<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Typed, immutable settings value object for nfsen-ng.
 *
 * Uses PHP 8.4 asymmetric visibility (`public private(set)`) so properties
 * are publicly readable but can only be written inside this class — enabling
 * clone-based `with…()` fluent mutators without exposing setters.
 *
 * Usage:
 *   $s = Settings::fromArray($nfsen_config);
 *   $s->sources                         // string[]
 *   $s->nfdumpBinary                    // string
 *   $s->logPriority                     // int (LOG_* constant)
 *   $s->withSources(['gw1', 'gw2'])     // new instance, original unchanged
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

    /**
     * @param string[]             $sources           Configured NetFlow source names
     * @param int[]                $ports             Configured port numbers
     * @param string[]             $filters           Saved nfdump filter expressions
     * @param string[]             $defaultGraphProtocols Default graph protocols
     * @param int                  $importYears       How many years of data to import/retain (shared by all datasources)
     * @param array<string, mixed> $datasourceConfigs Raw datasource config sub-arrays keyed by name
     */
    private function __construct(
        public private(set) array $sources,
        public private(set) array $ports,
        public private(set) array $filters,
        public private(set) string $datasourceName,
        public private(set) string $processorName,
        public private(set) string $defaultView,
        public private(set) string $defaultGraphDisplay,
        public private(set) string $defaultGraphDatatype,
        public private(set) array $defaultGraphProtocols,
        public private(set) int $defaultFlowLimit,
        public private(set) string $defaultStatsOrderBy,
        public private(set) string $nfdumpBinary,
        public private(set) string $nfdumpProfilesData,
        public private(set) string $nfdumpProfile,
        public private(set) int $nfdumpMaxProcesses,
        public private(set) int $importYears,
        public private(set) int $logPriority,
        private array $datasourceConfigs,
    ) {}

    // ── Factories ─────────────────────────────────────────────────────────────

    /** Build from the raw `$nfsen_config` array loaded from settings.php. */
    public static function fromArray(array $raw): self {
        $logPriority    = (int) ($raw['log']['priority'] ?? \LOG_INFO);
        $datasourceName = (string) ($raw['general']['db'] ?? 'RRD');
        $importYears    = (int) ($raw['db'][$datasourceName]['import_years'] ?? 3);

        // Env-var override for log level — applied at construction time
        $envLevel = getenv('NFSEN_LOG_LEVEL');
        if ($envLevel !== false && $envLevel !== '') {
            $mapped = self::LOG_LEVEL_MAP[strtoupper($envLevel)] ?? null;
            if ($mapped !== null) {
                $logPriority = $mapped;
            }
        }

        return new self(
            sources: (array) ($raw['general']['sources'] ?? []),
            ports: array_map('intval', (array) ($raw['general']['ports'] ?? [])),
            filters: (array) ($raw['general']['filters'] ?? []),
            datasourceName: $datasourceName,
            processorName: (string) ($raw['general']['processor'] ?? 'NfDump'),
            defaultView: (string) ($raw['frontend']['defaults']['view'] ?? 'graphs'),
            defaultGraphDisplay: (string) ($raw['frontend']['defaults']['graphs']['display'] ?? 'sources'),
            defaultGraphDatatype: (string) ($raw['frontend']['defaults']['graphs']['datatype'] ?? 'traffic'),
            defaultGraphProtocols: (array) ($raw['frontend']['defaults']['graphs']['protocols'] ?? ['any']),
            defaultFlowLimit: (int) ($raw['frontend']['defaults']['flows']['limit'] ?? 50),
            defaultStatsOrderBy: (string) ($raw['frontend']['defaults']['statistics']['order_by'] ?? 'bytes'),
            nfdumpBinary: (string) ($raw['nfdump']['binary'] ?? '/usr/bin/nfdump'),
            nfdumpProfilesData: (string) ($raw['nfdump']['profiles-data'] ?? '/var/nfdump/profiles-data'),
            nfdumpProfile: (string) ($raw['nfdump']['profile'] ?? 'live'),
            nfdumpMaxProcesses: max(1, (int) ($raw['nfdump']['max-processes'] ?? 1)),
            importYears: max(1, $importYears),
            logPriority: $logPriority,
            datasourceConfigs: (array) ($raw['db'] ?? []),
        );
    }

    /** Build from environment variables only — the standard path for Docker deployments. */
    public static function fromEnv(): self {
        $logPriority = \LOG_INFO;
        $envLevel    = getenv('NFSEN_LOG_LEVEL');
        if ($envLevel !== false && $envLevel !== '') {
            $mapped = self::LOG_LEVEL_MAP[strtoupper($envLevel)] ?? null;
            if ($mapped !== null) {
                $logPriority = $mapped;
            }
        }

        // NFSEN_SOURCES — comma-separated source names, e.g. "gw1,gw2,mailserver"
        $sources    = [];
        $sourcesEnv = getenv('NFSEN_SOURCES');
        if ($sourcesEnv !== false && $sourcesEnv !== '') {
            $sources = array_values(array_filter(array_map('trim', explode(',', $sourcesEnv))));
        }

        // NFSEN_PORTS — comma-separated port numbers, e.g. "80,443,22"
        $ports    = [];
        $portsEnv = getenv('NFSEN_PORTS');
        if ($portsEnv !== false && $portsEnv !== '') {
            $ports = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $portsEnv)))));
        }

        // NFSEN_FILTERS — JSON array of nfdump filter strings
        // e.g. '["proto tcp","dst port 80"]'
        $filters    = [];
        $filtersEnv = getenv('NFSEN_FILTERS');
        if ($filtersEnv !== false && $filtersEnv !== '') {
            $decoded = json_decode($filtersEnv, true);
            if (\is_array($decoded)) {
                $filters = $decoded;
            }
        }

        // Datasource configs — import_years is a shared top-level setting; only store
        // datasource-specific connection details here.
        $rrdConfig = [];
        $rrdPath   = getenv('NFSEN_RRD_PATH');
        if ($rrdPath !== false && $rrdPath !== '') {
            $rrdConfig['data_path'] = $rrdPath;
        }

        $datasourceConfigs = [
            'RRD' => $rrdConfig,
            'VictoriaMetrics' => [
                'host' => (string) (getenv('VM_HOST') ?: 'victoriametrics'),
                'port' => (int) (getenv('VM_PORT') ?: 8428),
            ],
        ];

        return new self(
            sources: $sources,
            ports: $ports,
            filters: $filters,
            datasourceName: (string) (getenv('NFSEN_DATASOURCE') ?: 'RRD'),
            processorName: (string) (getenv('NFSEN_PROCESSOR') ?: 'NfDump'),
            defaultView: 'graphs',
            defaultGraphDisplay: 'sources',
            defaultGraphDatatype: 'traffic',
            defaultGraphProtocols: ['any'],
            defaultFlowLimit: 50,
            defaultStatsOrderBy: 'bytes',
            nfdumpBinary: (string) (getenv('NFSEN_NFDUMP_BINARY') ?: '/usr/local/nfdump/bin/nfdump'),
            nfdumpProfilesData: (string) (getenv('NFSEN_NFDUMP_PROFILES') ?: '/var/nfdump/profiles-data'),
            nfdumpProfile: (string) (getenv('NFSEN_NFDUMP_PROFILE') ?: 'live'),
            nfdumpMaxProcesses: max(1, (int) (getenv('NFSEN_NFDUMP_MAX_PROCESSES') ?: 1)),
            importYears: max(1, (int) (getenv('NFSEN_IMPORT_YEARS') ?: 3)),
            logPriority: $logPriority,
            datasourceConfigs: $datasourceConfigs,
        );
    }

    // ── Computed properties ───────────────────────────────────────────────────

    /**
     * Fully qualified datasource class name.
     *
     * @throws \InvalidArgumentException for unknown datasource names
     */
    public function datasourceClass(): string {
        $key   = strtolower($this->datasourceName);
        $class = self::DATASOURCE_MAP[$key] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException("Unknown datasource '{$key}'. Known: " . implode(', ', array_keys(self::DATASOURCE_MAP)));
        }

        return $class;
    }

    /**
     * Fully qualified processor class name.
     *
     * @throws \InvalidArgumentException for unknown processor names
     */
    public function processorClass(): string {
        $key   = strtolower($this->processorName);
        $class = self::PROCESSOR_MAP[$key] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException("Unknown processor '{$key}'. Known: " . implode(', ', array_keys(self::PROCESSOR_MAP)));
        }

        return $class;
    }

    /**
     * How many years of data to import/retain (shared by all datasources).
     * Equivalent to reading the `$importYears` property directly.
     */
    public function importYears(): int {
        return $this->importYears;
    }

    /**
     * Return the raw config sub-array for a given datasource (e.g. 'RRD').
     *
     * @return array<string, mixed>
     */
    public function datasourceConfig(string $name): array {
        return (array) ($this->datasourceConfigs[$name] ?? []);
    }

    // ── Fluent with…() mutators ───────────────────────────────────────────────

    /** @param string[] $sources */
    public function withSources(array $sources): self {
        $clone          = clone $this;
        $clone->sources = $sources;

        return $clone;
    }

    /** @param int[] $ports */
    public function withPorts(array $ports): self {
        $clone        = clone $this;
        $clone->ports = $ports;

        return $clone;
    }

    /** @param string[] $filters */
    public function withFilters(array $filters): self {
        $clone          = clone $this;
        $clone->filters = $filters;

        return $clone;
    }

    public function withDatasourceName(string $name): self {
        $clone                 = clone $this;
        $clone->datasourceName = $name;

        return $clone;
    }

    public function withProcessorName(string $name): self {
        $clone                = clone $this;
        $clone->processorName = $name;

        return $clone;
    }

    public function withDefaultView(string $view): self {
        $clone              = clone $this;
        $clone->defaultView = $view;

        return $clone;
    }

    public function withDefaultGraphDisplay(string $display): self {
        $clone                     = clone $this;
        $clone->defaultGraphDisplay = $display;

        return $clone;
    }

    public function withDefaultGraphDatatype(string $datatype): self {
        $clone                      = clone $this;
        $clone->defaultGraphDatatype = $datatype;

        return $clone;
    }

    /** @param string[] $protocols */
    public function withDefaultGraphProtocols(array $protocols): self {
        $clone                        = clone $this;
        $clone->defaultGraphProtocols = $protocols;

        return $clone;
    }

    public function withDefaultFlowLimit(int $limit): self {
        $clone                   = clone $this;
        $clone->defaultFlowLimit = $limit;

        return $clone;
    }

    public function withDefaultStatsOrderBy(string $orderBy): self {
        $clone                      = clone $this;
        $clone->defaultStatsOrderBy = $orderBy;

        return $clone;
    }

    public function withNfdumpBinary(string $binary): self {
        $clone               = clone $this;
        $clone->nfdumpBinary = $binary;

        return $clone;
    }

    public function withNfdumpProfilesData(string $path): self {
        $clone                    = clone $this;
        $clone->nfdumpProfilesData = $path;

        return $clone;
    }

    public function withNfdumpProfile(string $profile): self {
        $clone                = clone $this;
        $clone->nfdumpProfile = $profile;

        return $clone;
    }

    public function withNfdumpMaxProcesses(int $max): self {
        $clone                    = clone $this;
        $clone->nfdumpMaxProcesses = max(1, $max);

        return $clone;
    }

    public function withImportYears(int $years): self {
        $clone              = clone $this;
        $clone->importYears = max(1, $years);

        return $clone;
    }

    public function withLogPriority(int $priority): self {
        $clone              = clone $this;
        $clone->logPriority = $priority;

        return $clone;
    }

    /** @param array<string, mixed> $config */
    public function withDatasourceConfig(string $name, array $config): self {
        $clone                            = clone $this;
        $clone->datasourceConfigs[$name]  = $config;

        return $clone;
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /** Convert a log-level name string to a PHP LOG_* constant. Returns LOG_INFO for unknown values. */
    public static function logLevelFromString(string $name): int {
        return self::LOG_LEVEL_MAP[strtoupper($name)] ?? \LOG_INFO;
    }

    /** Convert a PHP LOG_* constant back to a lower-case string (e.g. "debug"). */
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
}
