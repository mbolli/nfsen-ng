/**
 * Conversations Sankey (4.4.4): sources on the left, destinations on the right and, grouped by
 * destination port, the ports between them. Nodes and links come from the ranked pairs in
 * data-conversation (ConversationPayload.php); the canvas sits in an ignored container, so a
 * morph never touches it. A source node takes its pair's series slot, the rest are neutral;
 * the traffic outside the ranked pairs is one Others node per column (2.3).
 */
import { chartTheme, onThemeChange, seriesColor } from 'nfsen/theme-colors';

const MIN_HEIGHT = 500;
const PX_PER_NODE = 22;
const LABEL_FONT_SIZE = 11;
/** Node key of Others in each column; no address, subnet or port contains it. */
const OTHERS = '*';

/** The traffic graph's rate units (nfsen-chart.js): bits base 1000, bytes base 1024. */
const RATE_UNITS = {
    bits: { base: 1000, rate: [' b/s', ' kb/s', ' Mb/s', ' Gb/s', ' Tb/s', ' Pb/s'] },
    bytes: { base: 1024, rate: [' B/s', ' KiB/s', ' MiB/s', ' GiB/s', ' TiB/s', ' PiB/s'] },
    packets: { base: 1000, rate: [' pkt/s', ' k pkt/s', ' M pkt/s', ' G pkt/s', ' T pkt/s', ' P pkt/s'] },
};

const escapeHtml = (text) =>
    String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Bytes with binary prefixes, as the tables print them ("1.7 GiB"). */
export function formatBytes(value) {
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    let n = Number(value) || 0;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${i === 0 ? n : n.toFixed(n < 10 ? 2 : 1)} ${units[i]}`;
}

export function formatCount(value) {
    return (Number(value) || 0).toLocaleString('en');
}

/** A value of the run's metric: bytes base 1024, packets as a count. */
export function formatMetric(value, metric) {
    return metric === 'packets' ? `${formatCount(value)} packets` : formatBytes(value);
}

/** Three significant digits and the prefix that keeps the number below the base, as the traffic graph prints them. */
function scaled(value, { base, rate }) {
    let v = Math.abs(Number(value) || 0);
    let i = 0;
    while (v >= base && i < rate.length - 1) {
        v /= base;
        i++;
    }
    const digits = v === 0 || v >= 100 ? 0 : v >= 10 ? 1 : 2;
    return `${Number(v.toFixed(digits))}${rate[i]}`;
}

/** The average rate over the window, bytes in the global unit (bits or bytes). */
export function formatRate(value, metric, seconds, unit) {
    const s = Math.max(1, Number(seconds) || 1);
    if (metric === 'packets') return scaled(value / s, RATE_UNITS.packets);
    return unit === 'bytes' ? scaled(value / s, RATE_UNITS.bytes) : scaled((value * 8) / s, RATE_UNITS.bits);
}

let measureContext = null;

/** The width of a node label in pixels. */
function labelWidth(text) {
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

/** The payload of a data-conversation attribute, or null when it holds none. */
export function parsePayload(text) {
    try {
        const payload = JSON.parse(text || 'null');
        return Array.isArray(payload?.pairs) ? payload : null;
    } catch {
        return null;
    }
}

/** A port node's label: the number, or ICMP's type.code (a string in the payload). */
export function portLabel(port) {
    return typeof port === 'string' ? `ICMP ${port}` : String(port);
}

/**
 * Nodes and links for the Sankey. Node ids carry their column (src:, port:, dst:), so one
 * address on both sides is two nodes and the layout keeps two or three clean columns. Nodes
 * come in rank order with Others last, which the layout keeps.
 */
export function sankeyGraph(payload) {
    const metric = payload.meta?.metric === 'packets' ? 'packets' : 'bytes';
    const byPort = payload.meta?.groupBy === 'port';
    const nodes = new Map();
    const links = new Map();

    const node = (name, label, kind, series = null, others = false) => {
        if (!nodes.has(name)) nodes.set(name, { name, label, kind, series, others });
        return name;
    };
    const link = (source, target, pair) => {
        const key = `${source}\u0000${target}`;
        const entry = links.get(key) ?? { source, target, value: 0, bytes: 0, packets: 0, flows: 0, reverse: null };
        entry.value += pair[metric];
        entry.bytes += pair.bytes;
        entry.packets += pair.packets;
        entry.flows += pair.flows;
        entry.reverse = pair.reverse ?? entry.reverse;
        links.set(key, entry);
    };

    const connect = (src, port, dst, pair) => {
        if (port === null) {
            link(src, dst, pair);
            return;
        }
        link(src, port, pair);
        link(port, dst, pair);
    };

    for (const pair of payload.pairs) {
        const src = node(`src:${pair.src}`, pair.src, 'source', pair.series ?? null);
        const port = byPort ? node(`port:${pair.port}`, portLabel(pair.port), 'port') : null;
        const dst = node(`dst:${pair.dst}`, pair.dst, 'destination');
        connect(src, port, dst, pair);
    }

    const others = payload.others;
    if (others && others[metric] > 0) {
        const src = node(`src:${OTHERS}`, 'Others', 'source', null, true);
        const port = byPort ? node(`port:${OTHERS}`, 'Others', 'port', null, true) : null;
        const dst = node(`dst:${OTHERS}`, 'Others', 'destination', null, true);
        connect(src, port, dst, { bytes: others.bytes, packets: others.packets, flows: others.flows, reverse: null });
    }

    return { metric, nodes: [...nodes.values()], links: [...links.values()] };
}

/** Saves a data URL as a file. */
export function download(href, filename) {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
}

/** The clipboard API needs a secure context; plain http falls back to a selected textarea. */
async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    const copied = document.execCommand('copy');
    area.remove();
    if (!copied) throw new Error('copy refused');
}

// The page's Export menu and the subnet node click (4.4.4).
window.nfsenConversations ??= {
    async copy(text, what) {
        try {
            await copyText(text);
            window.showMessage?.('success', `Copied the ${what}.`, true);
        } catch {
            window.showMessage?.('warning', `Could not copy the ${what}.`, true);
        }
    },
    exportPairs(kind) {
        const table = document.querySelector('#convPanel-pairs nfsen-table');
        if (kind === 'json') table?.exportJson();
        else table?.exportCsv();
    },
    exportPng(view) {
        document.querySelector(`#convPanel-${view} :is(nfsen-sankey, nfsen-matrix)`)?.downloadPng();
    },
};

