<?php

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

/** Result tables of nfdump rows, enhanced client-side by nfsen-table.js (4.3.4). */
class Table {
    /** Fields left out unless the caller passes its own hiddenFields. */
    public const array HIDDEN_FIELDS = ['cnt', 'type', 'ident', 'export_sysid', 'sampled'];

    /** Rows per page choices of a paginated table (D14). */
    public const array PAGE_SIZES = [25, 50, 100, 250];

    /** Rows per chunk of generateChunked(): about 1 MB of markup, one event each. */
    public const int CHUNK_ROWS = 1000;

    /**
     * Field name to human-readable title mapping.
     */
    private const FIELD_TITLES = [
        'record' => 'Flow Records',
        'srcip' => 'Source IP',
        'dstip' => 'Destination IP',
        'ip' => 'IP Address',
        'srcport' => 'Source Port',
        'dstport' => 'Destination Port',
        'port' => 'Port',
        'proto' => 'Protocol',
        'tos' => 'Type of Service',
        'srctos' => 'Source ToS',
        'dsttos' => 'Destination ToS',
        'as' => 'AS Number',
        'srcas' => 'Source AS',
        'dstas' => 'Destination AS',
        'inif' => 'Input Interface',
        'outif' => 'Output Interface',
        'mpls1' => 'MPLS Label 1',
        'mpls2' => 'MPLS Label 2',
        'mpls3' => 'MPLS Label 3',
        'mpls4' => 'MPLS Label 4',
        'mpls5' => 'MPLS Label 5',
        'mpls6' => 'MPLS Label 6',
        'mpls7' => 'MPLS Label 7',
        'mpls8' => 'MPLS Label 8',
        'mpls9' => 'MPLS Label 9',
        'mpls10' => 'MPLS Label 10',
        'srcmask' => 'Source Mask',
        'dstmask' => 'Destination Mask',
        'srcvlan' => 'Source VLAN',
        'dstvlan' => 'Destination VLAN',
        'insrcmac' => 'Input Source MAC',
        'outdstmac' => 'Output Destination MAC',
        'indstmac' => 'Input Destination MAC',
        'outsrcmac' => 'Output Source MAC',
        // NSEL / Cisco ASA
        'event' => 'NSEL Event',
        'xevent' => 'NSEL Extended Event',
        'natsrcip' => 'NAT Src IP',
        'natdstip' => 'NAT Dst IP',
        'natip' => 'NAT IP',
        'natsrcport' => 'NAT Src Port',
        'natdstport' => 'NAT Dst Port',
        'natport' => 'NAT Port',
        // NEL / NAT
        'nevent' => 'NAT Event',
        'nsrcip' => 'NAT Src IP',
        'ndstip' => 'NAT Dst IP',
        'nsrcport' => 'NAT Src Port',
        'ndstport' => 'NAT Dst Port',
        // nfdump JSON address fields, after Nfdump::normalizeAddressFamilyKeys() dropped
        // the address-family digit (src4_addr/src6_addr -> src_addr, ...)
        'src_addr' => 'Source IP',
        'dst_addr' => 'Destination IP',
        'src_tun_ip' => 'Source Tunnel IP',
        'dst_tun_ip' => 'Destination Tunnel IP',
        'src_xlt_ip' => 'XLATE Src IP',
        'dst_xlt_ip' => 'XLATE Dst IP',
        'ip_next_hop' => 'Next Hop',
        'bgp_next_hop' => 'BGP Next Hop',
        'ip_router' => 'Router IP',
        // NSEL translated address fields (flow listing)
        'xlate_src_addr' => 'XLATE Src IP',
        'xlate_dst_addr' => 'XLATE Dst IP',
        'xlate_src_port' => 'XLATE Src Port',
        'xlate_dst_port' => 'XLATE Dst Port',
        'nat_src_addr' => 'NAT Src IP',
        'nat_dst_addr' => 'NAT Dst IP',
        'nat_src_port' => 'NAT Src Port',
        'nat_dst_port' => 'NAT Dst Port',
        // nfdump's `-o csv` names, which is what an aggregated query answers with: it refuses
        // json once records are aggregated (#174). Without these the columns read as the
        // camelCase key split into words, so an aggregated table said "SrcAddr" and "DstPort"
        // where the same unaggregated table said "Source IP" and "Destination Port".
        'firstSeen' => 'First Seen',
        'lastSeen' => 'Last Seen',
        'srcAddr' => 'Source IP',
        'dstAddr' => 'Destination IP',
        'srcPort' => 'Source Port',
        'dstPort' => 'Destination Port',
        'srcNet' => 'Source Network',
        'dstNet' => 'Destination Network',
        'srcMask' => 'Source Mask',
        'dstMask' => 'Destination Mask',
        'srcAS' => 'Source AS',
        'dstAS' => 'Destination AS',
        'nextAS' => 'Next AS',
        'prevAS' => 'Previous AS',
        'srcVlan' => 'Source VLAN',
        'dstVlan' => 'Destination VLAN',
        'srcGeo' => 'Source Country',
        'dstGeo' => 'Destination Country',
        'srcTos' => 'Source ToS',
        'dstTos' => 'Destination ToS',
        'inSrcMac' => 'Input Source MAC',
        'outDstMac' => 'Output Destination MAC',
        'inDstMac' => 'Input Destination MAC',
        'outSrcMac' => 'Output Source MAC',
        'nextIP' => 'Next Hop',
        'bgpNextIP' => 'BGP Next Hop',
        'routerIP' => 'Router IP',
        'obsDomainID' => 'Observation Domain',
        'obsPointID' => 'Observation Point',
        'minTTL' => 'Min TTL',
        'maxTTL' => 'Max TTL',
        'input' => 'Input Interface',
        'output' => 'Output Interface',
        'mplsLabel1' => 'MPLS Label 1',
        'mplsLabel2' => 'MPLS Label 2',
        'mplsLabel3' => 'MPLS Label 3',
        'mplsLabel4' => 'MPLS Label 4',
        'mplsLabel5' => 'MPLS Label 5',
        'mplsLabel6' => 'MPLS Label 6',
        'mplsLabel7' => 'MPLS Label 7',
        'mplsLabel8' => 'MPLS Label 8',
        'mplsLabel9' => 'MPLS Label 9',
        'mplsLabel10' => 'MPLS Label 10',
        'bps' => 'Bits/s',
        'rank' => 'Rank',
        'share_pct' => 'Share',
        'bpp' => 'Bytes/Packet',
        // Merged-flow columns, which exist only for a bi-directional query.
        'outPackets' => 'Out Packets',
        'inPackets' => 'In Packets',
        'outBytes' => 'Out Bytes',
        'inBytes' => 'In Bytes',
    ];

