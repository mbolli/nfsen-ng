/**
 * NfsenChart Web Component
 * Encapsulates Dygraphs charting library with Datastar integration
 */
export class NfsenChart extends HTMLElement {
    constructor() {
        super();
        this.dygraph = null;
        this.config = null;
        this.container = null;
    }

    connectedCallback() {
        this.container = this.querySelector('.chart-canvas');
        // Initialize chart when data is provided via Datastar signals
        this.initializeChart();
    }

    disconnectedCallback() {
        if (this.dygraph) {
            this.dygraph.destroy();
            this.dygraph = null;
        }
    }

    /**
     * Display a message in the chart container
     * @param {string} message - The message to display
     * @param {string} type - Message type: 'error', 'warning', 'info', or 'loading'
     */
    showMessage(message, type = 'info') {
        if (!this.container) return;

        const typeClasses = {
            error: 'alert alert-danger',
            warning: 'alert alert-warning',
            info: 'text-center py-5 text-muted',
            loading: 'text-center py-5 text-muted',
        };

        const cssClass = typeClasses[type] || typeClasses.info;
        this.container.innerHTML = `<div class="${cssClass}">${message}</div>`;
    }

    /**
     * Wait for Dygraph library to be loaded
     * @param {number} timeout - Maximum time to wait in milliseconds (default: 5000)
     * @returns {Promise<boolean>} - Resolves to true if loaded, false if timeout
     */
    async waitForDygraph(timeout = 5000) {
        const startTime = Date.now();

        while (!window.Dygraph) {
            if (Date.now() - startTime > timeout) {
                return false;
            }
            // Wait 50ms before checking again
            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        return true;
    }

    async initializeChart() {
        // Wait for Dygraph library to load
        if (!window.Dygraph) {
            this.showMessage('Waiting for Dygraph library...', 'info');
            const loaded = await this.waitForDygraph();
            if (!loaded) {
                this.showMessage('Dygraph library failed to load', 'error');
                return;
            }
        }

        // Get data from attributes or wait for Datastar signal updates
        const dataAttr = this.dataset.chartData;
        const configAttr = this.dataset.chartConfig;

        if (!(dataAttr && configAttr)) {
            return;
        }

        try {
            // Datastar binds signals to attributes as JSON strings
            const data = JSON.parse(dataAttr);
            const config = configAttr ? JSON.parse(configAttr) : {};

            this.updateChart(data, config);
        } catch (e) {
            console.error('Error parsing chart data:', e, 'dataAttr:', dataAttr);
            this.showMessage(`Error parsing data: ${e.message}`, 'error');
        }
    }

    /**
     * Enable/disable the sync button based on whether the graph has been zoomed/panned
     */
    updateSyncButtonState(enabled) {
        const syncButton = document.querySelector('#date_syncing button.sync-date');
        if (syncButton) {
            syncButton.disabled = !enabled;
        }
    }

    /**
     * Get the current zoom/pan range from the graph
     * @returns {Object} {from: timestamp_ms, to: timestamp_ms}
     */
    getCurrentRange() {
        if (this.dygraph) {
            const range = this.dygraph.xAxisRange();
            return {
                from: Math.floor(range[0]),
                to: Math.floor(range[1]),
            };
        }
        return null;
    }

    updateChart(data, config) {
        // Transform RRD data format to Dygraphs format if needed
        let chartData = data;
        let labels = ['Date'];

        // Check if data is in RRD format (object with data, start, end, step, legend)
        if (data && typeof data === 'object' && data.data && data.legend) {
            labels = ['Date', ...data.legend];

            // Convert {timestamp: [values]} to [[Date, val1, val2, ...]]
            chartData = Object.entries(data.data).map(([timestamp, values]) => {
                const date = new Date(parseInt(timestamp) * 1000);
                return [date, ...values];
            });
        }

        const type = config.type || 'flows';
        const trafficUnit = config.trafficUnit || 'bits';

        if (!chartData || chartData.length === 0) {
            this.showMessage('No data available for the selected range.', 'info');
            return;
        }

        // If chart exists, just update it instead of recreating
        if (this.dygraph) {
            // Merge new config with existing
            const updateConfig = {
                title: this.getTitle(config.display, config.sources, config.protocols, config.ports, type),
                labels: labels,
                ylabel: type !== 'traffic' ? type.toUpperCase() + '/s' : trafficUnit + '/s',
                labelsKMB: type === 'flows' || type === 'packets',
                labelsKMG2: trafficUnit === 'bits' || trafficUnit === 'bytes',
                file: chartData,
                // Reset dateWindow to show the full range of new data (not the previous zoom)
                dateWindow: [chartData[0][0], chartData[chartData.length - 1][0]],
            };

            this.dygraph.updateOptions(updateConfig);

            // Update series controls if labels changed
            if (JSON.stringify(labels) !== JSON.stringify(this.currentLabels)) {
                this.currentLabels = labels;
                this.populateSeriesControls(labels.slice(1));
            }

            return;
        }

        // Create new Dygraph instance
        this.config = {
            title: this.getTitle(config.display, config.sources, config.protocols, config.ports, type),
            labels: labels,
            ylabel: type !== 'traffic' ? type.toUpperCase() + '/s' : trafficUnit + '/s',
            xlabel: 'TIME',
            labelsKMB: type === 'flows' || type === 'packets',
            labelsKMG2: trafficUnit === 'bits' || trafficUnit === 'bytes',
            labelsDiv: document.getElementById('legend'),
            labelsSeparateLines: true,
            legend: 'always',
            stepPlot: true,
            showRangeSelector: true,
            dateWindow: [chartData[0][0], chartData[chartData.length - 1][0]],
            zoomCallback: this.handleZoom.bind(this),
            clickCallback: this.handleClick.bind(this),
            highlightSeriesOpts: {
                strokeWidth: 2,
                strokeBorderWidth: 1,
                highlightCircleSize: 5,
            },
            rangeSelectorPlotStrokeColor: '#888888',
            rangeSelectorPlotFillColor: '#cccccc',
            stackedGraph: true,
            fillGraph: true,
        };

        try {
            this.dygraph = new window.Dygraph(this.container, chartData, this.config);
            this.currentLabels = labels;
            // Populate series visibility checkboxes
            this.populateSeriesControls(labels.slice(1)); // Skip 'Date' label

            // Set up interaction model to detect panning
            this.setupPanHandler();

            // Set up range selector interaction handler
            this.setupRangeSelectorHandler();
        } catch (e) {
            console.error('Error creating Dygraph:', e);
            this.showMessage(`Error creating chart: ${e.message}`, 'error');
        }
    }

    getTitle(display, sources, protocols, ports, type) {
        const displayItems = display === 'sources' ? sources : display === 'protocols' ? protocols : ports;
        const cat = displayItems.length > 1 ? display : display.replace(/s$/, ''); // singular form
        const titleSuffix = displayItems.length > 4 ? displayItems.length + ' ' + cat : cat + ' ' + displayItems.join(', ');
        return type + ' for ' + titleSuffix;
    }

    /**
     * Handle zoom events from Dygraph
     */
    handleZoom(minDate, maxDate, yRanges) {
        // Enable sync button when graph is zoomed or panned
        this.updateSyncButtonState(true);

        // Expose zoomed range as attributes
        this.setAttribute('data-zoom-start', Math.floor(minDate));
        this.setAttribute('data-zoom-end', Math.floor(maxDate));
    }

    /**
     * Handle click events on data points
     * Allows user to click a point and zoom in to a 5-minute window around it
     */
    handleClick(e, x, points) {
        if (!confirm('Zoom in to this data point?')) {
            return;
        }
        const from = x;
        const to = x + 300000;
        if (this.dygraph) {
            this.dygraph.updateOptions({
                dateWindow: [from, to],
            });
        }
        this.updateSyncButtonState(true);
        this.setAttribute('data-zoom-start', Math.floor(from));
        this.setAttribute('data-zoom-end', Math.floor(to));
    }

    /**
     * Set up pan handler to detect when user pans the graph
     */
    setupPanHandler() {
        if (!this.dygraph) return;

        // Save original endPan function if not already saved
        if (!this.originalEndPan) {
            this.originalEndPan = window.Dygraph.defaultInteractionModel.endPan;
        }

        // Replace with our own that also enables sync button
        window.Dygraph.defaultInteractionModel.endPan = (event, g, context) => {
            // Call original
            if (this.originalEndPan) {
                this.originalEndPan(event, g, context);
            }

            // Enable sync button after panning
            this.updateSyncButtonState(true);
        };
    }

    /**
     * Set up range selector handler to detect when user drags the range selector
     */
    setupRangeSelectorHandler() {
        if (!this.dygraph) return;

        // Wait for Dygraph to render the range selector
        setTimeout(() => {
            const rangeSelectorElements = this.container.querySelectorAll('.dygraph-rangesel-fgcanvas, .dygraph-rangesel-zoomhandle');

            rangeSelectorElements.forEach((element) => {
                // Track if we're currently dragging the range selector
                element.addEventListener('mousedown', () => {
                    this.rangeSelectorActive = true;

                    // Set up mouseup handler
                    const handleMouseUp = () => {
                        if (this.rangeSelectorActive) {
                            this.rangeSelectorActive = false;
                            // Enable sync button after range selector drag
                            this.updateSyncButtonState(true);
                        }
                        document.removeEventListener('mouseup', handleMouseUp);
                    };

                    document.addEventListener('mouseup', handleMouseUp);
                });
            });
        }, 100);
    }

    // Method to update chart data (can be called from Datastar)
    static get observedAttributes() {
        return ['data-chart-data', 'data-chart-config'];
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue !== newValue && this.isConnected && newValue !== null) {
            this.initializeChart();
        }
    }

