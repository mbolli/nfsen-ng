// Generated from dropdown.ts by `go tool task ts`: edit the TypeScript, not this file.
import { rocket, startPeeking, stopPeeking } from 'datastar';
// Host getters must not subscribe callers (e.g. a data-attr effect that set an
// attribute) to our internal signals.
const peek = (fn) => {
    startPeeking();
    try {
        return fn();
    }
    finally {
        stopPeeking();
    }
};
// One ElementInternals per element: attachInternals() works once, and setup
// runs again when the element is re-attached. Its custom states
// (:state(pending)) are styleable from the page and morph-proof.
const internals = new WeakMap();
const internalsOf = (host) => internals.get(host) ?? internals.set(host, host.attachInternals()).get(host);
// Pixel corners: notches every corner by p (2px times --sb-notch; at 0 the
// border-radius takes over).
const notch = (p) => `polygon(${p} 0, calc(100% - ${p}) 0, calc(100% - ${p}) ${p}, 100% ${p}, 100% calc(100% - ${p}), calc(100% - ${p}) calc(100% - ${p}), calc(100% - ${p}) 100%, ${p} 100%, ${p} calc(100% - ${p}), 0 calc(100% - ${p}), 0 ${p}, ${p} ${p})`;
// Case- and accent-insensitive matching for the type-ahead.
const fold = (s) => s.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const anchors = CSS.supports('anchor-name: --a');
// Submenus: how deep the tree may go. Children below this are dropped, so a
// silly (or cyclic) tree can neither lock the browser up nor render a menu
// nobody can reach. The markup holds one popover per level.
const MAX_DEPTH = 5;
const LEVELS = [...Array(MAX_DEPTH + 1).keys()];
const normalize = (list, depth = 0) => (Array.isArray(list) ? list : [])
    .map((it) => {
    if (it === null || it === undefined)
        return null;
    // A string is an item with only a value.
    if (typeof it !== 'object') {
        const s = String(it);
        it = s === '-' || s === '---' ? { divider: true } : { value: s };
    }
    if (it.divider)
        return { divider: true };
    const children = depth < MAX_DEPTH ? normalize(it.children, depth + 1) : [];
    return {
        divider: false,
        key: String(it.value ?? it.label ?? ''),
        value: children.length ? '' : String(it.value ?? it.label ?? ''),
        label: String(it.label ?? it.value ?? ''),
        description: it.description ? String(it.description) : '',
        icon: it.icon ? String(it.icon) : '',
        disabled: !!it.disabled,
        danger: !!it.danger,
        radio: it.type === 'radio',
        children,
    };
})
    .filter(Boolean);
