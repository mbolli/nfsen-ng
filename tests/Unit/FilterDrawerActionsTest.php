<?php

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FilterDrawerActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\FilterDrawer;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\QueryKit;
use mbolli\nfsen_ng\pages\RangeControls;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\processor\FilterValidator;
use mbolli\nfsen_ng\store\Database;
use mbolli\nfsen_ng\store\SavedFilterRepository;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * The shell signals, the range, the query kit and the drawer on a Via with the real templates,
 * plus the filter signals of the pages the drawer edits. The pages themselves stay out, so the
 * test does not depend on their state.
 *
 * @return array{0: Via, 1: Context}
 */
function filterDrawerCompose(): array {
    putenv('VIA_TEST_MODE=1');

    try {
        $app = new Via(new ViaConfig()->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $app->setGlobalState('_fatalError', 'No datasource in this test.');
        $c = new Context('ctx-filter-drawer', '/', $app);
    } finally {
        putenv('VIA_TEST_MODE');
    }
    Shell::signals($c);
    RangeControls::signals($c);
    QueryKit::signals($c);
    FilterDrawer::signals($c);
    foreach (QueryKit::TARGETS as $meta) {
        if ($meta['filter'] !== '' && $c->getSignal($meta['filter']) === null) {
            $c->signal('', $meta['filter'], clientWritable: true);
        }
    }
    $states = new PageStates();
    QueryKit::register($c, $app, $states);
    FilterDrawer::register($c, $app, $states);

    return [$app, $c];
}

/** Runs a drawer action as a POST would: `?query` input, then the handler, inside a coroutine. */
function filterDrawerPost(Context $c, string $action, array $query = []): void {
    $c->setRequestInput(array_map('strval', $query), []);
    $id = (string) $c->getAction($action)?->id();
    Coroutine::run(static fn () => $c->executeAction($id));
}

/** @return array{id: string, level: string, text: string} */
function filterDrawerNotice(Context $c): array {
    /** @var array{id: string, level: string, text: string} */
    return $c->getSignal('_drawer_notice')?->getValue() ?? [];
}

function filterDrawerAck(Context $c): string {
    return (string) $c->getSignal('_drawer_imported')?->getValue();
}

/** The rendered drawer, as the layout includes it. */
function filterDrawerRender(Via $app, Context $c): string {
    $states = new PageStates();

    return html_entity_decode($c->render('drawer/filter-drawer.html.twig', [
        'querykit' => QueryKit::viewData($c, $app, $states, true, 'flows'),
        'drawer' => FilterDrawer::viewData($c, $app, $states, true, 'flows'),
    ]), ENT_QUOTES);
}

beforeEach(function (): void {
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->prefsBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    $this->stateDirBefore = isset(Config::$stateDir) ? Config::$stateDir : null;
    $this->deploymentBefore = Config::$deploymentFilters;
    Config::$settings = Settings::fromArray([
        'general' => ['ports' => [80], 'sources' => ['gw'], 'db' => 'Rrd', 'processor' => 'Nfdump'],
        'nfdump' => ['binary' => dirname(__DIR__) . '/Support/bin/nfdump-z-valid', 'profiles-data' => sys_get_temp_dir() . '/nfsen-drawer-none', 'profile' => 'live', 'max-processes' => 4],
        'log' => ['priority' => LOG_ERR],
    ]);
    Config::$prefsFile = '';
    Config::$stateDir = '';
    Config::$deploymentFilters = [];
    (new ReflectionProperty(FilterValidator::class, 'cache'))->setValue(null, []);

    $this->db = Database::open(':memory:');
    Database::useShared($this->db);
    $this->filters = new SavedFilterRepository($this->db);
    [$this->app, $this->c] = filterDrawerCompose();
});

afterEach(function (): void {
    Database::resetShared();
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    Config::$prefsFile = $this->prefsBefore ?? '';
    Config::$stateDir = $this->stateDirBefore ?? '';
    Config::$deploymentFilters = $this->deploymentBefore;
});

describe('filter-migrate-local', function (): void {
    test('maps plain strings to named items: trimmed, 60 character names, empties skipped', function (): void {
        $long = 'src net 10.0.0.0/8 and dst net 192.168.0.0/16 and not dst port 53 and bytes > 1000';

        expect(FilterDrawerActions::browserItems(json_encode(['proto tcp', "  dst port 53\n", '', '   ', $long])))->toBe([
            ['name' => 'proto tcp', 'expression' => 'proto tcp'],
            ['name' => 'dst port 53', 'expression' => 'dst port 53'],
            ['name' => mb_substr($long, 0, 60), 'expression' => $long],
        ]);
    });

    test('ignores anything that is not a JSON list, and entries that are not strings', function (): void {
        expect(FilterDrawerActions::browserItems(''))->toBe([])
            ->and(FilterDrawerActions::browserItems('not json'))->toBe([])
            ->and(FilterDrawerActions::browserItems('"proto tcp"'))->toBe([])
            ->and(FilterDrawerActions::browserItems('{"a": "proto tcp"}'))->toBe([])
            ->and(FilterDrawerActions::browserItems('null'))->toBe([])
            ->and(FilterDrawerActions::browserItems('[1, null, ["proto tcp"], {"x": 1}, "proto udp"]'))->toBe([
                ['name' => 'proto udp', 'expression' => 'proto udp'],
            ])
        ;
    });

    test('imports with origin browser, clears drawer_import, acknowledges and says how many', function (): void {
        $this->c->getSignal('drawer_import')?->setValue(json_encode(['proto tcp', 'dst port 53', 'proto tcp']), broadcast: false);

        filterDrawerPost($this->c, 'filter-migrate-local');

        expect(array_column($this->filters->list(), 'origin', 'expression'))->toBe(['dst port 53' => 'browser', 'proto tcp' => 'browser'])
            ->and($this->c->getSignal('drawer_import')?->getValue())->toBe('')
            ->and(filterDrawerAck($this->c))->toMatch('/^[0-9a-f]{16}$/')
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'success', 'text' => 'Imported 2 saved filters from this browser'])
        ;
    });

    test('a second post imports nothing and says nothing, but acknowledges again with a new id', function (): void {
        $payload = json_encode(['proto tcp']);
        $this->c->getSignal('drawer_import')?->setValue($payload, broadcast: false);
        filterDrawerPost($this->c, 'filter-migrate-local');
        $first = filterDrawerAck($this->c);
        $this->c->getSignal('_drawer_notice')?->setValue(FilterDrawer::NOTICE_DEFAULT, broadcast: false);

        $this->c->getSignal('drawer_import')?->setValue($payload, broadcast: false);
        filterDrawerPost($this->c, 'filter-migrate-local');

        expect($this->filters->list())->toHaveCount(1)
            ->and(filterDrawerNotice($this->c)['text'])->toBe('')
            ->and($this->c->getSignal('drawer_import')?->getValue())->toBe('')
            ->and(filterDrawerAck($this->c))->not->toBe('')
            ->and(filterDrawerAck($this->c))->not->toBe($first)
        ;
    });

    test('a non-list payload is cleared without importing', function (): void {
        $this->c->getSignal('drawer_import')?->setValue('{"proto tcp": 1}', broadcast: false);

        filterDrawerPost($this->c, 'filter-migrate-local');

        expect($this->filters->list())->toBe([])
            ->and($this->c->getSignal('drawer_import')?->getValue())->toBe('')
        ;
    });

    test('a post without the browser list is not acknowledged', function (): void {
        filterDrawerPost($this->c, 'filter-migrate-local');

        expect(filterDrawerAck($this->c))->toBe('')
            ->and(filterDrawerNotice($this->c)['text'])->toBe('')
        ;
    });

    test('an unavailable store is not acknowledged, so the client does not mark the browser done', function (): void {
        Database::resetShared();
        $this->c->getSignal('drawer_import')?->setValue(json_encode(['proto tcp']), broadcast: false);

        filterDrawerPost($this->c, 'filter-migrate-local');

        expect(filterDrawerAck($this->c))->toBe('')
            ->and($this->c->getSignal('drawer_import')?->getValue())->toBe('')
            ->and(filterDrawerNotice($this->c))->toMatchArray([
                'level' => 'error',
                'text' => "Could not import this browser's saved filters: the state directory is not configured yet",
            ])
        ;
        Database::useShared($this->db);
        expect($this->filters->list())->toBe([]);
    });

    test('a deleted deployment preset stays deleted even when the browser list has it (D16)', function (): void {
        Config::$deploymentFilters = ['proto tcp'];
        $preset = $this->filters->list()[0];
        $this->filters->delete($preset['id']);
        $this->c->getSignal('drawer_import')?->setValue(json_encode(['proto tcp', 'port 53']), broadcast: false);

        filterDrawerPost($this->c, 'filter-migrate-local');

        expect(array_column($this->filters->list(), 'origin', 'expression'))->toBe(['port 53' => 'browser']);
    });
});

