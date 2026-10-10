import { rocket, startPeeking, stopPeeking } from 'datastar'

// A page effect that calls show() or hide() must not subscribe to our state.
const peek = (fn) => {
	startPeeking()
	try {
		return fn()
	} finally {
		stopPeeking()
	}
}

const styles = /* css */ `
:host {
	--_shadow: var(--sb-shadow-overlay, 0 0 48px -16px rgb(0 0 0 / 0.7));
	--_bg: var(--sb-surface-raised, #10182B);
	--_border: var(--sb-border, #283552);
	--_text: var(--sb-text-1, #F3F4FA);
	--_muted: var(--sb-text-2, #AEBBDD);
	--_overlay: var(--sb-surface-overlay, rgb(5 8 20 / 0.72));
	--_brand: var(--sb-brand, #8C6BFF);
	--_size: var(--sb-drawer-size, 20rem);
	--_ease: 250ms cubic-bezier(0.2, 0, 0, 1);
	display: contents;
}
:host([hidden]) { display: none; }
.panel {
	box-sizing: border-box;
	display: flex;
	flex-direction: column;
	inline-size: auto;
	block-size: auto;
	max-inline-size: 100%;
	max-block-size: 100%;
	margin: 0;
	padding: 0;
	border: 0 solid var(--_border);
	background: var(--_bg);
	color: var(--_muted);
}
.start, .end { inline-size: var(--_size); }
.top, .bottom { block-size: var(--_size); }
.start { inset-inline-end: auto; border-inline-end-width: 1px; --_off: -100%; }
.end { inset-inline-start: auto; border-inline-start-width: 1px; --_off: 100%; }
.start:dir(rtl) { --_off: 100%; }
.end:dir(rtl) { --_off: -100%; }
.top { bottom: auto; border-block-end-width: 1px; --_off: 0 -100%; }
.bottom { top: auto; border-block-start-width: 1px; --_off: 0 100%; }
/* fixed and z-index for a non-modal drawer: only a modal one is in the top layer. */
dialog {
	position: fixed;
	inset: 0;
	z-index: var(--sb-z-overlay, 40);
	box-shadow: var(--_shadow);
	translate: var(--_off);
	transition: translate var(--_ease), display var(--_ease) allow-discrete, overlay var(--_ease) allow-discrete;
}
/* .panel's display would show a closed dialog. */
dialog:not([open]) { display: none; }
dialog[open] { translate: none; }
dialog::backdrop {
	background: var(--_overlay);
	backdrop-filter: blur(2px);
	opacity: 0;
	transition: opacity var(--_ease), display var(--_ease) allow-discrete, overlay var(--_ease) allow-discrete;
}
dialog[open]::backdrop { opacity: 1; }
@starting-style {
	dialog[open] { translate: var(--_off); }
	dialog[open]::backdrop { opacity: 0; }
}
header { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; }
.title { flex: 1; min-inline-size: 0; color: var(--_text); }
h2 { margin: 0; font-size: 1rem; font-weight: 700; }
.body { flex: 1; min-block-size: 0; overflow: auto; overscroll-behavior: contain; padding: 0 1.25rem 1.25rem; font-size: 0.875rem; }
footer { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0.875rem 1.25rem; border-block-start: 1px solid var(--_border); }
.close {
	all: unset;
	display: grid;
	place-items: center;
	inline-size: 1.75rem;
	block-size: 1.75rem;
	border-radius: 4px;
	color: var(--_muted);
	cursor: pointer;
}
.close:hover { color: var(--_text); background: color-mix(in oklch, var(--_text) 8%, transparent); }
.close:focus-visible { outline: 2px solid var(--_brand); }
.close svg { inline-size: 1rem; block-size: 1rem; }
@media (prefers-reduced-motion: reduce) { dialog, dialog::backdrop { transition: none; } }
@media (forced-colors: active) { .close:hover { outline: 1px solid; } }
`

