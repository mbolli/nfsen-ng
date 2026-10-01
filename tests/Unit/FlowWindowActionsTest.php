<?php

/**
 * The Flows list's actions: one element patch per window, sort or column change, the notice once
 * the rows are gone, the tab's state restored before and persisted after, the zone from FlowsState.
 */

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\FlowWindowActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\FlowsPage;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Revival;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * A tab with only the list's actions registered, its patches in an array (VIA_TEST_MODE): no
 * page signal exists, flows_tz included, as in a context a slim POST revived.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function flowWindowTestBare(?Via $app = null, string $id = ''): array {
    putenv('VIA_TEST_MODE=1');

    try {
        $app ??= new Via(new ViaConfig());
        $c = new Context($id !== '' ? $id : 'ctx-bare-' . bin2hex(random_bytes(3)), '/', $app);
    } finally {
        putenv('VIA_TEST_MODE');
    }
    $states = new PageStates();
    FlowWindowActions::register($c, $app, $states);

    return [$app, $c, $states];
}

/**
 * A tab on the Flows page with the real templates, in the fatal-error state so a render touches
 * no datasource.
 *
 * @return array{0: Via, 1: Context, 2: PageStates}
 */
function flowWindowTestCompose(): array {
    putenv('VIA_TEST_MODE=1');

    try {
        $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $app->setGlobalState('_fatalError', 'No datasource in this test.');
        $c = new Context('ctx-window-' . bin2hex(random_bytes(3)), '/', $app);
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
    $c->getSignal('page')?->setValue('flows', broadcast: false);
    $c->view(static fn (bool $isUpdate): string => flowWindowTestRender($c, $app, $states, $isUpdate), cacheUpdates: false);

    return [$app, $c, $states];
}

function flowWindowTestRender(Context $c, Via $app, PageStates $states, bool $isUpdate = false): string {
    return $c->render('pages/flows.html.twig', Shell::render($c, $app, $states, $isUpdate));
}

/** @return list<array<string, mixed>> */
function flowWindowTestPatches(Context $c): array {
    $all = [];
    while (($patch = $c->getPatch()) !== null) {
        $all[] = $patch;
    }

    return $all;
}

/**
 * Runs the registered action $name with $input as the request's query.
 *
 * @param array<string, string> $input
 *
 * @return list<array<string, mixed>> the patches it queued
 */
function flowWindowTestRun(Context $c, string $name, array $input): array {
    flowWindowTestPatches($c);
    $c->setRequestInput($input, []);
    $c->executeAction((string) $c->getAction($name)?->id());

    return flowWindowTestPatches($c);
}

/** @return list<array<string, mixed>> $n rows; row i has src_port 10000 + i and in_bytes i % 7 */
function flowWindowTestRows(int $n): array {
    return array_map(static fn (int $i): array => [
        'first' => '2026-08-29T05:17:53.000',
        'in_bytes' => $i % 7,
        'src_port' => 10_000 + $i,
        'src_addr' => '10.0.' . intdiv($i, 250) . '.' . $i % 250,
    ], range(0, $n - 1));
}

/**
 * Stores a Run's result; $list holds what the Run copied from the browser.
 *
 * @param list<array<string, mixed>>                                                    $rows
 * @param array{tz?: string, sortKey?: string, sortDir?: string, hidden?: list<string>} $list
 */
function flowWindowTestStore(FlowsState $flows, array|int $rows = 1_000, array $list = []): string {
    $records = is_int($rows) ? flowWindowTestRows($rows) : $rows;
    FlowActions::storeResult($flows, new QueryResult($records, 'nfdump -c ' . count($records), '', 0.1, TimeWindow::raw(0, 300), "x\n"), 0.1, '/_action/ip-info', [
        'list' => [...['tz' => 'UTC', 'sortKey' => '', 'sortDir' => '', 'hidden' => []], ...$list],
    ]);

    return $flows->resultId;
}

/** @return list<int> the file index of each row of $html, from its src_port */
function flowWindowTestIndices(string $html): array {
    preg_match_all('~<div role="cell">(\d+)(?: <small>\([^)]*\)</small>)?</div><div role="cell" data-kind="address">~', $html, $m);

    return array_map(static fn (string $port): int => (int) $port - 10_000, $m[1]);
}

/** @return list<string> the data-sort-key of each header button in $html */
function flowWindowTestHeader(string $html): array {
    preg_match_all('~data-sort-key="([^"]+)"~', $html, $m);

    return $m[1];
}

/** The time text of 2026-08-29T05:17:53 (in the server's zone, as nfdump printed it) in $zone. */
function flowWindowTestTime(string $zone): string {
    return new DateTimeImmutable('2026-08-29T05:17:53.000')->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
}

beforeEach(function (): void {
    FlowsState::makeRoom(static fn (): bool => false);
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    $this->prefsBefore = isset(Config::$prefsFile) ? Config::$prefsFile : null;
    Config::$prefsFile = sys_get_temp_dir() . '/nfsen-flows-window-missing.json';
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1'], 'ports' => [80]],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-flows-window-missing', 'profile' => 'live'],
        'frontend' => ['defaults' => ['view' => 'flows']],
    ]);
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
    if ($this->prefsBefore !== null) {
        Config::$prefsFile = $this->prefsBefore;
    }
});

