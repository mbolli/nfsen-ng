---
name: Toast
tag: sb-toast
category: feedback
summary: A toast region the server fills, stacks and announces.
author: zweiundeins
tags: [notification, snackbar, feedback, live-region]
since: 2026-09-23
preview: |
  <sb-toast placement="inline" duration="0" label="Flight deck" style="inline-size: 100%" toasts='[{"id":"a","variant":"ok","title":"Docking complete","text":"All systems nominal."},{"id":"b","variant":"warn","text":"Fuel at 18%."}]'></sb-toast>
usage: |
  <sb-toast toasts='[{"id":"saved","variant":"ok","title":"Saved","text":"Your changes are live."}]'></sb-toast>
playground:
  values: {placement: inline, duration: 0, max: 3}
  attrs: {toasts: '[{"id":"a","variant":"ok","title":"Docking complete","text":"Welcome aboard, commander."},{"id":"b","variant":"warn","text":"Fuel at 18%."},{"id":"c","text":"Telemetry resumes in 30 seconds."}]'}
  style: "inline-size: 100%"
---

The feedback channel for a CQRS app. The server renders *what happened* as a list of toasts; the region stacks them in a corner, announces them to assistive technology and dismisses them again on its own. Nothing is invented in the browser: the toasts are server state, and a morph brings the current list.

## What belongs where

- **The server owns the list.** `toasts` is the whole truth about which messages exist. Add one in a command, render the page again, and it appears. Drop it and it is gone, everywhere, in every tab.
- **The viewer owns the reading.** Which toasts this viewer has already closed, the countdowns, the pause while the pointer or the keyboard is inside, the entrance and exit animations: all local, in `$$` signals and plain closure state, never written back to an attribute. A morph therefore never resurrects a toast that was just closed, and never resets a countdown.
- **No pending, no optimistic updates.** A toast *is* the server's confirmation. It has no `confirm` attribute, no `:state(pending)` and no `revert()`, because nothing here is an edit waiting to be accepted.

Closing a toast is view state, so it emits `sb-dismiss` and hides the toast at once. The server may listen and drop the toast from its list with a command. When an id disappears from `toasts`, the region forgets it completely, so a later toast that reuses the id is shown again.

## Examples

### Stacking, announcing, counting down

Local signals stand in for the server here, which is all a docs page needs. A real page hands `toasts` the list the server rendered.

```html preview
<div data-signals="{_n: 0, _list: []}">
  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-block-end: 12px">
    <sb-button size="sm" data-on:click="$_n++; $_list = [...$_list, {id: 'i' + $_n, text: 'Telemetry packet ' + $_n + ' received.'}]">Info</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="$_n++; $_list = [...$_list, {id: 'i' + $_n, variant: 'ok', title: 'Saved', text: 'Mission plan stored.'}]">Success</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="$_n++; $_list = [...$_list, {id: 'i' + $_n, variant: 'warn', text: 'Fuel at 18%.'}]">Warning</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="$_n++; $_list = [...$_list, {id: 'i' + $_n, variant: 'danger', title: 'Hull breach', text: 'Seal deck 7 immediately.', duration: 0}]">Danger</sb-button>
  </div>
  <sb-toast
    placement="inline"
    max="3"
    duration="6000"
    data-attr:toasts="JSON.stringify($_list)"
    data-preserve-attr="toasts"
    data-on:sb-dismiss="$_list = $_list.filter(t => t.id !== evt.detail.id)"
  ></sb-toast>
</div>
```

Hover a toast, or tab into its dismiss button, and every countdown stops until you leave. The danger toast carries `duration: 0`, so it waits for a human. A dismissed toast fades out even though the page drops it from the list at once.

### In a corner

`placement` pins the stack to one of the six corners of the viewport; `inline` (used in these docs) puts it in the page flow instead. The newest toast always sits closest to the edge the region is pinned to, and the DOM order follows the visual order, so Tab and screen readers move the way the eye does.

```html preview
<div data-signals="{_m: 0, _corner: []}">
  <sb-button data-on:click="$_m++; $_corner = [...$_corner, {id: 'c' + $_m, variant: 'ok', title: 'Command accepted', text: 'Burn scheduled for T-minus 40.'}]">Send a command</sb-button>
  <sb-toast
    placement="bottom-end"
    label="Mission notifications"
    data-attr:toasts="JSON.stringify($_corner)"
    data-preserve-attr="toasts"
    data-on:sb-dismiss="$_corner = $_corner.filter(t => t.id !== evt.detail.id)"
  ></sb-toast>
</div>
```

