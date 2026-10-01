<?php

/**
 * FlowRows: the host's markup keeps K1 and Datastar's parser happy, list requests post only via_ctx,
 * the window patch keeps what the page set, and a cell shows what the table's cell shows.
 */

declare(strict_types=1);

use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;
use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;

/** Datastar 1.0.4's attribute plugins and nfsen-ng's persist, as tests/e2e/rocket.test.mjs lists them, plus the morph's own. */
const FLOW_ROWS_TEST_PLUGINS = '/^data-(attr|bind|class|computed|effect|indicator|init|json-signals|on|on-intersect|on-interval|on-signal-patch|ref|show|signals|style|text|persist|ignore-morph|preserve-attr)(:|__|$)/';

const FLOW_ROWS_TEST_SLIM = ", { filterSignals: { include: /^via_ctx$/ }, requestCancellation: 'disabled' })";

/** @return list<array<string, mixed>> records of the dev data's shape, with the cases a cell formats differently */
function flowRowsTestRecords(): array {
    return [
        ['cnt' => 1, 'type' => 'FLOW', 'first' => '2026-08-29T05:17:53.000', 'last' => '2026-08-29T05:17:54.000', 'in_packets' => 10, 'in_bytes' => 1000,
            'proto' => 6, 'tcp_flags' => '...AP...', 'src_port' => 10_000, 'dst_port' => 443, 'src_addr' => '10.0.0.1', 'dst_addr' => '2001:db8::1',
            'src_geo' => '', 'ip_router' => '127.0.0.1', 'note' => '<b>&"x"</b>'],
        ['cnt' => 2, 'first' => 'not a date', 'in_packets' => 1_234_567, 'in_bytes' => 5_000_000_000, 'proto' => 1, 'src_port' => 0,
            'src_addr' => '10.0.1.1', 'dst_addr' => 'n/a', 'note' => ['nested' => true]],
    ];
}

/** @param list<string> $shown */
function flowRowsTestHost(string $firstRows = '<div role="row" aria-rowindex="2"><div role="cell">x</div></div>', array $shown = ['in_bytes', 'src_addr']): string {
    return FlowRows::host([
        'result' => 'ab12cd34',
        'total' => 10_000,
        'itemSize' => FlowRows::ITEM_SIZE,
        'columns' => [
            'keys' => ['in_bytes', 'src_addr'],
            'kinds' => ['in_bytes' => 'num', 'src_addr' => 'address'],
            'titles' => ['in_bytes' => 'In Bytes', 'src_addr' => 'Source IP'],
            'widths' => ['in_bytes' => 12, 'src_addr' => 13],
        ],
        'shown' => $shown,
        'template' => 'minmax(12ch, 1fr) minmax(13ch, 1fr)',
        'sortKey' => 'in_bytes',
        'sortDir' => 'desc',
        'firstRows' => $firstRows,
        'windowUrl' => '/_action/flows-window',
        'sortUrl' => '/_action/flows-sort',
        'ipInfoUrl' => "/_action/ip-info'x",
    ]);
}

function flowRowsTestElement(string $html): Element {
    $doc = HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);
    $host = $doc->querySelector('sb-virtual-scroll');
    assert($host instanceof Element);

    return $host;
}

/** @return list<string> what breaks the rules inside the host: ids, table elements, plugin attributes */
function flowRowsTestProblems(Element $host): array {
    $problems = [];
    foreach ($host->querySelectorAll('*') as $el) {
        if (in_array($el->localName, ['tr', 'td', 'th', 'table', 'tbody', 'thead'], true)) {
            $problems[] = $el->localName;
        }
        foreach ($el->attributes as $attribute) {
            if ($attribute->name === 'id' || preg_match(FLOW_ROWS_TEST_PLUGINS, $attribute->name) === 1) {
                $problems[] = $el->localName . ' ' . $attribute->name;
            }
        }
    }

    return $problems;
}

