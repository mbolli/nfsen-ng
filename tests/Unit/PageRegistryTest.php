<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\ConversationsPage;
use mbolli\nfsen_ng\pages\FlowsPage;
use mbolli\nfsen_ng\pages\OverviewPage;
use mbolli\nfsen_ng\pages\Page;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\ShellModule;
use mbolli\nfsen_ng\pages\TrafficGraph;
use mbolli\nfsen_ng\processor\NfdumpException;
use mbolli\nfsen_ng\query\FlowsQuery;
use mbolli\nfsen_ng\query\MatrixQuery;
use mbolli\nfsen_ng\query\StatsQuery;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * The page handler of app.php against a Via with the real templates. The app is in its
 * fatal-error state, so rendering touches no datasource.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function pageRegistryTestCompose(): array {
    $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context('ctx-compose', '/', $app);
    $states = new PageStates();

    Shell::signals($c);
    foreach (PageRegistry::MODULES as $module) {
        $module::signals($c);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::signals($c);
    }
    Shell::register($c, $app, $states);
    foreach (PageRegistry::MODULES as $module) {
        $module::register($c, $app, $states);
    }
    foreach (PageRegistry::PAGES as $page) {
        $page::register($c, $app, $states);
    }

    return [$app, $c, $states];
}

describe('ids and lookups', function (): void {
    test('pages are listed in sidebar order', function (): void {
        expect(PageRegistry::ids())->toBe(['overview', 'talkers', 'flows', 'conversations', 'alerts', 'health', 'settings'])
            ->and(PageRegistry::find('flows'))->toBe(FlowsPage::class)
            ->and(PageRegistry::find('graphs'))->toBeNull()
            ->and(PageRegistry::find(''))->toBeNull()
        ;
    });

    test('every page and module implements its interface', function (): void {
        foreach (PageRegistry::PAGES as $page) {
            expect(is_subclass_of($page, Page::class))->toBeTrue();
        }
        foreach (PageRegistry::MODULES as $module) {
            expect(is_subclass_of($module, ShellModule::class))->toBeTrue();
        }
    });

    test('the analysis pages are the four that show the traffic graph', function (): void {
        $analysis = array_values(array_filter(PageRegistry::ids(), PageRegistry::isAnalysis(...)));

        expect($analysis)->toBe(['overview', 'talkers', 'flows', 'conversations'])
            ->and(PageRegistry::isAnalysis('nope'))->toBeFalse()
        ;
    });

    test('the graph and the live window run for the analysis pages, and for every page until rendering is lazy', function (): void {
        foreach (PageRegistry::ids() as $id) {
            $analysis = PageRegistry::isAnalysis($id);

            expect(PageRegistry::rendersAnalysis($id))->toBe($analysis || !PageRegistry::lazy())
                ->and(TrafficGraph::mode($id))->toBe($analysis || !PageRegistry::lazy() ? 'overview' : 'none')
            ;
        }
    });

    test('meta carries what the sidebar and the page header need', function (): void {
        $meta = PageRegistry::meta();

        expect(array_column($meta, 'id'))->toBe(PageRegistry::ids())
            ->and(array_column($meta, 'group'))->toBe(['analysis', 'analysis', 'analysis', 'analysis', 'monitor', 'monitor', 'system'])
            ->and($meta[1])->toBe([
                'id' => 'talkers',
                'title' => 'Top Talkers',
                'lede' => 'Exact top N from nfdump for the selected range, sources and protocol.',
                'icon' => 'list-ranked',
                'group' => 'analysis',
                'analysis' => true,
            ])
        ;
        foreach ($meta as $page) {
            expect($page['title'])->not->toBe('')
                ->and($page['lede'])->toEndWith('.')
                ->and($page['icon'])->toMatch('/^[a-z-]+$/')
            ;
        }
    });
});