// What a row needs to render: never the children, which would put a whole tree
// into a signal.
const light = (r) => ({ divider: !!r.divider, label: r.label ?? '', description: r.description ?? '', icon: r.icon ?? '', disabled: !!r.disabled, danger: !!r.danger, parent: !!r.children?.length, check: false, checked: false, onpath: false, pending: false });
const styles = /* css */ `
:host {
	--_shadow: var(--sb-shadow-overlay, 0 12px 24px rgb(0 0 0 / 0.55));
	--_bg: var(--sb-control-bg, #0B1224);
	--_border: var(--sb-control-border, #283552);
	--_border-hover: var(--sb-control-border-hover, #3A4868);
	--_text: var(--sb-text-1, #F3F4FA);
	--_muted: var(--sb-text-muted, #7785A8);
	--_panel: var(--sb-surface-raised, #10182B);
	--_hover: var(--sb-surface-hover, #1A2540);
	--_brand: var(--sb-brand, #8C6BFF);
	--_brand-light: var(--sb-brand-light, #B09AFF);
	--_danger: var(--sb-danger, #F2777A);
	--_radius: var(--sb-control-radius, 6px);
	--_notch: var(--sb-notch, 1);
	--_n: calc(2px * var(--_notch));
	--_dir: 1;
	display: inline-block;
	vertical-align: middle;
}
:host([hidden]) { display: none; }
:host(:dir(rtl)) { --_dir: -1; }

/* Trigger: a notched plate with a caret. */
.trigger {
	all: unset;
	display: inline-flex;
	align-items: center;
	gap: 0.5em;
	min-block-size: 2.5rem;
	padding-inline: 0.9rem;
	background: var(--_bg);
	font-weight: 600;
	line-height: 1;
	white-space: nowrap;
	cursor: pointer;
	anchor-name: --sb-dropdown;
	transition: box-shadow 120ms, background 120ms;
}
.trigger:hover { box-shadow: inset 0 0 0 1px var(--_border-hover); background: var(--_hover); }
.trigger:focus-visible { box-shadow: inset 0 0 0 2px var(--_brand-light); outline: 2px solid transparent; outline-offset: -2px; }
.trigger[aria-expanded="true"] { box-shadow: inset 0 0 0 1px var(--_brand-light); background: var(--_hover); }
.trigger:disabled { opacity: 0.5; cursor: default; }
/* The caret (part="caret"): a page's ::part(caret) rules win over these, the open turn included. */
.caret {
	inline-size: 8px;
	block-size: 6px;
	flex: none;
	color: color-mix(in srgb, currentColor 70%, transparent);
	background: currentColor;
	/* Stepped and turning in two steps at notch 1, a triangle turning smoothly at 0. */
	clip-path: polygon(0px 0px, 8px 0px, calc(6.667px + 1.333px * var(--_notch)) 2px, calc(6.667px + -0.667px * var(--_notch)) 2px, calc(5.333px + 0.667px * var(--_notch)) 4px, calc(5.333px + -0.333px * var(--_notch)) 4px, calc(4px + 1px * var(--_notch)) 6px, calc(4px + -1px * var(--_notch)) 6px, calc(2.667px + 0.333px * var(--_notch)) 4px, calc(2.667px + -0.667px * var(--_notch)) 4px, calc(1.333px + 0.667px * var(--_notch)) 2px, calc(1.333px + -1.333px * var(--_notch)) 2px);
	transition: rotate 120ms steps(calc(2 + 998 * (1 - var(--_notch))), end);
}
.trigger[aria-expanded="true"] .caret { rotate: 180deg; }

/* Every level is a top-layer popover, so nothing can clip a menu. It casts
   the shadow: the notched panel inside would clip its own. */
[popover] {
	position: fixed;
	inset: auto;
	margin: 0;
	min-inline-size: 10rem;
	max-inline-size: min(22rem, 100vw - 1rem);
	padding: 0;
	border: 0;
	background: none;
	filter: drop-shadow(var(--_shadow));
}
.menu {
	max-block-size: min(20rem, 60dvh);
	overflow: auto;
	padding: 4px;
	background: var(--_panel);
}
/* The trigger and every menu: a notched plate. After .trigger, whose
   all: unset would take these back. */
.trigger, .menu {
	box-sizing: border-box;
	box-shadow: inset 0 0 0 1px var(--_border);
	clip-path: ${notch('var(--_n)')};
	border-radius: calc(var(--_radius) * (1 - var(--_notch)));
	color: var(--_text);
	font-size: 0.875rem;
}
/* CSS anchor positioning where it exists; place() does the same in JS elsewhere.
   The root hangs off the trigger, a submenu off the parent row that opened it. */
@supports (anchor-name: --a) {
	[popover] { position-try-fallbacks: flip-block, flip-inline; }
	.lvl0 { position-anchor: --sb-dropdown; min-inline-size: anchor-size(width); }
${LEVELS.slice(1)
    .map((k) => `.lvl${k}{position-anchor:--sb-sub-${k}}.lvl${k - 1} [aria-expanded="true"]{anchor-name:--sb-sub-${k}}`)
    .join('')}
	[data-place="bottom-start"] { position-area: block-end span-inline-end; margin-block-start: 4px; }
	[data-place="bottom"] { position-area: block-end center; margin-block-start: 4px; }
	[data-place="bottom-end"] { position-area: block-end span-inline-start; margin-block-start: 4px; }
	[data-place="top-start"] { position-area: block-start span-inline-end; margin-block-end: 4px; }
	[data-place="top"] { position-area: block-start center; margin-block-end: 4px; }
	[data-place="top-end"] { position-area: block-start span-inline-start; margin-block-end: 4px; }
	/* Submenus open to the inline end and flip to the start when there is no room. */
	[data-place="end"] { position-area: inline-end span-block-end; position-try-fallbacks: flip-inline, flip-block, flip-block flip-inline; margin-inline-start: 2px; margin-block-start: -6px; }
}
.anim:popover-open { animation: pop 110ms cubic-bezier(0.2, 0, 0, 1); }
@keyframes pop { from { opacity: 0; translate: 0 -3px; } }

[role^="menuitem"] {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.4rem 0.6rem;
	border-radius: calc((var(--_radius) - 2px) * (1 - var(--_notch)));
	cursor: pointer;
}
[role^="menuitem"]:hover { background: var(--_hover); }
/* The focused row, and the row whose submenu is open. */
[role^="menuitem"]:focus, [role^="menuitem"][aria-expanded="true"] { background: var(--_hover); box-shadow: inset calc(2px * var(--_dir)) 0 0 var(--_brand); outline: 2px solid transparent; outline-offset: -2px; }
[role^="menuitem"]:focus-visible { outline: 2px solid var(--_brand-light); outline-offset: -2px; }
[role^="menuitem"][aria-disabled="true"] { opacity: 0.45; cursor: default; }
[role^="menuitem"][aria-disabled="true"]:hover { background: none; }
.danger { color: var(--_danger); }
.danger:focus { box-shadow: inset calc(2px * var(--_dir)) 0 0 var(--_danger); }
/* The mark column is reserved for every row of a radio group, so the menu
   does not jump when the value moves. */
.check { flex: none; inline-size: 0.85rem; block-size: 0.85rem; }
.check.on, .check.path {
	background: currentColor;
	clip-path: polygon(10% 46%, 4% 60%, 40% 94%, 96% 26%, 84% 14%, 38% 70%);
}
/* A parent the choice sits under: a faint mark, and no ARIA — it is not
   selectable, it only says the choice is in there. */
.check.path { opacity: 0.35; }
/* A choice the server has not confirmed yet: the pixel board fades its
   pending cells the same way. */
.pending { opacity: 0.62; }
.pending .check.on { opacity: 0.6; }
.icon { flex: none; inline-size: 1.15rem; text-align: center; }
.body { min-inline-size: 0; flex: 1; }
.label { font-weight: 600; }
.desc { color: var(--_muted); font-size: 0.75rem; font-weight: 400; }
.label, .desc { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* An arrow marks a row that opens a submenu: stepped at notch 1, a triangle at 0. */
.more {
	flex: none;
	inline-size: 6px;
	block-size: 8px;
	background: currentColor;
	opacity: 0.6;
	clip-path: polygon(0px 0px, calc(0px + 2px * var(--_notch)) 0px, calc(1.5px + 0.5px * var(--_notch)) 1px, calc(1.5px + 2.5px * var(--_notch)) 1px, calc(4.5px + -0.5px * var(--_notch)) 3px, 6px calc(4px + -1px * var(--_notch)), 6px calc(4px + 1px * var(--_notch)), calc(4.5px + -0.5px * var(--_notch)) 5px, calc(1.5px + 2.5px * var(--_notch)) 7px, calc(1.5px + 0.5px * var(--_notch)) 7px, calc(0px + 2px * var(--_notch)) 8px, 0px 8px);
	scale: var(--_dir) 1;
}

[role="separator"] { block-size: 1px; margin: 4px 2px; background: var(--_border); }
.empty { padding: 0.4rem 0.6rem; color: var(--_muted); }
/* Slotted items are read as data and re-rendered inside the menu. */
slot[name="item"] { display: none; }
@media (prefers-reduced-motion: reduce) {
	.anim:popover-open { animation: none; }
	.caret, .trigger { transition: none; }
}
/* Forced colours drop shadows and backgrounds: glyphs and edges come back in
   system colours, focus in the transparent outlines above. */
@media (forced-colors: active) {
	.check.on, .check.path, .more, [role="separator"] { forced-color-adjust: none; background: CanvasText; }
	.caret { forced-color-adjust: none; background: color-mix(in srgb, CanvasText 70%, transparent); }
	.trigger { outline: 1px solid ButtonBorder; outline-offset: -1px; }
	.menu { outline: 1px solid CanvasText; outline-offset: -1px; }
}
`;
rocket('sb-dropdown', {
    props: ({ bool, json, oneOf, string }) => ({
        items: json.default(() => []).docs({ description: 'The menu, as JSON: ["Rename", "-", {"value":"delete","label":"Delete","danger":true}]. An item is {value, label?, description?, icon?, disabled?, danger?}, {"divider":true} ("-" works too), or a submenu {label, children:[...]} nested up to 5 levels deep. Server data: a new array replaces the whole tree, open or not.' }),
        label: string.trim.default('Actions').docs({ description: 'Text of the default trigger, and the accessible name of trigger and menu.' }),
        placement: oneOf('bottom-start', 'bottom', 'bottom-end', 'top-start', 'top', 'top-end').default('bottom-start').docs({ description: 'Preferred side and alignment of the menu; it flips and shifts when there is no room. Submenus always open to the inline end and flip to the start.' }),
        type: oneOf('actions', 'radio').default('actions').docs({ description: 'radio makes the root menu one radio group, for a menu that shows a current choice; a group inside a submenu is type:"radio" on that item instead. The group reaches into its submenus, and a dropdown holds one group.' }),
        value: string.docs({ description: 'Radio group: the checked value, owned by the server — for a choice in a submenu the item values from the group root down, joined with dots ("date.newest"). A new value from the server wins (value="" clears it); the live value is the value property.' }),
        confirm: bool.docs({ description: 'Radio group: :state(pending) on the host and on the chosen item while the local value differs from the server\'s value attribute (see revert()).' }),
        open: bool.docs({ description: 'Open on first render. A changed attribute from the server opens or closes the menu (open="false" closes); re-sent identical markup leaves the local state alone. Never reflected: use the open property, show() and hide() from the client.' }),
        disabled: bool.docs({ description: 'Disable the trigger (and close the menu).' }),
        name: string.trim.docs({ description: 'Name reported in sb-select and sb-change (e.g. the field of a command).' }),
    }),
    manifest: {
        slots: [
            { name: 'trigger', description: 'Content of the trigger button (text, an icon). The component provides the button itself, with all the ARIA on it.' },
            { name: 'item', description: 'Menu items as markup instead of items: <button slot="item" value="x" disabled data-icon="🛰" data-description="…" data-danger>Label</button>, or <hr slot="item"> for a divider. A submenu is data-children=\'[…]\' (the same JSON as items). They are read as data; items wins when it is not empty.' },
        ],
        events: [
            { name: 'sb-select', kind: 'custom-event', bubbles: true, composed: true, description: 'A plain item was chosen; the whole menu closes. detail: { name, value }: ready for a command. A submenu parent never reports a value, and an item of a radio group reports sb-change instead.' },
            { name: 'change', kind: 'event', bubbles: true, composed: true, description: 'Radio group: the value changed.' },
            { name: 'sb-change', kind: 'custom-event', bubbles: true, composed: true, description: 'Radio group: an item of the group was chosen. detail: { name, value }, the value being the dotted path for a choice inside a submenu: ready for a command.' },
            { name: 'sb-open', kind: 'custom-event', bubbles: true, composed: true, description: 'The menu opened (the root; submenus are view state and stay quiet).' },
            { name: 'sb-close', kind: 'custom-event', bubbles: true, composed: true, description: 'The menu closed. detail: { reason }: item, escape, outside, scroll, tab, trigger, server or api.' },
        ],
    },
    // Rendered once: items, open levels and the focused row all flow through
    // signals, so new items never rebuild the trigger or take the focus.
    renderOnPropChange: false,
    setup: ({ $$, action, adoptStyles, cleanup, defineHostProp, effect, emit, host, observeProps, overrideProp, props }) => {
        adoptStyles(host, styles);
        // Open/closed, which submenus are open, the focused row and the
        // type-ahead buffer are local: none of it is ever written to an
        // attribute, which a morph would reset anyway.
        let isOpen = props.open; // plain too: cleanup runs after the signals are cleared
        $$.open = isOpen;
        // Where the keyboard focus is: plain, since nothing renders it.
        let focusLevel = 0; // level with the keyboard focus
        let focusIndex = -1; // row index inside that level, -1 for none
        let inside = false; // keyboard focus is in one of the menus
        $$.disabled = props.disabled;
        $$.value = props.value; // radio group: the checked value
        $$.anim = false; // no opening animation for a menu that starts open
        // The root follows the placement prop; a submenu always opens to the
        // inline end and flips to the start when there is no room.
        for (const k of LEVELS)
            $$['side' + k] = k === 0 ? props.placement : 'end';
        // publish() (in the first rebuild() below) sets the rest: how many levels
        // are on screen (depth), what the trigger reads (trigger), icons, rev, and
        // per level its rows, open parent, name and mark column.
        const menuEl = (k) => host.shadowRoot?.querySelector('.lvl' + k);
        const rowEl = (k, i) => menuEl(k)?.querySelector(`[data-idx="${i}"]`);
        const trigger = () => host.shadowRoot?.querySelector('.trigger');
        // tree and path stay plain: a tree in a signal would merge on every
        // assignment, and only the rendered rows need to be reactive.
        let tree = [];
        let path = []; // index of the open parent per level: [2] means level 1 hangs off row 2
        let rev = 0;
        // The one radio group, as the path of the item whose children it is
        // ([] for the root menu). null when the menu is all actions.
        let group = null;
        // The server's value, for the pending mark and revert() (see the value
        // attribute below).
        let servedV = props.value;
        // The group starts at its root level and runs through every submenu below
        // it, so a nested leaf is part of the same group.
        const onGroup = (k) => !!group && k >= group.length && group.every((g, j) => path[j] === g);
        // What a row of the group reports: the item values from the group root
        // down to it, joined with dots ("date.newest").
        const pathValue = (k, i) => [...path.slice(group.length, k), i].map((at, j) => node(group.length + j, at)?.key ?? '').join('.');
        // The items of the group, whatever level they sit on.
        const atPath = (p) => {
            let list = tree;
            for (const i of p)
                list = list[i]?.children ?? [];
            return list;
        };
        // The label of the chosen leaf, for the trigger: the value walked back
        // through the tree. An unknown value is shown as it came.
        const chosenLabel = () => {
            if (!group || !$$.value)
                return '';
            let list = atPath(group);
            let hit;
            for (const seg of $$.value.split('.')) {
                hit = list.find((r) => !r.divider && r.key === seg);
                if (!hit)
                    return $$.value;
                list = hit.children;
            }
            return hit.children.length ? $$.value : hit.label;
        };
        const nodes = (k) => {
            let list = tree;
            for (let j = 0; j < k; j++)
                list = list[path[j]]?.children ?? [];
            return list;
        };
        const node = (k, i) => nodes(k)[i];
        const opens = (k, i) => !!node(k, i)?.children?.length && !node(k, i).disabled;
        const focusable = (k, i) => {
            const r = node(k, i);
            return !!r && !r.divider && !r.disabled;
        };
        const step = (k, from, dir) => {
            const n = nodes(k).length;
            for (let s = 1; s <= n; s++) {
                const i = (from + dir * s + n * (s + 1)) % n;
                if (focusable(k, i))
                    return i;
            }
            return -1;
        };
        const edge = (k, dir) => step(k, dir > 0 ? -1 : 0, dir);
        // Markup items are read as data (label, value, flags, data-children) and
        // rendered inside the menu: we never write roles or tabindex into the
        // page's own DOM, where the next morph would strip them.
        const slotted = () => (host.shadowRoot?.querySelector('slot[name="item"]')?.assignedElements() ?? []).map((el) => {
            if (el.localName === 'hr' || el.hasAttribute('data-divider'))
                return { divider: true };
            let children = [];
            try {
                children = JSON.parse(el.getAttribute('data-children') || '[]');
            }
            catch { }
            return {
                value: el.getAttribute('value'), // normalize() falls back to the label
                label: el.textContent.trim(),
                description: el.getAttribute('data-description'),
                icon: el.getAttribute('data-icon'),
                disabled: el.hasAttribute('disabled') || el.getAttribute('aria-disabled') === 'true',
                danger: el.hasAttribute('data-danger'),
                children,
            };
        });
        // Every level's rows, the open parents and the menu names in one go. Each
        // level has its own signals, so nothing nests inside one (a signal merges
        // what you assign to it).
        const publish = () => {
            let icons = false;
            const waiting = props.confirm && $$.value !== servedV;
            for (const k of LEVELS) {
                const rows = k <= path.length ? nodes(k).map(light) : [];
                const here = onGroup(k); // this level belongs to the radio group
                rows.forEach((r, i) => {
                    if (!here || r.divider)
                        return;
                    const pv = pathValue(k, i);
                    if (r.parent) {
                        // A parent is never selectable; a faint mark only says the
                        // choice lives somewhere in there.
                        r.onpath = $$.value === pv || String($$.value).startsWith(pv + '.');
                        return;
                    }
                    r.check = true;
                    r.checked = pv === $$.value;
                    // The check the user clicked is a rendered result: it stays
                    // pending until the server's value says the same.
                    r.pending = waiting && r.checked;
                });
                $$['checks' + k] = here;
                $$['rows' + k] = rows;
                $$['parent' + k] = k < path.length ? path[k] : -1;
                // A submenu is named by the item that opens it.
                $$['lbl' + k] = k === 0 ? props.label : (node(k - 1, path[k - 1])?.label ?? '');
                if (rows.some((r) => r.icon))
                    icons = true;
            }
            $$.icons = icons;
            // A radio menu says what it is and what is chosen; a page that sets
            // label still owns the first half, and an actions menu is untouched.
            $$.trigger = [props.label, chosenLabel()].filter(Boolean).join(': ');
            $$.depth = $$.open ? path.length + 1 : 0;
            $$.rev = ++rev; // any change to what is on screen re-runs the placement
        };
        // Move the DOM focus back onto the focused row after a re-render, but
        // only when it fell on the floor: document.activeElement is the host
        // while the focus is inside our shadow root, and the body when a row was
        // removed under it. If the user moved on, leave them alone.
        const refocus = (force) => requestAnimationFrame(() => {
            if (!$$.open || !inside || focusIndex < 0)
                return;
            if (!force) {
                const active = document.activeElement;
                if (active && active !== document.body && active !== host)
                    return;
            }
            const el = rowEl(focusLevel, focusIndex);
            if (el && host.shadowRoot.activeElement !== el)
                el.focus();
        });
        const focusRow = (k, i) => {
            if (i < 0)
                return;
            if (k !== focusLevel)
                type = ''; // the type-ahead never leaks across levels
            focusLevel = k;
            focusIndex = i;
            inside = true;
            refocus(true);
        };
        let shown = ''; // what the levels currently render, to skip idle rebuilds
        const rebuild = (force) => {
            const next = normalize(props.items?.length ? props.items : slotted());
            const key = JSON.stringify(next);
            if (key === shown && !force)
                return;
            shown = key;
            // Whether a menu had the focus has to be read before the rows go:
            // the morph can park a row before removing it, so focusout fires
            // while it is still connected and only the empty relatedTarget shows.
            const had = inside;
            const wasLevel = focusLevel;
            const wasIndex = focusIndex;
            tree = next;
            // One radio group per dropdown: the root menu (type="radio" on the
            // host) or the children of one item (type: "radio"). Anything beyond
            // the first is ignored and reported, never guessed at.
            group = props.type === 'radio' ? [] : null;
            const extra = [];
            const scan = (list, at) => list.forEach((r, i) => {
                // A divider carries nothing else, so every read stays optional.
                if (r.radio && r.children?.length) {
                    if (group)
                        extra.push(r.label || 'item ' + i);
                    else
                        group = [...at, i];
                }
                if (r.children?.length)
                    scan(r.children, [...at, i]);
            });
            scan(tree, []);
            if (extra.length)
                reportError(new Error(`<sb-dropdown> holds one radio group; ignoring ${extra.join(', ')}`));
            // Submenus survive new items only while their parent is still a
            // parent: otherwise the path is cut back to where it still holds.
            let keep = 0;
            while (keep < path.length && opens(keep, path[keep]))
                keep++;
            path = path.slice(0, keep);
            publish();
            // The focused row is gone: take its neighbour (the old index clamped
            // into the level that is left, skipping rows nobody can focus), never
            // the first row — that would send one arrow key to the other end.
            const level = Math.min(wasLevel, path.length);
            if (!focusable(level, wasIndex) || level !== wasLevel) {
                focusLevel = level;
                // step() from the row before tries the clamped index itself first.
                focusIndex = step(level, Math.min(Math.max(wasIndex, 0), nodes(level).length - 1) - 1, 1);
            }
            inside = had;
            refocus(false);
        };
        const place = (k) => {
            const m = menuEl(k);
            const a = k === 0 ? trigger() : rowEl(k - 1, path[k - 1]);
            if (anchors || !m || !a)
                return;
            const r = a.getBoundingClientRect();
            const gap = 4;
            const pad = 8;
            // Whether v..v + size fits into pad..max - pad; the wanted spot, or the
            // other one when only that fits; a spot shifted back inside, in px.
            const fits = (v, size, max) => v >= pad && v + size <= max - pad;
            const pick = (want, other, size, max) => (fits(want, size, max) || !fits(other, size, max) ? want : other);
            const shift = (v, size, max) => Math.round(Math.min(Math.max(pad, v), Math.max(pad, max - size - pad))) + 'px';
            if (k === 0)
                m.style.minInlineSize = r.width + 'px';
            const w = m.offsetWidth;
            const h = m.offsetHeight;
            let left;
            let top;
            if (k === 0) {
                const [side, align = 'center'] = props.placement.split('-');
                const above = r.top - h - gap;
                const below = r.bottom + gap;
                // Flip to the other side when only that one has room.
                top = side === 'top' ? pick(above, below, h, innerHeight) : pick(below, above, h, innerHeight);
                left = align === 'end' ? r.right - w : align === 'center' ? r.left + (r.width - w) / 2 : r.left;
            }
            else {
                // A submenu hangs off its parent row, to the inline end.
                const after = r.right - 2; // to the right of the row
                const before = r.left - w + 2; // to its left
                left = getComputedStyle(host).direction === 'rtl' ? pick(before, after, w, innerWidth) : pick(after, before, w, innerWidth);
                // Level with the row, or ending level with it when that has no room.
                top = r.top - 6;
                if (!fits(top, h, innerHeight))
                    top = r.bottom + 6 - h;
            }
            // Shift back into the viewport.
            m.style.left = shift(left, w, innerWidth);
            m.style.top = shift(top, h, innerHeight);
        };
        // No attribute form for these: a click anywhere in the document, and the
        // scrolling of any ancestor while we position by hand.
        const onDown = (evt) => {
            tap = evt.pointerType === 'touch';
            if (!evt.composedPath().includes(host))
                close('outside', false);
        };
        const onMove = () => {
            const r = trigger()?.getBoundingClientRect();
            if (r && (r.bottom < 0 || r.top > innerHeight))
                return close('scroll', false);
            for (const k of LEVELS)
                place(k);
        };
        let bound = false;
        const bind = (on) => {
            if (on === bound)
                return;
            bound = on;
            const m = on ? 'addEventListener' : 'removeEventListener';
            document[m]('pointerdown', onDown, true);
            if (!anchors) {
                ;
                window[m]('scroll', onMove, { capture: true, passive: true });
                window[m]('resize', onMove);
            }
        };
        let tap = false; // the last press was a touch
        let type = ''; // type-ahead buffer, per level
        let typer = 0;
        let hoverIn = 0; // pointer: open a submenu after a moment
        let hoverOut = 0; // …and close it a little later, so a diagonal path survives
        const timers = () => {
            clearTimeout(hoverIn);
            clearTimeout(hoverOut);
        };
        const setOpen = (next, { focus, reason = 'api', defer } = {}) => {
            if (next && props.disabled)
                return;
            if (!!next === !!$$.open)
                return;
            clearTimeout(typer);
            timers();
            type = '';
            path = [];
            // Opening animates from here on; a menu the server rendered open does
            // not (the class has to be there before the popover shows, or adding
            // it later would start the animation a frame too late).
            if (next)
                $$.anim = true;
            $$.open = isOpen = !!next;
            focusLevel = 0;
            inside = !!(next && focus);
            focusIndex = next ? (focus ? edge(0, focus === 'last' ? -1 : 1) : -1) : -1;
            publish();
            if (next && focus)
                refocus(true);
            const fire = () => emit(next ? 'sb-open' : 'sb-close', next ? undefined : { reason });
            // An open change that came from the server arrives inside a morph's
            // effect: emit in a later task, so a page's @post isn't tracked by it.
            defer ? setTimeout(fire) : fire();
        };
        const close = (reason, back = true) => {
            if (!$$.open)
                return;
            setOpen(false, { reason });
            if (back)
                trigger()?.focus();
        };
        // Submenus are view state: no event, no attribute, just which levels show.
        const openSub = (k, i, focus) => {
            if (!opens(k, i) || k >= MAX_DEPTH)
                return;
            timers();
            path = [...path.slice(0, k), i];
            publish();
            if (focus)
                focusRow(k + 1, edge(k + 1, 1));
        };
        const closeTo = (level, focus) => {
            timers();
            if (path.length <= level)
                return;
            const parent = path[level];
            const had = inside && focusLevel > level; // the focus was in a level that is going
            path = path.slice(0, level);
            publish();
            if (focus || had)
                focusRow(level, parent);
        };
        const choose = (k, i, touch) => {
            // A parent opens its submenu and moves the focus into it, also when
            // hovering opened it already. A second tap closes it again, the only
            // way back on a touch screen. Its own value never counts.
            if (opens(k, i))
                return touch && path[k] === i ? closeTo(k, true) : openSub(k, i, true);
            // Not a parent, so a row the focus can take is a leaf.
            if (!focusable(k, i))
                return;
            const value = node(k, i).value;
            // An item of the radio group changes a value; every other item is an
            // intent the page turns into a command. Never both for one item.
            if (onGroup(k)) {
                $$.value = pathValue(k, i);
                publish();
                sync();
                emit('change');
                emit('sb-change', { name: props.name, value: peek(() => $$.value) });
            }
            else {
                emit('sb-select', { name: props.name, value });
            }
            close('item');
        };
        // The open and value attributes: the first one sets the initial state, a
        // changed one from the server wins over the local state (open="false"
        // closes, value="" clears). A *removed* attribute is ignored: morphs also
        // strip attributes that were only reflected. served and said are the
        // server's last word (null without one), so a re-written identical
        // attribute leaves the local state alone: a morph can never re-open a
        // menu the user just closed. They are watched on the attribute, not
        // through observeProps, which stays silent when the decoded value did
        // not change (open="false" on an element that never had the attribute).
        const states = internalsOf(host).states;
        const sync = () => peek(() => (props.confirm && $$.value !== servedV ? states.add('pending') : states.delete('pending')));
        // :state(open) follows the menu, whichever way it opens or closes.
        effect(() => states[$$.open ? 'add' : 'delete']('open'));
        let said = host.hasAttribute('value') ? props.value : null;
        const serverValue = () => {
            if (!host.hasAttribute('value'))
                return void (said = null);
            if (props.value === said)
                return;
            $$.value = servedV = said = props.value;
            publish();
            sync();
        };
        let served = host.hasAttribute('open') ? props.open : null;
        const serverOpen = () => {
            if (!host.hasAttribute('open'))
                return void (served = null);
            if (props.open === served)
                return;
            setOpen((served = props.open), { reason: 'server', defer: true });
        };
        // Slotted items change without a slotchange when a morph only rewrites a
        // label or a flag: no attribute form for watching that either. Both
        // checks above are idempotent, so every batch runs them.
        const watch = new MutationObserver(() => peek(() => {
            serverOpen();
            serverValue();
            rebuild();
        }));
        watch.observe(host, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['open', 'value', 'type', 'disabled', 'aria-disabled', 'slot', 'data-icon', 'data-description', 'data-danger', 'data-divider', 'data-children'] });
        rebuild();
        observeProps((p, changes) => peek(() => {
            if (p.disabled && $$.open)
                setOpen(false, { reason: 'api', defer: true });
            $$.side0 = p.placement;
            $$.disabled = p.disabled;
            rebuild('type' in changes); // a new type moves the group
            publish(); // a new label also renames the menu
            sync();
        }));
        // Radio group: the attribute is the server's value, $$.value the local
        // one. With confirm, :state(pending) marks a choice the server has not
        // confirmed yet; revert() goes back to the server's value.
        effect(() => ($$.value, sync()));
        overrideProp('value', () => peek(() => $$.value), (v) => peek(() => (($$.value = String(v ?? '')), publish(), sync())));
        defineHostProp('revert', { value: () => peek(() => (($$.value = servedV), publish(), sync())) });
        // host.open / show() / hide(): the live state, never an attribute.
        overrideProp('open', () => peek(() => $$.open), (v) => peek(() => setOpen(!!v && v !== 'false', { focus: v ? 'first' : null })));
        defineHostProp('show', { value: () => peek(() => setOpen(true, { focus: 'first' })) });
        defineHostProp('hide', { value: () => peek(() => close('api', false)) });
        cleanup(() => {
            // Moved (a morph re-attaches it) or removed while open: the menu
            // closes, and says so, unless the server's open attribute brings it
            // straight back.
            if (isOpen && !served)
                setTimeout(() => emit('sb-close', { reason: 'api' }));
            bind(false);
            watch.disconnect();
            clearTimeout(typer);
            timers();
            // A refocus() still pending must not focus a row of the menu that the
            // next setup renders (a re-attach starts with the focus outside).
            inside = false;
            states.delete('open');
            for (const k of LEVELS) {
                try {
                    menuEl(k)?.hidePopover();
                }
                catch { }
            }
        });
        // Shows and hides the popovers, positions them and keeps the document
        // listeners in step. A plain effect, not an action in data-effect: an
        // action would be looked up on a host that may already be gone.
        const apply = (depth) => {
            if (!menuEl(0))
                return; // before the first render
            for (const k of LEVELS) {
                const m = menuEl(k);
                try {
                    m.togglePopover(k < depth); // nothing happens to a level already there
                    if (k < depth)
                        place(k);
                }
                catch { }
            }
            // Measured once more after the rows have rendered: a popover that was
            // just shown is still empty when the effect runs.
            if (!anchors && depth)
                requestAnimationFrame(() => peek(() => LEVELS.forEach((k) => k < $$.depth && place(k))));
            bind(depth > 0);
        };
        effect(() => {
            const depth = $$.depth;
            $$.rev; // any new rows, or a submenu that moved to another parent
            peek(() => apply(depth));
        });
        // The effect's first run happens before the first render, so a menu the
        // server rendered open is shown right after it (setup, then render, then
        // this microtask).
        queueMicrotask(() => peek(() => $$.depth && apply($$.depth)));
        action('items', () => peek(rebuild));
        action('toggle', () => peek(() => ($$.open ? close('trigger', false) : setOpen(true, { focus: 'first' }))));
        action('click', ({ evt }, k, i) => peek(() => {
            evt.stopPropagation();
            choose(k, i, tap);
        }));
        action('focusin', ({ evt }, k) => peek(() => {
            inside = true;
            const i = Number(evt.target?.dataset?.idx);
            if (Number.isInteger(i)) {
                if (k !== focusLevel)
                    type = '';
                focusLevel = k;
                focusIndex = i;
            }
        }));
        action('focusout', ({ evt }) => peek(() => {
            // A row that re-rendered away also "loses" focus, and the morph can
            // park it first, so it is still connected and only the empty
            // relatedTarget gives it away; refocus() decides where it goes.
            if (!evt.target.isConnected || evt.relatedTarget === null)
                return;
            // Moving between levels is not leaving: every level is its own popover.
            if (!host.shadowRoot.contains(evt.relatedTarget))
                inside = false;
        }));
        // Hover: open a submenu after a moment, and close it a little later, so a
        // diagonal path from the parent row into the submenu does not lose it.
        action('enter', ({ evt }, k, i) => peek(() => {
            if (evt.pointerType === 'touch')
                return;
            timers();
            if (opens(k, i)) {
                if (path[k] !== i)
                    hoverIn = setTimeout(() => peek(() => openSub(k, i, false)), 140);
            }
            else if (path.length > k) {
                hoverOut = setTimeout(() => peek(() => closeTo(k, false)), 320);
            }
        }));
        action('leave', ({ evt }) => peek(() => {
            if (evt.pointerType === 'touch')
                return;
            clearTimeout(hoverIn);
        }));
        // The pointer reached a menu: whatever close was pending is off.
        action('over', () => peek(timers));
        // One current row: while the menu has the focus, it follows the pointer
        // as it moves. Not on pointerenter, and without scrolling: a row that
        // scrolls under a resting pointer must not take the keyboard's focus.
        action('move', ({ evt }) => peek(() => {
            const r = evt.target.closest('[data-idx]:not([aria-disabled])');
            if (r && inside && evt.pointerType !== 'touch')
                r.focus({ preventScroll: true });
        }));
        action('triggerKey', ({ evt: e }) => peek(() => {
            const evt = e;
            if (evt.key === 'ArrowDown' || evt.key === 'ArrowUp')
                setOpen(true, { focus: evt.key === 'ArrowUp' ? 'last' : 'first' });
            else if (evt.key === 'Escape' && $$.open)
                close('escape');
            else
                return;
            evt.preventDefault();
        }));
        // Keys inside a menu. Enter and Space choose or open a submenu, the
        // arrows move and walk into and out of submenus, Escape and Tab close.
        action('key', ({ evt: e }, k) => peek(() => {
            const evt = e;
            const rtl = getComputedStyle(host).direction === 'rtl';
            const into = rtl ? 'ArrowLeft' : 'ArrowRight';
            const out = rtl ? 'ArrowRight' : 'ArrowLeft';
            const i = focusIndex;
            switch (evt.key) {
                case 'ArrowDown':
                    focusRow(k, step(k, i, 1));
                    break;
                case 'ArrowUp':
                    focusRow(k, step(k, i, -1));
                    break;
                case 'Home':
                    focusRow(k, edge(k, 1));
                    break;
                case 'End':
                    focusRow(k, edge(k, -1));
                    break;
                case into:
                    if (opens(k, i))
                        openSub(k, i, true);
                    break;
                case out:
                    if (k > 0)
                        closeTo(k - 1, true);
                    break;
                case 'Enter':
                case ' ':
                    choose(k, i);
                    break;
                case 'Escape':
                    // A submenu closes on its own; the root closes the menu.
                    if (k > 0)
                        closeTo(k - 1, true);
                    else
                        close('escape');
                    break;
                case 'Tab':
                    // Close and hand the focus back, then let the browser tab on.
                    close('tab');
                    return;
                default: {
                    if (evt.key.length !== 1 || evt.altKey || evt.ctrlKey || evt.metaKey)
                        return;
                    clearTimeout(typer);
                    typer = setTimeout(() => (type = ''), 700);
                    type += fold(evt.key);
                    // A letter, also typed again and again ("ddd"), searches from the
                    // row after the focused one, so it cycles through the rows it
                    // starts; a word ("lla" for Llama) searches from the focused row,
                    // which may still match. Both wrap.
                    const q = type.replace(/^(.)\1+$/, '$1');
                    const n = nodes(k).length;
                    for (let s = q[1] ? 0 : 1; s <= n; s++) {
                        const j = (i + s + n) % n;
                        if (focusable(k, j) && fold(nodes(k)[j].label).startsWith(q)) {
                            focusRow(k, j);
                            break;
                        }
                    }
                    break;
                }
            }
            evt.preventDefault();
        }));
    },
    render: ({ html }) => html `
		<button class="trigger" part="trigger" type="button" aria-haspopup="menu" aria-controls="menu0"
			data-attr:aria-expanded="String($$open)"
			data-attr:aria-label="$$trigger || null"
			data-attr:disabled="$$disabled"
			data-on:click="@toggle()"
			data-on:keydown="@triggerKey()"><slot name="trigger"><span data-text="$$trigger"></span></slot><span class="caret" part="caret" aria-hidden="true"></span></button>
		${LEVELS.map((k) => html `
		<div class="lvl${k}" popover="manual" data-attr:data-place="$$side${k}" data-class:anim="$$anim">
			<div id="menu${k}" class="menu" part="menu" role="menu"
				data-attr:aria-label="$$lbl${k} || null"
				data-on:keydown="@key(${k})"
				data-on:focusin="@focusin(${k})"
				data-on:focusout="@focusout()"
				data-on:pointerenter="@over()"
				data-on:pointermove="@move()">
				<!-- r?.: when the list shrinks, data-for can re-evaluate a removed row once with r undefined. -->
				<template data-for="r, i in $$rows${k}">
					<div
						data-show="!!r"
						data-attr:part="'item' + (r?.checked ? ' checked' : '') + (r?.onpath ? ' onpath' : '') + (r?.pending ? ' pending' : '')"
						data-attr:role="!r ? null : r.divider ? 'separator' : r.check ? 'menuitemradio' : 'menuitem'"
						data-attr:data-idx="r?.divider ? null : i"
						data-attr:tabindex="r?.divider ? null : -1"
						data-attr:aria-disabled="r?.disabled ? 'true' : null"
						data-attr:aria-checked="r?.check ? String(!!r?.checked) : null"
						data-attr:aria-haspopup="r?.parent ? 'menu' : null"
						data-attr:aria-expanded="r?.parent ? String($$parent${k} === i) : null"
						data-class:danger="r?.danger"
						data-class:pending="r?.pending"
						data-on:click="@click(${k}, i)"
						data-on:pointerenter="@enter(${k}, i)"
						data-on:pointerleave="@leave()">
						<span class="check" aria-hidden="true" data-show="$$checks${k} && !r?.divider" data-class:on="r?.checked" data-class:path="r?.onpath"></span>
						<span class="icon" aria-hidden="true" data-show="$$icons && !r?.divider" data-text="r?.icon"></span>
						<span class="body" data-show="!r?.divider">
							<span class="label" data-text="r?.label"></span>
							<span class="desc" data-show="r?.description" data-text="r?.description"></span>
						</span>
						<span class="more" aria-hidden="true" data-show="r?.parent"></span>
					</div>
				</template>
				<div class="empty" aria-disabled="true" data-attr:role="${k} < $$depth && !$$rows${k}.length ? 'menuitem' : null" data-show="${k} < $$depth && !$$rows${k}.length">Nothing here</div>
			</div>
		</div>`)}
		<slot name="item" data-on:slotchange="@items()"></slot>
	`,
});
