# Filter Builder

The filter builder is a modal `<dialog id="filter-drawer">` that edits the nfdump
filter of one query or alert rule. It is a shell module, so every page has it:
`FilterDrawer` (`backend/pages/FilterDrawer.php`) declares its signals and fills
the Twig key `drawer`, the actions are in `FilterDrawerActions.php`, the template
is `backend/templates/drawer/filter-drawer.html.twig` and the styles are in
`frontend/css/drawer.css`. What the user sees is in the
[guide](../guide/filter-builder.md).

## Targets

The drawer edits one of five targets (`FilterDrawer::TARGETS`). Each is also a
`QueryKit` target, and **Apply** writes into that target's filter signal:

| Target | Title | Filter signal | **Apply and run** clicks |
|---|---|---|---|
| `overview` | Overview | `graph_filter` (the filtered graph) | `data-run="overview"`, the graph's **Apply filter** |
| `talkers` | Top Talkers | `stats_filter` | `data-run="talkers"` |
| `flows` | Flows | `flows_filter` | `data-run="flows"` |
| `conversations` | Conversations | `sankey_filter` | `data-run="conversations"` |
| `alert` | Alert rule | `alert_form_nfdumpFilter` | nothing, the button is hidden |

`viewData()` returns `targets` on every render: per target the label, the wire id
of its filter signal and whether it runs. `components/filter-field.html.twig`
shows the **Builder** and **Saved** buttons only for a target listed there.

## Opening the drawer

Both buttons dispatch a window event:

```js
window.dispatchEvent(new CustomEvent('nfsen-open-drawer', { detail: { target: 'flows', tab: 'builder' } }));
```

`tab` is `builder`, `raw` or `saved`; `saved` shows the Builder tab and focuses
the saved-filter search. An unknown target is ignored. The dialog's handler copies
the field's text into `drawer_filter` (unless it keeps a draft, see below), sets
`drawer_target` and `drawer_open`, calls `showModal()` and posts `drawer-open`.
That action clears the status line and checks the text into `_flt_drawer`; the
sync that follows renders the editor and the saved list.

## Rendering

A closed drawer renders only its frame with a *Loading filters* placeholder.
`viewData()` reads the grammar and the saved list only while `drawer_open` is
true, so a closed drawer adds no store read to a render.
`drawer-close` does nothing but record `drawer_open = false`, which drops the
list and the grammar from the renders that follow. The dialog carries
`data-preserve-attr="open"`, so a morph does not close it. Below 48em the editor
and the saved list stack instead of standing side by side.

After a saved-filter action, `FilterDrawerActions` re-renders the tab while the
drawer is open and sends only the changed signals while it is closed. Outcomes
go to `_drawer_notice` (`{id, level, text}`), the status line under the saved
list; a notice that arrives while the drawer is closed, as after the browser
import at page load, becomes a toast. A duplicate is a warning and a bad name an
error, and neither is logged; any other failure is logged at `LOG_ERR` too. Each
action catches `\Throwable`, so every failure reaches the status line: php-via
would log it and answer `500`, which the browser does not show.

## Editor and grammar

The Builder tab wraps its textarea in `<nfsen-filter-editor>`
(`frontend/js/components/nfsen-filter-editor.js`); the Raw filter tab is a taller
plain textarea bound to the same `drawer_filter`. The grammar comes from
`FilterGrammar` (`backend/query/FilterGrammar.php`):

- `fields()`: 18 Basic and 17 Advanced primitives, each with a label, a snippet
  and a help text. The `<ip>`, `<cidr>`, `<n>` and similar parts of a snippet are
  placeholders.
- `examples()`: eight complete filters with a description.
- `keywords()`: every word the snippets spell out, plus protocol names and a few
  more nfdump words, sorted and unique. The template passes them to the editor in
  `data-grammar`.

A click on a field or an example calls the editor's `insert()`, which puts the
snippet at the cursor, adds a space on either side where it touches other text,
and selects the first placeholder. **Tab** with no suggestion list open selects
the next placeholder after the selection, forward only, so Tab leaves the
textarea once none is left. While the user types, the editor offers up to eight
keywords that start with the word before the caret; **Escape** closes that list
and not the drawer. Suggestions and insertions are announced through the hidden
status element named by `data-status`, because a textarea cannot be a combobox.

