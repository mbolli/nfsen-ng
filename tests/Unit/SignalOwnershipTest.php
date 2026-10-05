<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** The server-owned TAB signals: php-via ignores the browser's copy of these (clientWritable: false). */
const SIGNAL_OWNERSHIP_SERVER = [
    '_error', 'import_running', 'serverTz', 'nfcapdTz',
    'query_running', 'query_permille', 'query_status', 'query_eta', 'query_exact', 'query_kind',
    'data_range_min', 'data_range_max', 'available_profiles',
    '_flt_overview', '_flt_talkers', '_flt_flows', '_flt_conversations', '_flt_alert', '_flt_drawer',
    '_est_overview', '_est_overview_topn', '_est_talkers', '_est_flows', '_est_conversations', '_est_drawer',
    '_drawer_imported', '_drawer_notice',
    'graph_isLive', 'graph_actualResolution', 'graph_lastUpdate', 'graph_step', '_ov_topn_pending', '_ov_exact_rows',
    '_stats_rows', 'flows_count', 'flows_count_label', '_est_flows_summary', '_conv_stale', '_conv_pairs',
];

/** A context with every signal and action of app.php's page handler, as a new tab or a revival gets it. */
function signalOwnershipCompose(string $id = 'ctx-ownership'): Context {
    $app = new Via(new ViaConfig()->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates')->withLogLevel('error'));
    $app->setGlobalState('_fatalError', 'No datasource in this test.');
    $c = new Context($id, '/', $app);
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

    return $c;
}

/** A value of another type than the signal holds, as a stale or forged post would carry. */
function signalOwnershipOther(mixed $value): mixed {
    return match (true) {
        is_bool($value) => !$value,
        is_int($value) => $value + 7,
        is_string($value) => $value . 'x',
        default => 'posted',
    };
}

beforeEach(function (): void {
    $settings = new ReflectionProperty(Config::class, 'settings');
    $prefs = new ReflectionProperty(Config::class, 'prefsFile');
    $this->settingsBefore = $settings->isInitialized() ? Config::$settings : null;
    $this->prefsBefore = $prefs->isInitialized() ? Config::$prefsFile : null;

    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1', 'gw2'], 'ports' => [80, 443]],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-signal-ownership-missing', 'profile' => 'live'],
    ]);
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-signal-ownership-missing.json';
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->prefsBefore !== null) {
        Config::$prefsFile = $this->prefsBefore;
    }
});

describe('TAB signal ownership', function (): void {
    test('exactly the listed signals are server-owned', function (): void {
        $c = signalOwnershipCompose();
        $owned = array_keys(array_filter($c->getNamedSignals(), static fn ($signal): bool => !$signal->isClientWritable()));

        expect($owned)->toEqualCanonicalizing(SIGNAL_OWNERSHIP_SERVER);
    });

    test('a post never overwrites a server-owned signal, and the next sync sends the server value back', function (): void {
        $c = signalOwnershipCompose();
        $posted = [];
        $before = [];
        foreach (SIGNAL_OWNERSHIP_SERVER as $name) {
            $signal = $c->getSignal($name);
            $signal?->markSynced();
            $before[$name] = $signal?->getValue();
            $posted[(string) $signal?->id()] = signalOwnershipOther($before[$name]);
        }

        $c->injectSignals($posted);

        foreach (SIGNAL_OWNERSHIP_SERVER as $name) {
            expect($c->getSignal($name)?->getValue())->toBe($before[$name], "{$name} keeps the server value")
                ->and($c->getSignal($name)?->hasChanged())->toBeTrue("{$name} is sent again")
            ;
        }
    });

    test('the browser\'s copy still reaches the signals it writes', function (): void {
        $c = signalOwnershipCompose();
        $c->injectSignals([
            (string) $c->getSignal('flows_filter')?->id() => 'proto udp',
            (string) $c->getSignal('graph_display')?->id() => 'protocols',
            (string) $c->getSignal('page')?->id() => 'flows',
        ]);

        expect($c->getSignal('flows_filter')?->string())->toBe('proto udp')
            ->and($c->getSignal('graph_display')?->string())->toBe('protocols')
            ->and($c->getSignal('page')?->string())->toBe('flows')
        ;
    });

    // A revival rebuilds the context under the same id and injects what the browser still holds.
    test('a revived tab keeps its pinned window and built flows graph, but not a query that ran before', function (): void {
        $c = signalOwnershipCompose('ctx-revived');
        $held = [
            'query_running' => true,
            'query_kind' => 'flows',
            'query_status' => 'Read 1 GiB of 4 GiB',
            'range_live' => false,
            'range_preset' => 'custom',
            'datestart' => 1_780_000_000,
            'dateend' => 1_780_086_400,
            'flows_graph_key' => 'graph-key',
            'flows_graph_fingerprint' => 'graph-fingerprint',
        ];
        $posted = [];
        foreach ($held as $name => $value) {
            $posted[(string) $c->getSignal($name)?->id()] = $value;
        }

        $c->injectSignals($posted);

        expect($c->getSignal('query_running')?->bool())->toBeFalse()
            ->and($c->getSignal('query_kind')?->string())->toBe('')
            ->and($c->getSignal('query_status')?->string())->toBe('')
            ->and($c->getSignal('range_live')?->bool())->toBeFalse()
            ->and($c->getSignal('range_preset')?->string())->toBe('custom')
            ->and($c->getSignal('datestart')?->int())->toBe(1_780_000_000)
            ->and($c->getSignal('dateend')?->int())->toBe(1_780_086_400)
            ->and($c->getSignal('flows_graph_key')?->string())->toBe('graph-key')
            ->and($c->getSignal('flows_graph_fingerprint')?->string())->toBe('graph-fingerprint')
        ;
    });
});
