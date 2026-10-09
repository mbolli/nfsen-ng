import { rocket } from 'datastar'

const styles = /* css */ `
:host {
	--_shadow: var(--sb-shadow-overlay, 0 24px 48px -16px rgb(0 0 0 / 0.7));
	--_bg: var(--sb-surface-raised, #10182B);
	--_border: var(--sb-border, #283552);
	--_text: var(--sb-text-1, #F3F4FA);
	--_muted: var(--sb-text-2, #AEBBDD);
	--_overlay: var(--sb-surface-overlay, rgb(5 8 20 / 0.72));
	--_brand: var(--sb-brand, #8C6BFF);
	--_radius: var(--sb-radius, 8px);
	display: contents;
}
:host([hidden]) { display: none; }
.panel {
	box-sizing: border-box;
	inline-size: min(28rem, 100vw - 2rem);
	padding: 0;
	border: 1px solid var(--_border);
	border-radius: var(--_radius);
	background: var(--_bg);
	color: var(--_muted);
	box-shadow: var(--_shadow);
}
.inline { display: block; inline-size: 100%; max-inline-size: 28rem; }
dialog[open] { animation: pop 180ms cubic-bezier(0.2, 0, 0, 1); }
dialog::backdrop { background: var(--_overlay); backdrop-filter: blur(2px); }
header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem 0; }
h2 { margin: 0; color: var(--_text); font-size: 1rem; font-weight: 700; }
.body { padding: 0.5rem 1.25rem 1.25rem; font-size: 0.875rem; }
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
@keyframes pop { from { opacity: 0; scale: 0.96; } }
@media (prefers-reduced-motion: reduce) { dialog[open] { animation: none; } }
`

rocket('sb-modal', {
	props: ({ bool, string }) => ({
		heading: string.trim.default('Dialog').docs({ description: 'Title of the dialog.' }),
		open: bool.docs({ description: 'The server\'s word: a changed attribute wins (open="false" closes it, also after show()); a removed one is ignored. Emits no sb-open or sb-close. The property returns this word; isOpen tells whether it is showing.' }),
		inline: bool.docs({ description: 'Render in place, always visible, without an overlay or close button (previews, docs).' }),
		closable: bool.default(true).docs({ description: 'Show the close button (not on inline panels).' }),
	}),
	manifest: {
		slots: [
			{ name: 'default', description: 'Dialog body.' },
			{ name: 'footer', description: 'Action buttons. Elements with data-sb-close close the dialog; sb-close reports the attribute\'s value (or the element\'s text) as detail.value.' },
		],
		events: [
			{ name: 'sb-open', kind: 'custom-event', bubbles: true, composed: true, description: 'After show() opened it (not when the server opens it).' },
			{ name: 'sb-close', kind: 'custom-event', bubbles: true, composed: true, description: 'After the user or close() closed it (not when the server closes it). detail: { reason: button | escape | backdrop | action | api, value }.' },
		],
	},
	setup: ({ $$, action, adoptStyles, cleanup, defineHostProp, emit, host, props }) => {
		adoptStyles(host, styles)
		$$.open = props.open
		$$.footer = false
		// The server's last word on open, or null while it has none. Only a
		// *different* word wins, so re-sending the same markup never reopens a
		// dialog the user dismissed. A removed attribute is ignored (morphs also
		// strip reflected ones): to close, the server sends open="false".
		// Why not observeProps: it only fires when the decoded value changes, so
		// open="false" after show() (the prop is still false) would go unnoticed.
		// No peek(): the callback is a microtask, outside any effect.
		let served = host.hasAttribute('open') ? props.open : null
		const watch = new MutationObserver(() => {
			const was = served
			served = host.hasAttribute('open') ? props.open : null
			if (served != null && served !== was) $$.open = served
		})
		watch.observe(host, { attributeFilter: ['open'] })
		cleanup(() => watch.disconnect())
		const show = () => {
			if ($$.open) return
			$$.open = true
			emit('sb-open')
		}
		const close = (reason = 'api', value) => {
			if (!$$.open) return
			$$.open = false
			emit('sb-close', { reason, value })
		}
		defineHostProp('show', { value: show })
		defineHostProp('close', { value: close })
		defineHostProp('isOpen', { get: () => $$.open })

		action('close', (_, reason = 'button') => close(reason))
		// Elements marked data-sb-close close the dialog and report their value
		// (or their text). Only our own: one in a nested sb-modal's or
		// sb-drawer's light DOM is inside this host too, and closes just that one.
		action('click', ({ evt }) => {
			const btn = evt.target.closest?.('[data-sb-close]')
			if (btn?.closest('sb-modal,sb-drawer') === host) close('action', btn.getAttribute('data-sb-close') || btn.textContent.trim())
		})
	},
	// No ids in here: toggling inline swaps <section> and <dialog> around the
	// same markup, and the morph can't move an id'd element into a parent of
	// another type. aria-label names the panel instead.
	render: ({ html, props: { heading, inline, closable } }) => {
		const inner = html`
			<header>
				<h2 part="heading">${heading}</h2>
				${closable && !inline ? html`<button class="close" part="close" type="button" aria-label="Close" data-on:click="@close()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>` : null}
			</header>
			<div class="body" part="body"><slot></slot></div>
			<footer part="footer" data-show="$$footer" data-init="$$footer = !!el.firstChild.assignedElements().length" data-on:slotchange="$$footer = !!el.firstChild.assignedElements().length"><slot name="footer"></slot></footer>
		`
		return inline
			? html`<section class="panel inline" part="panel" role="group" aria-label=${heading} data-on:click="@click()">${inner}</section>`
			: // data-preserve-attr: showModal() sets open on the dialog, and a
			  // re-render (any prop change) would strip it, leaving an invisible
			  // dialog in the top layer that makes the whole page inert. A dialog
			  // that comes back open but not modal (moved without moveBefore) is
			  // closed and shown again as a modal.
			  // A click on the backdrop (the dialog element itself) closes it, but
			  // only when the press started there too: selecting text in the body
			  // and releasing over the backdrop also clicks the dialog (the common
			  // ancestor), and must not throw away what the user typed.
			  html`<dialog class="panel" part="panel" aria-label=${heading} data-preserve-attr="open"
				data-effect="$$open ? el.matches(':modal') || (el.close(), el.showModal()) : el.close()"
				data-on:cancel="evt.preventDefault(); @close('escape')"
				data-on:pointerdown="$$down = evt.target === el"
				data-on:click="$$down && evt.target === el ? @close('backdrop') : @click()">${inner}</dialog>`
	},
})
