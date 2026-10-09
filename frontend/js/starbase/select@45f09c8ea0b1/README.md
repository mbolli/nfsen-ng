---
name: Select
tag: sb-select
category: forms
summary: A select that can filter, pick several, or autocomplete from the server.
author: zweiundeins
tags: [select, dropdown, combobox, autocomplete, search, form]
since: 2026-09-22
preview: |
  <sb-select label="Destination" placeholder="Pick a planet" value="Mars" options='["Mercury","Venus","Earth","Mars","Jupiter","Saturn"]' style="inline-size: 14rem"></sb-select>
usage: |
  <sb-select label="Destination" placeholder="Pick a planet" options='["Mercury","Venus","Earth","Mars"]'></sb-select>
playground:
  attrs:
    options: '["Mercury","Venus","Earth","Mars","Jupiter","Saturn","Uranus","Neptune"]'
  values: {placeholder: "Pick a planet", searchable: true}
  props:
    maxChips: {min: -1, max: 6}
  exclude: [options, value, delay, minChars, loading, name, remote, total, selectAllLabel, matchesLabel, clearLabel]
---

A select for one or several values:

- **Plain:** pick from a list.
- **`searchable`:** type to filter the options in the browser.
- **`remote`:** type to search on the server. It follows the same pattern as the tree: the select asks with an event, and the server answers with `results`.

The live value is the `value` property (a string, or an array with `multiple`), so `data-bind` works.

## Examples

### Autocomplete from the server

Type a star, planet or moon ("or", "sat", "eu"…), from the site's example dataset:

1. After a short pause, the select emits `sb-search` with the query.
2. `@get('/demo/data/search?q=…&into=_found')` asks the server.
3. The server patches `$_found` with the results.
4. `data-attr:results` hands them back.

`data-indicator` shows the spinner while the request is in flight. Without `loading`, the spinner only covers the pause before `sb-search`.

```html preview
<div data-signals="{_found: [], _body: '', _searching: false}" style="display: grid; gap: 12px">
  <sb-select remote clearable label="Find a star, planet or moon" placeholder="Type a name…"
    data-attr:results="JSON.stringify($_found)"
    data-attr:loading="$_searching"
    data-preserve-attr="results loading"
    data-indicator:_searching
    data-on:sb-search="@get('/demo/data/search?kind=star,planet,dwarf,moon&into=_found&delay=150&q=' + encodeURIComponent(evt.detail.query))"
    data-bind:_body__prop.value></sb-select>
  <span>Picked: <b data-text="$_body || 'nothing yet'"></b></span>
</div>
```

