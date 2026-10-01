<?php

/**
 * The Flows list's exports: CSV, JSON and Print built on the server in the tab's order and columns,
 * kept in pieces of whole rows, and pulled piece by piece into the element #flowsExports gets.
 */

declare(strict_types=1);

use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\actions\FlowExportActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\FlowRows;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\state\FlowExports;
use mbolli\nfsen_ng\pages\state\FlowRowStore;
use mbolli\nfsen_ng\pages\state\FlowsState;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use starfederation\datastar\enums\ElementPatchMode;

/** @return array{0: Context, 1: PageStates} a tab with only the export actions, its patches in an array */
function flowExportTestBare(): array {
    putenv('VIA_TEST_MODE=1');

    try {
        $app = new Via(new ViaConfig());
        $c = new Context('ctx-export-' . bin2hex(random_bytes(3)), '/', $app);
    } finally {
        putenv('VIA_TEST_MODE');
    }
    $states = new PageStates();
    FlowExportActions::register($c, $app, $states);

    return [$c, $states];
}

/**
 * Runs action $name with $input as the request's query.
 *
 * @param array<string, string> $input
 *
 * @return list<array<string, mixed>> the patches it queued
 */
function flowExportTestRun(Context $c, string $name, array $input): array {
    while ($c->getPatch() !== null);
    $c->setRequestInput($input, []);
    $c->executeAction((string) $c->getAction($name)?->id());
    $all = [];
    while (($patch = $c->getPatch()) !== null) {
        $all[] = $patch;
    }

    return $all;
}

/** @return list<array<string, mixed>> $n rows; row 7's note holds a quote, a comma and a newline */
function flowExportTestRows(int $n): array {
    return array_map(static fn (int $i): array => [
        'in_bytes' => $i % 7,
        'src_port' => 10_000 + $i,
        'src_addr' => '10.0.' . intdiv($i, 250) . '.' . $i % 250,
        'srcip' => '192.0.2.' . $i % 250,
        'note' => $i === 7 ? "a \"q\", b\nc" : "n{$i}",
    ], range(0, $n - 1));
}

/**
 * Stores a Run's list result with the browser's sort and hidden columns.
 *
 * @param list<array<string, mixed>> $rows
 * @param list<string>               $hidden
 */
function flowExportTestStore(FlowsState $flows, array $rows, string $sortKey = '', string $sortDir = '', array $hidden = []): string {
    FlowActions::storeResult($flows, new QueryResult($rows, 'nfdump -c ' . count($rows), '', 0.1, TimeWindow::raw(0, 300), "x\n"), 0.1, '/_action/ip-info', [
        'list' => ['tz' => 'UTC', 'sortKey' => $sortKey, 'sortDir' => $sortDir, 'hidden' => $hidden],
    ]);

    return $flows->resultId;
}

/**
 * Exports the tab's list and pulls every piece as the browser does.
 *
 * @return array{token: string, chunks: int, format: string, name: string, text: string}
 */
function flowExportTestFile(Context $c, string $result, string $format, bool $enhanced): array {
    $sent = flowExportTestRun($c, 'flows-export', ['result' => $result, 'format' => $format, 'enhanced' => $enhanced ? '1' : '0']);
    expect($sent)->toHaveCount(1);
    preg_match('~id="flowsExportData-([0-9a-f]{16})" class="flows-export-data" data-chunks="(\d+)" data-format="([a-z]+)" data-name="([^"]*)"~', (string) $sent[0]['content'], $m);
    expect($m)->not->toBe([]);
    $text = '';
    for ($n = 0; $n < (int) $m[2]; ++$n) {
        $piece = flowExportTestRun($c, 'flows-export-chunk', ['export' => $m[1], 'chunk' => (string) $n]);
        expect($piece)->toHaveCount(1);
        preg_match('~^<span data-chunk="' . $n . '">(.*)</span>$~s', (string) $piece[0]['content'], $p);
        $text .= html_entity_decode($p[1], ENT_QUOTES | ENT_HTML5);
    }

    return ['token' => $m[1], 'chunks' => (int) $m[2], 'format' => $m[3], 'name' => $m[4], 'text' => $text];
}

beforeEach(function (): void {
    FlowExports::reset();
    $this->settingsBefore = isset(Config::$settings) ? Config::$settings : null;
    Config::$settings = Settings::fromArray([
        'general' => ['sources' => ['gw1'], 'ports' => [80]],
        'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-flows-export-missing', 'profile' => 'live'],
    ]);
});

afterEach(function (): void {
    if ($this->settingsBefore !== null) {
        Config::$settings = $this->settingsBefore;
    }
});

