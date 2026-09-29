<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\QueryKitActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\query\Estimate;
use mbolli\nfsen_ng\query\QueryEstimator;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\QueryRunRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/** 2024-01-01 00:00 UTC, a multiple of 300. */
const QUERY_KIT_BASE = 1_704_067_200;

/**
 * The page handler's signals and actions on a Via with the real templates. VIA_TEST_MODE gives
 * the context an array patch queue, so pushed patches can be read back.
 *
 * @return array{0: Via, 1: Context}
 */
function queryKitCompose(): array {
    putenv('VIA_TEST_MODE=1');

    try {
        $app = new Via(new ViaConfig()->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $app->setGlobalState('_fatalError', 'No datasource in this test.');
        $c = new Context('ctx-query-kit', '/', $app);
    } finally {
        putenv('VIA_TEST_MODE');
    }
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

    return [$app, $c];
}

/** One capture file per interval of [$from, $to) under <root>/live/<source>, in the nfcapd timezone. */
function queryKitCaptures(string $root, string $source, int $from, int $to, int $bytes): void {
    for ($ts = $from; $ts < $to; $ts += 300) {
        $dt = new DateTimeImmutable('@' . $ts)->setTimezone(Config::nfcapdTimezone());
        $dir = $root . '/live/' . $source . '/' . $dt->format('Y/m/d');
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($dir . '/nfcapd.' . $dt->format('YmdHi'), str_repeat('x', $bytes));
    }
}

function queryKitRemoveTree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($dir);
}

/**
 * Signal patches queued for the client, merged in order.
 *
 * @return list<array<string, mixed>>
 */
function queryKitSignalPatches(Context $c): array {
    $patches = [];
    while (($patch = $c->getPatch()) !== null) {
        if ($patch['type'] === 'signals' && is_array($patch['content'])) {
            $patches[] = $patch['content'];
        }
    }

    return $patches;
}

/**
 * Runs $fn inside a coroutine, as the server does, until every coroutine it started ends. An
 * earlier test may have left the runtime hooks on, and a hooked call outside one throws.
 */
function queryKitRun(Closure $fn): void {
    Coroutine::run($fn);
}

/** As after a sync: nothing pending, nothing queued. */
function queryKitSettle(Context $c): void {
    foreach ($c->getNamedSignals() as $signal) {
        $signal->markSynced();
    }
    queryKitSignalPatches($c);
}

