/**
 * <nfsen-chart>: the traffic graph on Apache ECharts (spec 1.8, 4.1.3).
 *
 * Attributes: data-chart-data (GraphData JSON), data-chart-config (the server's part of the
 * configuration), data-chart-style (the client-local style toggles), data-mode
 * (overview | picker | picker-total), data-aria-base (the first part of the accessible name),
 * data-external-prefix (ids of the Series and Legend panels, Overview only).
 *
 * Events, all bubbling: range-select {from, to} after a brush (ms, on 5 minute boundaries),
 * graph-zoom {from, to} after a Ctrl + wheel zoom and after new data is drawn (ms; isZoomed()
 * says whether the view is a preview), brush-armed {armed}.
 */
import { chartTheme, linePattern, onThemeChange } from 'nfsen/theme-colors';
import { tzOptions } from 'nfsen/tz-utils';

/** Series slots with their own colour (2.3); stacked series past them sum into Others. */
const SLOTS = 8;
/** Line series past this share the neutral colour. */
const LINE_SLOTS = 24;
/** Rows in the tooltip and the legend at the cursor, largest first. */
const MAX_ROWS = 12;
/** A brushed range snaps to capture intervals. */
const INTERVAL_MS = 300_000;
/** A drag narrower than this is a click, which does nothing. */
const MIN_BRUSH_PX = 4;
const HINT_MS = 2000;
const GAP_TEXT = 'no data';

const UNITS = {
    bits: {
        base: 1000,
        axis: ['', ' k', ' M', ' G', ' T', ' P'],
        rate: [' b/s', ' kb/s', ' Mb/s', ' Gb/s', ' Tb/s', ' Pb/s'],
        name: 'bits/s',
    },
    bytes: {
        base: 1024,
        axis: ['', ' Ki', ' Mi', ' Gi', ' Ti', ' Pi'],
        rate: [' B/s', ' KiB/s', ' MiB/s', ' GiB/s', ' TiB/s', ' PiB/s'],
        name: 'bytes/s',
    },
    packets: {
        base: 1000,
        axis: ['', ' k', ' M', ' G', ' T', ' P'],
        rate: [' pkt/s', ' k pkt/s', ' M pkt/s', ' G pkt/s', ' T pkt/s', ' P pkt/s'],
        name: 'packets/s',
    },
    flows: {
        base: 1000,
        axis: ['', ' k', ' M', ' G', ' T', ' P'],
        rate: [' flows/s', ' k flows/s', ' M flows/s', ' G flows/s', ' T flows/s', ' P flows/s'],
        name: 'flows/s',
    },
};

/**
 * True for anything that cannot be drawn or formatted as a number: the null the datasources
 * use for an empty bucket (#154), and NaN, Infinity or a non-number. The formatters run inside
 * ECharts' hover handling, out of reach of the update path's error handling (#160).
 */
function isGap(value) {
    return value == null || value === '' || !Number.isFinite(Number(value));
}

