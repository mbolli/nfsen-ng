# Flows

Raw flow-record search: `FlowsQuery` runs nfdump (see
[Nfdump Integration](../architecture/nfdump-integration.md)) over the selected
window and the page shows the records as a table, nfdump's raw output and a
summary. Page: `FlowsPage` (`backend/pages/FlowsPage.php`), actions in
`FlowActions.php` and `FlowGraphActions.php`, per-tab state in `FlowsState`.

![Flows](../images/01-page-flows.png)

## Query inputs

| Signal | Effect |
|---|---|
| `flows_filter` | Raw nfdump filter, validated with `nfdump -Z` while typing (`validate-filter?target=flows`) |
| `flows_limit` | `-c`: 20, 50, 100, 500, 1,000 or 10,000 rows |
| `flows_lower_limit`, `flows_upper_limit` | Byte limits, composed into the filter as `bytes > n` and `bytes < n` |
| `flows_agg_*` | Bidirectional, protocol, source/destination port and per-direction IP aggregation with a prefix, mapped onto `-a`, `-A` and `-B` |
| `flows_orderByTstart` | Sorts the returned rows by start time |

The window, the sources and the global protocol come from the controls bar.
`FilterComposer` parenthesises the user filter, the protocol term and the byte
limits and joins them with `and`, so an `or` in the filter cannot escape the byte
limits. A filter starting with `-` never reaches nfdump's option parser: the
command passes `--` before it.

## Estimate and run

The estimate card (`estimate-query?target=flows`) shows the files, bytes and
seconds of the window before anything runs. Flows is the one kind whose time is an
upper bound: nfdump stops once it has the rows `-c` asks for, and the estimate
says *stops early once the limit is reached*. Flows is not clamped by
`NFSEN_MAX_STATS_WINDOW`, since the limit already bounds it.

`flow-actions` (query kind `flows`) runs `-o json -c <limit>` through
`QueryRunner`, with progress from the bytes nfdump has read and a Kill. Listings
above 1,000 rows run one at a time per worker, after checking that the worker has
the memory to parse them, so two 10,000-row runs cannot exhaust it together.

## The table

The Flows tab is `nfsen-table` (`frontend/js/components/nfsen-table.js`), shared
by every result table of the app. It is a Rocket element whose shadow root holds
only a `<slot>`: the table markup stays in the light DOM, and its rows, page,
sort and focus live in `nfsen/host-state`, so a morph that moves the table into
a new result host keeps them. The server renders the first page (50 rows) in a
result host; the rest arrives in chunks of 1,000 rows that `nfsen/chunks`
(`chunks.js`) pulls with `flows-rows?result=<id>&chunk=<n>`, one event each, so
no single SSE event carries a large result. `chunks.js` loads before the
Datastar bundle, because a `data-effect` on first load reads its
`window.nfsenPullChunks`. A new page empties the table body in one step and
appends its rows 50 per animation frame, holding the table at its old height
until the last batch, so the pager and a focused page button stay put. Paging is client-side, with page sizes 25, 50, 100 and 250.
The pager says *Showing 1-50 of 1,234 returned (limit 10,000)*, and when the limit
was reached, that nfdump cannot skip rows. Sorting, the Columns menu (hidden
columns remembered per browser), CSV/JSON/Print export and the Enhanced data
switch (formatted or raw values in the export) all work on the rows in the
browser. An export waits for the chunks still on their way, and refuses with a
message in the pager when some never arrived.

Clicking an IP address opens the [IP info](ip-info.md) dialog.

## Raw output

The Raw output tab is nfdump's stdout, untouched on every path, with the command
line (`Nfdump::execute()` returns a clean `command` and the `notes` nfdump printed
beside the data). It is sent in 512 KiB chunks through `flows-raw`, and the page
keeps the first 5 MiB of a larger output. Copy and Download wait for the chunks.

## Summary

A `-o json` run prints no summary footer, so the Summary tab is built from three
sources:

- **Returned rows**: flows, packets, bytes, first and last seen, duration and
  averages, computed in PHP from the rows (`FlowsQuery::returnedSummary()`),
  labelled as limited by the row limit when it was reached.
- **Range totals**: `TotalsProvider::fetchProtocolTotals()` of the datasource, the
  unfiltered stored totals of the window per protocol. They are read right after
  the table is sent, from the stored series only.
- **Filtered totals**: `flows-summary-run` (query kind `flows-summary`) runs
  `-o csv -n 0 -s proto/bytes` with the full composed filter, without the row
  limit or aggregation, and sums the protocol rows. It has its own estimate
  (`flows-summary-estimate`) and runs only on **Compute filtered totals**.

## Traffic over time

The folded **Traffic over time** section plots the current flow query over the
window, which is what [#166](https://github.com/mbolli/nfsen-ng/issues/166) asked
for: filter in Flows, then see *when* those flows happened.

![Traffic over time after Build graph](../images/09-page-flows-graph.png)

The series is built by the same per-bin builder as Overview's filtered mode
(`FilteredSeries`), reading the flow filter with its byte limits, exactly the
expression the table runs. `build-flows-graph` (query kind `flowsgraph`) builds
it; `touch-flows-graph` re-renders after a client-side change (the plotted unit)
and reads nothing.

- **The row limit and aggregation options do not apply.** Neither changes which
  records match: one truncates the table, the other regroups its rows. The
  section says so.
- **It never builds on its own.** Plotting a filter means one nfdump run per
  interval, so the section states what it would read (*in 145 nfdump runs*) and
  waits for **Build graph**. The runs go side by side, one per free nfdump
  process, and **Kill** stops them all and keeps the intervals that finished.
  The window is clamped by `NFSEN_MAX_STATS_WINDOW`.
- **A built graph survives a moving window.** The section renders the last build
  and reports that the query changed, instead of blanking a graph somebody waited
  for. The comparison rounds to the five-minute slot, so a live window end does
  not count as a change.

A filtered series has no per-port breakdown: the filter *is* the port selection.
With several sources selected the split is per source, otherwise per protocol.
The chart carries its own legend, since the section has no series panel. Its
unit and the display timezone of its labels come from `data-chart-config`, which
the page sets from client signals and lists in the host's `data-preserve-attr`,
so a server update does not reset them.
