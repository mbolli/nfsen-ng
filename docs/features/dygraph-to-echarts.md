# Migrate the Graphs tab from Dygraphs to Apache ECharts

Status: **planned, not implemented** — plan written 2026-07-06, after the Sankey diagram feature (#152) shipped ECharts into the codebase.

## Motivation

The Sankey diagram (`docs/features/152-sankey-diagram.md`) vendored Apache ECharts (`echarts.min.js`, ~1.1MB, the largest single vendored JS asset in the project — `dygraph.min.js` is 131KB). At the time, migrating the Graphs tab off dygraph was deliberately deferred so it wouldn't carry risk into the Sankey delivery. That reasoning holds in reverse now: ECharts is already vendored, already proven working in production-shaped conditions (real nfdump data, dark mode, resize, SSE re-renders) via the Sankey component — the bundle cost is already paid. Not migrating means paying for two charting libraries indefinitely for no reason other than momentum.

This plan assumes the migration happens as its own, standalone piece of work — not bundled with unrelated feature changes — given the Graphs tab is the app's default, most-used view.

## Core architectural decision: swap the engine, not the interface

`nfsen-chart.js`'s public surface — the `<nfsen-chart>` tag, its `data-chart-data`/`data-chart-config` attributes, and its public methods (`updateOptions()`, `setVisibility()`, `resize()`, `xAxisRange()`, `isZoomed()`, `getCurrentRange()`) — is consumed from three other files: `graph-view.html.twig`, `graph-filters.html.twig`, and `date-range.html.twig` (the zoom-sync "Sync now"/"Follow" buttons). All of those call sites pass **dygraph-shaped option objects** directly through, e.g.:

```
$graph._widget.updateOptions({logscale: true})
$graph._widget.updateOptions({stackedGraph: stacked, fillGraph: stacked})
$graph._widget.updateOptions({stepPlot: true})
$graph._widget.updateOptions({ylabel: 'Bits/s', labelsKMG2: true})
```

**Recommendation: keep every one of these call sites unchanged.** Rewrite only the inside of `nfsen-chart.js` — same tag name, same attributes, same method names, same accepted option keys — translating dygraph-shaped input into ECharts calls internally. This means **zero changes** to `graph-view.html.twig`, `graph-filters.html.twig`, `date-range.html.twig`, or any backend code (`GraphActions.php`, `app.php` signals). The entire migration is scoped to one file (`nfsen-chart.js`) plus the vendoring/script-tag cleanup in `layout.html.twig`. This is the same adapter-pattern reasoning that made the Sankey component low-risk to build against an already-established template pattern — here it's even lower risk, since the templates don't change at all.

## Feature-by-feature mapping

| Dygraph feature (current) | ECharts equivalent | Notes |
|---|---|---|
| Line/step/curve/stacked/filled area (`stepPlot`, `stackedGraph`, `fillGraph`) | `series.step`, `series.stack` + `series.areaStyle` | Direct option translation inside `updateOptions()`. |
| Range selector (`showRangeSelector: true`) | `dataZoom` with `type: 'slider'`, `showDataShadow: true` | Verified in the earlier chart-library research (2026-07-06 Sankey planning session) that `showDataShadow` renders the same kind of mini-preview-in-the-handle-track dygraph shows. |
| Zoom/pan (`zoomCallback`, `endPan` override) | `dataZoom` `datazoom` action event | Replaces the `window.Dygraph.defaultInteractionModel.endPan` monkey-patch entirely — ECharts exposes zoom/pan as a normal event (`chart.on('datazoom', ...)`), no prototype patching needed. |
| Click-to-zoom on a point (`clickCallback`) | `chart.on('click', ...)` + `chart.dispatchAction({type: 'dataZoom', ...})` | Same 5-minute-window zoom-in behavior, reimplemented against ECharts' zoom action. |
| Range-selector drag detection (`mousedown`/`mouseup` on `.dygraph-rangesel-*` elements) | Same `dataZoom` `datazoom` event as above — no separate handling needed | Simpler than the current implementation, which needs a manual `querySelectorAll` + mousedown/mouseup listener pair specifically to catch range-selector drags separately from main-plot pan. |
| Log/linear scale (`logscale`) | `yAxis.type: 'log'` / `'value'` | Direct. |
| KMB/KMG2 axis formatting (`labelsKMB`, `labelsKMG2`) | `yAxis.axisLabel.formatter` callback | The existing K/M/G formatting logic is portable almost verbatim into a formatter function. |
| Timezone-aware x-axis labels (`axes.x.axisLabelFormatter`, `xValueFormatter`) | `xAxis.axisLabel.formatter` / `tooltip.formatter` callbacks | Same `tzOptions()` helper (`tz-utils.js`) reused as-is. |
| Dark/light theme (`getDygraphThemeColors()`, `MutationObserver` on `data-bs-theme`) | `chart.setOption()` with recomputed colors, same `MutationObserver` pattern | Directly reuse the `MutationObserver` wiring already in `nfsen-chart.js`; only the color-application call changes from `dygraph.updateOptions()` to `chart.setOption()`. |
| Resize (`ResizeObserver` + `dygraph.resize()`) | Same `ResizeObserver` + `chart.resize()` | Identical shape. **Apply the Sankey-migration lesson**: observe the actual canvas container, not just the outer custom element, and add a defensive `requestAnimationFrame(() => chart.resize())` right after `echarts.init()` (see Gotchas below). |
| `getCurrentRange()` (`dygraph.xAxisRange()`) | `chart.getOption().dataZoom[0]` (start/end percent) converted to timestamps, or track the last `datazoom` event's absolute range | Needed verbatim by `date-range.html.twig`'s "Sync now" button — must return `{from, to}` in the same shape. |
| `isZoomed(axis)` | Compare current `dataZoom` range against the full data range | Used nowhere in the current templates by name search — verify during implementation whether any call site actually depends on it before deciding whether to keep it. |
| `setVisibility(index, visible)` (series checkboxes in `#series` div) | `chart.dispatchAction({type: 'legendToggleSelect', name: seriesName})` or `chart.setOption({legend: {selected: {...}}})` | The external checkbox UI in `#series` stays — `populateSeriesControls()` keeps building the same checkboxes, just calling the new internal method. |
| Custom external legend (`labelsDiv: document.getElementById('legend')`, `labelsSeparateLines: true`) | No direct ECharts equivalent — must hand-roll | **The one genuinely non-trivial piece.** ECharts renders its legend inside its own canvas; there's no built-in "render into an arbitrary external DOM node" option. Reimplement via `chart.on('updateAxisPointer', ...)` (or a `mousemove`/`showTip` listener) to manually update the `#legend` div's HTML with the hovered series names + current values, matching the current per-series-per-line layout. Budget real implementation time for this specifically. |
| Title auto-generation (`getTitle()`) | Same function, applied via `chart.setOption({title: {text: ...}})` | Chart-library-agnostic string logic — copy as-is. |
| View Transitions API wrapping (`startViewTransition`) | Unchanged | Independent of the charting library; the same wrapping logic around `chart.setOption()` calls. |

## What does NOT change

- `graph-view.html.twig`, `graph-filters.html.twig`, `date-range.html.twig` — zero edits, per the adapter-pattern decision above.
- `backend/actions/GraphActions.php`, `app.php` signals (`graph_display`, `graph_sources`, `graph_datatype`, etc.) — the data contract into `nfsen-chart.js` is unchanged.
- `frontend/js/components/tz-utils.js` — reused as-is.
- `echarts.min.js` — already vendored (Sankey work); no new vendoring step needed. `package.json`'s `echarts` dependency and `postinstall` copy step already exist.

## Data transform

`GraphActions::fetchGraphData()` returns the RRD shape `{data: {timestamp: [values...]}, start, end, step, legend}`, which `updateChart()` currently converts into dygraph's `[[Date, val1, val2, ...]]` row format. For ECharts, the equivalent is either:
- an ECharts `dataset.source` array of `[timestamp, val1, val2, ...]` rows (closest to the current transform, minimal rewrite), or
- one `series[]` entry per legend column, each with its own `[[timestamp, value], ...]` data array.

Recommend the `dataset.source` approach — it's the more direct port of the existing conversion logic and keeps a single source of truth for the x-axis across all series, matching how dygraph consumes it today.

## Gotchas learned from building the Sankey component (apply here directly)

1. **`data-ignore-morph`**: already present on `graph-view.html.twig`'s `.chart-container` wrapper (`data-ignore-morph data-ignore`) — this bug is already avoided here, unlike Sankey where it had to be added. No action needed, just don't remove it.
2. **Container-sizing timing**: the Graphs tab is visible by default (`_currentView: 'graphs'` is the initial view), unlike the Sankey tab which starts hidden — so the specific "container measured at 100px because it was `display:none` at `echarts.init()` time" bug is less likely to reproduce here. Still, apply the same defensive fix as a precaution (observe the inner canvas div via `ResizeObserver`, not just the outer element; call `chart.resize()` on the next animation frame after init) since window-resize and dark-mode-toggle scenarios remain identical risk surfaces.
3. **Label clipping at diagram edges**: not applicable to a line/area chart (no sankey-style outside-positioned node labels), so this specific gotcha doesn't carry over.
4. **Benign `AbortError: Transition was skipped`**: already occurs today with dygraph's `view-transition-name: nfsen-chart` under rapid interaction; unrelated to the charting engine, will persist post-migration, not a regression to chase.

## Known pre-existing quirk to explicitly decide on, not silently carry or fix

`updateChart()` currently resets `dateWindow` to the **full new data range** on every single update call — including the periodic 15-second live-refresh interval. This means if a user zooms into a live graph, the next background refresh silently snaps the zoom back out to full view. This is present in the *current* dygraph implementation, not something the migration introduces. Decide explicitly during implementation whether to preserve this behavior as-is (safest, matches current UX exactly) or fix it (probably desirable, but is a behavior change riding on the migration — flag it separately rather than letting it slip in unlabeled).

## Cleanup checklist

- Remove `frontend/js/dygraph.min.js`, `frontend/css/dygraph.css` from the repo.
- Remove the `<script src=".../dygraph.min.js">` and `<link ... dygraph.css>` tags from `layout.html.twig`.
- Update `AGENTS.md` line 4 ("Dygraphs" in the stack summary) to reflect ECharts.
- Do **not** edit `CHANGELOG.md`'s historical dygraph entries — those are a record of the past, not living documentation.
- No `package.json` change needed — dygraph was never an npm-managed dependency (manually vendored), so there's nothing to `pnpm remove`.

## Testing / verification plan

Mirror the approach that caught three real rendering-layer bugs during the Sankey build (data was always correct; the bugs were all in the browser layer, only visible through actual interaction): headless `chromium-browser --headless=new --no-sandbox --remote-debugging-port=...` driven directly over its native CDP WebSocket (Node 22's built-in `WebSocket`), since `chrome-devtools-mcp`'s bundled browser launch doesn't work in this sandboxed environment. Specifically exercise, with real captured data:

- Initial load (default view) renders correctly, no console errors.
- Zoom via main-plot drag, via range-selector drag, and via click-to-zoom-on-point — each should fire the same `graph-zoom` event shape consumed by `date-range.html.twig`.
- "Sync now" and "Follow" buttons against the new `getCurrentRange()` implementation.
- Series visibility checkboxes toggle correctly.
- Stacked/line, step/curve, linear/log toggles.
- Traffic unit (bits/bytes) toggle and its KMG2 formatting.
- Dark/light theme toggle recolors correctly (background, axis lines, grid, series colors).
- Window resize (sidebar toggle equivalent, or just viewport resize) triggers `chart.resize()` correctly.
- Live-refresh behavior (15s interval) — confirm the dateWindow-reset decision (above) was implemented as decided, not left ambiguous.
- The external `#legend` div updates correctly on hover — this is the one hand-rolled piece, test it explicitly rather than assuming ECharts "just handles it."

## Suggested implementation order

1. Rewrite `nfsen-chart.js` internals against ECharts, preserving the exact public interface (tag, attributes, methods, accepted option keys in `updateOptions()`).
2. Build the custom external-legend glue (the one non-trivial new piece) and verify it against real data.
3. Remove dygraph assets + script/link tags from `layout.html.twig`; update `AGENTS.md`.
4. Run the full visual verification checklist above via the CDP-driven headless script.
5. Decide and implement (or explicitly preserve) the live-refresh dateWindow-reset behavior.
6. Manual pass against the real running app (`nfsen-caddy` container, `localhost:8080`) across both light and dark mode, at least one real zoom/pan/sync-button interaction cycle.
