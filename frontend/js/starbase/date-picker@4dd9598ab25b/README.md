---
name: Date Picker
tag: sb-date-picker
category: forms
summary: A date field with a calendar, for one date or a range, in the reader's language.
author: zweiundeins
tags: [date, calendar, datepicker, range, booking, intl, i18n, form]
since: 2026-09-29
preview: |
  <sb-date-picker inline mode="range" value='{"start":"2026-10-06","end":"2026-10-09"}' month="2026-10" disabled-dates='["2026-10-14","2026-10-15"]' lang="en-GB" style="zoom: 0.7"></sb-date-picker>
usage: |
  <sb-date-picker label="Launch date" value="2026-10-14"></sb-date-picker>
playground:
  values: {label: Launch date}
  attrs: {value: "2026-10-14"}
  exclude: [value, disabledDates, month, open, name, lang, error, confirm, applyLabel]
  props: {step: {min: 1, max: 3600}}
---

A text field for a date, with a button that opens a calendar. The field shows the date the way the page's language writes it and takes typed dates too. With `inline`, the calendar sits on the page on its own, always visible.

- **`mode="range"`:** pick a start and an end. The range is **one value** (`{start, end}`), committed once, like [`sb-range`](/components/range).
- **`min`, `max`, `disabled-dates`:** days that can't be picked, e.g. booked ones. The server sends them, also one month at a time.
- **`month` and `open`:** view state the server may set. The calendar reports the user paging months with `sb-month`.
- **`time` and `step`:** a time of day with the date. A row of time fields below the days edits the time, and Apply commits the date and the time together. `step` is the granularity in seconds, as on `<input type="datetime-local">`.
- **`time-zone`:** with `time`, an IANA zone (`Europe/Zurich`) or `local` for the viewer's zone. The picker shows and reads the wall clock there, and the value is an instant with the zone's offset.

The value is an ISO date (`2026-09-29`) in and out, whatever the language, with `time` a local date-time (`2026-09-29T14:30`), and with `time-zone` an RFC 3339 instant with its offset (`2026-09-29T14:30+02:00`). The live value is the `value` property, so `data-bind` works.

## Examples

### A date

Type a date, or open the calendar with the button:

```html preview
<div data-signals="{_launch: '2026-10-14'}" style="display: grid; gap: 12px">
  <sb-date-picker label="Launch date" value="2026-10-14" data-bind:_launch__prop.value></sb-date-picker>
  <span>Value: <code data-text="$_launch || 'none'"></code></span>
</div>
```

### A range

The first pick marks the start, the second the end, and only then does the value change, with one `sb-change`. Picking the end first works too: the two are put in order.

```html preview
<div data-signals="{_stay: ''}" style="display: grid; gap: 12px">
  <sb-date-picker mode="range" name="stay" label="Stay" value='{"start":"2026-10-06","end":"2026-10-09"}'
    data-on:sb-change="$_stay = JSON.stringify(evt.detail)"></sb-date-picker>
  <code data-text="$_stay || 'Pick two dates…'"></code>
</div>
```

`el.value` returns `{ start, end }` (or `null` when there is no range), and setting it takes `{ start, end }`, `[start, end]` or the JSON.

### A date and a time

With `time`, the field shows and reads a date and a time, and the calendar has a time row below the days. A day picked in the calendar goes into a draft and moves the focus to the time row's first field; nothing is sent yet. Apply, or Enter in the time row, commits the date and the time as one value.

```html preview
<div data-signals="{_launch: '2026-10-14T09:30'}" style="display: grid; gap: 12px">
  <sb-date-picker time label="Launch" value="2026-10-14T09:30" data-bind:_launch__prop.value></sb-date-picker>
  <span>Value: <code data-text="$_launch || 'none'"></code></span>
</div>
```

The value is a local date-time, `2026-10-14T09:30`, normalised as `<input type="datetime-local">` normalises it: seconds only when they aren't zero, a space accepted for the `T`, and a date alone or a string with a time zone offset read as no value. The value names no zone, so the server knows which one the time is in. With [`time-zone`](#time-zones), the value carries its offset instead.

