# Sankey Diagram

> **In development** — the Sankey tab is still being built out (tracked as
> [issue #152](https://github.com/mbolli/nfsen-ng/issues/152)); expect
> rough edges.

The **Sankey** tab visualizes *who talks to whom*: each band is a
source→destination pair, sized by bytes or packets, so the biggest
conversations are immediately obvious as the widest bands.

![Sankey tab with real traffic](../images/03-page-sankey.png)

## When to reach for this instead of Statistics

[Statistics](statistics.md) ranks addresses independently — "top 10 source
IPs" and "top 10 destination IPs" are two separate lists. Sankey shows the
*relationship* between them in one picture: which sources are driving
traffic to which destinations, at a glance, without cross-referencing two
tables yourself.

## Running a query

Same pattern as [Flows](browsing-flows.md) and [Statistics](statistics.md):
set your date range and filters, click **Process data**. The extra control
here is **Rank / size by** — Bytes or Packets — which decides how wide each
band is drawn. **Top pairs** caps how many source→destination pairs appear;
past a certain count the diagram gets crowded rather than more useful, so
start small (10–20) and widen only if you need to.

Filters (sources, nfdump filter expression, min/max bytes) work the same as
every other data tab.
