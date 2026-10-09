import { rocket, startPeeking, stopPeeking } from 'datastar'

// Host getters must not subscribe callers (e.g. data-bind's sync effect).
const peek = <T>(fn: () => T): T => {
	startPeeking()
	try {
		return fn()
	} finally {
		stopPeeking()
	}
}

// One ElementInternals per element: attachInternals() works once, and setup
// runs again when the element is re-attached. Its custom states
// (:state(pending)) are styleable from the page and morph-proof.
const internals = new WeakMap<HTMLElement, ElementInternals>()
const internalsOf = (host: HTMLElement) => internals.get(host) ?? internals.set(host, host.attachInternals()).get(host)!

// Options come as strings or {value, label?, description?, disabled?}.
type Option = { value: string; label: string; description: string; disabled: boolean }
const normalize = (list: unknown): Option[] =>
	(Array.isArray(list) ? list : []).map((o) => {
		if (typeof o !== 'object' || !o) o = { value: String(o) }
		return { value: String(o.value ?? o.label ?? ''), label: String(o.label ?? o.value ?? ''), description: String(o.description || ''), disabled: !!o.disabled }
	})

// Case- and accent-insensitive matching.
const fold = (s: string) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()

const anchors = CSS.supports('anchor-name: --a')

// The list sits under the control, as wide as it: CSS anchor positioning in
// @supports, a JS fallback (in setOpen) elsewhere. An empty .note stays rendered
// (no padding) as a status region, so a new note is announced.
const styles = /* css */ `
:host {
	--_notch: var(--sb-notch, 1);
	--_shadow: var(--sb-shadow-overlay, 0 16px 40px -16px rgb(0 0 0 / 0.6));
	--_bg: var(--sb-control-bg, #0B1224);
	--_border: var(--sb-control-border, #283552);
	--_border-hover: var(--sb-control-border-hover, #3A4868);
	--_text: var(--sb-control-text, #F3F4FA);
	--_placeholder: var(--sb-control-placeholder, #7785A8);
	--_label: var(--sb-text-2, #AEBBDD);
	--_muted: var(--sb-text-muted, #7785A8);
	--_panel: var(--sb-surface-raised, #10182B);
	--_hover: var(--sb-surface-hover, #1A2540);
	--_brand: var(--sb-brand, #8C6BFF);
	--_brand-light: var(--sb-brand-light, #B09AFF);
	--_brand-subtle: var(--sb-brand-subtle, rgb(140 107 255 / 0.14));
	--_radius: var(--sb-control-radius, 6px);
	display: block;
	inline-size: 100%;
	max-inline-size: 26rem;
}
:host([hidden]) { display: none; }
.field:has(:disabled) { opacity: 0.5; pointer-events: none; }
.field { display: grid; gap: 0.4rem; }
.label { color: var(--_label); font-size: 0.8125rem; font-weight: 600; }
.control {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.3rem;
	min-block-size: 2.75rem;
	padding-block: 0.3rem;
	padding-inline: 0.5rem 2.25rem;
	box-sizing: border-box;
	border: 1px solid var(--_border);
	border-radius: var(--_radius);
	background: var(--_bg);
	cursor: text;
	position: relative;
	anchor-name: --sb-select;
	transition: border-color 120ms, box-shadow 120ms;
}
.control:hover { border-color: var(--_border-hover); }
.control:focus-within { border-color: var(--_brand-light); box-shadow: 0 0 0 3px var(--_brand-subtle); outline: 2px solid transparent; }
/* The arrow (part="arrow"): a page's ::part(arrow) rules win over these, :state(open) included. */
.arrow {
	position: absolute;
	inset-inline-end: 0.85rem;
	inset-block-start: 50%;
	inline-size: 8px;
	block-size: 6px;
	translate: 0 -50%;
	color: var(--_placeholder);
	background: currentColor;
	clip-path: polygon(0px 0px, 8px 0px, calc(6.667px + 1.333px * var(--_notch)) 2px, calc(6.667px + -0.667px * var(--_notch)) 2px, calc(5.333px + 0.667px * var(--_notch)) 4px, calc(5.333px + -0.333px * var(--_notch)) 4px, calc(4px + 1px * var(--_notch)) 6px, calc(4px + -1px * var(--_notch)) 6px, calc(2.667px + 0.333px * var(--_notch)) 4px, calc(2.667px + -0.667px * var(--_notch)) 4px, calc(1.333px + 0.667px * var(--_notch)) 2px, calc(1.333px + -1.333px * var(--_notch)) 2px); /* stepped at notch 1, a triangle at 0 */
}
.open .arrow { rotate: 180deg; }
.chip {
	display: inline-flex;
	align-items: center;
	gap: 0.25rem;
	min-inline-size: 0;
	padding-block: 0.15rem;
	padding-inline: 0.5rem 0.2rem;
	border: 1px solid transparent;
	border-radius: calc(var(--_radius) - 2px);
	background: var(--_brand-subtle);
	color: var(--_text);
	font-size: 0.8125rem;
}
.chip > span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chip button { flex: none; }
.more { flex: none; padding-inline: 0.5rem; }
.sum { flex: 0 1 auto; min-inline-size: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-inline: 0.35rem; color: var(--_text); font-size: 0.875rem; }
.sum:empty { display: none; }
/* max-chips or summary: one line, labels cut short */
.compact { flex-wrap: nowrap; overflow: hidden; }
.compact .chip:not(.more) { flex: 0 1 auto; max-inline-size: 10rem; }
.compact input { min-inline-size: 2ch; }
.chip button, .clear { all: unset; display: grid; place-items: center; inline-size: 1.1rem; block-size: 1.1rem; border-radius: 3px; color: var(--_muted); cursor: pointer; }
.chip button:hover, .clear:hover { color: var(--_text); background: var(--_hover); }
input {
	all: unset;
	flex: 1;
	min-inline-size: 5ch;
	block-size: 2rem;
	padding-inline: 0.35rem;
	color: var(--_text);
}
input::placeholder { color: var(--_placeholder); }
input[readonly] { cursor: pointer; }
.clear { position: absolute; inset-inline-end: 2rem; inline-size: 1.25rem; block-size: 1.25rem; }
.spin { position: absolute; inset-inline-end: 2rem; inline-size: 12px; block-size: 12px; background: var(--_brand-light); animation: spin 0.6s steps(calc(4 + 996 * (1 - var(--_notch)))) infinite; clip-path: polygon(0 0, calc(4px * var(--_notch)) 0, calc(4px * var(--_notch)) calc(4px * var(--_notch)), 0 calc(4px * var(--_notch)), 0 0, calc(8px * var(--_notch)) calc(8px * var(--_notch)), calc(12px * var(--_notch)) calc(8px * var(--_notch)), calc(12px * var(--_notch)) calc(12px * var(--_notch)), calc(8px * var(--_notch)) calc(12px * var(--_notch)), calc(8px * var(--_notch)) calc(8px * var(--_notch))); opacity: var(--_notch); } /* the pixel spinner, for notch 1 */
/* The smooth spinner, for notch 0: a ring turning at a steady speed. */
.ring { position: absolute; inset-inline-end: 2rem; inline-size: 12px; block-size: 12px; box-sizing: border-box; border: 2px solid var(--_brand-light); border-inline-end-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite; opacity: calc(1 - var(--_notch)); }
@keyframes spin { to { rotate: 360deg; } }
[popover] {
	margin: 0;
	padding: 4px;
	border: 1px solid var(--_border);
	border-radius: var(--_radius);
	background: var(--_panel);
	color: var(--_text);
	box-shadow: var(--_shadow);
	max-block-size: min(18rem, 50dvh);
	overflow: auto;
	box-sizing: border-box;
}
@supports (anchor-name: --a) {
	[popover] {
		position-anchor: --sb-select;
		inset: auto;
		position-area: bottom span-right;
		inline-size: anchor-size(width);
		margin-block-start: 4px;
		position-try-fallbacks: flip-block;
	}
}
[role=option] { display: grid; gap: 0.1rem; padding: 0.45rem 0.6rem; border-radius: calc(var(--_radius) - 2px); cursor: pointer; }
[role=option][aria-disabled=true] { opacity: 0.45; cursor: default; }
[role=option].active { background: var(--_hover); box-shadow: inset 2px 0 0 var(--_brand); outline: 2px solid transparent; outline-offset: -2px; }
[role=option].active:dir(rtl) { box-shadow: inset -2px 0 0 var(--_brand); }
[role=option][aria-selected=true] { color: var(--_brand-light); font-weight: 600; }
.acts { display: grid; margin-block-end: 4px; padding-block-end: 4px; border-block-end: 1px solid var(--_border); }
.act { color: var(--_label); font-size: 0.8125rem; }
.desc { color: var(--_muted); font-size: 0.75rem; font-weight: 400; }
.note { padding: 0.6rem; color: var(--_muted); font-size: 0.8125rem; }
.note:empty { padding: 0; }
@media (prefers-reduced-motion: reduce) { .spin, .ring { animation: none; } }
@media (forced-colors: active) { .arrow, .spin { forced-color-adjust: none; background: CanvasText; } .ring { border-color: CanvasText; border-inline-end-color: transparent; } }
`