### Steps and seconds

`step` is the time's granularity in seconds, as on `datetime-local`: `60` (the default) for minutes, `900` for quarter hours, and `1` shows the seconds. A step that isn't a positive number (`step=""`) means `60`, as it does there. Up and Down move the minutes by `step / 60` (the seconds by `step`, below a minute), and a typed value off the step is kept.

```html preview
<div style="display: flex; flex-wrap: wrap; gap: 16px">
  <sb-date-picker time step="900" inline label="Quarter hours" value="2026-10-14T09:15" lang="en-GB"></sb-date-picker>
  <sb-date-picker time step="1" inline label="To the second" value="2026-10-14T09:15:30" lang="en-GB"></sb-date-picker>
</div>
```

### A range with times

With `mode="range"`, the time row has a line for the start and one for the end. A picked day keeps the time its end had; in an empty range the start begins at 00:00 and the end at the last step of its day (23:59 with minutes). Apply sends one `sb-change` with both ends, in time order:

```html preview
<div data-signals="{_window: ''}" style="display: grid; gap: 12px">
  <sb-date-picker mode="range" time inline name="window" label="Maintenance window" month="2026-10" lang="en-GB"
    data-on:sb-change="$_window = JSON.stringify(evt.detail)"></sb-date-picker>
  <code data-text="$_window || 'Pick two days, then Apply'"></code>
</div>
```

### Time zones

With `time-zone`, the picker shows and reads the wall clock of that zone, and the value is an RFC 3339 instant with the zone's offset at that moment. `local` is the viewer's own zone, and so is a name the browser can't read. The calendar's days, today and `disabled-dates` are that zone's days. The same instant, `2026-10-14T07:30:00Z`, in three zones:

```html preview
<div lang="en-GB" data-signals="{_zurich: '2026-10-14T09:30+02:00', _york: '2026-10-14T03:30-04:00', _mine: ''}" style="display: grid; gap: 12px">
  <sb-date-picker time time-zone="Europe/Zurich" label="Zurich" value="2026-10-14T07:30:00Z" data-bind:_zurich__prop.value></sb-date-picker>
  <code data-text="$_zurich"></code>
  <sb-date-picker time time-zone="America/New_York" label="New York" value="2026-10-14T07:30:00Z" data-bind:_york__prop.value></sb-date-picker>
  <code data-text="$_york"></code>
  <sb-date-picker time time-zone="local" label="Your time" value="2026-10-14T07:30:00Z"
    data-init="customElements.whenDefined(el.localName).then(() => $_mine = el.value)" data-on:change="$_mine = el.value"></sb-date-picker>
  <code data-text="$_mine"></code>
</div>
```

The picker shows no zone name, so the page says which zone it shows, as the labels do here. The server can't know the viewer's offset, so the third line reads the value from the picker once it is defined, and after every change. A wall time the zone skips (the spring gap) is read as the time after the gap, and one it repeats (the autumn overlap) as the earlier of the two. A value from the server keeps its own offset: `2026-10-25T02:30+01:00` in `Europe/Zurich` stays `+01:00` through an Apply that leaves the time alone.

### Inline, with bounds and ruled-out days

`min` and `max` limit the calendar, and `disabled-dates` rules out single days. They can be focused and read, but not picked.

```html preview
<sb-date-picker inline label="Launch window" value="2026-10-14" month="2026-10"
  min="2026-10-05" max="2026-11-20"
  disabled-dates='["2026-10-10","2026-10-11","2026-10-17","2026-10-18"]'></sb-date-picker>
```

With `time`, `min` and `max` may be date-times too (with `time-zone`, with or without an offset). A day can be picked when part of it lies between them, and the time row keeps the draft inside them: picking `min`'s day shows `min`'s time. A date alone is the start of that day for `min` and the end of that day for `max`. `disabled-dates` stays a list of whole days.

