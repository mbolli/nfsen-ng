---
name: Virtual Scroll
tag: sb-virtual-scroll
category: layout
summary: A list of any length that holds only the rows in view, rendered by the server.
author: zweiundeins
tags: [virtual, list, infinite scroll, windowing, large data, grid, hyperlith]
since: 2026-09-29
preview: |
  <sb-virtual-scroll label="Stars" item-size="24" total="40" style="block-size: 8rem; inline-size: 13rem"><div role="listitem">Mornis · K3V</div><div role="listitem">Gararin · G0V</div><div role="listitem">Nebanox · M4IV</div><div role="listitem">Dabalis · G3IV</div></sb-virtual-scroll>
usage: |
  <sb-virtual-scroll id="stars" label="Stars" item-size="32"
    data-on:sb-window="@get('/stars?offset=' + evt.detail.offset + '&count=' + evt.detail.count)"></sb-virtual-scroll>
playground:
  content: <div role="listitem">Sirius</div><div role="listitem">Canopus</div><div role="listitem">Arcturus</div><div role="listitem">Vega</div><div role="listitem">Capella</div><div role="listitem">Rigel</div><div role="listitem">Procyon</div><div role="listitem">Achernar</div><div role="listitem">Betelgeuse</div><div role="listitem">Hadar</div><div role="listitem">Altair</div><div role="listitem">Acrux</div>
  attrs: {total: "12"}
  exclude: [offset, total]
  props:
    itemSize: {min: 12, max: 64}
    columns: {min: 1, max: 6}
    buffer: {min: 0, max: 8000, step: 100}
  values: {label: Bright stars}
  style: "block-size: 10rem"
---

A scroll container for a list of any length. The server renders the items as plain HTML, and the page holds only the rows in view plus a buffer around them. The component does the geometry: it makes the scrollbar as long as the whole list, puts the items where they belong, and asks for a new window when you scroll far enough. This is Anders Murphy's approach in [hyperlith](https://github.com/andersmurphy/hyperlith).

The server owns `offset`, `total` and the items. The scroll position stays in the browser. There is no value to send back, so no command contract either.

## Examples

### A million rows from the server

A star catalog of 1,000,000 lines, which the server computes from each line's index (`/demo/data/list`). Drag the scrollbar anywhere: the rows arrive a moment later, and the bars stand in for rows that are on their way.

```html preview
<style>
  #stars > div { display: flex; gap: 1.5ch; align-items: center; padding-inline: 0.75rem; font-size: 0.75rem; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; }
  #stars b { color: var(--sb-text-1); }
</style>
<sb-virtual-scroll id="stars" label="Star catalog" item-size="17" data-ignore-morph
  data-on:sb-window="@get('/demo/data/list?id=stars&offset=' + evt.detail.offset + '&count=' + evt.detail.count)"></sb-virtual-scroll>
```

1. On connect, and whenever the viewport passes half the buffer, the list emits `sb-window` with `{ offset, count }`: the rows in view plus the buffer (4,000px of rows by default) above and below.
2. `@get` asks the server for that window.
3. The server answers with the host element: the new `offset` and `total`, and the items as its children. Datastar's morph patches it like any other markup.

`data-ignore-morph` keeps this page's own frames away from the list (see [Server side](#server-side)).

### Column headings and jumping to an item

The `header` slot stays at the top while the rows scroll under it, and scrolls sideways with them: these rows are wider than a phone, so the list scrolls both ways. `scrollToIndex()` jumps to any item and so asks for its window.

```html preview
<style>
  #catalog { block-size: 16rem; }
  #catalog > div { display: grid; grid-template-columns: 6.5rem 3.5rem 10rem 6rem 6rem 4rem; gap: 1rem; align-items: center; inline-size: max-content; min-inline-size: 100%; padding-inline: 0.75rem; font-size: 0.8125rem; font-variant-numeric: tabular-nums; }
  #catalog > [slot="header"] { block-size: 2rem; background: var(--sb-surface-card); border-block-end: 1px solid var(--sb-border); color: var(--sb-text-muted); font-size: 0.75rem; }
</style>
<div style="display: grid; gap: 12px; inline-size: 100%">
  <sb-virtual-scroll id="catalog" label="Star catalog" item-size="28" data-ignore-morph
    data-on:sb-window="@get('/demo/data/list?id=catalog&total=100000&header&offset=' + evt.detail.offset + '&count=' + evt.detail.count)">
    <div slot="header" aria-hidden="true"><span>Star</span> <span>Class</span> <span>Constellation</span> <span>Brightness</span> <span>Distance</span> <span>Planets</span></div>
  </sb-virtual-scroll>
  <div style="display: flex; gap: 8px">
    <sb-button size="sm" variant="outline" data-on:click="document.getElementById('catalog').scrollToIndex(0)">First</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="document.getElementById('catalog').scrollToIndex(49999)">Star 50,000</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="document.getElementById('catalog').scrollToIndex(99999, { block: 'end' })">Last</sb-button>
  </div>
</div>
```

The header is part of the host's markup, so the server sends it with every window (`&header` here). Without it, the morph would remove it.

