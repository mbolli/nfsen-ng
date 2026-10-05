/**
 * <nfsen-matrix data-conversation> (4.4.4; ROCKET-SPEC 6.6, shape A): top sources against top destinations (D25).
 * A cell whose pair is not among the ranked pairs is hatched: its traffic is unknown, not zero.
 */
import { rocket } from 'datastar';
import { downloadUrl } from 'nfsen/download';
import { escapeHtml, fitLabel, formatMetric, labelWidth } from 'nfsen/format';
import { hostState, peekState, whenGone } from 'nfsen/host-state';
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

/** "64%", or one decimal below 10 %, as ConversationActions::percent(). */
function formatShare(share) {
    const pct = share * 100;
    return `${pct.toFixed(pct > 0 && pct < 10 ? 1 : 0)}%`;
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
    const both = payload.meta?.direction === 'both';
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
            // The heatmap skips a cell without a value, so the hatched ones carry a 0. In Both,
            // B -> A is part of the merged A <-> B cell: known, so left blank rather than hatched.
            if (value !== undefined) data.push([x, y, value, total > 0 ? value / total : null]);
            else if (!both || !cells.has(`${dst}\u0000${src}`)) missing.push([x, y, 0]);
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

/** The payload the host holds, parsed again only when the attribute changed. */
function payloadOf(host, state) {
    if (state.text !== host.dataConversation) {
        state.text = host.dataConversation;
        state.payload = parsePayload(state.text);
    }
    return state.payload;
}

function release(state) {
    state.chart?.dispose();
    state.chart = null;
}

function note(state, canvas, text) {
    release(state);
    state.drawn = null;
    const p = document.createElement('p');
    p.className = 'conv-chart-message';
    p.textContent = text;
    canvas.replaceChildren(p);
}

/** One redraw per burst of prop changes, after the morph that made them (K15). */
function schedule(host) {
    const state = peekState(host);
    if (!state || state.queued) return;
    state.queued = true;
    queueMicrotask(() => {
        state.queued = false;
        draw(host);
    });
}

/** A full draw, not chart.resize(), since a theme change while hidden was skipped; `force` redraws what is drawn. */
function draw(host, force = false) {
    const state = peekState(host);
    const canvas = host.querySelector('.matrix-canvas');
    if (!state || !canvas || !host.isConnected) return;
    const payload = payloadOf(host, state);
    if (!payload?.pairs.length) {
        note(state, canvas, 'No conversations in this result.');
        return;
    }
    if (host.clientWidth === 0) return;
    if (!window.echarts) {
        clearTimeout(state.retry);
        state.retry = setTimeout(() => draw(host, force), 100);
        return;
    }
    const theme = chartTheme();
    const width = canvas.clientWidth || host.clientWidth;
    const drawn = state.drawn;
    if (!force && state.chart && drawn?.text === state.text && drawn.theme === theme && drawn.width === width) return;

    const grid = matrixGrid(payload);
    const format = (v) => formatMetric(v, grid.metric);
    const pieces = rampPieces(grid.min, grid.max, sequentialRamp(6), format);
    const layout = matrixLayout(grid, pieces, width);
    canvas.style.height = `${Math.max(MIN_HEIGHT, GRID_TOP + grid.sources.length * CELL_PX + layout.bottom)}px`;

    if (state.chart) {
        state.chart.resize();
    } else {
        canvas.replaceChildren();
        state.chart = window.echarts.init(canvas);
    }
    state.chart.setOption(option(payload, grid, pieces, layout, theme), true);
    state.drawn = { text: state.text, theme, width };
}

function option(payload, grid, pieces, layout, theme) {
    const { metric } = grid;
    const topN = payload.meta?.topN ?? payload.pairs.length;
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

function downloadPng(host) {
    const chart = peekState(host)?.chart;
    if (!chart) return;
    downloadUrl(chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: chartTheme().surface }), 'conversations-matrix.png');
}

rocket('nfsen-matrix', {
    mode: 'open',
    props: ({ number, string }) => ({
        dataConversation: string.docs({ description: 'The result as ConversationPayload JSON: ranked pairs, Others, totals and meta.' }),
        dataSeconds: number.docs({ description: 'The length of the result window in seconds, as nfsen-sankey takes it.' }),
        dataUnit: string.docs({ description: 'bits or bytes, as nfsen-sankey takes it; the grid shows totals, not rates.' }),
    }),
    manifest: { events: [] },
    setup: ({ cleanup, defineHostProp, host, observeProps }) => {
        if (!host.shadowRoot.firstChild) host.shadowRoot.append(document.createElement('slot'));
        const state = hostState(host, () => ({ chart: null, text: null, payload: null, drawn: null, frame: 0, retry: 0, queued: false }));

        defineHostProp('chart', { get: () => peekState(host)?.chart ?? null });
        defineHostProp('resize', { value: () => draw(host, true) });
        defineHostProp('downloadPng', { value: () => downloadPng(host) });
        defineHostProp('toJSON', { value: () => host.tagName });

        observeProps(() => schedule(host), 'dataConversation');
        // Also sees the view become visible: a chart drawn while hidden has no size.
        const resizes = new ResizeObserver(() => {
            cancelAnimationFrame(state.frame);
            state.frame = requestAnimationFrame(() => draw(host));
        });
        resizes.observe(host);
        const stopTheme = onThemeChange(() => draw(host));
        schedule(host);

        cleanup(() => {
            resizes.disconnect();
            stopTheme();
            cancelAnimationFrame(state.frame);
            clearTimeout(state.retry);
            whenGone(host, release);
        });
    },
});
