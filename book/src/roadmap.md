# Roadmap

Tracked as [GitHub issues](https://github.com/mbolli/nfsen-ng/issues).

## In progress

- **[#150 — Alerts improvement](https://github.com/mbolli/nfsen-ng/issues/150).**
  Host/subnet and protocol-scoped alerting, implemented as a freeform nfdump
  traffic filter on a rule (see [Alerts](features/alerts.md)) rather than
  separate host/protocol fields — covers the same use case (an ICMP-only or
  single-subnet rule) with one field instead of two.

## Open

- **[#143 — v1 broken on FreeBSD](https://github.com/mbolli/nfsen-ng/issues/143).**
  OpenSwoole's C extension doesn't build on FreeBSD
  (openswoole/ext-openswoole#233), and php-via is built on OpenSwoole, so a
  bare-metal FreeBSD install currently can't run this at all. Short-term:
  document the limitation. Medium-term: whether php-via can be made
  runtime-agnostic (OpenSwoole vs. Swoole vs. an alternative server) is an
  open question that needs a php-via-side answer, not just an nfsen-ng one.

## Recently shipped

- **[#152 — Sankey diagram](https://github.com/mbolli/nfsen-ng/issues/152).**
  The Sankey tab (see [Sankey Diagram](features/sankey.md)) aggregates
  flows into top-N src/dst pairs and renders them with ECharts.
- Migrated the Graphs tab from Dygraphs to Apache ECharts, so the whole app
  now shares one charting library instead of two.
- Custom-duration date range input (presets weren't granular enough for
  every use case).
- Column visibility/sort-order persistence across SSE table re-renders.
- Docker env var for the SSE reload interval.
