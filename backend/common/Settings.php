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
        'LOG_EMERG' => LOG_EMERG,
        'LOG_ALERT' => LOG_ALERT,
        'LOG_CRIT' => LOG_CRIT,
        'LOG_ERR' => LOG_ERR,
        'LOG_WARNING' => LOG_WARNING,
        'LOG_NOTICE' => LOG_NOTICE,
        'LOG_INFO' => LOG_INFO,
        'LOG_DEBUG' => LOG_DEBUG,
        'EMERG' => LOG_EMERG,
        'ALERT' => LOG_ALERT,
        'CRIT' => LOG_CRIT,
        'ERR' => LOG_ERR,
        'ERROR' => LOG_ERR,
        'WARNING' => LOG_WARNING,
        'NOTICE' => LOG_NOTICE,
        'INFO' => LOG_INFO,
        'DEBUG' => LOG_DEBUG,
    ];

    // ─── Datasource class name map ────────────────────────────────────────────
    // Keyed by canonical short name (matched case-insensitively). The keys are
    // the single source for the NFSEN_DATASOURCE enum — see datasourceNames().
    private const DATASOURCE_MAP = [
        'RRD' => 'mbolli\\nfsen_ng\\datasources\\Rrd',
        'VictoriaMetrics' => 'mbolli\\nfsen_ng\\datasources\\VictoriaMetrics',
    ];

    // ─── Processor class name map ─────────────────────────────────────────────
    private const PROCESSOR_MAP = [
        'NfDump' => 'mbolli\\nfsen_ng\\processor\\Nfdump',
    ];

    // ─── Valid UI theme values (deployment default for the dark-mode toggle) ──
    // 'auto' follows the browser's prefers-color-scheme; 'dark'/'light' force a
    // default that seeds a fresh browser (no saved toggle yet, e.g. after a cache
    // wipe). A user's explicit toggle is stored client-side and always wins.
    private const THEMES = ['auto', 'dark', 'light'];

    /**
     * @param string[]             $sources               Configured NetFlow source names
     * @param int[]                $ports                 Configured port numbers
     * @param string[]             $filters               Saved nfdump filter expressions
     * @param string[]             $defaultGraphProtocols Default graph protocols
     * @param int                  $importYears           How many years of data to import/retain (shared by all datasources)
     * @param array<string, mixed> $datasourceConfigs     Raw datasource config sub-arrays keyed by name
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
        public private(set) string $defaultTheme,
        public private(set) string $nfdumpBinary,
        public private(set) string $nfdumpProfilesData,
        public private(set) string $nfdumpProfile,
        public private(set) int $nfdumpMaxProcesses,
        public private(set) int $importYears,
        public private(set) int $logPriority,
        public private(set) int $maxStatsWindow,
        public private(set) string $netboxUrl,
        public private(set) string $netboxToken,
        /** @var AlertRule[] Alert rules stored in preferences.json */
        public private(set) array $alerts,
        public private(set) string $alertEmailFrom,
        public private(set) string $displayTimezone,
        public private(set) string $defaultEmailSubjectTemplate,
        public private(set) string $defaultEmailBodyTemplate,
        public private(set) string $defaultWebhookTitleTemplate,
        public private(set) string $defaultWebhookMessageTemplate,
        private array $datasourceConfigs,
    ) {}

    // ── Factories ─────────────────────────────────────────────────────────────

    /**
     * Build from the raw `$nfsen_config` array loaded from settings.php.
     *
     * settings.php is a deprecated overlay on top of the environment: where the
     * array defines a key it wins, and where it omits one the value falls back
     * to {@see EnvRegistry} (env var, else registry default). This gives the two
     * config paths one set of defaults and one validator each. Log level is the
     * lone exception — an explicit NFSEN_LOG_LEVEL overrides the file, since
     * bumping verbosity via env should work even with a settings.php present.
     */
    public static function fromArray(array $raw): self {
        $datasourceName = (string) ($raw['general']['db'] ?? EnvRegistry::value('NFSEN_DATASOURCE'));
        $importYears = max(1, (int) ($raw['db'][$datasourceName]['import_years'] ?? EnvRegistry::value('NFSEN_IMPORT_YEARS')));

        $logPriority = EnvRegistry::isSet('NFSEN_LOG_LEVEL')
            ? self::logLevelFromString((string) EnvRegistry::value('NFSEN_LOG_LEVEL'))
            : (int) ($raw['log']['priority'] ?? LOG_INFO);

        return new self(
            sources: (array) ($raw['general']['sources'] ?? EnvRegistry::value('NFSEN_SOURCES')),
            ports: array_map('intval', (array) ($raw['general']['ports'] ?? EnvRegistry::value('NFSEN_PORTS'))),
            filters: (array) ($raw['general']['filters'] ?? EnvRegistry::value('NFSEN_FILTERS')),
            datasourceName: $datasourceName,
            processorName: (string) ($raw['general']['processor'] ?? EnvRegistry::value('NFSEN_PROCESSOR')),
            defaultView: (string) ($raw['frontend']['defaults']['view'] ?? 'graphs'),
            defaultGraphDisplay: (string) ($raw['frontend']['defaults']['graphs']['display'] ?? 'sources'),
            defaultGraphDatatype: (string) ($raw['frontend']['defaults']['graphs']['datatype'] ?? 'traffic'),
            defaultGraphProtocols: (array) ($raw['frontend']['defaults']['graphs']['protocols'] ?? ['any']),
            defaultFlowLimit: (int) ($raw['frontend']['defaults']['flows']['limit'] ?? 50),
            defaultStatsOrderBy: (string) ($raw['frontend']['defaults']['statistics']['order_by'] ?? 'bytes'),
            defaultTheme: self::normalizeTheme((string) ($raw['frontend']['defaults']['theme'] ?? EnvRegistry::value('NFSEN_DEFAULT_THEME'))),
            nfdumpBinary: (string) ($raw['nfdump']['binary'] ?? EnvRegistry::value('NFSEN_NFDUMP_BINARY')),
            nfdumpProfilesData: (string) ($raw['nfdump']['profiles-data'] ?? EnvRegistry::value('NFSEN_NFDUMP_PROFILES')),
            nfdumpProfile: (string) ($raw['nfdump']['profile'] ?? EnvRegistry::value('NFSEN_NFDUMP_PROFILE')),
            nfdumpMaxProcesses: max(1, (int) ($raw['nfdump']['max-processes'] ?? EnvRegistry::value('NFSEN_NFDUMP_MAX_PROCESSES'))),
            importYears: $importYears,
            logPriority: $logPriority,
            maxStatsWindow: max(0, (int) ($raw['general']['max_stats_window'] ?? EnvRegistry::value('NFSEN_MAX_STATS_WINDOW'))),
            netboxUrl: (string) ($raw['general']['netbox_url'] ?? EnvRegistry::value('NFSEN_NETBOX_URL')),
            netboxToken: (string) ($raw['general']['netbox_token'] ?? EnvRegistry::value('NFSEN_NETBOX_TOKEN')),
            alerts: [],
            alertEmailFrom: (string) ($raw['general']['alert_email_from'] ?? EnvRegistry::value('NFSEN_ALERT_EMAIL_FROM')),
            displayTimezone: 'browser',
            defaultEmailSubjectTemplate: '',
            defaultEmailBodyTemplate: '',
            defaultWebhookTitleTemplate: '',
            defaultWebhookMessageTemplate: '',
            datasourceConfigs: (array) ($raw['db'] ?? []),
        );
    }

    /**
     * Build from environment variables only — the standard path for Docker deployments.
     * Every value is resolved, typed, and validated by {@see EnvRegistry}, the single
     * source of truth for env-var names, defaults, and validation.
     */
    public static function fromEnv(): self {
        // Datasource configs — import_years is a shared top-level setting; only store
        // datasource-specific connection details here.
        $rrdConfig = [];
        $rrdPath = (string) EnvRegistry::value('NFSEN_RRD_PATH');
        if ($rrdPath !== '') {
            $rrdConfig['data_path'] = $rrdPath;
        }

        $datasourceConfigs = [
            'RRD' => $rrdConfig,
            'VictoriaMetrics' => [
                'host' => (string) EnvRegistry::value('NFSEN_VM_HOST'),
                'port' => (int) EnvRegistry::value('NFSEN_VM_PORT'),
            ],
        ];

        return new self(
            sources: (array) EnvRegistry::value('NFSEN_SOURCES'),
            ports: (array) EnvRegistry::value('NFSEN_PORTS'),
            filters: (array) EnvRegistry::value('NFSEN_FILTERS'),
            datasourceName: (string) EnvRegistry::value('NFSEN_DATASOURCE'),
            processorName: (string) EnvRegistry::value('NFSEN_PROCESSOR'),
            defaultView: 'graphs',
            defaultGraphDisplay: 'sources',
            defaultGraphDatatype: 'traffic',
            defaultGraphProtocols: ['any'],
            defaultFlowLimit: 50,
            defaultStatsOrderBy: 'bytes',
            defaultTheme: (string) EnvRegistry::value('NFSEN_DEFAULT_THEME'),
            nfdumpBinary: (string) EnvRegistry::value('NFSEN_NFDUMP_BINARY'),
            nfdumpProfilesData: (string) EnvRegistry::value('NFSEN_NFDUMP_PROFILES'),
            nfdumpProfile: (string) EnvRegistry::value('NFSEN_NFDUMP_PROFILE'),
            nfdumpMaxProcesses: (int) EnvRegistry::value('NFSEN_NFDUMP_MAX_PROCESSES'),
            importYears: (int) EnvRegistry::value('NFSEN_IMPORT_YEARS'),
            logPriority: self::logLevelFromString((string) EnvRegistry::value('NFSEN_LOG_LEVEL')),
            maxStatsWindow: (int) EnvRegistry::value('NFSEN_MAX_STATS_WINDOW'),
            netboxUrl: (string) EnvRegistry::value('NFSEN_NETBOX_URL'),
            netboxToken: (string) EnvRegistry::value('NFSEN_NETBOX_TOKEN'),
            alerts: [],
            alertEmailFrom: (string) EnvRegistry::value('NFSEN_ALERT_EMAIL_FROM'),
            displayTimezone: 'browser',
            defaultEmailSubjectTemplate: '',
            defaultEmailBodyTemplate: '',
            defaultWebhookTitleTemplate: '',
            defaultWebhookMessageTemplate: '',
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
        return self::resolveClass(self::DATASOURCE_MAP, $this->datasourceName, 'datasource');
    }

    /**
     * Fully qualified processor class name.
     *
     * @throws \InvalidArgumentException for unknown processor names
     */
    public function processorClass(): string {
        return self::resolveClass(self::PROCESSOR_MAP, $this->processorName, 'processor');
    }

    /**
     * Case-insensitively resolve a canonical short name (e.g. "RRD") to its FQCN.
     *
     * @param array<string, string> $map  canonical short name → fully-qualified class name
     * @param string                $kind human label for the error message
     *
     * @throws \InvalidArgumentException for unknown names
     */
    private static function resolveClass(array $map, string $name, string $kind): string {
        foreach ($map as $canonical => $class) {
            if (strcasecmp($canonical, $name) === 0) {
                return $class;
            }
        }

        throw new \InvalidArgumentException("Unknown {$kind} '{$name}'. Known: " . implode(', ', array_keys($map)));
    }

    /**
     * Canonical datasource names — the single source for the NFSEN_DATASOURCE enum.
     *
     * @return list<string>
     */
    public static function datasourceNames(): array {
        return array_keys(self::DATASOURCE_MAP);
    }

    /**
     * Canonical processor names — the single source for the NFSEN_PROCESSOR enum.
     *
     * @return list<string>
     */
    public static function processorNames(): array {
        return array_keys(self::PROCESSOR_MAP);
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
        $clone = clone $this;
        $clone->sources = $sources;

        return $clone;
    }

    /** @param int[] $ports */
    public function withPorts(array $ports): self {
        $clone = clone $this;
        $clone->ports = $ports;

        return $clone;
    }

    /** @param string[] $filters */
    public function withFilters(array $filters): self {
        $clone = clone $this;
        $clone->filters = $filters;

        return $clone;
    }

    public function withDatasourceName(string $name): self {
        $clone = clone $this;
        $clone->datasourceName = $name;

        return $clone;
    }

    public function withProcessorName(string $name): self {
        $clone = clone $this;
        $clone->processorName = $name;

        return $clone;
    }

    public function withDefaultView(string $view): self {
        $clone = clone $this;
        $clone->defaultView = $view;

        return $clone;
    }

    public function withDefaultGraphDisplay(string $display): self {
        $clone = clone $this;
        $clone->defaultGraphDisplay = $display;

        return $clone;
    }

    public function withDefaultGraphDatatype(string $datatype): self {
        $clone = clone $this;
        $clone->defaultGraphDatatype = $datatype;

        return $clone;
    }

    /** @param string[] $protocols */
    public function withDefaultGraphProtocols(array $protocols): self {
        $clone = clone $this;
        $clone->defaultGraphProtocols = $protocols;

        return $clone;
    }

    public function withDefaultFlowLimit(int $limit): self {
        $clone = clone $this;
        $clone->defaultFlowLimit = $limit;

        return $clone;
    }

    public function withDefaultStatsOrderBy(string $orderBy): self {
        $clone = clone $this;
        $clone->defaultStatsOrderBy = $orderBy;

        return $clone;
    }

    public function withDefaultTheme(string $theme): self {
        $clone = clone $this;
        $clone->defaultTheme = self::normalizeTheme($theme);

        return $clone;
    }

    public function withNfdumpBinary(string $binary): self {
        $clone = clone $this;
        $clone->nfdumpBinary = $binary;

        return $clone;
    }

    public function withNfdumpProfilesData(string $path): self {
        $clone = clone $this;
        $clone->nfdumpProfilesData = $path;

        return $clone;
    }

    public function withNfdumpProfile(string $profile): self {
        $clone = clone $this;
        $clone->nfdumpProfile = $profile;

        return $clone;
    }

    public function withNfdumpMaxProcesses(int $max): self {
        $clone = clone $this;
        $clone->nfdumpMaxProcesses = max(1, $max);

        return $clone;
    }

    public function withImportYears(int $years): self {
        $clone = clone $this;
        $clone->importYears = max(1, $years);

        return $clone;
    }

    public function withLogPriority(int $priority): self {
        $clone = clone $this;
        $clone->logPriority = $priority;

        return $clone;
    }

    /** @param array<string, mixed> $config */
    public function withDatasourceConfig(string $name, array $config): self {
        $clone = clone $this;
        $clone->datasourceConfigs[$name] = $config;

        return $clone;
    }

    /** @param AlertRule[] $alerts */
    public function withAlerts(array $alerts): self {
        $clone = clone $this;
        $clone->alerts = $alerts;

        return $clone;
    }

    public function withDisplayTimezone(string $timezone): self {
        $clone = clone $this;
        $clone->displayTimezone = \in_array($timezone, ['browser', 'server'], true) ? $timezone : 'browser';

        return $clone;
    }

    public function withDefaultEmailSubjectTemplate(string $template): self {
        $clone = clone $this;
        $clone->defaultEmailSubjectTemplate = $template;

        return $clone;
    }

    public function withDefaultEmailBodyTemplate(string $template): self {
        $clone = clone $this;
        $clone->defaultEmailBodyTemplate = $template;

        return $clone;
    }

    public function withDefaultWebhookTitleTemplate(string $template): self {
        $clone = clone $this;
        $clone->defaultWebhookTitleTemplate = $template;

        return $clone;
    }

    public function withDefaultWebhookMessageTemplate(string $template): self {
        $clone = clone $this;
        $clone->defaultWebhookMessageTemplate = $template;

        return $clone;
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /** Normalize a UI theme string to one of 'auto'|'dark'|'light'. Unknown/empty values fall back to 'auto'. */
    public static function normalizeTheme(string $theme): string {
        $t = strtolower(trim($theme));

        return \in_array($t, self::THEMES, true) ? $t : 'auto';
    }

    /** Convert a log-level name string to a PHP LOG_* constant. Returns LOG_INFO for unknown values. */
    public static function logLevelFromString(string $name): int {
        return self::LOG_LEVEL_MAP[strtoupper($name)] ?? LOG_INFO;
    }

    /**
     * Accepted log-level name strings, lower-cased and de-duplicated.
     * The single source for the NFSEN_LOG_LEVEL enum in {@see EnvRegistry}.
     *
     * @return list<string>
     */
    public static function logLevelNames(): array {
        return array_values(array_unique(array_map('strtolower', array_keys(self::LOG_LEVEL_MAP))));
    }

    /** Convert a PHP LOG_* constant back to a lower-case string (e.g. "debug"). */
    public static function logLevelToString(int $priority): string {
        $flip = array_flip([
            'emerg' => LOG_EMERG,
            'alert' => LOG_ALERT,
            'crit' => LOG_CRIT,
            'error' => LOG_ERR,
            'warning' => LOG_WARNING,
            'notice' => LOG_NOTICE,
            'info' => LOG_INFO,
            'debug' => LOG_DEBUG,
        ]);

        return $flip[$priority] ?? 'info';
    }
}