### With commands

In a CQRS app nothing about a toast is decided in the browser. A command runs, the page renders again, and the server's render contains the toast:

```html
<sb-toast placement="top-end" max="4" duration="6000"
  toasts='[{"id":"7f2","variant":"ok","title":"Saved","text":"Mission plan stored."}]'
  data-on:sb-dismiss="@post('/cmd/toast-seen', {payload: {tabid: $tabid, ...evt.detail}})"></sb-toast>
```

The command that produced the message queues it for this session (in `tab_state`, say, next to the other server-owned UI state); the page renders the queue into `toasts`. `sb-dismiss` posts the id back, the command drops it from the queue, and the next render no longer contains it. The region does not wait for that round trip: the toast is hidden the moment it is dismissed, and the server's answer only has to agree.

### A link or an action

A toast can carry one control: a link (`href`) or a button (`action`), with the page's own words for it in `label`. A news toast opens what it is about:

```html
<sb-toast placement="top-end" duration="8000" label="Mitteilungen" dismiss-label="Schliessen: {message}"
  toasts='[{"id":"m42","variant":"ok","title":"Das Treffen steht","text":"Samstag, 14 Uhr, mit Anna.","href":"/matches/42","label":"Treffen öffnen"}]'
  data-on:sb-dismiss="@post('/cmd/toast-seen', {payload: {tabid: $tabid, ...evt.detail}})"></sb-toast>
```

An action asks the server to do something, and the toast closes once it is sent:

```html
<sb-toast placement="top-end" label="Mitteilungen"
  toasts='[{"id":"n7","title":"Neue Nachricht","text":"Anna: Passt dir 14 Uhr?","action":"reply","label":"Antworten"}]'
  data-on:sb-toast-action="@post('/cmd/toast-action', {payload: {tabid: $tabid, ...evt.detail}})"
  data-on:sb-dismiss="@post('/cmd/toast-seen', {payload: {tabid: $tabid, ...evt.detail}})"></sb-toast>
```

`sb-toast-action` carries `{id, action}`; the `sb-dismiss` that follows it (reason `"user"`) lets the server drop the toast as for any other close.

```html preview
<div data-signals="{_k: 0, _news: [], _did: ''}">
  <div style="display: flex; flex-wrap: wrap; gap: 8px; margin-block-end: 12px">
    <sb-button size="sm" data-on:click="$_k++; $_news = [...$_news, {id: 'n' + $_k, variant: 'ok', title: 'Docking slot confirmed', text: 'Bay 4 at 14:00.', href: '#bay-4', label: 'Open the docking plan'}]">News with a link</sb-button>
    <sb-button size="sm" variant="outline" data-on:click="$_k++; $_news = [...$_news, {id: 'n' + $_k, title: 'New message', text: 'Ground: hold at waypoint 3?', action: 'reply', label: 'Reply'}]">Message with an action</sb-button>
  </div>
  <sb-toast
    placement="inline"
    duration="10000"
    data-attr:toasts="JSON.stringify($_news)"
    data-preserve-attr="toasts"
    data-on:sb-toast-action="$_did = evt.detail.action + ' on ' + evt.detail.id"
    data-on:sb-dismiss="$_news = $_news.filter(t => t.id !== evt.detail.id)"
  ></sb-toast>
  <p data-show="$_did" data-text="'sb-toast-action: ' + $_did"></p>
</div>
```

If the page drives `toasts` from a signal instead of rendering the attribute, add `data-preserve-attr="toasts"`, or the next morph resets the attribute to the server's markup:

```html
<sb-toast data-attr:toasts="JSON.stringify($messages)" data-preserve-attr="toasts"></sb-toast>
```

## Toasts

Each entry of `toasts` is `{id, title?, text, variant?, duration?, href?, action?, label?}`:

- `id`: stable and unique in the list. It is the only thing that ties a dismissal to a toast, so reuse it for the same message and never for a different one. Without an id, the message is the id (`"Title. Text"`, or just the text), with `#2`, `#3`… for repeats of the same message. Repeats can't be told apart that way: once the first is dismissed and dropped, the next one takes over its id and goes with it, so give repeated messages ids.
- `title`: an optional bold first line, in the tone colour.
- `text`: the message. A plain string in the array is read as `{text}`, so its id is the string itself.
- `variant`: `info` (the default), `ok`, `warn` or `danger`. It sets the tone colour and how the message is announced.
- `duration`: ms for this toast, overriding the `duration` prop. `0` makes it sticky.
- `href`: a link under the message, `label` its text. A plain click (or Enter) follows it and closes the toast, with `sb-dismiss`; a click that opens it elsewhere (Ctrl, Cmd, Shift, Alt or the middle button) keeps the toast. A `javascript:` URL is not shown.
- `action`: a name. The toast gets a button with `label` as its text; activating it emits `sb-toast-action` with `{id, action}` and closes the toast, with `sb-dismiss`. With both `href` and `action`, the toast has the link.
- `label`: the text of the link or button. There is no built-in one: without a `label`, `href` and `action` are ignored.

