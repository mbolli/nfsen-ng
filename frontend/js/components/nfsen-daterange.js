/**
 * NfsenDateRange Web Component
 * Encapsulates noUiSlider with Datastar integration.
 * Replaces the former jQuery + ion.rangeSlider implementation.
 */

// ── Date formatting helpers ─────────────────────────────────────────────────

const _pad = (n) => String(n).padStart(2, '0');

/**
 * Format a timestamp for grid pip labels based on the total visible span.
 * Adapts granularity: time-only for ≤24 h, date only for larger spans.
 */
function formatPipLabel(ms, totalSpanMs) {
    const d = new Date(ms);
    if (totalSpanMs <= 24 * 3600_000) {
        // ≤24 h → "HH:MM"
        return `${_pad(d.getHours())}:${_pad(d.getMinutes())}`;
    }
    if (totalSpanMs <= 7 * 86400_000) {
        // ≤7 days → "DD MMM"
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    }
    if (totalSpanMs <= 90 * 86400_000) {
        // ≤90 days → "DD MMM"
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    }
    // >90 days → "MMM 'YY"
    const mon = d.toLocaleDateString('en-GB', { month: 'short' });
    return `${mon} '${String(d.getFullYear()).slice(2)}`;
}

/**
 * Format a timestamp for floating handle tooltips based on the selected span.
 * Shows time when the selected range is ≤24 h.
 */
function formatTooltip(ms, selectedSpanMs) {
    const d = new Date(ms);
    if (selectedSpanMs <= 24 * 3600_000) {
        // ≤24 h → "HH:MM"
        return `${_pad(d.getHours())}:${_pad(d.getMinutes())}`;
    }
    if (selectedSpanMs <= 7 * 86400_000) {
        // ≤7 days → "ddd DD MMM HH:MM"
        const day = d.toLocaleDateString('en-GB', { weekday: 'short' });
        const date = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
        return `${day} ${date} ${_pad(d.getHours())}:${_pad(d.getMinutes())}`;
    }
    // >7 days → "YYYY-MM-DD"
    return `${d.getFullYear()}-${_pad(d.getMonth() + 1)}-${_pad(d.getDate())}`;
}

/**
 * Build noUiSlider pips config from a min/max range.
 * Uses ~8 large ticks across the span, with sub-ticks in between.
 */
function buildPipsConfig(minMs, maxMs) {
    const spanMs = maxMs - minMs;
    return {
        mode: 'positions',
        values: [0, 12.5, 25, 37.5, 50, 62.5, 75, 87.5, 100],
        density: 4,
        format: {
            to: (v) => formatPipLabel(v, spanMs),
        },
    };
}

/** Build tooltip formatters for the two handles. */
function buildTooltips(from, to) {
    const span = to - from;
    return [
        { to: (v) => formatTooltip(v, span) },
        { to: (v) => formatTooltip(v, span) },
    ];
}

// ── Web Component ───────────────────────────────────────────────────────────

export class NfsenDateRange extends HTMLElement {
    constructor() {
        super();
        this.slider = null; // the <div> DOM node that holds the noUiSlider instance
        this._pendingUpdates = null;
        this._updateTimer = null;
    }

    connectedCallback() {
        this.render();
    }

    disconnectedCallback() {
        if (this.slider) {
            this.slider.noUiSlider?.destroy();
            this.slider = null;
        }
    }

    render() {
        this.innerHTML = '<div class="daterange-slider"></div>';
        this.slider = this.querySelector('.daterange-slider');
        this._initSlider();
    }

    _initSlider() {
        if (!window.noUiSlider) {
            console.warn('noUiSlider not available');
            return;
        }

        const minMs  = parseInt(this.getAttribute('data-min')  || Date.now() - 86400_000);
        const maxMs  = parseInt(this.getAttribute('data-max')  || Date.now());
        const fromMs = parseInt(this.getAttribute('data-from') || minMs);
        const toMs   = parseInt(this.getAttribute('data-to')   || maxMs);

        window.noUiSlider.create(this.slider, {
            start:     [fromMs, toMs],
            connect:   true,
            behaviour: 'drag-tap',
            range:     { min: minMs, max: maxMs },
            tooltips:  buildTooltips(fromMs, toMs),
            pips:      buildPipsConfig(minMs, maxMs),
            format: {
                to:   (v) => Math.round(v),
                from: (v) => parseInt(v),
            },
        });

        // 'change' fires on mouseup/touchend only — avoids spamming graph refreshes
        // while the user is still dragging.
        this.slider.noUiSlider.on('change', (values) => {
            const [from, to] = values.map(Number);
            // Refresh tooltip format to reflect the new selected span
            this.slider.noUiSlider.updateOptions({
                tooltips: buildTooltips(from, to),
            }, false); // false = do not re-render (tooltips update themselves)
            this._emitRangeChange(from, to);
        });
    }