/** Three significant digits and the prefix that keeps the number below the base. */
function scaled(value, base, units) {
    if (isGap(value)) return GAP_TEXT;
    let v = Math.abs(Number(value));
    let i = 0;
    while (v >= base && i < units.length - 1) {
        v /= base;
        i++;
    }
    const digits = v === 0 || v >= 100 ? 0 : v >= 10 ? 1 : 2;
    return `${Number(value) < 0 ? '-' : ''}${Number(v.toFixed(digits))}${units[i]}`;
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

function parseJson(text, fallback) {
    if (!text) return fallback;
    try {
        return JSON.parse(text);
    } catch {
        return fallback;
    }
}

/** A view transition scoped to the element where the browser has them, with its promises handled. */
function transition(el, fn) {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (typeof el.startViewTransition !== 'function' || reduced || !el.checkVisibility?.()) {
        fn();
        return;
    }
    try {
        const t = el.startViewTransition(fn);
        // A skipped or aborted transition still ran fn; only the animation is lost.
        t.ready?.catch(() => {});
        t.finished?.catch(() => {});
        t.updateCallbackDone?.catch(() => {});
    } catch {
        fn();
    }
}

export class NfsenChart extends HTMLElement {
    static get observedAttributes() {
        return ['data-chart-data', 'data-chart-config', 'data-chart-style', 'data-mode', 'data-aria-base'];
    }

    constructor() {
        super();
        this.chart = null;
        this.container = null;
        this.rows = [];
        this.names = [];
        this.slots = [];
        this.config = {};
        this.chartStyle = { logscale: false, stacked: false, stepplot: true };
        this.hiddenSeries = new Set();
        this.drawnData = null;
        this.panelKey = '';
        this.seriesKey = '';
        this.brushArmed = false;
        this.brushOnce = false;
        this.coarse = window.matchMedia('(pointer: coarse)');
        this.scheduled = false;
        this.hintTimer = 0;
        this.dateFmt = (ms, extra = {}) => new Date(ms).toLocaleString(undefined, extra);
    }

    connectedCallback() {
        this.container = this.querySelector('.chart-canvas');
        this.hint = this.querySelector('.chart-hint');

        // The inner container can still be settling when the element already has its size.
        this.resizeObserver = new ResizeObserver(() => this.chart?.resize());
        this.resizeObserver.observe(this);
        if (this.container) this.resizeObserver.observe(this.container);

        this.unsubscribeTheme = onThemeChange(() => this.chart && this.render({ keepZoom: true }));
        this.onPointerChange = () => this.syncBrush();
        this.coarse.addEventListener('change', this.onPointerChange);
        // A plain wheel scrolls the page; only Ctrl + wheel zooms (D6), which the hint says. The
        // inside dataZoom cancels every wheel it receives, so a plain one never reaches it.
        this.onWheel = (event) => {
            if (!this.chart || event.ctrlKey || event.metaKey || !this.container?.contains(event.target)) return;
            event.stopPropagation();
            this.showHint();
        };
        this.addEventListener('wheel', this.onWheel, { passive: true, capture: true });

        this.schedule();
    }

    disconnectedCallback() {
        this.resizeObserver?.disconnect();
        this.resizeObserver = null;
        this.unsubscribeTheme?.();
        this.unsubscribeTheme = null;
        this.coarse.removeEventListener('change', this.onPointerChange);
        this.removeEventListener('wheel', this.onWheel, { capture: true });
        clearTimeout(this.hintTimer);
        this.destroy();
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue === newValue || !this.isConnected) return;
        if (name === 'data-chart-style') {
            this.applyStyle();
            return;
        }
        if (name === 'data-aria-base') {
            this.updateAria();
            return;
        }
        // An emptied or removed data attribute keeps the last chart (1.2).
        if (name === 'data-chart-data' && !newValue) return;
        this.schedule();
    }

    /** A morph changes several attributes in one task; draw once, after all of them. */
    schedule() {
        if (this.scheduled) return;
        this.scheduled = true;
        queueMicrotask(() => {
            this.scheduled = false;
            this.load();
        });
    }

    async load() {
        if (!this.container) {
            this.container = this.querySelector('.chart-canvas');
            if (!this.container) return;
        }
        if (!window.echarts && !(await this.waitForECharts())) {
            this.showMessage('The chart library failed to load.');
            return;
        }

        const dataText = this.dataset.chartData;
        if (!dataText) return;
        const config = parseJson(this.dataset.chartConfig, {});
        const key = `${this.dataset.mode}|${this.dataset.chartConfig}|${dataText}`;
        if (key === this.drawnData && this.chart) return;

        const data = parseJson(dataText, null);
        if (data === null) {
            this.showMessage('The chart data could not be read.');
            this.announceView();
            return;
        }
        this.chartStyle = { ...this.chartStyle, ...parseJson(this.dataset.chartStyle, {}) };
        this.drawnData = key;
        const zoom = this.chart && this.isZoomed() ? this.getCurrentRange() : null;
        const sameWindow = (config.window ?? '') === (this.config.window ?? '');
        const seriesKey = this.seriesKey;
        this.setData(data, config);
        this.render({ zoom: sameWindow && seriesKey === this.seriesKey ? this.zoomInside(zoom) : null, announce: true });
    }

    /** A zoom that still lies inside the drawn data, so a refresh of the same window keeps the preview (D6). */
    zoomInside(zoom) {
        if (!zoom || this.rows.length === 0) return null;
        return zoom.from >= this.rows[0][0] && zoom.to <= this.rows[this.rows.length - 1][0] ? zoom : null;
    }

    async waitForECharts(timeout = 5000) {
        const start = Date.now();
        while (!window.echarts) {
            if (Date.now() - start > timeout) return false;
            await new Promise((resolve) => setTimeout(resolve, 50));
        }
        return true;
    }

    /** GraphData ({data: {ts: [values]}, legend}) as rows of [ms, ...values], plus names and slots. */
    setData(data, config) {
        this.config = config ?? {};
        const legend = Array.isArray(data?.legend) ? data.legend.map(String) : [];
        const rows = data && typeof data.data === 'object' && data.data !== null ? Object.entries(data.data) : [];
        this.rows = rows
            .map(([ts, values]) => [Number(ts) * 1000, ...(Array.isArray(values) ? values : [])])
            .filter((row) => Number.isFinite(row[0]))
            .sort((a, b) => a[0] - b[0]);

        const names =
            Array.isArray(this.config.seriesNames) && this.config.seriesNames.length === legend.length ? this.config.seriesNames : legend;
        this.names = names.map(String);
        const slots =
            Array.isArray(this.config.seriesSlots) && this.config.seriesSlots.length === legend.length ? this.config.seriesSlots : null;
        this.slots = this.names.map((_, i) => (slots ? Number(slots[i]) || 0 : i + 1));

        // A new set of series starts all visible; the same set keeps what was hidden.
        const seriesKey = `${this.mode()}|${this.names.join('\u001f')}`;
        if (seriesKey !== this.seriesKey) this.hiddenSeries.clear();
        this.seriesKey = seriesKey;

        const opts = tzOptions(this.config.displayTz || 'browser', this.config.nfcapdTz || 'UTC');
        this.dateFmt = (ms, extra = {}) => new Date(ms).toLocaleString(undefined, { ...opts, ...extra });
    }

    mode() {
        return this.dataset.mode || this.config.mode || 'overview';
    }

    unit() {
        return UNITS[this.config.unit] ?? UNITS.bits;
    }

    isStacked() {
        return this.mode() !== 'overview' || this.chartStyle.stacked === true;
    }

    /**
     * How each series is drawn (2.3): its colour and pattern from its fixed position, and in a
     * stacked chart whether it is summed into Others.
     * @returns {{name: string, color: string, slot: string, pattern: string, width: number, others: boolean}[]}
     */
    seriesPlan(theme) {
        const stacked = this.isStacked();
        const pickerTotal = this.mode() === 'picker-total';
        return this.names.map((name, i) => {
            const pos = this.slots[i] - 1;
            if (pickerTotal || pos < 0) {
                return { name, color: theme.others, slot: 'others', pattern: 'solid', width: 1.5, others: false };
            }
            if (stacked) {
                return pos < SLOTS
                    ? { name, color: theme.series[pos], slot: String(pos + 1), pattern: 'solid', width: 1.5, others: false }
                    : { name, color: theme.others, slot: 'others', pattern: 'solid', width: 1.5, others: true };
            }
            if (pos >= LINE_SLOTS) {
                return { name, color: theme.others, slot: 'others', pattern: 'solid', width: 1, others: false };
            }
            return {
                name,
                color: theme.series[pos % SLOTS],
                slot: String((pos % SLOTS) + 1),
                pattern: linePattern(pos),
                width: 1.5,
                others: false,
            };
        });
    }

    /** Rows with a trailing Others column: the sum of its visible members, a gap when all are. */
    sourceRows(plan) {
        const members = plan.map((p, i) => (p.others && !this.hiddenSeries.has(p.name) ? i : -1)).filter((i) => i >= 0);
        if (!plan.some((p) => p.others)) return this.rows;
        return this.rows.map((row) => {
            let sum = null;
            for (const i of members) {
                const v = row[i + 1];
                if (!isGap(v)) sum = (sum ?? 0) + Number(v);
            }
            return [...row, sum];
        });
    }

    /**
     * Draws the current data and style. keepZoom holds the view across a style or theme change,
     * zoom restores a given one; announce reports the resulting view (graph-zoom) after new data.
     */
    render({ keepZoom = false, zoom: restore = null, announce = false }) {
        if (!this.container) return;
        if (this.rows.length === 0 || this.names.length === 0) {
            this.showMessage('No data available for the selected range.');
            this.updateAria();
            if (announce) this.announceView();
            return;
        }

        const zoom = restore ?? (keepZoom && this.isZoomed() ? this.getCurrentRange() : null);
        const option = this.buildOption();
        const apply = () => {
            if (!this.chart) return;
            try {
                this.chart.setOption(option, { notMerge: true });
                if (zoom) this.chart.dispatchAction({ type: 'dataZoom', startValue: zoom.from, endValue: zoom.to });
                this.afterRender();
                if (announce) this.announceView();
            } catch (e) {
                // Deferred by the transition, so past load()'s handling: without disposing, the
                // instance stays broken and every later update fails the same way (#160).
                console.error('Error updating chart:', e);
                this.showMessage(`The chart could not be drawn: ${e.message}`);
            }
        };

        if (this.chart) {
            transition(this.container, apply);
            return;
        }

        try {
            // A message may be left in the container; ECharts needs it empty.
            this.container.replaceChildren();
            this.chart = window.echarts.init(this.container);
            // The container may not have settled when ECharts measured it.
            requestAnimationFrame(() => this.chart?.resize());
            this.chart.on('datazoom', () => this.handleZoom());
            this.chart.on('brushEnd', (params) => this.handleBrush(params));
            // The picker's built-in legend: remembered like the Series panel, so a redraw keeps it.
            this.chart.on('legendselectchanged', (params) => this.handleLegendSelect(params));
            this.chart.on('updateAxisPointer', (params) => this.updateCursorLegend(params));
            apply();
        } catch (e) {
            console.error('Error creating chart:', e);
            this.showMessage(`The chart could not be drawn: ${e.message}`);
        }
    }

    afterRender() {
        this.syncBrush();
        const panelKey = `${this.mode()}|${this.names.join('\u001f')}|${this.isStacked()}`;
        if (panelKey !== this.panelKey) {
            this.panelKey = panelKey;
            this.populateSeriesPanel();
        }
        this.updateAria();
    }

    buildOption() {
        const theme = chartTheme();
        const plan = this.seriesPlan(theme);
        const unit = this.unit();
        const mode = this.mode();
        const stacked = this.isStacked();
        const picker = mode !== 'overview';
        const rate = (v) => scaled(v, unit.base, unit.rate);
        // Past four coloured series, line charts name their lines at the right edge (2.3).
        const endLabels = !stacked && plan.length > 4;
        const hasOthers = plan.some((p) => p.others);
        const selected = Object.fromEntries(plan.map((p) => [p.name, !this.hiddenSeries.has(p.name)]));

        const series = plan
            .map((p, i) => ({ p, i }))
            .filter(({ p }) => !p.others)
            .map(({ p, i }, n) => ({
                name: p.name,
                type: 'line',
                showSymbol: false,
                step: this.chartStyle.stepplot !== false ? 'end' : false,
                smooth: false,
                stack: stacked ? 'total' : undefined,
                areaStyle: stacked ? { opacity: picker ? 0.85 : 0.6 } : undefined,
                color: p.color,
                lineStyle: { width: p.width, type: p.pattern },
                // Hovering lifts a line and fades the rest: with many series the colour
                // alone cannot say which one is which.
                emphasis: { focus: 'series' },
                endLabel: endLabels && n < SLOTS ? { show: true, formatter: '{a}', color: theme.text, fontSize: 11 } : undefined,
                labelLayout: endLabels ? { moveOverlap: 'shiftY' } : undefined,
                encode: { x: 0, y: i + 1 },
            }));
        if (hasOthers) {
            series.push({
                name: 'Others',
                type: 'line',
                showSymbol: false,
                step: this.chartStyle.stepplot !== false ? 'end' : false,
                stack: 'total',
                areaStyle: { opacity: 0.6 },
                color: theme.others,
                lineStyle: { width: 1 },
                emphasis: { focus: 'series' },
                encode: { x: 0, y: this.names.length + 1 },
            });
        }

        return {
            backgroundColor: 'transparent',
            animation: false,
            aria: { enabled: true },
            textStyle: { color: theme.text },
            grid: {
                left: 8,
                right: endLabels ? 96 : 16,
                top: picker ? 24 : 28,
                bottom: picker ? 40 : 8,
                containLabel: true,
            },
            legend: picker
                ? {
                      show: true,
                      bottom: 0,
                      left: 'center',
                      icon: 'roundRect',
                      itemWidth: 12,
                      itemHeight: 12,
                      textStyle: { color: theme.text },
                      selected,
                  }
                : { show: false, selected },
            tooltip: {
                trigger: 'axis',
                backgroundColor: theme.tooltipBg,
                borderColor: theme.tooltipBorder,
                textStyle: { color: theme.text },
                axisPointer: { type: 'line', lineStyle: { color: theme.axis } },
                formatter: (params) => this.tooltipRows(params, rate),
            },
            xAxis: {
                type: 'time',
                axisLine: { lineStyle: { color: theme.axis } },
                axisTick: { lineStyle: { color: theme.axis } },
                splitLine: { show: false },
                axisLabel: { color: theme.text, hideOverlap: true, formatter: (ms) => this.axisTime(ms) },
            },
            yAxis: {
                type: this.chartStyle.logscale ? 'log' : 'value',
                name: unit.name,
                nameTextStyle: { color: theme.text, align: 'left' },
                axisLine: { show: false },
                splitLine: { lineStyle: { color: theme.grid } },
                axisLabel: { color: theme.text, formatter: (v) => scaled(v, unit.base, unit.axis) },
            },
            // Ctrl + wheel zooms a local preview; a plain wheel and any drag stay with the page
            // and the brush (D6). The slider is gone.
            dataZoom: [
                {
                    type: 'inside',
                    xAxisIndex: 0,
                    zoomOnMouseWheel: 'ctrl',
                    moveOnMouseMove: false,
                    moveOnMouseWheel: false,
                    preventDefaultMouseMove: false,
                },
            ],
            brush: {
                xAxisIndex: 0,
                brushType: 'lineX',
                brushMode: 'single',
                throttleType: 'debounce',
                transformable: false,
                brushStyle: { color: theme.brush, borderColor: theme.brushBorder, borderWidth: 1 },
                outOfBrush: { colorAlpha: 1 },
            },
            toolbox: { show: false },
            dataset: { source: this.sourceRows(plan) },
            series,
        };
    }

    /** Axis times in the display timezone, as detailed as the window needs. */
    axisTime(ms) {
        const span = this.rows.length > 1 ? this.rows[this.rows.length - 1][0] - this.rows[0][0] : 0;
        if (span <= 36 * 3600_000) return this.dateFmt(ms, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
        if (span <= 10 * 86400_000) {
            return this.dateFmt(ms, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
        }
        return this.dateFmt(ms, { month: 'short', day: 'numeric' });
    }

    /** The largest series at the hovered time, biggest first and capped. */
    tooltipRows(params, rate) {
        const entries = (Array.isArray(params) ? params : [params]).filter(Boolean);
        if (entries.length === 0) return '';
        const pointValue = (p) => (Array.isArray(p.value) ? p.value[p.encode?.y?.[0] ?? 1] : p.value);
        const ranked = entries
            .map((p) => ({ marker: p.marker, name: p.seriesName, value: pointValue(p) }))
            .filter((row) => !isGap(row.value))
            .sort((a, b) => b.value - a.value);
        const time = entries[0].axisValue;
        const head = `<div>${escapeHtml(this.dateFmt(time, { dateStyle: 'medium', timeStyle: 'short' }))}</div>`;
        const rows = ranked
            .slice(0, MAX_ROWS)
            .map((row) => `<div>${row.marker}${escapeHtml(row.name)}: <b>${escapeHtml(rate(row.value))}</b></div>`)
            .join('');
        const hidden = ranked.length - Math.min(ranked.length, MAX_ROWS);
        return head + rows + (hidden > 0 ? `<div>+${hidden} more</div>` : '');
    }

    /** One of this chart's external panels (Series, Legend), scoped by data-external-prefix. */
    externalEl(name) {
        const prefix = this.getAttribute('data-external-prefix');
        return document.getElementById(prefix ? `${prefix}-${name}` : name);
    }

    /**
     * The Series panel lists and toggles every series, Others members included (2.3). Change
     * listeners are bound here: Datastar skips the panel ([data-ignore]).
     */
    populateSeriesPanel() {
        const panel = this.externalEl('series');
        if (!panel || this.mode() !== 'overview') return;
        const plan = this.seriesPlan(chartTheme());
        const stacked = this.isStacked();
        panel.replaceChildren();

        const note = stacked
            ? plan.some((p) => p.others) && 'Series 9 and later are summed into Others; the legend at the cursor lists them.'
            : plan.length > LINE_SLOTS && 'Series 25 and later share the neutral colour; use the list to isolate one.';
        if (note) {
            const p = document.createElement('p');
            p.className = 'series-note';
            p.textContent = note;
            panel.append(p);
        }

        const list = document.createElement('ul');
        list.className = 'series-items';
        plan.forEach((p, index) => {
            const item = document.createElement('li');
            const label = document.createElement('label');
            label.className = 'series-item';
            label.title = p.name;
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = !this.hiddenSeries.has(p.name);
            box.addEventListener('change', () => this.setVisibility(index, box.checked));
            const swatch = document.createElement('span');
            swatch.className = 'series-swatch';
            swatch.dataset.series = p.slot;
            if (!stacked) swatch.dataset.pattern = p.pattern;
            swatch.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span');
            name.className = 'series-name';
            name.textContent = p.name;
            label.append(box, swatch, name);
            item.append(label);
            list.append(item);
        });
        panel.append(list);
    }

    /** The Legend panel: the values at the hovered time, largest first, at most MAX_ROWS. */
    updateCursorLegend(params) {
        const panel = this.externalEl('legend');
        if (!panel || !this.chart || this.mode() !== 'overview') return;
        const index = params?.dataIndex ?? params?.axesInfo?.[0]?.value;
        const row = typeof index === 'number' && this.rows[index] ? this.rows[index] : this.rowAt(params?.axesInfo?.[0]?.value);
        if (!row) return;

        const plan = this.seriesPlan(chartTheme());
        const unit = this.unit();
        const ranked = plan
            .map((p, i) => ({ p, value: row[i + 1] }))
            .filter(({ p, value }) => !this.hiddenSeries.has(p.name) && !isGap(value))
            .sort((a, b) => b.value - a.value);

        const time = document.createElement('p');
        time.className = 'cursor-legend-time';
        time.textContent = this.dateFmt(row[0], { dateStyle: 'medium', timeStyle: 'short' });
        const list = document.createElement('ol');
        list.className = 'cursor-legend-rows';
        for (const { p, value } of ranked.slice(0, MAX_ROWS)) {
            const item = document.createElement('li');
            const swatch = document.createElement('span');
            swatch.className = 'series-swatch';
            swatch.dataset.series = p.slot;
            swatch.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span');
            name.textContent = p.others ? `${p.name} (Others)` : p.name;
            const figure = document.createElement('b');
            figure.textContent = scaled(value, unit.base, unit.rate);
            item.append(swatch, name, figure);
            list.append(item);
        }
        panel.replaceChildren(time, list);
        if (ranked.length > MAX_ROWS) {
            const more = document.createElement('p');
            more.className = 'cursor-legend-more';
            more.textContent = `+${ranked.length - MAX_ROWS} more`;
            panel.append(more);
        }
    }

    rowAt(ms) {
        if (!Number.isFinite(ms) || this.rows.length === 0) return null;
        let best = this.rows[0];
        for (const row of this.rows) {
            if (Math.abs(row[0] - ms) < Math.abs(best[0] - ms)) best = row;
        }
        return best;
    }

    /** "Traffic by protocol, Stored data · 5 min resolution, Sep 24 14:20 to Sep 25 14:20, peak 3.1 Gb/s at 08:50" (1.8). */
    updateAria() {
        const base = this.dataset.ariaBase || 'Traffic graph';
        if (this.rows.length === 0) {
            this.setAttribute('aria-label', `${base}, no data in this range`);
            return;
        }
        const plan = this.names.map((name) => !this.hiddenSeries.has(name));
        let peak = null;
        for (const row of this.rows) {
            let total = null;
            plan.forEach((visible, i) => {
                if (visible && !isGap(row[i + 1])) total = (total ?? 0) + Number(row[i + 1]);
            });
            if (total !== null && (peak === null || total > peak.total)) peak = { total, at: row[0] };
        }
        const first = this.rows[0][0];
        const last = this.rows[this.rows.length - 1][0];
        const short = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
        const unit = this.unit();
        const parts = [base, `${this.dateFmt(first, short)} to ${this.dateFmt(last, short)}`];
        if (peak) parts.push(`peak ${scaled(peak.total, unit.base, unit.rate)} at ${this.dateFmt(peak.at, short)}`);
        this.setAttribute('aria-label', parts.join(', '));
    }

    // ── Brush (1.8) ─────────────────────────────────────────────────────────

    /** Armed after every draw on fine pointers; on coarse ones only by armBrushOnce(). */
    syncBrush() {
        if (!this.chart) return;
        const armed = !this.coarse.matches || this.brushOnce;
        this.chart.dispatchAction({
            type: 'takeGlobalCursor',
            key: 'brush',
            brushOption: armed ? { brushType: 'lineX', brushMode: 'single' } : { brushType: false },
        });
        if (armed !== this.brushArmed || this.coarse.matches) {
            this.brushArmed = armed;
            this.dispatchEvent(new CustomEvent('brush-armed', { bubbles: true, detail: { armed: this.coarse.matches && armed } }));
        }
    }

    /** Coarse pointers: arm the brush for one selection (the #brushToggle button). */
    armBrushOnce() {
        this.brushOnce = true;
        this.syncBrush();
    }

    disarmBrush() {
        this.brushOnce = false;
        this.syncBrush();
    }

    handleBrush(params) {
        const range = params?.areas?.[0]?.coordRange;
        this.chart?.dispatchAction({ type: 'brush', areas: [] });
        if (this.brushOnce) this.disarmBrush();
        if (!Array.isArray(range) || range.length < 2 || !this.chart) return;

        const [a, b] = range.map(Number);
        const width = Math.abs(this.chart.convertToPixel({ xAxisIndex: 0 }, b) - this.chart.convertToPixel({ xAxisIndex: 0 }, a));
        if (!Number.isFinite(width) || width < MIN_BRUSH_PX) return;
        const from = Math.floor(Math.min(a, b) / INTERVAL_MS) * INTERVAL_MS;
        const to = Math.max(from + INTERVAL_MS, Math.ceil(Math.max(a, b) / INTERVAL_MS) * INTERVAL_MS);
        this.dispatchEvent(new CustomEvent('range-select', { bubbles: true, detail: { from, to } }));
    }

    showHint() {
        if (!this.hint) return;
        this.hint.hidden = false;
        clearTimeout(this.hintTimer);
        this.hintTimer = setTimeout(() => {
            this.hint.hidden = true;
        }, HINT_MS);
    }

    // ── Zoom ────────────────────────────────────────────────────────────────

    handleZoom() {
        const range = this.getCurrentRange();
        if (!range) return;
        this.dispatchEvent(new CustomEvent('graph-zoom', { bubbles: true, detail: { from: range.from, to: range.to } }));
    }

    /** After new data: the kept preview again, or a view that is not zoomed, which ends it. */
    announceView() {
        const range = this.getCurrentRange() ?? { from: 0, to: 0 };
        this.dispatchEvent(new CustomEvent('graph-zoom', { bubbles: true, detail: { from: range.from, to: range.to } }));
    }

    handleLegendSelect(params) {
        const selected = params?.selected ?? {};
        for (const name of this.names) {
            if (!(name in selected)) continue;
            if (selected[name]) this.hiddenSeries.delete(name);
            else this.hiddenSeries.add(name);
        }
        this.updateAria();
    }

    /**
     * The zoomed range, or the whole drawn range before any zoom.
     * @returns {{from: number, to: number}|null} ms
     */
    getCurrentRange() {
        if (!this.chart) return null;
        const dz = this.chart.getOption()?.dataZoom?.[0];
        if (dz && dz.startValue != null && dz.endValue != null) {
            return { from: Math.floor(Number(dz.startValue)), to: Math.floor(Number(dz.endValue)) };
        }
        if (this.rows.length) {
            return { from: this.rows[0][0], to: this.rows[this.rows.length - 1][0] };
        }
        return null;
    }

    /** @returns {Array|null} [from, to] in ms */
    xAxisRange() {
        const range = this.getCurrentRange();
        return range ? [range.from, range.to] : null;
    }

    isZoomed() {
        if (!this.chart) return false;
        const range = this.getCurrentRange();
        if (!range || this.rows.length === 0) return false;
        return range.from > this.rows[0][0] || range.to < this.rows[this.rows.length - 1][0];
    }

    resetZoom() {
        this.chart?.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
    }

    // ── Public API kept from the Dygraphs era ───────────────────────────────

    resize() {
        this.chart?.resize();
    }

    /** Style keys the old templates sent: logscale, stackedGraph, fillGraph, stepPlot. */
    updateOptions(options = {}) {
        if ('logscale' in options) this.chartStyle.logscale = !!options.logscale;
        if ('stackedGraph' in options) this.chartStyle.stacked = !!options.stackedGraph;
        if ('fillGraph' in options) this.chartStyle.stacked = !!options.fillGraph;
        if ('stepPlot' in options) this.chartStyle.stepplot = !!options.stepPlot;
        if (this.chart) this.render({ keepZoom: true });
    }

    /** data-chart-style changed: the client-local toggles of the Options panel. */
    applyStyle() {
        const next = { ...this.chartStyle, ...parseJson(this.dataset.chartStyle, {}) };
        if (JSON.stringify(next) === JSON.stringify(this.chartStyle)) return;
        this.chartStyle = next;
        if (this.chart) this.render({ keepZoom: true });
    }

    /**
     * Show or hide one series (0-based, in data order). A member of Others changes the sum, so
     * the chart is drawn again; any other series is a legend selection.
     */
    setVisibility(index, visible) {
        const name = this.names[index];
        if (name === undefined) return;
        if (visible) this.hiddenSeries.delete(name);
        else this.hiddenSeries.add(name);
        if (!this.chart) return;
        const plan = this.seriesPlan(chartTheme());
        if (plan[index]?.others) {
            this.chart.setOption({ dataset: { source: this.sourceRows(plan) } });
        } else {
            this.chart.dispatchAction({ type: visible ? 'legendSelect' : 'legendUnSelect', name });
        }
        this.updateAria();
    }

    showMessage(message) {
        if (!this.container) return;
        // An ECharts instance must not outlive its canvas: it would keep drawing into a node
        // that is gone while the next update takes the setOption path (#160).
        this.destroy();
        const p = document.createElement('p');
        p.className = 'chart-placeholder';
        p.textContent = message;
        this.container.replaceChildren(p);
    }

    destroy() {
        this.chart?.dispose();
        this.chart = null;
        this.panelKey = '';
        this.brushArmed = false;
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-chart', NfsenChart);