describe('the file', function (): void {
    test('CSV of 300 rows: the shown columns in the tab\'s order, a repeated title with its key, quoting, raw values', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(300), 'src_port', 'desc', ['in_bytes']);

        $file = flowExportTestFile($c, $id, 'csv', false);
        $lines = explode("\n", $file['text']);

        expect($file['format'])->toBe('csv')
            ->and($file['name'])->toBe(FlowActions::exportName(0, 300) . '.csv')
            ->and($file['text'])->toEndWith("\n")
            ->and($lines[0])->toBe('Src Port,Source IP,Source IP (srcip),Note')
            ->and($lines[1])->toBe('10299,10.0.1.49,192.0.2.49,n299')
            ->and($lines[2])->toBe('10298,10.0.1.48,192.0.2.48,n298')
            // Row 7 comes 293rd from the top; its note spans two lines inside quotes.
            ->and($lines[293] . "\n" . $lines[294])->toBe("10007,10.0.0.7,192.0.2.7,\"a \"\"q\"\", b\nc\"")
            ->and(count($lines))->toBe(1 + 300 + 1 + 1)
        ;
    });

    test('JSON as JSON.stringify(rows, null, 2) writes it, keyed by title; enhanced and raw take the same rows', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(300), hidden: ['in_bytes', 'srcip']);

        $raw = flowExportTestFile($c, $id, 'json', false);
        $enhanced = flowExportTestFile($c, $id, 'json', true);
        $rows = json_decode($raw['text'], true);

        expect($raw['name'])->toBe(FlowActions::exportName(0, 300) . '.json')
            ->and($raw['text'])->toStartWith("[\n  {\n    \"Src Port\": \"10000\",\n    \"Source IP\": \"10.0.0.0\",\n    \"Note\": \"n0\"\n  },\n  {\n")
            ->and($raw['text'])->toEndWith("\n  }\n]")
            ->and($rows)->toHaveCount(300)
            ->and($rows[7])->toBe(['Src Port' => '10007', 'Source IP' => '10.0.0.7', 'Note' => "a \"q\", b\nc"])
            ->and(array_keys(json_decode($enhanced['text'], true)[0]))->toBe(['Src Port', 'Source IP', 'Note'])
            ->and(count(json_decode($enhanced['text'], true)))->toBe(300)
        ;
    });

    test('an empty list is [] in JSON and the title line alone in CSV', function (): void {
        $titles = FlowExportActions::titles(['src_port']);

        expect(implode('', FlowExportActions::pieces('json', $titles, [])))->toBe('[]')
            ->and(implode('', FlowExportActions::pieces('csv', $titles, [])))->toBe("Src Port\n")
            ->and(json_decode(implode('', FlowExportActions::pieces('print', $titles, [])), true))->toBe(['title' => 'Flows', 'columns' => ['Src Port'], 'rows' => []])
        ;
    });

    test('Print is the enhanced rows as {title, columns, rows}', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(20), hidden: ['in_bytes']);

        $file = flowExportTestFile($c, $id, 'print', false);
        $data = json_decode($file['text'], true);

        expect($file['name'])->toBe(FlowActions::exportName(0, 300))
            ->and($data['title'])->toBe('Flows')
            ->and($data['columns'])->toBe(['Src Port', 'Source IP', 'Source IP (srcip)', 'Note'])
            ->and($data['rows'])->toHaveCount(20)
        ;
    });

    test('a file above RAW_CHUNK_BYTES comes in several pieces of whole rows that join to the whole file', function (): void {
        $titles = FlowExportActions::titles(['src_port', 'note']);
        $rows = static function (): Generator {
            for ($i = 0; $i < 6000; ++$i) {
                yield [(string) (10_000 + $i), str_repeat('x', 200) . ",{$i}"];
            }
        };

        foreach (['csv', 'json', 'print'] as $format) {
            $pieces = FlowExportActions::pieces($format, $titles, $rows());
            $whole = implode('', $pieces);

            expect(count($pieces))->toBeGreaterThan(1);
            foreach ($pieces as $n => $piece) {
                expect(strlen($piece))->toBeLessThanOrEqual(FlowsState::RAW_CHUNK_BYTES);
                if ($n < count($pieces) - 1) {
                    // A cut falls between rows: the next piece starts a row.
                    match ($format) {
                        'csv' => expect($piece)->toEndWith("\n")->and($pieces[$n + 1])->toMatch('/^1\d{4},/'),
                        'json' => expect($pieces[$n + 1])->toStartWith(",\n  {\n"),
                        default => expect($pieces[$n + 1])->toStartWith(',["'),
                    };
                }
            }
            if ($format === 'csv') {
                expect(substr_count($whole, "\n"))->toBe(6001);
            } else {
                $data = json_decode($whole, true);
                expect($format === 'json' ? count($data) : count($data['rows']))->toBe(6000);
            }
        }
    });
});

