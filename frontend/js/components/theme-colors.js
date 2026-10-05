/**
 * Resolves design tokens to rgba() strings, since ECharts cannot parse oklch() or light-dark().
 * Import it as 'nfsen/theme-colors' so every chart shares one module instance and cache.
 */

const SERIES_SLOTS = 8;

/** @type {Map<string, ChartTheme>} */
const cache = new Map();

/** @type {HTMLSpanElement | null} */
let probe = null;

/** @type {CanvasRenderingContext2D | null} */
let ctx = null;

/**
 * @typedef {object} ChartTheme
 * @property {string} text
 * @property {string} axis
 * @property {string} grid
 * @property {string} tooltipBg
 * @property {string} tooltipBorder
 * @property {string} brush
 * @property {string} brushBorder
 * @property {string} surface
 * @property {string[]} series  eight slot colours, index 0 is slot 1
 * @property {string} others
 */

function probeElement() {
    if (!probe?.isConnected) {
        probe = document.createElement('span');
        probe.setAttribute('aria-hidden', 'true');
        // forced-color-adjust keeps the token's own value when forced colors are on.
        probe.style.cssText = 'display:none;forced-color-adjust:none';
        (document.body ?? document.documentElement).appendChild(probe);
    }
    return probe;
}

function context() {
    if (!ctx) {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        ctx = canvas.getContext('2d', { willReadFrequently: true });
    }
    return ctx;
}

/**
 * Resolve any CSS colour value (a token reference, light-dark(), color-mix(), a system colour)
 * against the current theme, as an rgba() string.
 * @param {string} cssValue e.g. 'var(--series-1)'
 * @returns {string}
 */
export function resolve(cssValue) {
    const el = probeElement();
    el.style.color = '';
    el.style.color = cssValue;
    const computed = getComputedStyle(el).color;

    const c = context();
    if (!c) return computed;
    c.clearRect(0, 0, 1, 1);
    c.fillStyle = 'rgba(0, 0, 0, 0)';
    c.fillStyle = computed;
    c.fillRect(0, 0, 1, 1);
    const [r, g, b, a] = c.getImageData(0, 0, 1, 1).data;
    return `rgba(${r}, ${g}, ${b}, ${Math.round((a / 255) * 1000) / 1000})`;
}

function forcedColors() {
    return window.matchMedia('(forced-colors: active)').matches;
}

function themeKey() {
    const root = document.documentElement;
    const scheme = root.dataset.theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'os-dark' : 'os-light');
    return `${scheme}|${forcedColors() ? 'forced' : 'normal'}`;
}

function build() {
    // In forced-colors mode the chart chrome follows the system palette like the rest of the
    // page; the series keep their token colours, as swatches and chips do.
    const chrome = forcedColors()
        ? {
              text: 'CanvasText',
              axis: 'CanvasText',
              grid: 'GrayText',
              tooltipBg: 'Canvas',
              tooltipBorder: 'CanvasText',
              brush: 'color-mix(in srgb, Highlight 25%, transparent)',
              brushBorder: 'Highlight',
              surface: 'Canvas',
          }
        : {
              text: 'var(--chart-text)',
              axis: 'var(--chart-axis)',
              grid: 'var(--chart-grid)',
              tooltipBg: 'var(--chart-tooltip-bg)',
              tooltipBorder: 'var(--chart-tooltip-border)',
              brush: 'var(--chart-brush)',
              brushBorder: 'var(--chart-brush-border)',
              surface: 'var(--surface-2)',
          };

    /** @type {ChartTheme} */
    const theme = Object.fromEntries(Object.entries(chrome).map(([key, value]) => [key, resolve(value)]));
    theme.series = Array.from({ length: SERIES_SLOTS }, (_, i) => resolve(`var(--series-${i + 1})`));
    theme.others = resolve('var(--series-others)');
    return theme;
}

/**
 * Every colour a chart needs, resolved for the current theme and cached per theme.
 * @returns {ChartTheme}
 */
export function chartTheme() {
    const key = themeKey();
    let theme = cache.get(key);
    if (!theme) {
        theme = build();
        cache.set(key, theme);
    }
    return theme;
}

/**
 * The colour of one series slot (1-based). Slots past the palette, and 'others', are the
 * neutral Others colour.
 * @param {number | 'others'} slot
 * @returns {string}
 */
export function seriesColor(slot) {
    const theme = chartTheme();
    const index = Number(slot) - 1;
    return Number.isInteger(index) && index >= 0 && index < SERIES_SLOTS ? theme.series[index] : theme.others;
}

/**
 * The Matrix heat ramp: --text-1 mixed into --surface-2 from 20 % to 100 %.
 * @param {number} [steps=6]
 * @returns {string[]} lightest (least traffic) first
 */
export function sequentialRamp(steps = 6) {
    const n = Math.max(1, Math.floor(steps));
    return Array.from({ length: n }, (_, i) => {
        const pct = n === 1 ? 100 : 20 + (80 * i) / (n - 1);
        return resolve(`color-mix(in oklab, var(--text-1) ${pct}%, var(--surface-2))`);
    });
}

/**
 * Line style for a line-chart series once the eight slots repeat: 1-8 solid, 9-16 dashed,
 * 17-24 dotted; from 25 on the series are thin neutral lines, drawn solid.
 * @param {number} index 0-based series index
 * @returns {'solid' | 'dashed' | 'dotted'}
 */
export function linePattern(index) {
    const round = Math.floor(index / SERIES_SLOTS);
    if (round === 1) return 'dashed';
    if (round === 2) return 'dotted';
    return 'solid';
}

/**
 * Call `callback(chartTheme())` whenever the resolved colours may have changed: the
 * <html data-theme> switch, and the OS colour scheme or forced-colors mode flipping.
 * @param {(theme: ChartTheme) => void} callback
 * @returns {() => void} unsubscribe
 */
export function onThemeChange(callback) {
    let last = themeKey();
    const notify = () => {
        const key = themeKey();
        if (key === last) return;
        last = key;
        callback(chartTheme());
    };

    const observer = new MutationObserver(notify);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    const queries = ['(prefers-color-scheme: dark)', '(forced-colors: active)'].map((q) => window.matchMedia(q));
    for (const q of queries) q.addEventListener('change', notify);

    return () => {
        observer.disconnect();
        for (const q of queries) q.removeEventListener('change', notify);
    };
}
