<?php

/**
 * FlowRowStore: blocks, windows of the shown columns, orders, time zones, export rows and the
 * budget it shares with FlowsState's payloads.
 */

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Table;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;

/**
 * Rows of the dev data's shape: row i has src_port 10000 + i, and in_bytes from $bytes when given.
 *
 * @param null|Closure(int): mixed $bytes
 *
 * @return list<array<string, mixed>>
 */
function flowRowStoreTestRows(int $n, ?Closure $bytes = null): array {
    $rows = [];
    for ($i = 0; $i < $n; ++$i) {
        $rows[] = [
            'cnt' => $i + 1,
            'first' => '2026-08-29T05:17:53.000',
            'in_packets' => 10,
            'in_bytes' => $bytes !== null ? $bytes($i) : 1000,
            'proto' => 6,
            'src_port' => 10_000 + $i,
            'src_addr' => '10.0.' . intdiv($i, 250) . '.' . $i % 250,
        ];
    }

    return $rows;
}

/** @return list<int> the src_port of each row of a window, minus 10,000: the file index */
function flowRowStoreTestIndices(string $html): array {
    preg_match_all('~<div role="cell">(\d+)(?: <small>\([^)]*\)</small>)?</div><div role="cell" data-kind="address">~', $html, $m);

    return array_map(static fn (string $port): int => (int) $port - 10_000, $m[1]);
}

/** @return list<int> */
function flowRowStoreTestOrder(?string $order): array {
    return $order === null || $order === '' ? [] : array_values(unpack('N*', $order) ?: []);
}

/** @return list<string> every column key of result $id */
function flowRowStoreTestKeys(string $id): array {
    return FlowRowStore::columns($id)['keys'] ?? [];
}

/** @return list<string> the rows of a window's HTML */
function flowRowStoreTestRowList(string $html): array {
    return array_map(static fn (string $part): string => '<div role="row" ' . $part, array_slice(explode('<div role="row" ', $html), 1));
}

/**
 * A window of every column.
 *
 * @return null|array{html: string, blocks: int}
 */
function flowRowStoreTestWindow(string $id, int $offset, int $count, string $order = '', string $zone = 'UTC'): ?array {
    return FlowRowStore::window($id, $offset, $count, $order, new DateTimeZone($zone), flowRowStoreTestKeys($id));
}

/**
 * @param null|Generator<int, list<string>, mixed, void> $rows
 *
 * @return list<list<string>>
 */
function flowRowStoreTestExport(?Generator $rows): array {
    return $rows === null ? [] : array_values(iterator_to_array($rows, false));
}

beforeEach(function (): void {
    FlowsState::makeRoom(static fn (): bool => false);
});

