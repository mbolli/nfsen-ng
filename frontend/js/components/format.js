/** Number, rate and label formatting shared by the charts and the table (nfsen/format). */

/** What a figure that cannot be formatted reads. */
export const GAP_TEXT = 'no data';

/** The traffic graph's datatypes: axis prefixes, rate units and names. */
export const UNITS = {
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
 * use for an empty bucket (#154), and NaN, Infinity or a non-number.
 */
export function isGap(value) {
    return value == null || value === '' || !Number.isFinite(Number(value));
}

/** Three significant digits and the prefix that keeps the number below the base. */
export function scaled(value, base, units) {
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

const ENTITIES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

export function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ENTITIES[c]);
}

const BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

function binary(value, round) {
    let n = Number(value) || 0;
    let i = 0;
    while (n >= 1024 && i < BYTE_UNITS.length - 1) {
        n /= 1024;
        i++;
    }
    return `${i === 0 ? round(n) : n.toFixed(n < 10 ? 2 : 1)} ${BYTE_UNITS[i]}`;
}

/** Bytes with binary prefixes, as the tables print them ("1.7 GiB"); whole bytes are rounded (the Matrix). */
export function formatBytes(value) {
    return binary(value, Math.round);
}

/** formatBytes with bytes below 1 KiB printed as they come (the Sankey: "7.5 B"). */
export function formatBytesExact(value) {
    return binary(value, (n) => n);
}

/** A count, rounded (the Matrix). */
export function formatCount(value) {
    return Math.round(Number(value) || 0).toLocaleString('en');
}

/** A count printed as it comes (the Sankey). */
export function formatCountExact(value) {
    return (Number(value) || 0).toLocaleString('en');
}

/** A value of a run's metric: bytes base 1024, packets as a count, rounded (the Matrix). */
export function formatMetric(value, metric) {
    return metric === 'packets' ? `${formatCount(value)} packets` : formatBytes(value);
}

/** formatMetric without the rounding (the Sankey). */
export function formatMetricExact(value, metric) {
    return metric === 'packets' ? `${formatCountExact(value)} packets` : formatBytesExact(value);
}

/** The average rate over `seconds`: packets, or bytes in the traffic unit (bits or bytes). */
export function formatRate(value, metric, seconds, unit) {
    const s = Math.max(1, Number(seconds) || 1);
    if (metric === 'packets') return scaled(value / s, UNITS.packets.base, UNITS.packets.rate);
    if (unit === 'bytes') return scaled(value / s, UNITS.bytes.base, UNITS.bytes.rate);
    return scaled((value * 8) / s, UNITS.bits.base, UNITS.bits.rate);
}

const LABEL_FONT_SIZE = 11;
let measureContext = null;

/** The width of a chart label in pixels. */
export function labelWidth(text) {
    measureContext ??= document.createElement('canvas').getContext('2d');
    if (!measureContext) return String(text).length * LABEL_FONT_SIZE * 0.6;
    measureContext.font = `${LABEL_FONT_SIZE}px sans-serif`;
    return measureContext.measureText(String(text)).width;
}

/**
 * The label shortened from the middle to fit `width`: addresses of one network share the
 * start and differ at the end, so the tail keeps the larger part.
 */
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
