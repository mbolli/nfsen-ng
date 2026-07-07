/**
 * NfsenSankey Web Component
 * Renders a src -> dst traffic-flow Sankey diagram using Apache ECharts.
 *
 * Node ids from the server are pre-prefixed with 'src:'/'dst:' (see
 * SankeyActions::buildSankeyPayload()) so the graph is always strictly bipartite —
 * every node has either only outgoing or only incoming edges, never both. ECharts'
 * default topology-driven sankey layout therefore naturally produces exactly two
 * columns (sources left, destinations right) with no manual depth/nodeAlign config.
 */
export class NfsenSankey extends HTMLElement {
    constructor() {
        super();
        this.chart = null;
        this.container = null;
        this.lastPayload = null;
    }

    connectedCallback() {
        this.container = this.querySelector('.sankey-canvas');
        this.initializeChart();

        // Observe the canvas container itself, not just the custom element — the
        // outer <nfsen-sankey> can already be at its final size while the inner div
        // is still settling (e.g. right after the results card switches from
        // display:none to visible), which is exactly when ECharts needs to re-measure.
        this._resizeObserver = new ResizeObserver(() => {
            if (this.chart) this.chart.resize();
        });
        this._resizeObserver.observe(this);
        if (this.container) this._resizeObserver.observe(this.container);

        this._themeObserver = new MutationObserver(() => {
            if (this.chart && this.lastPayload) this.renderChart(this.lastPayload);
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

    showMessage(message, type = 'info') {
        if (!this.container) return;

        const typeClasses = {
            error: 'alert alert-danger',
            warning: 'alert alert-warning',
            info: 'text-center py-5 text-muted',
            loading: 'text-center py-5 text-muted',
        };

        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }

        const cssClass = typeClasses[type] || typeClasses.info;
        this.container.innerHTML = `<div class="${cssClass}">${message}</div>`;
    }

    async waitForEcharts(timeout = 5000) {
        const startTime = Date.now();

        while (!window.echarts) {
            if (Date.now() - startTime > timeout) {
                return false;
            }
            await new Promise((resolve) => setTimeout(resolve, 50));
        }

        return true;
    }

    async initializeChart() {
        if (!this.container) {
            this.container = this.querySelector('.sankey-canvas');
            if (!this.container) return;
        }

        if (!window.echarts) {
            this.showMessage('Waiting for ECharts library...', 'info');
            const loaded = await this.waitForEcharts();
            if (!loaded) {
                this.showMessage('ECharts library failed to load', 'error');
                return;
            }
        }

        const dataAttr = this.dataset.sankeyData;
        if (!dataAttr) {
            return;
        }

        try {
            const payload = JSON.parse(dataAttr);
            this.updateChart(payload);
        } catch (e) {
            console.error('Error parsing sankey data:', e, 'dataAttr:', dataAttr);
            this.showMessage(`Error parsing data: ${e.message}`, 'error');
        }
    }

    /**
     * Format a raw byte count as a human-readable string (e.g. "1.7 GB").
     */
    formatBytes(value) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let n = Number(value) || 0;
        let i = 0;
        while (n >= 1024 && i < units.length - 1) {
            n /= 1024;
            i++;
        }
        return `${n.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
    }

    formatNumber(value) {
        return Number(value).toLocaleString();
    }

    getThemeColors() {
        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return {
            textColor: isDark ? '#dee2e6' : '#212529',
            lineColor: isDark ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.15)',
            tooltipBg: isDark ? '#2b3035' : '#ffffff',
            tooltipBorder: isDark ? '#495057' : '#dee2e6',
        };
    }

    updateChart(payload) {
        const nodes = payload?.nodes || [];
        const links = payload?.links || [];

        if (!nodes.length || !links.length) {
            this.lastPayload = null;
            this.showMessage('No data available for the selected range.', 'info');
            return;
        }

        this.lastPayload = payload;
        this.renderChart(payload);
    }

    /**
     * Sankey node labels collide vertically once too many nodes are packed into a
     * fixed-height canvas (GitHub issue #152 — 100+ nodes per column reduced to an
     * unreadable smear of overlapping text). Give each node a minimum vertical
     * allowance so labels on either column stay legible, growing the canvas (the
     * outer .sankey-container scrolls, see nfsen-ng.css) instead of shrinking
     * node/label spacing indefinitely.
     */
    computeCanvasHeight(nodes) {
        const MIN_HEIGHT = 500;
        const PX_PER_NODE = 16;
        const srcCount = nodes.filter((n) => n.name.startsWith('src:')).length;
        const dstCount = nodes.length - srcCount;
        const maxPerColumn = Math.max(srcCount, dstCount, 1);
        return Math.max(MIN_HEIGHT, maxPerColumn * PX_PER_NODE);
    }

    renderChart(payload) {
        if (!this.container) return;

        const nodes = payload?.nodes || [];
        const links = payload?.links || [];

        this.container.style.height = `${this.computeCanvasHeight(nodes)}px`;

        if (!this.chart) {
            // showMessage() may have left a message div behind (error/empty state) — clear it
            // before creating a fresh ECharts instance on the container.
            this.container.innerHTML = '';
            this.chart = window.echarts.init(this.container);
            // Defensive: if the container's layout hadn't fully settled yet (e.g. right
            // after the results card switched from display:none to visible), ECharts
            // may have measured a stale/undersized width at init time. Re-measure once
            // on the next frame so the diagram isn't permanently stuck at that size.
            requestAnimationFrame(() => this.chart?.resize());
        } else {
            // Container height may have just changed above (new payload, different node
            // count) — re-measure so ECharts doesn't keep rendering at the old size.
            this.chart.resize();
        }

        const theme = this.getThemeColors();
        const formatBytes = this.formatBytes.bind(this);
        const formatNumber = this.formatNumber.bind(this);

        this.chart.setOption(
            {
                backgroundColor: 'transparent',
                tooltip: {
                    trigger: 'item',
                    triggerOn: 'mousemove',
                    backgroundColor: theme.tooltipBg,
                    borderColor: theme.tooltipBorder,
                    textStyle: { color: theme.textColor },
                    formatter: (params) => {
                        if (params.dataType === 'edge') {
                            const src = params.data.source.replace(/^src:/, '');
                            const dst = params.data.target.replace(/^dst:/, '');
                            return (
                                `${src} &rarr; ${dst}<br>` +
                                `Volume: <b>${formatBytes(params.data.value)}</b><br>` +
                                `Flows: <b>${formatNumber(params.data.flows || 0)}</b>`
                            );
                        }
                        const label = params.data?.label || params.name;
                        return label;
                    },
                },
                series: [
                    {
                        type: 'sankey',
                        // Reserve margin on both sides for the outside-positioned src/dst
                        // labels — without this, nodes sitting exactly at the container's
                        // left/right edge have their labels clipped by the canvas boundary
                        // (observed: labels reduced to just 1-2 visible characters).
                        left: 130,
                        right: 130,
                        emphasis: { focus: 'adjacency' },
                        nodeGap: 10,
                        lineStyle: {
                            color: 'gradient',
                            curveness: 0.5,
                            opacity: 0.4,
                        },
                        label: {
                            color: theme.textColor,
                        },
                        data: nodes.map((n) => ({
                            name: n.name,
                            label: {
                                formatter: n.label,
                                // Source-column nodes sit at the left edge — their default
                                // 'right' label position would point inward, overlapping the
                                // ribbons and the destination column. Point outward instead.
                                position: n.name.startsWith('src:') ? 'left' : 'right',
                            },
                        })),
                        links: links.map((l) => ({
                            source: l.source,
                            target: l.target,
                            value: l.value,
                            flows: l.flows,
                        })),
                    },
                ],
            },
            true
        );
    }

    // Method to update chart data (can be called from Datastar)
    static get observedAttributes() {
        return ['data-sankey-data'];
    }

    attributeChangedCallback(_name, oldValue, newValue) {
        if (oldValue !== newValue && this.isConnected && newValue !== null) {
            this.initializeChart();
        }
    }

    resize() {
        if (this.chart) {
            this.chart.resize();
        }
    }

    destroy() {
        if (this.chart) {
            this.chart.dispose();
            this.chart = null;
        }
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-sankey', NfsenSankey);
