# IP Info Lookup

Every IP address rendered as a link (`<a class="ip-link">`, from `TableFormatter`
in result tables, and from the Overview KPI cards and top-N table, the
Conversations Sankey and IP pairs) posts the `ip-info?ip=` action
(`UtilityActions.php`). It renders `partials/ip-info-modal.html.twig` into
`ShellState::$modalHtml`, which the layout places in `#modal-root`, and opens it
as a native `<dialog>`. The dialog carries `data-preserve-attr="open"` and its
HTML stays in the tab's state, so live updates re-render it instead of
closing it.

The dialog has:

- **Hostname**: `gethostbyaddr()`, falling back to shelling out to `host`, and then
  to a "could not be resolved" label rather than echoing the IP back. With reverse
  DNS turned off in Settings (`rdnsEnabled`), no lookup happens and the row says
  *not looked up (reverse DNS is turned off)*.
- **Location**, for public IPs only, from one of two sources:
  - **A local MaxMind database**, when `NFSEN_GEOIP_DB` points at a GeoLite2 or
    GeoIP2 City or Country `.mmdb` that opens. `GeoIpDatabase`
    (`backend/common/GeoIpDatabase.php`) reads it with the pure-PHP
    `maxmind-db/reader` package, opens it once per process and reopens it when the
    file changes; a lookup takes microseconds and makes no network request. The
    dialog says *Source: MaxMind database*. An address the database does not know
    is reported as such, without asking the web service.
  - **A web service**, otherwise: [ipapi.co](https://ipapi.co/) by default (city,
    region, country, coordinates, timezone, ASN, organisation, whatever it
    returns), with a five-second timeout so a slow or unreachable API can't hang
    the dialog. The endpoint is configurable via `NFSEN_IPINFO_URL` (plus
    `NFSEN_IPINFO_TOKEN` for an API key), since ipapi.co rate-limits anonymous
    callers; see
    [Configuration](../deployment/configuration.md#geolocation-lookup). The dialog
    names the service's host. A configured `.mmdb` that cannot be opened also
    lands here, and Settings > Integrations says why.

  A rate-limit or other error reply is shown as a message rather than an empty
  table. The country flag is rendered server-side as a regional-indicator emoji
  (`IpLookup::countryFlag()`), so the dialog makes no third-party request of its
  own.
- **Netbox data**, for private IPs only, if `NFSEN_NETBOX_URL` and
  `NFSEN_NETBOX_TOKEN` are configured: whatever IPAM record Netbox has for the
  address (`IpLookup::netbox()`).

Private versus public is decided once (`IpLookup::isPrivate()`) and picks exactly
one of geolocation or Netbox: a private (RFC 1918) address is never sent to a
geolocation service, and a public address never triggers a Netbox lookup.