describe('saved filter actions', function (): void {
    test('filter-save stores the editor text under the name, and leaves edit mode', function (): void {
        $this->c->getSignal('drawer_filter')?->setValue('dst port 443', broadcast: false);
        $this->c->getSignal('drawer_name')?->setValue('Web', broadcast: false);
        $this->c->getSignal('drawer_edit')?->setValue(7, broadcast: false);

        filterDrawerPost($this->c, 'filter-save');

        $saved = $this->filters->list();
        expect($saved)->toHaveCount(1)
            ->and($saved[0])->toMatchArray(['name' => 'Web', 'expression' => 'dst port 443', 'origin' => 'user', 'starred' => false])
            ->and($this->c->getSignal('drawer_name')?->getValue())->toBe('')
            ->and($this->c->getSignal('drawer_edit')?->getValue())->toBe(0)
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'success', 'text' => 'Saved as Web'])
        ;
    });

    test('filter-save without a name names the filter after its text', function (): void {
        $this->c->getSignal('drawer_filter')?->setValue('proto udp', broadcast: false);

        filterDrawerPost($this->c, 'filter-save');

        expect($this->filters->list()[0]['name'])->toBe('proto udp')
            ->and(filterDrawerNotice($this->c)['text'])->toBe('Saved as proto udp')
        ;
    });

    test('a duplicate expression answers with the name it is saved under', function (): void {
        $this->filters->create('DNS', 'port 53');
        $this->c->getSignal('drawer_filter')?->setValue("  port\n53 ", broadcast: false);
        $this->c->getSignal('drawer_name')?->setValue('Other', broadcast: false);

        filterDrawerPost($this->c, 'filter-save');

        expect($this->filters->list())->toHaveCount(1)
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'warning', 'text' => 'Already saved as DNS'])
            ->and($this->c->getSignal('drawer_name')?->getValue())->toBe('Other')
        ;
    });

    test('an empty filter is not saved', function (): void {
        filterDrawerPost($this->c, 'filter-save');

        expect($this->filters->list())->toBe([])
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'error', 'text' => 'The filter expression is empty.'])
        ;
    });

    test('filter-update saves a new name and expression', function (): void {
        $id = $this->filters->create('Web', 'dst port 443');
        $this->c->getSignal('drawer_edit')?->setValue($id, broadcast: false);
        $this->c->getSignal('drawer_filter')?->setValue('dst port in [80 443]', broadcast: false);
        $this->c->getSignal('drawer_name')?->setValue('Web (both)', broadcast: false);

        filterDrawerPost($this->c, 'filter-update', ['id' => $id]);

        expect($this->filters->find($id))->toMatchArray(['name' => 'Web (both)', 'expression' => 'dst port in [80 443]'])
            ->and($this->c->getSignal('drawer_edit')?->getValue())->toBe(0)
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'success', 'text' => 'Saved changes to Web (both)'])
        ;
    });

    test('filter-update with rename=1 keeps the expression', function (): void {
        $id = $this->filters->create('Web', 'dst port 443');
        $this->c->getSignal('drawer_rename')?->setValue($id, broadcast: false);
        $this->c->getSignal('drawer_filter')?->setValue('proto icmp', broadcast: false);
        $this->c->getSignal('drawer_name')?->setValue('HTTPS', broadcast: false);

        filterDrawerPost($this->c, 'filter-update', ['id' => $id, 'rename' => '1']);

        expect($this->filters->find($id))->toMatchArray(['name' => 'HTTPS', 'expression' => 'dst port 443'])
            ->and($this->c->getSignal('drawer_rename')?->getValue())->toBe(0)
            ->and($this->c->getSignal('drawer_name')?->getValue())->toBe('')
            ->and(filterDrawerNotice($this->c)['text'])->toBe('Renamed to HTTPS')
        ;
    });

    test('filter-update onto another filter\'s expression is refused and keeps edit mode', function (): void {
        $this->filters->create('DNS', 'port 53');
        $id = $this->filters->create('Web', 'dst port 443');
        $this->c->getSignal('drawer_edit')?->setValue($id, broadcast: false);
        $this->c->getSignal('drawer_filter')?->setValue('port 53', broadcast: false);

        filterDrawerPost($this->c, 'filter-update', ['id' => $id]);

        expect($this->filters->find($id)['expression'])->toBe('dst port 443')
            ->and($this->c->getSignal('drawer_edit')?->getValue())->toBe($id)
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'warning', 'text' => 'Already saved as DNS'])
        ;
    });

    test('an id that is missing or not a number is refused', function (): void {
        filterDrawerPost($this->c, 'filter-delete');
        expect(filterDrawerNotice($this->c))->toMatchArray(['level' => 'error', 'text' => 'No saved filter was named.']);

        filterDrawerPost($this->c, 'filter-delete', ['id' => '1 or 1=1']);
        expect(filterDrawerNotice($this->c)['text'])->toBe('No saved filter was named.');
    });

    test('filter-star stars and unstars, and a starred filter lists first', function (): void {
        $this->filters->create('Alpha', 'proto tcp');
        $zulu = $this->filters->create('Zulu', 'proto udp');

        filterDrawerPost($this->c, 'filter-star', ['id' => $zulu, 'on' => '1']);
        expect($this->filters->list()[0])->toMatchArray(['name' => 'Zulu', 'starred' => true]);

        filterDrawerPost($this->c, 'filter-star', ['id' => $zulu, 'on' => '0']);
        expect($this->filters->list()[0]['name'])->toBe('Alpha')
            ->and($this->filters->find($zulu)['starred'])->toBeFalse()
        ;
    });

    test('filter-delete removes the filter and leaves an edit of it', function (): void {
        $id = $this->filters->create('Web', 'dst port 443');
        $this->c->getSignal('drawer_edit')?->setValue($id, broadcast: false);

        filterDrawerPost($this->c, 'filter-delete', ['id' => $id]);

        expect($this->filters->list())->toBe([])
            ->and($this->c->getSignal('drawer_edit')?->getValue())->toBe(0)
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'success', 'text' => 'Deleted Web'])
        ;
    });

    test('filter-use loads the expression into the editor, bumps last used and checks it', function (): void {
        $id = $this->filters->create('Web', 'dst port 443');
        $before = time();

        filterDrawerPost($this->c, 'filter-use', ['id' => $id]);

        $filter = $this->filters->find($id);
        expect($this->c->getSignal('drawer_filter')?->getValue())->toBe('dst port 443')
            ->and($filter['useCount'])->toBe(1)
            ->and($filter['lastUsedAt'])->toBeGreaterThanOrEqual($before)
            ->and($this->c->getSignal('_flt_drawer')?->getValue())->toBe(['status' => 'valid', 'message' => '', 'checked' => 'dst port 443'])
            ->and(filterDrawerNotice($this->c)['text'])->toBe('Loaded Web into the editor')
        ;
    });

    test('filter-use keeps its notice when the filter cannot be checked', function (): void {
        $id = $this->filters->create('Web', 'dst port 443');
        Config::$settings = Config::$settings->withNfdumpBinary("/nonexistent/nf\0dump");

        filterDrawerPost($this->c, 'filter-use', ['id' => $id]);

        expect($this->c->getSignal('drawer_filter')?->getValue())->toBe('dst port 443')
            ->and($this->filters->find($id)['useCount'])->toBe(1)
            ->and($this->c->getSignal('_flt_drawer')?->getValue())->toBe(['status' => 'invalid', 'message' => FilterValidator::UNCHECKED, 'checked' => 'dst port 443'])
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => '', 'text' => 'Loaded Web into the editor'])
        ;
    });

    test('filter-use of a filter deleted meanwhile says so and keeps the editor', function (): void {
        $this->c->getSignal('drawer_filter')?->setValue('proto tcp', broadcast: false);

        filterDrawerPost($this->c, 'filter-use', ['id' => 99]);

        expect($this->c->getSignal('drawer_filter')?->getValue())->toBe('proto tcp')
            ->and(filterDrawerNotice($this->c))->toMatchArray(['level' => 'error', 'text' => 'The saved filter no longer exists.'])
        ;
    });

    test('an unavailable store is named in the status line', function (): void {
        Database::resetShared();
        $this->c->getSignal('drawer_filter')?->setValue('proto tcp', broadcast: false);

        filterDrawerPost($this->c, 'filter-save');

        expect(filterDrawerNotice($this->c))->toMatchArray(['level' => 'error', 'text' => 'Saved filters unavailable: the state directory is not configured yet']);
    });
});