    /**
     * A table of nfdump rows inside an `<nfsen-table>`. Ids are derived from $tableId, so
     * several tables can share a document.
     *
     * @param list<mixed>          $data    one decoded nfdump record per entry (field => value), or raw
     *                                      output lines rendered as preformatted text
     * @param array<string, mixed> $options Optional configuration:
     *                                      - 'hiddenFields' => list<string>, fields to leave out (default HIDDEN_FIELDS)
     *                                      - 'linkIpAddresses' => bool, addresses open the IP info modal
     *                                      - 'ipInfoActionUrl' => string, the ip-info action the address links post to
     *                                      - 'emptyMessage' => string, said when there are no rows
     *                                      - 'emptyTitle' => string, the empty state's heading (default 'No rows')
     *                                      - 'originalData' => string, nfdump's text for the Original view
     *                                      - 'paginate' => bool, client-side pages (default false)
     *                                      - 'pageSize' => int, rows per page (default 50)
     *                                      - 'limit' => int, the row limit the rows were fetched with, for the pager text
     *                                      - 'limitReached' => bool, whether that limit cut the result (default: rows >= limit)
     *                                      - 'rankColumn' => string, field rendered as a .rank chip, first
     *                                      - 'rankSeries' => array<int, int>, rank => series slot
     *                                      - 'caption' => string, the table's caption and print title
     *                                      - 'exportName' => string, file name of exports without extension
     *                                      - 'result' => string, the result id nfsen-table tells results apart by
     */
    public static function generate(array $data, string $tableId, array $options = []): string {
        return self::render($data, $tableId, $options, 0)['html'];
    }