rocket('sb-select', {
	props: ({ bool, json, number, string }) => ({
		options: json.default(() => []).docs({ description: 'Choices: ["A", "B"] or [{value, label, description?, disabled?}].' }),
		results: json.default(() => []).docs({ description: 'Remote: the results of the current search, in the same shape. The server sets it (a signal patch through data-attr, or a morph).' }),
		value: string.docs({ description: 'The value; for multiple, a JSON array or values separated by commas. A new value from the server replaces it; the live value is the value property.' }),
		label: string.trim.docs({ description: 'Visible label.' }),
		placeholder: string.docs({ description: 'Placeholder text.' }),
		multiple: bool.docs({ description: 'Pick several; they show as chips.' }),
		searchable: bool.docs({ description: 'Type to filter the options (in the browser).' }),
		remote: bool.docs({ description: 'Type to search on the server: emits sb-search; the server answers with results.' }),
		delay: number.clamp(0, 2000).default(250).docs({ description: 'Remote: debounce before sb-search, in ms.' }),
		minChars: number.clamp(0, 10).default(1).docs({ description: 'Remote: characters needed before searching.' }),
		loading: bool.docs({ description: 'Show that results are on their way (bind it to data-indicator).' }),
		clearable: bool.docs({ description: 'Show a button that clears the value.' }),
		disabled: bool.docs({ description: 'Disable the control.' }),
		name: string.trim.docs({ description: 'Name reported in sb-change (e.g. the field of a command) and submitted with its form.' }),
		summary: string.docs({ description: 'Multiple: a text shown instead of chips, e.g. "{count} of {total} sources". {count} is the number picked, {more} those not shown as chips, {total} the options (or the total prop). With max-chips, it shows only beyond that many picks, after the chips.' }),
		maxChips: number.clamp(-1, 1000).default(-1).docs({ description: 'Multiple: show at most this many chips on one line, then a "+K" chip (or the summary). 0 shows only the count; -1 (the default) shows every chip and wraps.' }),
		total: number.clamp(-1, 1e9).default(-1).docs({ description: 'Summary: what {total} stands for, e.g. the number of results a remote search can reach. -1 (the default): the options it knows (remote: every option it has been offered).' }),
		actions: bool.docs({ description: 'Multiple: "Select all" (while a search filters: "Select the N matches") and "Clear" rows at the top of the list.' }),
		selectAllLabel: string.default('Select all').docs({ description: 'Actions: the text of "Select all".' }),
		matchesLabel: string.default('Select the match|Select the {count} matches').docs({ description: 'Actions: the text of "Select the N matches" while a search filters the list. Text before a | is for one match.' }),
		clearLabel: string.default('Clear').docs({ description: 'Actions: the text of "Clear", also the clear button\'s accessible name.' }),
		confirm: bool.docs({ description: 'Server-confirmed value: :state(pending) while the local value differs from the server\'s value attribute (see revert()).' }),
	}),
	manifest: {
		events: [
			{ name: 'sb-search', kind: 'custom-event', bubbles: true, composed: true, description: 'Remote: the query changed (debounced). detail: { query }. Answer by setting results.' },
			{ name: 'change', kind: 'event', bubbles: true, composed: true, description: 'The value changed.' },
			{ name: 'sb-change', kind: 'custom-event', bubbles: true, composed: true, description: 'The value changed. detail: { name, value } (a string, or an array for multiple): ready for a command.' },
		],
	},
	// Rendered once: options, query and selection all flow through signals,
	// so updates (e.g. server results while typing) never rebuild the input.
	renderOnPropChange: false,
	setup: ({ $$, action, adoptStyles, cleanup, defineHostProp, effect, emit, host, observeProps, overrideProp, props }) => {
		adoptStyles(host, styles)
		const parseValue = (v: unknown): string[] => {
			if (Array.isArray(v)) return v.map(String)
			const s = String(v ?? '').trim()
			if (!s) return []
			if (s.startsWith('[')) {
				try {
					return JSON.parse(s).map(String)
				} catch {}
			}
			return props.multiple ? s.split(',').map((x) => x.trim()).filter(Boolean) : [s]
		}
		// Labels of everything ever offered, so a selection keeps its label
		// when remote results move on.
		const labels = new Map<string, string>()
		let options: Option[] = [] // what the list offers: options, or results when remote
		// Take in the props: the options, and the signals the template reads
		// (it is rendered once).
		const learn = () => {
			options = normalize(props.remote ? props.results : props.options)
			for (const o of options) labels.set(o.value, o.label)
			for (const k of ['label', 'placeholder', 'multiple', 'loading', 'clearable', 'disabled', 'clearLabel'] as const) $$[k] = props[k]
			$$.typing = props.searchable || props.remote
		}
		learn()

		$$.selected = parseValue(props.value)
		$$.query = ''
		$$.open = false
		$$.active = -1 // index into the rows: $$.acts, then $$.view
		$$.view = []
		$$.acts = [] // the list actions, rendered before the options
		$$.base = 0 // $$.acts.length: the row index of the first option
		$$.note = ''
		$$.pending = false // remote: typed, waiting for the debounce

		// What the input shows: the query while typing, else (single) the label.
		// Never read a missing index of a signal array: that creates it ("" at [0]
		// of an empty list). And signals are gone while the element is detached.
		// (Computed lazily: $$.chips comes from the refresh() below.)
		$$.text = () => ($$.typing && ($$.open || $$.multiple) ? $$.query : $$.multiple || !$$.chips?.length ? '' : $$.chips[0].label)
		type Row = { id: string; value?: string; label: string; disabled: boolean; act?: string }
		// The highlighted row: an action or an option.
		const at = (): Row | undefined => {
			const k = $$.active - $$.base
			return k < 0 ? $$.acts?.find((_: unknown, i: number) => i === $$.active) : $$.view?.find((_: unknown, i: number) => i === k)
		}
		$$.current = () => ($$.open ? at()?.id : '') || ''

		const refresh = () => {
			const q = fold($$.query.trim())
			// Remote: the results belong to the query, and a short one has none.
			const short = props.remote && $$.query.trim().length < props.minChars
			const view = short ? [] : props.searchable && !props.remote && q ? options.filter((o) => fold(o.label).includes(q) || fold(o.description).includes(q)) : options
			$$.view = view.map((o, i) => ({ ...o, id: 'o' + i, selected: $$.selected.includes(o.value) }))
			const acts: Row[] = []
			if (props.multiple && props.actions) {
				const open = view.filter((o) => !o.disabled)
				const filtered = props.remote ? !!$$.query.trim() : props.searchable && !!q
				const [one, many] = props.matchesLabel.includes('|') ? props.matchesLabel.split('|') : [props.matchesLabel, props.matchesLabel]
				const all = filtered ? (open.length === 1 ? one : many).replaceAll('{count}', String(open.length)) : props.selectAllLabel
				if (view.length) acts.push({ act: 'all', id: 'a-all', label: all, disabled: open.every((o) => $$.selected.includes(o.value)) })
				acts.push({ act: 'clear', id: 'a-clear', label: props.clearLabel, disabled: !$$.selected.length })
			}
			$$.acts = acts
			$$.base = acts.length
			if ($$.active >= acts.length + view.length) $$.active = view.length ? acts.length : -1
			$$.note = short ? 'Type to search' : view.length ? '' : props.loading || $$.pending ? 'Searching…' : 'No results'
			// The closed control: chips up to max-chips (none with only a summary),
			// then the summary or a "+K" chip.
			const chips = $$.selected.map((v: string) => ({ value: v, label: labels.get(v) ?? v }))
			const max = !props.multiple ? Infinity : props.maxChips >= 0 ? props.maxChips : props.summary ? 0 : Infinity
			const more = Math.max(0, chips.length - max)
			const total = props.total >= 0 ? props.total : props.remote ? labels.size : options.length
			$$.chips = chips
			$$.shown = chips.slice(0, max)
			$$.compact = max !== Infinity
			$$.more = more && !props.summary ? '+' + more : ''
			$$.hidden = chips.slice(max).map((c: { label: string }) => c.label).join(', ')
			$$.sum = more && props.summary ? props.summary.replace(/\{(count|more|total)\}/g, (_, k: string) => String({ count: chips.length, more, total }[k])) : ''
			// Read with the combobox (aria-describedby): the whole selection.
			$$.picked = props.multiple ? chips.map((c: { label: string }) => c.label).join(', ') : ''
		}
		refresh()

		// peek: attribute changes arrive inside the effect of whoever set them.
		observeProps(() =>
			peek(() => {
				learn()
				if (props.disabled) setOpen(false)
				if (props.remote && $$.open && $$.active < 0 && options.length) $$.active = 0
				refresh()
			}),
		)

		const value = (s: string[] = [...$$.selected]) => (props.multiple ? s : (s[0] ?? ''))
		overrideProp('value', () => peek(value), (v) => peek(() => (($$.selected = parseValue(v)), refresh())))
		// Commands: the attribute is the server's value, JSON.stringify($$.selected) the local one.
		// With confirm, :state(pending) marks an edit the server hasn't confirmed
		// yet; revert() returns to the server's value (e.g. a rejected command).
		const states = internalsOf(host).states
		const sync = () => peek(() => (props.confirm && JSON.stringify($$.selected) !== JSON.stringify(parseValue(props.value)) ? states.add('pending') : states.delete('pending')))
		effect(() => (JSON.stringify($$.selected), sync()))
		observeProps(sync)
		// :state(open) follows the list, whichever way it opens or closes.
		effect(() => states[$$.open ? 'add' : 'delete']('open'))
		cleanup(() => states.delete('open'))
		// A new value attribute from the server wins, value="" included. Watched on
		// the attribute: observeProps stays silent when the decoded value did not
		// change (value="" on an element that never had one). A removed attribute
		// is ignored (morphs also strip reflected ones; see sb-slider), and the
		// same value again leaves the user's edit alone.
		let served: string | null = host.hasAttribute('value') ? props.value : null
		const watch = new MutationObserver(() =>
			peek(() => {
				if (!host.hasAttribute('value')) return void (served = null)
				if (props.value === served) return
				served = props.value
				$$.selected = parseValue(served) // the effect above syncs pending
				refresh()
			}),
		)
		watch.observe(host, { attributeFilter: ['value'] })
		cleanup(() => watch.disconnect())
		// sync() too: inside a Datastar expression the effect runs only at its end.
		defineHostProp('revert', { value: () => peek(() => (($$.selected = parseValue(props.value), refresh()), sync())) })

		// Forms: until Rocket can make this element form-associated, join the
		// submissions and resets of the form it sits in. `formdata` also fires for
		// new FormData(form), so Datastar's contentType: 'form' posts include it.
		// Both are heard on the root (document or shadow root), once every
		// listener on the form has run: a reset the page cancelled (whenever its
		// listener was added) leaves the value, as it leaves native fields, and a
		// form nested in this one by a script, whose events bubble through it,
		// isn't taken for it. setup reruns on a re-attach, so a move follows.
		// Like <select>: one entry per picked value with multiple (none when
		// nothing is picked), else one, "" when nothing is. A reset is revert()
		// (the server's value, no events), and a typed search goes.
		const form = host.closest('form')
		const root = host.getRootNode()
		const onData = (evt: Event) => evt.target === form && peek(() => props.name && !props.disabled && [value()].flat().forEach((v) => (evt as FormDataEvent).formData.append(props.name, v)))
		const onReset = (evt: Event) => evt.target === form && !evt.defaultPrevented && (($$.query = ''), (host as unknown as { revert(): void }).revert())
		root.addEventListener('formdata', onData)
		root.addEventListener('reset', onReset)
		cleanup(() => (root.removeEventListener('formdata', onData), root.removeEventListener('reset', onReset)))

		const $ = <E extends Element = HTMLElement>(s: string) => host.shadowRoot?.querySelector<E>(s) // in the rendered template
		// Keep the highlighted option in view.
		const show = () => requestAnimationFrame(() => host.shadowRoot?.getElementById(at()?.id as string)?.scrollIntoView({ block: 'nearest' }))
		const setOpen = (open: boolean) => {
			if (open === $$.open || (open && props.disabled)) return
			$$.open = open
			const p = $('[popover]')
			try {
				if (open) {
					p!.showPopover()
					// Without CSS anchor positioning: put the list under the control.
					if (!anchors) {
						const r = $('.control')!.getBoundingClientRect()
						Object.assign(p!.style, { position: 'fixed', inset: 'auto', left: r.left + 'px', top: r.bottom + 4 + 'px', width: r.width + 'px' })
					}
				} else p!.hidePopover()
			} catch {}
			if (!open && !props.multiple) $$.query = ''
			if (open) ($$.active = $$.view.length ? $$.base + Math.max(0, $$.view.findIndex((o: { selected: boolean }) => o.selected)) : -1), search(), show()
			refresh()
		}
		const change = () => {
			refresh()
			emit('change')
			emit('sb-change', { name: props.name, value: value() })
		}
		const pick = (v: string | undefined) => {
			const o = options.find((x) => x.value === v)
			if (!o || o.disabled) return
			if (props.multiple) {
				$$.selected = $$.selected.includes(v) ? $$.selected.filter((x: string) => x !== v) : [...$$.selected, v]
				$$.query = ''
				change()
				search()
			} else {
				$$.selected = [v]
				change()
				setOpen(false)
			}
		}

		// A list action: one change with the whole new value; the list stays open.
		const act = (kind: string | undefined) => {
			if (kind === 'all') {
				const add = $$.view.filter((o: Row & { selected: boolean }) => !o.disabled && !o.selected).map((o: Row) => o.value)
				if (!add.length) return
				$$.selected = [...$$.selected, ...add]
			} else if (kind === 'clear') {
				if (!$$.selected.length) return
				$$.selected = []
			} else return
			change()
		}

		let timer = 0
		const search = () => {
			if (!props.remote) return
			clearTimeout(timer)
			const q = $$.query.trim()
			if (q.length < props.minChars) return ($$.pending = false)
			$$.pending = true
			// The spinner covers the debounce; the request itself is loading's
			// (data-indicator): an answer that changes nothing can't be seen.
			timer = setTimeout(() => (emit('sb-search', { query: q }), ($$.pending = false), refresh()), props.delay)
		}
		cleanup(() => clearTimeout(timer))

		action('type', ({ el, evt }) => {
			evt!.stopPropagation() // a query is not a value: no input event on the host
			$$.query = (el as HTMLInputElement).value
			setOpen(true)
			search() // before refresh: the note says "Searching…" during the pause
			refresh()
			$$.active = $$.view.length ? $$.base : -1 // the first match, after setOpen's selected one
		})
		// Clicks on the chips' and the clear button never get here: they stop there.
		action('toggle', () => {
			$('input')?.focus()
			setOpen(!$$.open)
		})
		action('pick', ({ evt }, v: string) => {
			const e = evt as MouseEvent
			e.preventDefault() // keep focus in the input
			e.button || pick(v) // the main button only
		})
		action('act', ({ evt }, kind: string) => {
			const e = evt as MouseEvent
			e.preventDefault()
			e.button || act(kind)
		})
		action('remove', ({ evt }, v: string) => {
			evt!.stopPropagation()
			$$.selected = $$.selected.filter((x: string) => x !== v)
			change()
		})
		action('clear', ({ evt }) => {
			evt!.stopPropagation()
			$$.selected = []
			$$.query = ''
			change()
			$('input')?.focus()
		})
		// Removing a focused select blurs it too: its signals are gone by then.
		action('blur', () => setTimeout(() => host.isConnected && (host.shadowRoot!.activeElement || setOpen(false)), 0))
		let buf = '' // type-ahead, without searchable or remote
		let typer = 0
		action('key', ({ evt: e }) => {
			const evt = e as KeyboardEvent
			const n = $$.base + $$.view.length
			// Space opens and picks like Enter, unless it is typed text.
			switch (evt.key === ' ' && !$$.typing && !buf ? 'Enter' : evt.key) {
				case 'ArrowDown':
				case 'ArrowUp':
					if (!$$.open) setOpen(true)
					else if (n) $$.active = ($$.active + (evt.key === 'ArrowUp' ? n - 1 : 1)) % n
					break
				case 'Home':
				case 'End':
					if (!$$.open || !n) return
					$$.active = evt.key === 'Home' ? 0 : n - 1
					break
				case 'Enter':
					if (!$$.open) setOpen(true)
					else {
						const r = at() // nothing highlighted: picks nothing
						r?.act ? act(r.act) : pick(r?.value)
					}
					break
				case 'Escape':
					if (!$$.open) return
					setOpen(false)
					break
				case 'Backspace': {
					// The last chip shown: never a pick hidden behind "+K" or the summary.
					const n = $$.shown?.length
					if (!props.multiple || $$.query || !n) return
					const last = $$.shown[n - 1].value
					$$.selected = $$.selected.filter((x: string) => x !== last)
					change()
					break
				}
				case 'Tab':
					setOpen(false)
					return
				default: {
					// Type-ahead: a letter moves to the next option that starts with
					// it (the same letter again cycles), more letters refine the match.
					if ($$.typing || evt.key.length > 1 || evt.ctrlKey || evt.metaKey || evt.altKey) return
					clearTimeout(typer)
					typer = setTimeout(() => (buf = ''), 500)
					const q = (buf += fold(evt.key)).replace(/^(.)\1+$/, '$1')
					setOpen(true)
					const a = $$.active - $$.base - Number(q.length > 1) // search after it, or from it
					const j = [...$$.view, ...$$.view].findIndex((o: Option, i: number) => i > a && fold(o.label).startsWith(q))
					if (j >= 0) $$.active = $$.base + (j % $$.view.length)
				}
			}
			evt.preventDefault()
			show()
		})
	},
	render: ({ html }) => html`
		<div class="field" data-class:open="$$open">
			<label class="label" part="label" for="input" data-show="$$label" data-text="$$label"></label>
			<div class="control" part="control" data-class:compact="$$compact" data-on:click="@toggle()">
				<template data-for="c in $$shown">
					<span class="chip" part="chip" data-show="$$multiple">
						<span data-text="c?.label"></span>
						<button type="button" tabindex="-1" data-attr:aria-label="'Remove ' + c?.label" data-on:mousedown="evt.preventDefault()" data-on:click="@remove(c?.value)">×</button>
					</span>
				</template>
				<span class="chip more" part="more" data-show="$$more" data-text="$$more" data-attr:title="$$hidden"></span>
				<span class="sum" part="summary" data-text="$$sum"></span>
				<span id="picked" hidden data-text="$$picked"></span>
				<input id="input" part="input" role="combobox" autocomplete="off" spellcheck="false"
					aria-controls="list"
					data-attr:aria-autocomplete="$$typing && 'list'"
					data-attr:aria-label="$$label ? null : ($$placeholder || 'Select')"
					data-attr:aria-expanded="String($$open)"
					data-attr:aria-describedby="$$picked ? 'picked' : null"
					data-attr:aria-activedescendant="$$current || null"
					data-attr:aria-busy="$$loading ? 'true' : null"
					data-attr:readonly="!$$typing"
					data-attr:disabled="$$disabled"
					data-attr:placeholder="$$multiple && $$chips?.length ? null : $$placeholder"
					data-effect="el.value !== $$text && (el.value = $$text)"
					data-on:input="@type()"
					data-on:keydown="@key()"
					data-on:blur="@blur()"/>
				<span class="spin" aria-hidden="true" data-show="$$loading || $$pending"></span>
				<span class="ring" aria-hidden="true" data-show="$$loading || $$pending"></span>
				<span class="arrow" part="arrow" aria-hidden="true"></span>
				<button type="button" class="clear" part="clear" data-attr:aria-label="$$clearLabel" tabindex="-1"
					data-show="$$clearable && $$chips?.length && !$$loading && !$$pending" data-on:click="@clear()">×</button>
			</div>
			<div id="list" part="listbox" popover="manual" role="listbox"
				data-attr:aria-multiselectable="$$multiple ? 'true' : null"
				data-attr:aria-label="$$label || $$placeholder || 'Options'">
				<div class="acts" role="group" data-show="$$acts?.length"><template data-for="a, k in $$acts">
					<div role="option" class="act"
						data-attr:id="a?.id"
						data-attr:aria-disabled="a?.disabled ? 'true' : null"
						data-class:active="k === $$active"
						data-on:mousedown="@act(a?.act)"
						data-on:mousemove="$$active = k"
						data-text="a?.label"></div>
				</template></div>
				<template data-for="o, i in $$view">
					<div role="option"
						data-attr:id="o?.id"
						data-attr:aria-selected="String(!!o?.selected)"
						data-attr:aria-disabled="o?.disabled ? 'true' : null"
						data-class:active="i + $$base === $$active"
						data-on:mousedown="@pick(o?.value)"
						data-on:mousemove="$$active = i + $$base">
						<span data-text="o?.label"></span>
						<span class="desc" data-show="o?.description" data-text="o?.description"></span>
					</div>
				</template>
				<div class="note" role="status" data-text="$$note"></div>
			</div>
		</div>
	`,
})
