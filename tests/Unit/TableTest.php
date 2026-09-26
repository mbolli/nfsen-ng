<?php

declare(strict_types=1);

use mbolli\nfsen_ng\common\Table;

describe('Table', function (): void {
    describe('generate', function (): void {
        test('generates HTML table from array data', function (): void {
            $data = [
                ['name' => 'Test', 'value' => 123],
                ['name' => 'Test2', 'value' => 456],
            ];

            $result = Table::generate($data, 'testTable');

            expect($result)
                ->toBeString()
                ->toContain('<table')
                ->toContain('testTable')
                ->toContain('Test')
                ->toContain('123')
            ;
        });

        test('handles empty data with custom message', function (): void {
            $result = Table::generate([], 'emptyTable', [
                'emptyMessage' => 'No records found',
            ]);

            expect($result)
                ->toContain('No records found')
                ->toContain('emptyTable')
            ;
        });

        test('hides specified fields', function (): void {
            $data = [
                ['visible' => 'yes', 'hidden' => 'no', 'cnt' => 5],
            ];

            $result = Table::generate($data, 'testTable', [
                'hiddenFields' => ['hidden', 'cnt'],
            ]);

            expect($result)
                ->toContain('visible')
                ->not->toContain('>hidden<')
                ->not->toContain('>cnt<')
            ;
        });

        // The table is styled as a table, by element. There is no class to pass and
        // nothing that could want a different one.
        test('emits a plain table element', function (): void {
            $result = Table::generate([['col' => 'val']], 'testTable');

            expect($result)->toContain('<table>')
                ->and($result)->not->toContain('<table class=')
            ;
        });

        test('handles string data rows as preformatted text', function (): void {
            $data = ['line 1', 'line 2', 'line 3'];

            $result = Table::generate($data, 'textTable');

            expect($result)
                ->toContain('<pre>')
                ->toContain('line 1')
            ;
        });

        test('generates proper table headers', function (): void {
            $data = [
                ['srcip' => '10.0.0.1', 'dstip' => '10.0.0.2', 'bytes' => 1000],
            ];

            $result = Table::generate($data, 'flowTable');

            expect($result)
                ->toContain('<th')
                ->toContain('<thead')
            ;
        });

        test('generates table body with data rows', function (): void {
            $data = [
                ['ip' => '10.0.0.1', 'port' => 80],
                ['ip' => '10.0.0.2', 'port' => 443],
            ];

            $result = Table::generate($data, 'dataTable');

            expect($result)
                ->toContain('<tbody')
                ->toContain('10.0.0.1')
                ->toContain('10.0.0.2')
            ;
        });

        test('builds columns from all rows, not just the first (#157)', function (): void {
            // nfdump's JSON output only carries the fields a record actually has, so a
            // TCP row and an ICMP row have different schemas.
            $data = [
                ['proto' => 6, 'src_port' => 443, 'src_addr' => '10.0.0.1'],
                ['proto' => 58, 'icmp_type' => 128, 'src_addr' => 'fe80::1'],
            ];

            $result = Table::generate($data, 'flowTable');

            expect($result)
                ->toContain('data-original-title="src_port"')
                ->toContain('data-original-title="icmp_type"')
                ->toContain('10.0.0.1')
                ->toContain('fe80::1')
            ;
        });

        test('inserts a new column next to its neighbour in the row it came from', function (): void {
            $data = [
                ['a' => 1, 'b' => 2, 'c' => 3],
                ['a' => 4, 'x' => 5, 'c' => 6],
            ];

            preg_match_all(
                '/data-original-title="([^"]+)"/',
                Table::generate($data, 'orderTable'),
                $matches
            );

            expect($matches[1])->toBe(['a', 'x', 'b', 'c']);
        });

        test('renders addresses of both families in one column (#157)', function (): void {
            $data = [
                ['src_addr' => '10.0.0.1', 'dst_addr' => '10.0.0.2'],
                ['src_addr' => '2001:db8::1', 'dst_addr' => '2001:db8::2'],
            ];

            $result = Table::generate($data, 'flowTable');

            preg_match_all('/data-original-title="([^"]+)"/', $result, $matches);

            expect($matches[1])->toBe(['src_addr', 'dst_addr']);
            expect($result)
                ->toContain('10.0.0.1')
                ->toContain('2001:db8::1')
            ;
        });

        test('escapes HTML in cell values', function (): void {
            $data = [
                ['content' => '<script>alert("xss")</script>'],
            ];

            $result = Table::generate($data, 'xssTable');

            expect($result)
                ->not->toContain('<script>')
                ->toContain('&lt;script&gt;')
            ;
        });
    });

    describe('the contract other pages use (4.3.4)', function (): void {
        test('still a bare table, inside an nfsen-table with the given id', function (): void {
            $result = Table::generate([['a' => 1]], 'statsTable');

            expect($result)->toStartWith('<nfsen-table id="statsTable"')
                ->toContain('<table>')
                ->not->toContain('<table class=')
            ;
        });

        test('derives every id from the table id, so two tables can share a page', function (): void {
            $flows = Table::generate([['a' => 1]], 'flowTable', ['originalData' => 'raw']);
            $stats = Table::generate([['a' => 1]], 'statsTable', ['originalData' => 'raw']);

            preg_match_all('/\bid="([^"]+)"/', $flows . $stats, $ids);

            expect($ids[1])->toBe(array_unique($ids[1]))
                ->and($flows)->toContain('id="flowTable-enhanced"', 'id="flowTableOuter"', 'id="flowTableInner"')
                ->and($stats)->toContain('id="statsTable-enhanced"', 'id="statsTableOuter"', 'id="statsTableInner"')
                ->and($flows . $stats)->not->toContain('id="exportEnhancedData"')
            ;
        });

        test('leaves the export buttons to the page and keeps the Enhanced data choice', function (): void {
            $result = Table::generate([['a' => 1]], 'flowTable');

            expect($result)->toContain('class="export-enhanced-data"', 'Enhanced data', 'column-selector-placeholder')
                ->not->toContain('export-csv', 'export-json', 'export-print', 'data-ref', 'style=')
            ;
        });

        test('hides the default fields when no hiddenFields are passed, and only the given ones otherwise', function (): void {
            $row = [['srcip' => '10.0.0.1', 'cnt' => 1, 'type' => 'FLOW', 'ident' => 'x', 'export_sysid' => 1, 'sampled' => 0]];

            preg_match_all('/data-original-title="([^"]+)"/', Table::generate($row, 't'), $default);
            preg_match_all('/data-original-title="([^"]+)"/', Table::generate($row, 't', ['hiddenFields' => ['cnt']]), $given);

            expect($default[1])->toBe(['srcip'])
                ->and(Table::HIDDEN_FIELDS)->toBe(['cnt', 'type', 'ident', 'export_sysid', 'sampled'])
                ->and($given[1])->toBe(['srcip', 'type', 'ident', 'export_sysid', 'sampled'])
            ;
        });

        test('headers are sort buttons, and numbers, times and addresses say what they are', function (): void {
            $result = Table::generate([['first' => 1700000000, 'src_addr' => '10.0.0.1', 'bytes' => 10, 'proto' => 6, 'note' => 'x']], 't');

            expect($result)->toContain(
                '<th scope="col" data-original-title="bytes" data-num><button type="button" class="sort-button">Bytes</button></th>',
                '<td data-kind="time" data-raw="1700000000"><time data-epoch="1700000000">',
                '<td data-kind="address" data-sort-value="0167772161"><a href="#" class="ip-link">10.0.0.1</a></td>',
                '<td data-num data-raw="10">10.00 B</td>',
                '<td>x</td>',
            );
        });

        // A 10,000 row result has to fit a 128 MB worker.
        test('a cell repeats no value it already shows', function (): void {
            $result = Table::generate([['proto' => 6, 'packets' => 7, 'label' => 'a<b']], 't');

            expect($result)->toContain('<td data-raw="6">TCP <small>(6)</small></td>', '<td data-num>7</td>', '<td>a&lt;b</td>');
        });

        test('address links post ip-info through one handler on the table', function (): void {
            $linked = Table::generate([['src_addr' => '10.0.0.1']], 't', ['ipInfoActionUrl' => "/_action/ip-info-a'b"]);
            $plain = Table::generate([['src_addr' => '10.0.0.1']], 't', ['ipInfoActionUrl' => '/x', 'linkIpAddresses' => false]);

            expect($linked)->toContain(
                'data-on:click="const a = evt.target.closest(&apos;a.ip-link&apos;); if (a) { evt.preventDefault(); @post(&apos;/_action/ip-info-a\\&apos;b?ip=&apos; + encodeURIComponent(a.textContent.trim())) }"',
            )
                ->and(substr_count($linked, 'data-on:'))->toBe(1)
                ->and($plain)->not->toContain('data-on:', 'ip-link')
            ;
        });

        test('pagination keeps the rows past the first page in a template and renders the pager', function (): void {
            $rows = array_map(static fn (int $i): array => ['n' => $i], range(1, 60));

            $result = Table::generate($rows, 'flowTable', ['paginate' => true, 'pageSize' => 25, 'limit' => 60]);

            expect($result)->toContain('data-page-size="25"', 'data-limit="60"', 'class="table-pager"', 'id="flowTable-page-size"')
                ->toContain('Showing 1-25 of 60 returned (limit 60). nfdump cannot skip rows: raise the limit to see more.')
                ->toContain('data-page="0" aria-current="page"', 'data-page="2" aria-label="Page 3"', '<option value="25" selected>25</option>')
                ->and(substr_count(explode('<template class="table-rows">', $result)[0], '<tr>'))->toBe(26)
                ->and(substr_count(explode('<template class="table-rows">', $result)[1] ?? '', '<tr>'))->toBe(35)
                ->and($result)->toContain("</tbody>\n</table>\n</div>\n<template class=\"table-rows\">\n<tr>")
            ;
        });

        test('a table without pagination shows every row and has no pager', function (): void {
            $result = Table::generate(array_map(static fn (int $i): array => ['n' => $i], range(1, 60)), 't');

            expect($result)->not->toContain('<template', 'table-pager', 'data-page-size');
        });

        test('the pager text names the limit, and why there are no more rows only when it was reached', function (): void {
            expect(Table::pagerText(1, 50, 1234, 10000))->toBe('Showing 1-50 of 1,234 returned (limit 10,000).')
                ->and(Table::pagerText(51, 100, 10000, 10000))->toBe('Showing 51-100 of 10,000 returned (limit 10,000). nfdump cannot skip rows: raise the limit to see more.')
                ->and(Table::pagerText(1, 20, 20, 0))->toBe('Showing 1-20 of 20 rows.')
                ->and(Table::pagerText(0, 0, 0, 50))->toBe('No rows returned (limit 50).')
            ;
        });

        test('the pager lists every page up to seven, else the ends and the pages around the current one', function (): void {
            expect(Table::pagerPages(0, 3))->toBe([0, 1, 2])
                ->and(Table::pagerPages(0, 25))->toBe([0, 1, 2, 3, 4, null, 24])
                ->and(Table::pagerPages(12, 25))->toBe([0, null, 11, 12, 13, null, 24])
                ->and(Table::pagerPages(23, 25))->toBe([0, null, 20, 21, 22, 23, 24])
            ;
        });

        test('a rank column comes first as a chip, coloured only for ranks with a series slot', function (): void {
            $rows = [
                ['src' => '10.0.0.1', 'rank' => 1, 'bytes' => 30],
                ['src' => '10.0.0.2', 'rank' => 2, 'bytes' => 20],
                ['src' => '10.0.0.3', 'rank' => 3, 'bytes' => 10],
            ];

            $result = Table::generate($rows, 'conversationsTable', ['rankColumn' => 'rank', 'rankSeries' => [1 => 1, 2 => 5]]);
            preg_match_all('/data-original-title="([^"]+)"/', $result, $headers);

            expect($headers[1])->toBe(['rank', 'src', 'bytes'])
                ->and($result)->toContain('<table class="ranking">')
                ->toContain('<td data-kind="rank"><span class="rank" data-series="1">1</span></td>')
                ->toContain('<span class="rank" data-series="5">2</span>')
                ->toContain('<span class="rank">3</span>')
            ;
        });

        test('the caption names the table for assistive technology and for the printout', function (): void {
            $result = Table::generate([['a' => 1]], 'flowTable', ['caption' => 'Flows <all>', 'exportName' => 'flows-1-2']);

            expect($result)->toContain('data-caption="Flows &lt;all&gt;"', '<caption class="visually-hidden">Flows &lt;all&gt;</caption>', 'data-export-name="flows-1-2"');
        });

        test('the Original view holds nfdump\'s text verbatim and escaped', function (): void {
            $result = Table::generate([['a' => 1]], 't', ['originalData' => "Date first seen\n<script>x</script>"]);

            expect($result)->toContain('data-view="original"', 'aria-pressed="true" data-view="table"', '<div class="original" hidden><pre>Date first seen')
                ->toContain('&lt;script&gt;x&lt;/script&gt;')
                ->not->toContain('<script>')
            ;
        });

        test('output nfdump could not read into rows is shown escaped', function (): void {
            $result = Table::generate([], 't', ['originalData' => '<b>Summary:</b> <img src=x onerror=alert(1)>']);

            expect($result)->toBe('<div id="t" class="table-raw"><pre>&lt;b&gt;Summary:&lt;/b&gt; &lt;img src=x onerror=alert(1)&gt;</pre></div>');
        });

        test('no rows is an empty state with the message escaped, not a status notice', function (): void {
            expect(Table::generate([], 't', ['emptyMessage' => 'No <rows>']))->toBe('<div id="t" class="empty-state"><h3>No rows</h3><p>No &lt;rows&gt;</p></div>');
        });

        test('a chunked table holds its first page and yields the other rows in chunks', function (): void {
            $rows = array_map(static fn (int $i): array => ['n' => $i], range(1, 2_600));

            $table = Table::generateChunked($rows, 'flowTable', ['pageSize' => 50, 'limit' => 10_000, 'result' => 'ab12', 'rowsUrl' => "/_action/flows-rows-x'y"], 1_000);
            $chunks = iterator_to_array($table['chunks']);

            expect($table['html'])->toContain(
                'data-result="ab12"',
                'data-page-size="50"',
                'data-total="2600"',
                'data-chunks="3"',
                'data-on:nfsen-table-more="@post(&apos;/_action/flows-rows-x\\&apos;y?result=&apos; + evt.detail.result + &apos;&amp;chunk=&apos; + evt.detail.chunk)"',
                'Showing 1-50 of 2,600 returned (limit 10,000).',
            )
                ->and($table['html'])->not->toContain('<template')
                ->and(substr_count($table['html'], '<tr>'))->toBe(51)
                ->and(array_keys($chunks))->toBe([0, 1, 2])
                ->and(array_map(static fn (string $c): int => substr_count($c, '<tr>'), $chunks))->toBe([1_000, 1_000, 550])
                ->and($chunks[0])->toStartWith('<template class="table-rows" data-chunk="0">' . "\n<tr><td>51</td></tr>")
                ->and($chunks[2])->toEndWith("\n<tr><td>2600</td></tr>\n</template>")
            ;
        });

        test('a chunked table of one page has no chunks', function (): void {
            $table = Table::generateChunked([['n' => 1]], 't', ['rowsUrl' => '/x']);

            expect(iterator_to_array($table['chunks']))->toBe([])
                ->and($table['html'])->not->toContain('data-chunks', 'nfsen-table-more')
                ->and(iterator_to_array(Table::generateChunked([], 't', [])['chunks']))->toBe([])
            ;
        });

        test('the caller says whether the limit cut the result: aggregation merges the flows it counts', function (): void {
            $rows = array_map(static fn (int $i): array => ['flows' => 4], range(1, 25));

            $cut = Table::generate($rows, 't', ['paginate' => true, 'limit' => 100, 'limitReached' => true]);
            $whole = Table::generate(array_fill(0, 100, ['n' => 1]), 't', ['paginate' => true, 'limit' => 100, 'limitReached' => false]);

            expect($cut)->toContain('data-limit-reached="true"', 'Showing 1-25 of 25 returned (limit 100). nfdump cannot skip rows')
                ->and($whole)->toContain('data-limit-reached="false"', 'Showing 1-50 of 100 returned (limit 100).</p>')
                ->and(Table::pagerText(1, 25, 25, 100, true))->toEndWith('raise the limit to see more.')
                ->and(Table::pagerText(1, 50, 100, 100, false))->toBe('Showing 1-50 of 100 returned (limit 100).')
            ;
        });
    });

    describe('field title mapping', function (): void {
        test('maps srcip to Source IP', function (): void {
            $data = [['srcip' => '10.0.0.1']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Source IP');
        });

        test('maps dstip to Destination IP', function (): void {
            $data = [['dstip' => '10.0.0.2']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Destination IP');
        });

        test('maps the family-agnostic src_addr/dst_addr to Source/Destination IP', function (): void {
            $result = Table::generate([['src_addr' => '10.0.0.1', 'dst_addr' => 'fe80::1']], 'test');

            expect($result)
                ->toContain('Source IP')
                ->toContain('Destination IP')
            ;
        });

        test('maps proto to Protocol', function (): void {
            $data = [['proto' => 'TCP']];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Protocol');
        });

        test('maps srcport to Source Port', function (): void {
            $data = [['srcport' => 12345]];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Source Port');
        });

        test('maps dstport to Destination Port', function (): void {
            $data = [['dstport' => 443]];
            $result = Table::generate($data, 'test');

            expect($result)->toContain('Destination Port');
        });
    });

    describe('default hidden fields', function (): void {
        test('hides cnt field by default', function (): void {
            $data = [['name' => 'test', 'cnt' => 10]];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>cnt<');
        });

        test('hides type field by default', function (): void {
            $data = [['name' => 'test', 'type' => 'flow']];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>type<');
        });

        test('hides sampled field by default', function (): void {
            $data = [['name' => 'test', 'sampled' => 1]];
            $result = Table::generate($data, 'test');

            expect($result)->not->toContain('>sampled<');
        });
    });
});