describe('flows-window', function (): void {
    test('a window is one elements patch of the host by id, with the clamped offset, its rows and the shown columns', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows);

        $sent = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '900', 'count' => '150']);

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['type'])->toBe('elements')
            ->and($sent[0])->not->toHaveKey('selector')
            ->and($sent[0])->not->toHaveKey('mode')
            ->and($sent[0]['content'])->toStartWith(sprintf(
                '<sb-virtual-scroll id="flowRows-%s" offset="900" total="1000" aria-rowcount="1001" style="--flow-cols: %s" data-preserve-attr="%s"><div slot="header"',
                $id,
                FlowRowStore::template($id, ['first', 'in_bytes', 'src_port', 'src_addr']),
                FlowRows::PRESERVE,
            ))
            // Only data-preserve-attr names it: the patch itself must morph.
            ->and($sent[0]['content'])->not->toContain('data-ignore-morph=', ' data-ignore-morph>')
            ->and(flowWindowTestIndices($sent[0]['content']))->toBe(range(900, 999))
            ->and([$flows->lastOffset, $flows->lastCount])->toBe([900, 150])
        ;

        // Past the end the window is empty; a count above MAX_WINDOW is cut to it.
        $past = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '5000', 'count' => '10']);
        $wide = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '0', 'count' => '9999']);

        expect($past)->toHaveCount(1)
            ->and($past[0]['content'])->toContain('offset="1000" total="1000"')
            ->and(flowWindowTestIndices($past[0]['content']))->toBe([])
            ->and(flowWindowTestIndices($wide[0]['content']))->toBe(range(0, FlowRows::MAX_WINDOW - 1))
            ->and([$flows->lastOffset, $flows->lastCount])->toBe([0, FlowRows::MAX_WINDOW])
        ;
    });

    test('a window and its header carry only the shown columns, and the template of the shown columns', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 300, ['hidden' => ['src_port', 'not_in_this_result']]);

        $html = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '10', 'count' => '5'])[0]['content'];

        expect($flows->shownKeys())->toBe(['first', 'in_bytes', 'src_addr'])
            ->and($flows->hiddenColumns)->toBe(['src_port', 'not_in_this_result'])
            ->and(flowWindowTestHeader($html))->toBe(['first', 'in_bytes', 'src_addr'])
            ->and($html)->toContain('style="--flow-cols: ' . FlowRowStore::template($id, ['first', 'in_bytes', 'src_addr']) . '"')
            ->and(substr_count(FlowRowStore::template($id, $flows->shownKeys()), 'minmax('))->toBe(3)
            ->and(substr_count($html, 'role="cell"'))->toBe(5 * 3)
            ->and($html)->not->toContain('>10010<')
            ->and(substr_count($flows->firstRows, 'role="cell"'))->toBe(FlowRows::INITIAL_ROWS * 3)
        ;
    });

    test('times come in the zone of FlowsState, with no flows_tz signal in the context', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 5, ['tz' => 'America/New_York']);

        $html = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '0', 'count' => '5'])[0]['content'];

        expect($c->getSignal('flows_tz'))->toBeNull()
            ->and($flows->browserTz)->toBe('America/New_York')
            ->and($flows->zone()->getName())->toBe('America/New_York')
            ->and(substr_count($html, '>' . flowWindowTestTime('America/New_York') . '</time>'))->toBe(5)
        ;

        // A display time zone of 'server' writes the capture zone instead.
        Config::$settings = Config::$settings->withDisplayTimezone('server');
        $server = flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '0', 'count' => '1'])[0]['content'];
        expect($server)->toContain('>' . flowWindowTestTime(Config::nfcapdTimezone()->getName()) . '</time>');
    });

    test('a stale result, an html result or input that is no number queue nothing', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows);

        $sent = [];
        foreach ([['result' => 'older123', 'offset' => '0', 'count' => '10'], ['result' => $id, 'offset' => '-1', 'count' => '10'], ['result' => $id, 'offset' => '0']] as $input) {
            $sent = [...$sent, ...flowWindowTestRun($c, 'flows-window', $input)];
        }
        $sent = [...$sent, ...flowWindowTestRun($c, 'flows-sort', ['result' => 'older123', 'key' => 'in_bytes', 'dir' => 'asc'])];
        $sent = [...$sent, ...flowWindowTestRun($c, 'flows-columns', ['result' => 'older123', 'hidden' => ''])];
        $html = flowWindowTestStore($flows, []);
        $sent = [...$sent, ...flowWindowTestRun($c, 'flows-window', ['result' => $html, 'offset' => '0', 'count' => '10'])];

        expect($flows->mode)->toBe('html')
            ->and($sent)->toBe([])
            ->and($flows->rowsLost)->toBeFalse()
        ;
    });

    test('rows the store dropped: the tab syncs and shows the notice instead of the list', function (): void {
        [$app, $c, $states] = flowWindowTestCompose();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows);
        flowWindowTestRender($c, $app, $states);
        FlowsState::makeRoom(static fn (): bool => false);

        $synced = implode('', array_map(static fn (array $p): string => is_string($p['content'] ?? null) ? $p['content'] : '', flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '0', 'count' => '100'])));

        expect($flows->rowsLost)->toBeTrue()
            ->and($synced)->toContain('The server dropped these rows to free memory for other results. Run again to see them.')
            ->and($synced)->not->toContain("flowRows-{$id}")
        ;
    });

    test('every list action sees the rows dropped and says so', function (): void {
        foreach ([['flows-window', ['offset' => '0', 'count' => '10']], ['flows-sort', ['key' => 'in_bytes', 'dir' => 'asc']], ['flows-columns', ['hidden' => 'src_port']]] as [$action, $input]) {
            [, $c, $states] = flowWindowTestBare();
            $id = flowWindowTestStore($states->flows, 50);
            FlowsState::makeRoom(static fn (): bool => false);

            $sent = flowWindowTestRun($c, $action, ['result' => $id, ...$input]);

            expect($states->flows->rowsLost)->toBeTrue()
                ->and(array_filter($sent, static fn (array $p): bool => str_contains((string) ($p['content'] ?? ''), 'sb-virtual-scroll')))->toBe([])
            ;
        }
    });
});