describe('drawer-open', function (): void {
    test('checks the text the drawer opened with into _flt_drawer and clears the last notice', function (): void {
        $this->c->getSignal('drawer_target')?->setValue('flows', broadcast: false);
        $this->c->getSignal('drawer_filter')?->setValue('proto tcp', broadcast: false);
        $this->c->getSignal('_drawer_notice')?->setValue(['id' => 'x', 'level' => 'success', 'text' => 'Saved as Web'], broadcast: false);

        filterDrawerPost($this->c, 'drawer-open');

        expect($this->c->getSignal('_flt_drawer')?->getValue())->toBe(['status' => 'valid', 'message' => '', 'checked' => 'proto tcp'])
            ->and(filterDrawerNotice($this->c))->toBe(FilterDrawer::NOTICE_DEFAULT)
            ->and($this->c->getSignal('drawer_target')?->getValue())->toBe('flows')
        ;
    });

    test('drawer-close only records the posted state, so later renders leave the list out', function (): void {
        $this->c->getSignal('drawer_open')?->setValue(false, broadcast: false);
        $this->c->getSignal('drawer_filter')?->setValue('proto tcp', broadcast: false);

        filterDrawerPost($this->c, 'drawer-close');

        expect(filterDrawerNotice($this->c))->toBe(FilterDrawer::NOTICE_DEFAULT)
            ->and($this->c->getSignal('_flt_drawer')?->getValue())->toBe(QueryKit::FILTER_DEFAULT)
            ->and(FilterDrawer::viewData($this->c, $this->app, new PageStates(), true, 'flows')['savedFilters'])->toBe([])
        ;
    });

    test('a filter that cannot be checked leaves the saved-filter line empty', function (): void {
        $this->c->getSignal('drawer_filter')?->setValue('proto tcp', broadcast: false);
        Config::$settings = Config::$settings->withNfdumpBinary("/nonexistent/nf\0dump");

        filterDrawerPost($this->c, 'drawer-open');

        expect($this->c->getSignal('_flt_drawer')?->getValue())->toBe(['status' => 'invalid', 'message' => FilterValidator::UNCHECKED, 'checked' => 'proto tcp'])
            ->and(filterDrawerNotice($this->c))->toBe(FilterDrawer::NOTICE_DEFAULT)
        ;
    });

    test('an unknown target is dropped', function (): void {
        $this->c->getSignal('drawer_target')?->setValue('drawer', broadcast: false);

        filterDrawerPost($this->c, 'drawer-open');

        expect($this->c->getSignal('drawer_target')?->getValue())->toBe('');
    });
});

