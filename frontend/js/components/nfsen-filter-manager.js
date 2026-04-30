/**
 * Filter Manager Web Component
 *
 * Manages saved nfdump filters with localStorage persistence.
 * Provides dropdown selection, save, and delete functionality.
 *
 * Usage:
 *   <nfsen-filter-manager
 *     data-target-textarea="filterNfdumpTextarea"
 *     data-storage-key="stored_filters"
 *     data-default-filters='["proto tcp", "proto udp"]'>
 *   </nfsen-filter-manager>
 *
 * Attributes:
 *   - data-target-textarea: ID of the textarea to update when filter is selected
 *   - data-storage-key: localStorage key for storing filters (default: 'stored_filters')
 *   - data-default-filters: JSON array of default filters from server config
 */

class NfsenFilterManager extends HTMLElement {
    static get observedAttributes() {
        return ['data-default-filters'];
    }

    constructor() {
        super();
        this.targetTextarea = null;
        this.storageKey = 'stored_filters';
        this.defaultFilters = [];
        // Generate unique ID for this instance
        this.instanceId = `filter-select-${Math.random().toString(36).substr(2, 9)}`;
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (name === 'data-default-filters' && newValue !== oldValue) {
            try {
                this.defaultFilters = newValue ? JSON.parse(newValue) : [];
            } catch (e) {
                this.defaultFilters = [];
            }
            // Only reload if the component has already rendered
            if (this.querySelector('select')) {
                this.loadFilters();
            }
        }
    }

    connectedCallback() {
        // Get configuration from data attributes
        this.targetTextarea = this.dataset.targetTextarea || 'filterNfdumpTextarea';
        this.storageKey = this.dataset.storageKey || 'stored_filters';

        // Parse default filters from data attribute
        try {
            this.defaultFilters = this.dataset.defaultFilters ? JSON.parse(this.dataset.defaultFilters) : [];
        } catch (e) {
            console.error('Error parsing default filters:', e);
            this.defaultFilters = [];
        }

        // Render the component
        this.render();

        // Load saved filters from localStorage
        this.loadFilters();

        // Set up event listeners
        this.setupEventListeners();

        // Listen for global filter updates from settings
        this._onGlobalFiltersUpdated = (e) => {
            this.defaultFilters = e.detail?.filters ?? [];
            this.loadFilters();
        };
        document.addEventListener('nfsen-global-filters-updated', this._onGlobalFiltersUpdated);
    }

    disconnectedCallback() {
        if (this._onGlobalFiltersUpdated) {
            document.removeEventListener('nfsen-global-filters-updated', this._onGlobalFiltersUpdated);
            this._onGlobalFiltersUpdated = null;
        }
    }

    render() {
        this.innerHTML = `
            <div class="btn-group mt-2 mb-2" role="group" aria-label="Filter Manager">
                <div class="form-group">
                    <label for="${this.instanceId}">Filters</label>
                    <select id="${this.instanceId}" class="form-control form-select" title="Filters">
                        <option value="" disabled selected>Select your filter</option>
                    </select>
                </div>

                <button type="button" class="btn btn-outline-primary filter-delete-btn" disabled>
                    Delete filter <span class="small">(local)</span>
                </button>
                <button type="button" class="btn btn-outline-primary filter-save-btn">
                    Save filter <span class="small">(local)</span>
                </button>
            </div>
        `;
    }

    setupEventListeners() {
        const select = this.querySelector('select');
        const deleteBtn = this.querySelector('.filter-delete-btn');
        const saveBtn = this.querySelector('.filter-save-btn');

        // Handle filter selection
        select.addEventListener('change', () => {
            this.onFilterSelect(select.value);
        });

        // Handle delete button
        deleteBtn.addEventListener('click', () => {
            this.deleteCurrentFilter();
        });

        // Handle save button
        saveBtn.addEventListener('click', () => {
            this.saveCurrentFilter();
        });
    }