describe('storing and windows', function (): void {
    test('a window holds its rows in order, wrapped with their place in the list', function (): void {
        FlowRowStore::store('w1', flowRowStoreTestRows(300), ['ipInfoActionUrl' => '/_action/ip-info']);
        $window = flowRowStoreTestWindow('w1', 10, 5);
        $rows = flowRowStoreTestRowList($window['html'] ?? '');

        expect(FlowRowStore::count('w1'))->toBe(300)
            ->and($rows)->toHaveCount(5)
            ->and(flowRowStoreTestIndices($window['html']))->toBe([10, 11, 12, 13, 14])
            ->and($window['html'])->toStartWith('<div role="row" aria-rowindex="12" data-ignore><div role="cell"')
            ->and($rows[1])->toStartWith('<div role="row" aria-rowindex="13" data-stripe data-ignore>')
            ->and($window['html'])->toContain('<a href="#" class="ip-link">10.0.0.10</a>', '<div role="cell" data-num>1000.0 B</div>')
            ->and($window['blocks'])->toBe(1)
            ->and(flowRowStoreTestKeys('w1'))->toBe(['first', 'in_packets', 'in_bytes', 'proto', 'src_port', 'src_addr'])
        ;
    });

    test('offset and count are clamped to the list and to MAX_WINDOW', function (): void {
        FlowRowStore::store('w2', flowRowStoreTestRows(700));

        expect(flowRowStoreTestRowList(flowRowStoreTestWindow('w2', 690, 50)['html'] ?? ''))->toHaveCount(10)
            ->and(flowRowStoreTestWindow('w2', 700, 50))->toBe(['html' => '', 'blocks' => 0])
            ->and(flowRowStoreTestWindow('w2', 9_999, 50))->toBe(['html' => '', 'blocks' => 0])
            ->and(flowRowStoreTestRowList(flowRowStoreTestWindow('w2', -5, 3)['html'] ?? ''))->toHaveCount(3)
            ->and(flowRowStoreTestIndices(flowRowStoreTestWindow('w2', 0, 600)['html'] ?? ''))->toBe(range(0, FlowRows::MAX_WINDOW - 1))
            ->and(FlowRowStore::window('missing', 0, 5, '', new DateTimeZone('UTC'), ['src_port']))->toBeNull()
        ;
    });

    test('block boundaries: rows 63, 64 and 127 come from the right blocks', function (): void {
        FlowRowStore::store('w3', flowRowStoreTestRows(200));
        $one = static fn (int $i): array => flowRowStoreTestIndices(flowRowStoreTestWindow('w3', $i, 1)['html'] ?? '');

        expect($one(63))->toBe([63])
            ->and($one(64))->toBe([64])
            ->and($one(127))->toBe([127])
            ->and($one(128))->toBe([128])
            ->and($one(199))->toBe([199])
            ->and(flowRowStoreTestWindow('w3', 63, 2)['blocks'])->toBe(2)
            ->and(flowRowStoreTestWindow('w3', 60, 70)['blocks'])->toBe(3)
            ->and(flowRowStoreTestIndices(flowRowStoreTestWindow('w3', 60, 70)['html'] ?? ''))->toBe(range(60, 129))
        ;
    });

    test('a window in a sorted order expands each block once and numbers rows by their place in that order', function (): void {
        FlowRowStore::store('w4', flowRowStoreTestRows(200));
        $window = flowRowStoreTestWindow('w4', 1, 3, pack('N*', 150, 3, 70, 199, 4));

        expect(flowRowStoreTestIndices($window['html'] ?? ''))->toBe([3, 70, 199])
            ->and($window['blocks'])->toBe(3)
            ->and($window['html'] ?? '')->toStartWith('<div role="row" aria-rowindex="3" data-stripe data-ignore>')
        ;
    });

    test('a window of some columns takes their cells by position, in the order asked for, whatever the cells hold', function (): void {
        $rows = [
            ['a' => 'x</div><div role="cell">y', 'b' => 'ü€😀', 'c' => '', 'd' => 7],
            ['a' => '', 'b' => '<div role="row" x', 'c' => 'z', 'd' => 8],
        ];
        FlowRowStore::store('w10', $rows);
        $utc = new DateTimeZone('UTC');
        $cells = static function (array $shown) use ($utc): array {
            $html = FlowRowStore::window('w10', 0, 2, '', $utc, $shown)['html'] ?? '';
            $doc = HTMLDocument::createFromString('<!doctype html><body>' . $html, LIBXML_NOERROR);

            return array_map(
                static fn (Element $row): array => array_map(static fn (Element $c): string => $c->textContent ?? '', iterator_to_array($row->querySelectorAll('[role="cell"]'))),
                iterator_to_array($doc->querySelectorAll('[role="row"]')),
            );
        };

        expect($cells(['a', 'b', 'c', 'd']))->toBe([['x</div><div role="cell">y', 'ü€😀', '', '7'], ['', '<div role="row" x', 'z', '8']])
            ->and($cells(['d', 'b']))->toBe([['7', 'ü€😀'], ['8', '<div role="row" x']])
            ->and($cells(['c', 'missing']))->toBe([[''], ['z']])
            ->and($cells([]))->toBe([[], []])
            ->and(flowRowStoreTestRowList(FlowRowStore::window('w10', 0, 2, '', $utc, ['d', 'b'])['html'] ?? ''))->toHaveCount(2)
        ;
    });

    test('times are written in the zone asked for', function (): void {
        FlowRowStore::store('w5', flowRowStoreTestRows(3));
        $utc = flowRowStoreTestWindow('w5', 0, 1)['html'] ?? '';
        $zurich = flowRowStoreTestWindow('w5', 0, 3, '', 'Europe/Zurich')['html'] ?? '';
        $epoch = new DateTimeImmutable('2026-08-29T05:17:53.000')->getTimestamp();
        $utcText = new DateTimeImmutable('@' . $epoch)->format('Y-m-d H:i:s');
        $zurichText = new DateTimeImmutable('@' . $epoch)->setTimezone(new DateTimeZone('Europe/Zurich'))->format('Y-m-d H:i:s');

        expect($utc)->toContain("<time data-epoch=\"{$epoch}\">{$utcText}</time>")
            ->and($zurich)->toContain("<time data-epoch=\"{$epoch}\">{$zurichText}</time>")
            ->and(substr_count($zurich, $zurichText))->toBe(3)
        ;
    });

    test('the effective zone: nfcapd for a server display, else the browser when valid, else UTC', function (): void {
        expect(FlowRowStore::zone('server', 'Europe/Zurich', 'Asia/Tokyo')->getName())->toBe('Europe/Zurich')
            ->and(FlowRowStore::zone('browser', 'Europe/Zurich', 'Asia/Tokyo')->getName())->toBe('Asia/Tokyo')
            ->and(FlowRowStore::zone('browser', 'Europe/Zurich', 'Not/AZone')->getName())->toBe('UTC')
            ->and(FlowRowStore::zone('browser', 'Europe/Zurich', '')->getName())->toBe('UTC')
            ->and(FlowRowStore::isZone('America/New_York'))->toBeTrue()
            ->and(FlowRowStore::isZone('Not/AZone'))->toBeFalse()
            ->and(FlowRowStore::isZone(''))->toBeFalse()
        ;
    });

    test('column widths follow the titles and the 95th percentile of the cell text, within 6 and 40 ch', function (): void {
        $rows = array_map(static fn (int $i): array => ['a' => str_repeat('x', $i < 95 ? 10 : 80), 'b' => 'y', 'src_addr' => '10.0.0.1'], range(0, 99));
        FlowRowStore::store('w6', $rows);

        // a: p95 is 10 chars, the title "A" needs 3, plus 2; b: the minimum; src_addr: "Source IP" is 9 + 2, plus 2.
        expect(FlowRowStore::columns('w6')['widths'] ?? [])->toBe(['a' => 12, 'b' => 6, 'src_addr' => 13])
            ->and(FlowRowStore::template('w6', ['a', 'b', 'src_addr']))->toBe('minmax(12ch, 1fr) minmax(6ch, 1fr) minmax(13ch, 1fr)')
            ->and(FlowRowStore::template('w6', ['src_addr', 'missing']))->toBe('minmax(13ch, 1fr)')
            ->and(FlowRowStore::template('missing', ['a']))->toBe('')
        ;
    });

    test('an address column takes its longest address, so a few IPv6 ones among IPv4 are not cut', function (): void {
        $v6 = '2001:db8:85a3:8d3:1319:8a2e:370:7348';
        $rows = array_map(static fn (int $i): array => ['a' => $i < 97 ? 'short' : $v6, 'src_addr' => $i < 97 ? '10.0.0.1' : $v6], range(0, 99));
        FlowRowStore::store('w8', $rows);

        // a: p95 is "short", 5 chars plus 2, the IPv6 values cut; src_addr: 36 chars plus 2.
        expect(FlowRowStore::template('w8', ['a', 'src_addr']))->toBe('minmax(7ch, 1fr) minmax(38ch, 1fr)');
    });

    test('a full-length IPv6 address (39 characters) gets 41 ch, past MAX_CH; other columns stop at MAX_CH', function (): void {
        $v6 = '2a02:1810:4d2c:9f00:3c8e:1a2b:9f4d:7e21';
        $rows = array_map(static fn (int $i): array => ['a' => str_repeat('x', 60), 'dst_addr' => $i < 99 ? '10.0.0.1' : $v6], range(0, 99));
        FlowRowStore::store('w9', $rows);

        expect(strlen($v6))->toBe(39)
            ->and(FlowRowStore::template('w9', ['a', 'dst_addr']))->toBe('minmax(' . FlowRowStore::MAX_CH . 'ch, 1fr) minmax(41ch, 1fr)')
        ;
    });

    test('nothing to store: no rows, or rows that are lines of text', function (): void {
        expect(FlowRowStore::store('w7', []))->toBeFalse()
            ->and(FlowRowStore::store('w7', ['a line', 'another']))->toBeFalse()
            ->and(FlowRowStore::has('w7'))->toBeFalse()
        ;
    });
});

