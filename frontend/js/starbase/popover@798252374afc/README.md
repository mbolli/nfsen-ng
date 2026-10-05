---
name: Popover
tag: sb-popover
category: feedback
summary: A floating panel anchored to a trigger, as a click dialog or a hover card.
author: zweiundeins
tags: [popover, hovercard, dialog, floating, anchor, overlay]
since: 2026-09-29
preview: |
  <sb-popover label="Flight plan" arrow>Burn 4.2 s at T+38, then coast to the transfer orbit.</sb-popover>
usage: |
  <sb-popover label="Flight plan">Burn 4.2 s at T+38, then coast to the transfer orbit.</sb-popover>
playground:
  content: Burn 4.2 s at T+38, then coast to the transfer orbit.
  values: {label: Flight plan, arrow: true}
  exclude: [name, open]
---

A panel with any content, anchored to a trigger. In `click` mode it is a small non-modal dialog: a click opens it, a click outside or Escape closes it. In `hover` mode it is a hover card that shows up while the pointer or the keyboard focus rests on the trigger.

The panel is a native `popover` in the top layer, so no ancestor with `overflow: hidden` clips it. CSS anchor positioning places it next to the trigger; where the browser lacks anchored container queries, the component places it by hand. Either way it flips to the other side and shifts back into the viewport when there is no room.

`open` is view state, like [sb-details](/components/details): the server may own it, local opens and closes never write the attribute, and `sb-open` / `sb-close` report every change. Nothing is ever pending.

## Examples

### A click popover

Without a `trigger` slot, a button shows `label`, which also names the panel. When the panel holds something focusable, the focus moves to it on open and goes back to the trigger on close.

```html preview
<sb-popover label="Crew roster" arrow>
  <p style="margin: 0 0 8px">Four aboard. Provisions for 90 days.</p>
  <a href="#crew">Open the manifest</a>
</sb-popover>
```

### Your own trigger

Put a native `<button>` in the `trigger` slot. The component writes `aria-expanded` and `aria-haspopup="dialog"` onto it and puts them back after every morph, so the server markup doesn't need them. `label` (or `aria-label` on the element) still names the panel.

```html preview
<sb-popover label="Fuel status" placement="bottom-start" arrow>
  <button slot="trigger" type="button" aria-label="Fuel status" style="padding: 0; border: 0; background: none; cursor: pointer">
    <img src="/art/info.svg" alt="" width="32" height="32" style="display: block; image-rendering: pixelated">
  </button>
  Main tank 72%. Reserve 18%. Next refuel at Europa.
</sb-popover>
```

A component that renders its own button inside a shadow root, like `sb-button`, can't carry these attributes for its inner button. Use a native button, or the default trigger and style it through `::part(trigger)`.

### A hover card

`mode="hover"` opens the panel after a short delay (400 ms) while the pointer is on the trigger, or when the trigger gets the keyboard focus. The pointer can move onto the panel, which stays open while the pointer or the focus is on it, and closes 300 ms after both left. Escape dismisses it.

```html preview
<p>
  Commander
  <sb-popover mode="hover" placement="top" arrow>
    <a slot="trigger" href="#ada">Ada Okafor</a>
    <span><strong>Ada Okafor</strong>, flight engineer.<br>312 days in orbit. <a href="#ada-log">Mission log</a></span>
  </sb-popover>
  signed the flight plan.
</p>
```

In hover mode the panel describes the trigger (`aria-describedby`) instead of being a dialog, and the focus never moves on its own. Tab from the trigger reaches the links in an open card. A tap on a touch screen toggles it, since there is no hover there.

With your own trigger, the description is made of the elements in the panel, so loose text needs an element around it (the `<span>` above).

### Placement

`placement` is the side of the trigger (`top`, `bottom`, `start`, `end`) with an optional alignment along it (`-start`, `-end`). `start` and `end` are logical: in right-to-left text, `start` is on the right. The panel flips to the opposite side when only that one has room, and shifts along the edge to stay on screen. The arrow keeps pointing at the middle of the trigger.

```html preview
<div style="display: flex; flex-wrap: wrap; gap: 12px; padding-block: 24px">
  <sb-popover label="top-start" placement="top-start" arrow>Aligned with the trigger's start edge.</sb-popover>
  <sb-popover label="bottom-end" placement="bottom-end" arrow>Aligned with the trigger's end edge.</sb-popover>
  <sb-popover label="end" placement="end" arrow>Beside the trigger, centred.</sb-popover>
  <sb-popover label="start-start" placement="start-start" arrow>Before the trigger, level with its top.</sb-popover>
</div>
```

### When the server owns the open state

The two buttons stand in for the server: they change the `open` attribute, as a re-render would. The first one opens the panel, the one inside it sends `open="false"` and closes it. A changed attribute wins over whatever the user did. Close the panel by hand and press "Server sends open" again: nothing happens, because the attribute already says open and the same markup sent again is not a change. `data-preserve-attr` keeps the signal-driven attribute through a morph.

