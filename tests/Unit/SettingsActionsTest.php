<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\SettingsActions;
use mbolli\nfsen_ng\common\AlertRule;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\EnvRegistry;
use mbolli\nfsen_ng\common\GeoIpDatabase;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\common\UserPreferences;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\SettingsPage;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * A saved preferences file with everything a Settings save must leave alone.
 *
 * @return array<string, mixed>
 */
function settingsActionsSaved(): array {
    return (new UserPreferences(
        defaultView: 'flows',
        defaultGraphDisplay: 'ports',
        defaultGraphDatatype: 'packets',
        defaultGraphProtocols: ['udp'],
        defaultFlowLimit: 500,
        defaultStatsOrderBy: 'flows',
        filters: ['proto tcp', 'dst port 443'],
        logPriority: LOG_NOTICE,
        selectedProfile: 'test',
        alerts: [AlertRule::fromArray(['id' => 'r1', 'name' => 'High traffic', 'thresholdValue' => 5])],
        displayTimezone: 'server',
        defaultEmailSubjectTemplate: 'subject {rule}',
        defaultEmailBodyTemplate: 'body {rule}',
        defaultWebhookTitleTemplate: 'title {rule}',
        defaultWebhookMessageTemplate: 'message {rule}',
        theme: 'dark',
        defaultRange: '30d',
        defaultUnit: 'bytes',
        compactTables: true,
        rdnsEnabled: false,
    ))->toArray();
}

/**
 * What the General form and the reverse DNS switch post, by preference field.
 *
 * @return array<string, mixed>
 */
function settingsActionsForm(array $overrides = []): array {
    return [
        'defaultView' => 'talkers',
        'defaultRange' => '7d',
        'defaultUnit' => 'bits',
        'theme' => '',
        'compactTables' => false,
        'displayTimezone' => 'browser',
        'defaultGraphDisplay' => 'protocols',
        'defaultGraphDatatype' => 'flows',
        'defaultGraphProtocols' => 'tcp',
        'defaultFlowLimit' => 100,
        'defaultStatsOrderBy' => 'bps',
        'logPriority' => 'debug',
        'rdnsEnabled' => true,
        ...$overrides,
    ];
}

/** A tab with the Settings signals and the save-settings action, as the page handler composes it. */
function settingsActionsTab(Via $app, PageStates $states, string $id): Context {
    $c = new Context($id, '/', $app);
    $c->signal(Config::$settings->displayTimezone, 'displayTz', clientWritable: true);
    SettingsPage::signals($c);
    SettingsPage::register($c, $app, $states);

    return $c;
}

/** Runs save-settings in a tab and returns the scripts it sent (the toast). */
function settingsActionsSave(Context $c, string $scope): string {
    $c->setPageInput(['scope' => $scope]);
    $c->executeAction((string) $c->getAction('save-settings')?->id());
    $scripts = '';
    while (($patch = $c->getPatch()) !== null) {
        $scripts .= $patch['type'] === 'script' && is_string($patch['content']) ? $patch['content'] : '';
    }

    return $scripts;
}