Only the newest `max` toasts are rendered, and only rendered toasts count down. Older ones wait, and take their turn when a newer one goes, unless the server has dropped them by then.

## Events

- `sb-dismiss`: `{id, reason: "user" | "timeout"}`, after a toast went away (its close button, its link or action, `dismiss()`, `clear()`, or its countdown).
- `sb-toast-action`: `{id, action}`, when a toast's action button is activated, just before the toast closes.

## Methods

- `dismiss(id)`: dismiss one toast, exactly as the button does (including `sb-dismiss`).
- `clear()`: dismiss every toast, including those waiting behind `max` (each one emits `sb-dismiss`).

## Text and language

Every text it shows or reads out is a prop with an English default, so a page in another language sets them all. `{name}` in a text stands for a value it fills in.

- `label`: "Notifications", the name of the region.
- `dismiss-label`: "Dismiss: {message}", the name of each toast's close button; {message} is the toast's title and text.

A toast's own texts, the link's or action's `label` included, come with the toast and are never replaced.

## Styling

Every part is styleable from the page: `::part(region)`, `::part(toast)`, `::part(title)`, `::part(text)`, `::part(action)` (the link or action button), `::part(close)` and `::part(bar)` (the remaining-time bar). Tones come from `--sb-info`, `--sb-ok`, `--sb-warn` and `--sb-danger`, the surface from `--sb-surface-raised`, and the stack sits at `--sb-z-toast`. Two component variables tune the geometry:

- `--sb-toast-width`: width of the stack (default `22rem`; it never exceeds the viewport).
- `--sb-shadow-overlay`: the toasts' drop shadow, one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.
- `--sb-toast-inset`: distance from the edges (default `1rem`).

Corners and the status light notch with `--sb-notch`, so the 8-bit look can be turned off per theme (the light is a dot at `0`). For `prefers-reduced-motion: reduce` the entrance and exit animations are dropped, and the bar jumps down in fifths rather than slides.

## Accessibility

- **Announcing is separate from the visible stack.** The stack moves, fades and reorders, which a live region would read out again and again. So the toasts sit in a plain `role="region"` with `label` as its accessible name, and two visually hidden live regions do the announcing: a polite `role="status"` for `info` and `ok`, an assertive `role="alert"` for `warn` and `danger`. An urgent message interrupts, an ordinary one waits for a pause.
- **Focus is never stolen.** Nothing is focused when a toast appears. Each toast has a dismiss button named after its message ("Dismiss: Saved. Mission plan stored."), reachable with Tab in the order the toasts are shown. Keyboard focus stays with its toast while the stack changes. When that toast goes, focus moves to its neighbour, and after the last one back to where it came from (unless that was inside another component's shadow root, which the browser does not reveal).
- **Links and actions are real controls.** A toast's link is an `<a href>` and its action a `<button>`, after the message and before the close button in the tab order. Each is named by its `label` and described by the toast's title and text (`aria-describedby`), so it reads as "Treffen öffnen, link, Das Treffen steht Samstag, 14 Uhr" in focus. The live region reads the label after the message, so a listener knows the toast has something to open before tabbing to it. When the link or action closes its toast, keyboard focus moves on like after a dismissal, to the same kind of control in the next toast if it has one.
- **Time can be stopped.** Every countdown pauses while the pointer is over the region or the keyboard focus is inside it, so a toast cannot vanish while it is being read or its button is being aimed at. (A button focused by a mouse click does not pause anything, or dismissing one toast would stop all the others.) A toast that must not disappear at all gets `duration: 0`; one with a link or action that matters may want a longer `duration`.
- **A hidden page waits.** While the page is hidden (`document.visibilityState` is `"hidden"`: a background tab, a minimised window), every countdown stands still, and it resumes when the page is shown again, so a toast never expires unseen.
- **The countdown is decoration.** The bar is `aria-hidden`; it never carries information that is not in the text.