describe('legacy view ids (D2)', function (): void {
    test('every legacy view maps to its page', function (string $view, string $section, string $page): void {
        expect(PageRegistry::fromLegacy($view, $section))->toBe($page);
    })->with([
        ['graphs', '', 'overview'],
        ['statistics', '', 'talkers'],
        ['sankey', '', 'conversations'],
        ['investigate', '', 'flows'],
        ['flows', '', 'flows'],
        ['settings', 'import', 'health'],
        ['settings', 'health', 'health'],
        ['settings', 'alerts', 'alerts'],
        ['settings', 'preferences', 'settings'],
        ['settings', 'system', 'settings'],
        ['settings', '', 'settings'],
        [' Graphs ', '', 'overview'],
    ]);

    test('page ids map to themselves and unknown views to nothing', function (): void {
        foreach (PageRegistry::ids() as $id) {
            expect(PageRegistry::fromLegacy($id))->toBe($id);
        }
        expect(PageRegistry::fromLegacy('dashboard'))->toBe('')
            ->and(PageRegistry::fromLegacy(''))->toBe('')
        ;
    });

    test('Settings::normalizeView agrees with the registry for every stored value', function (string $value): void {
        $page = PageRegistry::fromLegacy($value);

        expect(Settings::normalizeView($value))->toBe($page !== '' ? $page : 'overview');
    })->with(['graphs', 'statistics', 'sankey', 'investigate', 'flows', 'settings', 'overview', 'talkers', 'conversations', 'alerts', 'health', 'bogus', '']);

    test('the old layout gets its own view id back', function (): void {
        expect(array_map(PageRegistry::toLegacy(...), PageRegistry::ids()))
            ->toBe(['graphs', 'statistics', 'flows', 'sankey', 'settings', 'settings', 'settings'])
            ->and(PageRegistry::toLegacy('graphs'))->toBe('graphs')
            ->and(PageRegistry::toLegacy('bogus'))->toBe('graphs')
        ;
    });
});

describe('query kinds', function (): void {
    test('every query_kind routes to the page that owns it', function (string $kind, string $page): void {
        expect(PageRegistry::pageForKind($kind))->toBe($page);
    })->with([
        ['graph', 'overview'],
        ['overview-topn', 'overview'],
        ['stats', 'talkers'],
        ['talkers-panel', 'talkers'],
        ['flows', 'flows'],
        ['flows-summary', 'flows'],
        ['flowsgraph', 'flows'],
        ['conversations', 'conversations'],
    ]);

    test('an unknown kind routes nowhere', function (): void {
        expect(PageRegistry::pageForKind(''))->toBeNull()
            ->and(PageRegistry::pageForKind('sankey'))->toBeNull()
        ;
    });

    test('the states follow the pages that keep results', function (): void {
        $states = new PageStates();

        expect(array_keys($states->all()))->toBe(['shell', 'overview', 'talkers', 'flows', 'conversations'])
            ->and($states->for('flows'))->toBe($states->flows)
            ->and($states->for('shell'))->toBe($states->shell)
            ->and($states->for('alerts'))->toBeNull()
            ->and($states->for(''))->toBeNull()
        ;
    });
});

