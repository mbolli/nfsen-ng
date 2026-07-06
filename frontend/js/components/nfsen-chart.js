/**
 * NfsenChart Web Component
 * Encapsulates Apache ECharts with Datastar integration.
 *
 * Public interface (tag, attributes, methods) is unchanged from the previous
 * Dygraphs-backed implementation — graph-view.html.twig, graph-filters.html.twig,
 * and date-range.html.twig all keep calling the same methods with the same
 * Dygraph-shaped option keys (logscale, stackedGraph, stepPlot, labelsKMG2, ...).
 * Those keys are translated internally in updateOptions() rather than changing
 * every call site — see docs/features/dygraph-to-echarts.md.
 */
import { tzOptions } from './tz-utils.js';

/** Binary-prefixed (K=1024) formatter, matching Dygraph's labelsKMG2 for bits/bytes. */
function formatKMG2(value) {
    const units = ['', 'K', 'M', 'G', 'T'];
    let v = value;
    let i = 0;
    while (Math.abs(v) >= 1024 && i < units.length - 1) {
        v /= 1024;
        i++;
    }
    return `${Number.isInteger(v) ? v : v.toFixed(2)}${units[i]}`;
}

/** Decimal-prefixed (K=1000) formatter, matching Dygraph's labelsKMB for flows/packets. */
function formatKMB(value) {
    const units = ['', 'K', 'M', 'B'];
    let v = value;
    let i = 0;
    while (Math.abs(v) >= 1000 && i < units.length - 1) {
        v /= 1000;
        i++;
    }
    return `${Number.isInteger(v) ? v : v.toFixed(2)}${units[i]}`;
}

export class NfsenChart extends HTMLElement {
    constructor() {
        super();
        this.chart = null;
        this.config = null;
        this.container = null;
        this.currentLabels = null;
        this.lastChartData = null;
        // Style state tracked across separate updateOptions() calls (each toggle button
        // in graph-filters.html.twig calls updateOptions() with only its own key set).
        this._logscale = false;
        this._stacked = false;
        this._stepPlot = true;
        this._labelsKMB = false;
        this._labelsKMG2 = false;
        this._ylabel = '';
    }

    connectedCallback() {
        this.container = this.querySelector('.chart-canvas');
        // Initialize chart when data is provided via Datastar signals
        this.initializeChart();

        // Resize whenever the container changes dimensions (handles responsive
        // layout changes and sidebar-toggling without a hard page reload). Observe
        // the canvas container itself, not just the custom element — the outer
        // <nfsen-chart> can already be at its final size while the inner div is
        // still settling, which is exactly when ECharts needs to re-measure.
        this._resizeObserver = new ResizeObserver(() => {
            if (this.chart) this.chart.resize();
        });
        this._resizeObserver.observe(this);
        if (this.container) this._resizeObserver.observe(this.container);

        // Re-apply canvas colours when the user toggles dark/light mode
        this._themeObserver = new MutationObserver(() => {
            if (this.chart) this.applyTheme();
        });
        this._themeObserver.observe(document.documentElement, { attributeFilter: ['data-bs-theme'] });
    }

    disconnectedCallback() {
        if (this._resizeObserver) {
            this._resizeObserver.disconnect();
            this._resizeObserver = null;
        }
        if (this._themeObserver) {
            this._themeObserver.disconnect();
            this._themeObserver = null;
        }
        this.destroy();
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
     * Wait for the ECharts library to be loaded
     * @param {number} timeout - Maximum time to wait in milliseconds (default: 5000)
     * @returns {Promise<boolean>} - Resolves to true if loaded, false if timeout
     */
    async waitForECharts(timeout = 5000) {
        const startTime = Date.now();

        while (!window.echarts) {
            if (Date.now() - startTime > timeout) {
                return false;
            }
            // Wait 50ms before checking again
            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        return true;
    }

    getThemeColors() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return {
            backgroundColor: isDark ? '#212529' : '#ffffff',
            textColor: isDark ? '#dee2e6' : '#212529',
            axisLineColor: isDark ? '#495057' : '#dee2e6',
            splitLineColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.1)',
            tooltipBg: isDark ? '#2b3035' : '#ffffff',
            tooltipBorder: isDark ? '#495057' : '#dee2e6',
            dataZoomBg: isDark ? '#2b3035' : '#f8f9fa',
            dataZoomFill: isDark ? '#495057' : '#cccccc',
            // Brighter series colors on a dark canvas, similar in spirit to the old
            // Dygraph colorSaturation/colorValue adjustment.
            palette: isDark
                ? ['#4dd0e1', '#81c784', '#ffb74d', '#e57373', '#ba68c8', '#90a4ae', '#f06292', '#a1887f']
                : ['#1f77b4', '#2ca02c', '#ff7f0e', '#d62728', '#9467bd', '#607d8b', '#e91e8c', '#795548'],
        };
    }

