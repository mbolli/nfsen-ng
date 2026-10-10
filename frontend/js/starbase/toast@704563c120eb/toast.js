import { rocket, startPeeking, stopPeeking } from 'datastar'

// Host getters and prop callbacks must not subscribe whoever is running
// (observeProps fires inside the effect of whoever set the attribute).
const peek = (fn) => {
	startPeeking()
	try {
		return fn()
	} finally {
		stopPeeking()
	}
}

// Pixel corners: notches every corner by p (a toast: 2px times --sb-notch, and
// at 0 the border-radius takes over; the status light: 3px, a dot at 0).
const notch = (p) => `polygon(${p} 0, calc(100% - ${p}) 0, calc(100% - ${p}) ${p}, 100% ${p}, 100% calc(100% - ${p}), calc(100% - ${p}) calc(100% - ${p}), calc(100% - ${p}) 100%, ${p} 100%, ${p} calc(100% - ${p}), 0 calc(100% - ${p}), 0 ${p}, ${p} ${p})`

const LEAVE = 160 // ms the fade-out runs before the toast is taken out
const ANNOUNCE_MAX = 200 // characters handed to the live region

// A toast is {id, title?, text, variant?, duration?}; strings are allowed too.
// Without an id, the message is the id ("#2", "#3"… on repeats): a position
// would hand a dismissal on to the next toast once the server drops one.
const normalize = (list) => {
	const seen = { __proto__: null }
	return (Array.isArray(list) ? list : []).flatMap((t) => {
		const o = typeof t === 'object' && t !== null ? t : { text: t }
		const text = String(o.text ?? o.message ?? '')
		const title = String(o.title ?? '')
		if (!text && !title) return []
		const msg = title ? `${title}. ${text}` : text
		const n = (seen[msg] = (seen[msg] || 0) + 1)
		const variant = ['ok', 'warn', 'danger'].includes(o.variant) ? o.variant : 'info'
		// Capped where setTimeout overflows (it takes anything longer as 0).
		const duration = Number.isFinite(Number(o.duration)) && o.duration !== '' && o.duration !== null ? Math.min(2 ** 31 - 1, Math.max(0, Number(o.duration))) : null
		// msg is what the live region and the dismiss button read.
		return [{ id: String(o.id ?? (n > 1 ? `${msg}#${n}` : msg)), title, text, msg: msg.length > ANNOUNCE_MAX ? `${msg.slice(0, ANNOUNCE_MAX - 1)}…` : msg, variant, duration }]
	})
}

