/**
 * <nfsen-sankey data-conversation data-seconds data-unit> (4.4.4; ROCKET-SPEC 6.6, shape A): sources, ports, destinations.
 * The canvas sits in an ignored container in the light DOM, so a morph never touches it.
 */
import { rocket } from 'datastar';
import { copyText } from 'nfsen/clipboard';
import { downloadUrl } from 'nfsen/download';
import { escapeHtml, fitLabel, formatBytesExact, formatCountExact, formatMetricExact, formatRate, labelWidth } from 'nfsen/format';
import { hostState, peekState, whenGone } from 'nfsen/host-state';
import { chartTheme, onThemeChange, seriesColor } from 'nfsen/theme-colors';

const MIN_HEIGHT = 500;
const PX_PER_NODE = 22;
/** Node key of Others in each column; no address, subnet or port contains it. */
const OTHERS = '*';

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

// The page's Export menu and the subnet node click (4.4.4).
window.nfsenConversations ??= {
    async copy(text, what) {
        const ok = await copyText(text);
        window.showMessage?.(ok ? 'success' : 'warning', ok ? `Copied the ${what}.` : `Could not copy the ${what}.`, true);
    },
    exportPairs(kind) {
        const table = document.querySelector('#convPanel-pairs nfsen-table');
        if (kind === 'json') table?.exportJson();
        else table?.exportCsv();
    },
    exportPng(view) {
        document.querySelector(`#convPanel-${view} :is(nfsen-sankey, nfsen-matrix)`)?.downloadPng?.();
    },
};

/** The payload the host holds, parsed again only when the attribute changed. */
function payloadOf(host) {
    const state = peekState(host);
    if (!state) return parsePayload(host.dataConversation);
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

/** Lays the chart out for the payload, the width and the theme; `force` also redraws what is already drawn. */
function draw(host, force = false) {
    const state = peekState(host);
    const canvas = host.querySelector('.sankey-canvas');
    if (!state || !canvas || !host.isConnected) return;
    const payload = payloadOf(host);
    if (!payload?.pairs.length) {
        note(state, canvas, 'No conversations in this result.');
        return;
    }
    // A hidden view has no width; the ResizeObserver draws it once it is shown.
    if (canvas.clientWidth === 0) return;
    if (!window.echarts) {
        clearTimeout(state.retry);
        state.retry = setTimeout(() => draw(host, force), 100);
        return;
    }
    const theme = chartTheme();
    const drawn = state.drawn;
    // The canvas, not the host: the container's scrollbar narrows only the canvas.
    if (!force && state.chart && drawn?.text === state.text && drawn.theme === theme && drawn.width === canvas.clientWidth) return;

    const graph = sankeyGraph(payload);
    state.nodes = new Map(graph.nodes.map((n) => [n.name, n]));
    const perColumn = ['source', 'port', 'destination'].map((kind) => graph.nodes.filter((n) => n.kind === kind).length);
    canvas.style.height = `${Math.max(MIN_HEIGHT, Math.max(...perColumn) * PX_PER_NODE)}px`;
    // Read after the height, which can bring or drop that scrollbar.
    const width = canvas.clientWidth;

    if (state.chart) {
        state.chart.resize();
    } else {
        canvas.replaceChildren();
        state.chart = window.echarts.init(canvas);
        state.chart.on('click', (params) => host.onClick?.(params));
    }
    state.chart.setOption(option(host, graph, theme, width), true);
    state.drawn = { text: state.text, theme, width };
}

function option(host, graph, theme, width) {
    const { metric } = graph;
    // Each outer column gets the room its widest label needs, up to a share of the width.
    const room = (kind) => {
        const widest = Math.max(0, ...graph.nodes.filter((n) => n.kind === kind).map((n) => labelWidth(n.label)));
        return Math.max(48, Math.min(Math.ceil(widest) + 14, 240, Math.round(width * 0.28)));
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
            formatter: (params) => tooltip(host, params, metric, host.dataSeconds),
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
                    label: nodeLabel(n, theme, (n.kind === 'source' ? left : right) - 10),
                })),
                links: graph.links,
            },
        ],
    };
}