    /**
     * Populate series visibility controls
     * Creates checkboxes for each data series with Datastar event handlers
     * @param {Array} seriesLabels - Array of series names
     */
    populateSeriesControls(seriesLabels) {
        const seriesContainer = document.getElementById('series');
        if (!seriesContainer) return;

        seriesContainer.innerHTML = '';
        seriesLabels.forEach((label, index) => {
            const id = `series_${index}`;
            const wrapper = document.createElement('label');
            wrapper.className = 'form-check';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'form-check-input';
            checkbox.id = id;
            checkbox.checked = true;
            checkbox.setAttribute('data-on:change', `$_graphWidget.setVisibility(${index}, event.target.checked)`);

            const labelEl = document.createElement('label');
            labelEl.className = 'form-check-label';
            labelEl.htmlFor = id;
            labelEl.textContent = label;

            wrapper.appendChild(checkbox);
            wrapper.appendChild(labelEl);
            seriesContainer.appendChild(wrapper);
        });
    }

    // Public API methods to match Dygraph API for backward compatibility
    // These allow the old nfsen-ng.js code to interact with the chart

    /**
     * Resize the chart (called when container size changes)
     */
    resize() {
        if (this.dygraph) {
            this.dygraph.resize();
        }
    }

    /**
     * Update chart options (e.g., stepPlot, stackedGraph, logscale)
     * @param {Object} options - Dygraph options to update
     */
    updateOptions(options) {
        if (this.dygraph) {
            this.dygraph.updateOptions(options);
        }
    }