describe('export rows', function (): void {
    test('enhanced and raw values equal the text and the data-raw of the table\'s cells for the same records', function (): void {
        $records = [
            ['cnt' => 1, 'type' => 'FLOW', 'first' => '2026-08-29T05:17:53.000', 'last' => 1_756_444_674, 'in_packets' => 10, 'in_bytes' => 1000,
                'proto' => 6, 'tcp_flags' => '...AP...', 'src_port' => 10_000, 'dst_port' => 443, 'src_addr' => '10.0.0.1', 'dst_addr' => '2001:db8::1',
                'src_geo' => '', 'ip_router' => '127.0.0.1', 'note' => '<b>&"x"</b>', 'duration' => 12.5],
            ['cnt' => 2, 'first' => 'not a date', 'in_packets' => 1_234_567, 'in_bytes' => 5_000_000_000, 'proto' => 1, 'src_port' => 0,
                'src_addr' => '10.0.1.1', 'dst_addr' => 'n/a', 'note' => ['nested' => true], 'duration' => 0],
            ['first' => '2026-08-29T06:00:00.000', 'proto' => 17, 'src_port' => 53, 'src_addr' => '192.168.1.1', 'note' => 'a, "b"'],
        ];
        $options = ['linkIpAddresses' => true, 'ipInfoActionUrl' => '/_action/ip-info'];
        FlowRowStore::store('e1', $records, $options);
        $keys = flowRowStoreTestKeys('e1');
        $zone = new DateTimeZone(date_default_timezone_get());
        $table = HTMLDocument::createFromString('<!doctype html><body>' . Table::generate($records, 'flowTable', $options), LIBXML_NOERROR);
        $text = $raw = [];
        foreach ($table->querySelectorAll('tbody tr') as $tr) {
            $cells = iterator_to_array($tr->querySelectorAll('td'));
            $text[] = array_map(static fn (Element $td): string => trim((string) preg_replace('/\s+/u', ' ', $td->textContent ?? '')), $cells);
            $raw[] = array_map(static fn (Element $td): string => $td->hasAttribute('data-raw') ? (string) $td->getAttribute('data-raw') : trim((string) preg_replace('/\s+/u', ' ', $td->textContent ?? '')), $cells);
        }

        expect($keys)->toHaveCount(count($text[0]))
            ->and(flowRowStoreTestExport(FlowRowStore::exportRows('e1', '', $keys, $zone, true)))->toBe($text)
            ->and(flowRowStoreTestExport(FlowRowStore::exportRows('e1', '', $keys, $zone, false)))->toBe($raw)
            ->and($raw[0][array_search('first', $keys, true)])->toBe('2026-08-29T05:17:53.000')
        ;
    });

    test('rows come in the order and with the columns asked for; a key the result lacks is empty', function (): void {
        FlowRowStore::store('e2', flowRowStoreTestRows(5, static fn (int $i): int => [30, 10, 20, 10, 0][$i]));
        $utc = new DateTimeZone('UTC');
        $order = FlowRowStore::order('e2', 'in_bytes', 'desc') ?? '';

        expect(flowRowStoreTestExport(FlowRowStore::exportRows('e2', $order, ['src_port', 'in_bytes', 'nope'], $utc, false)))->toBe([
            ['10000', '30', ''], ['10002', '20', ''], ['10001', '10', ''], ['10003', '10', ''], ['10004', '0', ''],
        ])
            ->and(flowRowStoreTestExport(FlowRowStore::exportRows('e2', $order, ['in_bytes', 'src_addr'], $utc, true))[0])->toBe(['30.00 B', '10.0.0.0'])
            ->and(FlowRowStore::exportRows('missing', '', ['in_bytes'], $utc, true))->toBeNull()
        ;
    });

    test('enhanced times are written in the zone asked for', function (): void {
        FlowRowStore::store('e3', flowRowStoreTestRows(2));
        $epoch = new DateTimeImmutable('2026-08-29T05:17:53.000')->getTimestamp();
        $tokyo = new DateTimeImmutable('@' . $epoch)->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');

        expect(flowRowStoreTestExport(FlowRowStore::exportRows('e3', '', ['first'], new DateTimeZone('Asia/Tokyo'), true)))->toBe([[$tokyo], [$tokyo]]);
    });

    test('a sorted export across many blocks and parts gives every row once, in order', function (): void {
        FlowRowStore::store('e4', array_map(static fn (int $i): array => ['row' => "r{$i}", 'key' => ($i * 7_919) % 9_001], range(0, 8_999)));
        $order = FlowRowStore::order('e4', 'key', 'asc') ?? '';
        $expected = array_map(static fn (int $i): string => "r{$i}", flowRowStoreTestOrder($order));
        $enhanced = array_column(flowRowStoreTestExport(FlowRowStore::exportRows('e4', $order, ['row'], new DateTimeZone('UTC'), true)), 0);
        $raw = array_column(flowRowStoreTestExport(FlowRowStore::exportRows('e4', $order, ['row'], new DateTimeZone('UTC'), false)), 0);

        expect($enhanced)->toHaveCount(9_000)
            ->and($enhanced)->toBe($expected)
            ->and($raw)->toBe($expected)
        ;
    });

    test('a NUL inside a value does not shift the columns of a joined string', function (): void {
        FlowRowStore::store('e5', [['a' => "x\0y", 'b' => 1], ['a' => 'z', 'b' => 2]]);

        expect(flowRowStoreTestExport(FlowRowStore::exportRows('e5', '', ['a', 'b'], new DateTimeZone('UTC'), false)))->toBe([["x\u{FFFD}y", '1'], ['z', '2']])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('e5', 'b', 'desc')))->toBe([1, 0])
        ;
    });

    test('a generator taken before the store drops the result still gives every row', function (): void {
        FlowRowStore::store('e6', flowRowStoreTestRows(100));
        $rows = FlowRowStore::exportRows('e6', '', ['src_port'], new DateTimeZone('UTC'), true);
        FlowsState::makeRoom(static fn (): bool => false);

        expect(FlowRowStore::has('e6'))->toBeFalse()
            ->and(flowRowStoreTestExport($rows))->toHaveCount(100)
        ;
    });
});

