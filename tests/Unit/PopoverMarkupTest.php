<?php

declare(strict_types=1);

use Dom\Element;
use Dom\HTMLDocument;
use mbolli\nfsen_ng\actions\FlowActions;
use mbolli\nfsen_ng\common\Config;
use mbolli\nfsen_ng\common\Settings;
use mbolli\nfsen_ng\pages\PageRegistry;
use mbolli\nfsen_ng\pages\PageStates;
use mbolli\nfsen_ng\pages\Shell;
use mbolli\nfsen_ng\query\QueryResult;
use mbolli\nfsen_ng\query\TimeWindow;
use Mbolli\PhpVia\Config as ViaConfig;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/** Datastar 1.0.4's attribute plugins and nfsen-ng's persist, the list of K1 in tests/e2e/rocket.test.mjs. */
const POPOVER_MARKUP_PLUGINS = [
    'attr', 'bind', 'class', 'computed', 'effect', 'indicator', 'init', 'json-signals', 'on', 'on-intersect',
    'on-interval', 'on-signal-patch', 'ref', 'show', 'signals', 'style', 'text', 'persist',
];

/** K1's exception: the plugin attributes an sb-popover's light DOM may carry, value forms that read page signals. */
const POPOVER_MARKUP_ALLOWED = '/^data-(?:(?:on|attr|class|style):|(?:effect|text|show|bind)(?:__|$))/';

/** What Rocket's rewrite renames into the component's scope on every light-DOM element, data-ignore or not. */
const POPOVER_MARKUP_RENAMED = '/^data-(?:(?:signals|ref)(?::|__|$)|(?:bind|computed|indicator):)/';

const POPOVER_MARKUP_TRIGGER = '<button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup">Open</button>';

/**
 * K1 in every literal `<sb-popover>` block of a Twig source: after the opening tag, no plugin attribute other
 * than the popover exception's, no keyed `data-bind:`, no `$$`, and no `@name(` in a plain `data-*` value.
 *
 * @return list<string>
 */