    _emitRangeChange(from, to) {
        const syncButton = document.querySelector('#date_syncing button.sync-date');
        if (syncButton) syncButton.disabled = true;

        this.dispatchEvent(
            new CustomEvent('range-change', {
                detail: {
                    from,
                    to,
                    fromDate: new Date(from),
                    toDate:   new Date(to),
                },
                bubbles:  true,
                composed: true,
            })
        );
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /** Programmatically set the selected range and fire range-change. */
    updateRange(from, to) {
        if (!this.slider?.noUiSlider) return;
        this.slider.noUiSlider.updateOptions({ tooltips: buildTooltips(from, to) }, false);
        this.slider.noUiSlider.set([from, to]);
        this._emitRangeChange(from, to);
    }

    /**
     * Set range to a specific duration (ms), anchored to the current max,
     * clamped so the selection never goes below data-min.
     * The template passes durationMs values (3600000, 86400000, etc.).
     */
    setQuickRange(durationMs) {
        if (!this.slider?.noUiSlider) return;
        const { min: minMs, max: maxMs } = this.slider.noUiSlider.options.range;
        const from = Math.max(maxMs - durationMs, minMs);
        this.updateRange(from, maxMs);
    }

    /** Navigate forward or backward by the current selected span. */
    navigate(direction) {
        if (!this.slider?.noUiSlider) return;
        const [from, to] = this.slider.noUiSlider.get(true).map(Number);
        const duration = to - from;
        const { min: minMs, max: maxMs } = this.slider.noUiSlider.options.range;
        if (direction === 'prev') {
            const newFrom = Math.max(from - duration, minMs);
            this.updateRange(newFrom, newFrom + duration);
        } else {
            const newTo = Math.min(to + duration, maxMs);
            this.updateRange(newTo - duration, newTo);
        }
    }

    /** Jump to the most recent data while keeping the same window size. */
    goToEnd() {
        if (!this.slider?.noUiSlider) return;
        const [from, to] = this.slider.noUiSlider.get(true).map(Number);
        const { min: minMs, max: maxMs } = this.slider.noUiSlider.options.range;
        const window = to - from;
        this.updateRange(Math.max(maxMs - window, minMs), maxMs);
    }

    // ── Attribute observation ─────────────────────────────────────────────────

    static get observedAttributes() {
        return ['data-min', 'data-max', 'data-from', 'data-to'];
    }

    attributeChangedCallback(name, oldValue, newValue) {
        if (oldValue === newValue || !this.isConnected || !this.slider?.noUiSlider) return;
        if (!this.offsetWidth) return; // not yet laid out — skip to avoid geometry errors

        // Batch: multiple attrs may fire in the same microtask
        if (!this._pendingUpdates) this._pendingUpdates = {};
        if (name === 'data-min')  this._pendingUpdates.min  = parseInt(newValue);
        if (name === 'data-max')  this._pendingUpdates.max  = parseInt(newValue);
        if (name === 'data-from') this._pendingUpdates.from = parseInt(newValue);
        if (name === 'data-to')   this._pendingUpdates.to   = parseInt(newValue);

        clearTimeout(this._updateTimer);
        this._updateTimer = setTimeout(() => {
            const upd = this._pendingUpdates;
            this._pendingUpdates = {};
            if (!upd || !Object.keys(upd).length) return;

            const ns = this.slider.noUiSlider;
            const curRange = ns.options.range;

            // Update range bounds + pips if min/max changed
            if (upd.min !== undefined || upd.max !== undefined) {
                const newMin = upd.min ?? curRange.min;
                const newMax = upd.max ?? curRange.max;
                ns.updateOptions({
                    range: { min: newMin, max: newMax },
                    pips:  buildPipsConfig(newMin, newMax),
                }, true); // true = fire re-render
            }

            // Update handle positions if from/to changed
            const [curFrom, curTo] = ns.get(true).map(Number);
            const newFrom = upd.from ?? curFrom;
            const newTo   = upd.to   ?? curTo;
            if (newFrom !== curFrom || newTo !== curTo) {
                ns.updateOptions({ tooltips: buildTooltips(newFrom, newTo) }, false);
                ns.set([newFrom, newTo]);
            }
        }, 0);
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-daterange', NfsenDateRange);