describe('flows-sort', function (): void {
    test('a sort answers from the top with aria-sort, in the direction asked for', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 300);
        flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '100', 'count' => '200']);

        $ascending = flowWindowTestRun($c, 'flows-sort', ['result' => $id, 'key' => 'in_bytes', 'dir' => 'asc']);
        $descending = flowWindowTestRun($c, 'flows-sort', ['result' => $id, 'key' => 'in_bytes', 'dir' => 'desc']);

        // in_bytes is i % 7: the zeros first in file order, then the ones.
        $zeros = range(0, 299, 7);
        expect($ascending)->toHaveCount(1)
            ->and($ascending[0]['content'])->toStartWith("<sb-virtual-scroll id=\"flowRows-{$id}\" offset=\"0\" total=\"300\"")
            ->and($ascending[0]['content'])->toContain('<div role="columnheader" data-num aria-sort="ascending"><button type="button" data-sort-key="in_bytes">')
            ->and(array_slice(flowWindowTestIndices($ascending[0]['content']), 0, count($zeros)))->toBe($zeros)
            // As many rows as the last window asked for, at least INITIAL_ROWS.
            ->and(flowWindowTestIndices($ascending[0]['content']))->toHaveCount(200)
            ->and($descending[0]['content'])->toContain('aria-sort="descending"')
            ->and(array_slice(flowWindowTestIndices($descending[0]['content']), 0, 3))->toBe([6, 13, 20])
            ->and([$flows->sortKey, $flows->sortDir, $flows->lastOffset, $flows->lastCount])->toBe(['in_bytes', 'desc', 0, 200])
            ->and(flowWindowTestIndices($flows->firstRows))->toHaveCount(FlowRows::INITIAL_ROWS)
            ->and(array_slice(flowWindowTestIndices($flows->firstRows), 0, 3))->toBe([6, 13, 20])
        ;

        // Later windows come in the sorted order.
        expect(flowWindowTestIndices(flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '1', 'count' => '2'])[0]['content']))->toBe([13, 20]);
    });

    test('a sort takes dir and ignores anything else, and an unknown column', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 50);

        $sent = [];
        foreach ([['key' => 'in_bytes'], ['key' => 'in_bytes', 'dir' => 'up'], ['key' => 'in_bytes', 'dir' => 'DESC'], ['key' => 'nope', 'dir' => 'asc']] as $input) {
            $sent = [...$sent, ...flowWindowTestRun($c, 'flows-sort', ['result' => $id, ...$input])];
        }

        expect($sent)->toBe([])
            ->and([$flows->sortKey, $flows->sortChain, $flows->currentOrder()])->toBe(['', [], ''])
        ;
    });

    test('a retried sort or column request gives the same patch and the same state', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 300);
        flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '40', 'count' => '60']);

        $sort = ['result' => $id, 'key' => 'in_bytes', 'dir' => 'desc'];
        $first = flowWindowTestRun($c, 'flows-sort', $sort);
        $state = [$flows->sortChain, $flows->currentOrder(), $flows->firstRows];
        $again = flowWindowTestRun($c, 'flows-sort', $sort);

        expect($again)->toBe($first)
            ->and([$flows->sortChain, $flows->currentOrder(), $flows->firstRows])->toBe($state)
        ;

        $columns = ['result' => $id, 'hidden' => 'src_addr,first'];
        $first = flowWindowTestRun($c, 'flows-columns', $columns);
        $again = flowWindowTestRun($c, 'flows-columns', $columns);

        expect($again)->toBe($first)
            ->and($flows->hiddenColumns)->toBe(['src_addr', 'first'])
        ;
    });

    test('a new result keeps the Run\'s sort when it has that column, and a revival rebuilds the order', function (): void {
        [, , $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 50, ['sortKey' => 'in_bytes', 'sortDir' => 'desc']);

        expect(array_slice(flowWindowTestIndices($flows->firstRows), 0, 2))->toBe([6, 13])
            ->and(substr_count($flows->firstRows, 'role="row"'))->toBe(50)
            ->and($flows->sortChain)->toBe([['in_bytes', 'desc']])
        ;

        $revived = new FlowsState();
        $revived->restore($flows->snapshot());
        expect([$revived->mode, $revived->sortKey, $revived->sortDir, $revived->firstRows, $revived->order])->toBe(['list', 'in_bytes', 'desc', '', null])
            ->and(array_slice(array_values(unpack('N*', $revived->currentOrder() ?? '') ?: []), 0, 2))->toBe([6, 13])
            ->and($revived->resultId)->toBe($id)
        ;

        // A Run whose sort names a column the result lacks lists in file order.
        flowWindowTestStore($flows, 50, ['sortKey' => 'dst_port', 'sortDir' => 'asc']);
        expect([$flows->sortKey, $flows->sortDir, $flows->sortChain, $flows->currentOrder()])->toBe(['', '', [], ''])
            ->and(array_slice(flowWindowTestIndices($flows->firstRows), 0, 3))->toBe([0, 1, 2])
        ;
    });

    test('a revival replays the tab\'s sorts, so tied rows keep the order they had', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        // in_bytes (i % 7) and in_packets (i % 2) tie in groups; src_port names each row's file index.
        $rows = array_map(static fn (array $row): array => ['in_packets' => ($row['src_port'] - 10_000) % 2, ...$row], flowWindowTestRows(60));
        $id = flowWindowTestStore($flows, $rows);
        foreach (['in_bytes', 'in_packets', 'in_bytes'] as $key) {
            flowWindowTestRun($c, 'flows-sort', ['result' => $id, 'key' => $key, 'dir' => 'asc']);
        }
        $live = $flows->currentOrder();

        $revived = new FlowsState();
        $revived->restore($flows->snapshot());

        // The first Bytes sort decides nothing once Bytes is sorted again.
        expect($flows->sortChain)->toBe([['in_packets', 'asc'], ['in_bytes', 'asc']])
            ->and($revived->sortChain)->toBe($flows->sortChain)
            ->and($revived->currentOrder())->toBe($live)
            // The last sort alone would put the ties in file order.
            ->and(FlowRowStore::order($id, 'in_bytes', 'asc'))->not->toBe($live)
        ;
    });
});