function popoverMarkupProblems(string $source, string $file = 'fixture'): array {
    // Twig comments, then tags (they may hold quotes and '>'), blanked with offsets and line breaks kept.
    $spaces = static fn (string $text): string => (string) preg_replace('/[^\n]/', ' ', $text);
    $blank = static fn (string $pattern, string $text): string => (string) preg_replace_callback(
        $pattern,
        static fn (array $m): string => $spaces($m[0]),
        $text
    );
    $uncommented = $blank('/\{#.*?#\}/s', $source);
    $masked = $blank('/\{\{.*?\}\}|\{%.*?%\}/s', $uncommented);
    // HTML comments and script and style elements, found where the Twig tags are masked, are blanked in both.
    preg_match_all('#<!--.*?-->|<(script|style)\b[^>]*>.*?</\1\s*>#is', $masked, $hidden, PREG_OFFSET_CAPTURE);
    foreach ($hidden[0] as [$text, $offset]) {
        $uncommented = substr_replace($uncommented, $spaces($text), $offset, strlen($text));
        $masked = substr_replace($masked, $spaces($text), $offset, strlen($text));
    }
    $plugin = '/^data-(?:' . implode('|', array_map(static fn (string $p): string => preg_quote($p, '/'), POPOVER_MARKUP_PLUGINS)) . ')(?::|__|$)/';
    $line = static fn (int $offset): string => $file . ':' . (substr_count($source, "\n", 0, $offset) + 1);

    $problems = [];
    $blocks = [];
    $depth = 0;
    $start = 0;
    preg_match_all('#<(/?)sb-popover(?=[\s/>])#i', $masked, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($tags as $tag) {
        $at = $tag[0][1];
        if ($tag[1][0] === '') {
            $start = $depth++ === 0 ? $at : $start;
        } elseif ($depth === 0) {
            $problems[] = $line($at) . ': </sb-popover> without its opening tag';
        } elseif (--$depth === 0) {
            $close = strpos($masked, '>', $at);
            $blocks[] = [$start, $close === false ? strlen($masked) : $close + 1, $at];
        }
    }
    if ($depth > 0) {
        $problems[] = $line($start) . ': <sb-popover> without its </sb-popover>';
    }

    foreach ($blocks as [$from, $to, $closeTag]) {
        $where = $line($from);
        $open = popoverMarkupTagEnd($masked, $from);
        preg_match_all('/\$\$[\w$]*/', substr($uncommented, $open, $closeTag - $open), $signals, PREG_OFFSET_CAPTURE);
        foreach ($signals[0] as [$text, $offset]) {
            $problems[] = $line($open + $offset) . ": {$text} in a popover, whose light DOM reads page signals only (K1)";
        }

        $doc = HTMLDocument::createFromString('<!DOCTYPE html><body>' . substr($masked, $from, $to - $from) . '</body>', LIBXML_NOERROR);
        foreach ($doc->querySelectorAll('sb-popover') as $host) {
            foreach ($host->attributes as $attr) {
                if (preg_match('/^data-(init|ref)(?::|__|$)/', $attr->name, $m) === 1) {
                    $problems[] = "{$where} " . popoverMarkupName($host) . ": {$attr->name} on the host (" . ($m[1] === 'init' ? 'K2' : 'K8') . ')';
                }
            }
        }
        foreach ($doc->querySelectorAll('sb-popover *') as $el) {
            $host = $el->parentElement?->closest('sb-popover');
            if ($host === null) {
                continue;
            }
            $ignored = $el->closest('[data-ignore]');
            $skip = $ignored !== null && $ignored !== $host && $host->contains($ignored);
            $at = "{$where} " . popoverMarkupName($host) . ': ';
            foreach ($el->attributes as $attr) {
                $name = $attr->name;
                if (preg_match($plugin, $name) === 1) {
                    if ($skip) {
                        if (preg_match(POPOVER_MARKUP_RENAMED, $name) === 1) {
                            $problems[] = $at . "{$name} on <{$el->localName}> in a data-ignore subtree, which Rocket renames all the same (K1)";
                        }

                        continue;
                    }
                    if (str_starts_with($name, 'data-bind:')) {
                        $problems[] = $at . "keyed {$name} on <{$el->localName}> binds a signal of the popover's own (K1)";
                    } elseif (preg_match(POPOVER_MARKUP_ALLOWED, $name) !== 1) {
                        $problems[] = $at . "{$name} on <{$el->localName}>, not one a popover may carry (K1)";
                    }
                } elseif (str_starts_with($name, 'data-') && preg_match('/@[A-Za-z_$][\w$]*\(/', $attr->value) === 1) {
                    $problems[] = $at . "{$name}=\"" . trim($attr->value) . "\" on <{$el->localName}>: Rocket rewrites @name( in every data-* value (K1)";
                }
            }
        }
    }

    return $problems;
}

/** The offset just past the opening tag that starts at `$from`, skipping quoted attribute values. */
function popoverMarkupTagEnd(string $masked, int $from): int {
    $quote = null;
    for ($i = $from, $n = strlen($masked); $i < $n; ++$i) {
        $ch = $masked[$i];
        if ($quote !== null) {
            $quote = $ch === $quote ? null : $quote;
        } elseif ($ch === '"' || $ch === "'") {
            $quote = $ch;
        } elseif ($ch === '>') {
            return $i + 1;
        }
    }

    return strlen($masked);
}

function popoverMarkupName(Element $host): string {
    $id = trim($host->getAttribute('id') ?? '');

    return 'sb-popover' . ($id !== '' ? '#' . $id : '');
}

/** A popover in the form every fixture below shares, with `$body` in its list. */
function popoverMarkupFixture(string $body): string {
    return '<sb-popover id="fixture" class="fixture" label="Fixture" placement="bottom-end">' . "\n"
        . '    ' . POPOVER_MARKUP_TRIGGER . "\n"
        . '    <ul class="popover-list"><li>' . $body . '</li></ul>' . "\n"
        . '</sb-popover>';
}

describe('sb-popover markup in backend/templates', function (): void {
    test('every literal sb-popover keeps to K1 and its popover exception', function (): void {
        $root = (string) realpath(__DIR__ . '/../../backend/templates');
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.twig')) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        $problems = [];
        foreach ($files as $path) {
            array_push($problems, ...popoverMarkupProblems((string) file_get_contents($path), substr($path, strlen($root) + 1)));
        }

        expect($files)->not->toBeEmpty()
            ->and($problems)->toBe([])
        ;
    });

    test('the Flows template writes both of its popovers as literal markup, so the scan reaches them', function (): void {
        $source = (string) file_get_contents(__DIR__ . '/../../backend/templates/pages/flows.html.twig');
        preg_match_all('/<sb-popover\b[^>]*\sid="([^"]+)"/', $source, $ids);

        expect($ids[1])->toBe(['flowsExport', 'flowTable-columnsPopover']);
    });

    test('the rendered Flows page with a list keeps to K1, the values its Twig writes included', function (): void {
        $settings = isset(Config::$settings) ? Config::$settings : null;
        $prefs = isset(Config::$prefsFile) ? Config::$prefsFile : null;
        Config::$prefsFile = sys_get_temp_dir() . '/nfsen-popover-markup-missing.json';
        Config::$settings = Settings::fromArray([
            'general' => ['sources' => ['gw1'], 'ports' => [80]],
            'nfdump' => ['profiles-data' => sys_get_temp_dir() . '/nfsen-popover-markup-missing', 'profile' => 'live'],
            'frontend' => ['defaults' => ['view' => 'flows']],
        ]);
        $app = new Via((new ViaConfig())->withTemplateDir(dirname(__DIR__, 2) . '/backend/templates'));
        $app->setGlobalState('_fatalError', 'No datasource in this test.');
        $c = new Context('ctx-popover-' . bin2hex(random_bytes(3)), '/', $app);
        $states = new PageStates();

        try {
            Shell::signals($c);
            foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $module) {
                $module::signals($c);
            }
            Shell::register($c, $app, $states);
            foreach ([...PageRegistry::MODULES, ...PageRegistry::PAGES] as $module) {
                $module::register($c, $app, $states);
            }
            $c->getSignal('page')?->setValue('flows');
            $rows = [['src_addr' => '10.0.0.1', 'in_bytes' => 5], ['src_addr' => '10.0.0.2', 'in_bytes' => 7]];
            FlowActions::storeResult($states->flows, new QueryResult($rows, 'nfdump -M x', '', 0.2, TimeWindow::raw(1_000, 2_000)), 0.2, '/_action/ip-info-x', [
                'limit' => 20,
                'list' => ['tz' => '', 'sortKey' => '', 'sortDir' => '', 'hidden' => ['in_bytes']],
            ]);
            $html = $c->render('pages/flows.html.twig', Shell::render($c, $app, $states, false));
        } finally {
            $states->flows->releaseStored();
            if ($settings !== null) {
                Config::$settings = $settings;
            }
            if ($prefs !== null) {
                Config::$prefsFile = $prefs;
            }
        }

        expect(popoverMarkupProblems($html, 'rendered pages/flows.html.twig'))->toBe([])
            ->and($html)->toContain('id="flowTable-columnsPopover"', 'id="flowTable-col-in_bytes"', 'filterSignals: { include: /^via_ctx$/ }')
            ->and(substr_count($html, 'id="flowsExport"'))->toBe(1)
        ;
    });
});

