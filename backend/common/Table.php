<?php

/**
 * Table Generation Class
 * Provides common functionality for generating HTML tables from data arrays.
 */

declare(strict_types=1);

namespace mbolli\nfsen_ng\common;

class Table {
    /**
     * Fields to hide from table display by default.
     */
    private const HIDDEN_FIELDS = ['cnt', 'type', 'ident', 'export_sysid', 'sampled'];

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
    ];

    /**
     * Generate a standard HTML table from data array.
     *
     * @param array  $data    Array of associative arrays representing table rows
     * @param string $tableId HTML ID for the table container
     * @param array  $options Optional configuration:
     *                        - 'hiddenFields' => array of fields to exclude
     *                        - 'cssClass' => CSS classes for table element
     *                        - 'responsive' => bool, wrap in responsive div
     *                        - 'linkIpAddresses' => bool, convert IP addresses to links
     *                        - 'emptyMessage' => string, message when no data
     *
     * @return string HTML table
     */
    public static function generate(array $data, string $tableId, array $options = []): string {
        // Merge options with defaults
        $options = array_merge([
            'hiddenFields' => self::HIDDEN_FIELDS,
            'cssClass' => 'table table-striped table-hover',
            'responsive' => true,
            'linkIpAddresses' => true,
            'emptyMessage' => 'No data available',
        ], $options);

        // Handle empty data
        if (empty($data)) {
            return \sprintf(
                '<div id="%s" class="alert alert-info">%s<br>Original output: <pre>%s</pre></div>',
                $tableId,
                $options['emptyMessage'],
                var_export($options['originalData'] ?? null, true)
            );
        }

        // Get column headers from first row
        $firstRow = $data[0];
        if (\is_string($firstRow)) {
            // If data rows are strings, return as preformatted text
            return \sprintf(
                '<div id="%s"><pre>%s</pre></div>',
                $tableId,
                htmlspecialchars(implode("\n", $data))
            );
        }
        $headers = array_filter(array_keys($firstRow), fn ($key) => !\in_array($key, $options['hiddenFields'], true));

        // Build view switcher HTML
        $viewSwitcherHtml = '';
        if ($options['originalData'] ?? false) {
            $viewSwitcherHtml = <<<'HTML'
            <div class="btn-group" role="group" aria-label="View switcher">
                <button type="button" class="btn btn-sm btn-outline-secondary active" data-view="table">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-table" viewBox="0 0 16 16">
                        <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm15 2h-4v3h4zm0 4h-4v3h4zm0 4h-4v3h3a1 1 0 0 0 1-1zm-5 3v-3H6v3zm-5 0v-3H1v2a1 1 0 0 0 1 1zm-4-4h4V8H1zm0-4h4V4H1zm5-3v3h4V4zm4 4H6v3h4z"/>
                    </svg>
                    Table View
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-view="original">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-file-text" viewBox="0 0 16 16">
                        <path d="M5 4a.5.5 0 0 0 0 1h6a.5.5 0 0 0 0-1zm-.5 2.5A.5.5 0 0 1 5 6h6a.5.5 0 0 1 0 1H5a.5.5 0 0 1-.5-.5M5 8a.5.5 0 0 0 0 1h6a.5.5 0 0 0 0-1zm0 2a.5.5 0 0 0 0 1h3a.5.5 0 0 0 0-1z"/>
                        <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zm10-1H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1"/>
                    </svg>
                    Original View
                </button>
            </div>
            HTML;
        } else {
            $viewSwitcherHtml = '<div></div>';
        }

        // Build export buttons HTML
        $exportButtonsHtml = <<<'HTML'
        <div class="export-buttons d-flex gap-2 align-items-center">
            <button class="btn btn-sm btn-outline-secondary export-csv" title="Export as CSV">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-filetype-csv" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M14 4.5V14a2 2 0 0 1-2 2h-1v-1h1a1 1 0 0 0 1-1V4.5h-2A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v9H2V2a2 2 0 0 1 2-2h5.5zM3.517 14.841a1.13 1.13 0 0 0 .401.823q.195.162.478.252.284.091.665.091.507 0 .859-.158.354-.158.539-.44.187-.284.187-.656 0-.336-.134-.56a1 1 0 0 0-.375-.357 2 2 0 0 0-.566-.21l-.621-.144a1 1 0 0 1-.404-.176.37.37 0 0 1-.144-.299q0-.234.185-.384.188-.152.512-.152.214 0 .37.068a.6.6 0 0 1 .246.181.56.56 0 0 1 .12.258h.75a1.1 1.1 0 0 0-.2-.566 1.2 1.2 0 0 0-.5-.41 1.8 1.8 0 0 0-.78-.152q-.439 0-.776.15-.337.149-.527.421-.19.273-.19.639 0 .302.122.524.124.223.352.367.228.143.539.213l.618.144q.31.073.463.193a.39.39 0 0 1 .152.326.5.5 0 0 1-.085.29.56.56 0 0 1-.255.193q-.167.07-.413.07-.175 0-.32-.04a.8.8 0 0 1-.248-.115.58.58 0 0 1-.255-.384zM.806 13.693q0-.373.102-.633a.87.87 0 0 1 .302-.399.8.8 0 0 1 .475-.137q.225 0 .398.097a.7.7 0 0 1 .272.26.85.85 0 0 1 .12.381h.765v-.072a1.33 1.33 0 0 0-.466-.964 1.4 1.4 0 0 0-.489-.272 1.8 1.8 0 0 0-.606-.097q-.534 0-.911.223-.375.222-.572.632-.195.41-.196.979v.498q0 .568.193.976.197.407.572.626.375.217.914.217.439 0 .785-.164t.55-.454a1.27 1.27 0 0 0 .226-.674v-.076h-.764a.8.8 0 0 1-.118.363.7.7 0 0 1-.272.25.9.9 0 0 1-.401.087.85.85 0 0 1-.478-.132.83.83 0 0 1-.299-.392 1.7 1.7 0 0 1-.102-.627zm8.239 2.238h-.953l-1.338-3.999h.917l.896 3.138h.038l.888-3.138h.879z"/>
                </svg>
                CSV
            </button>
            <button class="btn btn-sm btn-outline-secondary export-json" title="Export as JSON">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-filetype-json" viewBox="0 0 16 16">
                    <path fill-rule="evenodd" d="M14 4.5V11h-1V4.5h-2A1.5 1.5 0 0 1 9.5 3V1H4a1 1 0 0 0-1 1v9H2V2a2 2 0 0 1 2-2h5.5zM4.151 15.29a1.2 1.2 0 0 1-.111-.449h.764a.58.58 0 0 0 .255.384q.105.073.25.114.142.041.319.041.245 0 .413-.07a.56.56 0 0 0 .255-.193.5.5 0 0 0 .084-.29.39.39 0 0 0-.152-.326q-.152-.12-.463-.193l-.618-.143a1.7 1.7 0 0 1-.539-.214 1 1 0 0 1-.352-.367 1.1 1.1 0 0 1-.123-.524q0-.366.19-.639.192-.272.528-.422.337-.15.777-.149.456 0 .779.152.326.153.5.41.18.255.2.566h-.75a.56.56 0 0 0-.12-.258.6.6 0 0 0-.246-.181.9.9 0 0 0-.37-.068q-.324 0-.512.152a.47.47 0 0 0-.185.384q0 .18.144.3a1 1 0 0 0 .404.175l.621.143q.326.075.566.211a1 1 0 0 1 .375.358q.135.222.135.56 0 .37-.188.656a1.2 1.2 0 0 1-.539.439q-.351.158-.858.158-.381 0-.665-.09a1.4 1.4 0 0 1-.478-.252 1.1 1.1 0 0 1-.29-.375m-3.104-.033a1.3 1.3 0 0 1-.082-.466h.764a.6.6 0 0 0 .074.27.5.5 0 0 0 .454.246q.285 0 .422-.164.137-.165.137-.466v-2.745h.791v2.725q0 .66-.357 1.005-.355.345-.985.345a1.6 1.6 0 0 1-.568-.094 1.15 1.15 0 0 1-.407-.266 1.1 1.1 0 0 1-.243-.39m9.091-1.585v.522q0 .384-.117.641a.86.86 0 0 1-.322.387.9.9 0 0 1-.47.126.9.9 0 0 1-.47-.126.87.87 0 0 1-.32-.387 1.55 1.55 0 0 1-.117-.641v-.522q0-.386.117-.641a.87.87 0 0 1 .32-.387.87.87 0 0 1 .47-.129q.265 0 .47.129a.86.86 0 0 1 .322.387q.117.255.117.641m.803.519v-.513q0-.565-.205-.973a1.46 1.46 0 0 0-.59-.63q-.38-.22-.916-.22-.534 0-.92.22a1.44 1.44 0 0 0-.589.628q-.205.407-.205.975v.513q0 .562.205.973.205.407.589.626.386.217.92.217.536 0 .917-.217.384-.22.589-.627.204-.41.205-.973m1.29-.935v2.675h-.746v-3.999h.662l1.752 2.66h.032v-2.66h.75v4h-.656l-1.761-2.676z"/>
                </svg>
                JSON
            </button>
            <button class="btn btn-sm btn-outline-secondary export-print" title="Print Report">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-printer" viewBox="0 0 16 16">
                    <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/>
                    <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1"/>
                </svg>
                Print
            </button>
            <div class="form-check form-check-inline ms-2">
                <input class="form-check-input export-enhanced-data" type="checkbox" id="exportEnhancedData" checked title="Export formatted/enhanced data (unchecked = raw data)">
                <label class="form-check-label small" for="exportEnhancedData" title="Export formatted/enhanced data (unchecked = raw data)">
                    Enhanced Data
                </label>
            </div>
        </div>
        HTML;

        // Build column selector placeholder (will be populated by JS)
        $columnSelectorHtml = '<div class="column-selector-placeholder"></div>';

        // Build complete table HTML
        $html = <<<HTML
        <nfsen-table id="{$tableId}">
            <div class="table-toolbar mb-2 d-flex justify-content-between align-items-center gap-2">
                {$viewSwitcherHtml}
                {$exportButtonsHtml}
                {$columnSelectorHtml}
            </div>
        HTML;

        // Add responsive wrapper and table start
        $cssClass = $options['cssClass'];
        $html .= <<<HTML
            <div class="table-responsive" style="height: 1rem;" data-ref="outerWrapper" data-on:scroll__throttle.25ms.trailing="\$inner.scrollLeft = el.scrollLeft">
                <div data-ref="outer"></div>
            </div>
            <div class="table-responsive" data-ref="inner" data-init="\$outer.style.width = el.scrollWidth + 'px'" data-on:resize__throttle.50ms.trailing="\$outer.style.width = el.scrollWidth + 'px'" data-on:scroll__throttle.50ms.trailing="\$outerWrapper.scrollLeft = el.scrollLeft">
                <table class="{$cssClass}">
                    <thead>
                        <tr>
        HTML;

        // Add table headers
        foreach ($headers as $header) {
            $title = self::humanizeFieldName($header);
            $html .= <<<HTML
                            <th data-original-title="{$header}" class="sortable" style="cursor: pointer;">{$title}</th>
            HTML;
        }

        $html .= <<<'HTML'
                        </tr>
                    </thead>
                    <tbody>
        HTML;

        // Add table rows
        foreach ($data as $row) {
            $html .= "\n                        <tr>";
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $sortValue = TableFormatter::getSortValue($value, $header);
                $formattedValue = TableFormatter::formatCellValue($value, $header, $options);
                $sortValueEscaped = htmlspecialchars((string) $sortValue, ENT_QUOTES);
                $rawValueEscaped = htmlspecialchars((string) $value, ENT_QUOTES);
                $html .= "\n                            <td data-sort-value=\"{$sortValueEscaped}\" data-raw=\"{$rawValueEscaped}\">{$formattedValue}</td>";
            }
            $html .= "\n                        </tr>";
        }

        $html .= <<<'HTML'

                    </tbody>
                </table>
            </div>
        HTML;

        // Add original data if present
        if ($options['originalData'] ?? false) {
            $originalDataEscaped = htmlspecialchars($options['originalData']);
            $html .= <<<HTML

            <div class="original" style="display: none;">
                <pre class="mt-2">{$originalDataEscaped}</pre>
            </div>
        HTML;
        }

        // Close nfsen-table component
        $html .= "\n</nfsen-table>";

        return $html;
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
