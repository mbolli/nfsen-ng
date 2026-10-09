---
name: Modal
tag: sb-modal
category: feedback
summary: Accessible modal dialogs for any use case.
author: zweiundeins
tags: [dialog, overlay, popup, confirm]
since: 2026-09-21
preview: |
  <sb-modal inline heading="Mission Control"><span>Are you ready to launch?</span><sb-button slot="footer" size="sm" variant="outline" data-sb-close>Cancel</sb-button><sb-button slot="footer" size="sm" data-sb-close>Launch</sb-button></sb-modal>
usage: |
  <button data-on:click="$_confirm.show()">Delete…</button>
  <sb-modal data-ref:_confirm heading="Delete this file?">
    This cannot be undone.
    <button slot="footer" data-sb-close="cancel">Cancel</button>
    <button slot="footer" data-sb-close="delete">Delete</button>
  </sb-modal>
playground:
  content: Are you ready to launch?<sb-button slot="footer" size="sm" variant="outline" data-sb-close>Cancel</sb-button><sb-button slot="footer" size="sm" data-sb-close>Launch</sb-button>
  values: {heading: Mission Control, inline: true}
---

A modal built on the native `<dialog>`. The browser traps focus, closes on Escape and handles the top layer. Open it with `show()` and close it with `close()`, or mark footer buttons with `data-sb-close`.

## Examples

### Open from Datastar

`data-ref` gives you the element as a signal, so any Datastar expression can call its methods.

```html preview
<div>
  <sb-button data-on:click="$_launch.show()">Launch sequence</sb-button>
  <sb-modal data-ref:_launch heading="Mission Control" data-on:sb-close="$_answer = evt.detail.value">
    Are you ready to launch? This cannot be undone.
    <sb-button slot="footer" variant="ghost" data-sb-close="cancel">Cancel</sb-button>
    <sb-button slot="footer" data-sb-close="launch">Launch</sb-button>
  </sb-modal>
  <p data-signals:_answer="''" data-show="$_answer" data-text="'You chose: ' + $_answer"></p>
</div>
```

### Inline

The `inline` attribute renders the panel in place, which is handy for docs and previews. An inline panel is always visible and has no close button.

```html preview
<sb-modal inline heading="Docking complete" closable="false">
  All systems nominal. Welcome aboard.
</sb-modal>
```

### Opened by the server

In a CQRS app the server owns "is the dialog open?". The button below stands in for the server: it sets the `open` **attribute**, exactly like a re-render would, and three seconds later sends `open="false"`. Close the dialog yourself before that and `sb-close` tells the "server", which then sends `open="false"` right away.

```html preview
<div data-signals="{_srv: 'false'}">
  <sb-button data-on:click="$_srv = 'true'; setTimeout(() => $_srv = 'false', 3000)">Server opens it for 3 s</sb-button>
  <sb-modal heading="Incoming transmission" data-attr:open="$_srv" data-preserve-attr="open"
    data-on:sb-close="$_srv = 'false'">
    The server opened this dialog and will close it. Press Escape to close it now.
  </sb-modal>
</div>
```

On a Starbase page the same thing is one `sb-close` handler and one command:

```html
<sb-modal heading="Edit profile" open="{{ .Editing }}"
  data-on:sb-close="@post('/cmd/edit-profile/close', {payload: {tabid: $tabid, ...evt.detail}})">
  …
</sb-modal>
```

The command clears the flag, and the next render sends `open="false"`. Without it the server's `open` stays as it was, and to open the dialog again it first has to change its word.

| The server sends | What happens |
| --- | --- |
| `open` on the first render | Open, as a modal, on the first paint |
| a **changed** `open` value | It wins: `open` opens the dialog, `open="false"` closes it, also one opened with `show()`. No `sb-open` or `sb-close`: the server already knows |
| the **same** markup again | Nothing. A morph never reopens a dialog the user dismissed |
| **no** `open` attribute any more | Nothing. Morphs also strip attributes that were only reflected, so a removal is not an instruction: send `open="false"` |

`show()` and `close()` never write the attribute, so the element's `open` property is the server's word (false after `show()`); `isOpen` tells whether the dialog is showing.

### Buttons that close it

Any element in the dialog with `data-sb-close` closes it and reports `data-sb-close`'s value as `detail.value` (an empty `data-sb-close` reports the element's text). `detail.reason` says what closed it: `action` for these, `button` for the close button, `escape`, `backdrop`, or `api` for `close()`. `sb-open` and `sb-close` fire for `show()`, `close()` and the user, never for a change the server made. In a nested `sb-modal` or `sb-drawer`, `data-sb-close` closes only the one it belongs to.

A `<form>` in the body can't close the dialog with `method="dialog"`: the `<dialog>` is in the component's shadow root. Give a submit button in the footer `form="…"` (the form's `id`), and close the dialog from the form's submit handler or let the server close it.

## Styling

Style it from your page's CSS, without changing the component or importing anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Size:** the dialog is `28rem` wide (less on small screens); set `inline-size` on `::part(panel)` for another width.
- **Fonts:** the heading and the body use your page's font.
- **Colours:** the panel is `--sb-surface-raised` with a `--sb-border` edge and divider, the heading `--sb-text-1`, the body `--sb-text-2`. The backdrop is `--sb-surface-overlay`, slightly blurred. The close button's focus ring is `--sb-brand`; corners are `--sb-radius`.
- **Shadow:** `--sb-shadow-overlay` sets the dialog's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.
- **Parts:** `panel` (the dialog), `heading`, `body`, `footer` and `close` (the close button). Your page's `::part()` rules win over the component's own, without `!important`.

```html preview
<style>
  .my-modal::part(panel) { inline-size: min(22rem, 100%); border-radius: 0; }
  .my-modal::part(heading) { font-size: 1.25rem; }
</style>
<sb-modal class="my-modal" inline heading="Abort launch?">The countdown stops and the crew stands down.</sb-modal>
```

## Accessibility

The native modal `<dialog>` sets `aria-modal`, traps focus and restores it on close. The heading labels the dialog. Escape and a click on the backdrop both close it, and `sb-close` reports why in `detail.reason`. A press that starts inside the dialog, like selecting text and releasing over the backdrop, doesn't close it.