    /**
     * generate() with only the first page inline: the other rows are `<template>` chunks that
     * nfsen-table asks $options['rowsUrl'] for one at a time, so no event holds them all (D26).
     *
     * @param list<mixed>          $data
     * @param array<string, mixed> $options generate()'s options plus 'rowsUrl' => string
     *
     * @return array{html: string, chunks: \Generator<int, string, mixed, void>}
     */
    public static function generateChunked(array $data, string $tableId, array $options, int $chunkRows = self::CHUNK_ROWS): array {
        return self::render($data, $tableId, [...$options, 'paginate' => true], max(1, $chunkRows));
    }

    /**
     * "Showing 1-50 of 1,234 returned (limit 10,000)." plus why there are no more when the
     * limit was reached; nfsen-table.js says the same after a page change.
     */
    public static function pagerText(int $from, int $to, int $total, int $limit, ?bool $limitReached = null): string {
        $shown = $total === 0 ? 'No rows' : \sprintf('Showing %s-%s of %s', number_format($from), number_format($to), number_format($total));
        if ($limit <= 0) {
            return $shown . ($total === 0 ? '.' : ' rows.');
        }

        $text = \sprintf('%s returned (limit %s).', $shown, number_format($limit));

        return ($limitReached ?? $total >= $limit) ? $text . ' nfdump cannot skip rows: raise the limit to see more.' : $text;
    }

    /**
     * Get the human-readable title for a statistics type.
     *
     * @param string $statsFor The statistics type
     *
     * @return string Human-readable title
     */
    public static function getStatsTitle(string $statsFor): string {
        return self::FIELD_TITLES[$statsFor] ?? ucfirst($statsFor);
    }

    /**
     * Page numbers (0-based) the pager lists, null for a gap: all of up to seven, else the
     * first, the last and the ones around the current page.
     *
     * @return list<null|int>
     */
    public static function pagerPages(int $current, int $pages): array {
        if ($pages <= 7) {
            return range(0, max(0, $pages - 1));
        }
        if ($current < 4) {
            return [0, 1, 2, 3, 4, null, $pages - 1];
        }
        if ($current > $pages - 5) {
            return [0, null, ...range($pages - 5, $pages - 1)];
        }

        return [0, null, $current - 1, $current, $current + 1, null, $pages - 1];
    }

    /**
     * @param list<mixed>          $data
     * @param array<string, mixed> $options
     *
     * @return array{html: string, chunks: \Generator<int, string, mixed, void>}
     */
    private static function render(array $data, string $tableId, array $options, int $chunkRows): array {
        $options = array_merge([
            'hiddenFields' => self::HIDDEN_FIELDS,
            'linkIpAddresses' => true,
            'emptyMessage' => 'No data available',
            'emptyTitle' => 'No rows',
            'originalData' => '',
            'paginate' => false,
            'pageSize' => 50,
            'limit' => 0,
            'limitReached' => null,
            'rankColumn' => '',
            'rankSeries' => [],
            'caption' => '',
            'exportName' => '',
            'result' => '',
            'rowsUrl' => '',
        ], $options);

        $id = self::attr($tableId);
        $original = \is_string($options['originalData']) ? $options['originalData'] : '';

        if ($data === []) {
            if ($original !== '') {
                // Output nfdump could not read into rows (an unparsed biflow table): the text is the result.
                return ['html' => \sprintf('<div id="%s" class="table-raw"><pre>%s</pre></div>', $id, self::text($original)), 'chunks' => self::noChunks()];
            }

            // Result tables sit in a card under its h2 title (2.5).
            return ['html' => \sprintf('<div id="%s" class="empty-state"><h3>%s</h3><p>%s</p></div>', $id, self::text(self::stringOption($options, 'emptyTitle')), self::text(self::stringOption($options, 'emptyMessage'))), 'chunks' => self::noChunks()];
        }

        if (\is_string($data[0])) {
            // Only the first row is inspected, so anything that is not a line is dropped rather
            // than reaching implode() as an array.
            return ['html' => \sprintf(
                '<div id="%s" class="table-raw"><pre>%s</pre></div>',
                $id,
                self::text(implode("\n", array_filter($data, \is_string(...))))
            ), 'chunks' => self::noChunks()];
        }

        $hidden = \is_array($options['hiddenFields']) ? $options['hiddenFields'] : self::HIDDEN_FIELDS;
        $rankColumn = self::stringOption($options, 'rankColumn');
        $headers = array_values(array_filter(self::collectHeaders($data), static fn (string $key): bool => !\in_array($key, $hidden, true)));
        if ($rankColumn !== '' && \in_array($rankColumn, $headers, true)) {
            $headers = [$rankColumn, ...array_values(array_diff($headers, [$rankColumn]))];
        }
        $kinds = [];
        foreach ($headers as $header) {
            $kinds[$header] = $header === $rankColumn ? 'rank' : TableFormatter::cellKind($header);
        }

        /** @var array<int, int> $rankSeries */
        $rankSeries = \is_array($options['rankSeries']) ? $options['rankSeries'] : [];
        $row = static function (array $row) use ($headers, $kinds, $rankColumn, $rankSeries, $options): string {
            $html = '<tr>';
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $scalar = self::scalar($value);
                $content = $header === $rankColumn
                    ? self::rankChip($scalar, $rankSeries)
                    : TableFormatter::formatCellValue($value, $header, $options);
                $html .= self::cell($kinds[$header], $content, $scalar, self::scalar(TableFormatter::getSortValue($value, $header)));
            }

            return $html . '</tr>';
        };