    /** Re-apply theme colors without rebuilding series/axis data. */
    applyTheme() {
        if (!this.chart) return;
        const theme = this.getThemeColors();
        this.chart.setOption(
            {
                backgroundColor: theme.backgroundColor,
                color: theme.palette,
                textStyle: { color: theme.textColor },
                title: { textStyle: { color: theme.textColor } },
                xAxis: {
                    axisLine: { lineStyle: { color: theme.axisLineColor } },
                    splitLine: { lineStyle: { color: theme.splitLineColor } },
                },
                yAxis: {
                    axisLine: { lineStyle: { color: theme.axisLineColor } },
                    splitLine: { lineStyle: { color: theme.splitLineColor } },
                },
                tooltip: { backgroundColor: theme.tooltipBg, borderColor: theme.tooltipBorder, textStyle: { color: theme.textColor } },
                dataZoom: [
                    {},
                    { backgroundColor: theme.dataZoomBg, fillerColor: theme.dataZoomFill, textStyle: { color: theme.textColor } },
                ],
            },
            false
        );
    }

    async initializeChart() {
        // Ensure container is available (attributeChangedCallback fires before connectedCallback during upgrade)
        if (!this.container) {
            this.container = this.querySelector('.chart-canvas');
            if (!this.container) return;
        }

        // Wait for ECharts to load
        if (!window.echarts) {
            this.showMessage('Waiting for ECharts library...', 'info');
            const loaded = await this.waitForECharts();
            if (!loaded) {
                this.showMessage('ECharts library failed to load', 'error');
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
     * @returns {Object|null} {from: timestamp_ms, to: timestamp_ms}
     */
    getCurrentRange() {
        if (!this.chart) return null;

        const opt = this.chart.getOption();
        const dz = opt.dataZoom?.[0];
        if (dz && dz.startValue != null && dz.endValue != null) {
            return { from: Math.floor(dz.startValue), to: Math.floor(dz.endValue) };
        }

        // No zoom interaction has happened yet — dataZoom start/end are still percentages.
        // Fall back to the full data range.
        if (this.lastChartData?.length) {
            const first = this.lastChartData[0][0];
            const last = this.lastChartData[this.lastChartData.length - 1][0];
            return {
                from: Math.floor(first instanceof Date ? first.getTime() : first),
                to: Math.floor(last instanceof Date ? last.getTime() : last),
            };
        }

        return null;
    }

    updateChart(data, config) {
        // Transform RRD data format to a row-per-timestamp array, same shape as before
        let chartData = data;
        let labels = ['Date'];

        // Check if data is in RRD format (object with data, start, end, step, legend)
        if (data && typeof data === 'object' && data.data && data.legend) {
            labels = ['Date', ...data.legend];

            // Convert {timestamp: [values]} to [[Date, val1, val2, ...]]
            chartData = Object.entries(data.data).map(([timestamp, values]) => {
                const date = new Date(parseInt(timestamp, 10) * 1000);
                return [date, ...values];
            });
        }

        const type = config.type || 'flows';
        const trafficUnit = config.trafficUnit || 'bits';
        const displayTz = config.displayTz || 'browser';
        const nfcapdTz = config.nfcapdTz || 'UTC';
        const tzOpts = tzOptions(displayTz, nfcapdTz);
        this.dateFmt = (ms) => new Date(ms).toLocaleString(undefined, tzOpts);

        if (!chartData || chartData.length === 0) {
            this.lastChartData = null;
            if (this.chart) {
                this.chart.dispose();
                this.chart = null;
            }
            this.showMessage('No data available for the selected range.', 'info');
            return;
        }

        this.lastChartData = chartData;
        this._ylabel = type !== 'traffic' ? `${type.toUpperCase()}/s` : `${trafficUnit}/s`;
        this._labelsKMB = type === 'flows' || type === 'packets';
        this._labelsKMG2 = trafficUnit === 'bits' || trafficUnit === 'bytes';
        this._stepPlot = config.stepplot !== undefined ? config.stepplot : true;
        this._logscale = config.logscale || false;
        this._stacked = config.stacked || false;

        const title = this.getTitle(config.display, config.sources, config.protocols, config.ports, type);
        const option = this.buildOption(chartData, labels, title);

        if (this.chart) {
            const doUpdate = () => {
                this.chart.setOption(option, true);
                if (JSON.stringify(labels) !== JSON.stringify(this.currentLabels)) {
                    this.currentLabels = labels;
                    this.populateSeriesControls(labels.slice(1));
                }
            };

            if (typeof this.container.startViewTransition === 'function') {
                this.container.startViewTransition(doUpdate);
            } else if (document.startViewTransition) {
                document.startViewTransition(doUpdate);
            } else {
                doUpdate();
            }

            return;
        }

        try {
            // showMessage() may have left a message div behind (error/empty/loading state) —
            // clear it before creating a fresh ECharts instance on the container.
            this.container.innerHTML = '';
            this.chart = window.echarts.init(this.container);
            // Defensive: if the container's layout hadn't fully settled yet at init time,
            // ECharts may have measured a stale/undersized width. Re-measure once on the
            // next frame so the chart isn't permanently stuck at that size.
            requestAnimationFrame(() => this.chart?.resize());

            this.chart.setOption(option, true);
            this.currentLabels = labels;
            // Populate series visibility checkboxes
            this.populateSeriesControls(labels.slice(1)); // Skip 'Date' label

            // Single event covers main-plot zoom, pan, and range-selector drag alike —
            // ECharts fires the same 'datazoom' event for all three, and for programmatic
            // dispatchAction({type: 'dataZoom', ...}) calls too (used by click-to-zoom below).
            this.chart.on('datazoom', () => this.handleZoom());

            // Click a data point to zoom into a 5-minute window around it
            this.chart.on('click', (params) => this.handleClick(params));

            // Keep the external #legend div in sync with the hovered x-position
            this.chart.on('updateAxisPointer', (params) => this.updateExternalLegend(params));
        } catch (e) {
            console.error('Error creating chart:', e);
            this.showMessage(`Error creating chart: ${e.message}`, 'error');
        }
    }

    /**
     * Build the full ECharts option object for the current data + style state.
     * Used both for initial creation and for updates (always passed with notMerge:true),
     * which sidesteps the index/id merge subtleties of partial option patches.
     */
    buildOption(chartData, labels, title) {
        const theme = this.getThemeColors();
        const seriesNames = labels.slice(1);
        const formatY = this._labelsKMG2 ? formatKMG2 : this._labelsKMB ? formatKMB : (v) => String(v);

        return {
            backgroundColor: theme.backgroundColor,
            color: theme.palette,
            textStyle: { color: theme.textColor },
            title: { text: title, textStyle: { color: theme.textColor, fontSize: 14 } },
            grid: { left: 60, right: 20, top: 40, bottom: 70 },
            legend: { show: false }, // external #series checkboxes + #legend div are used instead
            tooltip: {
                trigger: 'axis',
                backgroundColor: theme.tooltipBg,
                borderColor: theme.tooltipBorder,
                textStyle: { color: theme.textColor },
                valueFormatter: formatY,
            },
            xAxis: {
                type: 'time',
                name: 'TIME',
                axisLine: { lineStyle: { color: theme.axisLineColor } },
                splitLine: { lineStyle: { color: theme.splitLineColor } },
                axisLabel: { formatter: (ms) => this.dateFmt(ms), hideOverlap: true },
            },
            yAxis: {
                type: this._logscale ? 'log' : 'value',
                name: this._ylabel,
                axisLine: { lineStyle: { color: theme.axisLineColor } },
                splitLine: { lineStyle: { color: theme.splitLineColor } },
                axisLabel: { formatter: formatY },
            },
            dataZoom: [
                { type: 'inside', xAxisIndex: 0 },
                {
                    type: 'slider',
                    xAxisIndex: 0,
                    showDataShadow: true,
                    backgroundColor: theme.dataZoomBg,
                    fillerColor: theme.dataZoomFill,
                    textStyle: { color: theme.textColor },
                },
            ],
            dataset: { source: chartData },
            series: seriesNames.map((name, i) => ({
                name,
                type: 'line',
                showSymbol: false,
                step: this._stepPlot ? 'end' : false,
                stack: this._stacked ? 'total' : undefined,
                areaStyle: this._stacked ? {} : undefined,
                encode: { x: 0, y: i + 1 },
            })),
        };
    }

    getTitle(display, sources, protocols, ports, type) {
        const parse = (v) => {
            if (Array.isArray(v)) return v;
            if (typeof v === 'string') {
                try {
                    return JSON.parse(v);
                } catch {
                    return [v];
                }
            }
            return [v];
        };
        const displayItems = parse(display === 'sources' ? sources : display === 'protocols' ? protocols : ports);
        const cat = displayItems.length > 1 ? display : display.replace(/s$/, ''); // singular form
        const titleSuffix = displayItems.length > 4 ? `${displayItems.length} ${cat}` : `${cat} ${displayItems.join(', ')}`;
        return `${type} for ${titleSuffix}`;
    }

    /**
     * Handle zoom/pan/range-selector-drag events (all fire the same 'datazoom' event).
     */
    handleZoom() {
        const range = this.getCurrentRange();
        if (!range) return;

        this.updateSyncButtonState(true);
        this.setAttribute('data-zoom-start', range.from);
        this.setAttribute('data-zoom-end', range.to);
        this.dispatchEvent(new CustomEvent('graph-zoom', { bubbles: true, detail: { from: range.from, to: range.to } }));
    }

    /**
     * Handle click events on data points.
     * Allows user to click a point and zoom in to a 5-minute window around it.
     */
    handleClick(params) {
        if (params.componentType !== 'series' || !params.value) return;
        if (!confirm('Zoom in to this data point?')) {
            return;
        }
        const x = params.value[0] instanceof Date ? params.value[0].getTime() : Number(params.value[0]);
        if (this.chart) {
            // Dispatching a dataZoom action fires the same 'datazoom' event a manual zoom
            // would, so handleZoom() above takes care of the sync-button/attribute/event work.
            this.chart.dispatchAction({ type: 'dataZoom', startValue: x, endValue: x + 300000 });
        }
    }

    /**
     * Update the external #legend div to show the series values at the hovered x-position,
     * matching the Dygraph labelsDiv behavior. ECharts has no built-in "render legend into
     * an arbitrary external DOM node" option, so this is hand-rolled.
     */
    updateExternalLegend(params) {
        const legendEl = document.getElementById('legend');
        if (!legendEl || !this.chart) return;

        const dataIndex = params.dataIndex;
        const source = this.lastChartData;
        if (dataIndex == null || !source || !source[dataIndex]) {
            return;
        }

        const [ts, ...values] = source[dataIndex];
        const tsMs = ts instanceof Date ? ts.getTime() : ts;
        const formatY = this._labelsKMG2 ? formatKMG2 : this._labelsKMB ? formatKMB : (v) => String(v);
        const seriesNames = (this.currentLabels || []).slice(1);

        const rows = seriesNames
            .map((name, i) => `<div>${escapeHtml(name)}: <b>${values[i] != null ? formatY(values[i]) : '—'}</b></div>`)
            .join('');
        legendEl.innerHTML = `<div class="fw-semibold">${escapeHtml(this.dateFmt(tsMs))}</div>${rows}`;
    }

    // Method to update chart data (can be called from Datastar)
    static get observedAttributes() {
        return ['data-chart-data', 'data-chart-config'];
    }

    attributeChangedCallback(_name, oldValue, newValue) {
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
            checkbox.setAttribute('data-on:change', `$graph._widget.setVisibility(${index}, event.target.checked)`);

            const labelEl = document.createElement('label');
            labelEl.className = 'form-check-label';
            labelEl.htmlFor = id;
            labelEl.textContent = label;

            wrapper.appendChild(checkbox);
            wrapper.appendChild(labelEl);
            seriesContainer.appendChild(wrapper);
        });
    }

    // Public API methods — same names/shapes as the previous Dygraphs-backed implementation
    // so graph-view.html.twig / graph-filters.html.twig / date-range.html.twig need no changes.

    /**
     * Resize the chart (called when container size changes)
     */
    resize() {
        if (this.chart) {
            this.chart.resize();
        }
    }

    /**
     * Update chart options. Accepts the same Dygraph-shaped keys the templates already
     * send (logscale, stackedGraph, fillGraph, stepPlot, ylabel, labelsKMG2) and translates
     * them into ECharts option patches internally.
     * @param {Object} options
     */
    updateOptions(options) {
        if (!this.chart || !this.currentLabels) return;

        if ('logscale' in options) this._logscale = options.logscale;
        if ('stackedGraph' in options) this._stacked = options.stackedGraph;
        if ('fillGraph' in options) this._stacked = options.fillGraph;
        if ('stepPlot' in options) this._stepPlot = options.stepPlot;
        if ('ylabel' in options) this._ylabel = options.ylabel;
        if ('labelsKMG2' in options) this._labelsKMG2 = options.labelsKMG2;

        const formatY = this._labelsKMG2 ? formatKMG2 : this._labelsKMB ? formatKMB : (v) => String(v);
        const seriesNames = this.currentLabels.slice(1);

        this.chart.setOption(
            {
                yAxis: {
                    type: this._logscale ? 'log' : 'value',
                    name: this._ylabel,
                    axisLabel: { formatter: formatY },
                },
                series: seriesNames.map((name, i) => ({
                    name,
                    step: this._stepPlot ? 'end' : false,
                    stack: this._stacked ? 'total' : undefined,
                    areaStyle: this._stacked ? {} : undefined,
                    encode: { x: 0, y: i + 1 },
                })),
            },
            false
        );
    }

    /**
     * Set visibility of a data series
     * @param {number} seriesIndex - Index of the series to show/hide (0-based for data series, not including Date)
     * @param {boolean} visible - Whether the series should be visible
     */
    setVisibility(seriesIndex, visible) {
        if (!this.chart || !this.currentLabels) return;
        const seriesName = this.currentLabels[seriesIndex + 1];
        if (!seriesName) return;
        // legend.selected controls series visibility regardless of whether the legend
        // widget itself is shown (it's hidden here — see legend: {show:false} in buildOption).
        this.chart.setOption({ legend: { selected: { [seriesName]: visible } } }, false);
    }

    /**
     * Get the current x-axis range
     * @returns {Array|null} [minX, maxX] where values are timestamps
     */
    xAxisRange() {
        const range = this.getCurrentRange();
        return range ? [range.from, range.to] : null;
    }

    /**
     * Check if the chart is currently zoomed
     * @returns {boolean}
     */
    isZoomed() {
        const range = this.getCurrentRange();
        if (!range || !this.lastChartData?.length) return false;
        const first = this.lastChartData[0][0];
        const last = this.lastChartData[this.lastChartData.length - 1][0];
        const fullFrom = first instanceof Date ? first.getTime() : first;
        const fullTo = last instanceof Date ? last.getTime() : last;
        return range.from > fullFrom || range.to < fullTo;
    }

    /**
     * Destroy the chart
     */
    destroy() {
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    }

    /**
     * Serialize component state to JSON (for e.g. data-json-signals)
     */
    toJSON() {
        return this.tagName;
    }
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

// Register the custom element
customElements.define('nfsen-chart', NfsenChart);
