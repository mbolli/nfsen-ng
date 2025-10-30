/**
 * NfsenTable Web Component
 * Encapsulates table container and handles IP link clicks
 * Uses MutationObserver to detect when backend sends table HTML via SSE
 * Includes column visibility selector stored in localStorage
 */

export class NfsenTable extends HTMLElement {
    constructor() {
        super();
        this.tableId = this.id || 'defaultTable';
        this.hiddenColumns = this.loadHiddenColumns();
        this._currentView = 'table'; // 'table' or 'original'
        this.sortState = { column: null, direction: 'asc' }; // Track sort state
    }

    connectedCallback() {
        // Initial setup
        this.setupTable();

        // Re-run setup when content changes (triggered by SSE patches)
        // Use a simple event-based approach instead of MutationObserver
        this.addEventListener('DOMNodeInserted', () => {
            // Debounce to avoid multiple calls
            clearTimeout(this.setupTimeout);
            this.setupTimeout = setTimeout(() => this.setupTable(), 50);
        });
    }

    disconnectedCallback() {
        clearTimeout(this.setupTimeout);
    }

    /**
     * Setup table features (sorting, visibility, etc.)
     */
    setupTable() {
        this.applyColumnVisibility();
        this.attachViewSwitcherListeners();
        this.addExportButtons();
        this.addColumnSelector();
        this.attachSortHandlers();
    }

    /**
     * Load hidden columns from localStorage
     */
    loadHiddenColumns() {
        const key = `nfsen-table-hidden-columns-${this.tableId}`;
        const stored = localStorage.getItem(key);
        return stored ? JSON.parse(stored) : [];
    }

    /**
     * Save hidden columns to localStorage
     */
    saveHiddenColumns() {
        const key = `nfsen-table-hidden-columns-${this.tableId}`;
        localStorage.setItem(key, JSON.stringify(this.hiddenColumns));
    }

    /**
     * Attach event listeners to view switcher buttons (if they exist)
     */
    attachViewSwitcherListeners() {
        const buttons = this.querySelectorAll('button[data-view]');
        if (buttons.length === 0) return;

        // Remove old listeners by replacing with clones
        buttons.forEach((button) => {
            const newButton = button.cloneNode(true);
            button.parentNode.replaceChild(newButton, button);

            newButton.addEventListener('click', (e) => {
                const view = e.currentTarget.dataset.view;
                this.switchView(view);
            });
        });

        // Apply current view if there's an original view
        if (this.querySelector('.original')) {
            this.applyView();
        }
    }

    /**
     * Switch between table and original views
     */
    switchView(view) {
        this._currentView = view;

        // Update button states
        const buttons = this.querySelectorAll('.view-switcher button');
        buttons.forEach((button) => {
            if (button.dataset.view === view) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        });

        this.applyView();
    }

    /**
     * Apply the current view (show/hide elements)
     */
    applyView() {
        const originalView = this.querySelector('.original');
        const tableContainers = this.querySelectorAll('.table-responsive');
        const columnSelector = this.querySelector('.column-selector');

        if (this._currentView === 'table') {
            // Show table and controls
            tableContainers.forEach((container) => {
                container.style.display = '';
            });
            if (columnSelector) columnSelector.style.display = '';
            if (originalView) originalView.style.display = 'none';
        } else {
            // Show original view, hide table and column selector
            tableContainers.forEach((container) => {
                container.style.display = 'none';
            });
            if (columnSelector) columnSelector.style.display = 'none';
            if (originalView) originalView.style.display = '';
        }
    }