/** @return list<string> the data-sort-key of each header button */
function flowRowsTestHeaderKeys(Element $host): array {
    return array_map(static fn (Element $b): string => $b->getAttribute('data-sort-key') ?? '', iterator_to_array($host->querySelectorAll('button[data-sort-key]')));
}

beforeEach(function (): void {
    FlowsState::makeRoom(static fn (): bool => false);
});

describe('the host', function (): void {
    test('the page render: a named table with its window, the handlers on the host itself', function (): void {
        $host = flowRowsTestElement(flowRowsTestHost());
        $names = array_values(array_map(static fn (Attr $a): string => $a->name, iterator_to_array($host->attributes)));

        expect($names)->toBe(FlowRows::PAGE_ATTRIBUTES)
            ->and($host->getAttribute('id'))->toBe('flowRows-ab12cd34')
            ->and($host->getAttribute('role'))->toBe('table')
            ->and($host->getAttribute('aria-label'))->toBe('Flows')
            ->and($host->getAttribute('aria-rowcount'))->toBe('10001')
            ->and([$host->getAttribute('offset'), $host->getAttribute('total')])->toBe(['0', '10000'])
            ->and($host->getAttribute('style'))->toBe('--flow-cols: minmax(12ch, 1fr) minmax(13ch, 1fr)')
            ->and($host->hasAttribute('data-ignore-morph'))->toBeTrue()
            ->and(flowRowsTestProblems($host))->toBe([])
        ;
    });

    test('the window and sort POSTs carry only via_ctx and cancel nothing; the IP info POST stays a full one', function (): void {
        $host = flowRowsTestElement(flowRowsTestHost());
        $click = (string) $host->getAttribute('data-on:click');

        expect($host->getAttribute('data-on:sb-window'))->toBe("@post('/_action/flows-window?result=ab12cd34&offset=' + evt.detail.offset + '&count=' + evt.detail.count" . FLOW_ROWS_TEST_SLIM)
            ->and($click)->toContain(
                "const a = evt.target.closest('a.ip-link'); if (a) { evt.preventDefault(); @post('/_action/ip-info\\'x?ip=' + encodeURIComponent(a.textContent.trim())) } ",
                "@post('/_action/flows-sort?result=ab12cd34&key=' + encodeURIComponent(s.dataset.sortKey) + '&dir=' + d" . FLOW_ROWS_TEST_SLIM . ' }',
            )
            ->and(substr_count($click, 'filterSignals'))->toBe(1)
            ->and(substr_count($click, "requestCancellation: 'disabled'"))->toBe(1)
            ->and(FlowRows::SLIM)->toBe("{ filterSignals: { include: /^via_ctx$/ }, requestCancellation: 'disabled' }")
        ;
    });

    test('a sort click reads its direction from the header, records the choice and goes to the top', function (): void {
        $click = (string) flowRowsTestElement(flowRowsTestHost())->getAttribute('data-on:click');

        expect($click)->toContain(
            "const s = evt.target.closest('button[data-sort-key]'); if (s) { const d = s.parentElement.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc'; "
                . "\$_flows_sort = s.dataset.sortKey + ' ' + d; el.scrollToIndex(0); @post(",
        );
    });

    test('every attribute the page sets survives a window, except the ones the window sets', function (): void {
        $preserved = explode(' ', FlowRows::PRESERVE);
        $set = array_values(array_diff(FlowRows::PAGE_ATTRIBUTES, ['id', 'offset', 'total', 'aria-rowcount', 'style']));

        expect(array_values(array_diff($set, $preserved)))->toBe([])
            ->and($preserved)->toContain('data-rocket-host')
            ->and($preserved)->not->toContain('style')
        ;
    });

    test('after a revival the page render leaves the window out, and the list asks for it', function (): void {
        $host = flowRowsTestElement(flowRowsTestHost(''));

        expect($host->hasAttribute('total'))->toBeFalse()
            ->and($host->hasAttribute('offset'))->toBeFalse()
            ->and($host->hasAttribute('aria-rowcount'))->toBeFalse()
            ->and($host->childElementCount)->toBe(1)
            ->and($host->firstElementChild?->getAttribute('slot'))->toBe('header')
        ;
    });

    test('the header: one sort button per shown column, aria-sort on the sorted one', function (): void {
        $host = flowRowsTestElement(flowRowsTestHost());
        $header = $host->querySelector('[slot="header"]');

        expect($header?->getAttribute('role'))->toBe('row')
            ->and($header?->getAttribute('aria-rowindex'))->toBe('1')
            ->and($header?->hasAttribute('data-ignore'))->toBe(FlowRows::ROW_IGNORE)
            ->and(flowRowsTestHeaderKeys($host))->toBe(['in_bytes', 'src_addr'])
            ->and($host->querySelector('[role="columnheader"][data-num]')?->getAttribute('aria-sort'))->toBe('descending')
            ->and($host->querySelectorAll('[aria-sort]')->length)->toBe(1)
            ->and($host->querySelector('button[data-sort-key="src_addr"]')?->textContent)->toBe('Source IP')
        ;
    });

    test('the header leaves out the hidden columns and keys the result lacks', function (): void {
        $host = flowRowsTestElement(flowRowsTestHost(shown: ['src_addr', 'nope']));

        expect(flowRowsTestHeaderKeys($host))->toBe(['src_addr'])
            ->and($host->querySelectorAll('[aria-sort]')->length)->toBe(0)
        ;
    });

    test('the window patch: no data-ignore-morph, the page\'s attributes preserved, the template, header and rows', function (): void {
        FlowRowStore::store('ab12cd34', flowRowsTestRecords(), ['ipInfoActionUrl' => '/_action/ip-info']);
        $columns = FlowRowStore::columns('ab12cd34');
        assert($columns !== null);
        $rows = FlowRowStore::window('ab12cd34', 0, 2, '', new DateTimeZone('UTC'), $columns['keys']);
        assert($rows !== null);
        $template = FlowRowStore::template('ab12cd34', $columns['keys']);
        $host = flowRowsTestElement(FlowRows::window('ab12cd34', 0, 2, $template, FlowRows::header($columns, $columns['keys'], '', ''), $rows['html']));

        expect($host->getAttribute('id'))->toBe('flowRows-ab12cd34')
            ->and($host->hasAttribute('data-ignore-morph'))->toBeFalse()
            ->and($host->getAttribute('data-preserve-attr'))->toBe(FlowRows::PRESERVE)
            ->and([$host->getAttribute('offset'), $host->getAttribute('total'), $host->getAttribute('aria-rowcount')])->toBe(['0', '2', '3'])
            ->and($host->getAttribute('style'))->toBe('--flow-cols: ' . $template)
            ->and($host->querySelectorAll('[role="row"]')->length)->toBe(3)
            ->and($host->querySelectorAll('[role="row"]:not([slot]) > [role="cell"]')->length)->toBe(2 * count($columns['keys']))
            ->and(flowRowsTestProblems($host))->toBe([])
        ;
    });

    test('a window of the shown columns carries only their header, cells and template', function (): void {
        FlowRowStore::store('ab12cd35', flowRowsTestRecords(), ['ipInfoActionUrl' => '/_action/ip-info']);
        $columns = FlowRowStore::columns('ab12cd35');
        assert($columns !== null);
        $shown = ['in_bytes', 'src_addr'];
        $rows = FlowRowStore::window('ab12cd35', 0, 2, '', new DateTimeZone('UTC'), $shown);
        assert($rows !== null);
        $host = flowRowsTestElement(FlowRows::window('ab12cd35', 0, 2, FlowRowStore::template('ab12cd35', $shown), FlowRows::header($columns, $shown, '', ''), $rows['html']));
        $cells = array_map(static fn (Element $cell): string => trim($cell->textContent ?? ''), iterator_to_array($host->querySelectorAll('[role="row"]:not([slot]) > [role="cell"]')));

        expect(flowRowsTestHeaderKeys($host))->toBe($shown)
            ->and($cells)->toBe(['1000.0 B', '10.0.0.1', '4.657 GiB', '10.0.1.1'])
            ->and($host->getAttribute('style'))->toBe(sprintf('--flow-cols: minmax(%dch, 1fr) minmax(%dch, 1fr)', $columns['widths']['in_bytes'], $columns['widths']['src_addr']))
        ;
    });

    test('the placeholder', function (): void {
        expect(FlowRows::placeholder('ab12cd34'))->toBe('<sb-virtual-scroll id="flowRows-ab12cd34" data-ignore-morph></sb-virtual-scroll>');
    });
});