    /**
     * Load filters from localStorage and populate dropdown
     */
    loadFilters() {
        const userFilters = this.getStoredFilters();
        const select = this.querySelector('select');

        // Clear existing options (except the placeholder)
        while (select.options.length > 1) {
            select.remove(1);
        }

        // Add default filters (from server config) with a visual separator if there are user filters
        if (this.defaultFilters.length > 0) {
            this.defaultFilters.forEach((filter) => {
                const option = document.createElement('option');
                option.value = filter;
                option.textContent = `${filter} (global)`;
                option.dataset.global = '1';
                select.appendChild(option);
            });

            // Add separator if there are also user filters
            if (userFilters.length > 0) {
                const separator = document.createElement('option');
                separator.disabled = true;
                separator.textContent = '──────────────';
                select.appendChild(separator);
            }
        }

        // Add user-saved filters
        userFilters.forEach((filter) => {
            const option = document.createElement('option');
            option.value = filter;
            option.textContent = filter;
            option.dataset.global = '0';
            select.appendChild(option);
        });
    }

    /**
     * Get filters from localStorage
     * @returns {Array} Array of filter strings
     */
    getStoredFilters() {
        try {
            const stored = window.localStorage.getItem(this.storageKey);
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            console.error('Error loading filters from localStorage:', e);
            return [];
        }
    }

    /**
     * Save filters to localStorage
     * @param {Array} filters - Array of filter strings
     */
    setStoredFilters(filters) {
        try {
            window.localStorage.setItem(this.storageKey, JSON.stringify(filters));
        } catch (e) {
            console.error('Error saving filters to localStorage:', e);
        }
    }

    /**
     * Get the target textarea element
     * @returns {HTMLElement|null}
     */
    getTargetTextarea() {
        return document.getElementById(this.targetTextarea);
    }

    /**
     * Get the current filter value from the textarea
     * @returns {string}
     */
    getCurrentFilterValue() {
        const textarea = this.getTargetTextarea();
        return textarea ? textarea.value.trim() : '';
    }

    /**
     * Handle filter selection - update target textarea
     * @param {string} filterValue - The selected filter value
     */
    onFilterSelect(filterValue) {
        const textarea = this.getTargetTextarea();
        if (textarea) {
            textarea.value = filterValue;
            // Dispatch both input (for Datastar data-bind) and change for other listeners
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            textarea.dispatchEvent(new Event('change', { bubbles: true }));
            // Set delete button disabled state based on whether the filter is global
            const selectedOption = this.querySelector(`option[value="${filterValue}"]`);
            const isGlobal = selectedOption && selectedOption.dataset.global === '1';
            const deleteBtn = this.querySelector('.filter-delete-btn');
            if (deleteBtn) {
                deleteBtn.disabled = isGlobal;
            }
        }
    }

    /**
     * Delete the current filter from localStorage
     */
    deleteCurrentFilter() {
        const currentFilter = this.getCurrentFilterValue();
        if (!currentFilter) {
            return;
        }

        let filters = this.getStoredFilters();
        const originalLength = filters.length;

        // Remove the filter
        filters = filters.filter((f) => f !== currentFilter);

        if (filters.length === originalLength) {
            // Filter wasn't found
            alert('Filter not found in saved filters');
            return;
        }

        // Save updated list
        this.setStoredFilters(filters);

        // Reload dropdown
        this.loadFilters();

        // Optional: Clear the select
        const select = this.querySelector('select');
        if (select) {
            select.value = '';
        }
    }

    /**
     * Save the current filter to localStorage
     */
    saveCurrentFilter() {
        const currentFilter = this.getCurrentFilterValue();
        if (!currentFilter) {
            console.warn('No filter to save');
            return;
        }
        // Require at least two words for a valid nfdump filter
        if (currentFilter.split(/\s+/).length < 2) {
            alert('A valid nfdump filter must contain at least two words.');
            return;
        }

        let filters = this.getStoredFilters();

        // Check if filter already exists
        if (filters.includes(currentFilter)) {
            console.log('Filter already saved');
            return;
        }

        // Add to beginning of array
        filters.unshift(currentFilter);

        // Save updated list
        this.setStoredFilters(filters);

        // Reload dropdown to show new filter
        this.loadFilters();

        // Select the newly added filter
        const select = this.querySelector('select');
        if (select) {
            select.value = currentFilter;
        }
    }

    /**
     * Public method to refresh the filter list
     * Can be called externally if needed
     */
    refresh() {
        this.loadFilters();
    }
}

// Register the custom element
customElements.define('nfsen-filter-manager', NfsenFilterManager);

export { NfsenFilterManager };
