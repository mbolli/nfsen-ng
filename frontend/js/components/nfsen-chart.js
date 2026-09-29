/**
 * <nfsen-chart>: the traffic graph on Apache ECharts (spec 1.8, 4.1.3; ROCKET-SPEC 6.9, shape A).
 * The canvas sits in an ignored container in the light DOM, so a morph never touches it.
 *
 * Props: data-chart-data (GraphData JSON), data-chart-config (the server's part of the
 * configuration), data-chart-style (the client-local style toggles), data-mode
 * (overview | picker | picker-total), data-aria-base (the first part of the accessible name),
 * data-external-prefix (ids of the Series and Legend panels, Overview only).
 *
 * Events, all bubbling: range-select {from, to} after a brush (ms, on 5 minute boundaries),
 * graph-zoom {from, to} after a Ctrl + wheel zoom and after new data is drawn (ms; isZoomed()
 * says whether the view is a preview), brush-armed {armed}.
 */
import { rocket } from 'datastar';
import { escapeHtml, isGap, scaled, UNITS } from 'nfsen/format';
import { hostState, peekState, whenGone } from 'nfsen/host-state';
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

const coarse = window.matchMedia('(pointer: coarse)');

/** Which redraw each prop asks for. */
const PROP_WORK = {
    dataChartData: 'data',
    dataChartConfig: 'data',
    dataMode: 'data',
    dataChartStyle: 'style',
    dataAriaBase: 'aria',
};

function parseJson(text, fallback) {
    if (!text) return fallback;
    try {
        return JSON.parse(text);
    } catch {
        return fallback;
    }
}

