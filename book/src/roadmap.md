# Roadmap

Tracked as [GitHub issues](https://github.com/mbolli/nfsen-ng/issues).

## Open

- **[#143 — v1 broken on FreeBSD](https://github.com/mbolli/nfsen-ng/issues/143).**
  OpenSwoole's C extension doesn't build on FreeBSD
  (openswoole/ext-openswoole#233), and php-via is built on OpenSwoole, so a
  bare-metal FreeBSD install currently can't run this at all. Short-term
  (documenting the limitation in the README/book) is done. Medium-term:
  whether php-via can be made runtime-agnostic (OpenSwoole vs. Swoole vs. an
  alternative server) is an open question that needs a php-via-side answer,
  not just an nfsen-ng one.

## Recently shipped

- **[#166 — graphs through an nfdump filter](https://github.com/mbolli/nfsen-ng/issues/166).**
  The Graphs tab can plot any filter expression instead of only what the
  datasource already aggregated, and the Flows tab grew a
  [Traffic over time](features/flows.md) section that plots the flow query you
  just typed, so "when did this happen" and "what exactly was it" are one screen
  without being one tab.
- **An optional [MCP server](features/mcp.md)**, off by default, giving an AI
  agent read-only access to the same data over stdio or HTTP. Ten tools in two
  cost tiers, so an agent establishes a window from stored aggregates before
  reading capture files.
- **[#171](https://github.com/mbolli/nfsen-ng/issues/171) and
  [#173](https://github.com/mbolli/nfsen-ng/issues/173) — import correctness.**
  Backfilling captures older than the install on VictoriaMetrics, per-port
  databases for ports with no traffic, and a configurable per-port direction for
  exporters that report one direction of each flow.
- **[#152 — Sankey diagram](https://github.com/mbolli/nfsen-ng/issues/152).**
  The Sankey tab (see [Sankey Diagram](features/sankey.md)) aggregates
  flows into top-N src/dst pairs and renders them with ECharts.
- **[#150 — Alerts improvement](https://github.com/mbolli/nfsen-ng/issues/150).**
  Host/subnet and protocol-scoped alerting, implemented as a freeform nfdump
  traffic filter on a rule (see [Alerts](features/alerts.md)) rather than
  separate host/protocol fields — covers the same use case (an ICMP-only or
  single-subnet rule) with one field instead of two.
- Migrated the Graphs tab from Dygraphs to Apache ECharts, so the whole app
  now shares one charting library instead of two.
- Custom-duration date range input (presets weren't granular enough for
  every use case).
- Column visibility/sort-order persistence across SSE table re-renders.
- Docker env var for the SSE reload interval.
- End-to-end browser test suite covering every tab (see [Testing](development/testing.md)).