describe('cells', function (): void {
    test('a cell holds what the table\'s cell holds for the same record, with the same kind', function (): void {
        $records = flowRowsTestRecords();
        $options = ['linkIpAddresses' => true, 'ipInfoActionUrl' => '/_action/ip-info'];
        FlowRowStore::store('c1', $records, $options);
        $keys = FlowRowStore::columns('c1')['keys'] ?? [];
        $table = HTMLDocument::createFromString('<!doctype html><body>' . Table::generate($records, 'flowTable', $options), LIBXML_NOERROR);
        $zone = new DateTimeZone(date_default_timezone_get());
        $list = flowRowsTestElement(FlowRows::window('c1', 0, 2, '', '', FlowRowStore::window('c1', 0, 2, '', $zone, $keys)['html'] ?? ''));

        $tableRows = iterator_to_array($table->querySelectorAll('tbody tr'));
        $listRows = iterator_to_array($list->querySelectorAll('[role="row"]'));
        expect($listRows)->toHaveCount(2);
        foreach ($tableRows as $r => $tr) {
            $tds = iterator_to_array($tr->querySelectorAll('td'));
            $cells = iterator_to_array($listRows[$r]->querySelectorAll('[role="cell"]'));
            expect($cells)->toHaveCount(count($tds));
            foreach ($tds as $i => $td) {
                expect($cells[$i]->innerHTML)->toBe($td->innerHTML)
                    ->and($cells[$i]->getAttribute('data-kind'))->toBe($td->getAttribute('data-kind'))
                    ->and($cells[$i]->hasAttribute('data-num'))->toBe($td->hasAttribute('data-num'))
                    ->and($cells[$i]->hasAttribute('data-sort-value') || $cells[$i]->hasAttribute('data-raw'))->toBeFalse()
                ;
            }
        }
        expect(FlowRowStore::columns('c1')['titles'] ?? [])->toBe(array_combine(
            $keys,
            array_map(static fn (Element $th): string => trim($th->textContent ?? ''), iterator_to_array($table->querySelectorAll('thead th'))),
        ));
    });

    test('rows: their place in the whole list, every other one striped', function (): void {
        expect(FlowRows::row(0, 'c'))->toBe('<div role="row" aria-rowindex="2"' . (FlowRows::ROW_IGNORE ? ' data-ignore' : '') . '>c</div>')
            ->and(FlowRows::row(9_999, ''))->toContain('aria-rowindex="10001" data-stripe')
            ->and(FlowRows::itemSize(true))->toBe(FlowRows::ITEM_SIZE_COMPACT)
            ->and(FlowRows::itemSize(false))->toBe(FlowRows::ITEM_SIZE)
            ->and(FlowRows::cell('num', '7'))->toBe('<div role="cell" data-num>7</div>')
            ->and(FlowRows::cell('address', 'a'))->toBe('<div role="cell" data-kind="address">a</div>')
            ->and(FlowRows::cell('text', 'a'))->toBe('<div role="cell">a</div>')
        ;
    });
});