describe('the popover markup check', function (): void {
    test('passes markup that keeps to the popover contract', function (string $markup): void {
        expect(popoverMarkupProblems($markup))->toBe([]);
    })->with([
        'Flows Export: slim posts that read a client signal, and Print announcing itself first' => [<<<'TWIG'
            <sb-popover class="flows-export" id="flowsExport" label="Export the flows" placement="bottom-end">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm"
                        data-preserve-attr="aria-expanded aria-haspopup" {{ f.count == 0 or f.result.lost or f.list is null ? 'disabled' }}>Export</button>
                <ul class="popover-list" id="flowsExportMenu">
                    <li><button type="button" class="menu-item" data-export="csv"
                                data-on:click="@post('{{ exportUrl }}csv&enhanced=' + ($_flows_enhanced ? 1 : 0), {{ slim }})">CSV</button></li>
                    <li><button type="button" class="menu-item" data-export="json"
                                data-on:click="@post('{{ exportUrl }}json&enhanced=' + ($_flows_enhanced ? 1 : 0), {{ slim }})">JSON</button></li>
                    <li><button type="button" class="menu-item" data-export="print"
                                data-on:click="window.nfsenFlowsList?.openPrint(); @post('{{ exportUrl }}print&enhanced=1', {{ slim }})">Print</button></li>
                </ul>
            </sb-popover>
            TWIG],
        'Flows Columns: a change on the list writes a client signal and posts the whole set' => [<<<'TWIG'
            <sb-popover class="column-selector" id="flowTable-columnsPopover" label="Columns to show" placement="bottom-end">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" aria-controls="flowTable-columns"
                        data-preserve-attr="aria-expanded aria-haspopup">Columns</button>
                {# Each box sends the whole new set, so a retried request changes nothing twice (D6). #}
                <ul class="popover-list column-selector-menu" id="flowTable-columns"
                    data-on:change="$_flows_hidden = window.nfsenFlowsList.hidden($_flows_hidden, evt.target); @post('{{ flowsColumns.url() }}?result={{ f.list.id|url_encode }}&hidden=' + $_flows_hidden.join(','), {{ slim }})">
                    <li><label><input type="checkbox" data-column-all{{ f.list.columns|filter(c => c.hidden) is empty ? ' checked' }}> Show all</label></li>
                    <li class="menu-sep" role="separator"></li>
                    {% for column in f.list.columns %}
                        <li><label><input type="checkbox" class="column-checkbox" id="flowTable-col-{{ column.key }}" data-column-key="{{ column.key }}"{{ column.hidden ? '' : ' checked' }}> {{ column.title }}</label></li>
                    {% endfor %}
                </ul>
            </sb-popover>
            TWIG],
        'Top Talkers Export: a host data-attr and a Twig loop' => [<<<'TWIG'
            <sb-popover class="talkers-export" id="statsExport" label="Export {{ r.caption }}" placement="bottom-end"
                        data-attr:hidden="!({{ current }})" data-preserve-attr="hidden">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup">Export</button>
                <ul class="popover-list" id="statsExportMenu">
                    {% for item in [{id: 'csv', label: 'CSV', method: 'exportCsv'}, {id: 'json', label: 'JSON', method: 'exportJson'}] %}
                        <li><button type="button" class="menu-item" data-export="{{ item.id }}"
                                    data-on:click="document.getElementById('statsTable')?.{{ item.method }}()">{{ item.label }}</button></li>
                    {% endfor %}
                </ul>
            </sb-popover>
            TWIG],
        'Conversations Export: data-text, data-attr and a client signal' => [<<<'TWIG'
            <code id="convCommand" hidden>{{ info.command }}</code>
            <sb-popover class="conv-export" id="convExport" label="Export" placement="bottom-end">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" id="convExportToggle"
                        data-preserve-attr="aria-expanded aria-haspopup">Export</button>
                <ul class="popover-list" id="convExportMenu">
                    <li><button type="button" class="menu-item" data-export="png" aria-disabled="false"
                                data-attr:aria-disabled="$_conv_view === 'pairs' ? 'true' : 'false'" data-preserve-attr="aria-disabled"
                                data-on:click="el.getAttribute('aria-disabled') !== 'true' && window.nfsenConversations.exportPng($_conv_view)"
                                data-text="$_conv_view === 'matrix' ? 'PNG of the Matrix' : 'PNG of the Sankey'">PNG of the Sankey</button></li>
                    <li><button type="button" class="menu-item" data-export="command"
                                data-on:click="window.nfsenConversations.copy(document.getElementById('convCommand').textContent, 'nfdump command')">Copy nfdump command</button></li>
                </ul>
            </sb-popover>
            TWIG],
        'a sources list: bind, effect, class, style, show, a debounced change and a post' => [<<<'TWIG'
            <sb-popover id="sourcesMenu" class="sources-menu" label="Sources" placement="bottom-start">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup"
                        data-attr:data-current="${{ page.id() }} > 0">Sources</button>
                <div class="popover-list sources-list" id="sourcesMenuList"
                     data-on:change__debounce.400ms="@post('{{ applyGlobals.url() }}')">
                    {% for s in sources %}
                        <label><input type="checkbox" name="globalSource" value="{{ s }}" {{ bind(graph_sources) }}
                                      data-effect="el.checked = ${{ graph_sources.id() }}.includes(el.value)"
                                      data-class:current="$_x" data-style:opacity="$_x ? 1 : 0.5" data-show="true"> {{ s }}</label>
                    {% endfor %}
                    <input data-bind="_duration" data-on:keydown__window="evt.key === 'Enter' && $_x">
                </div>
            </sb-popover>
            TWIG],
        'plugin attributes Rocket leaves alone in a data-ignore subtree' => [
            popoverMarkupFixture('<div data-ignore><span data-init="x" data-persist="y" data-bind="_z" data-json-signals></span></div>'),
        ],
        'markup outside every popover' => ['<div data-bind:x data-ref="r" data-name="a $$b">$$c</div>' . popoverMarkupFixture('<span data-text="$_x"></span>')],
        'Twig with quotes and > in the opening tag' => [<<<'TWIG'
            <sb-popover id="p{{ f.id }}" label="Actions for {{ f.name }}" {% if a > b %}class="wide"{% endif %}
                        data-show="$_n > 1" placement="bottom-end">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup"
                        aria-label="Actions for {{ f.name }}" title="{{ '>' }}">⋮</button>
                <ul class="popover-list"><li><button type="button" class="menu-item" data-action="edit">Edit</button></li></ul>
            </sb-popover>
            TWIG],
        'a comment holding forbidden markup' => [popoverMarkupFixture('{# data-bind:x data-ref="r" $$y #}<span></span>')],
        'an HTML comment and a script that name sb-popover' => [<<<'TWIG'
            <!-- the old <sb-popover> markup,
                 with data-bind:x and $$y -->
            <script>const x = '<sb-popover id="a">' + '$$b';</script>
            <style>/* <sb-popover> */ sb-popover::part(panel) { inline-size: 20rem; }</style>
            <sb-popover id="p" label="P">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup">Go</button>
                <!-- data-ref="r" $$c --><script>var s = '$$d';</script><span data-text="$_x"></span>
            </sb-popover>
            TWIG],
    ]);

    test('reports each form K1 keeps out of a popover', function (string $body, string $expected): void {
        $problems = popoverMarkupProblems(popoverMarkupFixture($body));

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toStartWith('fixture:')
            ->and($problems[0])->toContain($expected)
        ;
    })->with([
        'a keyed data-bind' => ['<input data-bind:x>', 'keyed data-bind:x'],
        'a keyed data-bind with a modifier' => ['<input data-bind:x__case.kebab>', 'keyed data-bind:x__case.kebab'],
        'data-ref' => ['<span data-ref="r"></span>', 'data-ref on <span>'],
        'a keyed data-ref' => ['<span data-ref:r></span>', 'data-ref:r on <span>'],
        'a $$ signal' => ['<span data-text="$$x"></span>', '$$x in a popover'],
        'free text holding $$' => ['<li data-name="a $$b"></li>', '$$b in a popover'],
        'free text holding @word(' => ['<li data-expression="tcp # see @docs(1)"></li>', 'data-expression="tcp # see @docs(1)"'],
        'data-signals' => ['<span data-signals="{x: 1}"></span>', 'data-signals on <span>'],
        'a keyed data-signals' => ['<span data-signals:x="1"></span>', 'data-signals:x on <span>'],
        'data-computed' => ['<span data-computed:x="1"></span>', 'data-computed:x'],
        'data-indicator' => ['<button data-indicator:busy data-on:click="1">Go</button>', 'data-indicator:busy'],
        'data-init' => ['<span data-init="x()"></span>', 'data-init on <span>'],
        'data-persist' => ['<span data-persist="x"></span>', 'data-persist on <span>'],
        'data-json-signals' => ['<pre data-json-signals></pre>', 'data-json-signals on <pre>'],
        'data-on-interval' => ['<span data-on-interval__duration.5s="x()"></span>', 'data-on-interval__duration.5s'],
        'data-on-intersect' => ['<span data-on-intersect="x()"></span>', 'data-on-intersect'],
        'data-on-signal-patch' => ['<span data-on-signal-patch="x()"></span>', 'data-on-signal-patch'],
        'data-signals in a data-ignore subtree' => [
            '<div data-ignore><span data-signals:y="1"></span></div>',
            'data-signals:y on <span> in a data-ignore subtree, which Rocket renames',
        ],
        'data-ref in a data-ignore subtree' => ['<div data-ignore><span data-ref="r"></span></div>', 'data-ref on <span> in a data-ignore subtree'],
        'a keyed data-bind in a data-ignore subtree' => ['<div data-ignore><input data-bind:x></div>', 'data-bind:x on <input> in a data-ignore subtree'],
        'a keyed data-computed in a data-ignore subtree' => [
            '<div data-ignore><span data-computed:c="1"></span></div>',
            'data-computed:c on <span> in a data-ignore subtree',
        ],
        'a $$ signal in a data-ignore subtree' => ['<div data-ignore><span data-text="$$x"></span></div>', '$$x in a popover'],
        'a $$ signal in the trigger' => [
            '</li></ul><button type="button" slot="trigger" data-attr:title="$$t">Go</button><ul><li>',
            '$$t in a popover',
        ],
    ]);

    test('reports every forbidden form of one popover, with its line', function (): void {
        $markup = "<p>before</p>\n" . <<<'TWIG'
            <sb-popover id="flowsExport" label="Export" placement="bottom-end">
                <button type="button" slot="trigger" class="menu-toggle" data-size="sm" data-preserve-attr="aria-expanded aria-haspopup">Export</button>
                <ul class="popover-list">
                    <li><input data-bind:x></li>
                    <li><span data-ref="r"></span></li>
                    <li><span data-text="$$x"></span></li>
                    <li data-name="a $$b"></li>
                </ul>
            </sb-popover>
            TWIG;

        expect(popoverMarkupProblems($markup, 'pages/flows.html.twig'))->toBe([
            'pages/flows.html.twig:7: $$x in a popover, whose light DOM reads page signals only (K1)',
            'pages/flows.html.twig:8: $$b in a popover, whose light DOM reads page signals only (K1)',
            "pages/flows.html.twig:2 sb-popover#flowsExport: keyed data-bind:x on <input> binds a signal of the popover's own (K1)",
            'pages/flows.html.twig:2 sb-popover#flowsExport: data-ref on <span>, not one a popover may carry (K1)',
        ]);
    });

    test('checks a popover nested in another, its host attributes as part of the outer one', function (): void {
        $inner = '<sb-popover id="inner" label="Inner" data-signals:open="false">' . POPOVER_MARKUP_TRIGGER . '<span data-ref="r"></span></sb-popover>';

        expect(popoverMarkupProblems(popoverMarkupFixture($inner)))->toBe([
            'fixture:1 sb-popover#fixture: data-signals:open on <sb-popover>, not one a popover may carry (K1)',
            'fixture:1 sb-popover#inner: data-ref on <span>, not one a popover may carry (K1)',
        ]);
    });

    test('reports data-init and data-ref on a host', function (): void {
        $markup = '<sb-popover id="p" label="P" data-init="x()" data-ref:pop>' . POPOVER_MARKUP_TRIGGER . '</sb-popover>';

        expect(popoverMarkupProblems($markup))->toBe([
            'fixture:1 sb-popover#p: data-init on the host (K2)',
            'fixture:1 sb-popover#p: data-ref:pop on the host (K8)',
        ]);
    });

    test('reports a popover it cannot delimit', function (): void {
        expect(popoverMarkupProblems("<div>\n<sb-popover id=\"p\" label=\"P\">" . POPOVER_MARKUP_TRIGGER))
            ->toBe(['fixture:2: <sb-popover> without its </sb-popover>'])
            ->and(popoverMarkupProblems('<span></sb-popover>'))
            ->toBe(['fixture:1: </sb-popover> without its opening tag'])
        ;
    });
});
