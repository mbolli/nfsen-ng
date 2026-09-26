/**
 * Conversations Matrix (4.4.4): a heat grid of the top sources (rows) against the top
 * destinations (columns) of one result, on the neutral ramp (D25). A cell whose pair is not
 * among the ranked pairs is hatched: its traffic is unknown, not zero.
 */
import { chartTheme, onThemeChange, sequentialRamp } from 'nfsen/theme-colors';

/** Rows and columns shown at most, the busiest first. */
const AXIS_MAX = 25;
const CELL_PX = 26;
const MIN_HEIGHT = 320;
const GRID_TOP = 8;
const LABEL_FONT_SIZE = 11;
/** ECharts' gap between an axis and its labels. */
const LABEL_MARGIN = 8;
const X_ROTATE = 35;
const X_LABEL_MAX = 130;
const NAME_BAND = 18;
/** The legend: swatch, gap to its text, gap to the next step, and the height of a row. */
const SWATCH = 14;
const TEXT_GAP = 6;
const ITEM_GAP = 12;
const LEGEND_ROW = 20;

const escapeHtml = (text) =>
    String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

function formatBytes(value) {
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    let n = Number(value) || 0;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${i === 0 ? Math.round(n) : n.toFixed(n < 10 ? 2 : 1)} ${units[i]}`;
}

function formatMetric(value, metric) {
    return metric === 'packets' ? `${Math.round(Number(value) || 0).toLocaleString('en')} packets` : formatBytes(value);
}

/** "64%", or one decimal below 10 %, as ConversationActions::percent(). */
function formatShare(share) {
    const pct = share * 100;
    return `${pct.toFixed(pct > 0 && pct < 10 ? 1 : 0)}%`;
}

let measureContext = null;

/** The width of an axis or legend label in pixels. */
function labelWidth(text) {
    measureContext ??= document.createElement('canvas').getContext('2d');
    if (!measureContext) return String(text).length * LABEL_FONT_SIZE * 0.6;
    measureContext.font = `${LABEL_FONT_SIZE}px sans-serif`;
    return measureContext.measureText(String(text)).width;
}

/** The label shortened from the middle, as nfsen-sankey.js does: hosts of one network differ at the end. */
export function fitLabel(text, width, measure = labelWidth) {
    const label = String(text);
    if (measure(label) <= width) return label;
    for (let keep = label.length - 1; keep > 1; keep--) {
        const head = Math.ceil(keep * 0.4);
        const fitted = `${label.slice(0, head)}…${label.slice(label.length - (keep - head))}`;
        if (measure(fitted) <= width) return fitted;
    }
    return '…';
}

/** "5.80 to 13.6 MiB": the unit once when both ends share it. */
export function rangeLabel(low, high) {
    const cut = low.lastIndexOf(' ');
    return cut > 0 && high.endsWith(low.slice(cut)) ? `${low.slice(0, cut)} to ${high}` : `${low} to ${high}`;
}

function parsePayload(text) {
    try {
        const payload = JSON.parse(text || 'null');
        return Array.isArray(payload?.pairs) ? payload : null;
    } catch {
        return null;
    }
}

/**
 * The grid of one result: sources and destinations ranked by their total, cells summed over
 * ports, and the cells without a ranked pair.
 */
export function matrixGrid(payload) {
    const metric = payload.meta?.metric === 'packets' ? 'packets' : 'bytes';
    const total = payload.totals?.[metric] ?? null;
    const cells = new Map();
    const bySource = new Map();
    const byDestination = new Map();

    for (const pair of payload.pairs) {
        const key = `${pair.src}\u0000${pair.dst}`;
        cells.set(key, (cells.get(key) ?? 0) + pair[metric]);
        bySource.set(pair.src, (bySource.get(pair.src) ?? 0) + pair[metric]);
        byDestination.set(pair.dst, (byDestination.get(pair.dst) ?? 0) + pair[metric]);
    }

    const top = (totals) =>
        [...totals.entries()]
            .sort((a, b) => b[1] - a[1])
            .slice(0, AXIS_MAX)
            .map(([name]) => name);
    const sources = top(bySource);
    const destinations = top(byDestination);

    const data = [];
    const missing = [];
    sources.forEach((src, y) => {
        destinations.forEach((dst, x) => {
            const value = cells.get(`${src}\u0000${dst}`);
            // The heatmap skips a cell without a value, so the hatched ones carry a 0.
            if (value === undefined) missing.push([x, y, 0]);
            else data.push([x, y, value, total > 0 ? value / total : null]);
        });
    });

    const values = data.map((d) => d[2]);
    return {
        metric,
        sources,
        destinations,
        data,
        missing,
        min: values.length ? Math.min(...values) : 0,
        max: values.length ? Math.max(...values) : 0,
    };
}

/** Six steps between the smallest and the largest cell, spaced geometrically: traffic is skewed. */
export function rampPieces(min, max, colors, format) {
    const n = colors.length;
    if (!(max > min)) return [{ min, max, color: colors[n - 1], label: format(max) }];
    const low = Math.max(min, 1);
    const bounds = Array.from({ length: n + 1 }, (_, i) => (i === 0 ? min : i === n ? max : low * (max / low) ** (i / n)));
    return colors.map((color, i) => ({
        min: bounds[i],
        max: bounds[i + 1],
        color,
        label: rangeLabel(format(bounds[i]), format(bounds[i + 1])),
    }));
}

/**
 * Bands below the grid for the rotated destination labels, the axis name and the legend, so
 * none of them overlap. Long labels are shortened from the middle; a legend too wide for one
 * row lists its steps one per row.
 */
export function matrixLayout(grid, pieces, width, measure = labelWidth) {
    const fit = (names, max) => new Map(names.map((name) => [name, fitLabel(name, max, measure)]));
    const yLabels = fit(grid.sources, Math.max(48, Math.min(200, Math.round(width * 0.28))));
    const xLabels = fit(grid.destinations, X_LABEL_MAX);
    const widest = Math.max(0, ...[...xLabels.values()].map((label) => measure(label)));
    const angle = (X_ROTATE * Math.PI) / 180;
    const xBand = LABEL_MARGIN + Math.ceil(widest * Math.sin(angle) + LABEL_FONT_SIZE * 1.4 * Math.cos(angle));
    const oneRow = pieces.reduce((sum, piece) => sum + SWATCH + TEXT_GAP + measure(piece.label), 0) + ITEM_GAP * (pieces.length - 1);
    const vertical = oneRow > width - 16;
    const legend = (vertical ? pieces.length : 1) * LEGEND_ROW;
    return { yLabels, xLabels, vertical, legend, nameGap: xBand + 4, bottom: xBand + 4 + NAME_BAND + 8 + legend };
}

/** Diagonal hatching for the cells outside the ranked pairs. */
function hatch(theme) {
    const canvas = document.createElement('canvas');
    canvas.width = 8;
    canvas.height = 8;
    const ctx = canvas.getContext('2d');
    if (!ctx) return theme.surface;
    ctx.fillStyle = theme.surface;
    ctx.fillRect(0, 0, 8, 8);
    ctx.strokeStyle = theme.axis;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(0, 8);
    ctx.lineTo(8, 0);
    ctx.moveTo(-2, 2);
    ctx.lineTo(2, -2);
    ctx.moveTo(6, 10);
    ctx.lineTo(10, 6);
    ctx.stroke();
    return { image: canvas, repeat: 'repeat' };
}

export class NfsenMatrix extends HTMLElement {
    static get observedAttributes() {
        return ['data-conversation'];
    }

    connectedCallback() {
        this.canvas = this.querySelector('.matrix-canvas');
        this.payload = parsePayload(this.dataset.conversation);
        // Also sees the view become visible. A full render, not chart.resize(): a theme change
        // while hidden was skipped, since a chart without a size is not drawn.
        this.resizeObserver = new ResizeObserver(() => {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.render());
        });
        this.resizeObserver.observe(this);
        this.stopTheme = onThemeChange(() => this.render());
        this.render();
    }

    disconnectedCallback() {
        cancelAnimationFrame(this.frame);
        this.resizeObserver?.disconnect();
        this.stopTheme?.();
        this.chart?.dispose();
        this.chart = null;
    }

    attributeChangedCallback(_name, oldValue, newValue) {
        if (oldValue === newValue || !this.isConnected) return;
        this.payload = parsePayload(newValue);
        this.render();
    }

    resize() {
        this.render();
    }

    downloadPng() {
        if (!this.chart) return;
        const href = this.chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: chartTheme().surface });
        const a = document.createElement('a');
        a.href = href;
        a.download = 'conversations-matrix.png';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    message(text) {
        this.chart?.dispose();
        this.chart = null;
        const p = document.createElement('p');
        p.className = 'conv-chart-message';
        p.textContent = text;
        this.canvas.replaceChildren(p);
    }

    render() {
        if (!this.canvas) return;
        if (!this.payload?.pairs.length) {
            this.message('No conversations in this result.');
            return;
        }
        if (this.clientWidth === 0) return;
        if (!window.echarts) {
            setTimeout(() => this.render(), 100);
            return;
        }

        const grid = matrixGrid(this.payload);
        this.grid = grid;
        const format = (v) => formatMetric(v, grid.metric);
        const pieces = rampPieces(grid.min, grid.max, sequentialRamp(6), format);
        const layout = matrixLayout(grid, pieces, this.canvas.clientWidth || this.clientWidth);
        this.canvas.style.height = `${Math.max(MIN_HEIGHT, GRID_TOP + grid.sources.length * CELL_PX + layout.bottom)}px`;

        if (!this.chart) {
            this.canvas.replaceChildren();
            this.chart = window.echarts.init(this.canvas);
        } else {
            this.chart.resize();
        }
        this.chart.setOption(this.option(grid, pieces, layout), true);
    }

    option(grid, pieces, layout) {
        const theme = chartTheme();
        const { metric } = grid;
        const topN = this.payload.meta?.topN ?? this.payload.pairs.length;
        const format = (v) => formatMetric(v, metric);
        const axis = {
            type: 'category',
            axisLine: { lineStyle: { color: theme.axis } },
            axisTick: { show: false },
            splitArea: { show: false },
        };

        return {
            backgroundColor: 'transparent',
            animation: false,
            grid: {
                top: GRID_TOP,
                right: 16,
                bottom: layout.bottom,
                left: 8,
                // Labels that need more room shrink the grid, never run into the legend.
                outerBounds: { top: 0, right: 0, bottom: layout.legend + 8, left: 0 },
            },
            tooltip: {
                backgroundColor: theme.tooltipBg,
                borderColor: theme.tooltipBorder,
                textStyle: { color: theme.text },
                formatter: (params) => {
                    const [x, y, value, share] = params.data;
                    const pair = `${escapeHtml(grid.sources[y])} → ${escapeHtml(grid.destinations[x])}`;
                    if (params.seriesIndex === 1) return `${pair}<br>not in top ${escapeHtml(topN)}`;
                    const of = share === null ? '' : ` (${formatShare(share)} of ${metric})`;
                    return `${pair}<br><b>${escapeHtml(format(value))}</b>${of}`;
                },
            },
            xAxis: {
                ...axis,
                data: grid.destinations,
                name: 'Destination',
                nameLocation: 'middle',
                nameGap: layout.nameGap,
                nameTextStyle: { color: theme.text, fontSize: LABEL_FONT_SIZE },
                axisLabel: {
                    color: theme.text,
                    fontSize: LABEL_FONT_SIZE,
                    margin: LABEL_MARGIN,
                    interval: 0,
                    rotate: X_ROTATE,
                    formatter: (name) => layout.xLabels.get(name) ?? name,
                },
            },
            yAxis: {
                ...axis,
                data: grid.sources,
                inverse: true,
                axisLabel: {
                    color: theme.text,
                    fontSize: LABEL_FONT_SIZE,
                    margin: LABEL_MARGIN,
                    interval: 0,
                    formatter: (name) => layout.yLabels.get(name) ?? name,
                },
            },
            visualMap: {
                type: 'piecewise',
                seriesIndex: 0,
                dimension: 2,
                orient: layout.vertical ? 'vertical' : 'horizontal',
                left: layout.vertical ? 8 : 'center',
                bottom: 0,
                padding: 0,
                itemWidth: SWATCH,
                itemHeight: 10,
                itemGap: layout.vertical ? LEGEND_ROW - 10 : ITEM_GAP,
                textGap: TEXT_GAP,
                textStyle: { color: theme.text, fontSize: LABEL_FONT_SIZE },
                pieces,
            },
            series: [
                {
                    type: 'heatmap',
                    data: grid.data,
                    itemStyle: { borderColor: theme.surface, borderWidth: 1 },
                    emphasis: { itemStyle: { borderColor: theme.text, borderWidth: 1 } },
                },
                {
                    type: 'heatmap',
                    data: grid.missing,
                    itemStyle: { color: hatch(theme), borderColor: theme.surface, borderWidth: 1 },
                    emphasis: { disabled: true },
                },
            ],
        };
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-matrix', NfsenMatrix);
