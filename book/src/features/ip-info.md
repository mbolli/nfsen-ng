# IP Info Lookup

Clicking an IP address in the Flows or Statistics tables (rendered as
`<a class="ip-link">` by `TableFormatter.php`) triggers the `ip-info` action
(`UtilityActions.php`), which renders a modal fragment
(`ip-info-modal.html.twig`) with:

- **Reverse DNS** — `gethostbyaddr()`, falling back to shelling out to `host`
  and then to a "could not be resolved" label rather than showing the raw IP
  back.
- **Geolocation**, for public IPs only — a live lookup against
  [ipapi.co](https://ipapi.co/) (city, region, country, coordinates,
  timezone, ASN, org, currency — whatever it returns), with a 5-second
  timeout so a slow/unreachable external API can't hang the modal.
- **Netbox data**, for private IPs only, if `NFSEN_NETBOX_URL`/
  `NFSEN_NETBOX_TOKEN` are configured — whatever IPAM record Netbox has for
  that address (`IpLookup::netbox()`).

Private vs. public is decided once (`IpLookup::isPrivate()`) and picks
exactly one of geolocation or Netbox — a private (RFC 1918) address is never
sent to the public geolocation API, and a public address never triggers a
Netbox lookup.

The modal is a native `<dialog>` element (no Bootstrap JS/Popper dependency)
pushed by the server as a Datastar patch — the same pattern as any other
action, just targeting a fragment instead of the whole page.