/** Sources point their label outward to the left, destinations to the right, ports sit on a chip. */
function nodeLabel(node, theme, width) {
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

/** The tooltip of a node or a link: the metric, its average rate in the traffic unit, and the other counts. */
function tooltip(host, params, metric, seconds) {
    const unit = host.dataUnit === 'bytes' ? 'bytes' : 'bits';
    const state = peekState(host);
    const figures = (d) =>
        [
            `<b>${escapeHtml(formatMetricExact(d[metric], metric))}</b> (${escapeHtml(formatRate(d[metric], metric, seconds, unit))} on average)`,
            metric === 'packets' ? escapeHtml(formatBytesExact(d.bytes)) : `${escapeHtml(formatCountExact(d.packets))} packets`,
            `${escapeHtml(formatCountExact(d.flows))} flows`,
        ].join('<br>');

    const payload = payloadOf(host);
    const topN = payload?.meta?.topN ?? payload?.pairs.length ?? 0;
    if (params.dataType === 'edge') {
        const d = params.data;
        const name = (id) => {
            const n = state?.nodes.get(id);
            if (!n) return escapeHtml(String(id).replace(/^(?:src|port|dst):/, ''));
            return escapeHtml(n.kind === 'port' && /^\d+$/.test(n.label) ? `port ${n.label}` : n.label);
        };
        if (state?.nodes.get(d.source)?.others) {
            return `Others, the pairs not in the top ${escapeHtml(topN)}<br>${figures(d)}`;
        }
        let html = `${name(d.source)} → ${name(d.target)}<br>${figures(d)}`;
        if (d.reverse) {
            html += `<br>of which ${escapeHtml(formatMetricExact(d.reverse[metric], metric))} from ${name(d.target)} to ${name(d.source)}`;
        }
        return html;
    }

    const label = escapeHtml(params.data?.text ?? params.name);
    const total = escapeHtml(formatMetricExact(params.value, metric));
    if (params.data?.others) return `${label}<br>pairs not in the top ${escapeHtml(topN)}, ${total}`;
    const hint = { source: 'source', destination: 'destination', port: 'destination port' }[params.data?.kind] ?? '';
    return `${label}<br>${hint}, ${total}`;
}

/** An address node opens its IP info (the page handles the event); a subnet node copies its filter. */
function nodeClick(host, params, emit) {
    if (params?.dataType !== 'node') return;
    const { text: label, kind: column, others } = params.data ?? {};
    if (column === 'port' || others) return;
    const group = payloadOf(host)?.meta?.groupBy;
    if (group === 'net24' || group === 'net16') {
        window.nfsenConversations.copy(`net ${label}`, `filter net ${label}`);
        return;
    }
    emit('conversation-node', { address: label });
}

function downloadPng(host) {
    const chart = peekState(host)?.chart;
    if (!chart) return;
    downloadUrl(chart.getDataURL({ type: 'png', pixelRatio: 2, backgroundColor: chartTheme().surface }), 'conversations-sankey.png');
}

rocket('nfsen-sankey', {
    mode: 'open',
    props: ({ number, string }) => ({
        dataConversation: string.docs({ description: 'The result as ConversationPayload JSON: ranked pairs, Others, totals and meta.' }),
        dataSeconds: number.docs({ description: 'The length of the result window in seconds, for the average rates.' }),
        dataUnit: string.docs({ description: 'bits or bytes: the traffic graph unit the tooltip prints rates in.' }),
    }),
    manifest: {
        events: [
            {
                name: 'conversation-node',
                kind: 'custom-event',
                bubbles: true,
                composed: true,
                description: 'An address node was clicked; detail.address is the address to look up.',
            },
        ],
    },
    setup: ({ cleanup, defineHostProp, emit, host, observeProps }) => {
        if (!host.shadowRoot.firstChild) host.shadowRoot.append(document.createElement('slot'));
        const state = hostState(host, () => ({
            chart: null,
            text: null,
            payload: null,
            nodes: new Map(),
            drawn: null,
            frame: 0,
            retry: 0,
            queued: false,
        }));

        defineHostProp('chart', { get: () => peekState(host)?.chart ?? null });
        defineHostProp('resize', { value: () => draw(host, true) });
        defineHostProp('downloadPng', { value: () => downloadPng(host) });
        defineHostProp('onClick', { value: (params) => nodeClick(host, params, emit) });
        defineHostProp('tooltip', { value: (params, metric, seconds) => tooltip(host, params, metric, seconds) });
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