### A grid

`columns` lays the items out in rows of that many: here a million pixels of a nebula, 48 to a row. With many items per row, a smaller `buffer` keeps the windows small.

```html preview
<style>
  #nebula { block-size: 16rem; }
  #nebula .p1 { background: color-mix(in oklch, var(--sb-brand) 20%, transparent); }
  #nebula .p2 { background: color-mix(in oklch, var(--sb-brand) 45%, transparent); }
  #nebula .p3 { background: color-mix(in oklch, var(--sb-brand) 75%, transparent); }
  #nebula .p4 { background: var(--sb-brand-light); }
  #nebula .p5 { background: var(--sb-text-1); }
</style>
<sb-virtual-scroll id="nebula" label="Nebula, one pixel per item" item-size="12" columns="48" buffer="240" data-ignore-morph
  data-on:sb-window="@get('/demo/data/list?id=nebula&kind=pixels&columns=48&offset=' + evt.detail.offset + '&count=' + evt.detail.count)"></sb-virtual-scroll>
```

## Server side

The handler behind these examples is Go with the [Datastar SDK](https://data-star.dev/reference/sdks); any SDK works the same way. `PatchElements` morphs the element with the id in the markup, and `demoListItem` renders a star's line or a pixel:

```go source=internal/web/demo_data.go#demoListKeep,Server.demoList
// demoListKeep are the host attributes the page sets. This endpoint serves any
// page, so it sends only offset, total and the items, and the morph keeps these
// as the page has them. data-ignore-morph keeps the page's own frames, which
// know nothing of the window, away from the list.
const demoListKeep = "item-size columns buffer label role style class data-ignore-morph data-on:sb-window"

// demoList answers an sb-virtual-scroll's sb-window with ?count= items from
// ?offset= (at most 5000), patched into the host with the id ?id=. The list is
// the star catalog: its million stars, or the first ?total=. &header adds the
// column headings; &kind=pixels&columns=<n> sends pixels of a nebula n wide.
func (s *Server) demoList(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	id := q.Get("id")
	if !elementIDRe.MatchString(id) {
		http.Error(w, "id must be an element id", http.StatusBadRequest)
		return
	}
	total, _ := strconv.Atoi(q.Get("total"))
	if total <= 0 || total > demo.CatalogSize {
		total = demo.CatalogSize
	}
	offset, _ := strconv.Atoi(q.Get("offset"))
	count, _ := strconv.Atoi(q.Get("count"))
	offset = min(max(offset, 0), total)
	count = min(max(count, 0), 5000, total-offset)
	cols := 0
	if q.Get("kind") == "pixels" {
		cols, _ = strconv.Atoi(q.Get("columns"))
		cols = max(cols, 1)
	}

	// The whole host: the morph patches it like any other markup.
	var b strings.Builder
	fmt.Fprintf(&b, `<sb-virtual-scroll id="%s" offset="%d" total="%d" data-preserve-attr="%s">`, id, offset, total, demoListKeep)
	if q.Has("header") {
		b.WriteString(`<div slot="header" aria-hidden="true"><span>Star</span> <span>Class</span> <span>Constellation</span> <span>Brightness</span> <span>Distance</span> <span>Planets</span></div>`)
	}
	for i := offset; i < offset+count; i++ {
		// aria-posinset and aria-setsize: the item's place in the whole list.
		attrs, content := demoListItem(i, cols)
		fmt.Fprintf(&b, `<div role="listitem" aria-posinset="%d" aria-setsize="%d"%s>%s</div>`, i+1, total, attrs, content)
	}
	b.WriteString(`</sb-virtual-scroll>`)

	w.Header().Set("Access-Control-Allow-Origin", "*") // public; used from the playground sandbox
	sse := datastar.NewSSE(w, r, datastar.WithCompression(datastar.WithBrotli(datastar.WithBrotliLevel(5)), datastar.WithGzip()))
	sse.PatchElements(b.String())
}
```

- **A query that patches the host**, like this one: it doesn't know the rest of the host's markup, so `data-preserve-attr` tells the morph to keep those attributes. This page re-renders as a whole on every change, from markup that knows nothing of the window, so its lists carry `data-ignore-morph`. The endpoint's own patches still apply: they don't carry the attribute.
- **A page that re-renders as a whole**, like hyperlith's: the window is the tab's state. `sb-window` posts a command that stores `offset` and `count`, and the page renders the list with its window and everything else. Nothing to preserve or ignore then, and the first render comes with its first window.

## Windows

- **What the component asks for:** `sb-window` carries `offset` (the first item, a multiple of `columns`) and `count` (enough rows to fill the viewport and the buffer on both sides). It asks once on connect, again when the viewport passes half the buffer on either side, and after a resize that needs more or fewer rows. Never on every scroll event, one request at a time, and never the window it asked for last: the same request would bring the same answer.
- **What the server sends:** the host with `offset` (the index of its first child, from 0), `total` and the items as its children, in order. It may send fewer items than asked for (a cap): the list then asks for windows that fit the cap, with the viewport in the middle, so the rows in view still come. An empty list is `total="0"` and asks for nothing; a host without `total` asks for its first window again, as on connect.
- **Items:** every item is one row of `item-size` px (or one cell of a row, with `columns`). Give the items no `id`: the morph patches them by position.
- **Placeholders:** the rows outside the current window show bars until their window arrives. The list never shows an item the server hasn't sent.
- **Length:** browsers cap how tall an element can be, Firefox at about 17.9 million px (Chrome and Safari at about 33.5 million). Keep rows × `item-size` under that: a million rows of 17px fit everywhere; for more, use `columns`.

## Methods

`scrollToIndex(index, { block })` scrolls to the item at `index` (from 0). `block` is `'start'` (the default: the item at the top), `'end'`, or `'nearest'` (only as far as needed to show it, for keyboard navigation). When the item is outside the current window, the scroll asks for its window.

The list's own actions are `scroll`, `trackFocus` and `leaveFocus`. Rocket looks up an `@name()` on the host, an item or the header among them first, so a component that renders the list must not give its own actions these names. Datastar's actions, such as `@get` and `@post`, are unaffected.

## Styling

Style it from your page's CSS, without changing the component or importing anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Size:** `block-size` is `20rem` by default; set it on the element (the list needs a height of its own to scroll). It fills the width it is given. Rows are exactly `item-size` px high, and `columns` share the width.
- **Items:** they are your markup, styled by your page's CSS (`#stars > div`). Rows wider than the list make it scroll sideways.
- **Fonts:** the component draws no text.
- **Colours:** the placeholder bars are `--sb-surface-hover`; the focus ring is `--sb-brand-light`. The header has no background of its own: give your header element one, or rows show through it.
- **Parts:** `scroller` (the scrolling box), `header` (the sticky header row) and `window` (the grid that holds the items). Your page's `::part()` rules win over the component's own, without `!important`.
- **Motion:** nothing animates, and jumps are instant. For smooth ones, set `scroll-behavior: smooth` on `::part(scroller)` inside `@media (prefers-reduced-motion: no-preference)`.
- **State:** `:state(loading)` while a window is on its way.

```html preview
<style>
  #styled { block-size: 12rem; border: 1px solid var(--sb-border); border-radius: var(--sb-radius); --sb-surface-hover: var(--sb-brand-subtle); }
  #styled > div { padding-inline: 1rem; align-content: center; border-block-end: 1px solid var(--sb-border-subtle); }
  #styled:state(loading)::part(scroller) { cursor: progress; }
</style>
<sb-virtual-scroll id="styled" label="Star catalog" item-size="36" data-ignore-morph
  data-on:sb-window="@get('/demo/data/list?id=styled&total=10000&offset=' + evt.detail.offset + '&count=' + evt.detail.count)"></sb-virtual-scroll>
```

## Accessibility

- **Roles:** the scroller is a `list`, named by `label`. The items are the server's markup, so the server renders their role: `role="listitem"`, with `aria-posinset` (the position, from 1) and `aria-setsize` (the total), so screen readers know where an item sits in the whole list, not only in the window. The component never changes the items: a morph would strip a role it added, and it would override the role of an item that is a link or a button.
- **Other structures:** a `role` on the host hands the semantics to the page, and the scroller drops its `list` role: a feed (`role="feed"` with `role="article"` items), or a grid (`role="grid"`, rows with `aria-rowindex`). Name the host with `aria-label` then.
- **Keyboard:** when no item has anything to focus, the scroller takes the focus itself, so the arrow keys, Page Up and Down, Home and End scroll it. Otherwise Tab goes to the items' links, buttons, fields and elements with a `tabindex`.
- **Focus across windows:** the morph patches the items by position, so a window can fill the focused element with another item or remove it. A frame after each window, the list puts the focus back on the same element of the item that now has its index, without scrolling, and Tab and Shift+Tab walk the items one at a time across any number of windows. It finds the element by its place among the item's links, buttons, fields and elements with a `tabindex`, counting those in a component's open shadow root too (the box of an `sb-checkbox`); in a row with a `tabindex` of its own, the link in it keeps the focus, not the row. When the element at that place is missing, disabled or hidden, the nearest one before it takes the focus, or else the nearest one after it. A component control that drops its `tabindex` while disabled (the box of a disabled `sb-checkbox`) leaves the count, so the elements after it move up one place. A page that moves the focus itself, like `sb-data-table` with its roving cell, has done so by then.
- **A new list:** when the server sends a list that ends before the focused item, its new last item takes the focus. An item with nothing that takes the focus, and an empty list, give it to the scroller, also when the morph left the focused element showing another item.
- **Focus outside the window:** when the focused item scrolls out of the window, the scroller holds the focus (with `tabindex="-1"` while it does), and a window that brings the item back gives it the focus again. A window never takes the focus back from the rest of the page. With a `role` on the host, the scroller has neither a role nor a name (ARIA allows no name on an element without a role), so it is an unnamed focus stop inside the host's table, grid or feed.
- **Header:** a control in the `header` slot takes the focus without scrolling the list, and a focused item under the header scrolls into view below it. Tab from the header goes to the first item of the window, which can be up to `buffer` px above the view.
