<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * Typed, immutable settings value object for nfsen-ng.
 *
 * Uses PHP 8.4 asymmetric visibility (`public private(set)`) so properties
 * are publicly readable but can only be written inside this class, which enables
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
    // the single source for the NFSEN_DATASOURCE enum, see datasourceNames().
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

    /** Range presets a page opens with; the first-visit default is 24h. */
    private const RANGES = ['1h', '24h', '7d', '30d', '1y'];

    private const UNITS = ['bits', 'bytes'];

    private const GRAPH_DATATYPES = ['traffic', 'packets', 'flows'];

    /**
     * Default view preference => page id. The page ids map to themselves; the old view ids
     * follow D2. Kept here rather than in the pages layer, which common must not use.
     */
    private const VIEWS = [
        'overview' => 'overview',
        'talkers' => 'talkers',
        'flows' => 'flows',
        'conversations' => 'conversations',
        'alerts' => 'alerts',
        'health' => 'health',
        'settings' => 'settings',
        'graphs' => 'overview',
        'statistics' => 'talkers',
        'sankey' => 'conversations',
        'investigate' => 'flows',
    ];

    /** The theme settings.php or NFSEN_DEFAULT_THEME chose; withDefaultTheme() leaves it alone. */
    public private(set) string $deploymentTheme;

    /**
     * @param list<string>         $sources               Configured NetFlow source names
     * @param list<int>            $ports                 Configured port numbers
     * @param list<string>         $filters               Saved nfdump filter expressions
     * @param list<string>         $defaultGraphProtocols Default graph protocols
     * @param int                  $importYears           How many years of data to import/retain (shared by all datasources)
     * @param array<string, mixed> $datasourceConfigs     Raw datasource config sub-arrays keyed by name
     */
    private function __construct(
        public private(set) array $sources,
        public private(set) array $ports,
        public private(set) array $filters,
        public private(set) string $datasourceName,
        public private(set) string $processorName,
        /** Page bare `/` opens: overview, talkers, flows, conversations, alerts, health or settings. */
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
        /** Parallel nfdump processes in effect, at least 1; derived from the CPU cores when $nfdumpMaxProcessesAuto. */
        public private(set) int $nfdumpMaxProcesses,
        public private(set) bool $nfdumpMaxProcessesAuto,
        /** Filter threads per nfdump process, passed as -W; 0 leaves nfdump's own default. */
        public private(set) int $nfdumpWorkers,
        /** Which side of a flow the per-port series counts: 'any', 'dst' or 'src'. */
        public private(set) string $portDirection,
        public private(set) int $importYears,
        public private(set) int $logPriority,
        public private(set) int $maxStatsWindow,
        /** Serve MCP over HTTP at /_mcp, on the app's own port and behind whatever guards it. */
        public private(set) bool $mcpHttpEnabled,
        /** @var list<string> hostnames an MCP client may use; empty means localhost only */
        public private(set) array $mcpHttpHosts,
        public private(set) string $netboxUrl,
        public private(set) string $netboxToken,
        /** @var list<AlertRule> Alert rules stored in preferences.json */
        public private(set) array $alerts,
        public private(set) string $alertEmailFrom,
        public private(set) string $displayTimezone,
        public private(set) string $defaultEmailSubjectTemplate,
        public private(set) string $defaultEmailBodyTemplate,
        public private(set) string $defaultWebhookTitleTemplate,
        public private(set) string $defaultWebhookMessageTemplate,
        private array $datasourceConfigs,
        /** Range preset every page opens with: '1h', '24h', '7d', '30d' or '1y'. */
        public private(set) string $defaultRange,
        /** Unit of rates and volumes in charts and KPI cards: 'bits' or 'bytes'. */
        public private(set) string $defaultUnit,
        public private(set) bool $compactTables,
        /** Reverse DNS lookups in the IP info dialog. */
        public private(set) bool $rdnsEnabled,
        /** Days of per-interval top-N data kept in SQLite; 0 disables collection. */
        public private(set) int $topnRetentionDays,
        /** Path of a local MaxMind .mmdb for IP lookups; empty uses the web service. */
        public private(set) string $geoipDb,
    ) {
        $this->deploymentTheme = $defaultTheme;
    }

    /**
     * Build from the raw `$nfsen_config` array loaded from settings.php.
     *
     * settings.php is a deprecated overlay on top of the environment: where the
     * array defines a key it wins, and where it omits one the value falls back
     * to {@see EnvRegistry} (env var, else registry default). This gives the two
     * config paths one set of defaults and one validator each. Log level is the
     * lone exception: an explicit NFSEN_LOG_LEVEL overrides the file, since
     * bumping verbosity via env should work even with a settings.php present.
     *
     * @param array<string, mixed> $raw decoded $nfsen_config from settings.php
     */
    public static function fromArray(array $raw): self {
        $datasourceName = (string) ($raw['general']['db'] ?? EnvRegistry::value('NFSEN_DATASOURCE'));
        $importYears = max(1, (int) ($raw['db'][$datasourceName]['import_years'] ?? EnvRegistry::value('NFSEN_IMPORT_YEARS')));

        $logPriority = EnvRegistry::isSet('NFSEN_LOG_LEVEL')
            ? self::logLevelFromString((string) EnvRegistry::value('NFSEN_LOG_LEVEL'))
            : (int) ($raw['log']['priority'] ?? LOG_INFO);

        $datatype = $raw['frontend']['defaults']['graphs']['datatype'] ?? 'traffic';
        [$maxProcesses, $maxProcessesAuto] = self::resolveMaxProcesses($raw['nfdump']['max-processes'] ?? EnvRegistry::value('NFSEN_NFDUMP_MAX_PROCESSES'));

        return new self(
            sources: self::stringList($raw['general']['sources'] ?? EnvRegistry::value('NFSEN_SOURCES')),
            ports: self::intList($raw['general']['ports'] ?? EnvRegistry::value('NFSEN_PORTS')),
            filters: self::stringList($raw['general']['filters'] ?? EnvRegistry::value('NFSEN_FILTERS')),
            datasourceName: $datasourceName,
            processorName: (string) ($raw['general']['processor'] ?? EnvRegistry::value('NFSEN_PROCESSOR')),
            defaultView: self::normalizeView($raw['frontend']['defaults']['view'] ?? 'overview'),
            defaultGraphDisplay: (string) ($raw['frontend']['defaults']['graphs']['display'] ?? 'sources'),
            defaultGraphDatatype: self::normalizeGraphDatatype($datatype),
            defaultGraphProtocols: self::stringList($raw['frontend']['defaults']['graphs']['protocols'] ?? ['any']),
            defaultFlowLimit: (int) ($raw['frontend']['defaults']['flows']['limit'] ?? 50),
            defaultStatsOrderBy: (string) ($raw['frontend']['defaults']['statistics']['order_by'] ?? 'bytes'),
            defaultTheme: self::normalizeTheme((string) ($raw['frontend']['defaults']['theme'] ?? EnvRegistry::value('NFSEN_DEFAULT_THEME'))),
            nfdumpBinary: (string) ($raw['nfdump']['binary'] ?? EnvRegistry::value('NFSEN_NFDUMP_BINARY')),
            nfdumpProfilesData: (string) ($raw['nfdump']['profiles-data'] ?? EnvRegistry::value('NFSEN_NFDUMP_PROFILES')),
            nfdumpProfile: (string) ($raw['nfdump']['profile'] ?? EnvRegistry::value('NFSEN_NFDUMP_PROFILE')),
            nfdumpMaxProcesses: $maxProcesses,
            nfdumpMaxProcessesAuto: $maxProcessesAuto,
            nfdumpWorkers: self::clampWorkers($raw['nfdump']['workers'] ?? EnvRegistry::value('NFSEN_NFDUMP_WORKERS')),
            portDirection: self::normalizePortDirection($raw['nfdump']['port-direction'] ?? EnvRegistry::value('NFSEN_PORT_DIRECTION')),
            importYears: $importYears,
            logPriority: $logPriority,
            maxStatsWindow: max(0, (int) ($raw['general']['max_stats_window'] ?? EnvRegistry::value('NFSEN_MAX_STATS_WINDOW'))),
            mcpHttpEnabled: (bool) ($raw['general']['mcp_http'] ?? EnvRegistry::value('NFSEN_MCP_HTTP')),
            mcpHttpHosts: array_values(array_filter(array_map('strval', (array) ($raw['general']['mcp_http_hosts'] ?? EnvRegistry::value('NFSEN_MCP_HOSTS'))))),
            netboxUrl: (string) ($raw['general']['netbox_url'] ?? EnvRegistry::value('NFSEN_NETBOX_URL')),
            netboxToken: (string) ($raw['general']['netbox_token'] ?? EnvRegistry::value('NFSEN_NETBOX_TOKEN')),
            alerts: [],
            alertEmailFrom: (string) ($raw['general']['alert_email_from'] ?? EnvRegistry::value('NFSEN_ALERT_EMAIL_FROM')),
            displayTimezone: 'browser',
            defaultEmailSubjectTemplate: '',
            defaultEmailBodyTemplate: '',
            defaultWebhookTitleTemplate: '',
            defaultWebhookMessageTemplate: '',
            // The image sets NFSEN_RRD_PATH to its volume: a settings.php without a data_path keeps it.
            datasourceConfigs: array_replace_recursive(self::envDatasourceConfigs(), (array) ($raw['db'] ?? [])),
            defaultRange: '24h',
            defaultUnit: self::legacyUnit($datatype),
            compactTables: false,
            rdnsEnabled: true,
            topnRetentionDays: max(0, (int) EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS')),
            geoipDb: (string) EnvRegistry::value('NFSEN_GEOIP_DB'),
        );
    }

    /**
     * Build from environment variables only: the standard path for Docker deployments.
     * Every value is resolved, typed, and validated by {@see EnvRegistry}, the single
     * source of truth for env-var names, defaults, and validation.
     */
    public static function fromEnv(): self {
        $datasourceConfigs = self::envDatasourceConfigs();
        [$maxProcesses, $maxProcessesAuto] = self::resolveMaxProcesses(EnvRegistry::value('NFSEN_NFDUMP_MAX_PROCESSES'));

        return new self(
            sources: self::stringList(EnvRegistry::value('NFSEN_SOURCES')),
            ports: self::intList(EnvRegistry::value('NFSEN_PORTS')),
            filters: self::stringList(EnvRegistry::value('NFSEN_FILTERS')),
            datasourceName: (string) EnvRegistry::value('NFSEN_DATASOURCE'),
            processorName: (string) EnvRegistry::value('NFSEN_PROCESSOR'),
            defaultView: 'overview',
            defaultGraphDisplay: 'sources',
            defaultGraphDatatype: 'traffic',
            defaultGraphProtocols: ['any'],
            defaultFlowLimit: 50,
            defaultStatsOrderBy: 'bytes',
            defaultTheme: (string) EnvRegistry::value('NFSEN_DEFAULT_THEME'),
            nfdumpBinary: (string) EnvRegistry::value('NFSEN_NFDUMP_BINARY'),
            nfdumpProfilesData: (string) EnvRegistry::value('NFSEN_NFDUMP_PROFILES'),
            nfdumpProfile: (string) EnvRegistry::value('NFSEN_NFDUMP_PROFILE'),
            nfdumpMaxProcesses: $maxProcesses,
            nfdumpMaxProcessesAuto: $maxProcessesAuto,
            nfdumpWorkers: self::clampWorkers(EnvRegistry::value('NFSEN_NFDUMP_WORKERS')),
            portDirection: self::normalizePortDirection(EnvRegistry::value('NFSEN_PORT_DIRECTION')),
            importYears: (int) EnvRegistry::value('NFSEN_IMPORT_YEARS'),
            logPriority: self::logLevelFromString((string) EnvRegistry::value('NFSEN_LOG_LEVEL')),
            maxStatsWindow: (int) EnvRegistry::value('NFSEN_MAX_STATS_WINDOW'),
            mcpHttpEnabled: (bool) EnvRegistry::value('NFSEN_MCP_HTTP'),
            mcpHttpHosts: array_values(array_filter(array_map('strval', (array) EnvRegistry::value('NFSEN_MCP_HOSTS')))),
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
            defaultRange: '24h',
            defaultUnit: 'bits',
            compactTables: false,
            rdnsEnabled: true,
            topnRetentionDays: max(0, (int) EnvRegistry::value('NFSEN_TOPN_RETENTION_DAYS')),
            geoipDb: (string) EnvRegistry::value('NFSEN_GEOIP_DB'),
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
     * Canonical datasource names, the single source for the NFSEN_DATASOURCE enum.
     *
     * @return list<string>
     */
    public static function datasourceNames(): array {
        return array_keys(self::DATASOURCE_MAP);
    }

    /**
     * A port graph counts one side of a flow, or either.
     *
     * 'dst' is the default because it is what every release so far counted: changing it
     * silently would step every existing port series upward at an upgrade, for data already
     * on disk under the old meaning. 'any' is the honest reading of "traffic for port N" and
     * the fix for an exporter that reports one direction of each flow, which ingress-only or
     * egress-only sampling does, but it is opt-in.
     */
    public static function normalizePortDirection(mixed $value): string {
        $value = \is_string($value) ? strtolower(trim($value)) : '';

        return \in_array($value, ['any', 'dst', 'src'], true) ? $value : 'dst';
    }

    /**
     * Canonical processor names, the single source for the NFSEN_PROCESSOR enum.
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

    /** @param list<string> $sources */
    public function withSources(array $sources): self {
        $clone = clone $this;
        $clone->sources = $sources;

        return $clone;
    }

    /** @param list<int> $ports */
    public function withPorts(array $ports): self {
        $clone = clone $this;
        $clone->ports = $ports;

        return $clone;
    }

    /** @param list<string> $filters */
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
        $clone->defaultView = self::normalizeView($view);

        return $clone;
    }

    public function withDefaultGraphDisplay(string $display): self {
        $clone = clone $this;
        $clone->defaultGraphDisplay = $display;

        return $clone;
    }

    public function withDefaultGraphDatatype(string $datatype): self {
        $clone = clone $this;
        $clone->defaultGraphDatatype = self::normalizeGraphDatatype($datatype);

        return $clone;
    }

    /** @param list<string> $protocols */
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

    /** 0 (or less) derives the limit from the CPU cores, like NFSEN_NFDUMP_MAX_PROCESSES=auto. */
    public function withNfdumpMaxProcesses(int $max): self {
        $clone = clone $this;
        [$clone->nfdumpMaxProcesses, $clone->nfdumpMaxProcessesAuto] = self::resolveMaxProcesses($max);

        return $clone;
    }

    public function withNfdumpWorkers(int $workers): self {
        $clone = clone $this;
        $clone->nfdumpWorkers = self::clampWorkers($workers);

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

    /** @param list<AlertRule> $alerts */
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

    public function withDefaultRange(string $range): self {
        $clone = clone $this;
        $clone->defaultRange = self::normalizeRange($range);

        return $clone;
    }

    public function withDefaultUnit(string $unit): self {
        $clone = clone $this;
        $clone->defaultUnit = self::normalizeUnit($unit);

        return $clone;
    }

    public function withCompactTables(bool $compact): self {
        $clone = clone $this;
        $clone->compactTables = $compact;

        return $clone;
    }

    public function withRdnsEnabled(bool $enabled): self {
        $clone = clone $this;
        $clone->rdnsEnabled = $enabled;

        return $clone;
    }

    public function withTopnRetentionDays(int $days): self {
        $clone = clone $this;
        $clone->topnRetentionDays = max(0, $days);

        return $clone;
    }

    public function withGeoipDb(string $path): self {
        $clone = clone $this;
        $clone->geoipDb = $path;

        return $clone;
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * The process limit a configured value stands for, and whether it was derived: a positive
     * number is used as it is; 0, `auto` or anything else that is not a positive number means
     * a third of the CPU cores, between 2 and 8 ({@see CpuBudget::autoProcesses()}).
     *
     * @return array{0: int<1, max>, 1: bool}
     */
    public static function resolveMaxProcesses(mixed $configured): array {
        $n = is_numeric($configured) ? (int) $configured : 0;
        if ($n >= 1) {
            return [$n, false];
        }

        return [max(1, CpuBudget::autoProcesses(CpuBudget::cores())), true];
    }

    /** -W takes 0 (nfdump's default) up to CpuBudget::MAX_WORKERS; anything else is the registry default. */
    public static function clampWorkers(mixed $workers): int {
        $workers = is_numeric($workers) ? (int) $workers : EnvRegistry::table()['NFSEN_NFDUMP_WORKERS']->default;

        return max(0, min(CpuBudget::MAX_WORKERS, (int) $workers));
    }

    /** Normalize a UI theme string to one of 'auto'|'dark'|'light'. Unknown/empty values fall back to 'auto'. */
    public static function normalizeTheme(string $theme): string {
        $t = strtolower(trim($theme));

        return \in_array($t, self::THEMES, true) ? $t : 'auto';
    }

    /** A page id; the old view ids map to theirs (D2) and anything else becomes 'overview'. */
    public static function normalizeView(mixed $view): string {
        $v = \is_string($view) ? strtolower(trim($view)) : '';

        return self::VIEWS[$v] ?? 'overview';
    }

    /** '1h'|'24h'|'7d'|'30d'|'1y'; anything else becomes '24h'. */
    public static function normalizeRange(mixed $range): string {
        $r = \is_string($range) ? strtolower(trim($range)) : '';

        return \in_array($r, self::RANGES, true) ? $r : '24h';
    }

    /** 'bits'|'bytes'; anything else becomes 'bits'. */
    public static function normalizeUnit(mixed $unit): string {
        $u = \is_string($unit) ? strtolower(trim($unit)) : '';

        return \in_array($u, self::UNITS, true) ? $u : 'bits';
    }

    /** 'traffic'|'packets'|'flows'. The legacy 'bytes' is traffic shown in bytes, see legacyUnit(). */
    public static function normalizeGraphDatatype(mixed $datatype): string {
        $d = \is_string($datatype) ? strtolower(trim($datatype)) : '';

        return \in_array($d, self::GRAPH_DATATYPES, true) ? $d : 'traffic';
    }

    /** The unit a config without a saved unit implies: the legacy 'bytes' datatype means traffic in bytes. */
    public static function legacyUnit(mixed $datatype): string {
        return \is_string($datatype) && strtolower(trim($datatype)) === 'bytes' ? 'bytes' : 'bits';
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

    /**
     * The datasources' connection details from the environment. import_years is a shared top-level
     * setting, so only datasource-specific details go here.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function envDatasourceConfigs(): array {
        $rrdConfig = [];
        $rrdPath = (string) EnvRegistry::value('NFSEN_RRD_PATH');
        if ($rrdPath !== '') {
            $rrdConfig['data_path'] = $rrdPath;
        }

        return [
            'RRD' => $rrdConfig,
            'VictoriaMetrics' => [
                'host' => (string) EnvRegistry::value('NFSEN_VM_HOST'),
                'port' => (int) EnvRegistry::value('NFSEN_VM_PORT'),
            ],
        ];
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /**
     * Coerce a raw config value into a list of strings.
     *
     * settings.php is hand-written and preferences.json is user-editable, so a
     * keyed array, a bare scalar or a nested array can all turn up where a list
     * is expected. Consumers index these positionally ($sources[0] in the
     * datasources), so re-key them and drop anything that isn't scalar.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array {
        $list = [];
        foreach ((array) $value as $item) {
            if (\is_scalar($item)) {
                $list[] = (string) $item;
            }
        }

        return $list;
    }

    /**
     * Same as {@see stringList()} for port numbers. Non-numeric entries are
     * dropped rather than cast: intval() would turn them into port 0, which is
     * not a rejected value but the datasources' name for the aggregate series.
     *
     * @return list<int>
     */
    private static function intList(mixed $value): array {
        $list = [];
        foreach ((array) $value as $item) {
            if (is_numeric($item)) {
                $list[] = (int) $item;
            }
        }

        return $list;
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
}