describe('the actions', function (): void {
    test('flows-export appends one element to #flowsExports that names the token, the pieces, the format and the file', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(50));

        $sent = flowExportTestRun($c, 'flows-export', ['result' => $id, 'format' => 'csv', 'enhanced' => '1']);
        $chunkUrl = (string) $c->getAction('flows-export-chunk')?->url();
        preg_match('~flowsExportData-([0-9a-f]{16})~', (string) $sent[0]['content'], $m);

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['type'])->toBe('elements')
            ->and($sent[0]['selector'])->toBe('#flowsExports')
            ->and($sent[0]['mode'])->toBe(ElementPatchMode::Append)
            ->and($sent[0]['content'])->toBe(sprintf(
                '<pre hidden id="flowsExportData-%1$s" class="flows-export-data" data-chunks="1" data-format="csv" data-name="%2$s"'
                . ' data-effect="window.nfsenFlowsList?.pull(el, (n) =&gt; @post(\'%3$s?export=%1$s&amp;chunk=\' + n, %4$s))"></pre>',
                $m[1],
                FlowActions::exportName(0, 300) . '.csv',
                $chunkUrl,
                htmlspecialchars(FlowRows::SLIM, ENT_QUOTES | ENT_HTML5),
            ))
        ;
    });

    test('flows-export-chunk appends the piece as text; an unknown token or piece queues nothing', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(10));
        $file = flowExportTestFile($c, $id, 'csv', false);

        $piece = flowExportTestRun($c, 'flows-export-chunk', ['export' => $file['token'], 'chunk' => '0']);

        expect($piece)->toHaveCount(1)
            ->and($piece[0]['selector'])->toBe("#flowsExportData-{$file['token']}")
            ->and($piece[0]['mode'])->toBe(ElementPatchMode::Append)
            ->and($piece[0]['content'])->toContain('"a ""q"", b' . "\nc\"")
            ->and(flowExportTestRun($c, 'flows-export-chunk', ['export' => $file['token'], 'chunk' => '1']))->toBe([])
            ->and(flowExportTestRun($c, 'flows-export-chunk', ['export' => str_repeat('0', 16), 'chunk' => '0']))->toBe([])
            ->and(flowExportTestRun($c, 'flows-export-chunk', ['export' => '<b>', 'chunk' => '0']))->toBe([])
            ->and(flowExportTestRun($c, 'flows-export-chunk', ['export' => $file['token'], 'chunk' => '-1']))->toBe([])
        ;
    });

    test('a stale result, a table result, an unknown format: nothing', function (): void {
        [$c, $states] = flowExportTestBare();
        $flows = $states->flows;
        $id = flowExportTestStore($flows, flowExportTestRows(10));

        $sent = [
            ...flowExportTestRun($c, 'flows-export', ['result' => 'older123', 'format' => 'csv']),
            ...flowExportTestRun($c, 'flows-export', ['result' => $id, 'format' => 'xml']),
        ];
        $html = flowExportTestStore($flows, []);
        $sent = [...$sent, ...flowExportTestRun($c, 'flows-export', ['result' => $html, 'format' => 'csv'])];

        expect($flows->mode)->toBe('html')
            ->and($sent)->toBe([])
            ->and($flows->rowsLost)->toBeFalse()
        ;
    });

    test('rows the store dropped: the tab says so instead of exporting', function (): void {
        [$c, $states] = flowExportTestBare();
        $id = flowExportTestStore($states->flows, flowExportTestRows(10));
        FlowRowStore::forget($id);

        $sent = flowExportTestRun($c, 'flows-export', ['result' => $id, 'format' => 'csv']);

        expect($states->flows->rowsLost)->toBeTrue()
            ->and(array_filter($sent, static fn (array $p): bool => str_contains((string) ($p['content'] ?? ''), 'flowsExportData-')))->toBe([])
        ;
    });
});

describe('FlowExports', function (): void {
    test('a fifth export drops the oldest, and an export lasts TTL seconds', function (): void {
        $tokens = array_map(static fn (int $i): string => FlowExports::add(["piece {$i}"], 1_000), range(1, 5));

        expect(FlowExports::has($tokens[0]))->toBeFalse()
            ->and(array_map(FlowExports::has(...), array_slice($tokens, 1)))->toBe([true, true, true, true])
            ->and(FlowExports::piece($tokens[4], 0, 1_000))->toBe('piece 5')
            ->and(FlowExports::piece($tokens[4], 0, 1_000 + FlowExports::TTL - 1))->toBe('piece 5')
            ->and(FlowExports::piece($tokens[4], 0, 1_000 + FlowExports::TTL))->toBeNull()
        ;
    });
});