The handler behind this demo is Go with the [Datastar SDK](https://data-star.dev/reference/sdks); any SDK works the same way. `demoAnswer` also serves plain JSON and the demo's `delay`:

```go source=internal/web/demo_data.go#Server.demoSearch,Server.demoAnswer
// demoSearch answers with the bodies whose name matches ?q=, at most ?limit=.
func (s *Server) demoSearch(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	limit, _ := strconv.Atoi(q.Get("limit"))
	limit = min(max(limit, 1), 50)
	if q.Get("limit") == "" {
		limit = 8
	}
	var kinds []string
	for _, k := range strings.Split(q.Get("kind"), ",") {
		if k = strings.TrimSpace(k); k != "" {
			kinds = append(kinds, k)
		}
	}
	var bodies []queries.DemoBody
	if err := s.q.View(r.Context(), func(rd *queries.Reader) (err error) {
		bodies, err = rd.DemoSearch(r.Context(), q.Get("q"), kinds, limit)
		return
	}); err != nil {
		s.fail(w, r, err)
		return
	}
	items := demoItems(bodies)
	s.demoAnswer(w, r, "_found", items, items)
}

// demoAnswer writes the list as JSON, or patches it into the requested signal.
func (s *Server) demoAnswer(w http.ResponseWriter, r *http.Request, defaultSignal string, list, patch any) {
	w.Header().Set("Access-Control-Allow-Origin", "*") // public; used from the playground sandbox
	if ms, _ := strconv.Atoi(r.URL.Query().Get("delay")); ms > 0 {
		select {
		case <-time.After(time.Duration(min(ms, 1500)) * time.Millisecond):
		case <-r.Context().Done():
			return
		}
	}
	// Datastar's own requests accept JSON too: they always get the patch.
	if r.Header.Get("Datastar-Request") == "" && strings.Contains(r.Header.Get("Accept"), "application/json") {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "public, max-age=300")
		json.NewEncoder(w).Encode(list)
		return
	}
	into := r.URL.Query().Get("into")
	if into == "" {
		into = defaultSignal
	}
	if !signalNameRe.MatchString(into) {
		http.Error(w, "into must be a signal name", http.StatusBadRequest)
		return
	}
	datastar.NewSSE(w, r).MarshalAndPatchSignals(map[string]any{into: patch})
}
```

Or the server re-renders the element with a new `results` attribute: a changed attribute always wins.

Until the query has `min-chars` characters, the list shows no results. With `min-chars="0"`, opening the list searches too, with an empty query (e.g. for recent or popular picks).

### Searchable

```html preview
<sb-select searchable label="Planet" placeholder="Type to filter"
  options='[{"value":"mercury","label":"Mercury","description":"0.39 AU"},{"value":"venus","label":"Venus","description":"0.72 AU"},{"value":"earth","label":"Earth","description":"1 AU"},{"value":"mars","label":"Mars","description":"1.52 AU"},{"value":"jupiter","label":"Jupiter","description":"5.2 AU"},{"value":"saturn","label":"Saturn","description":"9.5 AU"},{"value":"pluto","label":"Pluto","description":"No longer a planet","disabled":true}]'></sb-select>
```

### Several values

With `multiple`, the value is an array. Keep it in a signal with `sb-change`: `data-bind` treats array signals as checkbox groups, which is a different thing.

```html preview
<div data-signals="{_crew: ['Ada', 'Yuri']}" style="display: grid; gap: 12px">
  <sb-select multiple searchable clearable label="Crew" placeholder="Add crew"
    options='["Ada","Buzz","Chris","Mae","Sally","Valentina","Yuri"]' value='["Ada","Yuri"]'
    data-on:sb-change="$_crew = evt.detail.value"></sb-select>
  <span>Crew: <b data-text="$_crew.join(', ') || 'nobody'"></b></span>
</div>
```

### A compact picker for a toolbar

With many options, chips don't fit a toolbar. `summary` shows a text in their place, and `actions` puts "Select all" and "Clear" at the top of the list ("Select the N matches" while a search filters it). Nothing picked shows the placeholder, which here means all. The 84 sources are the first stars of the example dataset's catalog (`GET /demo/data/rows?count=84`):

```html preview
<style>
  .sources { inline-size: 15rem; }
  .sources::part(control) { min-block-size: 2.25rem; padding-block: 0; }
  .sources::part(input) { block-size: 1.75rem; }
</style>
<div data-signals="{_stars: {rows: []}, _sources: []}" data-init="@get('/demo/data/rows?count=84&into=_stars')"
  style="display: flex; flex-wrap: wrap; align-items: center; gap: 8px">
  <span>Flows from</span>
  <sb-select class="sources" multiple searchable clearable actions
    summary="{count} of {total} sources" placeholder="All sources"
    data-attr:options="JSON.stringify($_stars.rows.map((s) => ({value: String(s.id), label: s.name, description: s.class + ', ' + s.constellation})))"
    data-preserve-attr="options"
    data-on:sb-change="$_sources = evt.detail.value"></sb-select>
  <span data-text="$_sources.length ? $_sources.length + ' picked' : 'all of them'"></span>
</div>
```

`max-chips` shows a few chips first. With a summary too, the summary takes over from the chips beyond that many:

```html preview
<div style="display: grid; gap: 12px; max-inline-size: 22rem">
  <sb-select multiple searchable max-chips="2" label="Up to two chips, then +K"
    options='["Ada","Buzz","Chris","Mae","Sally","Valentina","Yuri"]' value='["Ada","Mae","Sally","Yuri"]'></sb-select>
  <sb-select multiple searchable max-chips="2" summary="+{more} more" label="Up to two chips, then a summary"
    options='["Ada","Buzz","Chris","Mae","Sally","Valentina","Yuri"]' value='["Ada","Mae","Sally","Yuri"]'></sb-select>
</div>
```

## Compact closed state and list actions

These work with `multiple`.

- **`summary`:** a text in place of the chips. `{count}` is the number picked, `{more}` the picks not shown as chips, and `{total}` the number of options: those in `options`, or, with `remote`, every option the server has offered so far, which only the server can know in full. Set `total` when it does, e.g. `total="84"`. Nothing picked shows the placeholder.
- **`max-chips="N"`:** at most N chips, then a "+K" chip for the rest (its tooltip names them). `max-chips="0"` shows only that count.
- **Both:** up to N picks show as chips. Beyond N, the first N chips stay and the summary takes the place of the "+K" chip, so `summary="+{more} more"` reads like it, in your words. With `max-chips="0"`, only the summary shows; with no `max-chips`, a summary alone is the same as `max-chips="0"`.
- Either keeps the control one line high: chips' labels are cut short to fit. Without them, chips wrap as before.
- **`actions`:** "Select all" and "Clear" rows at the top of the list. While a search filters it, "Select all" becomes "Select the N matches", which adds every enabled option the search shows (the search stays). Each is one change: one `change` and one `sb-change` with the whole new value, and the list stays open. They follow `confirm`, `revert()`, a new `value` from the server and forms like any other pick. An action that would change nothing is disabled.
- **Labels:** `select-all-label`, `clear-label` (also the clear button's accessible name) and `matches-label` (`{count}`; the text before a `|` is for one match: `"Select the match|Select the {count} matches"`).

## With commands

Give it a `name`, and it emits `sb-change` with `{ name, value }` when the value changes: ready to post as a command. With `confirm`, it sets `:state(pending)` until the server's re-rendered `value` matches, and `revert()` goes back to the server's value when a command is rejected. See [Commands and components](/contribute#commands-and-components).

A new `value` from the server always wins, and `value=""` clears it. Markup re-sent with the same `value` leaves the user's pick alone. Typing a search fires no `input` event on the element: `change` and `sb-change` come when the value changes.

## Options

`options` (and `results`, for remote searches) is a JSON array of strings, or of `{value, label?, description?, disabled?}`. The server can change either at any time. A selected value keeps its label even after the options it came from are gone.

## Forms

Inside a `<form>`, `sb-select` submits its value under its `name` (`name=value`; with `multiple`, one entry per picked value and none when nothing is picked, like `<select multiple>`), `new FormData(form)` and Datastar's `contentType: 'form'` include it, and a form reset brings back the server's value and clears a typed search. A `disabled` select submits nothing. It is not a form-associated element yet (Rocket can't declare one), so `required` and validity, `<fieldset disabled>`, `<label for>` and the `form` attribute don't reach it. With commands, `sb-change` carries `{ name, value }` (see [With commands](#with-commands)).

## Styling

Style it from your page's CSS, without changing the component or importing anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Size:** it fills the width it is given, up to `26rem`; set `max-inline-size` on the element to change that. The control is at least `2.75rem` tall and grows as chips wrap; the list is at most `18rem` tall (`max-block-size` on `::part(listbox)`).
- **Fonts:** the label, the text you type, the chips and the options use your page's font.
- **Colours:** the control is `--sb-control-bg` with a `--sb-control-border` edge (`--sb-control-border-hover` on hover) and `--sb-control-text`; the placeholder and the arrow are `--sb-control-placeholder`, the label `--sb-text-2`. Focus draws a `--sb-brand-light` edge with a `--sb-brand-subtle` glow, and chips are `--sb-brand-subtle`. The list is `--sb-surface-raised`; the active option is `--sb-surface-hover` with a `--sb-brand` edge, a selected one `--sb-brand-light`, descriptions `--sb-text-muted`. Corners are `--sb-control-radius`.
- **Shadow:** `--sb-shadow-overlay` sets the list's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.
- **Parts:** `label`, `control` (the box), `input`, `chip` (each chip, with `multiple`), `more` (the "+K" chip), `summary`, `clear` and `listbox` (the list). Your page's `::part()` rules win over the component's own, without `!important`.

```html preview
<style>
  .my-select { max-inline-size: 16rem; --sb-control-radius: 0; }
  .my-select::part(control) { border-width: 2px; }
  .my-select::part(listbox) { max-block-size: 10rem; }
</style>
<sb-select class="my-select" label="Destination" placeholder="Pick a planet" options='["Mercury","Venus","Earth","Mars"]'></sb-select>
```

## Accessibility

It follows the ARIA combobox pattern:

- **Structure:** the input is a `combobox` controlling a `listbox`, with the highlighted option in `aria-activedescendant`. It is `aria-autocomplete="list"` only when you can type (`searchable` or `remote`).
- **Keys:**
  - Down and Up open the list and move through it; Home and End jump.
  - Enter picks, and Escape closes.
  - Without `searchable` or `remote`, Space opens and picks like Enter, and typing jumps to the next option that starts with the letters (the same letter again cycles).
  - Backspace in an empty input removes the last chip shown, with `multiple`. A pick behind "+K" or the summary stays: remove it in the list.
  - The list actions are rows like the options, before them: Home reaches "Select all", and Enter runs it.
- **Selection:** with `multiple`, the combobox's description (`aria-describedby`) lists every pick, also those behind "+K" or a summary. The "+K" chip and the summary are text, not buttons.
- **Loading:** the input is `aria-busy` while results are on their way, and "Searching…", "No results" and "Type to search" are announced (a status region).
- **Disabled:** `disabled` takes it out of the tab order, and neither keys nor the pointer can change it.
- **Forced colours:** the focus ring, the highlighted option and the arrow use system colours.

The list is a native popover, so it is never clipped by a scrolling container.