describe('flows-columns', function (): void {
    test('a column change answers at the last window with the new header, rows and template', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 300);
        flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '200', 'count' => '40']);

        $sent = flowWindowTestRun($c, 'flows-columns', ['result' => $id, 'hidden' => 'src_port,not_in_this_result']);
        $html = $sent[0]['content'];

        expect($sent)->toHaveCount(1)
            ->and($html)->toStartWith("<sb-virtual-scroll id=\"flowRows-{$id}\" offset=\"200\" total=\"300\"")
            ->and($html)->toContain('style="--flow-cols: ' . FlowRowStore::template($id, ['first', 'in_bytes', 'src_addr']) . '"')
            ->and(flowWindowTestHeader($html))->toBe(['first', 'in_bytes', 'src_addr'])
            ->and(substr_count($html, '<div role="row" aria-rowindex="'))->toBe(40)
            ->and($html)->toContain('<div role="row" aria-rowindex="202"')
            ->and(substr_count($html, 'role="cell"'))->toBe(40 * 3)
            ->and($flows->hiddenColumns)->toBe(['src_port', 'not_in_this_result'])
            ->and(substr_count($flows->firstRows, 'role="cell"'))->toBe(FlowRows::INITIAL_ROWS * 3)
        ;

        // Showing every column again; a list that took no window yet answers with its first rows.
        $all = flowWindowTestRun($c, 'flows-columns', ['result' => $id, 'hidden' => '']);
        expect(flowWindowTestHeader($all[0]['content']))->toBe(['first', 'in_bytes', 'src_port', 'src_addr'])
            ->and($flows->hiddenColumns)->toBe([])
        ;
        $fresh = flowWindowTestStore($flows, 300);
        $first = flowWindowTestRun($c, 'flows-columns', ['result' => $fresh, 'hidden' => 'first']);
        expect($first[0]['content'])->toStartWith("<sb-virtual-scroll id=\"flowRows-{$fresh}\" offset=\"0\" total=\"300\"")
            ->and(substr_count($first[0]['content'], '<div role="row" aria-rowindex="'))->toBe(FlowRows::INITIAL_ROWS)
        ;
    });

    test('a hidden set keeps its valid keys, at most MAX_HIDDEN with the result\'s keys first', function (): void {
        [, $c, $states] = flowWindowTestBare();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 30, ['hidden' => ['first']]);
        $others = array_map(static fn (int $i): string => "k{$i}", range(1, FlowsState::MAX_HIDDEN));

        expect(flowWindowTestRun($c, 'flows-columns', ['result' => $id]))->toBe([])
            ->and($flows->hiddenColumns)->toBe(['first'])
        ;

        $sent = flowWindowTestRun($c, 'flows-columns', ['result' => $id, 'hidden' => implode(',', [...$others, 'src port', 'src_port'])]);
        expect($sent)->toHaveCount(1)
            ->and(flowWindowTestHeader($sent[0]['content']))->toBe(['first', 'in_bytes', 'src_addr'])
            ->and($flows->hiddenColumns)->toBe(['src_port', ...array_slice($others, 0, FlowsState::MAX_HIDDEN - 1)])
        ;

        // Nothing valid left: every column shows.
        $all = flowWindowTestRun($c, 'flows-columns', ['result' => $id, 'hidden' => 'src port,src_port;' . str_repeat('x', 65)]);
        expect(flowWindowTestHeader($all[0]['content']))->toBe(['first', 'in_bytes', 'src_port', 'src_addr'])
            ->and($flows->hiddenColumns)->toBe([])
            ->and(FlowsState::hiddenFrom('srcAddr,firstSeen,in_bytes,in_bytes'))->toBe(['srcAddr', 'firstSeen', 'in_bytes'])
            ->and(FlowsState::hiddenFrom(['a', 3, 'b c', ['x'], 'b', '123']))->toBe(['a', 'b', '123'])
            ->and(FlowsState::hiddenFrom(null))->toBe([])
        ;
    });
});