describe('FilterDrawer view', function (): void {
    test('lists every target with its filter signal\'s wire id; only pages run', function (): void {
        $targets = FilterDrawer::targets($this->c);

        expect(array_keys($targets))->toBe(['overview', 'talkers', 'flows', 'conversations', 'alert'])
            ->and($targets['flows'])->toBe(['label' => 'Flows', 'signal' => (string) $this->c->getSignal('flows_filter')?->id(), 'runs' => true])
            ->and($targets['alert']['signal'])->toBe((string) $this->c->getSignal('alert_form_nfdumpFilter')?->id())
            ->and($targets['alert']['runs'])->toBeFalse()
        ;
    });

    test('a closed drawer reads no store and renders only its frame', function (): void {
        $this->filters->create('Web', 'dst port 443');
        $data = FilterDrawer::viewData($this->c, $this->app, new PageStates(), true, 'flows');
        $html = filterDrawerRender($this->app, $this->c);

        expect($data)->toMatchArray(['open' => false, 'grammar' => null, 'savedFilters' => [], 'savedError' => ''])
            ->and($html)->toContain('<dialog id="filter-drawer" class="drawer"', 'Loading filters', 'id="drawerApply"')
            // The browser import is marked done on the server's acknowledgement only.
            ->and($html)->toContain('data-effect="const ack = $' . $this->c->getSignal('_drawer_imported')?->id() . ';')
            ->and($html)->not->toContain('saved-list', 'data-filter-id', 'drawerFilterTextarea', 'nfsen-filter-editor', 'Web')
        ;
    });

    test('an open drawer renders the editor, the grammar and the saved list, starred first', function (): void {
        $this->filters->create('Web', 'dst port 443');
        $this->filters->create('DNS', 'port 53', 'deployment', starred: true);
        $this->c->getSignal('drawer_open')?->setValue(true, broadcast: false);
        $this->c->getSignal('drawer_target')?->setValue('flows', broadcast: false);

        $html = filterDrawerRender($this->app, $this->c);

        expect($html)->toContain(
            'Filter builder',
            'for Flows',
            '<nfsen-filter-editor id="drawerEditor"',
            'id="drawerFilterTextarea"',
            'aria-describedby="drawerFilterStatus drawerSuggestStatus"',
            'id="drawerSuggestStatus" class="visually-hidden" role="status"',
            'id="drawerRawTextarea"',
            'data-filter-status="drawer"',
            'data-snippet="src host <ip>"',
            'data-snippet="dst port in [80 443]"',
            'data-estimate="drawer"',
            'id="drawerSearch"',
            'aria-label="Star DNS" title="Star DNS"',
            'aria-label="Close the filter builder" title="Close the filter builder"',
            'aria-label="Actions for Web"',
            '<span class="badge">preset</span>',
        )
            ->and(strpos($html, 'data-name="DNS"'))->toBeLessThan(strpos($html, 'data-name="Web"'))
            ->and(substr_count($html, 'data-filter-id='))->toBe(2)
            // 2.7.7: the estimate is the editor column's last line, under the Fields and Examples.
            ->and(strpos($html, 'data-filter-status="drawer"'))->toBeLessThan(strpos($html, 'class="field-tree"'))
            ->and(strpos($html, 'class="field-tree"'))->toBeLessThan(strpos($html, 'data-estimate="drawer"'))
            ->and(strpos($html, 'data-estimate="drawer"'))->toBeLessThan(strpos($html, 'class="drawer-saved'))
        ;
    });

    test('an alert rule has no estimate and no Apply and run target', function (): void {
        $this->c->getSignal('drawer_open')?->setValue(true, broadcast: false);
        $this->c->getSignal('drawer_target')?->setValue('alert', broadcast: false);

        $html = filterDrawerRender($this->app, $this->c);

        expect($html)->toContain('for Alert rule', '["overview","talkers","flows","conversations"].includes(')
            ->and($html)->not->toContain('data-estimate="drawer"')
        ;
    });

    test('an unavailable store is named instead of the list', function (): void {
        Database::resetShared();
        $this->c->getSignal('drawer_open')?->setValue(true, broadcast: false);

        $data = FilterDrawer::viewData($this->c, $this->app, new PageStates(), true, 'flows');

        expect($data['savedError'])->toBe('Saved filters unavailable: the state directory is not configured yet')
            ->and(filterDrawerRender($this->app, $this->c))->toContain('Saved filters unavailable: the state directory is not configured yet')
        ;
    });

    test('the filter field has lost the old manager and opens the drawer instead', function (): void {
        $states = new PageStates();
        $html = html_entity_decode($this->c->render('components/filter-field.html.twig', [
            'querykit' => QueryKit::viewData($this->c, $this->app, $states, true, 'flows'),
            'drawer' => FilterDrawer::viewData($this->c, $this->app, $states, true, 'flows'),
            'target' => 'alert',
            'signal' => $this->c->getSignal('alert_form_nfdumpFilter'),
            'textareaId' => 'alertNfdumpFilter',
        ]), ENT_QUOTES);

        expect($html)->not->toContain('nfsen-filter-manager', '(local)')
            ->and($html)->toContain(
                "new CustomEvent('nfsen-open-drawer', {detail: {target: 'alert', tab: 'builder'}})",
                "new CustomEvent('nfsen-open-drawer', {detail: {target: 'alert', tab: 'saved'}})",
                'data-filter-status="alert"',
            )
        ;
    });
});