        $rows = array_values(array_filter($data, \is_array(...)));
        $total = \count($rows);
        $paginate = (bool) $options['paginate'];
        $pageSize = max(1, (int) $options['pageSize']);
        $limit = max(0, (int) $options['limit']);
        $reached = \is_bool($options['limitReached']) ? $options['limitReached'] : ($limit > 0 && $total >= $limit);
        $chunked = $chunkRows > 0 && $total > $pageSize;
        $inline = $chunked ? $pageSize : $total;
        $caption = self::stringOption($options, 'caption');
        $exportName = self::stringOption($options, 'exportName');
        $result = self::stringOption($options, 'result');

        // Datastar moves an element whose id both results share into the new result host and
        // morphs it, so nfsen-table.js tells results apart by this token, not by element identity.
        $attributes = ['id' => $tableId, 'data-result' => $result !== '' ? $result : bin2hex(random_bytes(4))];
        if ($caption !== '') {
            $attributes['data-caption'] = $caption;
        }
        if ($exportName !== '') {
            $attributes['data-export-name'] = $exportName;
        }
        if ($paginate) {
            $attributes['data-page-size'] = (string) $pageSize;
            $attributes['data-limit'] = (string) $limit;
            $attributes['data-limit-reached'] = $reached ? 'true' : 'false';
        }
        if ($chunked) {
            $attributes['data-total'] = (string) $total;
            $attributes['data-chunks'] = (string) (int) ceil(($total - $pageSize) / $chunkRows);
            $rowsUrl = self::stringOption($options, 'rowsUrl');
            if ($rowsUrl !== '') {
                $attributes['data-on:nfsen-table-more'] = "@post('" . self::jsString($rowsUrl) . "?result=' + evt.detail.result + '&chunk=' + evt.detail.chunk)";
            }
        }
        $ipInfoUrl = self::stringOption($options, 'ipInfoActionUrl');
        if ($options['linkIpAddresses'] && $ipInfoUrl !== '') {
            // One handler for every address link, rather than one per cell for Datastar to wire up.
            $attributes['data-on:click'] = "const a = evt.target.closest('a.ip-link'); if (a) { evt.preventDefault(); @post('"
                . self::jsString($ipInfoUrl) . "?ip=' + encodeURIComponent(a.textContent.trim())) }";
        }