describe('SettingsActions::buildPreferences()', function (): void {
    test('writes every General field and reverse DNS', function (): void {
        $prefs = SettingsActions::buildPreferences(settingsActionsSaved(), settingsActionsForm());

        expect($prefs->defaultView)->toBe('talkers')
            ->and($prefs->defaultRange)->toBe('7d')
            ->and($prefs->defaultUnit)->toBe('bits')
            ->and($prefs->theme)->toBe('')
            ->and($prefs->compactTables)->toBeFalse()
            ->and($prefs->displayTimezone)->toBe('browser')
            ->and($prefs->defaultGraphDisplay)->toBe('protocols')
            ->and($prefs->defaultGraphDatatype)->toBe('flows')
            ->and($prefs->defaultGraphProtocols)->toBe(['tcp'])
            ->and($prefs->defaultFlowLimit)->toBe(100)
            ->and($prefs->defaultStatsOrderBy)->toBe('bps')
            ->and($prefs->logPriority)->toBe(LOG_DEBUG)
            ->and($prefs->rdnsEnabled)->toBeTrue()
        ;
    });

    test('keeps the selected profile, the rules, their templates and the filters', function (): void {
        $saved = settingsActionsSaved();
        $prefs = SettingsActions::buildPreferences($saved, settingsActionsForm())->toArray();

        expect($prefs['selectedProfile'])->toBe('test')
            ->and($prefs['alerts'])->toBe($saved['alerts'])
            ->and($prefs['filters'])->toBe(['proto tcp', 'dst port 443'])
            ->and($prefs['defaultEmailSubjectTemplate'])->toBe('subject {rule}')
            ->and($prefs['defaultEmailBodyTemplate'])->toBe('body {rule}')
            ->and($prefs['defaultWebhookTitleTemplate'])->toBe('title {rule}')
            ->and($prefs['defaultWebhookMessageTemplate'])->toBe('message {rule}')
        ;
    });

    test('ignores posted keys that are not form fields', function (): void {
        $prefs = SettingsActions::buildPreferences(settingsActionsSaved(), settingsActionsForm([
            'selectedProfile' => 'live',
            'filters' => [],
            'alerts' => [],
            'defaultEmailSubjectTemplate' => 'hijacked',
        ]));

        expect($prefs->selectedProfile)->toBe('test')
            ->and($prefs->filters)->toBe(['proto tcp', 'dst port 443'])
            ->and($prefs->alerts)->toHaveCount(1)
            ->and($prefs->defaultEmailSubjectTemplate)->toBe('subject {rule}')
        ;
    });

    test('a field that was not posted keeps its saved value', function (): void {
        $prefs = SettingsActions::buildPreferences(settingsActionsSaved(), ['defaultRange' => '1h', 'theme' => null]);

        expect($prefs->defaultRange)->toBe('1h')
            ->and($prefs->theme)->toBe('dark')
            ->and($prefs->defaultView)->toBe('flows')
            ->and($prefs->defaultUnit)->toBe('bytes')
            ->and($prefs->compactTables)->toBeTrue()
            ->and($prefs->rdnsEnabled)->toBeFalse()
            ->and($prefs->logPriority)->toBe(LOG_NOTICE)
        ;
    });

    test('without a preferences file it starts from the defaults', function (): void {
        $prefs = SettingsActions::buildPreferences(['selectedProfile' => 'lab'], ['defaultUnit' => 'bytes']);

        expect($prefs->selectedProfile)->toBe('lab')
            ->and($prefs->defaultUnit)->toBe('bytes')
            ->and($prefs->defaultRange)->toBe('24h')
            ->and($prefs->theme)->toBe('')
            ->and($prefs->filters)->toBe([])
            ->and($prefs->alerts)->toBe([])
        ;
    });

    test('the theme: the deployment default saves as the empty value', function (mixed $posted, string $saved): void {
        expect(SettingsActions::buildPreferences(settingsActionsSaved(), ['theme' => $posted])->theme)->toBe($saved);
    })->with([
        'deployment default' => ['', ''],
        'system' => ['system', 'system'],
        'light' => ['light', 'light'],
        'dark' => ['Dark', 'dark'],
        'the settings.php spelling' => ['auto', 'system'],
        'junk' => ['purple', ''],
    ]);

    test('the default view is a page id, and legacy view ids map to theirs', function (mixed $posted, string $saved): void {
        expect(SettingsActions::buildPreferences([], ['defaultView' => $posted])->defaultView)->toBe($saved);
    })->with([
        ['health', 'health'],
        ['statistics', 'talkers'],
        ['graphs', 'overview'],
        ['sankey', 'conversations'],
        ['bogus', 'overview'],
    ]);

    test('the graph protocol is one choice, stored as a one-element list', function (mixed $posted, array $saved): void {
        expect(SettingsActions::buildPreferences([], ['defaultGraphProtocols' => $posted])->defaultGraphProtocols)->toBe($saved);
    })->with([
        'a choice' => ['icmp', ['icmp']],
        'upper case' => ['UDP', ['udp']],
        'the old multi-select list' => [['tcp', 'udp'], ['tcp']],
        'an unknown protocol' => ['sctp', ['any']],
        'an empty list' => [[], ['any']],
    ]);

    test('the flow limit is clamped to 20..10000', function (mixed $posted, int $saved): void {
        expect(SettingsActions::buildPreferences([], ['defaultFlowLimit' => $posted])->defaultFlowLimit)->toBe($saved);
    })->with([
        [1000, 1000],
        ['500', 500],
        [5, 20],
        [99_999, 10_000],
        ['many', 50],
    ]);

    test('the log level is saved as its LOG_ constant', function (mixed $posted, int $saved): void {
        expect(SettingsActions::buildPreferences([], ['logPriority' => $posted])->logPriority)->toBe($saved);
    })->with([
        ['debug', LOG_DEBUG],
        ['error', LOG_ERR],
        ['WARNING', LOG_WARNING],
        ['crit', LOG_CRIT],
        [LOG_NOTICE, LOG_NOTICE],
        ['bogus', LOG_INFO],
    ]);

    test('choices outside their lists fall back to the defaults', function (): void {
        $prefs = SettingsActions::buildPreferences(settingsActionsSaved(), settingsActionsForm([
            'defaultRange' => '2w',
            'defaultUnit' => 'nibbles',
            'displayTimezone' => 'mars',
            'defaultGraphDisplay' => 'everything',
            'defaultGraphDatatype' => 'bananas',
            'defaultStatsOrderBy' => 'random',
        ]));

        expect($prefs->defaultRange)->toBe('24h')
            ->and($prefs->defaultUnit)->toBe('bits')
            ->and($prefs->displayTimezone)->toBe('browser')
            ->and($prefs->defaultGraphDisplay)->toBe('sources')
            ->and($prefs->defaultGraphDatatype)->toBe('traffic')
            ->and($prefs->defaultStatsOrderBy)->toBe('bytes')
        ;
    });

    test('switches accept what a signal or a hand edit may hold', function (mixed $posted, bool $saved): void {
        $prefs = SettingsActions::buildPreferences([], ['compactTables' => $posted, 'rdnsEnabled' => $posted]);

        expect($prefs->compactTables)->toBe($saved)
            ->and($prefs->rdnsEnabled)->toBe($saved)
        ;
    })->with([
        [true, true],
        [false, false],
        ['true', true],
        ['1', true],
        ['off', false],
        ['', false],
    ]);

    test('every General field and reverse DNS has a signal the page declares', function (): void {
        $settings = Settings::fromArray(['general' => ['sources' => ['gw1']]]);
        $declared = array_keys(SettingsPage::formValues($settings, ''));

        expect(array_diff(array_values(SettingsActions::FIELDS), $declared))->toBe([])
            ->and($declared)->toContain('displayTz')
        ;
    });

    test('each Save writes its own form: General without reverse DNS, the switch alone', function (): void {
        $general = SettingsActions::fieldsFor('general');
        $rdns = SettingsActions::fieldsFor('rdns');

        expect($rdns)->toBe(['rdnsEnabled' => 'settings_rdnsEnabled'])
            ->and($general)->not->toHaveKey('rdnsEnabled')
            ->and($general + $rdns)->toEqual(SettingsActions::FIELDS)
            ->and(SettingsActions::fieldsFor(''))->toBe(SettingsActions::FIELDS)
            ->and(SettingsActions::fieldsFor('bogus'))->toBe(SettingsActions::FIELDS)
        ;
    });
});