async function waitForECharts(timeout = 5000) {
    const start = Date.now();
    while (!window.echarts) {
        if (Date.now() - start > timeout) return false;
        await new Promise((resolve) => setTimeout(resolve, 50));
    }
    return true;
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

/**
 * What a chart keeps across a move (K14): the ECharts instance, the drawn data, the hidden series,
 * the brush and the zoom. Setup binds listeners to it; the host API reads it through peekState.
 */
class ChartState {
    constructor(host) {
        this.host = host;
        this.chart = null;
        this.container = null;
        this.hint = null;
        this.rows = [];
        this.names = [];
        this.slots = [];
        this.config = {};
        this.configText = '';
        this.chartStyle = { logscale: false, stacked: false, stepplot: true };
        this.hiddenSeries = new Set();
        this.drawnData = null;
        this.panelKey = '';
        this.seriesKey = '';
        this.brushArmed = false;
        this.brushOnce = false;
        this.pending = new Set();
        this.hintTimer = 0;
        this.dateFmt = (ms, extra = {}) => new Date(ms).toLocaleString(undefined, extra);
    }

    /** From setup: find the canvas (a new one drops the instance bound to the old) and catch up on the props. */
    attach() {
        const container = this.host.querySelector('.chart-canvas');
        if (container !== this.container) {
            this.destroy();
            this.drawnData = null;
            this.container = container;
        }
        this.hint = this.host.querySelector('.chart-hint');
        for (const work of ['data', 'style', 'aria']) this.queue(work);
    }

    release() {
        clearTimeout(this.hintTimer);
        this.destroy();
        this.container = null;
    }

    emit(type, detail) {
        this.host.dispatchEvent(new CustomEvent(type, { bubbles: true, composed: true, detail }));
    }

    /** Props change in the middle of a morph (K15): note what they ask for and do it once, after the morph. */
    queue(work) {
        const first = this.pending.size === 0;
        this.pending.add(work);
        if (!first) return;
        queueMicrotask(() => {
            const pending = new Set(this.pending);
            this.pending.clear();
            if (!this.host.isConnected) return;
            if (pending.has('data')) this.load();
            if (pending.has('style')) this.applyStyle();
            // Before the first draw the server's label stands; the draw writes the full one.
            if (pending.has('aria') && this.drawnData !== null) this.updateAria();
        });
    }

    async load() {
        if (!this.container) {
            this.container = this.host.querySelector('.chart-canvas');
            this.hint = this.host.querySelector('.chart-hint');
            if (!this.container) return;
        }
        if (!window.echarts && !(await waitForECharts())) {
            this.showMessage('The chart library failed to load.');
            return;
        }
        if (!this.host.isConnected) return;

        // An emptied or removed data or config attribute keeps the last chart (1.2, K5).
        const dataText = this.host.dataChartData;
        if (!dataText) return;
        if (this.host.dataChartConfig) this.configText = this.host.dataChartConfig;
        const config = parseJson(this.configText, {});
        const key = `${this.host.dataMode}|${this.configText}|${dataText}`;
        if (key === this.drawnData && this.chart) return;

        const data = parseJson(dataText, null);
        if (data === null) {
            this.showMessage('The chart data could not be read.');
            this.announceView();
            return;
        }
        this.chartStyle = { ...this.chartStyle, ...parseJson(this.host.dataChartStyle, {}) };
        this.drawnData = key;
        const zoom = this.chart && this.isZoomed() ? this.getCurrentRange() : null;
        const sameWindow = (config.window ?? '') === (this.config.window ?? '');
        const seriesKey = this.seriesKey;
        this.setData(data, config);
        this.draw({ zoom: sameWindow && seriesKey === this.seriesKey ? this.zoomInside(zoom) : null, announce: true });
    }

    /** A zoom that still lies inside the drawn data, so a refresh of the same window keeps the preview (D6). */
    zoomInside(zoom) {
        if (!zoom || this.rows.length === 0) return null;
        return zoom.from >= this.rows[0][0] && zoom.to <= this.rows[this.rows.length - 1][0] ? zoom : null;
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
        return this.host.dataMode || this.config.mode || 'overview';
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
    draw({ keepZoom = false, zoom: restore = null, announce = false }) {
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
        const prefix = this.host.dataExternalPrefix;
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
        const base = this.host.dataAriaBase || 'Traffic graph';
        if (this.rows.length === 0) {
            this.host.setAttribute('aria-label', `${base}, no data in this range`);
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
        this.host.setAttribute('aria-label', parts.join(', '));
    }

    // ── Brush (1.8) ─────────────────────────────────────────────────────────

    /** Armed after every draw on fine pointers; on coarse ones only by armBrushOnce(). */
    syncBrush() {
        if (!this.chart) return;
        const armed = !coarse.matches || this.brushOnce;
        this.chart.dispatchAction({
            type: 'takeGlobalCursor',
            key: 'brush',
            brushOption: armed ? { brushType: 'lineX', brushMode: 'single' } : { brushType: false },
        });
        if (armed !== this.brushArmed || coarse.matches) {
            this.brushArmed = armed;
            this.emit('brush-armed', { armed: coarse.matches && armed });
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
        this.emit('range-select', { from, to });
    }

    /** A plain wheel scrolls the page; only Ctrl + wheel zooms (D6), which the hint says. */
    wheel(event) {
        if (!this.chart || event.ctrlKey || event.metaKey || !this.container?.contains(event.target)) return;
        // The inside dataZoom cancels every wheel it receives, so a plain one must not reach it.
        event.stopPropagation();
        if (!this.hint) return;
        this.hint.hidden = false;
        clearTimeout(this.hintTimer);
        this.hintTimer = setTimeout(() => {
            if (this.hint) this.hint.hidden = true;
        }, HINT_MS);
    }

    // ── Zoom ────────────────────────────────────────────────────────────────

    handleZoom() {
        const range = this.getCurrentRange();
        if (!range) return;
        this.emit('graph-zoom', { from: range.from, to: range.to });
    }

    /** After new data: the kept preview again, or a view that is not zoomed, which ends it. */
    announceView() {
        const range = this.getCurrentRange() ?? { from: 0, to: 0 };
        this.emit('graph-zoom', { from: range.from, to: range.to });
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

    isZoomed() {
        if (!this.chart) return false;
        const range = this.getCurrentRange();
        if (!range || this.rows.length === 0) return false;
        return range.from > this.rows[0][0] || range.to < this.rows[this.rows.length - 1][0];
    }

    resetZoom() {
        this.chart?.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
    }

    /** data-chart-style changed: the client-local toggles of the Options panel. */
    applyStyle() {
        const next = { ...this.chartStyle, ...parseJson(this.host.dataChartStyle, {}) };
        if (JSON.stringify(next) === JSON.stringify(this.chartStyle)) return;
        this.chartStyle = next;
        if (this.chart) this.draw({ keepZoom: true });
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
}

/** A host method that finds the state on each call (K14), so the host holds no reference to it. */
function method(host, name, fallback) {
    return { value: (...args) => peekState(host)?.[name](...args) ?? fallback };
}

rocket('nfsen-chart', {
    mode: 'open',
    props: ({ string }) => ({
        dataChartData: string.docs({ description: 'GraphData JSON ({data: {ts: [values]}, legend}); empty keeps the last chart.' }),
        dataChartConfig: string.docs({
            description:
                "The server's part of the configuration as JSON (unit, mode, series names and slots, time zones); empty keeps the last.",
        }),
        dataChartStyle: string.docs({
            description: 'The client-local style toggles as JSON: logscale, stacked, stepplot; empty keeps the last.',
        }),
        dataMode: string.docs({ description: 'overview, picker or picker-total; empty takes the mode of the configuration.' }),
        dataAriaBase: string.docs({ description: 'The first part of the accessible name the chart writes on every draw.' }),
        dataExternalPrefix: string.docs({ description: 'The id prefix of the Series and Legend panels (Overview only).' }),
    }),
    manifest: {
        events: [
            {
                name: 'range-select',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description: 'A brush ended; detail {from, to} in ms, on 5 minute boundaries.',
            },
            {
                name: 'graph-zoom',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description:
                    'After a Ctrl + wheel zoom and after new data is drawn; detail {from, to} in ms. isZoomed() says whether it is a preview.',
            },
            {
                name: 'brush-armed',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description: 'The brush changed; detail.armed is true while a coarse pointer has it armed for one selection.',
            },
        ],
    },
    setup: ({ cleanup, defineHostProp, host, observeProps }) => {
        if (!host.shadowRoot.firstChild) host.shadowRoot.append(document.createElement('slot'));
        const state = hostState(host, () => new ChartState(host));

        defineHostProp('chart', { get: () => peekState(host)?.chart ?? null });
        defineHostProp('getCurrentRange', method(host, 'getCurrentRange', null));
        defineHostProp('isZoomed', method(host, 'isZoomed', false));
        for (const name of ['resetZoom', 'armBrushOnce', 'disarmBrush', 'setVisibility', 'showMessage']) {
            defineHostProp(name, method(host, name));
        }
        defineHostProp('resize', { value: () => peekState(host)?.chart?.resize() });
        defineHostProp('toJSON', { value: () => host.tagName });

        observeProps(
            (_, changes) => {
                for (const name of Object.keys(changes)) state.queue(PROP_WORK[name]);
            },
            ...Object.keys(PROP_WORK)
        );

        state.attach();
        // The inner container can still be settling when the element already has its size.
        const resizes = new ResizeObserver(() => peekState(host)?.chart?.resize());
        resizes.observe(host);
        if (state.container) resizes.observe(state.container);
        const stopTheme = onThemeChange(() => state.chart && state.draw({ keepZoom: true }));
        const onPointer = () => state.syncBrush();
        coarse.addEventListener('change', onPointer);
        const onWheel = (event) => state.wheel(event);
        host.addEventListener('wheel', onWheel, { passive: true, capture: true });

        cleanup(() => {
            resizes.disconnect();
            stopTheme();
            coarse.removeEventListener('change', onPointer);
            host.removeEventListener('wheel', onWheel, { capture: true });
            whenGone(host, (gone) => gone.release());
        });
    },
});
