import { rocket, startPeeking, stopPeeking } from 'datastar'

// A host method may be called from an effect: its reads must not subscribe it.
const peek = <T>(fn: () => T): T => {
	startPeeking()
	try {
		return fn()
	} finally {
		stopPeeking()
	}
}

// One ElementInternals per element: attachInternals() works once, and
// onFirstRender runs again when the element is re-attached. Its custom state
// (:state(loading)) survives morphs.
const internals = new WeakMap<HTMLElement, ElementInternals>()
const internalsOf = (host: HTMLElement) => internals.get(host) ?? internals.set(host, host.attachInternals()).get(host)!

// Items with something to focus take Tab themselves; otherwise the scroller does.
const FOCUSABLE = 'a[href], button, input, select, textarea, summary, [tabindex], [contenteditable]'

// sb-window's detail: the window of items to send, from the item at offset.
type WindowRequest = { offset: number; count: number }
// scrollToIndex's options: where the item ends up in the view.
type ScrollOptions = { block?: 'start' | 'nearest' | 'end' }

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
`

rocket('sb-virtual-scroll', {
	props: ({ number, string }) => ({
		total: number.min(0).docs({ description: 'Server data: how many items the whole list has.' }),
		offset: number.min(0).docs({ description: 'Server data: the index of the first child in the whole list (from 0, a multiple of columns).' }),
		itemSize: number.min(1).default(32).docs({ description: 'Row height in px. Every item is exactly one row high.' }),
		columns: number.clamp(1, 1000).step(1).default(1).docs({ description: 'Items per row: more than 1 lays them out as a grid.' }),
		buffer: number.min(0).default(4000).docs({ description: 'How far a window reaches past the viewport, above and below, in px.' }),
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
	render: ({ html }) => html`
		<div class="scroller" part="scroller" data-ref:scroller data-on:scroll__passive="@scroll()"
			data-on:focusin="@trackFocus()" data-on:focusout="@leaveFocus()" data-on:keydown="@keyFirst()"
			data-attr:role="$$role" data-attr:aria-label="($$role && $$label) || false" data-attr:tabindex="$$tab"
			data-style:--_head="$$head + 'px'">
			<div class="header" part="header" data-ref:header><slot name="header"></slot></div>
			<div class="spacer" data-style:block-size="$$height + 'px'" data-style:--_y="$$y + 'px'"
				data-style:--_h="$$win + 'px'" data-style:--_size="$$size + 'px'" data-style:--_cols="$$cols">
				<div class="window" part="window"><slot data-ref:items></slot></div>
			</div>
		</div>
	`,
	onFirstRender: ({ $$, action, adoptStyles, cleanup, defineHostProp, emit, host, props, refs }) => {
		const { scroller, header, items } = refs as { scroller: HTMLElement; header: HTMLElement; items: HTMLSlotElement }
		adoptStyles(host, styles)
		const states = internalsOf(host).states
		// view: the height the rows get (the scroller minus the header). have: the
		// items in the window. last: the last request (on its way while wait), sent
		// at `sent`. cap: the most items the server sends at once, once it sent
		// fewer than asked for before the end of the list (0: no cap seen).
		let view = 0, have = 0, last = '', lastCount = 0, sent = 0, wait = false, timer = 0, cap = 0
		const rows = (n: number) => Math.ceil(n / props.columns)
		// Buffer rows on each side of v rows in view: with a cap, as many as fit
		// in it around the view, so a window still covers the view.
		const buffer = (v: number) => {
			const b = Math.ceil(props.buffer / props.itemSize)
			return cap ? Math.min(b, Math.max(0, Math.floor((rows(cap) - v) / 2))) : b
		}

		// at: the index of the item with the focus (-1: none), place: the focused element among its
		// focusables. parked: the scroller holds the focus while no item can take it.
		let at = -1, place = 0, parked = false, frame = 0
		// A <template data-for> that renders the items is no item.
		const kids = () => items.assignedElements().filter((el) => el.localName != 'template')
		// In order, also those in components' open shadow roots (sb-checkbox's box).
		const focusables = (el: Element, out: Element[] = []) => {
			if (el.matches(FOCUSABLE)) out.push(el)
			for (const c of el.shadowRoot ? [...el.shadowRoot.children, ...el.children] : el.children) focusables(c, out)
			return out
		}
		const holds = () => {
			const a = (host.getRootNode() as Document | ShadowRoot).activeElement
			return a === host || host.contains(a)
		}
		const tab = () => (host.querySelector(FOCUSABLE) ? parked && -1 : 0)

		$$.head = 0
		const sync = () => {
			const size = props.itemSize
			have = kids().length
			$$.size = size
			$$.cols = props.columns
			$$.height = rows(props.total) * size
			$$.y = Math.floor(props.offset / props.columns) * size
			$$.win = rows(have) * size
			// A role on the host hands the semantics to the page (a grid, a feed).
			$$.role = !host.hasAttribute('role') && 'list'
			$$.label = props.label
			$$.tab = tab()
		}

		// The window for the scroll position: the visible rows plus the buffer on
		// both sides, clamped to the list.
		const want = (): [number, number] => {
			const size = props.itemSize, v = Math.ceil(view / size) + 1, b = buffer(v)
			const r = Math.max(0, Math.min(Math.round(scroller.scrollTop / size) - b, rows(props.total) - v - 2 * b))
			return [r * props.columns, (v + 2 * b) * props.columns]
		}
		// The viewport went past half the buffer, on a side where the list goes on.
		const outside = () => {
			const top = scroller.scrollTop, y = $$.y, half = (buffer(Math.ceil(view / props.itemSize) + 1) * props.itemSize) / 2
			return (props.offset > 0 && top < y + half) || (props.offset + have < props.total && top > y + $$.win - view - half)
		}
		const ask = () => {
			if (!view) return
			const [offset, n] = want()
			// A new count (connect, resize, new geometry) or no total yet (a morph
			// emptied the host) asks without a threshold.
			if (n === lastCount && host.hasAttribute('total') && !outside()) return
			// The window we want is in the one we have (total="0": an empty list).
			const end = offset + (host.hasAttribute('total') ? Math.min(n, props.total - offset) : n)
			if (offset >= props.offset && end <= props.offset + have) return
			// One request at a time, unless it got lost, and a window only once:
			// the same one again would bring the same answer.
			const key = offset + ' ' + n
			if (key === last || (wait && performance.now() - sent < 1000)) return
			last = key
			lastCount = n
			wait = true
			sent = performance.now()
			states.add('loading')
			emit<WindowRequest>('sb-window', { offset, count: n })
		}

		const park = () => parked || ((parked = true), ($$.tab = tab()), scroller.focus({ preventScroll: true }))
		const takes = (el: Element) => ((el as HTMLElement).focus({ preventScroll: true }), (el.getRootNode() as Document | ShadowRoot).activeElement === el)
		// A frame after a window, so a page that moves the focus itself (sb-data-table) goes first:
		// back to the same place in the item now at that index, or to the scroller while none is.
		const refocus = () => {
			frame = 0
			const a = document.activeElement
			if (at < 0 || !(holds() || !a || a === document.body)) return
			// A list that ends before the item: its neighbour, the new last item; an empty list: the scroller.
			if (host.hasAttribute('total')) at = Math.min(at, props.total - 1)
			if (at < 0) return holds() || park()
			const item = kids()[at - props.offset]
			if (!item) return park()
			// The focus is in that item already: where it was, or where the page put it.
			if (item.contains((host.getRootNode() as Document | ShadowRoot).activeElement)) return
			// The same place, or the nearest element before it, then after it, that takes the focus.
			const all = focusables(item), i = Math.max(0, Math.min(place, all.length - 1))
			// Nothing there takes it: the scroller, not an element the morph left showing another item.
			if (![...all.slice(0, i + 1).reverse(), ...all.slice(i + 1)].some(takes)) (at = -1), item.contains((host.getRootNode() as Document | ShadowRoot).activeElement) || park()
		}

		sync()
		action('scroll', ask)
		// Named apart from what a page may bind on the host: an action there
		// finds the host's own actions first.
		action('trackFocus', ({ evt }) => {
			const path = evt!.composedPath(), k = path.indexOf(items)
			if (path[0] === scroller) return
			const item = path[k - 1] as Element, i = k > 0 ? kids().indexOf(item) : -1
			at = i < 0 ? -1 : props.offset + i
			if (i < 0) return
			// The innermost one on the path: the link, not a row around it with a tabindex.
			const all = focusables(item)
			place = all.indexOf(path.find((el) => all.includes(el as Element)) as Element)
		})
		// Tab between a window and its refocus would move on from an element now showing another item.
		action('keyFirst', ({ evt }) => (evt as KeyboardEvent).key === 'Tab' && frame && refocus())
		action('leaveFocus', ({ evt }) => {
			const t = evt!.target as Element, to = (evt as FocusEvent).relatedTarget as Node | null
			const unpark = () => parked && host.shadowRoot!.activeElement !== scroller && ((parked = false), ($$.tab = tab()))
			if (t === scroller && to) unpark()
			if (to) return void (host.contains(to) || host.shadowRoot!.contains(to) || (at = -1))
			// No related target: a click on the page, a window switch (the focus stays), or the morph removing
			// the element. Gone once the morph is done: keep the item and refocus, also when the morph left the host as it was.
			queueMicrotask(() => (t === scroller && unpark(), t.isConnected ? holds() || (at = -1) : host.isConnected && (frame ||= requestAnimationFrame(refocus))))
		})
		// A morph (the server's answer) changes the children and the attributes.
		const watch = new MutationObserver(() => {
			wait = false
			states.delete('loading')
			sync()
			// Short of the request before the end of the list: the server's cap.
			// Without a total the host holds a new list, asked for afresh.
			if (have < lastCount && props.offset + have < props.total) cap = have
			if (!host.hasAttribute('total')) last = ''
			ask()
			frame ||= requestAnimationFrame(refocus)
		})
		watch.observe(host, { attributeFilter: ['offset', 'total', 'item-size', 'columns', 'buffer', 'label', 'role'], childList: true })
		// The first size asks right away, later ones once the resizing stops.
		const resize = new ResizeObserver(() => {
			clearTimeout(timer)
			timer = setTimeout(() => {
				$$.head = header.offsetHeight
				view = Math.max(0, scroller.clientHeight - $$.head)
				ask()
			}, view ? 150 : 0)
		})
		resize.observe(scroller)
		resize.observe(header)
		cleanup(() => {
			watch.disconnect()
			resize.disconnect()
			clearTimeout(timer)
			cancelAnimationFrame(frame)
		})

		defineHostProp('scrollToIndex', {
			value: (i: number, { block = 'start' }: ScrollOptions = {}) =>
				peek(() => {
					const size = props.itemSize, y = Math.floor(i / props.columns) * size, top = scroller.scrollTop, end = y + size - view
					scroller.scrollTo({ top: block === 'end' ? end : block !== 'nearest' || y < top ? y : Math.max(top, end) })
					ask()
				}),
		})
	},
})