    /**
     * Attach event listeners to export buttons
     */
    addExportButtons() {
        const buttons = this.querySelector('.export-buttons');
        if (!buttons) {
            console.log('Export buttons container not found');
            return;
        }

        // Check if listeners already attached (by checking for a flag)
        if (buttons.dataset.listenersAttached) return;

        // Attach event listeners to existing buttons (remove old listeners by cloning)
        const csvButton = buttons.querySelector('.export-csv');
        const jsonButton = buttons.querySelector('.export-json');
        const printButton = buttons.querySelector('.export-print');

        if (csvButton) {
            const newCsvButton = csvButton.cloneNode(true);
            csvButton.parentNode.replaceChild(newCsvButton, csvButton);
            newCsvButton.addEventListener('click', () => this.exportToCSV());
        }

        if (jsonButton) {
            const newJsonButton = jsonButton.cloneNode(true);
            jsonButton.parentNode.replaceChild(newJsonButton, jsonButton);
            newJsonButton.addEventListener('click', () => this.exportToJSON());
        }

        if (printButton) {
            const newPrintButton = printButton.cloneNode(true);
            printButton.parentNode.replaceChild(newPrintButton, printButton);
            newPrintButton.addEventListener('click', () => this.printTable());
        }

        buttons.dataset.listenersAttached = 'true';
    }

    /**
     * Extract table data as JSON array
     * @param {boolean} useEnhanced - If true, use displayed (enhanced/formatted) data; if false, use raw data from data attributes
     */
    getTableData(useEnhanced = true) {
        const table = this.querySelector('table');
        if (!table) return [];

        const headers = Array.from(table.querySelectorAll('thead th'))
            .filter((th) => th.style.display !== 'none')
            .map((th) => th.textContent.replace(/[▲▼]/g, '').trim());

        const data = [];
        table.querySelectorAll('tbody tr').forEach((tr) => {
            const row = {};
            Array.from(tr.querySelectorAll('td')).forEach((td, index) => {
                const th = table.querySelectorAll('thead th')[index];
                if (th && th.style.display !== 'none') {
                    const header = headers[Object.keys(row).length];
                    // Use raw data if available and useEnhanced is false, otherwise use displayed text
                    if (!useEnhanced && td.dataset.raw !== undefined) {
                        row[header] = td.dataset.raw;
                    } else {
                        row[header] = td.textContent.trim();
                    }
                }
            });
            data.push(row);
        });

        return data;
    }

