# Sankey diagram (src → dst traffic flow)

GitHub: [#152](https://github.com/mbolli/nfsen-ng/issues/152)
Status: **planned, not implemented** — plan finalized 2026-07-06
Related future work: see [Deferred: dygraph → ECharts migration](#deferred-dygraph--echarts-migration) — split into its own doc (e.g. `dygraph-to-echarts.md`) if/when that migration is actually taken up.

## Problem

User wants a Sankey diagram visualizing flow data as source → destination traffic relationships (top talkers by volume, rendered as a flow diagram rather than a table).

The original issue assumed this needs a new nfdump query mode in `Nfdump.php`, a new template/view, a Sankey charting library, and a new route/action. Investigation below confirms most of that but corrects the query-layer assumption — no new nfdump query mode is needed.

## Data layer (verified against real capture data)

Verified directly on this box against `/var/nfdump/profiles-data/live/all` (files with actual flow content live under `2026/01/12` through `2026/03/04` in this sandbox — useful for manual re-verification later):

```
nfdump -M <sources> -R <start>:<end> -a -A srcip,dstip -O bytes -n <topN> -o csv <filter>
```

This already produces exactly the top-N src→dst pairs ordered by byte volume, pre-sorted and pre-truncated by nfdump itself. It reuses infrastructure `FlowActions.php` already has:

- `Nfdump::buildAggregationString(['srcip' => 'srcip', 'dstip' => 'dstip'])` already returns `'srcip,dstip'` — no change needed.
- `-O` (order) and `-n` (top N) are already passed straight through via `setOption()`'s default case.

Sample verified output (`-o csv`, `-a -A srcip,dstip -O bytes -n 5`):

```
ts,te,td,sa,da,sp,dp,pr,flg,fwd,stos,ipkt,ibyt,opkt,obyt,in,out,sas,das,smk,dmk,dtos,dir,nh,nhb,svln,dvln,ismc,odmc,idmc,osmc,mpls1..mpls10,cl,sl,al,ra,eng,exid,tr
2026-02-11 09:51:56,2026-02-20 06:45:36,766420.821,172.24.154.108,149.126.4.47,0,0,0,........,0,0,114225,1658542255,0,0,...
```

`sa`/`da` = source/dest IP, `ibyt`/`ipkt` = bytes/packets. This is the same fixed 48-column schema as `Nfdump::get_output_format('full')` — nfdump's CSV mode always emits this schema regardless of which aggregation keys were requested.

### Gap: no per-pair flow count in the CSV schema

The aggregated CSV schema above has **no flow-count column** — only a query-wide summary total. Confirmed a workaround via a custom format string:

```
nfdump ... -a -A srcip,dstip -O bytes -n 5 -N -o 'fmt:%sa %da %ibyt %ipkt %fl'
```

```
172.24.154.108,149.126.4.47,1658542255,114225,1290
172.24.154.108,140.82.113.22,1189726929,763701,6050
...
```

(`-N` = raw numbers instead of `"1.7 G"`-style human formatting.) `Nfdump::get_output_format('fmt:%sa %da %ibyt %ipkt %fl')` already exists and already derives the header list `['sa','da','ibyt','ipkt','fl']` by splitting on spaces (tested in `NfdumpTest.php`) — it's just never fed back into structured row parsing today.

**User decision: include flows count in v1.** This requires a small, additive change to `Nfdump::execute()`:

- Currently, any `-a` (aggregation) combined with a non-`csv` format hits the `isAggregationWithoutCsv` branch and returns unparsed raw text (`decoded: []`).
- Add a new branch: when `-a` is set and format starts with `fmt:`, parse each output line via `preg_split('/\s+/', trim($line))` (safe here — none of `sa`/`da`/`ibyt`/`ipkt`/`fl` ever contain whitespace), zip against `get_output_format($format)`'s header list, and skip nfdump's own human-readable header/summary/footer lines (same style of detection the existing summary-line branch already uses).
- This is purely additive. The existing `-o csv` (Flows tab) and `-o json` (Stats tab) paths are untouched.
- Extract the line-parsing into a small pure/static method (e.g. `Nfdump::parseWhitespaceDelimitedAggregation()`) so it's unit-testable with the exact sample lines above, without needing a live nfdump binary — matching how `buildAggregationString()`/`buildThresholdFilter()` are tested today.

## Chart library research

The issue suggested "D3-Sankey, Google Charts, or similar." Full comparison:

| | **d3-sankey** | **Apache ECharts** (`sankey` series) | **Custom SVG renderer** | **Google Charts** |
|---|---|---|---|---|
| Self-hostable | Yes — small dist files, no ToS issue | Yes — official single-file UMD (`echarts.min.js`), Apache-2.0 | Yes — no dependency | **No** — see below |
| Vendoring effort | Copy 3 small files (`d3-array`, `d3-shape`, `d3-sankey`), no bundler needed | Copy **one** file, exactly like `dygraph.min.js` today | N/A | N/A |
| Raw bundle size | ~60-90KB minified combined | ~1.8MB minified full lib (Brotli-compressed in transit; still by far the largest vendored JS file — `dygraph.min.js` is 131KB uncompressed) | 0KB | N/A |
| What it gives for free | Layout math only — link paths, tooltips, hover highlighting, resize handling all hand-written | Layout + rendering + tooltips + hover highlighting + resize, all built-in and configurable | Nothing — everything hand-written | Full charting suite |
| Cycle handling (A→B and B→A both in top-N) | Assumes a DAG; needs manual node-depth override to force strict 2-column layout | Handles natively; can constrain to 2-column via `nodeAlign`/manual depth | Not an issue — 2-column layout designed in from the start | N/A |
| Fit with existing codebase style | Matches "vendor a focused lib, wrap in a custom element" (like `nfsen-chart.js` wraps dygraph) | Same pattern, larger lib used for one chart type | Matches "hand-roll it" (`nfsen-table.js`, `nfsen-toast.js`, `nfsen-tooltip.js`) | N/A |
| License / maintenance | BSD-3, standard reference D3 Sankey implementation | Apache-2.0, actively maintained (v6.0, 60k+ GitHub stars, 30 committers) | N/A | Free but ToS-restricted |

**Google Charts ruled out**: confirmed via Google's own docs that self-hosting/offline use of `google.charts.load`/`google.visualization` is against ToS and structurally impossible — the loader dynamically pulls renderer code from `gstatic.com` at runtime regardless of where the loader script itself is served from. This conflicts with the project's fully self-hosted/offline asset model (every other JS dependency — `datastar`, `nouislider`, `dygraph` — is vendored locally).

**Decision: Apache ECharts.** Ships an official single-file UMD build, drops into `frontend/js/` via the same `postinstall` copy pattern already used for `dygraph.min.js`/`nouislider.min.js` — no bundler, no tree-shaking needed for an MVP. Built-in `dataZoom`, legend-based series toggling, and native `sankey` series support meant the least custom rendering code of the viable options.

## Backend implementation

- **`Nfdump.php`**: add the whitespace-delimited aggregation parsing branch described above (additive only — no existing call sites affected).
- **`SankeyActions.php`** (new, mirrors `StatsActions.php`/`FlowActions.php`):
  - Builds the query: `-M` sources, `-R` date range, `-a -A srcip,dstip`, `-O <metric>`, `-n <topN>`, `-N -o 'fmt:%sa %da %ibyt %ipkt %fl'`.
  - Reuses `Nfdump::buildThresholdFilter()` for byte thresholds, same as Stats/Flows.
  - Transforms decoded rows into `{nodes, links}` JSON for the chart.
  - Filters self-loops (`sa === da` — confirmed possible in real captures, e.g. loopback/NAT edge cases).
  - Renders notifications the same way Stats/Flows do (command shown, elapsed time, stderr warnings).
- **`app.php`**: new signals —
  - `sankey_topN` (int, default ~20, clientWritable)
  - `sankey_metric` (`bytes`|`packets`, drives `-O`, clientWritable)
  - `sankey_filter` (nfdump filter text, clientWritable)
  - `sankey_lower_limit`/`sankey_upper_limit` (byte thresholds, reuse existing pattern)
  - `SankeyActions::register($c, ...)` call
  - `sankeyData`/`sankeyNotifications` entries in the `layout.html.twig` render payload
- **`Settings.php`/`settings.php`**: trivial — `defaultView` is a free-form string (`'graphs, flows, statistics'` comment), so `'sankey'` is a valid addition with no enum/validation changes.

## Frontend implementation

- **Vendoring**: add `echarts` to `package.json` dependencies; extend `postinstall` to `cp node_modules/echarts/dist/echarts.min.js frontend/js/`.
- **`frontend/js/components/nfsen-sankey.js`** (new): custom element following the `nfsen-chart.js` shape — `connectedCallback`/`disconnectedCallback`, `ResizeObserver`, dark/light `MutationObserver`, `observedAttributes: ['data-sankey-data']`, wraps `echarts.init`/`setOption`. Registered in `frontend/js/components/index.js`.
- **`backend/templates/partials/sankey-filters.html.twig`** (new): near-copy of `stats-filters.html.twig` — reuse `graph_sources` multiselect, nfdump filter textarea + `nfsen-filter-manager`, byte thresholds. Swap the "Statistic properties" block for "Top N pairs" (select, like `stats_count`) and "Order by" (bytes/packets, like the `graph_trafficUnit` button group).
- **`backend/templates/partials/sankey-view.html.twig`** (new): mirrors `stats-view.html.twig`'s empty-state/queried-state structure; embeds `<nfsen-sankey data-sankey-data="{{ sankeyData }}">`.
- **`nav.html.twig`**: new tab link, `data-on:click__prevent="withViewTransition(() => { $_currentView = 'sankey' })"`.
- **`layout.html.twig`**: new `data-show="$_currentView == 'sankey'"` blocks for both the filter row and the view partial, following the existing four-tab pattern.

### Layout design decision (finalize during implementation)

Strict two-column bipartite layout (all sources left, all destinations right) rather than topology-driven multi-level layout — sidesteps cycle-handling complexity when both A→B and B→A appear in the top-N (common for real bidirectional traffic). Disambiguate a node that's both a source-in-one-pair and dest-in-another via `src:`/`dst:`-prefixed node ids with stripped display labels, so ECharts never has to reconcile role ambiguity.

## Tests

- `NfdumpTest.php`: unit tests for the new whitespace-delimited-aggregation parser, fed the exact sample lines captured above.
- New `SankeyActionsHandlerTest.php` (mirrors `FlowActionsHandlerTest.php`): tests for the row→`{nodes,links}` transform, self-loop filtering, empty-result handling.
- Check `tests/Arch/ArchitectureTest.php` for any structural rule new `*Actions` classes must satisfy before writing the new class.

## Suggested implementation order

1. `Nfdump.php` parser extension + unit tests (isolated, verifiable against real captured output without any UI).
2. `SankeyActions.php` + its tests.
3. Vendor ECharts, build `nfsen-sankey.js` against static/mock JSON first (iterate on the chart without a server round-trip).
4. Wire up templates + nav + `app.php` signals.
5. Manual verification against real data on this box (`2026/01/12` → `2026/03/04` is a known-good range with actual flow data).

## Deferred: dygraph → ECharts migration

While researching the chart library, explored whether ECharts could also replace dygraph in the existing Graphs tab (`nfsen-chart.js`), to justify the ECharts bundle cost across two chart types instead of one.

Every current dygraph feature has a credible ECharts equivalent:

| dygraph feature in use today | ECharts equivalent |
|---|---|
| Range selector (mini preview strip) | `dataZoom` slider with `showDataShadow: true` — renders the same kind of data-preview-in-the-handle-track |
| Zoom/pan → sync button, click-to-zoom | `dataZoom` events (`datazoom` action) + click handler dispatching a zoom action — same shape as the current `zoomCallback`/`endPan` override |
| Per-series visibility checkboxes | `legend.selected` + `dispatchAction({type:'legendToggleSelect'})`, callable from our own checkboxes exactly like `dygraph.setVisibility()` is today |
| KMB/KMG2 axis formatting | `axisLabel.formatter` callback — fully custom, same logic portable 1:1 |
| Log/linear toggle, stacked/step/line | native `yAxis.type:'log'`, `series.stack`, `series.step` |
| Timezone-aware x-axis labels | `axisLabel.formatter` callback, same as today |
| Theme swap on dark/light toggle | `setOption` with updated colors, or `echarts.init(dom, theme)` |
| ResizeObserver-driven resize | `chart.resize()` — identical shape |

**Deliberately not bundled into the Sankey plan.** The Graphs tab is a currently-working, actively-used core view with a lot of finely-tuned interaction glue (view-transitions, pan-sync, live SSE-driven updates) — migrating it is a standalone project with real regression risk and shouldn't block or get tangled with shipping Sankey. Revisit as its own proposal if/when ECharts has proven itself in production via the Sankey tab.