        $html = '<nfsen-table' . self::attributes($attributes) . ">\n";
        $html .= self::toolbar($tableId, $original !== '');
        $html .= \sprintf('<div class="table-scrollbar" id="%sOuter" aria-hidden="true"><div></div></div>', $id) . "\n";
        $html .= \sprintf('<div class="table-wrap" id="%sInner">', $id) . "\n";
        $html .= $rankColumn !== '' ? '<table class="ranking">' : '<table>';
        if ($caption !== '') {
            $html .= '<caption class="visually-hidden">' . self::text($caption) . '</caption>';
        }
        $html .= "\n<thead>\n<tr>";
        foreach ($headers as $header) {
            $html .= \sprintf(
                '<th scope="col" data-original-title="%s"%s><button type="button" class="sort-button">%s</button></th>',
                self::attr($header),
                $kinds[$header] === 'num' ? ' data-num' : '',
                self::text(self::humanizeFieldName($header))
            );
        }
        $html .= "</tr>\n</thead>\n<tbody>";

        for ($index = 0; $index < $inline; ++$index) {
            if ($paginate && $index === $pageSize) {
                // Rows past the first page wait in a template: Datastar never walks them, and
                // nfsen-table.js keeps them in memory, attaching one page at a time.
                $html .= "\n</tbody>\n</table>\n</div>\n<template class=\"table-rows\">";
            }
            $html .= "\n" . $row($rows[$index]);
        }
        $html .= $paginate && $inline > $pageSize ? "\n</template>\n" : "\n</tbody>\n</table>\n</div>\n";

        if ($original !== '') {
            $html .= '<div class="original" hidden><pre>' . self::text($original) . "</pre></div>\n";
        }
        if ($paginate) {
            $html .= self::pager($tableId, $total, $pageSize, $limit, $reached);
        }