    /**
     * Export table data to CSV
     */
    exportToCSV() {
        const useEnhanced = this.querySelector('.export-enhanced-data')?.checked ?? true;
        const data = this.getTableData(useEnhanced);
        if (data.length === 0) return;

        // Get headers from first row keys
        const headers = Object.keys(data[0]);

        // Convert to CSV rows
        const rows = [headers];
        data.forEach((row) => {
            const values = headers.map((header) => {
                let value = row[header] || '';
                // Escape quotes and wrap in quotes if contains comma or quote
                if (value.includes(',') || value.includes('"') || value.includes('\n')) {
                    value = '"' + value.replace(/"/g, '""') + '"';
                }
                return value;
            });
            rows.push(values);
        });

        const csv = rows.map((row) => row.join(',')).join('\n');
        this.downloadFile(csv, 'export.csv', 'text/csv');
    }

    /**
     * Export table data to JSON
     */
    exportToJSON() {
        const useEnhanced = this.querySelector('.export-enhanced-data')?.checked ?? true;
        const data = this.getTableData(useEnhanced);
        const json = JSON.stringify(data, null, 2);
        this.downloadFile(json, 'export.json', 'application/json');
    }

    /**
     * Print the table
     */
    printTable() {
        const table = this.querySelector('table');
        if (!table) return;

        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
            <head>
                <title>Statistics Report</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 20px; }
                    table { border-collapse: collapse; width: 100%; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    tr:nth-child(even) { background-color: #f9f9f9; }
                    @media print {
                        body { padding: 0; }
                    }
                </style>
            </head>
            <body>
                <h1>Statistics Report</h1>
                ${table.outerHTML}
                <script>
                    window.onload = function() {
                        window.print();
                        window.onafterprint = function() { window.close(); }
                    }
                </script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    /**
     * Helper to download a file
     */
    downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /**
     * Add column selector dropdown to the table
     */
    addColumnSelector() {
        const table = this.querySelector('table');
        if (!table) return;

        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        // Check if selector already exists
        const existingSelector = this.querySelector('.column-selector');
        if (existingSelector) return;

        // Get all column headers
        const headers = Array.from(headerRow.querySelectorAll('th'));
        if (headers.length === 0) return;

        // Create column selector
        const selector = document.createElement('div');
        selector.className = 'column-selector';
        const allVisible = this.hiddenColumns.length === 0;
        selector.innerHTML = `
            <div class="dropdown d-inline-block">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" 
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-eye" viewBox="0 0 16 16">
                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                        <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                    </svg>
                    Columns
                </button>
                <ul class="dropdown-menu column-selector-menu" style="max-height: 300px; overflow-y: auto;">
                    <li>
                        <label class="dropdown-item fw-bold border-bottom" style="cursor: pointer;">
                            <input type="checkbox" class="form-check-input me-2" 
                                   id="column-toggle-all-${this.tableId}"
                                   ${allVisible ? 'checked' : ''}>
                            Show All
                        </label>
                    </li>
                    ${headers
                        .map((header, index) => {
                            const columnName = header.textContent.trim();
                            const isHidden = this.hiddenColumns.includes(columnName);
                            return `
                            <li>
                                <label class="dropdown-item column-checkbox-item" style="cursor: pointer;">
                                    <input type="checkbox" class="form-check-input me-2 column-checkbox" 
                                           data-column-index="${index}"
                                           data-column-name="${columnName}"
                                           ${isHidden ? '' : 'checked'}>
                                    ${columnName}
                                </label>
                            </li>
                        `;
                        })
                        .join('')}
                </ul>
            </div>
        `;

        // Insert selector into the placeholder in view-switcher, or before table if no placeholder
        const placeholder = this.querySelector('.column-selector-placeholder');
        if (placeholder) {
            placeholder.appendChild(selector);
        } else {
            const tableParent = table.parentNode;
            tableParent.parentNode.insertBefore(selector, tableParent);
        }

        // Attach event listener for "Show All" checkbox
        const toggleAllCheckbox = selector.querySelector(`#column-toggle-all-${this.tableId}`);
        if (toggleAllCheckbox) {
            toggleAllCheckbox.addEventListener('change', (e) => {
                const columnCheckboxes = selector.querySelectorAll('.column-checkbox');
                if (e.target.checked) {
                    // Show all columns
                    this.hiddenColumns = [];
                    columnCheckboxes.forEach((cb) => (cb.checked = true));
                } else {
                    // Hide all columns
                    this.hiddenColumns = headers.map((h) => h.textContent.trim());
                    columnCheckboxes.forEach((cb) => (cb.checked = false));
                }
                this.saveHiddenColumns();
                this.applyColumnVisibility();
            });
        }

        // Attach event listeners for individual column checkboxes
        selector.querySelectorAll('.column-checkbox').forEach((checkbox) => {
            checkbox.addEventListener('change', (e) => {
                const columnName = e.target.dataset.columnName;
                if (e.target.checked) {
                    // Show column
                    this.hiddenColumns = this.hiddenColumns.filter((col) => col !== columnName);
                } else {
                    // Hide column
                    if (!this.hiddenColumns.includes(columnName)) {
                        this.hiddenColumns.push(columnName);
                    }
                }

                // Update "Show All" checkbox state
                const allChecked = Array.from(selector.querySelectorAll('.column-checkbox')).every((cb) => cb.checked);
                if (toggleAllCheckbox) {
                    toggleAllCheckbox.checked = allChecked;
                }

                this.saveHiddenColumns();
                this.applyColumnVisibility();
            });
        });
    }

    /**
     * Apply column visibility based on hiddenColumns list
     */
    applyColumnVisibility() {
        const table = this.querySelector('table');
        if (!table) return;

        const headerRow = table.querySelector('thead tr');
        if (!headerRow) return;

        const headers = Array.from(headerRow.querySelectorAll('th'));
        const bodyRows = Array.from(table.querySelectorAll('tbody tr'));

        headers.forEach((header, index) => {
            const columnName = header.textContent.replace(/[▲▼]/g, '').trim(); // Remove sort arrows
            const isHidden = this.hiddenColumns.includes(columnName);

            // Hide/show header
            header.style.display = isHidden ? 'none' : '';

            // Hide/show corresponding cells in body
            bodyRows.forEach((row) => {
                const cell = row.querySelectorAll('td')[index];
                if (cell) {
                    cell.style.display = isHidden ? 'none' : '';
                }
            });
        });
    }

    /**
     * Attach click handlers to table headers for sorting
     */
    attachSortHandlers() {
        const table = this.querySelector('table');
        if (!table) return;

        const headers = table.querySelectorAll('thead th.sortable');
        headers.forEach((header, columnIndex) => {
            // Remove existing listeners by cloning
            const newHeader = header.cloneNode(true);
            header.parentNode.replaceChild(newHeader, header);

            newHeader.addEventListener('click', () => {
                this.sortTable(columnIndex, newHeader);
            });
        });
    }

    /**
     * Sort table by column index
     */
    sortTable(columnIndex, headerElement) {
        const table = this.querySelector('table');
        if (!table) return;

        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));

        // Determine sort direction
        let direction = 'asc';
        if (this.sortState.column === columnIndex) {
            // Toggle direction if same column
            direction = this.sortState.direction === 'asc' ? 'desc' : 'asc';
        }

        // Update sort state
        this.sortState = { column: columnIndex, direction };

        // Update header indicators
        this.updateSortIndicators(headerElement, direction);

        // Sort rows
        rows.sort((rowA, rowB) => {
            const cellA = rowA.querySelectorAll('td')[columnIndex];
            const cellB = rowB.querySelectorAll('td')[columnIndex];

            if (!cellA || !cellB) return 0;

            const valueA = cellA.getAttribute('data-sort-value') || cellA.textContent;
            const valueB = cellB.getAttribute('data-sort-value') || cellB.textContent;

            return this.compareValues(valueA, valueB, direction);
        });

        // Re-append sorted rows
        rows.forEach((row) => tbody.appendChild(row));

        // Re-apply column visibility after sorting
        this.applyColumnVisibility();
    }

    /**
     * Compare two values for sorting
     */
    compareValues(a, b, direction) {
        // Handle empty values - always sort to end
        if (a === '' || a === null || a === undefined) return direction === 'asc' ? 1 : -1;
        if (b === '' || b === null || b === undefined) return direction === 'asc' ? -1 : 1;

        // Try numeric comparison first
        const numA = parseFloat(a);
        const numB = parseFloat(b);

        let result = 0;

        if (!isNaN(numA) && !isNaN(numB)) {
            // Both are numbers
            result = numA - numB;
        } else {
            // String comparison (case-insensitive, with natural numeric sorting)
            result = String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
        }

        return direction === 'asc' ? result : -result;
    }

    /**
     * Update sort indicators in table headers
     */
    updateSortIndicators(activeHeader, direction) {
        const table = this.querySelector('table');
        if (!table) return;

        // Remove all existing indicators
        table.querySelectorAll('thead th.sortable').forEach((header) => {
            header.classList.remove('sort-asc', 'sort-desc');
            const existingIcon = header.querySelector('.sort-icon');
            if (existingIcon) existingIcon.remove();
        });

        // Add indicator to active column
        activeHeader.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');

        // Add arrow icon
        const icon = document.createElement('span');
        icon.className = 'sort-icon ms-1';
        icon.innerHTML = direction === 'asc' ? '▲' : '▼';
        activeHeader.appendChild(icon);
    }
}

customElements.define('nfsen-table', NfsenTable);