describe('a save round trip', function (): void {
    test("the saved preferences reach Settings, and '' restores the deployment theme", function (): void {
        $file = tempnam(sys_get_temp_dir(), 'nfsen-settings-actions-');
        $deployment = Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'light']]]);

        try {
            SettingsActions::buildPreferences(settingsActionsSaved(), settingsActionsForm(['theme' => 'dark', 'compactTables' => true]))->save($file);
            $dark = UserPreferences::load($file)?->applyTo($deployment);

            $saved = UserPreferences::load($file)?->toArray() ?? [];
            SettingsActions::buildPreferences($saved, ['theme' => ''])->save($file);
            $restored = UserPreferences::load($file)?->applyTo($dark ?? $deployment);

            expect($dark?->defaultTheme)->toBe('dark')
                ->and($dark?->compactTables)->toBeTrue()
                ->and($dark?->defaultRange)->toBe('7d')
                ->and($dark?->defaultView)->toBe('talkers')
                ->and($restored?->defaultTheme)->toBe('light')
                ->and(UserPreferences::load($file)?->selectedProfile)->toBe('test')
            ;
        } finally {
            @unlink($file);
        }
    });
});

describe('the save-settings action', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = (new ReflectionProperty(Config::class, 'settings'))->isInitialized() ? Config::$settings : null;
        $this->prefsBefore = (new ReflectionProperty(Config::class, 'prefsFile'))->isInitialized() ? Config::$prefsFile : null;

        $this->dir = sys_get_temp_dir() . '/nfsen-settings-save-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        Config::$prefsFile = $this->dir . '/preferences.json';
        file_put_contents(Config::$prefsFile, json_encode(settingsActionsSaved(), JSON_THROW_ON_ERROR));
        Config::$settings = UserPreferences::fromArray(settingsActionsSaved())->applyTo(Settings::fromArray(['general' => ['sources' => ['gw1']]]));

        putenv('VIA_TEST_MODE=1');
        $this->app = new Via(new ViaConfig());
        $this->states = new PageStates();
    });

    afterEach(function (): void {
        putenv('VIA_TEST_MODE');
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
        removeTree($this->dir);
    });

    test('the reverse DNS Save writes the switch only, and the General edits stay unsaved in the form', function (): void {
        $c = settingsActionsTab($this->app, $this->states, 'ctx-rdns');
        $c->getSignal('settings_defaultRange')?->setValue('1h');
        $c->getSignal('settings_rdnsEnabled')?->setValue(true);

        $scripts = settingsActionsSave($c, 'rdns');
        $saved = UserPreferences::load(Config::$prefsFile);

        expect($scripts)->toContain('Settings saved.')
            ->and($saved?->rdnsEnabled)->toBeTrue()
            ->and($saved?->defaultRange)->toBe('30d')
            ->and(Config::$settings->rdnsEnabled)->toBeTrue()
            ->and(Config::$settings->defaultRange)->toBe('30d')
            ->and($c->getSignal('settings_defaultRange')?->string())->toBe('1h')
        ;
    });

    test('the General Save leaves reverse DNS as saved, and its switch edit stays unsaved', function (): void {
        $c = settingsActionsTab($this->app, $this->states, 'ctx-general');
        $c->getSignal('settings_defaultRange')?->setValue('1h');
        $c->getSignal('settings_rdnsEnabled')?->setValue(true);
        $c->getSignal('settings_flowLimit')?->setValue('5');

        settingsActionsSave($c, 'general');
        $saved = UserPreferences::load(Config::$prefsFile);

        expect($saved?->defaultRange)->toBe('1h')
            ->and($saved?->rdnsEnabled)->toBeFalse()
            ->and($saved?->selectedProfile)->toBe('test')
            ->and($saved?->alerts)->toHaveCount(1)
            ->and($c->getSignal('settings_flowLimit')?->int())->toBe(20)
            ->and($c->getSignal('settings_rdnsEnabled')?->bool())->toBeTrue()
        ;
    });

    test('a save reaches the other open tabs: the fields it changed, not the edits they hold', function (): void {
        $saver = settingsActionsTab($this->app, $this->states, 'ctx-saver');
        $other = settingsActionsTab($this->app, $this->states, 'ctx-other');
        $other->getSignal('settings_flowLimit')?->setValue(1000);
        $saver->getSignal('settings_defaultRange')?->setValue('7d');
        $saver->getSignal('displayTz')?->setValue('browser');

        settingsActionsSave($saver, 'general');
        SettingsPage::viewData($other, $this->app, $this->states, true);
        $afterSave = [
            'range' => $other->getSignal('settings_defaultRange')?->string(),
            'tz' => $other->getSignal('displayTz')?->string(),
            'limit' => $other->getSignal('settings_flowLimit')?->int(),
            'view' => $other->getSignal('settings_defaultView')?->string(),
        ];
        $other->getSignal('settings_defaultRange')?->setValue('1h');
        SettingsPage::viewData($other, $this->app, $this->states, true);

        expect($afterSave)->toBe(['range' => '7d', 'tz' => 'browser', 'limit' => 1000, 'view' => 'flows'])
            ->and($other->getSignal('settings_defaultRange')?->string())->toBe('1h')
        ;
    });

    test('a preferences file that cannot be read is not replaced', function (): void {
        file_put_contents(Config::$prefsFile, '{"alerts": [');
        $c = settingsActionsTab($this->app, $this->states, 'ctx-broken');
        $c->getSignal('settings_defaultRange')?->setValue('1h');

        $scripts = settingsActionsSave($c, 'general');

        expect(file_get_contents(Config::$prefsFile))->toBe('{"alerts": [')
            ->and($scripts)->toContain('could not be read')
            ->and($scripts)->not->toContain('Settings saved.')
            ->and(Config::$settings->defaultRange)->toBe('30d')
        ;
    });

    test('without a preferences file a save keeps the rules and templates in effect', function (): void {
        unlink(Config::$prefsFile);
        $c = settingsActionsTab($this->app, $this->states, 'ctx-nofile');

        settingsActionsSave($c, 'rdns');
        $saved = UserPreferences::load(Config::$prefsFile);

        expect($saved?->alerts)->toHaveCount(1)
            ->and($saved?->defaultEmailSubjectTemplate)->toBe('subject {rule}')
            ->and($saved?->defaultWebhookMessageTemplate)->toBe('message {rule}')
            ->and($saved?->defaultRange)->toBe('30d')
            ->and(Config::$settings->alerts)->toHaveCount(1)
        ;
    });

    test('a save drops the cached read-only tabs: Storage shows the preferences file it created', function (): void {
        unlink(Config::$prefsFile);
        $c = settingsActionsTab($this->app, $this->states, 'ctx-cache');
        $before = SettingsPage::viewData($c, $this->app, $this->states, false)['storage']['prefsFileExists'];

        settingsActionsSave($c, 'rdns');
        $after = SettingsPage::viewData($c, $this->app, $this->states, true)['storage']['prefsFileExists'];

        expect($before)->toBeFalse()->and($after)->toBeTrue();
    });

    test('the saved theme is read once, and a save keeps what it wrote', function (): void {
        $first = settingsActionsTab($this->app, $this->states, 'ctx-theme-1');
        file_put_contents(Config::$prefsFile, json_encode(['theme' => 'light', ...array_diff_key(settingsActionsSaved(), ['theme' => true])], JSON_THROW_ON_ERROR));
        $second = settingsActionsTab($this->app, $this->states, 'ctx-theme-2');
        $seeded = $second->getSignal('settings_theme')?->string();
        $second->getSignal('settings_theme')?->setValue('system');

        settingsActionsSave($second, 'general');
        $third = settingsActionsTab($this->app, $this->states, 'ctx-theme-3');

        expect($first->getSignal('settings_theme')?->string())->toBe('dark')
            ->and($seeded)->toBe('dark')
            ->and(UserPreferences::load(Config::$prefsFile)?->theme)->toBe('system')
            ->and($third->getSignal('settings_theme')?->string())->toBe('system')
        ;
    });

    test('two saves at once both land: the later one starts from what the earlier one wrote', function (): void {
        if (!extension_loaded('openswoole')) {
            $this->markTestSkipped('needs the openswoole extension');
        }
        $general = settingsActionsTab($this->app, $this->states, 'ctx-race-general');
        $rdns = settingsActionsTab($this->app, $this->states, 'ctx-race-rdns');
        $general->getSignal('settings_defaultRange')?->setValue('1h');
        $rdns->getSignal('settings_rdnsEnabled')?->setValue(true);

        // Coroutine::run() turns on the file hook, so each save yields on its read and write.
        $scripts = [];
        Coroutine::run(static function () use ($general, $rdns, &$scripts): void {
            foreach (['general' => $general, 'rdns' => $rdns] as $scope => $c) {
                Coroutine::create(static function () use ($c, $scope, &$scripts): void {
                    $scripts[$scope] = settingsActionsSave($c, $scope);
                });
            }
        });
        $saved = UserPreferences::load(Config::$prefsFile);

        expect($scripts['general'] ?? '')->toContain('Settings saved.')
            ->and($scripts['rdns'] ?? '')->toContain('Settings saved.')
            ->and($saved?->defaultRange)->toBe('1h')
            ->and($saved?->rdnsEnabled)->toBeTrue()
            ->and(Config::$settings->defaultRange)->toBe('1h')
            ->and(Config::$settings->rdnsEnabled)->toBeTrue()
        ;
    });
});