const styles = /* css */ `
:host {
	--_shadow: var(--sb-shadow-overlay, 0 10px 20px rgb(0 0 0 / 0.45));
	--_bg: var(--sb-surface-raised, #10182B);
	--_border: var(--sb-border, #283552);
	--_text: var(--sb-text-1, #F3F4FA);
	--_body: var(--sb-text-2, #AEBBDD);
	--_muted: var(--sb-text-muted, #7785A8);
	--_radius: var(--sb-radius, 8px);
	--_notch: var(--sb-notch, 1);
	--_n: calc(2px * var(--_notch));
	--_inset: var(--sb-toast-inset, 1rem);
	--_width: var(--sb-toast-width, 22rem);
	/* No box of its own: the region positions itself, the live regions are invisible. */
	display: contents;
}
:host([hidden]) { display: none; }
.region {
	position: fixed;
	z-index: var(--sb-z-toast, 60);
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	/* % of the viewport without its scrollbar (dvw would include it). */
	inline-size: min(var(--_width), calc(100% - 2 * var(--_inset)));
	/* The region itself is never a click target; the toasts in it are. */
	pointer-events: none;
	/* Here, not on .toast: a toast's own clip-path would cut its shadow off. */
	filter: drop-shadow(var(--_shadow));
}
[data-placement^="top"] { inset-block-start: var(--_inset); }
[data-placement^="bottom"] { inset-block-end: var(--_inset); }
[data-placement$="-start"] { inset-inline-start: var(--_inset); }
[data-placement$="-end"] { inset-inline-end: var(--_inset); }
[data-placement$="-center"] { inset-inline: 0; margin-inline: auto; }
/* For the docs and for pages that want the stack in the flow. */
[data-placement="inline"] { position: static; inline-size: 100%; }
.toast {
	--_tone: var(--sb-info, #65BFFF);
	--_from: -6px;
	display: grid;
	grid-template-columns: auto 1fr auto;
	align-items: start;
	gap: 0.625rem;
	position: relative;
	padding: 0.75rem 0.875rem 0.8rem;
	box-sizing: border-box;
	border: 1px solid color-mix(in oklch, var(--_tone) 30%, var(--_border));
	border-radius: calc(var(--_radius) * (1 - var(--_notch)));
	clip-path: ${notch('var(--_n)')};
	background: linear-gradient(color-mix(in oklch, var(--_tone) 8%, transparent), transparent), var(--_bg);
	color: var(--_body);
	pointer-events: auto;
	/* The negative --in (elapsed since it appeared) keeps the entrance from
	   replaying when the list re-renders: the animation is already over. */
	animation: sb-toast-in 180ms cubic-bezier(0.2, 0, 0, 1) var(--in, 0ms) both;
}
[data-placement^="bottom"] .toast { --_from: 6px; }
[data-variant="ok"] { --_tone: var(--sb-ok, #6EF59A); }
[data-variant="warn"] { --_tone: var(--sb-warn, #F5C451); }
[data-variant="danger"] { --_tone: var(--sb-danger, #F2777A); }
/* Rows are reused by index: each rebuild flips the names to restart them. */
.alt { animation-name: sb-toast-in2; }
.alt .bar { animation-name: sb-toast-bar2; }
.leaving { animation: sb-toast-out ${LEAVE}ms ease-in both; }
/* A pixel "status light", like sb-alert's: notched by --sb-notch, a dot at 0. */
.light {
	inline-size: 12px;
	block-size: 12px;
	margin-block-start: 0.3em;
	background: var(--_tone);
	clip-path: ${notch('calc(3px * var(--_notch))')};
	border-radius: calc(6px * (1 - var(--_notch)));
	box-shadow: 0 0 12px var(--_tone);
}
.title { display: block; color: var(--_tone); font-weight: 700; }
.title + .text { margin-block-start: 0.15rem; }
.text { font-size: 0.875rem; overflow-wrap: anywhere; }
.close {
	all: unset;
	display: grid;
	place-items: center;
	inline-size: 1.75rem;
	block-size: 1.75rem;
	margin-top: -0.25rem;
	margin-inline-end: -0.375rem;
	border-radius: calc(4px * (1 - var(--_notch)));
	color: var(--_muted);
	cursor: pointer;
}
.close:hover { color: var(--_text); background: color-mix(in oklch, var(--_text) 8%, transparent); }
.close:focus-visible { outline: 2px solid var(--_tone); outline-offset: -2px; }
.close svg { inline-size: 1rem; block-size: 1rem; }
/* Remaining time. --dur is the toast's duration, --bd the elapsed part as a
   negative delay, so a re-render resumes instead of restarting. */
.bar {
	position: absolute;
	inset: auto 0 0;
	block-size: 3px;
	background: var(--_tone);
	opacity: 0.55;
	transform-origin: 0;
	animation: sb-toast-bar var(--dur, 5000ms) linear var(--bd, 0ms) forwards;
}
:host(:dir(rtl)) .bar { transform-origin: 100%; }
.paused .bar { animation-play-state: paused; }
.sr {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
@keyframes sb-toast-in { from { opacity: 0; translate: 0 var(--_from); } }
@keyframes sb-toast-in2 { from { opacity: 0; translate: 0 var(--_from); } }
@keyframes sb-toast-out { to { opacity: 0; scale: 1 0.96; } }
@keyframes sb-toast-bar { to { scale: 0 1; } }
@keyframes sb-toast-bar2 { to { scale: 0 1; } }
@media (prefers-reduced-motion: reduce) {
	.toast { animation: none; }
	.bar { animation-timing-function: steps(5); }
}
`

