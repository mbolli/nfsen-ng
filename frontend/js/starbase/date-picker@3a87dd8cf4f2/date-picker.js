// Generated from date-picker.ts by `go tool task ts`: edit the TypeScript, not this file.
import { rocket, startPeeking, stopPeeking } from 'datastar';
// Host getters must not subscribe callers (e.g. data-bind's sync effect), and
// attribute changes arrive inside the effect of whoever set them.
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
// onFirstRender runs again when the element is re-attached. Its custom states
// (:state(pending)) are styleable from the page and morph-proof.
const internals = new WeakMap();
const internalsOf = (host) => internals.get(host) ?? internals.set(host, host.attachInternals()).get(host);
// Pixel corners: notches every corner by p (2px times --sb-notch; at 0 the
// border-radius takes over).
const notch = (p) => `polygon(${p} 0, calc(100% - ${p}) 0, calc(100% - ${p}) ${p}, 100% ${p}, 100% calc(100% - ${p}), calc(100% - ${p}) calc(100% - ${p}), calc(100% - ${p}) 100%, ${p} 100%, ${p} calc(100% - ${p}), 0 calc(100% - ${p}), 0 ${p}, ${p} ${p})`;
// A malformed tag (en_US) would make Intl throw: repair it, or use the default.
const locale = (tag) => { try {
    return Intl.getCanonicalLocales(tag?.replace(/_/g, '-') || [])[0];
}
catch { } };
// Dates are whole days since 1970-01-01 in UTC: no time zone or DST in the way.
const DAY = 864e5;
const ymd = (y, m, d) => Date.UTC(y, m, d) / DAY; // m from 0; overflow rolls over
const iso = (n) => new Date(n * DAY).toISOString().slice(0, 10);
const parts = (n) => {
    const d = new Date(n * DAY);
    return [d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()];
};
// An ISO date to its day, or null: 2026-02-31 is no date.
const day = (s) => {
    const m = /^(\d{4})-(\d\d)-(\d\d)$/.exec(String(s ?? '').trim());
    const n = m && ymd(+m[1], +m[2] - 1, +m[3]);
    return m && iso(n) === m[0] ? n : null;
};
// The same day k months on, or that month's last day when it is shorter.
const addMonths = (n, k) => {
    const [y, m, d] = parts(n);
    return ymd(y, m + k, Math.min(d, parts(ymd(y, m + k + 1, 0))[2]));
};
const today = () => ((n) => ymd(n.getFullYear(), n.getMonth(), n.getDate()))(new Date());
const lohi = (a, b) => (a > b ? [b, a] : [a, b]);
const anchors = CSS.supports('anchor-name: --a');
// A date-time is its wall clock as UTC ms. local: what datetime-local accepts
// (no zone, at most three fraction digits, dropped); off: a Z or offset in ms.
const stampRe = /^(\d{4}-\d\d-\d\d)([Tt ])(\d\d):(\d\d)(?::(\d\d)(?:\.(\d+))?)?([Zz]|([+-])(\d\d):(\d\d))?$/;
const clock = (s) => {
    const m = stampRe.exec(String(s ?? '').trim());
    const n = m && day(m[1]);
    if (!m || n == null || +m[3] > 23 || +m[4] > 59 || +m[5] > 59 || +m[9] > 23 || +m[10] > 59)
        return null;
    return {
        w: n * DAY + (+m[3] * 3600 + +m[4] * 60 + (+m[5] || 0)) * 1e3,
        local: m[2] !== 't' && !m[7] && !((m[6]?.length ?? 0) > 3),
        off: m[7] ? (m[8] === '-' ? -6e4 : 6e4) * ((+m[9] || 0) * 60 + (+m[10] || 0)) : null,
    };
};
// The normalised form, seconds only when they aren't zero, and the seconds of the day.
const stamp = (w) => {
    const s = new Date(w).toISOString();
    return s.slice(0, 16) + (s.slice(17, 19) === '00' ? '' : s.slice(16, 19));
};
const sod = (w) => (w - Math.floor(w / DAY) * DAY) / 1e3;
// The viewer's zone, and a zone Intl can read (else the viewer's).
const viewerZone = new Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
const zone = (tz) => {
    try {
        return tz === 'local' ? viewerZone : new Intl.DateTimeFormat('en', { timeZone: tz }).resolvedOptions().timeZone;
    }
    catch {
        return viewerZone;
    }
};
// wall is the instant t on the zone's clock, read as if that clock were UTC.
const clocks = new Map();
const wall = (t, tz) => {
    let c = clocks.get(tz);
    if (!c)
        clocks.set(tz, (c = new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', calendar: 'gregory', numberingSystem: 'latn', year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' })));
    const f = {};
    for (const { type, value } of c.formatToParts(t))
        f[type] = +value;
    return Date.UTC(f.year, f.month - 1, f.day, f.hour, f.minute, f.second, ((t % 1e3) + 1e3) % 1e3);
};
// The instant a wall clock w shows: in an overlap the earlier one, in a gap
// the one after it (Temporal's "compatible").
const instant = (w, tz) => {
    const [a, b] = [w - DAY, w + DAY].map((t) => w - (wall(t, tz) - t));
    const fit = [a, b].filter((t) => wall(t, tz) === w);
    return fit.length ? Math.min(...fit) : a;
};
const offset = (o) => ((o = Math.round(o / 6e4)), (o < 0 ? '-' : '+') + [Math.abs(o) / 60, Math.abs(o) % 60].map((n) => String(Math.floor(n)).padStart(2, '0')).join(':'));
// The time row's fields: b and a are the day period before or after the hour.
const FIELD = { h: 'h', m: 'm', s: 's', b: 'p', a: 'p' };
const shown = (el) => el.getClientRects().length > 0;
const styles = /* css */ `
:host {
	--_shadow: var(--sb-shadow-overlay, 0 12px 24px rgb(0 0 0 / 0.55));
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
	--_brand-hover: var(--sb-brand-hover, #A58BFF);
	--_brand-light: var(--sb-brand-light, #B09AFF);
	--_brand-subtle: var(--sb-brand-subtle, rgb(140 107 255 / 0.14));
	--_on-brand: var(--sb-text-on-brand, #F3F4FA);
	--_danger: var(--sb-danger, #F2777A);
	--_radius: var(--sb-control-radius, 6px);
	--_notch: var(--sb-notch, 1);
	--_n: calc(2px * var(--_notch));
	--_dir: 1;
	display: grid;
	gap: 0.4rem;
	max-inline-size: 20rem;
}
:host([hidden]) { display: none; }
:host(:dir(rtl)) { --_dir: -1; }
:host(:state(disabled)) { opacity: 0.5; pointer-events: none; }
label { color: var(--_label); font-size: 0.8125rem; font-weight: 600; }
.control {
	display: flex;
	align-items: center;
	border: 1px solid var(--_border);
	border-radius: var(--_radius);
	background: var(--_bg);
	anchor-name: --sb-date;
	transition: border-color 120ms, box-shadow 120ms;
}
.control:hover { border-color: var(--_border-hover); }
.control:focus-within { border-color: var(--_brand-light); box-shadow: 0 0 0 3px var(--_brand-subtle); }
.invalid { border-color: var(--_danger); }
input {
	all: unset;
	flex: 1;
	min-inline-size: 0;
	block-size: 2.75rem;
	padding-inline: 0.875rem;
	color: var(--_text);
	font-variant-numeric: tabular-nums;
}
input::placeholder { color: var(--_placeholder); }
button {
	all: unset;
	display: grid;
	grid-auto-flow: column;
	place-content: center;
	gap: 1px;
	inline-size: 1.75rem;
	block-size: 1.75rem;
	border-radius: calc(var(--_radius) - 2px);
	color: var(--_muted);
	cursor: pointer;
}
button:hover { color: var(--_text); background: var(--_hover); }
.control button { inline-size: 2.25rem; block-size: 2.25rem; margin-inline-end: 0.25rem; }
button[aria-disabled=true] { opacity: 0.35; cursor: default; background: none; }
/* Rings: the control's glow shows the input's focus. Transparent outlines
   become visible in forced colours. */
:focus-visible { outline: 2px solid var(--_brand-light); outline-offset: -2px; }
input:focus-visible { outline-color: transparent; }
svg { inline-size: 1rem; block-size: 1rem; }
.error { color: var(--_danger); font-size: 0.75rem; }
/* Never display: none: a live region announces only once it is there. */
.error:empty { position: absolute; }
[popover] {
	margin: 0;
	padding: 0;
	border: 0;
	background: none;
	overflow: visible;
	filter: drop-shadow(var(--_shadow));
}
@supports (anchor-name: --a) {
	[popover] { position-anchor: --sb-date; inset: auto; position-area: block-end span-inline-end; margin-block-start: 4px; position-try-fallbacks: flip-block, flip-inline; }
}
#cal { justify-self: start; }
.cal {
	padding: 0.5rem;
	background: var(--_panel);
	color: var(--_text);
	box-shadow: inset 0 0 0 1px var(--_border);
	clip-path: ${notch('var(--_n)')};
	border-radius: calc(var(--_radius) * (1 - var(--_notch)));
}
.head { display: flex; align-items: center; gap: 2px; }
.title { flex: 1; text-align: center; font-size: 0.875rem; font-weight: 600; }
/* Arrows pointing to the inline start (back) or end (on): stepped at notch 1, a triangle at 0. */
i { inline-size: 4px; block-size: 7px; background: currentColor; clip-path: polygon(0px 0px, calc(0px + 1px * var(--_notch)) 0px, calc(1.143px + -0.143px * var(--_notch)) 1px, calc(1.143px + 0.857px * var(--_notch)) 1px, calc(2.286px + -0.286px * var(--_notch)) 2px, calc(2.286px + 0.714px * var(--_notch)) 2px, calc(3.429px + -0.429px * var(--_notch)) 3px, 4px calc(3.5px + -0.5px * var(--_notch)), 4px calc(3.5px + 0.5px * var(--_notch)), calc(3.429px + -0.429px * var(--_notch)) 4px, calc(2.286px + 0.714px * var(--_notch)) 5px, calc(2.286px + -0.286px * var(--_notch)) 5px, calc(1.143px + 0.857px * var(--_notch)) 6px, calc(1.143px + -0.143px * var(--_notch)) 6px, calc(0px + 1px * var(--_notch)) 7px, 0px 7px); scale: var(--_dir) 1; }
.back i { scale: calc(-1 * var(--_dir)) 1; }
table { border-collapse: collapse; table-layout: fixed; inline-size: 15.75rem; margin-block-start: 0.25rem; }
th { block-size: 1.75rem; padding: 0; overflow: hidden; color: var(--_muted); font-size: 0.6875rem; font-weight: 600; }
td {
	position: relative;
	inline-size: 2.25rem;
	block-size: 2.25rem;
	padding: 0;
	text-align: center;
	font-size: 0.8125rem;
	font-variant-numeric: tabular-nums;
	clip-path: ${notch('var(--_n)')};
	border-radius: calc((var(--_radius) - 2px) * (1 - var(--_notch)));
}
[part~=day] { cursor: pointer; }
[part~=day]:hover { background: var(--_hover); }
[part~=today] { color: var(--_brand-light); font-weight: 700; }
[part~=today]::after { content: ""; position: absolute; inset-inline: 35%; inset-block-end: 5px; block-size: 2px; background: currentColor; }
[part~=range] { background: var(--_brand-subtle); clip-path: none; border-radius: 0; }
.picking [part~=range] { background: var(--_hover); }
[part~=day][part~=selected] { background: var(--_brand); color: var(--_on-brand); clip-path: ${notch('var(--_n)')}; }
[part~=disabled] { color: var(--_muted); text-decoration: line-through; cursor: default; }
[part~=disabled]:hover { background: none; }
.times { display: grid; gap: 0.375rem; inline-size: 15.75rem; margin-block-start: 0.5rem; padding-block-start: 0.5rem; border-block-start: 1px solid var(--_border); }
.time { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; }
[part~=time-label] { flex: 1; margin-inline-end: 0.25rem; color: var(--_label); font-size: 0.8125rem; font-variant-numeric: tabular-nums; }
.empty { color: var(--_muted); }
[part~=segment] {
	flex: none;
	box-sizing: border-box;
	inline-size: 2.25rem;
	block-size: 2rem;
	padding: 0;
	border: 1px solid var(--_border);
	border-radius: calc(var(--_radius) - 2px);
	background: var(--_bg);
	text-align: center;
	font-size: 0.875rem;
}
[part~=segment]:hover { border-color: var(--_border-hover); }
[part~=segment]:focus-visible { outline-color: var(--_brand-light); background: var(--_brand-subtle); }
[part~=segment]:not([inputmode]) { inline-size: 3rem; }
@supports (field-sizing: content) {
	[part~=segment]:not([inputmode]) { inline-size: auto; min-inline-size: 2.25rem; padding-inline: 0.375rem; field-sizing: content; }
}
.sep { color: var(--_muted); white-space: pre; }
[part~=apply] {
	justify-self: end;
	inline-size: auto;
	block-size: 2rem;
	padding-inline: 0.875rem;
	background: var(--_brand);
	color: var(--_on-brand);
	font-size: 0.8125rem;
	font-weight: 600;
	clip-path: ${notch('var(--_n)')};
}
[part~=apply]:hover { background: var(--_brand-hover); color: var(--_on-brand); }
[part~=apply][aria-disabled=true] { background: var(--_brand); }
@media (prefers-reduced-motion: reduce) { .control { transition: none; } }
@media (forced-colors: active) {
	i, [part~=today]::after { forced-color-adjust: none; background: CanvasText; }
	[part~=range], [part~=day][part~=selected] { forced-color-adjust: none; background: Highlight; color: HighlightText; }
	[part~=disabled] { color: GrayText; }
	.cal { outline: 1px solid CanvasText; outline-offset: -1px; }
	[part~=segment]:focus-visible { outline-color: Highlight; }
	[part~=apply] { outline: 1px solid ButtonText; outline-offset: -1px; }
	[part~=apply]:focus-visible { outline: 2px solid Highlight; outline-offset: -2px; }
	[part~=apply][aria-disabled=true], .empty { color: GrayText; outline-color: GrayText; }
}
`;
rocket('sb-date-picker', {
    props: ({ bool, json, number, oneOf, string }) => ({
        value: string.docs({ description: 'The date, ISO: 2026-09-29. With time, a local date-time as datetime-local submits it: 2026-09-29T14:30 (seconds only when not zero), and with time-zone an instant with its offset: 2026-09-29T14:30+02:00 (any RFC 3339 instant is read). With mode="range", JSON: {"start":"2026-09-29","end":"2026-10-03"}. A new value from the server replaces it (value="" clears); the live value is the value property.' }),
        mode: oneOf('single', 'range').default('single').docs({ description: 'One date, or a start and an end, committed together as one value.' }),
        time: bool.docs({ description: 'The value includes a time of day. The calendar and a time row below it edit a draft, and Apply (or Enter in the time row) commits the date and the time together.' }),
        step: number.clamp(0, 3600).default(60).docs({ description: 'With time: the time\'s granularity in whole seconds up to 3600, as on datetime-local (60: minutes, 900: quarter hours, 1: seconds shown); a step that isn\'t a positive number means 60. Up and Down move by it; typed values off the step are kept.' }),
        timeZone: string.trim.docs({ description: 'With time: an IANA time zone (Europe/Zurich), or local for the viewer\'s. The picker shows and reads the wall clock there, and the value is an RFC 3339 instant with the zone\'s offset: 2026-09-29T14:30+02:00. A zone the browser can\'t read means the viewer\'s. Without it, the value is a local date-time.' }),
        min: string.docs({ description: 'Earliest date that can be picked (ISO). With time, a date (from the start of that day) or a date-time, with time-zone with or without an offset.' }),
        max: string.docs({ description: 'Latest date that can be picked (ISO). With time, a date (to the end of that day) or a date-time, with time-zone with or without an offset.' }),
        disabledDates: json.default(() => []).docs({ description: 'Server data: dates that can\'t be picked, as a JSON array of ISO dates (e.g. booked days). They can still be focused and read.' }),
        month: string.docs({ description: 'The month shown, YYYY-MM (view state). A changed attribute from the server moves the calendar there; the user paging months emits sb-month.' }),
        open: bool.docs({ description: 'The calendar popover is open. View state: a changed attribute from the server opens or closes it (open="false" closes); local toggling never reflects it.' }),
        inline: bool.docs({ description: 'Show the calendar on the page, always visible, without the text field.' }),
        label: string.trim.docs({ description: 'Visible label, and the accessible name of the calendar.' }),
        placeholder: string.docs({ description: 'Placeholder text (default: the locale\'s date pattern, e.g. dd.mm.yyyy, or with time dd.mm.yyyy, hh:mm).' }),
        applyLabel: string.default('Apply').docs({ description: 'With time: the text of the button that commits the calendar\'s date and time.' }),
        error: string.docs({ description: 'Message shown when the typed text is not a date that can be picked.' }),
        lang: string.trim.docs({ description: 'Locale for month and weekday names, the first day of the week and the typed format (default: the page\'s lang, then the browser\'s).' }),
        disabled: bool.docs({ description: 'Disable the field and the calendar. A form leaves it out.' }),
        confirm: bool.docs({ description: 'Server-confirmed value: :state(pending) while the local value differs from the server\'s value attribute (see revert()).' }),
        name: string.trim.docs({ description: 'Name reported in sb-change, sb-month and sb-toggle (e.g. the field of a command), and submitted with its form.' }),
    }),
    manifest: {
        events: [
            { name: 'change', kind: 'event', bubbles: true, composed: true, description: 'The value changed.' },
            { name: 'sb-change', kind: 'custom-event', bubbles: true, composed: true, description: 'A date was picked or typed (with time: applied or typed). detail: { name, value }: an ISO date, with time a date-time (with time-zone, with its offset) ("" when cleared), or with mode="range" { start, end } (null when cleared). Ready for a command.' },
            { name: 'sb-month', kind: 'custom-event', bubbles: true, composed: true, description: 'The user moved the calendar to another month. detail: { name, year, month } (month from 1): the moment to send that month\'s disabled-dates.' },
            { name: 'sb-toggle', kind: 'custom-event', bubbles: true, composed: true, description: 'The popover opened or closed. detail: { name, open }. View state: not emitted for a change the server made.' },
        ],
    },
    // Rendered once: the grid holds the keyboard focus, so everything flows
    // through signals.
    renderOnPropChange: false,
    render: ({ html }) => {
        const seven = [...Array(7).keys()];
        // A segment of end i's time: k is its field (see FIELD), show the signal
        // that shows it.
        const seg = (k, i, show) => {
            const f = FIELD[k];
            return html `<input id="${k}${i}" part="segment" type="text" role="spinbutton" autocomplete="off" spellcheck="false"
				inputmode="${f === 'p' ? false : 'numeric'}" maxlength="${f === 'p' ? false : '2'}"
				data-show="$$${show}"
				data-bind="$$${k}${i}"
				data-attr:disabled="$$disabled"
				data-attr:aria-label="$$fl.${f}"
				data-attr:aria-valuenow="$$r${i}.${f}"
				data-attr:aria-valuemin="$$lo.${f}"
				data-attr:aria-valuemax="$$hi.${f}"
				data-attr:aria-valuetext="$$r${i}.${f}t"
				data-on:focus="el.select()"
				data-on:click="el.selectionStart === el.selectionEnd && el.select()"
				data-on:input="@typing()"
				data-on:focusout="@left()"/>`;
        };
        return html `
			<label part="label" for="i" data-show="$$label" data-text="$$label"></label>
			<div class="control" part="control" data-ref:control data-show="!$$inline" data-class:invalid="$$invalid"
				data-on:keydown="evt.key === 'Escape' && $$open && evt.stopPropagation()">
				<input id="i" part="input" autocomplete="off" spellcheck="false" aria-describedby="e"
					data-attr:aria-label="$$label ? null : $$aria"
					data-attr:placeholder="$$ph"
					data-attr:disabled="$$disabled"
					data-attr:aria-invalid="String($$invalid)"
					data-bind:text
					data-on:input="evt.stopPropagation()"
					data-on:change="@typed()"/>
				<button type="button" part="button" data-ref:button popovertarget="cal" aria-label="Choose date" aria-haspopup="dialog" aria-controls="cal"
					data-attr:aria-expanded="String(!!$$open)"
					data-attr:disabled="$$disabled"><svg viewBox="0 0 16 16" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M2 3h12v11H2zM3 7h10v6H3zM4 1h2v2H4zm6 0h2v2h-2zM5 9h2v2H5z"/></svg></button>
			</div>
			<span class="error" part="error" id="e" aria-live="polite" data-text="$$invalid && $$error || ''"></span>
			<div id="cal" data-ref:cal
				data-attr:popover="$$inline ? null : 'auto'"
				data-attr:role="$$inline ? 'group' : 'dialog'"
				data-attr:aria-modal="$$inline ? null : 'true'"
				data-attr:aria-label="$$label || 'Choose date'"
				data-effect="el.popover && el.togglePopover(!!$$open)"
				data-on:beforetoggle="@before()"
				data-on:toggle="@toggled()"
				data-on:keydown="@key()">
				<div class="cal" part="calendar">
					<div class="head">
						<button type="button" class="back" part="nav" data-attr:disabled="$$disabled" aria-label="Previous year" data-attr:aria-disabled="$$back ? null : 'true'" data-on:click="@page(-12)"><i></i><i></i></button>
						<button type="button" class="back" part="nav" data-attr:disabled="$$disabled" aria-label="Previous month" data-attr:aria-disabled="$$back ? null : 'true'" data-on:click="@page(-1)"><i></i></button>
						<div class="title" part="title" id="t" aria-live="polite" data-text="$$title"></div>
						<button type="button" part="nav" data-attr:disabled="$$disabled" aria-label="Next month" data-attr:aria-disabled="$$on ? null : 'true'" data-on:click="@page(1)"><i></i></button>
						<button type="button" part="nav" data-attr:disabled="$$disabled" aria-label="Next year" data-attr:aria-disabled="$$on ? null : 'true'" data-on:click="@page(12)"><i></i><i></i></button>
					</div>
					<table role="grid" part="grid" aria-labelledby="t" data-class:picking="$$picking">
						<thead><tr>${seven.map((d) => html `<th scope="col" data-attr:abbr="$$w${d}.l" data-text="$$w${d}.s"></th>`)}</tr></thead>
						<tbody data-ref:days>
							${[...Array(6).keys()].map((w) => html `<tr>${seven.map((d) => html `<td
										data-attr:part="$$c${w * 7 + d}.p"
										data-attr:tabindex="$$c${w * 7 + d}.f"
										data-attr:aria-selected="$$c${w * 7 + d}.s"
										data-attr:aria-disabled="$$c${w * 7 + d}.d"
										data-attr:aria-current="$$c${w * 7 + d}.k"
										data-attr:aria-label="$$c${w * 7 + d}.l"
										data-text="$$c${w * 7 + d}.t"
										data-on:click="@tap(${w * 7 + d})"
										data-on:pointerover="@over(${w * 7 + d})"></td>`)}</tr>`)}
						</tbody>
					</table>
					<div class="times" data-show="$$time">
						${[0, 1].map((i) => html `<div class="time" part="time" role="group" data-show="$$range || ${i} === 0" data-attr:aria-label="$$r${i}.n">
								<span part="time-label" aria-hidden="true" data-class:empty="$$r${i}.e" data-text="$$r${i}.l"></span>
								${seg('b', i, 'pb')}${seg('h', i, 'time')}<span class="sep" aria-hidden="true" data-text="$$sep"></span>${seg('m', i, 'time')}<span class="sep" aria-hidden="true" data-show="$$secs" data-text="$$sep2"></span>${seg('s', i, 'secs')}${seg('a', i, 'pa')}
							</div>`)}
						<button type="button" part="apply" data-attr:disabled="$$disabled" data-attr:aria-disabled="$$ready ? false : 'true'" data-text="$$applyLabel" data-on:click="@apply()"></button>
					</div>
				</div>
			</div>
		`;
    },
    onFirstRender: ({ $$, action, adoptStyles, cleanup, defineHostProp, effect, emit, host, observeProps, overrideProp, props, refs }) => {
        const { control, button, cal, days } = refs;
        adoptStyles(host, styles);
        const states = internalsOf(host).states;
        // :state(open) follows the popover: set before it shows, and checked again
        // after a toggle and a prop change (inline drops the popover without events).
        const opened = (o = cal.matches(':popover-open')) => states[o ? 'add' : 'delete']('open');
        const range = () => props.mode === 'range';
        // A value's ends are points: days, or with time date-times (see clock()),
        // with a zone instants. A time of day is in seconds.
        let tz = null;
        const zoneUp = () => (tz = props.time && props.timeZone ? zone(props.timeZone) : null);
        const pt = (s) => {
            if (!props.time)
                return day(s);
            const c = clock(s);
            return !c ? null : !tz ? (c.local ? c.w : null) : c.off != null ? c.w - c.off : instant(c.w, tz);
        };
        // A zone's offset in whole minutes, so the string names exactly p (old offsets have seconds).
        const str = (p, o) => (!props.time ? iso(p) : tz ? ((o = Math.round((wall(p, tz) - p) / 6e4) * 6e4), stamp(p + o) + offset(o)) : stamp(p));
        const wallOf = (p) => (tz ? wall(p, tz) : p);
        // The point of a wall clock: t when it shows that wall clock still.
        const pointOf = (w, t) => (!tz ? w : t != null && wall(t, tz) === w ? t : instant(w, tz));
        const dayOf = (p) => (props.time ? Math.floor(wallOf(p) / DAY) : p);
        const now = () => (tz ? Math.floor(wall(Date.now(), tz) / DAY) : today());
        // As on datetime-local, a step that isn't a positive number is 60.
        const step = () => (props.step > 0 ? Math.max(1, Math.round(props.step)) : 60);
        const last = () => Math.floor(86399 / step()) * step();
        // The value as one string, so values compare: "" or a point, or with range
        // "" or {"start","end"} JSON in order (what a form submits).
        const norm = (v) => {
            if (!range()) {
                const p = pt(v);
                return p == null ? '' : str(p);
            }
            try {
                v = typeof v === 'string' ? JSON.parse(v) : v;
            }
            catch { }
            const ps = (Array.isArray(v) ? v : [v?.start, v?.end]).map(pt);
            const [a, b] = lohi(ps[0], ps[1]);
            return a == null || b == null ? '' : JSON.stringify({ start: str(a), end: str(b) });
        };
        const out = (v) => (range() ? (v ? JSON.parse(v) : null) : v);
        const points = (v) => (range() ? (v ? Object.values(JSON.parse(v)) : []) : [v]).map(pt).filter((n) => n != null);
        const ends = (v) => points(v).map(dayOf);
        const text = (v) => {
            const ws = points(v).map(wallOf);
            const t = props.time && times[+(step() % 60 !== 0 || ws.some((w) => sod(w) % 60))];
            return ends(v).map((n, i) => (t ? t.format(ws[i]) : fmt.format(n * DAY))).join(' – ');
        };
        // The language: formats, the first day of the week, the typed order and
        // the placeholder. The typed format is the locale's numeric one
        // (dd.mm.yyyy, mm/dd/yyyy, yyyy/mm/dd…), in its own digits.
        let fmt, full, title, num, digits, order;
        let first, lo, hi, off, loP, hiP;
        // With time: the formats without and with seconds, their number order (time
        // first in vi) and the words they write (klo, г.), which typed text may contain.
        let times, stamps, cycle, ampm, words, datePattern;
        const speak = () => {
            let el = host, l;
            while (!(l = el.closest('[lang]')) && (el = el.getRootNode().host))
                ;
            const lang = locale(l?.lang);
            const dtf = (o) => new Intl.DateTimeFormat(lang, { calendar: 'gregory', timeZone: 'UTC', ...o });
            fmt = dtf({ year: 'numeric', month: '2-digit', day: '2-digit' });
            full = dtf({ dateStyle: 'full' });
            title = dtf({ year: 'numeric', month: 'long' });
            num = new Intl.NumberFormat(lang, { useGrouping: false });
            digits = [...'0123456789'].map((d) => num.format(d));
            try {
                const w = new Intl.Locale(fmt.resolvedOptions().locale);
                first = (w.getWeekInfo?.() ?? w.weekInfo).firstDay % 7;
            }
            catch {
                first = 1;
            }
            const ps = fmt.formatToParts(0);
            order = ps.map((p) => p.type).filter((t) => t !== 'literal');
            const pat = (ps) => ps.map((p) => ({ day: 'dd', month: 'mm', year: 'yyyy', hour: 'hh', minute: 'mm', second: 'ss' }[p.type] ?? p.value)).join('');
            datePattern = pat(ps);
            // h24 (midnight as 24) is read and shown as h23, in the field as in the time row.
            const hc = dtf({ hour: 'numeric' }).resolvedOptions().hourCycle || 'h23';
            cycle = hc === 'h24' ? 'h23' : hc;
            const dt = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', ...(hc === 'h24' && { hourCycle: 'h23' }) };
            times = [dtf(dt), dtf({ ...dt, second: '2-digit' })];
            const pattern = props.time ? pat(times[+(step() % 60 !== 0)].formatToParts(0)) : datePattern;
            $$.ph = props.placeholder || (range() ? `${pattern} – ${pattern}` : pattern);
            // 1970-01-04 (day 3) was a Sunday.
            const wd = (o, d) => dtf({ weekday: o }).format((3 + ((first + d) % 7)) * DAY);
            for (let d = 0; d < 7; d++) {
                const s = wd('short', d);
                $$['w' + d] = { s: s.length > 4 ? wd('narrow', d) : s, l: wd('long', d) };
            }
            // The time row: the fields' names, values and order, and the separators.
            const twelve = cycle === 'h11' || cycle === 'h12';
            const h12 = dtf({ hour: 'numeric', hourCycle: 'h12' });
            ampm = [0, DAY / 2].map((t, i) => h12.formatToParts(t).find((p) => p.type === 'dayPeriod')?.value || ['AM', 'PM'][i]);
            const tp = times[1].formatToParts(0);
            stamps = tp.map((p) => p.type).filter((t) => ['year', 'month', 'day', 'hour', 'minute', 'second'].includes(t));
            const at = (t) => tp.findIndex((p) => p.type === t);
            const after = (t) => (tp[at(t) + 1]?.type === 'literal' ? tp[at(t) + 1].value : ':');
            words = new Set(tp.flatMap((p) => (p.type === 'literal' && p.value.toLowerCase().match(/\p{L}+/gu)) || []));
            let names;
            try {
                names = new Intl.DisplayNames(lang, { type: 'dateTimeField' });
            }
            catch { }
            const name = (f, en) => {
                try {
                    return names.of(f) || en;
                }
                catch {
                    return en;
                }
            };
            $$.fl = { h: name('hour', 'Hour'), m: name('minute', 'Minute'), s: name('second', 'Second'), p: name('dayPeriod', 'AM/PM') };
            $$.lo = { h: +(cycle === 'h12'), m: 0, s: 0, p: 0 };
            $$.hi = { h: twelve ? 11 + +(cycle === 'h12') : 23, m: 59, s: 59, p: 1 };
            const before = twelve && at('dayPeriod') >= 0 && at('dayPeriod') < at('hour');
            $$.pb = before;
            $$.pa = twelve && !before;
            $$.sep = after('hour');
            $$.sep2 = after('minute');
        };
        // Bounds, as points (with time, a date alone is the start of that day for
        // min and its last second for max) and as days, and ruled-out days.
        const bound = () => {
            const b = (s, end) => {
                const d = day(s);
                return pt(s) ?? (d == null ? null : pointOf(d * DAY + (end ? 86399e3 : 0)));
            };
            loP = b(props.min) ?? -Infinity;
            hiP = b(props.max, true) ?? Infinity;
            lo = isFinite(loP) ? dayOf(loP) : loP;
            hi = isFinite(hiP) ? dayOf(hiP) : hiP;
            off = new Set([props.disabledDates].flat().map(day));
        };
        // The other props the template reads.
        const mirror = () => {
            for (const k of ['label', 'error', 'inline', 'disabled', 'time', 'applyLabel'])
                $$[k] = props[k];
            $$.range = range();
            $$.aria = host.getAttribute('aria-label') || 'Date';
            states[props.disabled ? 'add' : 'delete']('disabled');
        };
        zoneUp();
        speak();
        bound();
        mirror();
        const ok = (n) => n >= lo && n <= hi && !off.has(n);
        const clamp = (n) => Math.min(Math.max(n, lo), hi);
        const latin = (s) => s.replace(/\p{Nd}/gu, (c) => (digits.includes(c) ? String(digits.indexOf(c)) : c));
        // Typed text: ISO, or the numbers in the order the field shows them (with time,
        // date first works too) and a four-digit year; see hms(). Never guess.
        const read = (s) => {
            s = latin(s);
            let n = pt(s);
            const g = [...s.matchAll(/\d+/g)];
            const ways = props.time ? [stamps, [...order, 'hour', 'minute', 'second']] : [order];
            if (n == null && (props.time ? [5, 6] : [3]).includes(g.length))
                for (const way of ways) {
                    const o = {};
                    way.filter((t) => g.length > 5 || t !== 'second').forEach((t, i) => (o[t] = g[i]));
                    const [y, m, d, h, mi, sec] = ['year', 'month', 'day', 'hour', 'minute', 'second'].map((t) => o[t]?.[0]);
                    n = y?.length === 4 && (m?.length ?? 3) < 3 && (d?.length ?? 3) < 3 ? day(`${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`) : null;
                    if (props.time && n != null) {
                        const ds = [o.year, o.month, o.day];
                        const [a, b] = [Math.min(...ds.map((x) => x.index)), Math.max(...ds.map((x) => x.index + x[0].length))];
                        n = hms((s.slice(0, a) + ' ' + s.slice(b)).toLowerCase(), [h, mi, sec], n);
                    }
                    if (n != null)
                        break;
                }
            return n != null && ok(dayOf(n)) && n >= loP && n <= hiP ? n : null;
        };
        // The time around a typed date: AM or PM anywhere makes it 12-hour (12 AM
        // is 0); other words only as the format writes them.
        const hms = (tail, [h, m, s = '00'], n) => {
            let pm = -1;
            const period = (i) => (pm < 0 || pm === i) && ((pm = i), true);
            for (const [i, a] of ampm.entries()) {
                const w = a.toLowerCase();
                if (!tail.includes(w))
                    continue;
                if (!period(i))
                    return null;
                tail = tail.replace(w, ' ');
            }
            for (const w of tail.match(/\p{L}+/gu) ?? []) {
                const i = ['am', 'pm'].indexOf(w);
                if (i >= 0 ? !period(i) : !words.has(w))
                    return null;
            }
            if (h.length > 2 || m.length !== 2 || s.length !== 2 || +m > 59 || +s > 59 || +h > (pm < 0 ? 23 : 12))
                return null;
            return pointOf(n * DAY + ((pm < 0 ? +h : (+h % 12) + pm * 12) * 3600 + +m * 60 + +s) * 1e3);
        };
        // The month shown, the day with the grid's focus (always in that month),
        // the grid's first day, the first pick of a range and the day pointed at.
        let vy, vm, focus, g, f1, len;
        let pick1 = null;
        let hover = null;
        const here = (n) => n >= f1 && n < f1 + len;
        const show = (n, quiet) => {
            const [y, m] = parts(n);
            if (y === vy && m === vm)
                return;
            [vy, vm] = [y, m];
            quiet || emit('sb-month', { name: props.name, year: y, month: m + 1 });
        };
        // Every date is a cell with part="day today selected range start end
        // disabled", as they apply; the other months' cells are blank. false
        // drops an attribute: null would delete the signal its effect follows.
        const paint = () => peek(() => {
            f1 = ymd(vy, vm, 1);
            len = ymd(vy, vm + 1, 1) - f1;
            g = f1 - ((new Date(f1 * DAY).getUTCDay() - first + 7) % 7);
            const R = range();
            // The range on show: the value's (with time the draft's), or while
            // picking, from the first pick to the day pointed at.
            const e = props.time ? draft.slice(0, R ? 2 : 1).flatMap((x) => (x.d == null ? [] : [x.d])) : ends($$.v);
            const [a, b] = pick1 != null ? lohi(pick1, hover ?? focus) : [e[0], e.at(-1)];
            const t = now();
            for (let i = 0; i < 42; i++) {
                const n = g + i;
                const sel = pick1 != null ? n === pick1 : R ? n === a || n === b : n === b;
                $$['c' + i] = !here(n)
                    ? { t: '', l: false, p: false, f: false, s: false, d: false, k: false }
                    : {
                        t: num.format(n - f1 + 1),
                        l: full.format(n * DAY),
                        p: ['day', n === t && 'today', sel && 'selected', R && n >= a && n <= b && 'range', R && n === a && 'start', R && n === b && 'end', !ok(n) && 'disabled'].filter(Boolean).join(' '),
                        f: n === focus && !props.disabled ? '0' : '-1',
                        s: sel && 'true',
                        d: !ok(n) && 'true',
                        k: n === t && 'date',
                    };
            }
            $$.title = title.format(f1 * DAY);
            $$.back = lo < f1;
            $$.on = hi >= f1 + len;
            $$.picking = pick1 != null;
        });
        const cell = () => days.rows[((focus - g) / 7) | 0]?.cells[(focus - g) % 7];
        // Moves the grid's focus (and the month with it), within min and max.
        const move = (n, quiet) => {
            focus = clamp(n);
            hover = null;
            show(focus, quiet);
            paint();
        };
        let draft = [];
        let edited = false;
        let secs = false;
        const whole = (x) => pointOf(x.d * DAY + x.s * 1e3, x.t);
        const from = (p) => {
            const w = wallOf(p);
            const d = Math.floor(w / DAY);
            return { d, s: (w - d * DAY) / 1e3, t: p };
        };
        const redraft = () => {
            const ps = points($$.v);
            draft = [0, 1].map((i) => (ps[i] != null ? from(ps[i]) : { d: null, s: i ? last() : 0, t: null }));
            edited = false;
            secs = step() % 60 !== 0;
            draw();
        };
        // A field's value as its segment shows it (p: 0 AM, 1 PM), its values, and
        // its step: the step's seconds below a minute, its minutes below an hour.
        const val = (x, f) => {
            const H = Math.floor(x.s / 3600);
            return { h: cycle === 'h11' ? H % 12 : cycle === 'h12' ? H % 12 || 12 : H, m: Math.floor(x.s / 60) % 60, s: x.s % 60, p: +(H >= 12) }[f];
        };
        const span = (f) => (f === 'h' ? (cycle === 'h12' ? [1, 12] : [0, cycle === 'h11' ? 11 : 23]) : [0, f === 'p' ? 1 : 59]);
        const every = (f, s = step()) => (f === 's' && s < 60 ? s : f === 'm' && s % 60 === 0 && s < 3600 ? s / 60 : 1);
        const two = (n) => String(n).padStart(2, '0').replace(/\d/g, (c) => digits[+c]);
        // The time row shows the draft. Seconds show with a step or a draft that
        // has them, and stay until the next draft.
        const draw = () => props.time &&
            draft.length &&
            peek(() => {
                secs ||= draft.some((x) => x.s % 60);
                $$.secs = secs;
                $$.ready = pick1 == null && draft.slice(0, range() ? 2 : 1).every((x) => x.d != null);
                draft.forEach((x, i) => {
                    const [h, m, s, p] = ['h', 'm', 's', 'p'].map((f) => val(x, f));
                    const t = { h: two(h), m: two(m), s: two(s), p: ampm[p] };
                    $$['r' + i] = {
                        l: x.d == null ? datePattern : fmt.format(x.d * DAY),
                        n: x.d == null ? $$.label || $$.aria : full.format(x.d * DAY),
                        e: x.d == null,
                        h, m, s, p,
                        ht: t.h, mt: t.m, st: t.s, pt: t.p,
                    };
                    $$['h' + i] = t.h;
                    $$['m' + i] = t.m;
                    $$['s' + i] = t.s;
                    $$['a' + i] = $$['b' + i] = t.p;
                });
            });
        // The draft's ends within min and max.
        const fix = () => {
            for (const x of draft) {
                if (x.d == null)
                    continue;
                const p = whole(x);
                if (p < loP)
                    Object.assign(x, from(loP));
                else if (p > hiP)
                    Object.assign(x, from(hiP));
            }
        };
        // Sets field f of end i to v, as its segment shows it.
        const put = (i, f, v) => {
            const x = draft[i];
            let [H, M, S] = [Math.floor(x.s / 3600), Math.floor(x.s / 60) % 60, x.s % 60];
            if (f === 'h')
                H = cycle === 'h11' || cycle === 'h12' ? (v % 12) + (H >= 12 ? 12 : 0) : v;
            else if (f === 'p')
                H = (H % 12) + v * 12;
            else if (f === 'm')
                M = v;
            else
                S = v;
            x.s = H * 3600 + M * 60 + S;
            edited = true;
            fix();
            draw();
        };
        // Up and Down: the next step in that direction, wrapping within the field.
        const spin = (i, f, up) => {
            const [a, b] = span(f);
            const k = every(f);
            const v = val(draft[i], f);
            const n = a + (up ? Math.floor((v - a) / k) + 1 : Math.ceil((v - a) / k) - 1) * k;
            put(i, f, n > b ? a : n < a ? a + Math.floor((b - a) / k) * k : n);
        };
        // A segment's text, once it is left or complete: a number (a 12-hour hour
        // may be 0 for 12), else the draft's value shows again.
        const take = (el) => {
            const f = FIELD[el.id[0]];
            const i = +el.id[1];
            const t = latin(el.value).trim();
            $$[el.id] = el.value;
            if (f !== 'p' && /^\d{1,2}$/.test(t) && +t !== val(draft[i], f))
                put(i, f, Math.min(+t, f !== 'h' ? 59 : cycle === 'h11' || cycle === 'h12' ? 12 : 23));
            else
                draw();
        };
        const segs = () => [...cal.querySelectorAll('[part~=segment]')].filter(shown);
        // The value: $$.v is the local one, the attribute the server's. A new
        // value is also a new draft.
        const set = (v) => {
            $$.v = v;
            $$.text = text(v);
            $$.invalid = false;
            pick1 = null;
            props.time && redraft();
            paint();
        };
        const commit = (v) => {
            const was = $$.v;
            set(v);
            if (v !== was) {
                emit('change');
                emit('sb-change', { name: props.name, value: out(v) });
            }
        };
        const mon = (s) => (/^\d{4}-\d\d$/.test(s) ? day(s + '-01') : null);
        const toMonth = (n) => {
            const [y, m] = parts(n);
            move(addMonths(focus, (y - vy) * 12 + m - vm), true);
        };
        // The first month: month, else the value's, else today's. The focus is on
        // the value (or today) when it is in that month.
        const v0 = norm(props.value);
        focus = ends(v0)[0] ?? now();
        if (mon(props.month) != null && !iso(focus).startsWith(props.month))
            focus = mon(props.month);
        focus = clamp(focus);
        [vy, vm] = parts(focus);
        set(v0);
        const sync = () => peek(() => states[props.confirm && $$.v !== norm(props.value) ? 'add' : 'delete']('pending'));
        effect(() => $$.v != null && sync());
        // Only what a prop changes is rebuilt, and the grid is painted once.
        observeProps((p, changes) => peek(() => {
            const has = (...k) => k.some((x) => x in changes);
            // A new format: the server's value, normalised again, wins. step and
            // time-zone only shape a value with time.
            const format = has('mode', 'time') || (p.time && has('step', 'timeZone'));
            if (format)
                zoneUp();
            if (format || has('lang', 'placeholder'))
                speak();
            if (format || has('min', 'max', 'disabledDates'))
                bound();
            if (p.disabled || p.inline)
                $$.open = false;
            mirror();
            if (has('lang'))
                $$.invalid || ($$.text = text($$.v));
            if (format)
                set(norm(p.value));
            else if (has('lang', 'min', 'max', 'disabledDates', 'disabled'))
                paint();
            // Redrawn only for a prop the time row shows: a redraw drops a half-typed segment.
            if (!format && has('lang', 'label'))
                draw();
            sync();
            opened();
        }));
        // The popover (auto: light dismiss and Escape are the browser's). $$.open
        // follows it, and the server's open drives it through the data-effect: a
        // toggle that finds $$.open already there is the server's, and quiet.
        action('before', ({ evt }) => peek(() => {
            opened(evt.newState === 'open');
            pick1 = null;
            // Closing with the focus inside: it goes back to the button.
            if (evt.newState !== 'open')
                return cal.contains(host.shadowRoot.activeElement) && button.focus();
            // The user's open shows the value's month (or today's). The draft
            // starts from the value: a close dropped the last one.
            props.time && redraft();
            $$.open ? paint() : move(ends($$.v)[0] ?? now());
            if (anchors)
                return;
            const r = control.getBoundingClientRect();
            const rtl = host.matches(':dir(rtl)');
            Object.assign(cal.style, { position: 'fixed', inset: 'auto', top: r.bottom + 4 + 'px', [rtl ? 'right' : 'left']: (rtl ? document.documentElement.clientWidth - r.right : r.left) + 'px' });
        }));
        action('toggled', ({ evt }) => peek(() => {
            const o = evt.newState === 'open';
            opened();
            if (o === !!$$.open)
                return;
            $$.open = o;
            emit('sb-toggle', { name: props.name, open: o });
            o && cell()?.focus();
        }));
        // The server's word: a changed value, open or month attribute wins. A
        // removed one is ignored (morphs also strip reflected attributes), and
        // the same word again leaves the local state alone. Watched on the
        // attributes: observeProps is silent when the decoded value is the same.
        const said = {};
        const heard = (k) => {
            const a = host.getAttribute(k);
            const news = a !== null && a !== said[k];
            said[k] = a;
            return news;
        };
        for (const k of ['value', 'open', 'month'])
            heard(k);
        const watch = new MutationObserver(() => peek(() => {
            if (heard('value'))
                set(norm(props.value));
            if (heard('open'))
                $$.open = props.open && !props.inline && !props.disabled;
            if (!heard('month') || mon(props.month) == null)
                return;
            const had = host.shadowRoot.activeElement?.localName === 'td';
            toMonth(mon(props.month));
            had && cell()?.focus();
        }));
        watch.observe(host, { attributeFilter: ['value', 'open', 'month'] });
        overrideProp('value', () => peek(() => out($$.v)), (v) => peek(() => set(norm(v))));
        overrideProp('open', () => peek(() => !!$$.open), (v) => peek(() => cal.popover && !props.disabled && cal.togglePopover(!!v)));
        overrideProp('month', () => iso(ymd(vy, vm, 1)).slice(0, 7), (v) => peek(() => mon(v) != null && toMonth(mon(v))));
        const revert = () => peek(() => (set(norm(props.value)), sync()));
        defineHostProp('revert', { value: revert });
        // Forms: until Rocket can make this element form-associated, join the
        // submissions and resets of the form it sits in, heard on the root once
        // every listener on the form has run. The entry is the value's string;
        // a reset is revert(): the server's value, no events.
        const form = host.closest('form');
        const root = host.getRootNode();
        const onData = (evt) => evt.target === form && peek(() => props.name && !props.disabled && evt.formData.append(props.name, $$.v));
        const onReset = (evt) => evt.target === form && !evt.defaultPrevented && revert();
        root.addEventListener('formdata', onData);
        root.addEventListener('reset', onReset);
        cleanup(() => {
            root.removeEventListener('formdata', onData);
            root.removeEventListener('reset', onReset);
            watch.disconnect();
            states.delete('open');
        });
        const choose = (n) => {
            if (!ok(n) || props.disabled)
                return;
            if (props.time)
                return pick(n);
            if (range() && pick1 == null)
                return (pick1 = n), paint();
            const [a, b] = lohi(pick1 ?? n, n);
            commit(range() ? JSON.stringify({ start: iso(a), end: iso(b) }) : iso(n));
            $$.open && cal.hidePopover();
        };
        // With time, a day goes into the draft (a range's second pick completes
        // it), and the focus moves on to the time.
        const pick = (n) => {
            edited = true;
            if (range() && pick1 == null) {
                pick1 = n;
                draft[0].d = n;
                draft[1].d = null;
            }
            else if (range()) {
                ;
                [draft[0].d, draft[1].d] = lohi(pick1, n);
                pick1 = null;
            }
            else
                draft[0].d = n;
            fix();
            draw();
            paint();
            pick1 == null && segs()[0]?.focus();
        };
        // Apply: the draft, within the bounds and in order, becomes the value.
        const apply = () => {
            segs().forEach((s) => take(s));
            if (!$$.ready)
                return;
            fix();
            const [a, b] = draft.map(whole);
            const [s, e] = lohi(a, b);
            commit(range() ? JSON.stringify({ start: str(s), end: str(e) }) : str(a));
            $$.open && cal.hidePopover();
        };
        action('apply', () => peek(apply));
        // The typed text, committed on change (Enter or leaving the field).
        action('typed', () => peek(() => {
            const s = $$.text.trim();
            const ns = s ? (range() ? s.split(/\s+-\s+|\s*[–—]\s*/) : [s]).map(read) : [];
            if (ns.includes(null) || (ns.length && ns.length !== (range() ? 2 : 1)))
                return ($$.invalid = true);
            const [a, b] = lohi(ns[0], ns[1]);
            commit(!ns.length ? '' : range() ? JSON.stringify({ start: str(a), end: str(b) }) : str(ns[0]));
        }));
        // Typing in a segment: a letter sets the day period; two digits set the
        // number and move on to the next segment.
        action('typing', ({ el: input, evt: e }) => peek(() => {
            const el = input, evt = e;
            evt.stopPropagation();
            if (FIELD[el.id[0]] === 'p') {
                const [c, a, p] = [evt.data ?? '', ...ampm].map((s) => s.toLowerCase().match(/\p{L}/u)?.[0]);
                const v = c === 'a' || (c === a && a !== p) ? 0 : c === 'p' || (c === p && a !== p) ? 1 : -1;
                $$[el.id] = el.value;
                v < 0 ? draw() : put(+el.id[1], 'p', v);
                return queueMicrotask(() => el.select());
            }
            edited = true;
            if (!/^\d\d$/.test(latin(el.value).trim()))
                return;
            take(el);
            const all = segs();
            const next = all[all.indexOf(el) + 1];
            next ? next.focus() : queueMicrotask(() => el.select());
        }));
        action('left', ({ el }) => peek(() => take(el)));
        action('page', (_, k) => move(addMonths(focus, k)));
        // The days: i is the cell's place in the grid.
        action('tap', (_, i) => peek(() => {
            if (!here(g + i))
                return;
            focus = g + i;
            paint();
            choose(focus);
        }));
        action('over', (_, i) => peek(() => pick1 != null && here(g + i) && g + i !== hover && ((hover = g + i), paint())));
        // Keys in the time row; false leaves the key to the browser. Left and
        // Right move the caret, and from the text's edge to the next segment.
        const segment = (el, k) => {
            const f = FIELD[el.id[0]];
            const i = +el.id[1];
            const [a, b] = span(f);
            if (k === 'Enter')
                return take(el), apply(), true;
            if (k === 'ArrowUp' || k === 'ArrowDown')
                take(el), spin(i, f, k === 'ArrowUp');
            else if (k === 'Home' || k === 'End')
                put(i, f, k === 'Home' ? a : b);
            else if (k === 'ArrowLeft' || k === 'ArrowRight') {
                const on = (k === 'ArrowRight') !== host.matches(':dir(rtl)');
                const all = segs();
                const to = all[all.indexOf(el) + (on ? 1 : -1)];
                if (!to || (on ? el.selectionEnd < el.value.length : el.selectionStart > 0))
                    return false;
                return to.focus(), true;
            }
            else
                return false;
            queueMicrotask(() => el.select());
            return true;
        };
        action('key', ({ el: grid, evt: e }) => peek(() => {
            const el = grid, evt = e, target = evt.target;
            const k = evt.key;
            if (k === 'Escape') {
                // The browser closes the popover. Stopped here, the Escape can't also
                // close a drawer or popover the picker sits in. Inline, it drops a
                // half-picked range or an edited draft.
                if ($$.open)
                    return evt.stopPropagation();
                if (pick1 == null && !edited)
                    return;
                pick1 = null;
                props.time && redraft();
                paint();
            }
            else if (k === 'Tab' && $$.open) {
                // A dialog: the focus stays in it.
                const f = [...el.querySelectorAll('button, [tabindex="0"], input')].filter(shown);
                f[(f.indexOf(host.shadowRoot.activeElement) + (evt.shiftKey ? -1 : 1) + f.length) % f.length]?.focus();
            }
            else if (target.matches('[part~=segment]')) {
                if (!segment(target, k))
                    return;
            }
            else if (target.localName === 'td') {
                const x = host.matches(':dir(rtl)') ? -1 : 1;
                const w = (new Date(focus * DAY).getUTCDay() - first + 7) % 7; // place in the week
                const s = evt.shiftKey ? 12 : 1;
                const to = { ArrowLeft: focus - x, ArrowRight: focus + x, ArrowUp: focus - 7, ArrowDown: focus + 7, Home: focus - w, End: focus + 6 - w, PageUp: addMonths(focus, -s), PageDown: addMonths(focus, s) }[k];
                if (to != null)
                    move(to), queueMicrotask(() => cell()?.focus());
                else if (k === 'Enter' || k === ' ')
                    choose(focus);
                else
                    return;
            }
            else
                return;
            evt.preventDefault();
        }));
        // A calendar the server rendered open shows (the data-effect), now that the
        // actions its toggle events call are there.
        $$.open = props.open && !props.inline && !props.disabled;
    },
});