    /**
     * Set visibility of a data series
     * @param {number} seriesIndex - Index of the series to show/hide (0-based for data series, not including Date)
     * @param {boolean} visible - Whether the series should be visible
     */
    setVisibility(seriesIndex, visible) {
        if (this.dygraph) {
            // Dygraph expects 0-based index where 0 is the first data series (not the date column)
            this.dygraph.setVisibility(seriesIndex, visible);
        }
    }

    /**
     * Get the current x-axis range
     * @returns {Array} [minX, maxX] where values are timestamps
     */
    xAxisRange() {
        if (this.dygraph) {
            return this.dygraph.xAxisRange();
        }
        return null;
    }

    /**
     * Check if the chart is currently zoomed
     * @param {string} axis - 'x' or 'y'
     * @returns {boolean}
     */
    isZoomed(axis) {
        if (this.dygraph) {
            return this.dygraph.isZoomed(axis);
        }
        return false;
    }

    /**
     * Destroy the chart
     */
    destroy() {
        if (this.dygraph) {
            this.dygraph.destroy();
            this.dygraph = null;
        }
    }

    /**
     * Serialize component state to JSON (for e.g. data-json-signals)
     */
    toJSON() {
        return this.tagName;
    }
}

// Register the custom element
customElements.define('nfsen-chart', NfsenChart);
