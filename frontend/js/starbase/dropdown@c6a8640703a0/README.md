---
name: Dropdown
tag: sb-dropdown
category: navigation
summary: An actions menu with submenus, commands to post and a current choice.
author: zweiundeins
tags: [dropdown, menu, actions, popover, keyboard, navigation]
since: 2026-09-23
preview: |
  <sb-dropdown data-signals:_cardPick="''" data-preserve-attr="label"
    data-attr:label="$_cardPick ? 'Sent: ' + $_cardPick : 'Ship actions'"
    data-on:sb-select="$_cardPick = evt.detail.value"
    items='[{"value":"refuel","label":"Refuel","icon":"⛽"},{"label":"Set course","icon":"🧭","children":[{"value":"mars","label":"Mars"},{"value":"europa","label":"Europa"}]},{"divider":true},{"value":"scuttle","label":"Scuttle","icon":"💥","danger":true}]'></sb-dropdown>
usage: |
  <sb-dropdown label="Actions" data-on:sb-select="@post('/actions/' + evt.detail.value)"
    items='[{"value":"edit","label":"Edit"},{"value":"duplicate","label":"Duplicate"},{"divider":true},{"value":"delete","label":"Delete","danger":true}]'></sb-dropdown>
playground:
  attrs:
    "data-on:sb-select": '$_pg.label = "Sent: " + evt.detail.value'
    items: '[{"value":"refuel","label":"Refuel","icon":"⛽"},{"value":"course","label":"Set course","icon":"🧭","children":[{"value":"mars","label":"Mars"},{"value":"europa","label":"Europa"},{"value":"outer","label":"Outer system","children":[{"value":"titan","label":"Titan"},{"value":"triton","label":"Triton"}]}]},{"divider":true},{"value":"scuttle","label":"Scuttle","icon":"💥","danger":true}]'
  values: {label: "Ship actions"}
  exclude: [items, name, open, confirm]
---

An actions menu: a trigger opens a list of things the user can *do*, with submenus where a choice needs one. Choosing an item emits `sb-select` with `{ name, value }`, so a page posts it as a command and shows whatever the server renders next. A menu holds no value, so there is nothing pending and nothing to revert.

