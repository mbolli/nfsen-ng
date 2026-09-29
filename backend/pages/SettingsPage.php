<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\pages;

use mbolli\nfsen_ng\actions\SettingsActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\common\GeoIpDatabase;
use mbolli\nfsen_ng\common\HealthMetrics;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\Migrator;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Settings (was Settings > Preferences and System): the instance preferences, and the
 * deployment read-only in the Sources, Storage, Integrations and System tabs (4.8).
 *
 * @phpstan-type Choices array<string, string>
 * @phpstan-type EnvRow array{name: string, value: string, set: bool, doc: string}
 * @phpstan-type EnvGroup array{id: string, label: string, vars: list<EnvRow>}
 */
final class SettingsPage implements Page {
    /** App-global cache of the read-only tabs: they probe the file system and the SQLite file. */
    public const string CACHE = 'settings_facts';

    public const int CACHE_TTL = 30;

    /** App-global: the form values the last save wrote, and a counter the open tabs compare with. */
    public const string SAVED = 'settings_saved';

    /** Pages a bare address may open; Settings itself only while it is the saved value. */
    public const array VIEWS = ['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health'];

    public const array RANGES = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '1y' => 'Last year'];

    public const array UNITS = ['bits' => 'Bits', 'bytes' => 'Bytes'];

    /** The instance theme; '' (the deployment default) is labelled with the value it stands for. */
    public const array THEMES = ['system' => 'System', 'light' => 'Light', 'dark' => 'Dark'];

    public const array TIMEZONES = ['browser' => 'Browser', 'server' => 'Capture timezone'];

    public const array GRAPH_DISPLAYS = ['sources' => 'Sources', 'protocols' => 'Protocols', 'ports' => 'Ports'];

    public const array DATATYPES = ['traffic' => 'Traffic', 'packets' => 'Packets', 'flows' => 'Flows'];

    public const array PROTOCOLS = ['any' => 'Any', 'tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'other' => 'Other'];

    public const array FLOW_LIMITS = [20, 50, 100, 500, 1000, 10000];

    public const int FLOW_LIMIT_MIN = 20;

    public const int FLOW_LIMIT_MAX = 10000;

    public const array ORDER_BY = [
        'bytes' => 'Bytes', 'packets' => 'Packets', 'flows' => 'Flows',
        'pps' => 'Packets/s', 'bps' => 'Bits/s', 'bpp' => 'Bytes/packet',
    ];

    public const array LOG_LEVELS = ['debug' => 'Debug', 'info' => 'Info', 'notice' => 'Notice', 'warning' => 'Warning', 'error' => 'Error'];

    /** Offered only while one of them is the saved level. */
    private const array SEVERE_LOG_LEVELS = ['crit' => 'Critical', 'alert' => 'Alert', 'emerg' => 'Emergency'];

    /** Deployment theme (Settings::THEMES spelling) => the option label it matches. */
    private const array DEPLOYMENT_THEMES = ['auto' => 'System', 'light' => 'Light', 'dark' => 'Dark'];

    private const array ENV_GROUPS = [
        'core' => 'Core',
        'sources' => 'Sources',
        'datasource' => 'Datasource',
        'nfdump' => 'nfdump',
        'integrations' => 'Integrations',
        'daemon' => 'Import daemon',
        'files' => 'State and config files',
        'runtime' => 'OpenSwoole runtime',
        'timezone' => 'Timezone',
    ];

    /**
     * Per tab: the saved form values its form was last brought in line with.
     *
     * @var null|\WeakMap<Context, array{version: int, values: array<string, bool|int|string>}>
     */
    private static ?\WeakMap $seen = null;

    /** @var null|\WeakMap<Context, true> tabs that have rendered this page, and so read the read-only tabs */
    private static ?\WeakMap $opened = null;

    /**
     * The theme preference as saved, read once per preferences file and kept by publish():
     * signals() seeds it into every new tab.
     *
     * @var null|array{file: string, theme: string}
     */
    private static ?array $theme = null;

    public static function id(): string {
        return 'settings';
    }

    public static function title(): string {
        return 'Settings';
    }

    public static function lede(): string {
        return 'Preferences for this instance, and how it is deployed.';
    }

    public static function icon(): string {
        return 'gear';
    }

    public static function group(): string {
        return 'system';
    }

    public static function signals(Context $c): void {
        foreach (self::signalValues(Config::$settings, self::savedTheme()) as $name => $value) {
            $c->signal($value, $name, clientWritable: true);
        }
    }

    public static function register(Context $c, Via $app, PageStates $states): void {
        SettingsActions::register($c, $app);

        // signals() has just seeded the form: that is what this tab knows as saved.
        $values = [];
        foreach (array_keys(self::formValues(Config::$settings, '')) as $name) {
            $value = $c->getSignal($name)?->getValue();
            if (\is_bool($value) || \is_int($value) || \is_string($value)) {
                $values[$name] = $value;
            }
        }
        self::remember($c, self::savedVersion($app), $values);
    }

    public static function viewData(Context $c, Via $app, PageStates $states, bool $isUpdate): array {
        if ($isUpdate) {
            self::refresh($c, $app);
        }
        // A tab's first render of Settings (a GET, a reload of #/settings, the first navigation
        // to it) reads the read-only tabs fresh; its later renders may use the cache.
        $opened = self::opened();
        $fresh = !$isUpdate || !isset($opened[$c]);
        $opened[$c] = true;

        return [
            'general' => self::choices(Config::$settings),
            'captureTz' => Config::nfcapdTimezone()->getName(),
            'slots' => self::slots(),
            ...self::facts($app, $fresh, time()),
        ];
    }

    /**
     * The form's signals and their values for a settings object; the save sets them again
     * from what it wrote.
     *
     * @param string $theme the saved theme preference: '', 'system', 'light' or 'dark'
     *
     * @return array<string, bool|int|string>
     */
    public static function signalValues(Settings $settings, string $theme): array {
        return [
            'settings_defaultView' => Settings::normalizeView($settings->defaultView),
            'settings_defaultRange' => Settings::normalizeRange($settings->defaultRange),
            'settings_defaultUnit' => Settings::normalizeUnit($settings->defaultUnit),
            'settings_theme' => UserPreferences::normalizeTheme($theme),
            'settings_compactTables' => $settings->compactTables,
            'settings_graphDisplay' => $settings->defaultGraphDisplay,
            'settings_graphDatatype' => $settings->defaultGraphDatatype,
            // One protocol: the Overview graph opens with a single choice (D4).
            'settings_graphProtocols' => self::firstProtocol($settings->defaultGraphProtocols),
            'settings_flowLimit' => $settings->defaultFlowLimit,
            'settings_statsOrderBy' => $settings->defaultStatsOrderBy,
            'settings_logPriority' => Settings::logLevelToString($settings->logPriority),
            'settings_rdnsEnabled' => $settings->rdnsEnabled,
        ];
    }

    /**
     * Every signal a save writes: signalValues() plus the timezone display, which the Shell declares.
     *
     * @return array<string, bool|int|string>
     */
    public static function formValues(Settings $settings, string $theme): array {
        return [...self::signalValues($settings, $theme), 'displayTz' => $settings->displayTimezone];
    }

    /**
     * Records a save for the other open tabs, which take the fields it changed the next time
     * they render this page (refresh()). The read-only tabs are read again, since the save
     * may have created preferences.json.
     *
     * @param array<string, bool|int|string> $values formValues() of what the save wrote
     */
    public static function publish(Context $c, Via $app, array $values): void {
        $version = self::savedVersion($app) + 1;
        $app->setGlobalState(self::SAVED, ['version' => $version, 'values' => $values]);
        $app->setGlobalState(self::CACHE, null);
        self::remember($c, $version, $values);
        if (isset(Config::$prefsFile) && \is_string($values['settings_theme'] ?? null)) {
            self::$theme = ['file' => Config::$prefsFile, 'theme' => $values['settings_theme']];
        }
    }

    /**
     * Brings this tab's form in line with a save made in another tab: the fields that save
     * changed take the saved value, the others keep what this tab holds (4.8.2).
     */
    public static function refresh(Context $c, Via $app): void {
        $saved = $app->globalState(self::SAVED);
        if (!\is_array($saved) || !\is_int($saved['version'] ?? null) || !\is_array($saved['values'] ?? null)) {
            return;
        }
        $seen = self::seen()[$c] ?? null;
        if ($seen !== null && $seen['version'] === $saved['version']) {
            return;
        }

        /** @var array<string, bool|int|string> $values */
        $values = $saved['values'];
        foreach ($values as $name => $value) {
            if ($seen !== null && ($seen['values'][$name] ?? null) !== $value) {
                $c->getSignal($name)?->setValue($value, broadcast: false);
            }
        }
        self::remember($c, $saved['version'], $values);
    }

    /**
     * The General tab's options, value => label. Lists that must also show a saved value
     * the form does not offer (a hand-edited flow limit, a severe log level) include it.
     *
     * @return array{views: Choices, ranges: Choices, units: Choices, themes: Choices, timezones: Choices,
     *               graphDisplays: Choices, datatypes: Choices, protocols: Choices, flowLimits: list<int>,
     *               orderBy: Choices, logLevels: Choices}
     */
    public static function choices(Settings $settings): array {
        $titles = array_column(PageRegistry::meta(), 'title', 'id');
        $view = Settings::normalizeView($settings->defaultView);
        $views = [];
        foreach ([...self::VIEWS, ...(\in_array($view, self::VIEWS, true) ? [] : [$view])] as $id) {
            $views[$id] = $titles[$id] ?? ucfirst($id);
        }

        $level = Settings::logLevelToString($settings->logPriority);
        $flowLimits = array_values(array_unique([...self::FLOW_LIMITS, $settings->defaultFlowLimit]));
        sort($flowLimits);

        return [
            'views' => $views,
            'ranges' => self::RANGES,
            'units' => self::UNITS,
            'themes' => ['' => self::deploymentThemeLabel($settings)] + self::THEMES,
            'timezones' => self::TIMEZONES,
            'graphDisplays' => self::GRAPH_DISPLAYS,
            'datatypes' => self::DATATYPES,
            'protocols' => self::PROTOCOLS,
            'flowLimits' => $flowLimits,
            'orderBy' => self::ORDER_BY,
            'logLevels' => self::LOG_LEVELS + (isset(self::SEVERE_LOG_LEVELS[$level]) ? [$level => self::SEVERE_LOG_LEVELS[$level]] : []),
        ];
    }

    /** "Deployment default (Dark)": the option that saves '' names the theme it stands for. */
    public static function deploymentThemeLabel(Settings $settings): string {
        return 'Deployment default (' . (self::DEPLOYMENT_THEMES[$settings->deploymentTheme] ?? 'System') . ')';
    }

    /**
     * Every EnvRegistry variable by group, in registry order. Values go through
     * EnvVar::display(), so secrets are masked.
     *
     * @return list<EnvGroup>
     */
    public static function system(): array {
        $groups = [];
        foreach (EnvRegistry::table() as $var) {
            $value = EnvRegistry::value($var->name);
            $groups[$var->group][] = [
                'name' => $var->name,
                'value' => $var->display(EnvRegistry::acceptsAuto($var->name) && $value === 0 ? 'auto' : self::envString($value, $var->type)),
                'set' => EnvRegistry::isSet($var->name),
                'doc' => $var->doc,
            ];
        }

        $list = [];
        foreach ($groups as $id => $vars) {
            $list[] = ['id' => $id, 'label' => self::ENV_GROUPS[$id] ?? ucfirst($id), 'vars' => $vars];
        }

        return $list;
    }

    /**
     * System's "In effect" list: the deployment values no other tab shows, as nfsen-ng uses
     * them, so a settings.php value is visible next to the variable it replaces. The CPU cores
     * are detected, so their origin names the file or call they came from.
     *
     * @return list<array{label: string, value: string, code: bool, origin: string, hint?: string}>
     */
    public static function deployment(): array {
        $settings = Config::$settings;
        $presets = \count(Config::$deploymentFilters);
        $budget = HealthMetrics::processBudget();

        return [
            ['label' => 'nfdump binary', 'value' => $settings->nfdumpBinary, 'code' => true, 'origin' => self::origin('NFSEN_NFDUMP_BINARY', ['nfdump', 'binary'])],
            [
                'label' => 'Parallel nfdump processes',
                'value' => $budget['auto'] ? $budget['processes'] . ', auto' : (string) $budget['processes'],
                'code' => false,
                'origin' => self::origin('NFSEN_NFDUMP_MAX_PROCESSES', ['nfdump', 'max-processes']),
                'hint' => 'each uses about 2 to 3 CPU cores',
            ],
            ['label' => 'CPU cores', 'value' => (string) $budget['cores'], 'code' => false, 'origin' => $budget['coresOrigin']],
            [
                'label' => 'nfdump filter threads',
                'value' => HealthMetrics::workersText($budget),
                'code' => false,
                'origin' => self::origin('NFSEN_NFDUMP_WORKERS', ['nfdump', 'workers']),
            ],
            ['label' => 'Processor', 'value' => $settings->processorName, 'code' => true, 'origin' => self::origin('NFSEN_PROCESSOR', ['general', 'processor'])],
            ['label' => 'Default theme', 'value' => self::DEPLOYMENT_THEMES[$settings->deploymentTheme] ?? $settings->deploymentTheme, 'code' => false, 'origin' => self::origin('NFSEN_DEFAULT_THEME', ['frontend', 'defaults', 'theme'])],
            ['label' => 'Statistics window limit', 'value' => $settings->maxStatsWindow > 0 ? "{$settings->maxStatsWindow} seconds" : 'Unlimited', 'code' => false, 'origin' => self::origin('NFSEN_MAX_STATS_WINDOW', ['general', 'max_stats_window'])],
            ['label' => 'MCP over HTTP', 'value' => $settings->mcpHttpEnabled ? 'On' : 'Off', 'code' => false, 'origin' => self::origin('NFSEN_MCP_HTTP', ['general', 'mcp_http'])],
            ['label' => 'MCP hosts', 'value' => $settings->mcpHttpHosts === [] ? 'localhost only' : implode(', ', $settings->mcpHttpHosts), 'code' => false, 'origin' => self::origin('NFSEN_MCP_HOSTS', ['general', 'mcp_http_hosts'])],
            ['label' => 'Filter presets', 'value' => $presets === 0 ? 'None' : ($presets === 1 ? '1 expression' : "{$presets} expressions") . ', added to the saved filters at start-up', 'code' => false, 'origin' => self::origin('NFSEN_FILTERS', ['general', 'filters'])],
        ];
    }

    /**
     * nfdump slots in use by class: live, so outside the cache of the read-only tabs.
     *
     * @return array{inUse: int, max: int, split: string}
     */
    public static function slots(): array {
        $active = HealthMetrics::activeQueries();

        return ['inUse' => $active['inUse'], 'max' => $active['max'], 'split' => HealthMetrics::slotSplit($active)];
    }

    /**
     * The Integrations tab's read-only rows (3.11); reverse DNS is the form's switch.
     *
     * @return array{netbox: array{configured: bool, url: string, token: string},
     *               geoip: array{configured: bool, active: bool, path: string, type: string, built: string, error: string},
     *               webService: array{inUse: bool, url: string, token: string},
     *               email: array{from: string}}
     */
    public static function integrations(): array {
        $settings = Config::$settings;
        $geo = GeoIpDatabase::status();
        $geoActive = $geo['readable'] && $geo['error'] === '';

        return [
            'netbox' => [
                'configured' => $settings->netboxUrl !== '' && $settings->netboxToken !== '',
                'url' => $settings->netboxUrl,
                'token' => self::masked('NFSEN_NETBOX_TOKEN', $settings->netboxToken),
            ],
            'geoip' => [
                'configured' => trim($settings->geoipDb) !== '',
                'active' => $geoActive,
                'path' => $geo['path'] !== '' ? $geo['path'] : trim($settings->geoipDb),
                'type' => $geo['type'],
                'built' => $geo['buildEpoch'] > 0 ? gmdate('Y-m-d', $geo['buildEpoch']) : '',
                'error' => $geo['error'],
            ],
            'webService' => [
                'inUse' => !$geoActive,
                'url' => (string) EnvRegistry::value('NFSEN_IPINFO_URL'),
                'token' => self::masked('NFSEN_IPINFO_TOKEN', (string) EnvRegistry::value('NFSEN_IPINFO_TOKEN')),
            ],
            'email' => ['from' => $settings->alertEmailFrom],
        ];
    }

    /**
     * The Sources tab: what is captured and where (read-only).
     *
     * @return array{sources: list<string>, sourcesOrigin: string, ports: list<int>, portsOrigin: string,
     *               portDirection: string, portDirectionOrigin: string, profilesData: string,
     *               profilesDataExists: bool, profilesDataOrigin: string, defaultProfile: string, defaultProfileOrigin: string,
     *               profiles: list<string>,
     *               captureDirs: list<array{profile: string, source: string, path: string, exists: bool}>}
     */
    public static function sources(): array {
        $settings = Config::$settings;
        $profiles = array_values(array_map('strval', Config::detectProfiles()));
        $dirs = [];
        foreach ($profiles as $profile) {
            foreach ($settings->sources as $source) {
                $path = implode(\DIRECTORY_SEPARATOR, [rtrim($settings->nfdumpProfilesData, \DIRECTORY_SEPARATOR), $profile, $source]);
                $dirs[] = ['profile' => $profile, 'source' => $source, 'path' => $path, 'exists' => is_dir($path)];
            }
        }

        return [
            'sources' => $settings->sources,
            'sourcesOrigin' => self::origin('NFSEN_SOURCES', ['general', 'sources']),
            'ports' => $settings->ports,
            'portsOrigin' => self::origin('NFSEN_PORTS', ['general', 'ports']),
            'portDirection' => $settings->portDirection,
            'portDirectionOrigin' => self::origin('NFSEN_PORT_DIRECTION', ['nfdump', 'port-direction']),
            'profilesData' => $settings->nfdumpProfilesData,
            'profilesDataExists' => is_dir($settings->nfdumpProfilesData),
            'profilesDataOrigin' => self::origin('NFSEN_NFDUMP_PROFILES', ['nfdump', 'profiles-data']),
            'defaultProfile' => $settings->nfdumpProfile,
            'defaultProfileOrigin' => self::origin('NFSEN_NFDUMP_PROFILE', ['nfdump', 'profile']),
            'profiles' => $profiles,
            'captureDirs' => $dirs,
        ];
    }

    /**
     * The Storage tab: the time series backend and the files nfsen-ng writes (read-only).
     *
     * @return array{datasource: string, datasourceOrigin: string, location: array{label: string, value: string, exists: null|bool, origin: string},
     *               importYears: int, importYearsOrigin: string, stateDir: string, stateDirWritable: bool,
     *               prefsFile: string, prefsFileExists: bool, settingsFile: string,
     *               sqlite: array{path: string, exists: bool, writable: bool, journalMode: string, schemaVersion: int,
     *                             latestVersion: int, size: string, error: string},
     *               topnRetentionDays: int, topnRetentionOrigin: string}
     */
    public static function storage(): array {
        $settings = Config::$settings;
        $stateDir = isset(Config::$stateDir) ? Config::$stateDir : '';
        $prefsFile = isset(Config::$prefsFile) ? Config::$prefsFile : '';
        $sqlite = Database::inspect(rtrim($stateDir, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . Database::FILENAME);

        return [
            'datasource' => $settings->datasourceName,
            'datasourceOrigin' => self::origin('NFSEN_DATASOURCE', ['general', 'db']),
            'location' => self::datasourceLocation($settings),
            'importYears' => $settings->importYears,
            'importYearsOrigin' => self::origin('NFSEN_IMPORT_YEARS', ['db', $settings->datasourceName, 'import_years']),
            'stateDir' => $stateDir,
            'stateDirWritable' => $stateDir !== '' && is_dir($stateDir) && is_writable($stateDir),
            'prefsFile' => $prefsFile,
            'prefsFileExists' => $prefsFile !== '' && is_file($prefsFile),
            'settingsFile' => Config::$settingsFileLoaded ?? '',
            'sqlite' => [
                'path' => $sqlite['path'],
                'exists' => $sqlite['exists'],
                'writable' => $sqlite['writable'],
                'journalMode' => strtoupper($sqlite['journalMode']),
                'schemaVersion' => $sqlite['schemaVersion'],
                'latestVersion' => Migrator::latestVersion(),
                'size' => Estimate::humanBytes($sqlite['sizeBytes']),
                'error' => $sqlite['error'],
            ],
            'topnRetentionDays' => $settings->topnRetentionDays,
            'topnRetentionOrigin' => self::origin('NFSEN_TOPN_RETENTION_DAYS'),
        ];
    }

    /** The theme preference as saved ('' when nothing is saved): Settings only keeps what it resolves to. */
    private static function savedTheme(): string {
        if (!isset(Config::$prefsFile)) {
            return '';
        }
        if (self::$theme === null || self::$theme['file'] !== Config::$prefsFile) {
            self::$theme = ['file' => Config::$prefsFile, 'theme' => UserPreferences::load(Config::$prefsFile)->theme ?? ''];
        }

        return self::$theme['theme'];
    }

    /** @return \WeakMap<Context, array{version: int, values: array<string, bool|int|string>}> */
    private static function seen(): \WeakMap {
        return self::$seen ??= new \WeakMap();
    }

    /** @return \WeakMap<Context, true> */
    private static function opened(): \WeakMap {
        return self::$opened ??= new \WeakMap();
    }

    /** @param array<string, bool|int|string> $values */
    private static function remember(Context $c, int $version, array $values): void {
        $seen = self::seen();
        $seen[$c] = ['version' => $version, 'values' => $values];
    }

    private static function savedVersion(Via $app): int {
        $saved = $app->globalState(self::SAVED);

        return \is_array($saved) && \is_int($saved['version'] ?? null) ? $saved['version'] : 0;
    }

    /** @param list<string> $protocols */
    private static function firstProtocol(array $protocols): string {
        $first = strtolower((string) ($protocols[0] ?? 'any'));

        return isset(self::PROTOCOLS[$first]) ? $first : 'any';
    }

    /**
     * The read-only tabs from the shared cache, or read now when $fresh or the cache is stale.
     *
     * @return array<string, mixed>
     */
    private static function facts(Via $app, bool $fresh, int $now): array {
        $cache = $app->globalState(self::CACHE, null);
        if (!$fresh && \is_array($cache) && \is_int($cache['ts'] ?? null) && $now - $cache['ts'] < self::CACHE_TTL && \is_array($cache['facts'] ?? null)) {
            /** @var array<string, mixed> */
            return $cache['facts'];
        }

        $facts = [
            'sources' => self::sources(),
            'storage' => self::storage(),
            'integrations' => self::integrations(),
            'system' => self::system(),
            'deployment' => self::deployment(),
        ];

        try {
            $app->setGlobalState(self::CACHE, ['ts' => $now, 'facts' => $facts]);
        } catch (\OverflowException) {
            // Beyond the shared table's value size (worker_num > 1): serve the tabs uncached.
        }

        return $facts;
    }

    /** @return array{label: string, value: string, exists: null|bool, origin: string} */
    private static function datasourceLocation(Settings $settings): array {
        if ($settings->datasourceName === 'VictoriaMetrics') {
            $config = $settings->datasourceConfig('VictoriaMetrics');
            $host = \is_scalar($config['host'] ?? null) ? (string) $config['host'] : 'victoriametrics';
            $port = \is_scalar($config['port'] ?? null) ? (int) $config['port'] : 8428;

            $origin = self::origin('NFSEN_VM_HOST', ['db', 'VictoriaMetrics', 'host'], envFallback: false);
            if ($origin === 'default') {
                $origin = self::origin('NFSEN_VM_PORT', ['db', 'VictoriaMetrics', 'port'], envFallback: false);
            }

            return ['label' => 'VictoriaMetrics', 'value' => "{$host}:{$port}", 'exists' => null, 'origin' => $origin];
        }

        $path = $settings->datasourceConfig('RRD')['data_path'] ?? null;
        $path = \is_string($path) && $path !== ''
            ? $path
            : (isset(Config::$path) ? Config::$path : '') . \DIRECTORY_SEPARATOR . 'datasources' . \DIRECTORY_SEPARATOR . 'data';

        return ['label' => 'RRD directory', 'value' => $path, 'exists' => is_dir($path), 'origin' => self::origin('NFSEN_RRD_PATH', ['db', 'RRD', 'data_path'], envFallback: false)];
    }

    /**
     * Where a deployment value comes from: settings.php where it sets the key (it wins over the
     * environment), else the variable, else the built-in default.
     *
     * @param list<string> $path        the key in settings.php's $nfsen_config
     * @param bool         $envFallback false for the keys Settings::fromArray() takes from settings.php only
     */
    private static function origin(string $env, array $path = [], bool $envFallback = true): string {
        if (Config::$settingsFileLoaded !== null && $path !== []) {
            // Config::initialize() includes settings.php into this global.
            $node = $GLOBALS['nfsen_config'] ?? null;
            foreach ($path as $key) {
                $node = \is_array($node) ? ($node[$key] ?? null) : null;
            }
            if ($node !== null) {
                return 'settings.php';
            }
            if (!$envFallback) {
                return 'default';
            }
        }

        return EnvRegistry::isSet($env) ? $env : 'default';
    }

    private static function masked(string $env, string $value): string {
        return $value === '' ? '' : EnvRegistry::table()[$env]->display($value);
    }

    private static function envString(mixed $value, string $type): string {
        if (\is_array($value)) {
            return $type === 'json_array'
                ? (json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '')
                : implode(', ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '', $value));
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return \is_scalar($value) ? (string) $value : '';
    }
}