describe('orders', function (): void {
    test('stable with ties: equal keys keep the current order, ascending and descending', function (): void {
        $bytes = [3 => 10, 0 => 20, 2 => 10, 1 => 20, 4 => 5];
        FlowRowStore::store('o1', flowRowStoreTestRows(5, static fn (int $i): int => $bytes[$i]));

        expect(flowRowStoreTestOrder(FlowRowStore::order('o1', 'in_bytes', 'asc')))->toBe([4, 2, 3, 0, 1])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o1', 'in_bytes', 'desc')))->toBe([0, 1, 2, 3, 4])
            // Against a current order: ties keep it.
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o1', 'in_bytes', 'asc', pack('N*', 4, 3, 2, 1, 0))))->toBe([4, 3, 2, 1, 0])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o1', 'in_bytes', 'desc', pack('N*', 4, 3, 2, 1, 0))))->toBe([1, 0, 3, 2, 4])
            ->and(FlowRowStore::order('o1', 'nope', 'asc'))->toBeNull()
            ->and(FlowRowStore::order('missing', 'in_bytes', 'asc'))->toBeNull()
        ;
    });

    test('empty keys go last in both directions, in the current order', function (): void {
        $values = [0 => 3, 1 => '', 2 => 1, 3 => null, 4 => 2];
        $rows = flowRowStoreTestRows(5, static fn (int $i): mixed => $values[$i]);
        unset($rows[3]['in_bytes']);
        FlowRowStore::store('o2', $rows);

        expect(flowRowStoreTestOrder(FlowRowStore::order('o2', 'in_bytes', 'asc')))->toBe([2, 4, 0, 1, 3])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o2', 'in_bytes', 'desc')))->toBe([0, 4, 2, 1, 3])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o2', 'in_bytes', 'desc', pack('N*', 3, 1, 0, 2, 4))))->toBe([0, 4, 2, 3, 1])
        ;
    });

    test('keys compare as nfsen-table compares them: digits by value, numbers as numbers, text naturally', function (): void {
        // IPv4 keys are 10 digits, IPv6 keys 41: digit strings compare by length, then by digits.
        $addresses = ['10.0.0.10', '2001:db8::1', '10.0.0.9', '9.255.255.255', '::1'];
        $rows = array_map(static fn (string $a): array => ['src_addr' => $a], $addresses);
        FlowRowStore::store('o3', $rows);
        $words = ['b10', 'B2', 'a', 'b1'];
        FlowRowStore::store('o4', array_map(static fn (string $w): array => ['name' => $w], $words));
        $numbers = ['1.5', '-2', '10', '1e1', '0.25'];
        FlowRowStore::store('o5', array_map(static fn (string $n): array => ['value' => $n], $numbers));

        expect(flowRowStoreTestOrder(FlowRowStore::order('o3', 'src_addr', 'asc')))->toBe([3, 2, 0, 4, 1])
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o4', 'name', 'asc')))->toBe([2, 3, 1, 0])
            // 10 and 1e1 are equal numbers: the file order stays.
            ->and(flowRowStoreTestOrder(FlowRowStore::order('o5', 'value', 'asc')))->toBe([1, 4, 0, 2, 3])
            ->and(FlowRowStore::compare('007', '7'))->toBe(0)
            ->and(FlowRowStore::compare('0100000000000000000000001', '100000000000000000000000'))->toBe(1)
            ->and(FlowRowStore::compare('x', '10'))->toBe(1)
        ;
    });
});

