// Generated from virtual-scroll.ts by `go tool task ts`: edit the TypeScript, not this file.
import { rocket, startPeeking, stopPeeking } from 'datastar';
// A host method may be called from an effect: its reads must not subscribe it.
const peek = (fn) => {
    startPeeking();
    try {
        return fn();
    }
    finally {
        stopPeeking();
    }
};
// One ElementInternals per element: attachInternals() works once, and
// onFirstRender runs again when the element is re-attached. Its custom state
// (:state(loading)) and ARIA (aria-busy) survive morphs.
const internals = new WeakMap();
const internalsOf = (host) => internals.get(host) ?? internals.set(host, host.attachInternals()).get(host);
// Items with something to focus take Tab themselves; otherwise the scroller does.
const FOCUSABLE = 'a[href], button, input, select, textarea, summary, [tabindex], [contenteditable]';
// An item's key: what stays with it when items move ('': none).
const keyOf = (el) => el.getAttribute('data-key') || el.id;
// Notes on the styles, kept here where the minifier drops them:
// - The scroller is contained (strict): a new window never lays out the page.
// - The window is not: rows wider than the list overflow it, and the scroller
//   scrolls sideways (a table), with the sticky header.
// - The bars above and below the window stand for rows the server hasn't sent.
// - No scroll padding while a header control has the focus: the browser scrolls
//   before any focus event, and the padding would scroll the list for it.
const styles = /* css */ `
:host {
	--_ph: var(--sb-surface-hover, #1A2540);
	--_focus: var(--sb-brand-light, #B09AFF);
	display: block;
	box-sizing: border-box;
	inline-size: 100%;
	block-size: 20rem;
}
:host([hidden]) { display: none; }
.scroller {
	block-size: 100%;
	overflow: auto;
	overflow-anchor: none;
	overscroll-behavior: contain;
	contain: strict;
	outline: none;
	scroll-padding-block-start: var(--_head);
}
.scroller:has(.header:focus-within) { scroll-padding-block-start: 0; }
.scroller:focus-visible { outline: 2px solid var(--_focus); outline-offset: -2px; }
.header { position: sticky; inset-block-start: 0; z-index: 1; }
.spacer { position: relative; }
.probe { position: absolute; inline-size: 0; visibility: hidden; }
.window {
	position: absolute;
	inset-block-start: 0;
	inset-inline: 0;
	block-size: var(--_h);
	translate: 0 var(--_y);
	display: grid;
	grid-template-columns: repeat(var(--_cols), minmax(0, 1fr));
	grid-auto-rows: var(--_size);
}
.spacer::before, .spacer::after {
	content: "";
	position: absolute;
	inset-inline: 0;
	background: linear-gradient(transparent 30%, var(--_ph) 0 70%, transparent 0) 0 0 / 100% var(--_size);
}
.spacer::before { inset-block-start: 0; block-size: var(--_y); }
.spacer::after { inset-block: calc(var(--_y) + var(--_h)) 0; }
@media (forced-colors: active) {
	.spacer::before, .spacer::after { forced-color-adjust: none; --_ph: GrayText; }
}
`;
rocket('sb-virtual-scroll', {
    props: ({ number, string }) => ({
        total: number.min(-1).docs({ description: 'Server data: how many items the whole list has, or -1 while the server does not know (more may follow).' }),
        offset: number.min(0).docs({ description: 'Server data: the index of the first child in the whole list (from 0, a multiple of columns).' }),
        itemSize: string.trim.default('32').docs({ description: 'Row height: a number of px, or a CSS length (2.5rem, calc(1lh + 1rem), var(--row)) measured in whole px. Every item is exactly one row high.' }),
        columns: number.clamp(1, 1000).step(1).default(1).docs({ description: 'Items per row: more than 1 lays them out as a grid.' }),
        buffer: number.min(0).default(4000).docs({ description: 'How far a window reaches past the viewport, above and below, in px.' }),
        step: number.min(1).step(1).default(1).docs({ description: 'Windows start and end at a multiple of step items, so viewers ask for the same windows (1: no rounding).' }),
        label: string.trim.docs({ description: 'Accessible name of the list.' }),
    }),
    manifest: {
        slots: [
            { name: 'default', description: 'The items of the current window, one element each, in order. The server renders them.' },
            { name: 'header', description: 'Stays at the top while the items scroll under it, and scrolls sideways with them (column headings).' },
        ],
        events: [
            { name: 'sb-window', kind: 'custom-event', bubbles: true, composed: true, description: 'Asks for a window of items. detail: { offset, count }. Answer by re-rendering the host with those items as children and the new offset and total.' },
        ],
    },
    // Rendered once: the geometry goes through signals.
    renderOnPropChange: false,
    render: ({ html }) => html `
		<div class="scroller" part="scroller" data-ref:scroller data-on:scroll__passive="@scroll()"
			data-on:focusin="@trackFocus()" data-on:focusout="@leaveFocus()" data-on:keydown="@keyFirst()"
			data-attr:role="$$role" data-attr:aria-label="($$role && $$label) || false" data-attr:tabindex="$$tab"
			data-style:--_head="$$head + 'px'">
			<div class="header" part="header" data-ref:header><slot name="header"></slot></div>
			<div class="spacer" data-style:block-size="$$height + 'px'" data-style:--_y="$$y + 'px'"
				data-style:--_h="$$win + 'px'" data-style:--_size="$$size + 'px'" data-style:--_cols="$$cols">
				<div class="probe" data-ref:probe></div>
				<div class="window" part="window"><slot data-ref:items></slot></div>
			</div>
		</div>
	`,
    onFirstRender: ({ $$, action, adoptStyles, cleanup, defineHostProp, emit, host, props, refs }) => {
        const { scroller, header, items, probe } = refs;
        adoptStyles(host, styles);
        const internals = internalsOf(host), states = internals.states;
        const loading = (on) => (on ? states.add('loading') : states.delete('loading'), (internals.ariaBusy = on ? 'true' : null));
        // view: the height the rows get (the scroller minus the header). have: the
        // items in the window. last: the last request (on its way while wait), sent
        // at `sent`, of lastCount items before the rounding to steps and sentCount
        // after. cap: the most items the server sends at once, once it sent fewer
        // than asked for before the end of the list (0: no cap seen). px: the row
        // height. reach: how far the windows went while the total is unknown.
        let view = 0, have = 0, last = '', lastCount = 0, sentCount = 0, sent = 0, wait = false, timer = 0, cap = 0, px = 32, reach = 0;
        const rows = (n) => Math.ceil(n / props.columns);
        // Windows start and end at a multiple of this many items: of step and of columns.
        const unit = () => {
            let a = props.step, b = props.columns;
            while (b)
                [a, b] = [b, a % b];
            return (props.step * props.columns) / a;
        };
        // Buffer rows on each side of v rows in view: with a cap, as many as fit
        // in it around the view and the rounding to steps, so a window still covers the view.
        const buffer = (v) => {
            const b = Math.ceil(props.buffer / px);
            return cap ? Math.min(b, Math.max(0, Math.floor((rows(cap - 2 * (unit() - props.columns)) - v) / 2))) : b;
        };
        const known = () => host.hasAttribute('total') && props.total >= 0;
        const more = () => props.total < 0 || props.offset + have < props.total;
        // What the list spans: the total, or while it is unknown, the items seen and a window more.
        const extent = () => {
            const v = Math.ceil(view / px) + 1;
            return props.total < 0 ? reach + (v + 2 * buffer(v)) * props.columns : props.total;
        };
        // item-size in px: a number as it is, a CSS length as the probe's height.
        const measure = () => {
            const v = props.itemSize;
            if (+v > 0)
                return void (px = +v);
            probe.style.blockSize = '';
            probe.style.blockSize = v;
            px = Math.round(probe.getBoundingClientRect().height) || px;
        };
        // at: the index of the item with the focus (-1: none), focusKey: its key, place: the focused element
        // among its focusables. parked: the scroller holds the focus while no item can take it.
        let at = -1, focusKey = '', place = 0, parked = false, frame = 0;
        // A <template data-for> that renders the items is no item.
        const kids = () => items.assignedElements().filter((el) => el.localName != 'template');
        const find = (key) => (key ? kids().findIndex((el) => keyOf(el) === key) : -1);
        // The keyed item in the top row of the view: its index, and how far the view is scrolled into it.
        let anchor = null;
        const remember = () => {
            const size = px, top = scroller.scrollTop, r = Math.floor(top / size), i = r * props.columns;
            const el = kids()[i - props.offset], key = el ? keyOf(el) : '';
            anchor = key ? { key, index: i, delta: top - r * size } : null;
        };
        // Items that arrived before it move the anchor down: scroll with it, unless the list is at the top and idle.
        const keep = () => {
            if (!anchor || !(scroller.scrollTop > 0 || holds() || host.matches(':hover')))
                return;
            const j = find(anchor.key), cols = props.columns;
            if (j < 0)
                return;
            const r = Math.floor((props.offset + j) / cols);
            if (r !== Math.floor(anchor.index / cols))
                scroller.scrollTop = r * px + anchor.delta;
        };
        // In order, also those in components' open shadow roots (sb-checkbox's box).
        const focusables = (el, out = []) => {
            if (el.matches(FOCUSABLE))
                out.push(el);
            for (const c of el.shadowRoot ? [...el.shadowRoot.children, ...el.children] : el.children)
                focusables(c, out);
            return out;
        };
        const holds = () => {
            const a = host.getRootNode().activeElement;
            return a === host || host.contains(a);
        };
        const tab = () => (host.querySelector(FOCUSABLE) ? parked && -1 : 0);
        $$.head = 0;
        const sync = () => {
            const old = px, top = scroller.scrollTop;
            measure();
            have = kids().length;
            reach = props.total < 0 ? Math.max(reach, props.offset + have) : 0;
            $$.size = px;
            $$.cols = props.columns;
            $$.height = rows(extent()) * px;
            $$.y = Math.floor(props.offset / props.columns) * px;
            $$.win = rows(have) * px;
            // A role on the host hands the semantics to the page (a grid, a feed).
            $$.role = !host.hasAttribute('role') && 'list';
            $$.label = props.label;
            $$.tab = tab();
            // A new row height keeps the reader on the same row.
            if (px !== old)
                scroller.scrollTop = (top / old) * px;
        };
        // The window for the scroll position: the visible rows plus the buffer on
        // both sides, clamped to the list and rounded out to steps; and its count before the rounding.
        const want = () => {
            const v = Math.ceil(view / px) + 1, b = buffer(v), u = unit(), cols = props.columns;
            const r = Math.max(0, Math.min(Math.round(scroller.scrollTop / px) - b, rows(extent()) - v - 2 * b));
            const o = Math.floor((r * cols) / u) * u;
            return [o, Math.ceil(((r + v + 2 * b) * cols) / u) * u - o, (v + 2 * b) * cols];
        };
        // The viewport went past half the buffer, on a side where the list goes on.
        const outside = () => {
            const top = scroller.scrollTop, y = $$.y, half = (buffer(Math.ceil(view / px) + 1) * px) / 2;
            return (props.offset > 0 && top < y + half) || (more() && top > y + $$.win - view - half);
        };
        const ask = () => {
            if (!view)
                return;
            const [offset, n, raw] = want();
            // A new count (connect, resize, new geometry) or no total yet (a morph
            // emptied the host) asks without a threshold.
            if (raw === lastCount && host.hasAttribute('total') && !outside())
                return;
            // The window we want is in the one we have (total="0": an empty list).
            const end = offset + (known() ? Math.min(n, props.total - offset) : n);
            if (offset >= props.offset && end <= props.offset + have)
                return;
            // One request at a time, unless it got lost, and a window only once:
            // the same one again would bring the same answer.
            const key = offset + ' ' + n;
            if (key === last || (wait && performance.now() - sent < 1000))
                return;
            last = key;
            lastCount = raw;
            sentCount = n;
            wait = true;
            sent = performance.now();
            loading(true);
            emit('sb-window', { offset, count: n });
        };
        const park = () => parked || ((parked = true), ($$.tab = tab()), scroller.focus({ preventScroll: true }));
        const takes = (el) => (el.focus({ preventScroll: true }), el.getRootNode().activeElement === el);
        // A frame after a window, so a page that moves the focus itself (sb-data-table) goes first:
        // back to the same place in the item now at that index, or to the scroller while none is.
        const refocus = () => {
            frame = 0;
            const a = document.activeElement;
            if (at < 0 || !(holds() || !a || a === document.body))
                return;
            // A keyed item wherever the window has it now; the index only once it is gone.
            const j = find(focusKey);
            if (j >= 0)
                at = props.offset + j;
            // A list that ends before the item: its neighbour, the new last item; an empty list: the scroller.
            if (known())
                at = Math.min(at, props.total - 1);
            if (at < 0)
                return holds() || park();
            const item = kids()[at - props.offset];
            if (!item)
                return park();
            // The focus is in that item already: where it was, or where the page put it.
            if (item.contains(host.getRootNode().activeElement))
                return;
            // The same place, or the nearest element before it, then after it, that takes the focus.
            const all = focusables(item), i = Math.max(0, Math.min(place, all.length - 1));
            // Nothing there takes it: the scroller, not an element the morph left showing another item.
            if (![...all.slice(0, i + 1).reverse(), ...all.slice(i + 1)].some(takes))
                (at = -1), item.contains(host.getRootNode().activeElement) || park();
        };
        sync();
        remember();
        action('scroll', () => (remember(), ask()));
        // Named apart from what a page may bind on the host: an action there
        // finds the host's own actions first.
        action('trackFocus', ({ evt }) => {
            const path = evt.composedPath(), k = path.indexOf(items);
            if (path[0] === scroller)
                return;
            const item = path[k - 1], i = k > 0 ? kids().indexOf(item) : -1;
            at = i < 0 ? -1 : props.offset + i;
            if (i < 0)
                return;
            focusKey = keyOf(item);
            // The innermost one on the path: the link, not a row around it with a tabindex.
            const all = focusables(item);
            place = all.indexOf(path.find((el) => all.includes(el)));
        });
        // Scrolls item i into view, which asks for its window.
        const go = (i, block = 'start') => {
            const y = Math.floor(i / props.columns) * px, top = scroller.scrollTop, end = y + px - view;
            scroller.scrollTo({ top: block === 'end' ? end : block !== 'nearest' || y < top ? y : Math.max(top, end) });
            remember();
            ask();
        };
        // The elements outside the host that Tab stops at, in the flat tree's order:
        // those after it, or those before it from the nearest.
        const beyond = (after) => {
            const out = [[], []];
            let side = 0;
            const walk = (n) => {
                for (const c of n instanceof HTMLSlotElement && n.assignedElements().length ? n.assignedElements() : (n.shadowRoot ?? n).children) {
                    if (c === host)
                        side = 1;
                    else {
                        if (c.matches(FOCUSABLE) && c.tabIndex >= 0)
                            out[side].push(c);
                        walk(c);
                    }
                }
            };
            walk(document.body);
            return after ? out[1] : out[0].reverse();
        };
        action('keyFirst', ({ evt }) => {
            const e = evt, k = e.key, fwd = k === 'PageDown' || k === 'End';
            // Tab between a window and its refocus would move on from an element now showing another item.
            if (k === 'Tab')
                return void (frame && refocus());
            // A feed's keys: Page Down and Up to the next and the previous article,
            // Ctrl+End and Ctrl+Home out of the feed, to the next and the previous Tab stop.
            if (host.getAttribute('role') !== 'feed' || e.altKey || e.metaKey || e.shiftKey)
                return;
            if (e.ctrlKey ? k !== 'End' && k !== 'Home' : k !== 'PageDown' && k !== 'PageUp')
                return;
            if (e.ctrlKey)
                return void (beyond(fwd).some((el) => (el.focus(), el.getRootNode().activeElement === el)) && e.preventDefault());
            // From the scroller: the article at the top of the view, or the one before it.
            const i = at >= 0 ? at + (fwd ? 1 : -1) : Math.floor(scroller.scrollTop / px) * props.columns - (fwd ? 0 : 1);
            if (i < 0 || (props.total >= 0 && i >= props.total))
                return;
            // The article takes the focus as soon as a window has it.
            at = i;
            place = 0;
            focusKey = '';
            e.preventDefault();
            go(i, 'nearest');
            if (kids()[i - props.offset])
                refocus();
        });
        action('leaveFocus', ({ evt }) => {
            const t = evt.target, to = evt.relatedTarget;
            const unpark = () => parked && host.shadowRoot.activeElement !== scroller && ((parked = false), ($$.tab = tab()));
            if (t === scroller && to)
                unpark();
            if (to)
                return void (host.contains(to) || host.shadowRoot.contains(to) || (at = -1));
            // No related target: a click on the page, a window switch (the focus stays), or the morph removing
            // the element. Gone once the morph is done: keep the item and refocus, also when the morph left the host as it was.
            queueMicrotask(() => (t === scroller && unpark(), t.isConnected ? holds() || (at = -1) : host.isConnected && (frame ||= requestAnimationFrame(refocus))));
        });
        // A morph (the server's answer) changes the children and the attributes.
        const morphed = () => {
            wait = false;
            loading(false);
            sync();
            // Short of the request before the end of the list: the server's cap.
            // Without a total the host holds a new list, asked for afresh.
            if (have < sentCount && more())
                cap = have;
            if (!host.hasAttribute('total'))
                last = '';
            keep();
            ask();
            remember();
            frame ||= requestAnimationFrame(refocus);
            // A keyed item patched in place shows another entry: the focus goes back to its item now, before a key acts on it.
            const a = host.getRootNode().activeElement;
            if (focusKey && kids().some((el) => el.contains(a) && keyOf(el) !== focusKey))
                refocus();
        };
        const watch = new MutationObserver(morphed);
        watch.observe(host, { attributeFilter: ['offset', 'total', 'item-size', 'columns', 'buffer', 'step', 'label', 'role'], childList: true });
        // A morph that patches keyed items by position may change nothing but their keys.
        const keys = new MutationObserver((records) => records.some((r) => r.target.parentElement === host) && morphed());
        keys.observe(host, { attributeFilter: ['id', 'data-key'], subtree: true });
        // The first size asks right away, later ones once the resizing stops.
        const resize = new ResizeObserver(() => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                $$.head = header.offsetHeight;
                view = Math.max(0, scroller.clientHeight - $$.head);
                ask();
            }, view ? 150 : 0);
        });
        resize.observe(scroller);
        resize.observe(header);
        // A CSS length follows the page: its font size, its custom properties.
        const remeasure = new ResizeObserver(() => {
            const old = px;
            sync();
            if (px !== old)
                remember(), ask();
        });
        remeasure.observe(probe);
        cleanup(() => {
            watch.disconnect();
            keys.disconnect();
            resize.disconnect();
            remeasure.disconnect();
            clearTimeout(timer);
            cancelAnimationFrame(frame);
        });
        defineHostProp('scrollToIndex', {
            value: (i, { block } = {}) => peek(() => go(i, block)),
        });
    },
});