describe('revival', function (): void {
    test('every list action restores the tab\'s snapshot first, and sort and columns persist it', function (): void {
        [$app, $c, $states] = flowWindowTestBare();
        $id = flowWindowTestStore($states->flows, 300, ['tz' => 'Asia/Tokyo', 'sortKey' => 'in_bytes', 'sortDir' => 'desc', 'hidden' => ['src_addr']]);
        Revival::persist($c, $app, $states);
        $tab = $c->getId();

        // The worker revives the context for a window POST: a fresh PageStates, nothing rendered.
        [, $revived, $fresh] = flowWindowTestBare($app, $tab);
        $window = flowWindowTestRun($revived, 'flows-window', ['result' => $id, 'offset' => '0', 'count' => '20']);

        expect($window)->toHaveCount(1)
            ->and(flowWindowTestHeader($window[0]['content']))->toBe(['first', 'in_bytes', 'src_port'])
            ->and($window[0]['content'])->toContain('aria-sort="descending"', '>' . flowWindowTestTime('Asia/Tokyo') . '</time>')
            ->and(substr_count($window[0]['content'], '<div role="row" aria-rowindex="'))->toBe(20)
            ->and([$fresh->flows->resultId, $fresh->flows->sortKey, $fresh->flows->hiddenColumns])->toBe([$id, 'in_bytes', ['src_addr']])
            ->and(substr_count($fresh->flows->firstRows, '<div role="row" aria-rowindex="'))->toBe(FlowRows::INITIAL_ROWS)
        ;

        // A sort in another revival of the tab: restored, applied, persisted.
        [, $again, $third] = flowWindowTestBare($app, $tab);
        expect(flowWindowTestRun($again, 'flows-sort', ['result' => $id, 'key' => 'src_port', 'dir' => 'asc']))->toHaveCount(1)
            ->and($third->flows->sortChain)->toBe([['in_bytes', 'desc'], ['src_port', 'asc']])
            ->and($app->globalState(Revival::KEY)[$tab]['flows']['sortKey'] ?? null)->toBe('src_port')
            ->and($app->globalState(Revival::KEY)[$tab]['flows']['sortChain'] ?? null)->toBe([['in_bytes', 'desc'], ['src_port', 'asc']])
        ;

        // Columns likewise; the restored sort chain still orders the rows.
        [, $later, $fourth] = flowWindowTestBare($app, $tab);
        $columns = flowWindowTestRun($later, 'flows-columns', ['result' => $id, 'hidden' => 'first']);
        expect(flowWindowTestHeader($columns[0]['content']))->toBe(['in_bytes', 'src_port', 'src_addr'])
            ->and($fourth->flows->hiddenColumns)->toBe(['first'])
            ->and($app->globalState(Revival::KEY)[$tab]['flows']['hiddenColumns'] ?? null)->toBe(['first'])
            ->and($fourth->flows->currentOrder())->toBe($third->flows->currentOrder())
        ;
    });

    test('a window in a revived context whose snapshot names another result queues nothing', function (): void {
        [$app, $c, $states] = flowWindowTestBare();
        $id = flowWindowTestStore($states->flows, 30);
        Revival::persist($c, $app, $states);
        [, $revived] = flowWindowTestBare($app, $c->getId());

        expect(flowWindowTestRun($revived, 'flows-window', ['result' => 'ab' . substr($id, 2, 4) . 'zz', 'offset' => '0', 'count' => '5']))->toBe([]);
    });
});

