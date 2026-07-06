# Flow Browser

Raw flow-record search: runs `nfdump` (see
[Nfdump Integration](../architecture/nfdump-integration.md)) over the
selected date range and renders the matching records as a sortable table.

![Flows tab](../images/01-page-flows.png)

## Filters

| Control | Effect |
|---|---|
| Limit flows | Cap on returned records |
| Sources | Any / all / a specific source |
| nfdump filter | Raw nfdump filter syntax (e.g. `proto tcp and dst port 443`), free text |
| Min / max bytes | Byte-count bounds |

## Aggregation & output

Optional bidirectional and per-protocol aggregation; port aggregation by
source or destination; IP aggregation with none/exact-IP/IPv4-prefix/
IPv6-prefix granularity per direction; and an "order by tstart" toggle. These
map directly onto nfdump's own `-a`/aggregation flags — the filter panel is
essentially a form over nfdump's CLI surface, not a reimplementation of it.

## IP details

Clicking an IP address cell opens a modal with reverse-DNS plus either
public-IP geolocation or (for private IPs) Netbox data — see
[IP Info Lookup](ip-info.md).