rocket('sb-toast', {
	props: ({ json, number, oneOf, string }) => ({
		toasts: json.default(() => []).docs({
			description: 'The toasts, oldest first: [{id, title?, text, variant?: info|ok|warn|danger, duration?}]. Server state: every morph brings the current list, and a toast the server drops is gone.',
		}),
		placement: oneOf('top-start', 'top-center', 'top-end', 'bottom-start', 'bottom-center', 'bottom-end', 'inline')
			.default('bottom-end')
			.docs({ description: 'Corner of the viewport the stack is pinned to. "inline" puts it in the page flow instead (docs, demos, panels).' }),
		max: number.clamp(1, 10).default(3).docs({ description: 'How many toasts are shown at once. Older ones wait and appear as newer ones go; only shown toasts count down.' }),
		duration: number.clamp(0, 600000).default(5000).docs({ description: 'Default time before a toast dismisses itself, in ms. 0 makes them sticky; a toast may carry its own duration.' }),
		label: string.trim.default('Notifications').docs({ description: 'Accessible name of the region.' }),
	}),
	manifest: {
		events: [
			{
				name: 'sb-dismiss',
				kind: 'custom-event',
				bubbles: true,
				composed: true,
				description: 'A toast went away. detail: { id, reason: "user" | "timeout" }. It is already hidden locally; post a command if the server should drop it too.',
			},
		],
	},
	// Rendered once: the stack, the timers and the live regions all run through
	// signals, so a new toasts list never rebuilds the dismiss button under the
	// keyboard focus.
	renderOnPropChange: false,
	setup: ({ $$, action, adoptStyles, cleanup, defineHostProp, emit, host, observeProps, props }) => {
		adoptStyles(host, styles)

		// Plain closure state, never signals and never attributes: assigning an
		// object to a signal merges into it (keys never go away), and interaction
		// state on an attribute would not survive the next server morph.
		const dismissed = new Set() // ids this viewer closed or let expire
		const announced = new Set() // ids already handed to a live region
		const seenAt = new Map() // id -> when it first appeared (entrance)
		const timers = new Map() // id -> {total, left, at, t}; at and t are set by arm()
		const leaving = new Map() // id -> timeout that takes it out after the fade
		let last = [] // the list as last shown, fading toasts included
		let view = [] // the rows as last rendered, in DOM order
		let hover = false
		let gen = false // flips on every rebuild: fresh animations for every row
		let from // where the keyboard focus came from before it entered the stack

		const reduced = matchMedia('(prefers-reduced-motion: reduce)')

		$$.rows = []
		$$.polite = ''
		$$.assertive = ''

		// Attached before setup runs (and kept when the element is moved).
		const root = host.shadowRoot
		const closes = () => root.querySelectorAll('.close')
		// Keyboard focus pauses and is kept on its toast; a button focused by a
		// click does neither, or a dismissal with the mouse would stop every other
		// countdown and scroll an inline stack back into view as toasts go.
		const kb = () => root.querySelector(':focus-visible')
		const paused = () => hover || !!kb()

		const hold = (id) => {
			const s = timers.get(id)
			if (!s?.t) return
			clearTimeout(s.t)
			s.t = 0
			s.left = Math.max(0, s.left - (performance.now() - s.at))
		}
		const arm = (id) => {
			const s = timers.get(id)
			if (!s || s.t || paused()) return
			s.at = performance.now()
			s.t = setTimeout(() => {
				s.t = 0
				s.left = 0
				dismiss(id, 'timeout')
			}, s.left)
		}
		const forget = (id) => {
			hold(id)
			timers.delete(id)
			seenAt.delete(id)
			announced.delete(id)
		}

		// The visible stack, in the order it is painted.
		const rebuild = () => {
			// The focused row, by index: data-for reuses rows by index, so the
			// focus has to follow its toast by hand.
			const at = [...closes()].indexOf(kb())
			const had = view[at]?.id
			const list = normalize(props.toasts)
			// A toast that is fading out keeps its slot until the fade is over, even
			// when the server has dropped it already.
			last.forEach((t, i) => leaving.has(t.id) && !list.some((u) => u.id === t.id) && list.splice(i, 0, t))
			last = list
			// An id the server no longer sends is forgotten everywhere, so a
			// dismissal can never leak onto a later toast with the same id.
			for (const id of dismissed) if (!list.some((t) => t.id === id)) dismissed.delete(id)
			for (const id of seenAt.keys()) if (!list.some((t) => t.id === id)) forget(id)

			const alive = list.filter((t) => !dismissed.has(t.id) || leaving.has(t.id))
			const shown = alive.slice(-props.max)

			const t0 = performance.now()
			gen = !gen
			const rows = shown.map((t) => {
				const going = leaving.has(t.id)
				if (!seenAt.has(t.id)) seenAt.set(t.id, t0)
				const total = t.duration ?? props.duration
				if (total > 0 && !going && !timers.has(t.id)) timers.set(t.id, { total, left: total })
				const s = timers.get(t.id)
				const spent = s ? s.total - s.left + (s.t ? t0 - s.at : 0) : 0
				return {
					...t,
					leaving: going,
					alt: gen,
					// Only a toast with a timer has a bar (dismiss() drops a fading toast's).
					dur: s && `${s.total}ms`,
					bd: `-${Math.round(spent)}ms`,
					enter: `-${Math.round(t0 - seenAt.get(t.id))}ms`,
				}
			})
			// Newest nearest the edge the region is pinned to, and the DOM order is
			// the visual order (so Tab and screen readers follow the eye).
			if (props.placement.startsWith('top')) rows.reverse()

			// Keyboard focus stays on its toast, or moves to the neighbour (old index,
			// clamped) when that one goes, and back to where it came from after the
			// last one. Once before the rows change, so the focused row is never the
			// one taken out, and once after, for a row that did not exist yet (in a
			// microtask: inside an effect, e.g. data-attr:toasts, data-for renders later).
			const live = rows.filter((r) => !r.leaving)
			const to = live.find((r) => r.id === had) ?? live[Math.min(at, live.length - 1)]
			const move = () => at < 0 || (to ? closes()[rows.indexOf(to)] : from)?.focus()
			move()
			$$.rows = view = rows
			queueMicrotask(move)

			// Announce what is new, politely or assertively.
			const fresh = { polite: [], assertive: [] }
			for (const t of shown) {
				if (announced.has(t.id) || leaving.has(t.id)) continue
				announced.add(t.id)
				// Warnings and errors interrupt; everything else waits its turn.
				fresh[t.variant === 'warn' || t.variant === 'danger' ? 'assertive' : 'polite'].push(t.msg)
			}
			for (const k in fresh) {
				const s = fresh[k].join('. ')
				// A little later: a live region is not read when it is inserted with
				// its text (toasts in the first render). A trailing space alternates
				// per region, so the same message twice is read twice.
				if (s) setTimeout(() => ($$[k] = $$[k] === s ? `${s} ` : s), 100)
			}

			// The pointer may have been left behind by a toast that went away.
			syncPause(root.querySelector('.region')?.matches(':hover') ?? hover)
		}

		const syncPause = (next) => {
			hover = next
			$$.paused = paused()
			// Only the rendered toasts count down (not those queued behind max), and
			// none while paused.
			for (const id of timers.keys()) if (paused() || !view.some((r) => r.id === id)) hold(id)
			for (const r of view) arm(r.id)
		}

		// Dismissing is view state: it happens here and now, and the server hears
		// about it so it can drop the toast from its own list (after the local
		// rebuild, so a page that drops it at once still sees it fade).
		const dismiss = (id, reason) => {
			if (!id || dismissed.has(id)) return
			dismissed.add(id)
			hold(id)
			timers.delete(id)
			if (!reduced.matches) leaving.set(id, setTimeout(() => (leaving.delete(id), rebuild()), LEAVE))
			rebuild()
			emit('sb-dismiss', { id, reason })
		}

		const update = () => {
			$$.label = props.label
			$$.placement = props.placement
			rebuild()
		}
		update()
		// peek: attribute changes arrive inside the effect of whoever set them
		// (e.g. data-attr:toasts), and reading signals here must not subscribe it.
		observeProps(() => peek(update))

		action('dismiss', (_, id) => dismiss(id, 'user'))
		action('hover', (_, on) => syncPause(on))
		action('focus', ({ el }, evt) => {
			// Entering from outside: only the keyboard gets its focus handed back.
			if (evt && !el.contains(evt.relatedTarget)) from = evt.target.matches(':focus-visible') ? evt.relatedTarget : null
			syncPause(hover)
		})

		defineHostProp('dismiss', { value: (id) => peek(() => dismiss(String(id), 'user')) })
		// Everything, the toasts waiting behind max included.
		defineHostProp('clear', { value: () => peek(() => normalize(props.toasts).forEach((t) => dismiss(t.id, 'user'))) })

		cleanup(() => {
			for (const id of timers.keys()) hold(id)
			leaving.forEach(clearTimeout)
			leaving.clear()
		})
	},
	render: ({ html }) => html`
		<div
			class="region"
			part="region"
			role="region"
			data-attr:aria-label="$$label"
			data-attr:data-placement="$$placement"
			data-class:paused="$$paused"
			data-on:pointerenter="@hover(true)"
			data-on:pointerleave="@hover(false)"
			data-on:focusin="@focus(evt)"
			data-on:focusout="@focus()"
		>
			<!-- No id on a repeated element (Datastar's morph is not re-entrant), and
			     r?. everywhere: data-for can re-evaluate a removed row once with r undefined. -->
			<template data-for="r in $$rows">
				<div
					class="toast"
					part="toast"
					data-attr:data-variant="r?.variant"
					data-attr:aria-hidden="r?.leaving && 'true'"
					data-class:alt="r?.alt"
					data-class:leaving="r?.leaving"
					data-style="{'--dur': r?.dur, '--bd': r?.bd, '--in': r?.enter}"
				>
					<span class="light" aria-hidden="true"></span>
					<div>
						<strong class="title" part="title" data-show="r?.title" data-text="r?.title"></strong>
						<div class="text" part="text" data-text="r?.text"></div>
					</div>
					<!-- The button says which toast it closes. -->
					<button class="close" part="close" type="button" data-attr:aria-label="r && 'Dismiss: ' + r.msg" data-attr:tabindex="r?.leaving && -1" data-on:click="@dismiss(r?.id)">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18" /><path d="m6 6 12 12" /></svg>
					</button>
					<div class="bar" part="bar" aria-hidden="true" data-show="r?.dur"></div>
				</div>
			</template>
		</div>
		<!-- Announcing is separate from the stack: the visible toasts move, fade and
		     reorder, which a live region would read again. These two never do. -->
		<div class="sr" part="announcer" role="status" aria-live="polite" data-text="$$polite"></div>
		<div class="sr" part="announcer" role="alert" aria-live="assertive" data-text="$$assertive"></div>
	`,
})
