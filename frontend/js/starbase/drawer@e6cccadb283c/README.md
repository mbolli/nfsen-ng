---
name: Drawer
tag: sb-drawer
category: layout
summary: A panel that slides in from an edge, for navigation, filters or an edit form.
author: zweiundeins
tags: [drawer, sheet, sidebar, offcanvas, panel, dialog, overlay]
since: 2026-09-29
preview: |
  <div style="display: flex; justify-content: flex-end; inline-size: 100%; block-size: 12rem; border: 1px solid var(--sb-border); background: var(--sb-surface-inset)"><sb-drawer inline heading="Filters" style="--sb-drawer-size: 11rem">Ringed planets only.<sb-button slot="footer" size="sm" data-sb-close>Apply</sb-button></sb-drawer></div>
usage: |
  <button data-on:click="$_menu.show()">Menu</button>
  <sb-drawer data-ref:_menu side="start" heading="Menu">
    <a href="/">Home</a>
    <a href="/fleet">Fleet</a>
  </sb-drawer>
playground:
  content: Planets with rings, in the outer system.<sb-button slot="footer" size="sm" variant="outline" data-sb-close>Reset</sb-button><sb-button slot="footer" size="sm" data-sb-close>Apply</sb-button>
  values: {heading: Filters, inline: true}
  style: "--sb-drawer-size: 18rem"
---

A panel on the native `<dialog>` that slides in from an edge of the viewport. As a modal (the default) the page behind it is inert, the browser traps focus, and Escape or a click on the backdrop closes it. With `modal="false"` the page stays usable, like a side panel. Open it with `show()` and close it with `hide()`, or let the server own it with `open`.

## Examples

### Open from Datastar

`data-ref` gives you the element as a signal, so any Datastar expression can call its methods.

```html preview
<div>
  <sb-button data-on:click="$_filters.show()">Filters</sb-button>
  <sb-drawer data-ref:_filters heading="Filters" data-on:sb-close="$_picked = evt.detail.value || ''">
    <p>Planets with rings, in the outer system.</p>
    <sb-button slot="footer" variant="ghost" data-sb-close="reset">Reset</sb-button>
    <sb-button slot="footer" data-sb-close="apply">Apply</sb-button>
  </sb-drawer>
  <p data-signals:_picked="''" data-show="$_picked" data-text="'You chose: ' + $_picked"></p>
</div>
```

### Sides

`side` is the edge it slides in from: `end` (the default), `start`, `top` or `bottom`. `start` and `end` follow the text direction, so `start` is the left edge in left-to-right text and the right edge in right-to-left text. `--sb-drawer-size` is the width of a `start` or `end` drawer and the height of a `top` or `bottom` one.

```html preview
<div style="display: flex; flex-wrap: wrap; gap: 8px">
  <sb-button variant="outline" data-on:click="$_start.show()">Start</sb-button>
  <sb-button variant="outline" data-on:click="$_end.show()">End</sb-button>
  <sb-button variant="outline" data-on:click="$_top.show()">Top</sb-button>
  <sb-button variant="outline" data-on:click="$_bottom.show()">Bottom</sb-button>
  <sb-drawer data-ref:_start side="start" heading="Navigation">
    <nav style="display: grid; gap: 8px"><a href="#">Bridge</a><a href="#">Engine room</a><a href="#">Cargo bay</a></nav>
  </sb-drawer>
  <sb-drawer data-ref:_end side="end" heading="Crew">Seven on board, two on shore leave.</sb-drawer>
  <sb-drawer data-ref:_top side="top" heading="Alerts" style="--sb-drawer-size: 10rem">No alerts in this sector.</sb-drawer>
  <sb-drawer data-ref:_bottom side="bottom" heading="Cargo" style="--sb-drawer-size: 14rem">
    Twelve crates of ice, one of spare parts.
    <sb-button slot="footer" data-sb-close>Done</sb-button>
  </sb-drawer>
</div>
```

### Next to the page

With `modal="false"` the drawer has no backdrop and doesn't trap focus: the page stays usable while it is open. Escape closes it while the focus is inside it.

```html preview
<div data-signals:_fuel="40">
  <sb-button data-on:click="$_tools.isOpen ? $_tools.hide() : $_tools.show()">Toggle tools</sb-button>
  <sb-drawer data-ref:_tools modal="false" heading="Tools" style="--sb-drawer-size: 16rem">
    <label>Fuel <input type="range" data-bind:_fuel></label>
    <p data-text="'Fuel: ' + $_fuel + ' %'"></p>
  </sb-drawer>
</div>
```

### A custom header

The `header` slot replaces the heading. Whatever it holds names the drawer for screen readers.

```html preview
<div>
  <sb-button data-on:click="$_ship.show()">Ship details</sb-button>
  <sb-drawer data-ref:_ship>
    <span slot="header" style="display: flex; gap: 8px; align-items: center"><span aria-hidden="true">🚀</span><strong>Starbase One</strong></span>
    Docked at bay 7. Next departure at 14:20.
  </sb-drawer>
</div>
```

