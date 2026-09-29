---
name: Relative Time
tag: sb-relative-time
category: data
summary: '"3 minutes ago" that stays current, in any language, with a server fallback.'
author: zweiundeins
tags: [time, date, relative, intl, i18n]
since: 2026-09-22
preview: |
  <sb-relative-time datetime="2026-09-22T08:00:00Z" numeric="always">22 Sep 2026</sb-relative-time>
playground:
  attrs: {datetime: "2026-09-22T08:00:00Z"}
  exclude: [datetime]
---

Shows a moment relative to now, like "3 minutes ago" or "in 2 days", with the browser's `Intl.RelativeTimeFormat`. It keeps itself current (one timer for every instance on the page, each updated only when its text changes). Hovering shows the full date.

Days count calendar dates in the viewer's time zone, like the date on hover: "yesterday" is the date before today, whatever the hour.

Put a server-formatted date inside it: that is what shows before the component loads, for search engines, and without JavaScript.

## Examples

### Live

```html preview
<div data-signals="{_loaded: Date.now()}">
  This page was opened <sb-relative-time data-attr:datetime="$_loaded" data-preserve-attr="datetime">just now</sb-relative-time>.
</div>
```

### Past and future

`datetime` takes ISO 8601 or Unix time: a number is seconds below 10¹¹, milliseconds from there (March 1973 on). A four-digit number is a year (`2026`), as in HTML's `<time>`.

```html preview
<ul>
  <li>Launch: <sb-relative-time datetime="2026-09-21T09:30:00Z">21 Sep 2026</sb-relative-time></li>
  <li>Next window: <sb-relative-time datetime="2027-01-15T12:00:00Z" format="short">15 Jan 2027</sb-relative-time></li>
  <li>First light: <sb-relative-time datetime="1772668800" numeric="always">5 Mar 2026</sb-relative-time></li>
</ul>
```

### Languages

The page's `lang` (or the `lang` attribute) picks the words, also from inside another component's shadow root. A tag written with an underscore (`de_DE`) works too; one the browser can't read falls back to its own language.

```html preview
<ul>
  <li><sb-relative-time lang="de" datetime="2026-09-21T09:30:00Z"></sb-relative-time></li>
  <li><sb-relative-time lang="fr" datetime="2026-09-21T09:30:00Z"></sb-relative-time></li>
  <li><sb-relative-time lang="ja" datetime="2026-09-21T09:30:00Z"></sb-relative-time></li>
</ul>
```

### A date after a while

With `threshold="7"`, anything more than a week away shows as a date. While the page is open, a past moment turns into a date as it crosses the threshold, and a future one into relative text.

```html preview
<sb-relative-time threshold="7" datetime="2026-01-01T00:00:00Z">1 Jan 2026</sb-relative-time>
```

## From the server

The `datetime` attribute belongs to the server. When a Datastar stream re-renders it with a new moment, the text follows:

```html
<p>Last paint <sb-relative-time datetime="2026-09-22T08:14:03Z">08:14</sb-relative-time></p>
```

## Styling

Style it from your page's CSS — no need to change the component or import anything into it. Custom properties, inherited properties and `::part()` all reach into its shadow root.

- **Fonts and colours:** it is text: it takes the font, size and colour of wherever you put it.
- **Parts:** `time`, the `<time>` element (its `title` shows the full date on hover). The component sets no styles of its own, so your `::part()` rules need no `!important`.

```html preview
<style>
  .my-time { color: var(--sb-text-muted); }
  .my-time::part(time) { font-style: italic; text-decoration: underline dotted; }
</style>
<p>Launched <sb-relative-time class="my-time" datetime="2026-09-01T08:00:00Z">on 1 September</sb-relative-time>.</p>
```

## Accessibility

It renders a `<time datetime="…">` with the full date as its `title`, so the precise moment is available to assistive technology and on hover.