describe('the Run', function (): void {
    test('copies the browser\'s zone, sort and hidden columns, keeping only what is valid', function (): void {
        [, $c] = flowWindowTestBare();
        $tz = $c->signal('Asia/Tokyo', 'flows_tz', clientWritable: true);
        $sort = $c->signal('src_port desc', 'flows_sort', clientWritable: true);
        $hidden = $c->signal(['srcAddr', 'first'], 'flows_hidden', clientWritable: true);

        expect(FlowActions::listInputs($c))->toBe(['tz' => 'Asia/Tokyo', 'sortKey' => 'src_port', 'sortDir' => 'desc', 'hidden' => ['srcAddr', 'first']]);

        // The hidden keys are checked against the result once it is stored.
        $tz->setValue('Not/AZone', broadcast: false);
        $sort->setValue('src_port sideways', broadcast: false);
        $hidden->setValue(['ok', ['nested'], 'bad key'], broadcast: false);
        expect(FlowActions::listInputs($c))->toBe(['tz' => '', 'sortKey' => '', 'sortDir' => '', 'hidden' => ['ok', 'bad key']]);

        $sort->setValue('', broadcast: false);
        expect(FlowActions::listInputs($c)['sortKey'])->toBe('');
    });

    test('a hidden set past MAX_HIDDEN keeps the result\'s keys, so the Run still hides them', function (): void {
        [, , $states] = flowWindowTestBare();
        $flows = $states->flows;
        $others = array_map(static fn (int $i): string => "k{$i}", range(1, FlowsState::MAX_HIDDEN));
        flowWindowTestStore($flows, 30, ['hidden' => [...$others, 'bad key', 'src_port']]);

        expect($flows->hiddenColumns)->toBe(['src_port', ...array_slice($others, 0, FlowsState::MAX_HIDDEN - 1)])
            ->and($flows->shownKeys())->toBe(['first', 'in_bytes', 'src_addr'])
        ;
    });

    test('none of them is part of the query\'s fingerprint', function (): void {
        [, $c, $states] = flowWindowTestCompose();
        $before = FlowActions::fingerprintOf(FlowActions::inputs($c));
        $c->getSignal('flows_tz')?->setValue('Asia/Tokyo', broadcast: false);
        $c->getSignal('flows_sort')?->setValue('in_bytes desc', broadcast: false);
        $c->getSignal('flows_hidden')?->setValue(['first'], broadcast: false);

        expect(FlowActions::fingerprintOf(FlowActions::inputs($c)))->toBe($before)
            ->and($states->flows->isEmpty())->toBeTrue()
        ;
    });
});