describe('composition', function (): void {
    beforeEach(function (): void {
        $settings = new ReflectionProperty(Config::class, 'settings');
        $prefs = new ReflectionProperty(Config::class, 'prefsFile');
        $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
        $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [80, 443]],
            'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-page-registry-test-missing', 'profile' => 'live'],
            'frontend' => ['defaults' => ['view' => 'statistics', 'graphs' => ['protocols' => ['tcp', 'udp']]]],
        ]);
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-page-registry-test-missing.json';
    });

    afterEach(function (): void {
        if ($this->settingsBefore !== null) {
            Config::$settings = $this->settingsBefore;
        }
        if ($this->prefsBefore !== null) {
            Config::$prefsFile = $this->prefsBefore;
        }
    });

    test('the page handler declares every signal the old one did, plus the new globals', function (): void {
        [, $c] = pageRegistryTestCompose();
        $signals = array_keys($c->getNamedSignals());
        $old = ['datestart', 'dateend', 'data_range_min', 'data_range_max', '_error', 'graph_display', 'graph_sources',
            'graph_ports', 'graph_protocols', 'graph_datatype', 'graph_trafficUnit', 'graph_resolution', 'graph_mode',
            'graph_filter', 'graph_isLive', 'graph_actualResolution', 'graph_lastUpdate', 'query_running',
            'query_permille', 'query_status', 'query_eta', 'query_exact', 'query_kind', 'flows_filter',
            'flows_graph_shown', 'flows_graph_unit', 'flows_graph_key', 'flows_graph_fingerprint', 'flows_limit',
            'flows_agg_bidirectional', 'flows_agg_proto', 'flows_agg_srcport', 'flows_agg_dstport', 'flows_agg_srcip',
            'flows_agg_srcip_prefix', 'flows_agg_dstip', 'flows_agg_dstip_prefix', 'flows_orderByTstart',
            'flows_lower_limit', 'flows_upper_limit', 'flows_count', 'stats_filter', 'stats_count', 'stats_for',
            'stats_orderBy', 'stats_lower_limit', 'stats_upper_limit', 'stats_agg_bidirectional', 'stats_agg_proto',
            'stats_agg_srcport', 'stats_agg_dstport', 'stats_agg_srcip', 'stats_agg_srcip_prefix', 'stats_agg_dstip',
            'stats_agg_dstip_prefix', 'nfcapd_file_count', 'nfcapd_total_bytes', 'nfcapd_measured', 'sankey_filter',
            'sankey_topN', 'sankey_metric', 'sankey_show_ports', 'sankey_lower_limit', 'sankey_upper_limit',
            'selected_profile', 'available_profiles', 'admin_target_profile', 'import_running', 'confirm_rescan',
            'import_scan_ports', 'settings_defaultView', 'settings_graphDisplay', 'settings_graphDatatype',
            'settings_graphProtocols', 'settings_flowLimit', 'settings_statsOrderBy', 'settings_filtersText',
            'settings_logPriority', 'settings_defaultEmailSubjectTemplate', 'settings_defaultEmailBodyTemplate',
            'settings_defaultWebhookTitleTemplate', 'settings_defaultWebhookMessageTemplate', 'serverTz', 'nfcapdTz',
            'displayTz', 'alert_form_id', 'alert_form_name', 'alert_form_enabled', 'alert_form_profile',
            'alert_form_sources', 'alert_form_metric', 'alert_form_operator', 'alert_form_thresholdType',
            'alert_form_thresholdValue', 'alert_form_avgWindow', 'alert_form_cooldownSlots', 'alert_form_notifyEmail',
            'alert_form_emailSubjectTemplate', 'alert_form_emailBodyTemplate', 'alert_form_notifyWebhook',
            'alert_form_webhookTitleTemplate', 'alert_form_webhookMessageTemplate', 'alert_form_nfdumpFilter'];

        expect(array_diff($old, $signals))->toBe([])
            ->and($signals)->toContain('page', 'range_preset', 'range_live', 'protocol', '_flt_flows', '_est_overview_topn')
            ->and($signals)->not->toContain('_flt_overview_topn', '_est_alert')
        ;
    });

    test('the globals are seeded from the preferences', function (): void {
        [, $c] = pageRegistryTestCompose();

        expect($c->getSignal('page')?->string())->toBe('talkers')
            ->and($c->getSignal('page')?->hasChanged())->toBeFalse()
            ->and($c->getSignal('protocol')?->string())->toBe('any')
            ->and($c->getSignal('range_preset')?->string())->toBe('24h')
            ->and($c->getSignal('range_live')?->bool())->toBeTrue()
            ->and($c->getSignal('graph_sources')?->array())->toBe(['gw1', 'gw2'])
            ->and($c->getSignal('graph_trafficUnit')?->string())->toBe('bits')
            ->and($c->getSignal('dateend')?->int() - $c->getSignal('datestart')?->int())->toBe(86400)
            ->and($c->getSignal('settings_defaultView')?->string())->toBe('statistics')
            ->and($c->getSignal('selected_profile')?->string())->toBe('live')
        ;
    });

    test('the graph protocols preference does not narrow the queries while no control shows the protocol', function (): void {
        [, $c] = pageRegistryTestCompose();
        $protocol = RangeControls::protocol($c);
        $window = TimeWindow::raw(1_000, 2_000);

        expect($protocol)->toBe('any')
            ->and((new FlowsQuery($window, ['gw1'], 'live', 20, 'host 10.0.0.1', protocol: $protocol))->effectiveFilter())->not->toContain('proto')
            ->and((new StatsQuery($window, ['gw1'], 'live', 'srcip', 'bytes', 10, 'host 10.0.0.1', protocol: $protocol))->effectiveFilter())->not->toContain('proto')
            ->and((new MatrixQuery($window, ['gw1'], 'live', 'bytes', 10, filter: 'host 10.0.0.1', protocol: $protocol))->effectiveFilter())->not->toContain('proto')
        ;
    });

    test('the footer status cache drops expired profiles, so client-posted names cannot pile up', function (): void {
        [$app] = pageRegistryTestCompose();
        Config::$settings = Config::$settings->withSources([]);
        $now = time();
        $app->setGlobalState(Shell::STATUS_CACHE, [
            'bogus' => ['ts' => $now - Shell::STATUS_TTL, 'sources' => []],
            'recent' => ['ts' => $now - 1, 'sources' => []],
        ]);

        (new ReflectionMethod(Shell::class, 'captureSources'))->invoke(null, $app, 'live', $now);

        expect(array_keys($app->globalState(Shell::STATUS_CACHE, [])))->toBe(['recent', 'live']);
    });

    test('the live window follows the clock on analysis pages, and the data range keeps its own import throttle (1.7)', function (): void {
        [$app, $c, $states] = pageRegistryTestCompose();
        $app->setGlobalState('_fatalError', null);
        // Without sources the data range read returns early, so no datasource is touched.
        Config::$settings = Config::$settings->withSources([]);
        $now = time();
        $renderWith = static function (string $page) use ($app, $c, $states, $now): int {
            $c->getSignal('datestart')?->setValue($now - 3_720, broadcast: false);
            $c->getSignal('dateend')?->setValue($now - 120, broadcast: false);
            RangeControls::viewData($c, $app, $states, true, $page);

            return (int) $c->getSignal('dateend')?->int();
        };

        $onHealth = $renderWith('health');
        $onOverview = $renderWith('overview');
        $width = $c->getSignal('dateend')?->int() - $c->getSignal('datestart')?->int();
        $c->getSignal('import_running')?->setValue(true, broadcast: false);
        $importing = $renderWith('overview');

        expect($onHealth >= $now)->toBe(!PageRegistry::lazy())
            ->and($onOverview)->toBeGreaterThanOrEqual($now)
            ->and($width)->toBe(3_600)
            ->and($states->shell->rangeFetchedAt)->toBeGreaterThanOrEqual($now)
            // Throttled by the data range's own read, although no graph was ever fetched.
            ->and($states->shell->graphFetchedAt)->toBe(0)
            ->and($importing)->toBe($now - 120)
        ;
    });

    test('the first SSE sync pushes the new signals but not page, which the client owns (1.2)', function (): void {
        // The array-backed patch queue php-via uses outside a server.
        putenv('VIA_TEST_MODE=1');

        try {
            [$app, $c, $states] = pageRegistryTestCompose();
        } finally {
            putenv('VIA_TEST_MODE');
        }
        $c->view(static function (bool $isUpdate) use ($c, $app, $states): string {
            Shell::render($c, $app, $states, $isUpdate);

            return '<main id="app"></main>';
        }, cacheUpdates: false);

        $c->renderView();
        $c->sync();
        $pushed = [];
        while (($patch = $c->getPatch()) !== null) {
            if ($patch['type'] === 'signals' && is_array($patch['content'])) {
                $pushed = [...$pushed, ...$patch['content']];
            }
        }

        $wireId = static fn (string $name): string => $c->getSignal($name)?->id() ?? $name;

        expect($pushed)->toHaveKeys(array_map($wireId, ['protocol', 'range_preset', 'range_live', 'datestart']))
            ->and($pushed)->not->toHaveKey($wireId('page'))
        ;
    });

    test('every action is registered by a page or the shell', function (): void {
        [, $c] = pageRegistryTestCompose();
        $actions = array_keys($c->getNamedActions());

        expect($actions)->toContain(
            'navigate',
            'dismiss-notification',
            'count-files',
            'ip-info',
            'kill-nfdump',
            'change-profile',
            'run-filtered-graph',
            'refresh-graphs',
            'stats-actions',
            'flow-actions',
            'build-flows-graph',
            'touch-flows-graph',
            'sankey-actions',
            'save-alert',
            'delete-alert',
            'toggle-alert',
            'test-alert',
            'trigger-import',
            'backfill-import',
            'force-rescan',
            'cancel-import',
            'save-settings',
        )->and($actions)->not->toContain('dismiss-sankey-notification');
    });

    test('the render array carries the 1.5 contract, and old top-level keys for the shell and the active page only', function (): void {
        [$app, $c, $states] = pageRegistryTestCompose();
        $data = Shell::render($c, $app, $states, false);

        expect($data)->toHaveKeys(['shell', 'range', 'graph', 'querykit', 'drawer', 'pages'])
            ->and($data)->not->toHaveKey(Shell::LEGACY)
            ->and(array_keys($data['pages']))->toBe(PageRegistry::ids())
            ->and(array_keys(array_filter(array_map(static fn (array $p): bool => $p['active'], $data['pages']))))->toBe(['talkers'])
            ->and($data['pages']['flows'])->toBe(['active' => false])
            ->and($data['pages']['health'])->toBe(['active' => false])
            ->and($data['shell']['activePage'])->toBe('talkers')
            ->and($data['shell']['isAnalysis'])->toBeTrue()
            ->and($data['shell']['pagesMeta'])->toBe(PageRegistry::meta())
            ->and($data['shell']['defaults'])->toBe(['view' => 'talkers', 'theme' => 'auto', 'density' => 'comfortable'])
            ->and($data['shell']['healthLevel'])->toBe('unknown')
            ->and($data['shell']['healthIssues'])->toBe(0)
            ->and($data['shell']['alertsFiring'])->toBe(0)
            ->and($data['graph']['mode'])->toBe('overview')
            ->and(array_column($data['range']['presets'], 'id'))->toBe(['1h', '24h', '7d', '30d', '1y'])
            ->and($data['querykit']['targets'])->toHaveKeys(['overview', 'overview-topn', 'talkers', 'flows', 'conversations', 'drawer', 'alert'])
            ->and($data)->toHaveKeys([
                'sources', 'ports', 'filters', 'deployDatasource', 'importProgress', 'importCurrentFile', 'importStatusText', 'importEta',
                'graphData', 'statsTableHtml', 'statsNotifications',
            ])
            // The shell's own keys moved under `shell`; other pages' keys only come with their page.
            ->and($data)->not->toHaveKeys(['version', 'defaults', 'captureStatus', 'alertFiredHtml', 'importSources'])
            ->and($data)->not->toHaveKeys(['flowTableHtml', 'sankeyData', 'filteredCost', 'healthChecks', 'alerts', 'deployImportYears'])
        ;
    });

    test('an inactive page renders no data, and a non-analysis page no graph (1.2)', function (): void {
        [$app, $c, $states] = pageRegistryTestCompose();
        $c->getSignal('page')?->setValue('health', broadcast: false);
        $data = Shell::render($c, $app, $states, true);

        expect($data['shell']['activePage'])->toBe('health')
            ->and($data['shell']['isAnalysis'])->toBeFalse()
            ->and($data['pages']['talkers'])->toBe(['active' => false])
            ->and($data['graph']['mode'])->toBe('none')
            ->and($data['graph']['data'])->toBe('')
            ->and($data)->toHaveKeys(['healthChecks', 'daemonsInfo', 'importLog'])
            ->and($data)->not->toHaveKey('statsTableHtml')
        ;
    });

    test('the result partials render from it, escaping nfdump\'s messages (D21)', function (string $partial, string $page): void {
        [$app, $c, $states] = pageRegistryTestCompose();
        $c->getSignal('page')?->setValue($page, broadcast: false);
        $state = $states->for($page);
        $state?->notifyFailure(new NfdumpException("Unknown protocol: <b>x</b> at '\"<b>x</b>\"'", "nfdump -M /data -- 'proto \"<b>x</b>\"'"));

        $html = $c->render("partials/{$partial}.html.twig", Shell::render($c, $app, $states, false));

        expect($html)->not->toContain('<b>x</b>')
            ->and($html)->toContain(
                'Error: Unknown protocol: &lt;b&gt;x&lt;/b&gt;',
                '<code>nfdump -M /data -- &#039;proto &quot;&lt;b&gt;x&lt;/b&gt;&quot;&#039;</code>',
                "?page={$page}&id={$state?->notifications[0]['id']}')",
            )
        ;
    })->with([
        ['flow-view', 'flows'],
        ['stats-view', 'talkers'],
        ['sankey-view', 'conversations'],
    ]);

    test('a page render marks every state rendered, so unchanged results are not re-sent', function (): void {
        [$app, $c, $states] = pageRegistryTestCompose();
        $page = $c->getSignal('page');
        $page?->setValue('flows', broadcast: false);
        $states->flows->setResult('<table></table>', 1);

        $first = Shell::render($c, $app, $states, false);
        $second = Shell::render($c, $app, $states, true);
        $states->flows->setResult('<table></table>', 1);
        $third = Shell::render($c, $app, $states, true);
        $page?->setValue('health', broadcast: false);
        Shell::render($c, $app, $states, true);
        $page?->setValue('flows', broadcast: false);
        $back = Shell::render($c, $app, $states, true);
        $page?->setValue('conversations', broadcast: false);
        $conversations = Shell::render($c, $app, $states, true);

        expect($first['pages']['flows']['result']['send'])->toBeTrue()
            ->and($second['pages']['flows']['result']['send'])->toBeFalse()
            ->and($third['pages']['flows']['result']['send'])->toBeTrue()
            // Its section held a skeleton in between, so the result is sent again.
            ->and($back['pages']['flows']['result']['send'])->toBeTrue()
            ->and($conversations['pages'][ConversationsPage::id()]['result']['send'])->toBeTrue()
            ->and($conversations['pages'][OverviewPage::id()])->toBe(['active' => false])
        ;
    });
});