### Inline

`inline` renders the panel in place, which is handy for docs and previews. An inline panel is always visible and has no close button.

```html preview
<sb-drawer inline side="start" heading="Mission log" style="--sb-drawer-size: 16rem">Day 12. The rings of X-9 are in view.</sb-drawer>
```

### Buttons that close it

Any element in the drawer with `data-sb-close` closes it and reports the attribute's value as `detail.value` (an empty `data-sb-close` reports the element's text). `detail.reason` says what closed it: `action` for these, `button` for the close button, `escape`, `backdrop`, or `api` for `hide()`. A `data-sb-close` inside a nested `sb-drawer` or `sb-modal` closes only that one.

A `<form>` in the body can't close the drawer with `method="dialog"`: the `<dialog>` is in the component's shadow root. Give a submit button in the footer `form="…"` (the form's `id`), and close the drawer from the form's submit handler or let the server close it.

## With commands

In a CQRS app the server owns "is the drawer open?", exactly like `sb-modal`'s `open`. The button below stands in for the server: it sets the `open` **attribute**, like a re-render would, and three seconds later sends `open="false"`. Close the drawer yourself before that and `sb-close` tells the "server", which then sends `open="false"` right away.

```html preview
<div data-signals="{_srv: 'false'}">
  <sb-button data-on:click="$_srv = 'true'; setTimeout(() => $_srv = 'false', 3000)">Server opens it for 3 s</sb-button>
  <sb-drawer heading="Incoming transmission" data-attr:open="$_srv" data-preserve-attr="open"
    data-on:sb-close="$_srv = 'false'">
    The server opened this drawer and will close it. Press Escape to close it now.
  </sb-drawer>
</div>
```

On a Starbase page it is one `sb-close` handler and one command:

```html
<sb-drawer heading="Edit profile" open="{{ .Editing }}"
  data-on:sb-close="@post('/cmd/edit-profile/close', {payload: {tabid: $tabid, ...evt.detail}})">
  …
</sb-drawer>
```

The command clears the flag, and the next render sends `open="false"`.

- `open` on the first render opens the drawer on the first paint.
- A **changed** `open` wins: `open` opens the drawer, `open="false"` closes it, also one opened with `show()`. Neither sends `sb-open` or `sb-close`: the server already knows.
- The **same** markup again changes nothing. A morph never reopens a drawer the user closed.
- A **removed** `open` attribute changes nothing either. Morphs also strip attributes that were only reflected, so to close it the server sends `open="false"`.

`show()` and `hide()` (or `close()`, its name on `sb-modal`) never write the attribute, so the element's `open` property stays the server's word; `isOpen` tells whether the drawer is showing.

## Styling

Style it from your page's CSS, no need to change the component or import anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Size:** `--sb-drawer-size` (default `20rem`) is the width of a `start` or `end` drawer and the height of a `top` or `bottom` one, never more than the viewport. It spans the rest of the edge.
- **Fonts:** the heading and the body use your page's font.
- **Colours:** the panel is `--sb-surface-raised` with a `--sb-border` edge and footer divider, the heading `--sb-text-1`, the body `--sb-text-2`. The backdrop is `--sb-surface-overlay`, slightly blurred. The close button's focus ring is `--sb-brand`.
- **Shadow:** `--sb-shadow-overlay` sets the panel's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.
- **Stacking:** a modal drawer is in the top layer. With `modal="false"` it stays in the page, fixed at `--sb-z-overlay` (40), so an ancestor with a `transform` or `filter` confines it.
- **Parts:** `panel` (the dialog), `heading`, `body`, `footer` and `close` (the close button). Your page's `::part()` rules win over the component's own, without `!important`.

```html preview
<style>
  .my-drawer::part(panel) { border-inline-start: 4px solid var(--sb-brand); }
  .my-drawer::part(heading) { font-size: 1.25rem; }
</style>
<sb-drawer class="my-drawer" inline heading="Night shift" style="--sb-drawer-size: 16rem">Lights dimmed on decks 3 to 5.</sb-drawer>
```

## Accessibility

The drawer is a `<dialog>` named by its heading (or the `header` slot). On open the focus moves into it: to an element with `autofocus`, else the first focusable one (the close button), else the panel. On close it goes back to where it was. A modal drawer sets `aria-modal`, makes the page inert and traps focus; a drawer with `modal="false"` does neither, so Tab can leave it. Escape closes it: a modal drawer wherever the focus is, a non-modal one while the focus is inside. An open layer inside it, like a menu, a popover or a nested dialog, closes first. A press that starts inside the drawer, like selecting text and releasing over the backdrop, doesn't close it. Under `prefers-reduced-motion` it appears and disappears without sliding.