export class NfsenSankey extends HTMLElement {
    static get observedAttributes() {
        return ['data-conversation'];
    }

    connectedCallback() {
        this.canvas = this.querySelector('.sankey-canvas');
        this.payload = parsePayload(this.dataset.conversation);
        // Also sees the view become visible: a chart drawn while hidden has no size. The label
        // room follows the width, so a resize lays the chart out again.
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
        download(this.chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: chartTheme().surface }), 'conversations-sankey.png');
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
        // Hidden views wait for the ResizeObserver.
        if (this.clientWidth === 0) return;
        if (!window.echarts) {
            setTimeout(() => this.render(), 100);
            return;
        }

        const graph = sankeyGraph(this.payload);
        this.nodes = new Map(graph.nodes.map((n) => [n.name, n]));
        const perColumn = ['source', 'port', 'destination'].map((kind) => graph.nodes.filter((n) => n.kind === kind).length);
        this.canvas.style.height = `${Math.max(MIN_HEIGHT, Math.max(...perColumn) * PX_PER_NODE)}px`;

        if (!this.chart) {
            this.canvas.replaceChildren();
            this.chart = window.echarts.init(this.canvas);
            this.chart.on('click', (params) => this.onClick(params));
        } else {
            this.chart.resize();
        }
        this.chart.setOption(this.option(graph), true);
    }

    onClick(params) {
        if (params.dataType !== 'node') return;
        const kind = this.payload?.meta?.groupBy;
        const { text: label, kind: column, others } = params.data;
        if (column === 'port' || others) return;
        if (kind === 'net24' || kind === 'net16') {
            window.nfsenConversations.copy(`net ${label}`, `filter net ${label}`);
            return;
        }
        this.dispatchEvent(new CustomEvent('conversation-node', { bubbles: true, detail: { address: label } }));
    }

    option(graph) {
        const theme = chartTheme();
        const { metric } = graph;
        const seconds = Number(this.dataset.seconds) || 1;
        // Each outer column gets the room its widest label needs, up to a share of the width.
        const room = (kind) => {
            const widest = Math.max(0, ...graph.nodes.filter((n) => n.kind === kind).map((n) => labelWidth(n.label)));
            return Math.max(48, Math.min(Math.ceil(widest) + 14, 240, Math.round(this.clientWidth * 0.28)));
        };
        const left = room('source');
        const right = room('destination');

        return {
            backgroundColor: 'transparent',
            animation: false,
            tooltip: {
                trigger: 'item',
                backgroundColor: theme.tooltipBg,
                borderColor: theme.tooltipBorder,
                textStyle: { color: theme.text },
                formatter: (params) => this.tooltip(params, metric, seconds),
            },
            series: [
                {
                    type: 'sankey',
                    left,
                    right,
                    top: 8,
                    bottom: 8,
                    nodeGap: 12,
                    // Rank order top to bottom, so series slots N and N+1 sit side by side (2.3).
                    layoutIterations: 0,
                    draggable: false,
                    emphasis: { focus: 'adjacency' },
                    lineStyle: { color: 'source', curveness: 0.5, opacity: 0.35 },
                    label: { color: theme.text, fontSize: 11 },
                    data: graph.nodes.map((n) => ({
                        name: n.name,
                        text: n.label,
                        kind: n.kind,
                        others: n.others,
                        itemStyle: { color: seriesColor(n.series ?? 'others'), borderColor: theme.surface },
                        label: this.nodeLabel(n, theme, (n.kind === 'source' ? left : right) - 10),
                    })),
                    links: graph.links,
                },
            ],
        };
    }

    /** Sources point their label outward to the left, destinations to the right, ports sit on a chip. */
    nodeLabel(node, theme, width) {
        const position = node.kind === 'source' ? 'left' : node.kind === 'port' ? 'inside' : 'right';
        if (node.kind !== 'port') {
            const text = fitLabel(node.label, width);
            return { position, formatter: () => text };
        }
        return {
            position,
            formatter: () => node.label,
            align: 'center',
            verticalAlign: 'middle',
            backgroundColor: theme.tooltipBg,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
            borderRadius: 4,
            padding: [2, 5],
        };
    }

    tooltip(params, metric, seconds) {
        const unit = this.dataset.unit === 'bytes' ? 'bytes' : 'bits';
        const figures = (d) =>
            [
                `<b>${escapeHtml(formatMetric(d[metric], metric))}</b> (${escapeHtml(formatRate(d[metric], metric, seconds, unit))} on average)`,
                metric === 'packets' ? escapeHtml(formatBytes(d.bytes)) : `${escapeHtml(formatCount(d.packets))} packets`,
                `${escapeHtml(formatCount(d.flows))} flows`,
            ].join('<br>');

        const topN = this.payload?.meta?.topN ?? this.payload?.pairs.length ?? 0;
        if (params.dataType === 'edge') {
            const d = params.data;
            const name = (id) => {
                const n = this.nodes?.get(id);
                if (!n) return escapeHtml(String(id).replace(/^(?:src|port|dst):/, ''));
                return escapeHtml(n.kind === 'port' && /^\d+$/.test(n.label) ? `port ${n.label}` : n.label);
            };
            if (this.nodes?.get(d.source)?.others) {
                return `Others, the pairs not in the top ${escapeHtml(topN)}<br>${figures(d)}`;
            }
            let html = `${name(d.source)} → ${name(d.target)}<br>${figures(d)}`;
            if (d.reverse) {
                html += `<br>of which ${escapeHtml(formatMetric(d.reverse[metric], metric))} from ${name(d.target)} to ${name(d.source)}`;
            }
            return html;
        }

        const label = escapeHtml(params.data?.text ?? params.name);
        const total = escapeHtml(formatMetric(params.value, metric));
        if (params.data?.others) return `${label}<br>pairs not in the top ${escapeHtml(topN)}, ${total}`;
        const hint = { source: 'source', destination: 'destination', port: 'destination port' }[params.data?.kind] ?? '';
        return `${label}<br>${hint}, ${total}`;
    }

    toJSON() {
        return this.tagName;
    }
}

customElements.define('nfsen-sankey', NfsenSankey);
