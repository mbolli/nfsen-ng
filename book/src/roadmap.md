# Roadmap

Tracked as [GitHub issues](https://github.com/mbolli/nfsen-ng/issues).

## Open

- **[#143: v1 broken on FreeBSD](https://github.com/mbolli/nfsen-ng/issues/143).**
  OpenSwoole's C extension doesn't build on FreeBSD
  (openswoole/ext-openswoole#233), and php-via is built on OpenSwoole, so a
  bare-metal FreeBSD install currently can't run this at all. Short-term
  (documenting the limitation in the README/book) is done. Medium-term: whether
  php-via can be made runtime-agnostic (OpenSwoole vs. Swoole vs. an alternative
  server) is an open question that needs a php-via-side answer, not just an
  nfsen-ng one.

## Recently shipped

- **A new layout** (unreleased). A sidebar with Overview, Top Talkers, Flows,
  Conversations, Alerts, Health and Settings, each with its own address; one
  controls bar for the range, sources, protocol and unit; and the traffic graph
  as the range picker above every analysis page. Overview shows key figures and
  the busiest addresses, ports and protocols from lists collected during import
  and stored in SQLite. Queries show their cost before they run, filters are
  checked against nfdump while you type, and a filter builder keeps saved filters
  on the server. Alerts record resolved events, and Health shows capture
  freshness, disks and the recent log. See the [Quick Tour](guide/quick-tour.md).
- **Several nfdump processes per query** (unreleased). The process limit follows
  the CPU cores (`auto`), user queries go ahead of the import and the top-N
  collector, and large Top Talkers, Conversations and Overview exact runs, filtered
  graphs and the top-N backfill run in parallel, with results identical to a
  single nfdump run. The Docker images ship nfdump 1.7.10, and Health shows the
  server's event-loop lag. See [Nfdump Integration](architecture/nfdump-integration.md).
- **[#152: Sankey diagram](https://github.com/mbolli/nfsen-ng/issues/152), done.**
  [Conversations](guide/conversations.md) shows the top source and destination
  pairs as a Sankey, a Matrix and a ranked table, grouped by address, /24, /16 or
  destination port, in one or both directions.
- **[#166: graphs through an nfdump filter](https://github.com/mbolli/nfsen-ng/issues/166).**
  Overview can plot any filter expression instead of only what the datasource
  already aggregated, and Flows has a [Traffic over time](features/flows.md#traffic-over-time)
  section that plots the flow query you just typed, so "when did this happen" and
  "what exactly was it" are one screen.
- **An optional [MCP server](features/mcp.md)**, off by default, giving an AI
  agent read-only access to the same data over stdio or HTTP. Ten tools in two
  cost tiers, so an agent establishes a window from stored aggregates before
  reading capture files.
- **[#171](https://github.com/mbolli/nfsen-ng/issues/171) and
  [#173](https://github.com/mbolli/nfsen-ng/issues/173): import correctness.**
  Backfilling captures older than the install on VictoriaMetrics, per-port
  databases for ports with no traffic, and a configurable per-port direction for
  exporters that report one direction of each flow.
- **[#150: Alerts improvement](https://github.com/mbolli/nfsen-ng/issues/150).**
  Host/subnet and protocol-scoped alerting, implemented as a freeform nfdump
  traffic filter on a rule (see [Alerts](features/alerts.md)) rather than separate
  host and protocol fields: one field covers an ICMP-only or single-subnet rule.
- The traffic graphs moved from Dygraphs to Apache ECharts, so the whole app
  shares one charting library.
- Custom-duration time ranges, for windows the presets don't cover.
- Column visibility and sort order that survive live updates.
- End-to-end browser test suite covering every page (see
  [Testing](development/testing.md)).