**An actions menu keeps nothing.** No checkmark, no highlighted row, and the trigger keeps its label: it hands an intent over and forgets it, so everything visible afterwards is the page reacting (the demos here write the value into a signal). A menu that answers a question instead of doing something is the exception, and shows what is chosen: give that one [a current choice](#a-current-choice). For a value in a form, reach for [sb-select](/components/select).

The menu is a native `popover` in the top layer, positioned with CSS anchor positioning where the browser has it (and by hand, flipping and shifting, where it doesn't). No ancestor can clip it.

## Examples

### Actions

`items` is a JSON array. An item is a string, `{value, label?, description?, icon?, disabled?, danger?}`, or `{"divider": true}`; one with an `href` is a [link](#links). The string `"-"` is a divider too, and `{"group": "Move to"}` is a [group heading](#group-headings). Everything in `items` is text: `icon` is a glyph such as an emoji, and no string is ever rendered as HTML. SVG icons and lozenges are the markup form's, see [Rich items](#rich-items).

```html preview
<div data-signals:_actionPick="''" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="ship" label="Ship actions"
    items='["Refuel", {"value":"scan","label":"Long-range scan","icon":"📡","description":"Takes a while"}, "-", {"value":"dock","label":"Dock","disabled":true}, {"value":"scuttle","label":"Scuttle","icon":"💥","danger":true}]'
    data-on:sb-select="$_actionPick = evt.detail.name + ' → ' + evt.detail.value"></sb-dropdown>
  <code data-text="$_actionPick || 'Pick something…'"></code>
</div>
```

### Submenus

An item with `children` opens a submenu instead of reporting a value: the children win, so a parent never emits `sb-select` even when it carries a `value`. Nest up to five levels; anything deeper is dropped, so a runaway tree cannot build a menu nobody can reach.

```html preview
<div data-signals:_docPick="''" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="doc" label="Document"
    items='[{"value":"rename","label":"Rename","icon":"✏️"},
            {"label":"Export","icon":"📦","children":[
              {"value":"csv","label":"CSV"},
              {"value":"json","label":"JSON","description":"Pretty printed"},
              {"label":"Archive","children":[{"value":"zip","label":"ZIP"},{"value":"tar","label":"TAR"}]}]},
            {"label":"Move to","icon":"🗂","children":[{"value":"drafts","label":"Drafts"},{"value":"sent","label":"Sent"},{"value":"trash","label":"Trash","danger":true}]},
            {"divider":true},
            {"value":"delete","label":"Delete","icon":"💥","danger":true}]'
    data-on:sb-select="$_docPick = evt.detail.value"></sb-dropdown>
  <code data-text="$_docPick || 'Nothing chosen yet'"></code>
</div>
```

A submenu opens to the inline end of its parent item and flips to the start when there is no room. Only one submenu per level is open at a time, and choosing a leaf closes every level at once. Submenus are view state: they raise no events and the server never hears about them.

### A current choice

Some menus answer a question instead of doing something: *Sort by*, *Density*, *Theme*. Make the root menu one radio group with `type="radio"`, or a single submenu into one with `type: "radio"` on its parent item.

**An item of the group changes a value, so it emits `sb-change` with `{ name, value }`; every other item stays an intent and emits `sb-select`. A menu may mix both kinds, and no item ever sends both.**

The checked value is `value`, and the server owns it like every other value here: a changed attribute wins, `value=""` clears it, a removed one is ignored, and the live value is the `value` property. The demo plays the server with a signal: the menu reports the choice, the "server" sends the value back, and the check follows it.

```html preview
<div data-signals="{_sortPick: 'name'}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="sort" label="Sort by" type="radio" data-preserve-attr="value"
    data-attr:value="$_sortPick"
    items='[{"value":"name","label":"Name"},{"value":"size","label":"Size"},{"value":"modified","label":"Last modified"}]'
    data-on:sb-change="$_sortPick = evt.detail.value"></sb-dropdown>
  <code data-text="'Sorted by ' + $_sortPick"></code>
</div>
```

A group inside a submenu is the same thing one level down, and the rest of the menu goes on being actions:

```html preview
<div data-signals="{_filePick: 'name', _fileDid: ''}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="file" label="File" data-preserve-attr="value" data-attr:value="$_filePick"
    items='[{"value":"rename","label":"Rename","icon":"✏️"},
            {"label":"Sort by","icon":"↕️","type":"radio","children":[
              {"value":"name","label":"Name"},{"value":"size","label":"Size"},{"value":"modified","label":"Last modified"}]},
            {"divider":true},
            {"value":"delete","label":"Delete","icon":"💥","danger":true}]'
    data-on:sb-change="$_filePick = evt.detail.value; $_fileDid = ''"
    data-on:sb-select="$_fileDid = evt.detail.value"></sb-dropdown>
  <code data-text="$_fileDid ? 'Command: ' + $_fileDid : 'Sorted by ' + $_filePick"></code>
</div>
```

**The trigger shows the choice.** With a value set it reads `<label>: <chosen label>` (*Sort by: Stars*), and falls back to the plain `label` when nothing is chosen. The label stays yours: set it statically or with `data-attr` and the trigger composes from whatever it says. An actions menu is untouched by this and keeps its label as it always did. A chosen [rich item](#rich-items) shows itself instead: its icon and lozenge, with the same text as its accessible name.

#### A group across submenus

A group may reach into its submenus. The value is then the item values from the group root down to the leaf, joined with dots:

```html preview
<div data-signals="{_nestPick: 'stars'}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="order" label="Sort by" type="radio" data-preserve-attr="value" data-attr:value="$_nestPick"
    items='[{"value":"name","label":"Name"},
            {"value":"stars","label":"Stars"},
            {"value":"date","label":"Date","children":[
              {"value":"newest","label":"Newest first"},
              {"value":"oldest","label":"Oldest first"}]}]'
    data-on:sb-change="$_nestPick = evt.detail.value"></sb-dropdown>
  <code data-text="'value=' + JSON.stringify($_nestPick)"></code>
</div>
```

Choosing *Newest first* reports `date.newest`, and the trigger reads *Sort by: Newest first*, the leaf's own label, because the menu already shows which branch it sits in. A parent on the way is never selectable: it opens its submenu like any other parent, reports nothing of its own, and carries a faint mark so you can see where the choice lives. The server sets and clears the whole path as one value, with the rules from above: `value="date.oldest"` moves the check across levels, `value=""` clears it. Keep dots out of the item values of a group: they separate the segments.

**One group per dropdown.** A second `type: "radio"` is ignored and reported to the console instead of guessed at; two questions want two dropdowns. Checkbox groups, with several items checked at once, can follow if anyone needs them.

With `confirm`, the item the user chose stays marked pending until the server's `value` says the same, and `revert()` puts it back when the command never reaches the server (an error status, or `retries-failed`). It is the same contract every value component follows:

```html
<sb-dropdown name="sort" label="Sort by" type="radio" confirm value="name"
  items='[{"value":"name","label":"Name"},{"value":"size","label":"Size"}]'
  data-on:sb-change="@post('/cmd/sort', {payload: {tabid: $tabid, ...evt.detail}})"
  data-on:datastar-fetch="evt.detail.el === el && ['error', 'retries-failed'].includes(evt.detail.type) && el.revert()"></sb-dropdown>
```

```css
sb-dropdown::part(pending) { outline: 1px dashed var(--sb-border-strong); }
sb-dropdown:state(pending) { opacity: 0.85; }
```

### Placement

`placement` is the preferred side and alignment: `bottom-start`, `bottom`, `bottom-end`, `top-start`, `top` or `top-end`. The menu flips to the other side and shifts back into the viewport when there is no room.

```html preview
<div style="display: flex; gap: 12px; flex-wrap: wrap">
  <sb-dropdown label="Bottom end" placement="bottom-end" items='["Rename", "Duplicate", "-", "Delete"]'></sb-dropdown>
  <sb-dropdown label="Top start" placement="top-start" items='["Rename", "Duplicate", "-", "Delete"]'></sb-dropdown>
</div>
```

### Your own trigger

The `trigger` slot fills the trigger with your own content: text, an icon, an `sb-…` component. The button itself stays ours, so `aria-haspopup`, `aria-expanded` and the keyboard live in the shadow root and no morph can strip them. Keep the slot free of interactive elements (a button inside a button), and name the menu with `label`.

```html preview
<sb-dropdown label="More" placement="bottom-end" items='[{"value":"copy","label":"Copy link","icon":"🔗"},{"value":"share","label":"Share","icon":"📤"},{"divider":true},{"value":"remove","label":"Remove","danger":true}]'>
  <svg slot="trigger" width="16" height="4" viewBox="0 0 16 4" fill="currentColor" aria-hidden="true"><rect x="0" y="0" width="4" height="4"/><rect x="6" y="0" width="4" height="4"/><rect x="12" y="0" width="4" height="4"/></svg>
</sb-dropdown>
```

### A caret to match a toolbar button

Beside an `sb-button` with a `caret`, the dropdown can take the button's look: its box and text through `::part(trigger)`, and the caret through `::part(caret)`. Here the caret is a chevron stroked in the text's colour, as thick as the button's, and it turns over while the menu is open (`:state(open)`):

```html preview
<style>
  .tb-menu::part(trigger) {
    min-block-size: 2rem;
    padding-inline: 0.75rem;
    font-size: 0.8125rem;
    background: transparent;
    box-shadow: inset 0 0 0 1px var(--sb-brand-light);
    clip-path: none;
    border-radius: var(--sb-control-radius);
  }
  .tb-menu::part(trigger):hover, .tb-menu:state(open)::part(trigger) { background: var(--sb-brand-subtle); }
  .tb-menu::part(trigger):focus-visible { box-shadow: inset 0 0 0 2px var(--sb-brand-light); }
  .tb-menu::part(caret) {
    box-sizing: border-box;
    inline-size: 7px;
    block-size: 7px;
    color: inherit;
    background: none;
    clip-path: none;
    border: solid currentColor;
    border-width: 0 2px 2px 0;
    translate: 0 -25%;
    rotate: 45deg;
    transition: rotate 120ms, translate 120ms;
  }
  .tb-menu:state(open)::part(caret) { translate: 0 25%; rotate: 225deg; }
</style>
<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px">
  <sb-button variant="outline" size="sm" caret>Export</sb-button>
  <sb-dropdown class="tb-menu" label="Share" items='["Copy link", "Email", "-", "Embed"]'></sb-dropdown>
</div>
```

The page's `::part()` rules win over the component's own whatever their specificity, so a `rotate` set on `::part(caret)` replaces the default turn in both states: give the open one too, with `:state(open)`. Rules that leave `rotate` alone keep the turn, and `color` alone recolours the default caret. The same goes for `::part(trigger)`: a `box-shadow` there replaces the edge, the hover and the focus ring, so give `:hover` and `:focus-visible` their own.

### Items as markup

Instead of `items`, write the menu as light DOM. The items are read **as data** (label, `value`, `disabled`, `data-icon`, `data-description`, `data-danger`, `<hr>` for a divider and `data-group` for a [group heading](#group-headings); an icon or a lozenge inside the item is copied, see [Rich items](#rich-items)) and rendered inside the menu, so the component never writes roles or `tabindex` into your markup, where the next morph would strip them. `items` wins whenever it is not empty.


```html preview
<div data-signals:_crewPick="''" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown label="Crew" data-on:sb-select="$_crewPick = evt.detail.value">
    <button slot="item" value="ada" data-icon="👩‍🚀" data-description="Flight engineer">Ada</button>
    <button slot="item" value="yuri" data-icon="🧑‍🚀">Yuri</button>
    <hr slot="item">
    <button slot="item" value="eject" data-danger disabled>Eject</button>
  </sb-dropdown>
  <code data-text="$_crewPick || 'Nobody yet'"></code>
</div>
```

A slotted item opens a submenu with `data-children='[…]'`, the same JSON as `items`. Markup stops being the clearer form once a menu nests, so a deep tree belongs in `items`:

```html
<button slot="item" data-children='[{"value":"csv","label":"CSV"},{"value":"json","label":"JSON"}]'>Export</button>
```

### Rich items

A markup item can hold more than text. An element marked `data-icon` inside it (an inline `<svg>`, an `<img>`, a `<span>` with a glyph) becomes the row's icon, and any other elements, such as a lozenge with a status name, are shown in the row in place of the plain label. Here is a status menu with pixel icons and a coloured lozenge per status, as a current choice:

```html preview
<style>
  .status-menu::part(lozenge) {
    padding: 0.3em 0.5em;
    background: color-mix(in srgb, var(--tone) 18%, transparent);
    color: var(--tone);
    font-size: 0.6875rem;
    letter-spacing: 0.04em;
    line-height: 1;
    text-transform: uppercase;
  }
</style>
<div data-signals="{_statusPick: 'doing'}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown class="status-menu" name="status" label="Status" type="radio" data-preserve-attr="value"
    data-attr:value="$_statusPick" data-on:sb-change="$_statusPick = evt.detail.value">
    <button slot="item" value="todo">
      <svg data-icon viewBox="0 0 16 16" width="16" height="16" fill="currentColor" shape-rendering="crispEdges" aria-hidden="true" style="color: var(--sb-text-muted)"><path d="M4 3h8v1H4zM3 4h1v8H3zM12 4h1v8h-1zM4 12h8v1H4z"/></svg>
      <span part="lozenge" style="--tone: var(--sb-text-muted)">To do</span>
    </button>
    <button slot="item" value="doing">
      <svg data-icon viewBox="0 0 16 16" width="16" height="16" fill="currentColor" shape-rendering="crispEdges" aria-hidden="true" style="color: var(--sb-info)"><path d="M4 3h8v1H4zM3 4h1v8H3zM12 4h1v8h-1zM4 12h8v1H4zM8 4h4v8H8z"/></svg>
      <span part="lozenge" style="--tone: var(--sb-info)">In progress</span>
    </button>
    <button slot="item" value="review">
      <svg data-icon viewBox="0 0 16 16" width="16" height="16" fill="currentColor" shape-rendering="crispEdges" aria-hidden="true" style="color: var(--sb-warn)"><path d="M4 3h8v1H4zM3 4h1v8H3zM12 4h1v8h-1zM4 12h8v1H4zM5 7h6v2H5z"/></svg>
      <span part="lozenge" style="--tone: var(--sb-warn)">In review</span>
    </button>
    <button slot="item" value="done">
      <svg data-icon viewBox="0 0 16 16" width="16" height="16" fill="currentColor" shape-rendering="crispEdges" aria-hidden="true" style="color: var(--sb-ok)"><path d="M2 7h2v2H2zM4 9h2v2H4zM6 11h2v2H6zM8 9h2v2H8zM10 7h2v2h-2zM12 5h2v2h-2z"/></svg>
      <span part="lozenge" style="--tone: var(--sb-ok)">Done</span>
    </button>
  </sb-dropdown>
  <code data-text="'value=' + $_statusPick"></code>
</div>
```

- **The label** is the item's text without its icon ("In progress"). It stays the row's accessible name, the type-ahead's text and the second half of the trigger's name ("Status: In progress"), while the rich copy beside it is hidden from screen readers. Put the words a person should hear in the lozenge, and keep its marks `aria-hidden`.
- **The rows show copies.** The item stays in your page as you wrote it; the menu draws a copy of the icon and of the other elements, with every attribute, inside its shadow root. A morph that changes the items, an attribute or a text inside them updates the copies, open or not. Leave `id`s out of rich items: the copy would repeat them.
- **Styling the copies:** your page's class rules don't reach into the shadow root, so `.my-lozenge { … }` styles the item in the page, never its copy. Give the element a `part` and style `sb-dropdown::part(lozenge)`. For a colour per item, set a custom property inline (`style="--tone: var(--sb-ok)"`) and read it in the part rule, as above, or give the element a second part name (`part="lozenge lozenge-done"`) and style `::part(lozenge-done)`. `::part()` takes no attribute selectors, so a page that allows no inline styles gives each value a part name of its own, on icons too (`part="icon prio-high"`). An icon takes its colour from `fill`, `currentColor` or an inline `style`, and its box from `::part(icon)`.
- **The trigger** of a radio menu shows the chosen item the way the row does: its icon and its copy, or its icon and its label when it has no other elements. It follows the user's pick at once, like the check, and the server's `value` and new item markup after that. A page that wants something else in the trigger fills the `trigger` slot from its own state: the server renders the current value into it on every frame, so it shows what the server holds, never a pick in flight. With `confirm`, keep the default trigger if the pick should show while it is pending.
- **Actions** work the same: a rich item without `type="radio"` reports `sb-select`, and an `<a slot="item" href>` stays a link.

### Group headings

A heading over a run of items: `<div slot="item" data-group>Move to</div>` in markup, `{"group": "Move to"}` in `items`. The items after it are its group, up to the next heading, a divider or the end of that menu or submenu. Here is a status menu that lists the workflow's next statuses apart from the rest:

```html preview
<div data-signals="{_flowPick: 'doing'}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown name="status" label="Status" type="radio" data-preserve-attr="value"
    data-attr:value="$_flowPick" data-on:sb-change="$_flowPick = evt.detail.value">
    <div slot="item" data-group>Move to</div>
    <button slot="item" value="review">In review</button>
    <button slot="item" value="done">Done</button>
    <div slot="item" data-group>Other</div>
    <button slot="item" value="todo">To do</button>
    <button slot="item" value="doing">In progress</button>
    <hr slot="item">
    <button slot="item" value="archived">Archived</button>
  </sb-dropdown>
  <code data-text="'value=' + $_flowPick"></code>
</div>
```

The same menu in `items`:

```json
[{"group": "Move to"}, {"value": "review", "label": "In review"}, {"value": "done", "label": "Done"},
 {"group": "Other"}, {"value": "todo", "label": "To do"}, {"value": "doing", "label": "In progress"},
 "-", {"value": "archived", "label": "Archived"}]
```

- **A heading is a label**, never an item: it takes no focus, a click on it does nothing, and the arrow keys, Home, End and the type-ahead pass over it.
- **A radio menu stays one choice** however many headings divide it: one value, one check, one `sb-change`. Headings in a submenu work the same way.
- **Text only:** a heading shows its text (markup) or its `group` string (JSON), never HTML or a copied icon.
- **Styling:** muted small text by default; restyle it with `::part(group-label)`.

### Links

An item with an `href` is a link: the menu renders it as `<a role="menuitem" href>`, and the browser follows it. A click, Enter or Space opens it here; a Ctrl, Cmd or middle click, Shift+Enter or the browser's context menu open it in a new tab or window, as with any link, and hovering one shows its URL in the status bar. The menu closes on a click, Enter or Space (a middle click leaves it open), and a link emits no `sb-select`: there is no command to post, the navigation is the result.

In `items`, give the item an `href`; as markup, write `<a slot="item" href>`. Links mix freely with actions, dividers and submenus, and the keys, the type-ahead and `disabled` treat a link like any other item. A disabled link has no `href`, so it goes nowhere.

```html preview
<div style="display: flex; gap: 12px; flex-wrap: wrap">
  <sb-dropdown label="Go to"
    items='[{"value":"gallery","label":"Gallery","icon":"🌌","href":"/"},
            {"value":"playground","label":"Playground","icon":"🧪","href":"/playground"},
            {"label":"Components","icon":"📦","children":[
              {"value":"select","label":"Select","href":"/components/select"},
              {"value":"tree","label":"Tree","href":"/components/tree"}]},
            {"divider":true},
            {"value":"copy","label":"Copy this page’s link","icon":"🔗"}]'></sb-dropdown>
  <sb-dropdown label="Docs">
    <a slot="item" href="/contribute" data-description="How components are built">Contribute</a>
    <a slot="item" href="/themes">Themes</a>
    <hr slot="item">
    <a slot="item" href="/submit" aria-disabled="true">Submit (closed)</a>
  </sb-dropdown>
</div>
```

Markup links stay real links in your page, so crawlers and a page without JavaScript still find them. A parent with `children` drops its `href`, since it opens its submenu, and so does every item of a radio group: a [current choice](#a-current-choice) changes a value and reports `sb-change`.

### Server data

The menu is server data: a new `items` array replaces the whole tree whenever the server likes, and an open menu stays open. The open state is local and lives in a signal, so nothing about it is reset by a morph.

```html preview
<div data-signals="{_fleetAlt: false}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown label="Fleet" data-preserve-attr="items"
    data-attr:items="JSON.stringify($_fleetAlt ? ['Recall', 'Refit', '-', 'Decommission'] : ['Launch', 'Hold', '-', 'Scrub'])"></sb-dropdown>
  <button type="button" data-on:click="$_fleetAlt = !$_fleetAlt">Swap the items (try it while the menu is open)</button>
</div>
```

On a real page the same thing happens without the client signal: the server renders `<sb-dropdown items='…'>` again and the morph brings the new array. `data-preserve-attr` is only needed when a *signal* drives the attribute, as in the demo above.

## With commands

Give it a `name` and post the detail as it is:

```html
<sb-dropdown name="ship" label="Ship actions" items='[{"value":"refuel","label":"Refuel"}]'
  data-on:sb-select="@post('/cmd/ship', {payload: {tabid: $tabid, ...evt.detail}})"></sb-dropdown>
```

A plain item is an intent, not a value: nothing is pending and there is nothing to revert, and the component shows no result of its own (a radio group is the exception, and has both). The server decides, and the page re-renders (see [Commands and components](/contribute#commands-and-components)). When an item starts something slow, let the server render the pending state (a disabled item, a spinner in the page), never the menu.

**Refusals without page code:** a radio group (`type="radio"`) takes `rev`, a number the server bumps with every command it answers for this field, applied or refused (a counter in the tab's state will do; templ: `Rev: sb.Int(n)`). A new `rev` makes the server's `value` win even when it is unchanged, so a refused choice goes back and `:state(pending)` ends, without a `revert()` from the page; the same `rev` again changes nothing. When both change in one morph, the new value applies once. The page's op ids (see [Commands and components](/contribute#commands-and-components)) do the same for every component; `rev` needs nothing on the page. `rev` only helps where the server renders the outcome into this element's own frame. A view rendered once for every tab, and an outcome no command records (a rate limit, a failed request, being offline), need `revert()` instead: see [When `rev` is enough](/contribute#when-rev-is-enough).

**Pending from the page:** `pending` is the page's word that a command is on its way. While the attribute is there, the radio group has `:state(pending)`, its chosen item fades like an unconfirmed one, and `aria-busy` (on its ElementInternals, so morphs keep them), whatever `confirm` says: `data-attr:pending="$_saving"`, with a signal the page sets when it sends and the server's answer clears. The two add up: removing `pending` leaves an unconfirmed edit pending, and `revert()` leaves the page's `pending`. In a region the server morphs, a signal-driven `pending` needs `data-preserve-attr="pending"`.

## Open and closed

Opening and closing is local state. Clicks, the keyboard, an outside click and Escape only ever change a `$$` signal, so a server morph can never re-open a menu the user just closed.

The server still gets a say through the `open` attribute, with the usual rule:

- The first `open` sets the initial state: `<sb-dropdown open items='…'>` is open on the first paint, without the opening animation.
- A **changed** attribute wins over the local state: removing `open` closes the menu, adding it re-opens it.
- Re-sent identical markup changes nothing, because the morph never touches an attribute it already agrees with. That is what makes the attribute safe to render on every frame.
- Any value of `open` means open, `open="false"` too, as in HTML: leave the attribute out to close. A server that rendered no `open` can't close a menu the user opened by sending no `open` again: that is the same markup.

From the client, use the property and the methods instead. They never touch the attribute:

```js
el.open          // true or false, live
el.show()        // open, and move the focus to the first item
el.hide()        // close, and leave the focus where it is
```

Opening moves the focus into the menu one frame after `sb-open`, also when a script opens it (`show()`, a `click()` on the trigger): the first row takes it (ArrowUp on the trigger: the last). A page that records focus (a `focusin` listener) should leave focus inside an open menu alone.

`sb-open` fires when it opens, `sb-close` with `{ reason }` (`item`, `escape`, `outside`, `scroll`, `tab`, `trigger`, `server` or `api`) when it closes. `api` also covers a menu that closes because it was moved or removed while open (a morph that re-attaches it), unless the server's `open` attribute opens it again right away.

```html preview
<div data-signals="{_openState: 'closed'}" style="display: grid; gap: 12px; justify-items: start">
  <sb-dropdown label="Watch me" items='["Rename", "Duplicate", "-", "Delete"]'
    data-on:sb-open="$_openState = 'open'"
    data-on:sb-close="$_openState = 'closed (' + evt.detail.reason + ')'"></sb-dropdown>
  <code data-text="$_openState"></code>
</div>
```

## Text and language

Every text it shows or reads out is a prop with an English default, so a page in another language sets them all. `{name}` in a text stands for a value it fills in.

- `label`: "Actions", the default trigger's text and the menu's name.
- `empty-label`: "Nothing here", shown in a menu or submenu without items.

## Styling

Colours come from `--sb-control-bg`, `--sb-control-border`, `--sb-control-border-hover`, `--sb-surface-raised`, `--sb-surface-hover`, `--sb-brand`, `--sb-brand-light`, `--sb-text-1`, `--sb-text-muted` and `--sb-danger`; `--sb-notch: 0` rounds the pixel corners of trigger and menu, with `--sb-control-radius`, and clips nothing (a page's `box-shadow` on `::part(trigger)` or `::part(menu)` shows), and turns the stepped caret and submenu arrows into plain triangles, the caret turning smoothly. `--sb-shadow-overlay` sets the menu's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.

- **Parts:** `trigger` (the button), `caret`, `menu` (the panel of every level; its popover around it casts the shadow), `icon` (the icon box of a row, and of the trigger showing a rich choice), `label` (a row's text, bold by default), `item`, which also carries `checked`, `onpath` (a parent the choice sits under) and `pending` in a radio group, and `group-label` (a [group heading](#group-headings)). The copies of [rich items](#rich-items) bring their own `part` names. Your page's `::part()` rules win over the component's own, without `!important`.
- **Caret:** a block filled with its `color` (the trigger's text at 70%) and cut by a `clip-path`: a pixel staircase, or a plain triangle at `--sb-notch: 0`. It turns 180° while the menu is open. Recolour it with `color`, or replace the look with `background`, `clip-path`, `mask` or `border` on `::part(caret)`, as in [A caret to match a toolbar button](#a-caret-to-match-a-toolbar-button).
- **States:** `:state(open)` while the menu is open, and `:state(pending)` with `confirm` (see [A current choice](#a-current-choice)). Both work from the page: `sb-dropdown:state(open)::part(trigger)`.

Set the tokens on the element (or any ancestor), not on a part: the component reads them once, on its host.

```css
sb-dropdown { --sb-surface-raised: #1B1030; }
sb-dropdown::part(trigger) { font-weight: 700; }
```

## Accessibility

It follows the WAI-ARIA menu button pattern:

- **Structure:** the trigger is a `button` with `aria-haspopup="menu"` and `aria-expanded`; the menu is a `menu` named by `label`, its rows are `menuitem`s (links are `<a role="menuitem" href>`), dividers are `separator`s and disabled items are `aria-disabled`. A [group heading](#group-headings) is a `group` named by its text (`aria-label`, so a `text-transform` on `::part(group-label)` doesn't change what is announced) that holds the rows after it (`aria-owns`), so a screen reader announces "Move to, group" on entering it; the heading itself is no menu item. A row's name is its label; icons, and the copy of a rich item, are `aria-hidden`.
- **A radio group:** its rows are `menuitemradio` with `aria-checked`, and the group is named by the menu it lives in: the trigger label for a root group, the parent item for a submenu group. Every row of the group reserves the mark column, so the menu does not jump when the choice moves. A parent that only leads to the choice stays a `menuitem` with `aria-haspopup`: its faint mark is decoration (`aria-hidden`), never a checked state. The trigger names what the menu is *and* what is chosen ("Sort by: Newest first"), so the visible text and the accessible name stay the same string.
- **Keys:** Enter, Space and Down open the menu at the first item, Up at the last one. Up and Down move, Home and End jump, typing a few letters jumps to a matching item, and the same letter again cycles through the items it starts (the buffer never leaks from one level into another). Enter and Space choose, Escape and Tab close and hand the focus back to the trigger.
- **Submenus:** Right (Left in a right-to-left page), Enter or Space on a parent opens its submenu and moves the focus to its first item, also when hovering opened it already; Left or Escape closes it again and puts the focus back on the parent item, so the keyboard walks in and out without ever leaving the menu. Escape at the root closes the whole thing. A parent item is a `menuitem` with `aria-haspopup="menu"` and `aria-expanded`, and its submenu is a `menu` named after it.
- **Pointer:** hovering a parent opens its submenu after a moment and leaving closes it a little later, so a diagonal path from the item into the submenu keeps it; a click on the parent keeps it open and moves the focus into it. Tapping a parent opens its submenu and tapping it again closes it, which is the only way back on a touch screen. While the menu has the focus, the focus follows the moving pointer, so one row is current and Enter chooses the row under the pointer.
- **Focus:** real DOM focus moves onto the row inside the shadow root, so screen readers announce it and nothing in your markup is touched. When the server replaces the items while the menu is open, a row that is gone hands the focus to its neighbour, and the focus is only picked back up when it fell on the floor (see [Lists that hold the keyboard](/contribute#lists-that-hold-the-keyboard)). A submenu whose parent is no longer a parent closes itself.
- **Pointer:** an outside click closes the menu, disabled items ignore clicks.
- **Motion:** the opening animation is skipped under `prefers-reduced-motion`, and for a menu that is already open on the first render.
- **Forced colours:** the caret, the checks, the submenu arrows, the dividers and the edges of trigger and menu come back in system colours, and the focused row and trigger get an outline.