`FilterGrammar::PLACEHOLDERS` holds a sample value for every placeholder.
`FilterGrammarTest` fills every snippet with them and runs it, and every example,
through `nfdump -Z` on the real binary, so a snippet nfdump rejects fails the
suite wherever nfdump is installed.

## Validation and estimate

Typing posts `validate-filter?target=drawer` after 300 ms, as in every filter
field, and the answer lands in `_flt_drawer` (see
[Filter validation](../architecture/nfdump-integration.md#filter-validation)).
**Apply and run** is disabled while that answer says invalid or a query is
running.

For a target that runs, the drawer includes `components/query-estimate.html.twig`
with the target `drawer`. `QueryKitActions::estimateTarget()` resolves that to the
target the drawer was opened for, so the estimate uses that query's kind and
window. Like every estimate it follows the range, the sources and the profile,
not the filter text.

## Apply, cancel and drafts

**Apply** closes the dialog, writes `drawer_filter` into the target's filter
signal through Datastar's signal root by wire id, turns on the field's status
announcement and posts `validate-filter` for that target. **Apply and run** does
the same and then clicks the page's button with `data-run="<target>"`.

**Cancel**, **Escape** and the close button post `drawer-close`. When the text in
the drawer differs from the field's, the client signal `_drawer_draftFor`
remembers the target. The next `nfsen-open-drawer` for that target keeps
`drawer_filter` and shows *Unapplied draft restored* with **Discard draft**, which
copies the field's text back and checks it. Opening the drawer for another target
replaces the text with that field's and forgets the draft, so a browser tab keeps
one draft at a time.

## Saved filters

The right-hand column lists `SavedFilterRepository::list()`; uniqueness, order,
origins and the seeding of presets are described under
[SQLite Store](sqlite-store.md#saved-filters). The server sends the whole list,
and the search box hides rows in the browser (`nfsenFilterEditor.matches()`, by
name or expression, case-insensitive). A filter of origin `preference` or
`deployment` carries a *preset* badge.

| Control | Action |
|---|---|
| Star | `filter-star?id=&on=0\|1` |
| Menu, **Apply** | `filter-use?id=`: loads the expression into `drawer_filter`, marks the filter used and checks it; the drawer's **Apply** then takes it into the field |
| Menu, **Edit** | In the browser: loads name and expression into the editor and sets `drawer_edit`; **Save changes** posts `filter-update?id=` |
| Menu, **Rename** | In the browser: sets `drawer_rename` and shows the name input; **Enter** posts `filter-update?id=&rename=1` |
| Menu, **Delete** | A browser `confirm()`, then `filter-delete?id=` |
| **Save current filter** | `filter-save` with `drawer_filter` and `drawer_name`; an empty name falls back to the first 60 characters of the expression |

A name has at most 80 characters. When the store cannot be opened, the column
shows *Saved filters unavailable:* and the reason instead of the list, and the
actions answer with the same text.

## Browser import

At page load the dialog's `data-init` reads `localStorage['stored_filters']`, the
list earlier versions kept in each browser, and posts it to
`filter-migrate-local`. The browser marks itself done in `nfsen-filters-migrated`
only after the server acknowledges, and the old list stays where it is; the
contract is in the [Actions Reference](../api.md#filter-builder). The action seeds
the presets first and skips every expression that is a deployment preset, so a
preset the user deleted does not come back through a browser list.

## Tests

- `tests/Unit/FilterGrammarTest.php`: the primitives, the examples, the keywords
  and the `nfdump -Z` run above.
- `tests/Unit/FilterDrawerActionsTest.php`: every action against an in-memory
  store, the browser import and the view data for an open and a closed drawer.
- `tests/e2e/drawer.test.mjs`: opening from a field, suggestions, inserting and
  placeholders, validation, saving, renaming, editing, starring, searching,
  applying, drafts, the stacked layout, deleting, the alert target and the browser
  import. It saves and deletes its own filters, so it is marked mutating.