rocket('sb-drawer', {
	props: ({ bool, oneOf, string }) => ({
		heading: string.trim.default('Drawer').docs({ description: 'Title of the drawer (the header slot replaces it).' }),
		side: oneOf('end', 'start', 'top', 'bottom').docs({ description: 'The edge it slides in from. start and end follow the text direction.' }),
		modal: bool.default(true).docs({ description: 'Inert page, backdrop and trapped focus. modal="false" leaves the page usable.' }),
		open: bool.docs({ description: 'The server\'s word: a changed attribute wins (open="false" closes it, also after show()); a removed one is ignored. Emits no sb-open or sb-close. The property returns this word; isOpen tells whether it is showing.' }),
		inline: bool.docs({ description: 'Render in place, always visible, without an overlay or close button (previews, docs).' }),
		closable: bool.default(true).docs({ description: 'Show the close button (not on inline panels).' }),
	}),
	manifest: {
		slots: [
			{ name: 'default', description: 'Drawer body. It scrolls when it is taller than the drawer.' },
			{ name: 'header', description: 'Replaces the heading, next to the close button. It labels the drawer.' },
			{ name: 'footer', description: 'Action buttons, fixed at the bottom. Elements with data-sb-close close the drawer; sb-close reports the attribute\'s value (or the element\'s text) as detail.value.' },
		],
		events: [
			{ name: 'sb-open', kind: 'custom-event', bubbles: true, composed: true, description: 'After show() opened it (not when the server opens it).' },
			{ name: 'sb-close', kind: 'custom-event', bubbles: true, composed: true, description: 'After the user, hide() or close() closed it (not when the server closes it). detail: { reason: button | escape | backdrop | action | api, value }.' },
		],
	},
	setup: ({ $$, action, adoptStyles, cleanup, defineHostProp, emit, host, props }) => {
		adoptStyles(host, styles)
		$$.open = props.open
		$$.footer = false
		// The server's last word on open, or null while it has none, as in
		// sb-modal: only a changed word wins, a removed attribute is ignored.
		let served = host.hasAttribute('open') ? props.open : null
		const watch = new MutationObserver(() => {
			const was = served
			served = host.hasAttribute('open') ? props.open : null
			if (served != null && served !== was) $$.open = served
		})
		watch.observe(host, { attributeFilter: ['open'] })
		cleanup(() => watch.disconnect())
		const show = () =>
			peek(() => {
				if ($$.open) return
				$$.open = true
				emit('sb-open')
			})
		const close = (reason = 'api', value) =>
			peek(() => {
				if (!$$.open) return
				$$.open = false
				emit('sb-close', { reason, value })
			})
		defineHostProp('show', { value: show })
		defineHostProp('hide', { value: close })
		defineHostProp('close', { value: close })
		defineHostProp('isOpen', { get: () => peek(() => $$.open) })

		// Escape in a non-modal drawer (a modal one gets the dialog's cancel,
		// which the browser sends to the top layer). On window, so a control or
		// popover inside that uses the key has had it first; not from inside an
		// open layer nested in the drawer.
		const onKey = (evt) => {
			if (evt.key !== 'Escape' || evt.defaultPrevented || props.modal || !$$.open) return
			const dlg = host.shadowRoot.querySelector('dialog')
			for (const el of evt.composedPath()) {
				if (el === dlg) return evt.preventDefault(), close('escape')
				if (el.matches?.(':popover-open, dialog[open]')) return
			}
		}
		addEventListener('keydown', onKey)
		cleanup(() => removeEventListener('keydown', onKey))

		action('close', (_, reason = 'button') => close(reason))
		// Only our own data-sb-close: one inside a nested drawer or modal is in
		// this host's light DOM too, and closes just that one.
		action('click', ({ evt }) => {
			const btn = evt.target.closest?.('[data-sb-close]')
			if (btn?.closest('sb-drawer,sb-modal') === host) close('action', btn.getAttribute('data-sb-close') || btn.textContent.trim())
		})
	},
	// The id only on the dialog: toggling inline swaps <section> and <dialog>
	// around the same markup, and the morph can't move an id'd element there.
	render: ({ html, props: { heading, side, modal, inline, closable } }) => {
		const inner = html`
			<header>
				<div class="title" id=${inline ? null : 'title'}><slot name="header"><h2 part="heading">${heading}</h2></slot></div>
				${closable && !inline ? html`<button class="close" part="close" type="button" aria-label="Close" data-on:click="@close()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>` : null}
			</header>
			<div class="body" part="body"><slot></slot></div>
			<footer part="footer" data-show="$$footer" data-init="$$footer = !!el.firstChild.assignedElements().length" data-on:slotchange="$$footer = !!el.firstChild.assignedElements().length"><slot name="footer"></slot></footer>
		`
		return inline
			? html`<section class="panel ${side}" part="panel" role="group" aria-label=${heading} data-on:click="@click()">${inner}</section>`
			: // data-preserve-attr and the backdrop's pointerdown: see sb-modal.
			  html`<dialog class="panel ${side}" part="panel" aria-labelledby="title" data-preserve-attr="open"
				data-effect="$$open ? el.open && el.matches(':modal') == ${modal} || (el.close(), el[${modal} ? 'showModal' : 'show']()) : el.close()"
				data-on:cancel="evt.preventDefault(); @close('escape')"
				data-on:pointerdown="$$down = evt.target === el"
				data-on:click="$$down && evt.target === el ? @close('backdrop') : @click()">${inner}</dialog>`
	},
})