### Booked days, one month at a time

When the user moves to another month, the picker emits `sb-month` with `{ name, year, month }` (month from 1). The page asks the server for that month's booked days, and the server answers with `disabled-dates`: a signal patch handed over with `data-attr`, or a morph. Here the page makes the days up itself:

```html preview
<div data-signals="{_booked: ['2026-10-03','2026-10-04','2026-10-12','2026-10-13','2026-10-24']}">
  <sb-date-picker inline mode="range" label="Your nights" month="2026-10"
    data-attr:disabled-dates="JSON.stringify($_booked)" data-preserve-attr="disabled-dates"
    data-on:sb-month="$_booked = [3, 4, 12, 13, 24].map((d) => [evt.detail.year, evt.detail.month, d].map((n) => String(n).padStart(2, '0')).join('-'))"></sb-date-picker>
</div>
```

On a real page, `sb-month` asks the server:

```html
<sb-date-picker mode="range" data-attr:disabled-dates="JSON.stringify($_booked)" data-preserve-attr="disabled-dates"
  data-on:sb-month="@get('/booked?year=' + evt.detail.year + '&month=' + evt.detail.month)"></sb-date-picker>
```

```go
func booked(w http.ResponseWriter, r *http.Request) {
	y, _ := strconv.Atoi(r.URL.Query().Get("year"))
	m, _ := strconv.Atoi(r.URL.Query().Get("month"))
	datastar.NewSSE(w, r).MarshalAndPatchSignals(map[string]any{
		"_booked": bookedDays(y, time.Month(m)), // ["2026-10-03", ...]
	})
}
```

The first month comes with the page, so it needs no request. `disabled-dates` is server data: it only flows in, and a new list never touches the value or a range the user is halfway through.

### Languages

Month and weekday names come from the browser's `Intl`, in the element's `lang` (or the nearest one above it, also outside another component's shadow root). The language also picks the first day of the week (Monday where the browser doesn't know) and the format the field shows and reads. A tag with an underscore (`de_DE`) works; one the browser can't read falls back to its own language.

```html preview
<div style="display: grid; gap: 12px">
  <sb-date-picker lang="de" label="Startdatum" value="2026-10-14"></sb-date-picker>
  <sb-date-picker lang="en-US" label="Start date" value="2026-10-14"></sb-date-picker>
  <sb-date-picker lang="ja" label="開始日" value="2026-10-14"></sb-date-picker>
</div>
```

### Typing

The field takes a date in the language's numeric format (`14.10.2026` in German, `10/14/2026` in US English, `2026/10/14` in Japanese, in the language's own digits) or in ISO (`2026-10-14`), with a four-digit year. The placeholder shows the pattern, e.g. `dd.mm.yyyy`. With `mode="range"`, type both dates with a dash between them (`14.10.2026 - 18.10.2026`).

The date is read when the field is committed (Enter, or leaving it). Text that isn't a date, or is a date that can't be picked, stays in the field, marked invalid, and nothing is sent: the picker never guesses. `error` sets the message shown below the field. An empty field clears the value.

```html preview
<sb-date-picker label="Return date" min="2026-10-01" error="Enter a date from 1 October 2026, like 14.10.2026." lang="de"></sb-date-picker>
```

