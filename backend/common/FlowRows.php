<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/**
 * The Flows list on sb-virtual-scroll: every piece of the host's markup, for the page render and
 * for the window patches alike, so the two cannot drift.
 *
 * @phpstan-type Columns array{keys: list<string>, kinds: array<string, string>, titles: array<string, string>, widths: array<string, int>}
 * @phpstan-type Host array{result: string, total: int, itemSize: int, columns: Columns, shown: list<string>, template: string,
 *                         sortKey: string, sortDir: string, firstRows: string, windowUrl: string, sortUrl: string, ipInfoUrl: string}
 */
final class FlowRows {
    /** Row heights in px, comfortable and compact: the old table's row heights at 1400 px, 37.5 and 29.5, rounded up. */
    public const int ITEM_SIZE = 38;

    public const int ITEM_SIZE_COMPACT = 30;

    /** Rows asked for above and below the view, in px. */
    public const int BUFFER_PX = 600;

    /** Rows the result's render carries: with the buffer, a view up to about 4,800 px needs no second request. */
    public const int INITIAL_ROWS = 160;

    public const int MAX_WINDOW = 500;

    /** data-ignore on every row: Datastar's apply skips them after a window's morph. */
    public const bool ROW_IGNORE = true;

    /** The options of every list request: it posts only via_ctx, and no request cancels another. */
    public const string SLIM = "{ filterSignals: { include: /^via_ctx$/ }, requestCancellation: 'disabled' }";

    /** The host attributes a page render sets, which a window patch keeps. */
    public const string PRESERVE = 'role aria-label item-size buffer class data-ignore-morph data-on:sb-window data-on:click data-rocket-host';

    /** What the page render sets on the host; all but id, offset, total, aria-rowcount and style must be in PRESERVE. */
    public const array PAGE_ATTRIBUTES = ['id', 'role', 'aria-label', 'aria-rowcount', 'item-size', 'buffer', 'offset', 'total', 'style', 'data-ignore-morph', 'data-on:sb-window', 'data-on:click'];

    private const string IGNORE = self::ROW_IGNORE ? ' data-ignore' : ''; // @phpstan-ignore ternary.alwaysTrue (a switch kept for measuring without it)

    public static function itemSize(bool $compact): int {
        return $compact ? self::ITEM_SIZE_COMPACT : self::ITEM_SIZE;
    }

    public static function hostId(string $result): string {
        return 'flowRows-' . $result;
    }

    /**
     * The host with its header and first rows, for a render whose client lacks this result. Without
     * first rows (after a revival) it leaves out the window, and the component asks for it.
     *
     * @param Host $h
     */
    public static function host(array $h): string {
        $result = Table::jsString($h['result']);
        $window = "@post('" . Table::jsString($h['windowUrl']) . '?result=' . $result
            . "&offset=' + evt.detail.offset + '&count=' + evt.detail.count, " . self::SLIM . ')';
        $click = "const a = evt.target.closest('a.ip-link'); if (a) { evt.preventDefault(); @post('" . Table::jsString($h['ipInfoUrl'])
            . "?ip=' + encodeURIComponent(a.textContent.trim())) } "
            . "const s = evt.target.closest('button[data-sort-key]'); if (s) { "
            . "const d = s.parentElement.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc'; "
            . "\$_flows_sort = s.dataset.sortKey + ' ' + d; el.scrollToIndex(0); @post('" . Table::jsString($h['sortUrl'])
            . '?result=' . $result . "&key=' + encodeURIComponent(s.dataset.sortKey) + '&dir=' + d, " . self::SLIM . ') }';
        $rows = $h['firstRows'];
        $attributes = [
            'id' => self::hostId($h['result']),
            'role' => 'table',
            'aria-label' => 'Flows',
        ];
        if ($rows !== '') {
            $attributes['aria-rowcount'] = (string) ($h['total'] + 1);
        }
        $attributes += [
            'item-size' => (string) $h['itemSize'],
            'buffer' => (string) self::BUFFER_PX,
        ];
        if ($rows !== '') {
            $attributes['offset'] = '0';
            $attributes['total'] = (string) $h['total'];
        }
        $attributes['style'] = '--flow-cols: ' . $h['template'];

        return '<sb-virtual-scroll' . self::attributes($attributes) . ' data-ignore-morph'
            . self::attributes(['data-on:sb-window' => $window, 'data-on:click' => $click]) . '>'
            . self::header($h['columns'], $h['shown'], $h['sortKey'], $h['sortDir']) . $rows . '</sb-virtual-scroll>';
    }

    /** The host of a result the client already holds: morphs skip it, and the live host keeps its window. */
    public static function placeholder(string $result): string {
        return '<sb-virtual-scroll id="' . self::attr(self::hostId($result)) . '" data-ignore-morph></sb-virtual-scroll>';
    }

    /** A window: the host morphed by id, the page's attributes kept, the shown columns' template, the header and rows. */
    public static function window(string $result, int $offset, int $total, string $template, string $header, string $rows): string {
        return \sprintf(
            '<sb-virtual-scroll id="%s" offset="%d" total="%d" aria-rowcount="%d" style="%s" data-preserve-attr="%s">%s%s</sb-virtual-scroll>',
            self::attr(self::hostId($result)),
            $offset,
            $total,
            $total + 1,
            self::attr('--flow-cols: ' . $template),
            self::PRESERVE,
            $header,
            $rows,
        );
    }

    /**
     * The sticky header row: one sort button per shown column; the sorted one says so in aria-sort.
     *
     * @param Columns      $columns
     * @param list<string> $shown
     */
    public static function header(array $columns, array $shown, string $sortKey, string $sortDir): string {
        $html = '<div slot="header" role="row" aria-rowindex="1"' . self::IGNORE . '>';
        foreach ($shown as $key) {
            if (!isset($columns['kinds'][$key])) {
                continue;
            }
            $sort = $key === $sortKey ? ' aria-sort="' . ($sortDir === 'desc' ? 'descending' : 'ascending') . '"' : '';
            $html .= \sprintf(
                '<div role="columnheader"%s%s><button type="button" data-sort-key="%s">%s</button></div>',
                $columns['kinds'][$key] === 'num' ? ' data-num' : '',
                $sort,
                self::attr($key),
                self::attr($columns['titles'][$key] ?? $key),
            );
        }

        return $html . '</div>';
    }

    /** Row $index of the list (from 0) around its shown cells. */
    public static function row(int $index, string $cells): string {
        return '<div role="row" aria-rowindex="' . ($index + 2) . '"' . ($index % 2 === 1 ? ' data-stripe' : '')
            . self::IGNORE . '>' . $cells . '</div>';
    }

    /** One cell as the store keeps it: the kind as Table::cell() sets it, no sort value, no raw value. */
    public static function cell(string $kind, string $content): string {
        return '<div role="cell"' . self::kindAttribute($kind) . '>' . $content . '</div>';
    }

    public static function kindAttribute(string $kind): string {
        return match ($kind) {
            'num' => ' data-num',
            'time', 'address' => ' data-kind="' . $kind . '"',
            default => '',
        };
    }

    /** @param array<string, string> $attributes */
    private static function attributes(array $attributes): string {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . self::attr($value) . '"';
        }

        return $html;
    }

    private static function attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
    }
}