describe('the page render', function (): void {
    test('viewData hands the page the list from stored state: its id, its host and every column; null for html and lost rows', function (): void {
        [$app, $c, $states] = flowWindowTestCompose();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 30, ['hidden' => ['src_port', 'not_in_this_result']]);
        $first = $flows->firstRows;

        $list = FlowsPage::viewData($c, $app, $states, false)['list'];
        $flows->markRendered(true);

        expect(array_keys($list ?? []))->toBe(['id', 'host', 'columns'])
            ->and($list['id'] ?? null)->toBe($id)
            ->and($list['host'] ?? '')->toStartWith("<sb-virtual-scroll id=\"flowRows-{$id}\" role=\"table\"")
            // No window assembled: the host wraps the first rows the Run stored.
            ->and($list['host'] ?? '')->toContain('</div></div>' . $first . '</sb-virtual-scroll>')
            ->and($flows->firstRows)->toBe($first)
            ->and($list['columns'] ?? [])->toBe([
                ['key' => 'first', 'title' => 'First', 'hidden' => false],
                ['key' => 'in_bytes', 'title' => 'In Bytes', 'hidden' => false],
                ['key' => 'src_port', 'title' => 'Src Port', 'hidden' => true],
                ['key' => 'src_addr', 'title' => 'Source IP', 'hidden' => false],
            ])
            ->and(FlowsPage::viewData($c, $app, $states, true)['list']['host'] ?? '')->toBe(FlowRows::placeholder($id))
        ;

        // Dropped rows: a list the client holds stays, with its columns, until a render must send it.
        FlowsState::makeRoom(static fn (): bool => false);
        $kept = FlowsPage::viewData($c, $app, $states, true)['list'];
        expect($kept['host'] ?? '')->toBe(FlowRows::placeholder($id))
            ->and(array_column($kept['columns'] ?? [], 'key'))->toBe(['first', 'in_bytes', 'src_port', 'src_addr'])
            ->and($flows->rowsLost)->toBeFalse()
            ->and(FlowsPage::viewData($c, $app, $states, false)['list'])->toBeNull()
            ->and($flows->rowsLost)->toBeTrue()
        ;

        flowWindowTestStore($flows, []);
        $html = FlowsPage::viewData($c, $app, $states, true);
        expect($html['list'])->toBeNull()
            ->and($html['tableHtml'])->toContain('No flows match this query')
        ;
    });

    test('the host for a new result, the placeholder afterwards', function (): void {
        [$app, $c, $states] = flowWindowTestCompose();
        $id = flowWindowTestStore($states->flows, 300);

        $first = flowWindowTestRender($c, $app, $states);
        $second = flowWindowTestRender($c, $app, $states, true);

        expect($first)->toContain(
            "<sb-virtual-scroll id=\"flowRows-{$id}\" role=\"table\" aria-label=\"Flows\" aria-rowcount=\"301\" item-size=\"" . FlowRows::ITEM_SIZE . '" buffer="' . FlowRows::BUFFER_PX . '" offset="0" total="300"',
            '/_action/flows-window',
            '/_action/flows-sort',
        )
            ->and(substr_count($first, '<div role="row" aria-rowindex='))->toBe(FlowRows::INITIAL_ROWS)
            ->and($first)->not->toContain('flowTableHost-', '<nfsen-table')
            ->and($second)->toContain("<sb-virtual-scroll id=\"flowRows-{$id}\" data-ignore-morph></sb-virtual-scroll>")
            ->and(substr_count($second, 'role="row"'))->toBe(0)
        ;

        // An empty result renders the empty state through the result host.
        $empty = flowWindowTestStore($states->flows, []);
        $html = flowWindowTestRender($c, $app, $states, true);
        expect($html)->toContain("flowTableHost-{$empty}", 'No flows match this query in the selected range.')
            ->and($html)->not->toContain('<sb-virtual-scroll')
        ;
    });

    test('compact tables make the rows compact from the next Run', function (): void {
        [, , $states] = flowWindowTestBare();
        Config::$settings = Config::$settings->withCompactTables(true);
        flowWindowTestStore($states->flows, 10);

        expect($states->flows->itemSize)->toBe(FlowRows::ITEM_SIZE_COMPACT);
    });

    test('after a revival the host goes out without its window, in the tab\'s sort', function (): void {
        [$app, $c, $states] = flowWindowTestCompose();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 100, ['sortKey' => 'in_bytes', 'sortDir' => 'asc']);
        $flows->restore($flows->snapshot());

        $html = flowWindowTestRender($c, $app, $states);

        expect($html)->toContain("<sb-virtual-scroll id=\"flowRows-{$id}\" role=\"table\" aria-label=\"Flows\" item-size=")
            ->and($html)->toContain('aria-sort="ascending"')
            ->and(substr_count($html, 'role="row" aria-rowindex="1"'))->toBe(1)
            ->and($html)->not->toContain('aria-rowindex="2"')
        ;
    });

    test('after a revival the next window rebuilds the first rows, so a later render sends the list with them', function (): void {
        [$app, $c, $states] = flowWindowTestCompose();
        $flows = $states->flows;
        $id = flowWindowTestStore($flows, 300);
        flowWindowTestRun($c, 'flows-sort', ['result' => $id, 'key' => 'in_bytes', 'dir' => 'asc']);
        $live = $flows->firstRows;
        $flows->restore($flows->snapshot());
        expect($flows->firstRows)->toBe('');

        flowWindowTestRun($c, 'flows-window', ['result' => $id, 'offset' => '200', 'count' => '40']);
        $html = flowWindowTestRender($c, $app, $states);

        expect($flows->firstRows)->toBe($live)
            ->and(flowWindowTestIndices($flows->firstRows))->toHaveCount(FlowRows::INITIAL_ROWS)
            ->and($html)->toContain("<sb-virtual-scroll id=\"flowRows-{$id}\" role=\"table\" aria-label=\"Flows\" aria-rowcount=\"301\"")
            ->and(substr_count($html, '<div role="row" aria-rowindex='))->toBe(FlowRows::INITIAL_ROWS)
        ;
    });
});