/** The signal values php-via seeds a new tab's document with, from its <meta data-signals__ifmissing>. */
function queryKitSeed(string $html): array {
    expect(preg_match('/<meta data-signals__ifmissing="([^"]*)">/', $html, $m))->toBe(1);

    return json_decode(html_entity_decode($m[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    $this->prefsBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    $this->root = sys_get_temp_dir() . '/nfsen-query-kit-' . bin2hex(random_bytes(6));
    mkdir($this->root, 0o777, true);
    Config::$settings = Settings::fromArray([
        'general' => ['ports' => [80], 'sources' => ['gw', 'edge'], 'db' => 'Rrd', 'processor' => 'Nfdump', 'max_stats_window' => 3600],
        'nfdump' => ['binary' => '/usr/bin/nfdump', 'profiles-data' => $this->root, 'profile' => 'live', 'max-processes' => 4],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$prefsFile = $this->root . '/missing-preferences.json';
    // No store: estimates use the default throughput.
    Config::$stateDir = '';
    Database::resetShared();
    QueryEstimator::resetCache();
    (new ReflectionProperty(FilterValidator::class, 'cache'))->setValue(null, []);

    [$this->app, $this->c] = queryKitCompose();
    $this->c->getSignal('selected_profile')?->setValue('live', broadcast: false);
    $this->c->getSignal('available_profiles')?->setValue(['live'], broadcast: false);
});

afterEach(function (): void {
    QueryEstimator::resetCache();
    Database::resetShared();
    Config::$stateDir = $this->stateDirBefore ?? '';
    if ($this->prefsBefore !== null) {
        Config::$prefsFile = $this->prefsBefore;
    }
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    queryKitRemoveTree($this->root);
});

describe('target table (3.5.3)', function (): void {
    test('every target names its filter signal, estimate kind and clamping', function (): void {
        expect(QueryKit::TARGETS)->toBe([
            'overview' => ['filter' => 'graph_filter', 'kind' => 'graph', 'clamped' => true],
            'overview-topn' => ['filter' => '', 'kind' => 'overview-topn', 'clamped' => true],
            'talkers' => ['filter' => 'stats_filter', 'kind' => 'stats', 'clamped' => true],
            'flows' => ['filter' => 'flows_filter', 'kind' => 'flows', 'clamped' => false],
            'conversations' => ['filter' => 'sankey_filter', 'kind' => 'conversations', 'clamped' => true],
            'drawer' => ['filter' => 'drawer_filter', 'kind' => 'drawer', 'clamped' => true],
            'alert' => ['filter' => 'alert_form_nfdumpFilter', 'kind' => '', 'clamped' => false],
        ]);
    });

    test('signals exist for validating and estimating targets only, with _ for -', function (): void {
        $signals = array_keys($this->c->getNamedSignals());

        expect($signals)->toContain('_flt_overview', '_flt_talkers', '_flt_flows', '_flt_conversations', '_flt_drawer', '_flt_alert')
            ->and($signals)->toContain('_est_overview', '_est_overview_topn', '_est_talkers', '_est_flows', '_est_conversations', '_est_drawer')
            ->and($signals)->not->toContain('_flt_overview_topn', '_est_alert', '_flt_overview-topn')
            ->and(QueryKit::estimateSignal('overview-topn'))->toBe('_est_overview_topn')
        ;
    });

    test('every estimating target resolves to a kind the estimator knows; the drawer borrows its target', function (): void {
        foreach (['overview', 'overview-topn', 'talkers', 'flows', 'conversations'] as $target) {
            expect(QueryKitActions::estimateTarget($this->c, $target))->toBe($target)
                ->and(QueryEstimator::DEFAULT_THROUGHPUT)->toHaveKey(QueryKit::TARGETS[$target]['kind'])
            ;
        }
        expect(QueryKitActions::estimateTarget($this->c, 'alert'))->toBeNull()
            ->and(QueryKitActions::estimateTarget($this->c, 'nope'))->toBeNull()
            // A drawer for no target yet, or for an alert rule, has no estimate.
            ->and(QueryKitActions::estimateTarget($this->c, 'drawer'))->toBeNull()
        ;

        $drawerTarget = $this->c->getSignal('drawer_target') ?? throw new LogicException('drawer_target is not declared');
        $drawerTarget->setValue('flows');
        expect(QueryKitActions::estimateTarget($this->c, 'drawer'))->toBe('flows');
        $drawerTarget->setValue('alert');
        expect(QueryKitActions::estimateTarget($this->c, 'drawer'))->toBeNull();
        $drawerTarget->setValue('drawer');
        expect(QueryKitActions::estimateTarget($this->c, 'drawer'))->toBeNull();
    });
});

describe('clamping per target', function (): void {
    test('only Flows reads the raw window; the rest are shortened to NFSEN_MAX_STATS_WINDOW', function (): void {
        $start = QUERY_KIT_BASE;
        $end = QUERY_KIT_BASE + 86_400;
        $this->c->getSignal('datestart')?->setValue($start, broadcast: false);
        $this->c->getSignal('dateend')?->setValue($end, broadcast: false);

        foreach (['overview', 'overview-topn', 'talkers', 'flows', 'conversations'] as $target) {
            $plan = QueryKitActions::plan($this->c, $target);
            $clamped = QueryKit::TARGETS[$target]['clamped'];

            expect($plan)->not->toBeNull()
                ->and($plan['window']->clamped)->toBe($clamped)
                ->and($plan['window']->end)->toBe($end)
                ->and($plan['window']->start)->toBe($clamped ? $end - 3600 : $start)
                ->and($plan['kind'])->toBe(QueryKit::TARGETS[$target]['kind'])
            ;
        }
    });

    test('the drawer takes its target\'s kind and clamping', function (): void {
        $this->c->getSignal('datestart')?->setValue(QUERY_KIT_BASE, broadcast: false);
        $this->c->getSignal('dateend')?->setValue(QUERY_KIT_BASE + 86_400, broadcast: false);
        $drawerTarget = $this->c->getSignal('drawer_target') ?? throw new LogicException('drawer_target is not declared');
        $drawerTarget->setValue('flows');

        expect(QueryKitActions::plan($this->c, 'drawer')['window']->clamped ?? null)->toBeFalse();
        $drawerTarget->setValue('talkers');
        expect(QueryKitActions::plan($this->c, 'drawer'))->toMatchArray(['target' => 'talkers', 'kind' => 'stats'])
            ->and(QueryKitActions::plan($this->c, 'drawer')['window']->clamped ?? null)->toBeTrue()
        ;
    });

    test('the filtered graph plans one run per bin and source, sources only from the configured list', function (): void {
        $this->c->getSignal('datestart')?->setValue(QUERY_KIT_BASE, broadcast: false);
        $this->c->getSignal('dateend')?->setValue(QUERY_KIT_BASE + 3600, broadcast: false);
        $this->c->getSignal('graph_sources')?->setValue(['gw', '../../etc', 'edge'], broadcast: false);
        $this->c->getSignal('graph_display')?->setValue('sources', broadcast: false);
        $this->c->getSignal('graph_resolution')?->setValue(12, broadcast: false);
        $this->c->getSignal('selected_profile')?->setValue('../other', broadcast: false);

        $plan = QueryKitActions::plan($this->c, 'overview');
        expect($plan)->toMatchArray(['sources' => ['gw', 'edge'], 'groups' => 2, 'points' => 12, 'profile' => 'live']);

        $this->c->getSignal('graph_display')?->setValue('protocols', broadcast: false);
        expect(QueryKitActions::plan($this->c, 'overview')['groups'] ?? null)->toBe(1);
    });
});

describe('payload shapes equal the seeded defaults', function (): void {
    test('_est_<t> is Estimate::toArray() plus pending, key for key', function (): void {
        $payload = QueryKitActions::estimatePayload(new Estimate(3, 3_145_728, 1, 1, false, false, '15 minutes'));

        expect(array_keys($payload))->toBe(array_keys(QueryKit::ESTIMATE_DEFAULT))
            ->and($payload['pending'])->toBeFalse()
            ->and($payload['bytesHuman'])->toBe('3 MiB')
        ;
    });

    test('_flt_<t> carries status, message and the text it checked', function (): void {
        expect(QueryKitActions::filterPayload('src ip x', ['valid' => false, 'message' => "syntax error at 'x'"]))
            ->toBe(['status' => 'invalid', 'message' => "syntax error at 'x'", 'checked' => 'src ip x'])
            ->and(QueryKitActions::filterPayload('proto tcp', ['valid' => true, 'message' => '']))
            ->toBe(['status' => 'valid', 'message' => '', 'checked' => 'proto tcp'])
            ->and(QueryKitActions::filterPayload(" \n", ['valid' => true, 'message' => '']))
            ->toBe(['status' => '', 'message' => '', 'checked' => " \n"])
            ->and(array_keys(QueryKitActions::filterPayload('x', ['valid' => true, 'message' => ''])))
            ->toBe(array_keys(QueryKit::FILTER_DEFAULT))
        ;
    });

    test('the document seeds exactly the defaults, under the signals\' wire ids, and the components nothing', function (): void {
        $states = new PageStates();
        $this->c->view(fn (bool $isUpdate): string => $this->c->render('layout.html.twig', Shell::render($this->c, $this->app, $states, $isUpdate)), cacheUpdates: false);
        $document = $this->app->buildHtmlDocument($this->c);
        $seed = queryKitSeed(substr($document, 0, (int) strpos($document, '</head>')));
        $data = [
            'querykit' => QueryKit::viewData($this->c, $this->app, new PageStates(), false, 'flows'),
            'drawer' => [],
            'filters' => [],
        ];
        $estimate = $this->c->render('components/query-estimate.html.twig', [...$data, 'target' => 'flows']);
        $field = $this->c->render('components/filter-field.html.twig', [
            ...$data,
            'target' => 'flows',
            'signal' => $this->c->getSignal('flows_filter'),
            'textareaId' => 'flowsFilter',
        ]);
        $estId = $this->c->getSignal('_est_flows')?->id();
        $fltId = $this->c->getSignal('_flt_flows')?->id();

        expect($seed[$estId] ?? null)->toBe(QueryKit::ESTIMATE_DEFAULT)
            ->and($seed)->toHaveKeys(array_map(
                fn (string $name): string => (string) $this->c->getSignal($name)?->id(),
                ['datestart', 'dateend', 'graph_sources', 'selected_profile'],
            ))
            ->and($seed[$fltId] ?? null)->toBe(QueryKit::FILTER_DEFAULT)
            ->and($estimate . $field)->not->toContain('data-signals__ifmissing')
            ->and($this->c->getSignal('_est_flows')?->getValue())->toBe(QueryKit::ESTIMATE_DEFAULT)
            ->and($this->c->getSignal('_flt_flows')?->getValue())->toBe(QueryKit::FILTER_DEFAULT)
            ->and($estimate)->toContain('data-estimate="flows"', '/_action/estimate-query?target=flows', "(true ? 'up to ' : 'about ')")
            ->and($field)->toContain('data-filter-status="flows"', 'aria-describedby="flowsFilterStatus"', 'id="flowsFilterStatus" role="status"')
        ;
    });

    test('the estimate posts again when a run of its own kind finishes, the drawer\'s by its target', function (): void {
        $running = (string) $this->c->getSignal('query_running')?->id();
        $kind = (string) $this->c->getSignal('query_kind')?->id();
        $drawerTarget = $this->c->getSignal('drawer_target') ?? throw new LogicException('drawer_target is not declared');
        $drawerTarget->setValue('flows');
        $data = ['querykit' => QueryKit::viewData($this->c, $this->app, new PageStates(), false, 'talkers')];
        $effect = fn (string $target): string => html_entity_decode(
            preg_match('/data-effect="([^"]*)"/', $this->c->render('components/query-estimate.html.twig', [...$data, 'target' => $target]), $m) === 1 ? $m[1] : '',
            ENT_QUOTES,
        );

        expect($effect('talkers'))->toContain(
            "const running = \${$running} === true; const ran = el.qkRunning === true && !running && \${$kind} === 'stats';",
            'if (moved || reset || ran)',
        )
            ->and($effect('drawer'))->toContain(
                "\${$kind} === ({\"overview\":\"graph\",\"overview-topn\":\"overview-topn\",\"talkers\":\"stats\",\"flows\":\"flows\",\"conversations\":\"conversations\"})[\${$drawerTarget->id()}]",
            )
        ;
    });

    test('an estimate says "about" for every target but Flows, and a target without an estimate renders nothing', function (): void {
        $data = ['querykit' => QueryKit::viewData($this->c, $this->app, new PageStates(), false, 'talkers')];
        $render = fn (string $target): string => html_entity_decode($this->c->render('components/query-estimate.html.twig', [...$data, 'target' => $target]), ENT_QUOTES);

        expect($render('talkers'))->toContain("(false ? 'up to ' : 'about ')", "false && 'stops early once the limit is reached'")
            ->and($render('flows'))->toContain("(true ? 'up to ' : 'about ')", "true && 'stops early once the limit is reached'")
            ->and(trim($render('alert')))->toBe('')
        ;
    });
});

describe('components', function (): void {
    beforeEach(function (): void {
        $this->field = fn (array $drawer): string => html_entity_decode($this->c->render('components/filter-field.html.twig', [
            'querykit' => QueryKit::viewData($this->c, $this->app, new PageStates(), false, 'flows'),
            'drawer' => $drawer,
            'filters' => [],
            'target' => 'flows',
            'signal' => $this->c->getSignal('flows_filter'),
            'textareaId' => 'flowsFilter',
        ]), ENT_QUOTES);
    });

    test('Builder and Saved open the drawer for the field\'s target, once the drawer lists targets', function (): void {
        $html = ($this->field)(['targets' => ['flows' => ['label' => 'Flows']]]);

        expect($html)->toContain(
            '<button type="button" data-size="sm" data-open-drawer="builder" aria-label="Open the filter builder"',
            "window.dispatchEvent(new CustomEvent('nfsen-open-drawer', {detail: {target: 'flows', tab: 'builder'}}))\">Builder</button>",
            '<button type="button" data-size="sm" data-open-drawer="saved" aria-label="Saved filters"',
            "window.dispatchEvent(new CustomEvent('nfsen-open-drawer', {detail: {target: 'flows', tab: 'saved'}}))\">Saved</button>",
        )
            ->and(($this->field)([]))->not->toContain('data-open-drawer', 'nfsen-open-drawer')
            ->and(($this->field)([]))->not->toContain('nfsen-filter-manager')
        ;
    });

    test('the status region stays quiet until the user edits the text', function (): void {
        $html = ($this->field)([]);

        expect($html)->toContain(
            'id="flowsFilterStatus" role="status" aria-live="off"',
            'data-preserve-attr="data-state aria-live"',
            "(el.qkAnswered || el.closest('.filter-field')?.contains(document.activeElement))) el.parentElement.setAttribute('aria-live', 'polite')",
        )
            ->and(substr_count($html, 'role="status"'))->toBe(1)
        ;
    });

    test('the query control announces through one status region, and its spinner is not one', function (): void {
        $id = fn (string $name): string => (string) $this->c->getSignal($name)?->id();
        $html = html_entity_decode($this->c->render('partials/progress-button.html.twig', [
            'id' => 'flowsRun',
            'label' => 'Process data',
            'action' => '/_action/flows',
            'killAction' => '/_action/kill',
            'running' => $id('query_running'),
            'permille' => $id('query_permille'),
            'status' => $id('query_status'),
            'eta' => $id('query_eta'),
            'exact' => $id('query_exact'),
            'kindSignal' => $id('query_kind'),
            'kind' => 'flows',
            'doneText' => "Number(\${$id('flows_count')}).toLocaleString('en') + ' flows returned'",
        ]), ENT_QUOTES);

        expect(substr_count($html, 'role="status"'))->toBe(1)
            ->and($html)->toContain('<span class="spinner" aria-hidden="true"></span>', "t = q > 0 ? q + ' percent' : 'Query started'")
            ->and($html)->toContain("(String(\${$id('query_status')}).startsWith('Done') ? (Number(\${$id('flows_count')}).toLocaleString('en') + ' flows returned') + '. ' + \${$id('query_status')}")
            ->and($html)->not->toMatch('/class="spinner"[^>]*role=/')
        ;
    });
});

describe('validate-filter', function (): void {
    $bin = static fn (string $name): string => dirname(__DIR__) . '/Support/bin/' . $name;

    test('writes nfdump\'s answer for the text it checked', function () use ($bin): void {
        $this->c->getSignal('flows_filter')?->setValue('src ip x', broadcast: false);
        queryKitRun(fn () => QueryKitActions::validate($this->c, 'flows', $bin('nfdump-z-syntax-error')));

        expect($this->c->getSignal('_flt_flows')?->getValue())
            ->toBe(['status' => 'invalid', 'message' => "syntax error at 'x'", 'checked' => 'src ip x'])
        ;

        $this->c->getSignal('stats_filter')?->setValue('proto tcp', broadcast: false);
        queryKitRun(fn () => QueryKitActions::validate($this->c, 'talkers', $bin('nfdump-z-valid')));
        expect($this->c->getSignal('_flt_talkers')?->getValue())->toBe(['status' => 'valid', 'message' => '', 'checked' => 'proto tcp']);
    });

    test('an empty filter has no status, and a posted non-string reads as empty', function () use ($bin): void {
        $this->c->getSignal('graph_filter')?->setValue(['proto tcp'], broadcast: false);
        queryKitRun(fn () => QueryKitActions::validate($this->c, 'overview', $bin('nfdump-z-syntax-error')));

        expect($this->c->getSignal('_flt_overview')?->getValue())->toBe(['status' => '', 'message' => '', 'checked' => '']);
    });

    test('ignores targets without a filter, and the drawer before its signal exists', function () use ($bin): void {
        queryKitRun(function () use ($bin): void {
            QueryKitActions::validate($this->c, 'overview-topn', $bin('nfdump-z-valid'));
            QueryKitActions::validate($this->c, 'drawer', $bin('nfdump-z-valid'));
            QueryKitActions::validate($this->c, 'nope', $bin('nfdump-z-valid'));
        });

        expect($this->c->getSignal('_flt_drawer')?->getValue())->toBe(QueryKit::FILTER_DEFAULT);
    });

    test('of two overlapping checks the newest request answers, even when the older one finishes last', function () use ($bin): void {
        $late = $this->root . '/nfdump-z-late';
        file_put_contents($late, "#!/bin/sh\nsleep 0.3\necho \"Line 1: host lookup failed\"\nexit 254\n");
        chmod($late, 0o755);
        $finished = [];

        queryKitRun(function () use ($bin, $late, &$finished): void {
            $this->c->getSignal('flows_filter')?->setValue('host slow.example', broadcast: false);
            Coroutine::create(function () use ($late, &$finished): void {
                QueryKitActions::validate($this->c, 'flows', $late);
                $finished[] = 'older';
            });
            // The next request's signals are injected while the first check still runs.
            $this->c->getSignal('flows_filter')?->setValue('proto tcp', broadcast: false);
            QueryKitActions::validate($this->c, 'flows', $bin('nfdump-z-valid'));
            $finished[] = 'newer';
        });

        expect($finished)->toBe(['newer', 'older'])
            ->and($this->c->getSignal('_flt_flows')?->getValue())->toBe(['status' => 'valid', 'message' => '', 'checked' => 'proto tcp'])
        ;
    });

    test('a check that throws answers "could not be checked" for its text, and rethrows', function () use ($bin): void {
        $this->c->getSignal('flows_filter')?->setValue('proto tcp', broadcast: false);
        // A cached answer that is not an array breaks validate()'s return type.
        (new ReflectionProperty(FilterValidator::class, 'cache'))->setValue(null, [$bin('nfdump-z-valid') . "\0proto tcp" => 'broken']);
        $thrown = null;
        queryKitRun(function () use ($bin, &$thrown): void {
            try {
                QueryKitActions::validate($this->c, 'flows', $bin('nfdump-z-valid'));
            } catch (Throwable $e) {
                $thrown = $e;
            }
        });

        expect($thrown)->toBeInstanceOf(TypeError::class)
            ->and($this->c->getSignal('_flt_flows')?->getValue())
            ->toBe(['status' => 'invalid', 'message' => FilterValidator::UNCHECKED, 'checked' => 'proto tcp'])
        ;
    });

    test('the action answers with a signal patch once the stream is up', function (): void {
        $this->c->getSignal('flows_filter')?->setValue('', broadcast: false);
        queryKitSettle($this->c);
        $this->c->setRequestInput(['target' => 'flows'], []);

        queryKitRun(fn () => $this->c->executeAction('validate-filter'));
        expect(queryKitSignalPatches($this->c))->toBe([]);

        $this->app->activeSseCount[$this->c->getId()] = 1;
        // As a posted client value: injected without being marked for the next push.
        $this->c->getSignal('flows_filter')?->setValue(' ', markChanged: false, broadcast: false);
        queryKitRun(fn () => $this->c->executeAction('validate-filter'));
        $fltId = (string) $this->c->getSignal('_flt_flows')?->id();

        expect(queryKitSignalPatches($this->c))->toBe([[$fltId => ['status' => '', 'message' => '', 'checked' => ' ']]]);
    });
});

describe('estimate-query', function (): void {
    beforeEach(function (): void {
        queryKitCaptures($this->root, 'gw', QUERY_KIT_BASE, QUERY_KIT_BASE + 7200, 1024);
        queryKitCaptures($this->root, 'edge', QUERY_KIT_BASE, QUERY_KIT_BASE + 7200, 2048);
        $this->c->getSignal('datestart')?->setValue(QUERY_KIT_BASE, broadcast: false);
        $this->c->getSignal('dateend')?->setValue(QUERY_KIT_BASE + 7200 - 1, broadcast: false);
        $this->c->getSignal('graph_sources')?->setValue(['gw', 'edge'], broadcast: false);
    });

    test('pushes pending, then the estimate, as signal patches', function (): void {
        $this->app->activeSseCount[$this->c->getId()] = 1;
        queryKitSettle($this->c);
        $this->c->setRequestInput(['target' => 'flows'], []);

        queryKitRun(fn () => $this->c->executeAction('estimate-query'));
        $patches = queryKitSignalPatches($this->c);
        $estId = (string) $this->c->getSignal('_est_flows')?->id();
        $value = $this->c->getSignal('_est_flows')?->getValue();

        expect($patches)->toHaveCount(2)
            ->and($patches[0])->toBe([$estId => QueryKit::ESTIMATE_DEFAULT])
            ->and($patches[1][$estId])->toBe($value)
            ->and($value)->toMatchArray([
                'pending' => false, 'files' => 48, 'bytes' => 24 * 3072, 'runs' => 1, 'seconds' => 1,
                'measured' => false, 'clamped' => false, 'window' => '2 hours', 'heavy' => false,
            ])
            ->and(array_keys($value))->toBe(array_keys(QueryKit::ESTIMATE_DEFAULT))
        ;
    });

    test('the estimate after the third recorded run says measured', function (): void {
        Database::useShared(Database::open(':memory:'));
        $runs = new QueryRunRepository(Database::shared());
        $runs->record('flows', 100_000_000, 0, 1000, true, 1);
        $runs->record('flows', 100_000_000, 0, 1000, true, 2);
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'flows'));
        $before = $this->c->getSignal('_est_flows')?->getValue();

        $runs->record('flows', 100_000_000, 0, 1000, true, 3);
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'flows'));

        expect($before['measured'] ?? null)->toBeFalse()
            ->and($this->c->getSignal('_est_flows')?->getValue())->toMatchArray(['measured' => true, 'seconds' => 1, 'pending' => false])
        ;
    });

    test('a clamped window estimates the recent end', function (): void {
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'talkers'));

        expect($this->c->getSignal('_est_talkers')?->getValue())->toMatchArray(['files' => 24, 'clamped' => true, 'window' => '1 hour', 'pending' => false]);
    });

    test('the filtered graph counts its nfdump runs', function (): void {
        $this->c->getSignal('graph_display')?->setValue('sources', broadcast: false);
        $this->c->getSignal('graph_resolution')?->setValue(12, broadcast: false);
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'overview'));

        expect($this->c->getSignal('_est_overview')?->getValue())->toMatchArray(['files' => 24, 'runs' => 24, 'clamped' => true]);
    });

    test('nothing to read says so with a window and no seconds', function (): void {
        $this->c->getSignal('datestart')?->setValue(QUERY_KIT_BASE + 86_400, broadcast: false);
        $this->c->getSignal('dateend')?->setValue(QUERY_KIT_BASE + 90_000, broadcast: false);
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'flows'));

        expect($this->c->getSignal('_est_flows')?->getValue())->toMatchArray(['files' => 0, 'seconds' => null, 'secondsHuman' => '', 'pending' => false, 'window' => '1 hour']);
    });

    test('a drawer with nothing to estimate drops its figures and outranks an estimate still running', function (): void {
        $drawerTarget = $this->c->getSignal('drawer_target') ?? throw new LogicException('drawer_target is not declared');
        $drawerTarget->setValue('flows');
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'drawer'));
        expect($this->c->getSignal('_est_drawer')?->getValue())->toMatchArray(['files' => 48, 'window' => '2 hours', 'pending' => false]);

        $inFlight = (new ReflectionMethod(QueryKitActions::class, 'nextTicket'))->invoke(null, $this->c, '_est_drawer');
        $drawerTarget->setValue('alert');
        queryKitRun(fn () => QueryKitActions::estimate($this->c, 'drawer'));

        expect($this->c->getSignal('_est_drawer')?->getValue())->toBe([...QueryKit::ESTIMATE_DEFAULT, 'pending' => false])
            ->and((new ReflectionMethod(QueryKitActions::class, 'isNewest'))->invoke(null, $this->c, '_est_drawer', $inFlight))->toBeFalse()
        ;
    });

    test('the kit answers through estimate-query and validate-filter; count-files is gone', function (): void {
        expect($this->c->getNamedActions())->toHaveKeys(['estimate-query', 'validate-filter'])
            ->and($this->c->getNamedActions())->not->toHaveKey('count-files')
        ;
    });

    test('only the newest request per answer signal writes, and checks and estimates count apart', function (): void {
        $next = new ReflectionMethod(QueryKitActions::class, 'nextTicket');
        $newest = new ReflectionMethod(QueryKitActions::class, 'isNewest');
        $first = $next->invoke(null, $this->c, '_est_flows');
        $second = $next->invoke(null, $this->c, '_est_flows');
        $check = $next->invoke(null, $this->c, '_flt_flows');

        expect($newest->invoke(null, $this->c, '_est_flows', $first))->toBeFalse()
            ->and($newest->invoke(null, $this->c, '_est_flows', $second))->toBeTrue()
            ->and($newest->invoke(null, $this->c, '_flt_flows', $check))->toBeTrue()
            ->and($newest->invoke(null, $this->c, '_est_talkers', 1))->toBeFalse()
        ;
    });
});
