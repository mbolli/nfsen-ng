# IP Info Lookup

Clicking an IP address in the Flows or Statistics tables triggers the
`ip-info` action (`UtilityActions.php`), which renders a modal fragment
(`ip-info-modal.html.twig`) with:

- **Reverse DNS** — `gethostbyaddr()`, falling back to a "could not be
  resolved" label rather than showing the raw IP back.
- **Netbox data**, if `NFSEN_NETBOX_URL`/`NFSEN_NETBOX_TOKEN` are configured —
  whatever IPAM record Netbox has for that address (`IpLookup::netbox()`).

The modal is a native `<dialog>` element (no Bootstrap JS/Popper dependency)
pushed by the server as a Datastar patch — the same pattern as any other
action, just targeting a fragment instead of the whole page.

Netbox integration is optional: without the two env vars set, the modal
simply shows reverse-DNS only.