describe('the budget shared with FlowsState', function (): void {
    test('storedBytes() is the payloads and the row stores together', function (): void {
        $state = new FlowsState();
        $state->setResult('<table></table>', 1, ['resultId' => 'b1', 'command' => 'nfdump', 'rawOutput' => str_repeat("line\n", 1_000)]);
        FlowRowStore::store('b2', flowRowStoreTestRows(100));

        expect(FlowRowStore::bytesOf('b2'))->toBeGreaterThan(0)
            ->and(FlowsState::payloadBytesOf('b1'))->toBeGreaterThan(0)
            ->and(FlowsState::storedBytes())->toBe(FlowRowStore::bytes() + FlowsState::payloadBytesOf('b1'))
            ->and(FlowRowStore::bytes())->toBe(FlowRowStore::bytesOf('b2'))
        ;
    });

    test('over the budget the least recently used entry goes, whether it is a payload or a row store', function (): void {
        $budget = FlowsState::PAYLOAD_BUDGET;
        // Hex cells compress to about half, their sort keys and raw values too: row stores of about 0.3 of the budget.
        $hexRows = static fn (): array => array_map(static fn (int $i): array => ['blob' => bin2hex(random_bytes(1_000))], range(1, intdiv((int) ($budget * 0.28), 3_000)));
        $payload = new FlowsState();
        // Random bytes do not compress: a third of the budget.
        $payload->setResult('<table></table>', 1, ['resultId' => 'p1', 'command' => 'nfdump', 'rawOutput' => random_bytes(intdiv($budget, 3))]);
        FlowRowStore::store('r1', $hexRows());
        FlowRowStore::store('r2', $hexRows());
        [$p, $r] = [FlowsState::payloadBytesOf('p1'), FlowRowStore::bytesOf('r1')];
        expect($p + 2 * $r)->toBeLessThanOrEqual($budget)
            ->and($p + 3 * $r)->toBeGreaterThan($budget)
            ->and(2 * $p + 2 * $r)->toBeGreaterThan($budget)
        ;

        // Reading the payload makes the first row store the least recently used.
        $payload->rawChunk(0);
        FlowRowStore::store('r3', $hexRows());

        expect([$payload->hasPayload(), FlowRowStore::has('r1'), FlowRowStore::has('r2'), FlowRowStore::has('r3')])->toBe([true, false, true, true])
            ->and(FlowsState::storedBytes())->toBeLessThanOrEqual($budget)
        ;

        // A window makes r2 recent, so the next payload pushes the payload p1 out.
        flowRowStoreTestWindow('r2', 0, 1);
        $next = new FlowsState();
        $next->setResult('<table></table>', 1, ['resultId' => 'p2', 'command' => 'nfdump', 'rawOutput' => random_bytes(intdiv($budget, 3))]);

        expect([$payload->hasPayload(), FlowRowStore::has('r2'), FlowRowStore::has('r3'), $next->hasPayload()])->toBe([false, true, true, true])
            ->and(FlowsState::storedBytes())->toBeLessThanOrEqual($budget)
        ;
    });

    test('makeRoom() evicts from both kinds, and a new result forgets the old one\'s rows', function (): void {
        $state = new FlowsState();
        $state->setResult('', 3, ['resultId' => 'm1', 'mode' => 'list', 'command' => 'nfdump', 'rawOutput' => "x\n"]);
        FlowRowStore::store('m1', flowRowStoreTestRows(3));
        FlowRowStore::store('m2', flowRowStoreTestRows(3));
        FlowsState::makeRoom(static fn (): bool => !FlowRowStore::has('m1'));

        expect(FlowRowStore::has('m1'))->toBeFalse()
            ->and(FlowRowStore::has('m2'))->toBeTrue()
            ->and($state->hasPayload())->toBeFalse()
            ->and($state->hasRows())->toBeFalse()
        ;

        FlowRowStore::store('m3', flowRowStoreTestRows(3));
        $state->setResult('', 3, ['resultId' => 'm4', 'mode' => 'list', 'command' => 'nfdump']);
        $state->setResult('', 3, ['resultId' => 'm3', 'mode' => 'list', 'command' => 'nfdump']);
        // The same result again keeps its rows.
        $state->setResult('', 3, ['resultId' => 'm3', 'mode' => 'list', 'command' => 'nfdump', 'rawOutput' => "y\n"]);
        expect(FlowRowStore::has('m3'))->toBeTrue();

        $state->setResult('', 0, []);

        expect(FlowRowStore::has('m3'))->toBeFalse()
            ->and(FlowsState::makeRoom(static fn (): bool => false))->toBeFalse()
            ->and(FlowsState::storedBytes())->toBe(0)
        ;
    });
});