```html preview
<div data-signals="{_briefOpen: 'false', _briefLog: 'no event yet'}" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center">
  <sb-button size="sm" data-on:click="$_briefOpen = 'true'">Server sends open</sb-button>
  <sb-popover name="brief" label="Mission brief" arrow data-attr:open="$_briefOpen" data-preserve-attr="open"
    data-on:sb-open="$_briefLog = 'open (' + evt.detail.reason + ')'"
    data-on:sb-close="$_briefLog = 'closed (' + evt.detail.reason + ')'">
    <p style="margin: 0 0 8px">Burn 4.2 s at T+38.</p>
    <sb-button size="sm" variant="outline" data-on:click="$_briefOpen = 'false'">Server sends open="false"</sb-button>
  </sb-popover>
  <code data-text="$_briefLog"></code>
</div>
```

## With commands

A page that keeps the panel open across renders posts both events and renders `open` from its state. A change the server made itself (reason `server`) needs no command:

```html
<sb-popover name="brief" label="Mission brief" open="{{ .BriefOpen }}"
  data-on:sb-open="evt.detail.reason !== 'server' && @post('/cmd/panel', {payload: {tabid: $tabid, name: evt.detail.name, open: true}})"
  data-on:sb-close="evt.detail.reason !== 'server' && @post('/cmd/panel', {payload: {tabid: $tabid, name: evt.detail.name, open: false}})">
  …
</sb-popover>
```

The command writes the panel into `tab_state`, and the next render brings the attribute back. There is no `confirm` and no `revert()`: a panel the user opened is open, whatever the server thinks, until the server sends a different `open`.

## Open and closed

Opening and closing is local. Clicks, the pointer, the keyboard, an outside click and Escape only change a `$$` signal, so a morph never re-opens a panel the user just closed. The server gets its say through the `open` attribute:

- The first `open` sets the initial state: `<sb-popover open>` is open on the first paint, without the fade-in.
- A **changed** attribute wins over the local state: `open="false"` closes the panel, `open` opens it again.
- Re-sent identical markup changes nothing.
- A **removed** attribute is ignored, because morphs also strip attributes that were only reflected. To close from the server, send `open="false"`.

From the client, use the property and the methods, which never touch the attribute:

```js
el.open    // true or false, live
el.show()  // open, and move the focus into the panel (click mode)
el.hide()  // close
```

`sb-open` and `sb-close` carry `{ name, reason }`. An open comes from the `trigger`, the `server` or the `api` (the property and the methods). A close comes from the `trigger`, a press `outside`, `escape`, `leave` (hover mode: the pointer and the focus left), the `server` or the `api`, which also covers a panel removed or moved while open, unless the server's `open` attribute says open.

Popovers nest: a press inside an inner panel keeps the outer one open, and Escape closes the innermost first, also before a drawer or a modal the popover sits in. A component inside the panel that handles Escape itself (a dropdown's open menu) keeps the panel open when it cancels the key.

## Styling

Parts: `trigger` (the default button), `panel` (the notched box around the content) and `arrow`. The panel is at most `20rem` wide and `24rem` tall and scrolls beyond that; set `inline-size` or `max-inline-size` on `::part(panel)` for another size. Text inherits the page's font.

Colours come from `--sb-surface-raised` (panel), `--sb-border-strong` (its edge and the arrow), `--sb-text-2` (content), and for the default trigger `--sb-control-bg`, `--sb-control-border`, `--sb-control-border-hover`, `--sb-surface-hover`, `--sb-brand-light` and `--sb-text-1`. `--sb-shadow-overlay` sets the panel's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`. `--sb-notch: 0` rounds the pixel corners, with `--sb-radius` for the panel and `--sb-control-radius` for the trigger. Set the tokens on the element or an ancestor, not on a part.

```html preview
<style>
  .wide-pop { --sb-surface-raised: var(--sb-brand-subtle); --sb-border-strong: var(--sb-brand); --sb-notch: 0; }
  .wide-pop::part(panel) { inline-size: 24rem; }
</style>
<sb-popover class="wide-pop" label="Tinted" arrow>Re-map the tokens rather than hard-coding colours, and it works on every theme.</sb-popover>
```

## Accessibility

- **Click mode:** the panel is a non-modal `dialog`, named by `aria-label` on the element or else by `label`. The trigger has `aria-haspopup="dialog"` and `aria-expanded`; the default trigger also has `aria-controls`. An id can't point from your own trigger into the component's shadow root, so a slotted trigger goes without `aria-controls`.
- **Focus:** opening from the trigger (or `show()`) moves the focus to the first focusable element in the panel, or to one with `autofocus`; a panel with nothing focusable leaves it on the trigger. A close while the focus is inside puts it back on the trigger, except a press outside, which leaves the focus where the press put it. Tab moves through the panel and on to the page, without closing it.
- **Hover mode:** the trigger has `aria-describedby` pointing at the content, so screen readers read the card as its description. The card follows [WCAG 1.4.13](https://www.w3.org/WAI/WCAG22/Understanding/content-on-hover-or-focus): Escape dismisses it wherever the focus is, the pointer can move onto it, and it stays until the pointer and the focus have left or it is dismissed. Keyboard focus on the trigger opens it like hovering does. Give the trigger a name of its own: the card is read after it, never as it.
- **Keys:** Enter and Space on the trigger toggle a click popover; Escape closes the innermost open popover.
- **Motion:** the fade-in is skipped under `prefers-reduced-motion`, and for a panel that is open on the first render.
- **Forced colours:** the panel edge, the default trigger's edge and the arrow come back in system colours, and focus rings are outlines.
