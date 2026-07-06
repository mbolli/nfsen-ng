# Sankey Diagram

> **Status: in development** — tracked as
> [issue #152](https://github.com/mbolli/nfsen-ng/issues/152). What's
> described here reflects the current working state, not a finished feature.

A flow-volume Sankey diagram (source → destination, ranked by bytes or
packets) rendered with [ECharts](https://echarts.apache.org/), backed by a
new nfdump aggregation query (`SankeyActions.php`) rather than reusing the
Flows/Statistics query shapes.

![Sankey tab](../images/03-page-sankey.png)

## Filters

Same shape as [Statistics](statistics.md) — date range, sources, nfdump
filter, min/max bytes — plus:

| Control | Effect |
|---|---|
| Top pairs | How many src→dst pairs to render |
| Rank / size by | Bytes or packets |

See the [Roadmap](../roadmap.md) for what's left before this is considered
complete.