describe('SettingsPage', function (): void {
    beforeEach(function (): void {
        $this->settingsBefore = (new ReflectionProperty(Config::class, 'settings'))->isInitialized() ? Config::$settings : null;
        $this->prefsBefore = (new ReflectionProperty(Config::class, 'prefsFile'))->isInitialized() ? Config::$prefsFile : null;
        $this->stateBefore = (new ReflectionProperty(Config::class, 'stateDir'))->isInitialized() ? Config::$stateDir : null;
        $this->settingsFileBefore = Config::$settingsFileLoaded;
        $this->nfsenConfigBefore = $GLOBALS['nfsen_config'] ?? null;
        $this->filtersBefore = Config::$deploymentFilters;

        $this->stateDir = sys_get_temp_dir() . '/nfsen-settings-page-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir . '/profiles/live/gw1/2026', 0o777, true);

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [80, 443]],
            'nfdump' => ['profiles-data' => $this->stateDir . '/profiles', 'profile' => 'live'],
            'frontend' => ['defaults' => ['theme' => 'dark', 'view' => 'statistics', 'graphs' => ['protocols' => ['udp', 'tcp']]]],
        ]);
        Config::$prefsFile = $this->stateDir . '/preferences.json';
        Config::$stateDir = $this->stateDir;
        Config::$settingsFileLoaded = null;
        GeoIpDatabase::resetShared();
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
        if ($this->stateBefore !== null) {
            Config::$stateDir = $this->stateBefore;
        }
        Config::$settingsFileLoaded = $this->settingsFileBefore;
        Config::$deploymentFilters = $this->filtersBefore;
        $GLOBALS['nfsen_config'] = $this->nfsenConfigBefore;
        GeoIpDatabase::resetShared();
        putenv('NFSEN_NETBOX_TOKEN');
        putenv('NFSEN_IPINFO_TOKEN');
        putenv('NFSEN_RRD_PATH');
        putenv('NFSEN_NFDUMP_MAX_PROCESSES');
        removeTree($this->stateDir);
    });

    test('the form signals hold page ids, one protocol and the saved theme', function (): void {
        $values = SettingsPage::signalValues(Config::$settings, '');

        expect($values['settings_defaultView'])->toBe('talkers')
            ->and($values['settings_graphProtocols'])->toBe('udp')
            ->and($values['settings_theme'])->toBe('')
            ->and($values['settings_logPriority'])->toBe('info')
            ->and($values['settings_defaultRange'])->toBe('24h')
            ->and($values['settings_defaultUnit'])->toBe('bits')
            ->and($values['settings_compactTables'])->toBeFalse()
            ->and($values['settings_rdnsEnabled'])->toBeTrue()
            ->and(SettingsPage::signalValues(Config::$settings->withDefaultGraphProtocols([]), 'auto')['settings_graphProtocols'])->toBe('any')
            ->and(SettingsPage::signalValues(Config::$settings, 'auto')['settings_theme'])->toBe('system')
        ;
    });

    test('the theme select names the deployment default it stands for', function (): void {
        $themes = SettingsPage::choices(Config::$settings)['themes'];
        $auto = SettingsPage::choices(Settings::fromArray(['frontend' => ['defaults' => ['theme' => 'auto']]]))['themes'];

        expect($themes)->toBe(['' => 'Deployment default (Dark)', 'system' => 'System', 'light' => 'Light', 'dark' => 'Dark'])
            ->and($auto[''])->toBe('Deployment default (System)')
        ;
    });

    test('the lists show a saved value the form does not offer', function (): void {
        $plain = SettingsPage::choices(Config::$settings);
        $odd = SettingsPage::choices(Config::$settings->withLogPriority(LOG_CRIT)->withDefaultFlowLimit(250)->withDefaultView('settings'));

        expect(array_keys($plain['views']))->toBe(SettingsPage::VIEWS)
            ->and($plain['views']['talkers'])->toBe('Top Talkers')
            ->and(array_keys($plain['logLevels']))->toBe(['debug', 'info', 'notice', 'warning', 'error'])
            ->and($plain['flowLimits'])->toBe(SettingsPage::FLOW_LIMITS)
            ->and($odd['logLevels'])->toHaveKey('crit')
            ->and($odd['flowLimits'])->toBe([20, 50, 100, 250, 500, 1000, 10000])
            ->and($odd['views'])->toHaveKey('settings')
        ;
    });

    test('System lists every EnvRegistry variable once, by group, with secrets masked', function (): void {
        putenv('NFSEN_NETBOX_TOKEN=very-secret-token');
        $groups = SettingsPage::system();
        $rows = array_merge(...array_column($groups, 'vars'));
        $byName = array_column($rows, null, 'name');

        expect(array_column($rows, 'name'))->toBe(EnvRegistry::names())
            ->and(array_column($groups, 'id'))->toBe(array_values(array_unique(array_map(static fn ($v): string => $v->group, EnvRegistry::table()))))
            ->and($byName['NFSEN_NETBOX_TOKEN']['value'])->toBe('***')
            ->and($byName['NFSEN_NETBOX_TOKEN']['set'])->toBeTrue()
            ->and(json_encode($groups))->not->toContain('very-secret-token')
            ->and($byName['NFSEN_IMPORT_YEARS']['value'])->toBe("'3'")
            ->and($byName['NFSEN_MCP_HTTP']['value'])->toBe("'false'")
        ;
        if (!EnvRegistry::isSet('NFSEN_IPINFO_TOKEN')) {
            expect($byName['NFSEN_IPINFO_TOKEN']['set'])->toBeFalse();
        }
    });

    test('Integrations: Netbox is configured only with both URL and token, and the token is masked', function (): void {
        $none = SettingsPage::integrations();
        Config::$settings = Settings::fromArray(['general' => ['netbox_url' => 'https://netbox.example', 'netbox_token' => 'tok-123']]);
        $both = SettingsPage::integrations();
        Config::$settings = Settings::fromArray(['general' => ['netbox_url' => 'https://netbox.example']]);
        $urlOnly = SettingsPage::integrations();

        expect($none['netbox']['configured'])->toBeFalse()
            ->and($both['netbox'])->toBe(['configured' => true, 'url' => 'https://netbox.example', 'token' => '***'])
            ->and($urlOnly['netbox']['configured'])->toBeFalse()
            ->and($none['email']['from'])->toBe('')
            ->and($none['geoip']['configured'])->toBeFalse()
            ->and($none['geoip']['active'])->toBeFalse()
            ->and($none['webService']['inUse'])->toBeTrue()
        ;
    });

    test('Integrations: a GeoIP path that cannot be opened is an error, and the web service stays in use', function (): void {
        Config::$settings = Config::$settings->withGeoipDb($this->stateDir . '/missing.mmdb');
        $geo = SettingsPage::integrations();

        expect($geo['geoip']['configured'])->toBeTrue()
            ->and($geo['geoip']['active'])->toBeFalse()
            ->and($geo['geoip']['path'])->toBe($this->stateDir . '/missing.mmdb')
            ->and($geo['geoip']['error'])->not->toBe('')
            ->and($geo['webService']['inUse'])->toBeTrue()
        ;
    });

    test('Sources lists each capture directory per detected profile and source', function (): void {
        $sources = SettingsPage::sources();

        expect($sources['profiles'])->toBe(['live'])
            ->and($sources['captureDirs'])->toBe([
                ['profile' => 'live', 'source' => 'gw1', 'path' => $this->stateDir . '/profiles/live/gw1', 'exists' => true],
                ['profile' => 'live', 'source' => 'gw2', 'path' => $this->stateDir . '/profiles/live/gw2', 'exists' => false],
            ])
            ->and($sources['profilesDataExists'])->toBeTrue()
            ->and($sources['sourcesOrigin'])->toBe(EnvRegistry::isSet('NFSEN_SOURCES') ? 'NFSEN_SOURCES' : 'default')
        ;
    });

    test('a tab opening Settings reads the read-only tabs fresh, an open one renders from the cache', function (): void {
        $app = new Via(new ViaConfig());
        $states = new PageStates();
        $gw2 = static fn (array $data): bool => $data['sources']['captureDirs'][1]['exists'];
        $open = settingsActionsTab($app, $states, 'ctx-open');
        $opened = $gw2(SettingsPage::viewData($open, $app, $states, true));

        mkdir($this->stateDir . '/profiles/live/gw2');
        $synced = $gw2(SettingsPage::viewData($open, $app, $states, true));
        $other = $gw2(SettingsPage::viewData(settingsActionsTab($app, $states, 'ctx-other'), $app, $states, true));

        expect([$opened, $synced, $other])->toBe([false, false, true]);
    });

    test('Storage: the RRD directory settings.php does not set comes from its variable', function (): void {
        putenv('NFSEN_RRD_PATH=/elsewhere/rrd');
        Config::$settingsFileLoaded = '/etc/nfsen-ng/settings.php';
        $GLOBALS['nfsen_config'] = ['general' => ['db' => 'RRD'], 'db' => ['RRD' => ['import_years' => 2]]];
        Config::$settings = Settings::fromArray($GLOBALS['nfsen_config']);
        $fileOnly = SettingsPage::storage();

        $GLOBALS['nfsen_config']['db']['RRD']['data_path'] = $this->stateDir;
        Config::$settings = Settings::fromArray($GLOBALS['nfsen_config']);
        $fileSets = SettingsPage::storage();

        Config::$settingsFileLoaded = null;
        Config::$settings = Settings::fromEnv();
        $envOnly = SettingsPage::storage();

        expect($fileOnly['location'])->toMatchArray(['value' => '/elsewhere/rrd', 'origin' => 'NFSEN_RRD_PATH'])
            ->and($fileOnly['importYearsOrigin'])->toBe('settings.php')
            ->and($fileSets['location'])->toMatchArray(['value' => $this->stateDir, 'exists' => true, 'origin' => 'settings.php'])
            ->and($envOnly['location'])->toMatchArray(['value' => '/elsewhere/rrd', 'exists' => false, 'origin' => 'NFSEN_RRD_PATH'])
        ;
    });

    test('System: In effect shows the values in use, with where each comes from', function (): void {
        putenv('NFSEN_NFDUMP_MAX_PROCESSES=4');
        Config::$settingsFileLoaded = '/etc/nfsen-ng/settings.php';
        $GLOBALS['nfsen_config'] = ['nfdump' => ['binary' => '/opt/nfdump/bin/nfdump'], 'frontend' => ['defaults' => ['theme' => 'dark']]];
        Config::$settings = Settings::fromArray($GLOBALS['nfsen_config']);
        Config::$deploymentFilters = ['proto tcp', 'proto udp'];
        $rows = array_column(SettingsPage::deployment(), null, 'label');

        expect($rows['nfdump binary'])->toBe(['label' => 'nfdump binary', 'value' => '/opt/nfdump/bin/nfdump', 'code' => true, 'origin' => 'settings.php'])
            ->and($rows['Parallel nfdump processes'])->toMatchArray(['value' => '4', 'origin' => 'NFSEN_NFDUMP_MAX_PROCESSES'])
            ->and($rows['Default theme'])->toMatchArray(['value' => 'Dark', 'origin' => 'settings.php'])
            ->and($rows['Statistics window limit']['value'])->toBe('Unlimited')
            ->and($rows['Filter presets']['value'])->toStartWith('2 expressions')
        ;
    });

    test('Storage reads the SQLite file without creating it', function (): void {
        $storage = SettingsPage::storage();
        $path = $this->stateDir . '/nfsen-ng.sqlite';

        expect($storage['sqlite']['path'])->toBe($path)
            ->and($storage['sqlite']['exists'])->toBeFalse()
            ->and(file_exists($path))->toBeFalse()
            ->and($storage['stateDirWritable'])->toBeTrue()
            ->and($storage['prefsFileExists'])->toBeFalse()
            ->and($storage['topnRetentionDays'])->toBe(Config::$settings->topnRetentionDays)
        ;
    });

    test('the page renders every tab without utility classes', function (): void {
        $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $c = new Context('ctx-settings', '/', $app);
        $states = new PageStates();
        $c->signal('browser', 'displayTz', clientWritable: true);
        SettingsPage::signals($c);
        SettingsPage::register($c, $app, $states);

        $html = $c->render('pages/settings.html.twig', [
            'pages' => ['settings' => ['active' => true, ...SettingsPage::viewData($c, $app, $states, false)]],
        ]);

        expect('<!DOCTYPE html><title>Settings</title>' . $html)->toBeValidHtml()
            ->and($html)->toContain(
                'id="settingsTab-general"',
                'id="settingsPanel-system"',
                '<option value="">Deployment default (Dark)</option>',
                'id="integrationNetbox"',
                '/_action/save-settings',
                '?scope=general',
                '?scope=rdns',
                'NFSEN_TOPN_RETENTION_DAYS',
                'id="settingsInEffect"',
                'scope="rowgroup"',
            )
            ->and($html)->not->toContain('colgroup')
            ->and(preg_match('/<dl\b[^>]*aria-labelledby/', $html))->toBe(0)
            ->and(preg_match('/class="[^"]*\b(muted|mono|strong|text-end|nowrap|upper|cluster-between|spacer|text-(danger|warning|success|info))\b/', $html))->toBe(0)
            ->and(preg_match('/[\x{2014}\x{2013}]/u', $html))->toBe(0)
            ->and(substr_count($html, 'role="tab"'))->toBe(5)
            ->and(substr_count($html, 'role="tabpanel"'))->toBe(5)
        ;
    });
});