        return [
            'html' => $html . '</nfsen-table>',
            'chunks' => $chunked ? self::chunks($rows, $pageSize, $chunkRows, $row) : self::noChunks(),
        ];
    }

    /**
     * The rows from $from on, as `<template data-chunk>` elements of $size rows each.
     *
     * @param list<array<mixed>>             $rows
     * @param \Closure(array<mixed>): string $row
     *
     * @return \Generator<int, string, mixed, void>
     */
    private static function chunks(array $rows, int $from, int $size, \Closure $row): \Generator {
        $total = \count($rows);
        for ($chunk = 0, $start = $from; $start < $total; ++$chunk, $start += $size) {
            $html = '<template class="table-rows" data-chunk="' . $chunk . '">';
            for ($index = $start, $end = min($total, $start + $size); $index < $end; ++$index) {
                $html .= "\n" . $row($rows[$index]);
            }

            yield $chunk => $html . "\n</template>";
        }
    }

    /** @return \Generator<int, string, mixed, void> */
    private static function noChunks(): \Generator {
        yield from [];
    }

    /**
     * Collect the column list as the union of all rows' keys.
     *
     * Rows are not guaranteed to share a schema: nfdump's JSON output emits per-record
     * fields (ports for TCP/UDP vs. icmp_type/icmp_code for ICMP, and so on), so deriving
     * the columns from the first row alone silently drops every field the first record
     * happens not to carry (#157). A new key is inserted right behind the previous key of
     * its own row, which keeps related columns adjacent instead of appended at the end.
     *
     * @param list<mixed> $data non-array rows are skipped
     *
     * @return list<string>
     */
    private static function collectHeaders(array $data): array {
        /** @var list<string> $headers */
        $headers = [];

        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $insertAt = 0;
            foreach (array_keys($row) as $key) {
                $position = array_search($key, $headers, true);
                if ($position === false) {
                    array_splice($headers, $insertAt, 0, [$key]);
                    ++$insertAt;
                } else {
                    $insertAt = $position + 1;
                }
            }
        }

        return $headers;
    }

    /** The Original view switch (Top Talkers keeps it), Enhanced data and the Columns menu slot. */
    private static function toolbar(string $tableId, bool $original): string {
        $id = self::attr($tableId);
        $html = '<div class="table-toolbar">';
        if ($original) {
            $html .= <<<'HTML'
                <div class="button-group" role="group" aria-label="View">
                    <button type="button" data-size="sm" aria-pressed="true" data-view="table">Table</button>
                    <button type="button" data-size="sm" aria-pressed="false" data-view="original">Original</button>
                </div>
                HTML;
        }
        $html .= \sprintf(
            '<label class="choice table-enhanced" title="Export formatted values; unchecked exports the raw values"><input type="checkbox" class="export-enhanced-data" id="%s-enhanced" checked> Enhanced data</label>',
            $id
        );

        return $html . "<div class=\"column-selector-placeholder\"></div></div>\n";
    }

    /** The pager as nfsen-table.js renders it for the first page, so nothing moves when it takes over. */
    private static function pager(string $tableId, int $total, int $pageSize, int $limit, bool $reached): string {
        $id = self::attr($tableId);
        $pages = max(1, (int) ceil($total / $pageSize));
        $buttons = '';
        foreach (self::pagerPages(0, $pages) as $page) {
            $buttons .= match ($page) {
                null => '<li aria-hidden="true">…</li>',
                0 => '<li><button type="button" data-size="sm" data-page="0" aria-current="page" aria-label="Page 1">1</button></li>',
                default => \sprintf('<li><button type="button" data-size="sm" data-page="%1$d" aria-label="Page %2$d">%2$d</button></li>', $page, $page + 1),
            };
        }
        $options = '';
        foreach (array_unique([...self::PAGE_SIZES, $pageSize]) as $size) {
            $options .= \sprintf('<option value="%1$d"%2$s>%1$d</option>', $size, $size === $pageSize ? ' selected' : '');
        }

        return \sprintf(
            '<nav class="table-pager" aria-label="Pages"><p class="table-pager-status" aria-live="polite">%1$s</p>'
            . '<div class="table-pager-controls"><button type="button" data-size="sm" data-page="prev" disabled>Previous</button>'
            . '<ol class="table-pager-pages">%2$s</ol>'
            . '<button type="button" data-size="sm" data-page="next"%3$s>Next</button>'
            . '<label class="table-pager-size" for="%4$s-page-size">Rows per page</label> <select id="%4$s-page-size">%5$s</select></div></nav>',
            self::text(self::pagerText($total > 0 ? 1 : 0, min($total, $pageSize), $total, $limit, $reached)),
            $buttons,
            $pages > 1 ? '' : ' disabled',
            $id,
            $options
        ) . "\n";
    }

    /**
     * A 10,000 row result has to fit a 128 MB worker, so a cell does not repeat a value it
     * already shows; nfsen-table.js reads those from the text or the time's epoch.
     */
    private static function cell(string $kind, string $content, string $raw, string $sort): string {
        $attributes = match ($kind) {
            'num' => ' data-num',
            'time', 'address', 'rank' => ' data-kind="' . $kind . '"',
            default => '',
        };
        if ($sort !== $raw && !str_contains($content, 'data-epoch="' . $sort . '"')) {
            $attributes .= ' data-sort-value="' . self::attr($sort) . '"';
        }
        if ($content !== self::text($raw) && html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5) !== $raw) {
            $attributes .= ' data-raw="' . self::attr($raw) . '"';
        }

        return '<td' . $attributes . '>' . $content . '</td>';
    }

    /** @param array<int, int> $rankSeries */
    private static function rankChip(string $rank, array $rankSeries): string {
        $slot = ctype_digit($rank) ? ($rankSeries[(int) $rank] ?? null) : null;

        return \sprintf(
            '<span class="rank"%s>%s</span>',
            $slot !== null ? ' data-series="' . $slot . '"' : '',
            self::text($rank)
        );
    }

    /** @param array<string, string> $attributes */
    private static function attributes(array $attributes): string {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . self::attr($value) . '"';
        }

        return $html;
    }

    /** @param array<string, mixed> $options */
    private static function stringOption(array $options, string $key): string {
        return \is_string($options[$key] ?? null) ? $options[$key] : '';
    }

    private static function scalar(mixed $value): string {
        return \is_scalar($value) ? (string) $value : '';
    }

    /** Inside a single-quoted JavaScript string. */
    private static function jsString(string $value): string {
        return addcslashes($value, "\\'\n\r");
    }

    private static function attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
    }

    private static function text(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
    }

    /**
     * Convert a field name to a human-readable title.
     *
     * @param string $fieldName The field name
     *
     * @return string Human-readable title
     */
    private static function humanizeFieldName(string $fieldName): string {
        // Otherwise, convert underscores to spaces and capitalize
        return self::FIELD_TITLES[$fieldName] ?? ucwords(str_replace('_', ' ', $fieldName));
    }
}