With `time`, type the date and the time as the field shows them: `14.10.2026, 09:30` in German, `10/14/2026, 9:30 PM` in US English, `21:30 14/10/2026` in Vietnamese (which also takes the date first). The hour is 24-hour unless the text has AM or PM (the language's own, or `am` and `pm` in any case), so `21:30` works in US English too, and `12 AM` is midnight. Seconds are optional, and an ISO date-time (`2026-10-14T09:30`, or with a space) works in every language. With `time-zone`, the typed time is the zone's wall clock, and an ISO date-time with an offset or `Z` is read as that instant. A date without a time is not a value.

```html preview
<sb-date-picker time label="Departure" lang="en-US" error="Enter a date and a time, like 10/14/2026, 9:30 PM."></sb-date-picker>
```

## With commands

Give it a `name`, and it emits `sb-change` with `{ name, value }` when the value changes: ready to post as a command. With `confirm`, it sets `:state(pending)` until the server's re-rendered `value` matches, and `revert()` goes back to the server's value when a command is rejected. See [Commands and components](/contribute#commands-and-components).

```html
<sb-date-picker name="launch" confirm value="2026-10-14"
  data-on:sb-change="@post('/cmd/launch', {payload: {tabid: $tabid, ...evt.detail}})"
  data-on:datastar-fetch="evt.detail.el === el && evt.detail.type === 'error' && el.revert()"></sb-date-picker>
```

A new `value` from the server always wins, and `value=""` clears it. Markup re-sent with the same `value` leaves the user's pick alone. A range is one command: the server accepts or rejects both ends together, and validates what lies between them (a booked night inside the range, say).

With `time`, `sb-change` carries a local date-time (`2026-10-14T09:30`), with `time-zone` an instant with the zone's offset (`2026-10-14T09:30+02:00`), and with `mode="range"` both as `{ start, end }`. It is sent once per Apply, Enter in the time row or typed value, never for a day picked in the calendar or a change in the time row. The server may send a zone picker any RFC 3339 instant (`2026-10-14T07:30:00Z`) and gets back the offset form, which parses to the same instant. A new `time` attribute from the server, or with `time` a new `step` or `time-zone`, reads its `value` again in the new format, and that value replaces the local one.

`open` and `month` are view state, not part of the value: never pending, and not touched by `revert()`. The server may set them, and a changed attribute wins (`open="false"` closes). The picker reports the user's changes with `sb-toggle` (`{ name, open }`) and `sb-month`, never for a change the server made.

## Forms

Inside a `<form>`, `sb-date-picker` submits its value under its `name`: the ISO date (`launch=2026-10-14`), with `mode="range"` the JSON of its `value` attribute, and an empty string when there is no date, like `<input type="date">`. With `time` it submits what `<input type="datetime-local">` submits for the same value (`launch=2026-10-14T09:30`, seconds only when they aren't zero), and with `time-zone` the instant with its offset (`launch=2026-10-14T09:30+02:00`). A `disabled` picker submits nothing. `new FormData(form)` and Datastar's `contentType: 'form'` include it, and a form reset brings back the server's value and clears invalid typed text. It is not a form-associated element yet (Rocket can't declare one), so `required` and validity, `<fieldset disabled>`, `<label for>` and the `form` attribute don't reach it. With commands, `sb-change` carries `{ name, value }` (see [With commands](#with-commands)).

## Styling

Style it from your page's CSS, without changing the component or importing anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Size:** the field fills the width it is given, up to `20rem`; set `max-inline-size` on the element to change that. The field is `2.75rem` tall, and each day `2.25rem` square, so the calendar is about `17rem` wide.
- **Fonts:** the label, the field, the month and the days use your page's font.
- **Colours:** the field is `--sb-control-bg` with a `--sb-control-border` edge (`--sb-control-border-hover` on hover) and `--sb-control-text`; the placeholder is `--sb-control-placeholder`, the label `--sb-text-2`, the button and the weekdays `--sb-text-muted`. Focus draws a `--sb-brand-light` edge with a `--sb-brand-subtle` glow, and invalid text a `--sb-danger` edge and message. The calendar is `--sb-surface-raised`; a picked day is `--sb-brand` with `--sb-text-on-brand` text, the days of a range `--sb-brand-subtle`, the day under the pointer `--sb-surface-hover`, and today `--sb-brand-light`. The time fields are drawn like the field, a focused one with a `--sb-brand-light` ring on `--sb-brand-subtle`; Apply is `--sb-brand` (`--sb-brand-hover` under the pointer). Corners are `--sb-control-radius`, and `--sb-notch: 0` rounds the calendar, the days and Apply instead of notching them.
- **Shadow:** `--sb-shadow-overlay` sets the calendar's drop shadow: one shadow without spread, such as `0 8px 16px rgb(0 0 0 / 0.3)`, or `none`.
- **Parts:** `label`, `control` (the field's box), `input`, `button` (opens the calendar), `error`, `calendar`, `title` (the month), `nav` (the four paging buttons) and `grid`. Every date is `day`, plus `today`, `selected`, `range`, `start`, `end` or `disabled` as they apply: `::part(day today)`. With `time`: `time` (a line of the time row), `time-label` (the date in front of it), `segment` (the hour, minute, second and day period fields) and `apply`. Your page's `::part()` rules win over the component's own, without `!important`.

```html preview
<style>
  .my-dates { --sb-brand: #F97316; --sb-brand-light: #FDBA74; --sb-brand-subtle: rgb(249 115 22 / 0.18); --sb-notch: 0; }
  .my-dates::part(day disabled) { text-decoration: none; opacity: 0.3; }
</style>
<sb-date-picker class="my-dates" inline mode="range" month="2026-10" value='{"start":"2026-10-12","end":"2026-10-16"}' disabled-dates='["2026-10-20","2026-10-21"]'></sb-date-picker>
```

## Accessibility

It follows the ARIA date picker dialog pattern:

- **Structure:** the field is a text input with its label. The button (`aria-haspopup="dialog"`, `aria-expanded`) opens the calendar, a dialog named by the label (or "Choose date"). The days are a `grid` named by the month, whose name is announced when it changes. Each day is named by its full date; the picked ones are `aria-selected`, today is `aria-current="date"`, and a day that can't be picked is `aria-disabled`.
- **Keys in the calendar:**
  - Left and Right move a day (mirrored right to left), Up and Down a week.
  - Page Up and Page Down move a month, with Shift a year.
  - Home and End go to the first and last day of the week.
  - Enter or Space picks the day; a day that can't be picked is skipped by picking, but can still be focused and read.
  - Escape closes the calendar, also from the field, and puts the focus back on the button (inline, it drops a half-picked range). In a drawer, a modal or a popover, the first Escape closes only the calendar. Tab moves between the paging buttons and the grid, and stays in the calendar while it is open.
- **Opening:** the focus goes to the picked day, else to today. Without `time`, picking a date closes the calendar and returns the focus to the button.
- **The time row (with `time`):** each line is a group named by its date's full format (by the label while no day is picked), with the date in the numeric format in front of it. Its fields (hour, minute, second when shown, and the day period in 12-hour languages) are text fields with the `spinbutton` role, named in the page's language (`Stunde` in German) and carrying their value, its text and the range. The numeric ones open a number keyboard on phones.
- **Keys in the time row:**
  - Up and Down move a field by its step, wrapping within it (23 to 00) without changing the next field; Home and End go to its first and last value (00 and 59 for the minutes, whatever the step).
  - Digits are typed as in a text field, and two of them move on to the next field, from a range's start line on to its end line.
  - Left and Right move the caret, and from the edge of the text to the previous or next field (mirrored right to left).
  - `a`, `p`, or the first letter of the language's AM or PM set the day period; Up and Down toggle it.
  - Enter applies. Tab moves through the paging buttons, the grid, the time fields and Apply.
- **Focus with `time`:** once the draft has its day (a range: both days), a pick moves the focus to the first field of the (start) line. Apply is `aria-disabled` until then. Apply closes the calendar and puts the focus back on the button; inline, the focus stays. Escape and a click outside close the calendar without applying, and the next open starts from the value again; inline, Escape resets the draft to the value.
- **Invalid text:** the field is `aria-invalid`, and the `error` message is announced (a live region).
- **Disabled:** `disabled` takes the field, the button and the calendar out of the tab order.
- **Forced colours:** focus rings (the time fields' and Apply's too), the arrows, today's mark and Apply's edge use system colours, and picked days `Highlight`.

The calendar is a native popover (`popover="auto"`), so it is never clipped by a scrolling container, and a click outside it closes it.
