# nfsen-ng on Unraid

Packaging for running nfsen-ng on [Unraid](https://unraid.net/). One Docker image
(`ghcr.io/mbolli/nfsen-ng`) carries the whole stack — `nfdump`, the `nfcapd`
collector, and the web app — so nothing extra is built. It runs in two roles that
share one appdata directory: **nfcapd** writes flow files, the **UI** reads them.

## Files here

| File | What it is |
|------|------------|
| `nfsen-ng.xml` | Community Applications template for the **web UI** |
| `nfsen-ng-nfcapd.xml` | Community Applications template for the **nfcapd collector** |
| `docker-compose.unraid.yml` | Both roles as a single stack (for the Compose Manager plugin) |
| `nfsen-ng-icon.png` | 512×512 icon (a zoomed crop of the app's own Sankey diagram) referenced by both templates |

## What you need before it's useful

nfsen-ng only *displays* flows; something has to *produce* them. Two prerequisites
no container can satisfy for you:

1. **A flow exporter on your network.** Enable NetFlow / sFlow / IPFIX export on your
   router or switch (pfSense, OPNsense, MikroTik, …) and point it at your Unraid
   host's IP on the collector port (default **9995/UDP**).
2. **Both roles on the same data path.** The collector writes to
   `live/<source>/` under the data directory; the UI reads the same directory. If
   the paths differ, the UI stays empty.

The **source name must match**: the last path segment of the collector's `-w`
argument (default `flows` → `live/flows`) must be listed in the UI's
`NFSEN_SOURCES`. Both default to `flows`, so leave them alone unless you rename.

TLS is intentionally out of scope for the app — front the UI with SWAG, Nginx
Proxy Manager, or Traefik.

## Install — option A: two CA templates (one-click)

Once these templates are published (see *Publishing* below), install **nfsen-ng**
and **nfsen-ng-nfcapd** from Community Applications. Set both `Data (profiles)`
paths to the same share (default `/mnt/user/appdata/nfsen-ng`), point your router
at the collector port, done.

## Install — option B: one Compose stack

Install the **Compose Manager** plugin from Community Applications, add a new stack,
and paste `docker-compose.unraid.yml`. It defines both roles and the shared volume
already. Edit `NFSEN_SOURCES` / the collector `-w` path only if you want a name
other than `flows`.

## Publishing to Community Applications

- **Self-host now (no gatekeeping):** in Unraid, *Apps → Settings → Template
  Repositories*, add `https://github.com/mbolli/nfsen-ng`. CA scans the repo for
  `*.xml` templates so anyone who adds the URL gets them immediately.
- **Public listing:** submit via the official portal at
  [ca.unraid.net/submit](https://ca.unraid.net/submit), or open a PR against
  [Squidly271/community.applications](https://github.com/Squidly271/community.applications).
  Listing a third-party image on your behalf can also be requested through
  [selfhosters/unRAID-CA-templates](https://github.com/selfhosters/unRAID-CA-templates).
- Publishing carries maintenance expectations: keep the templates working against
  new Unraid releases and respond in the support thread. Set `<Support>` to a real
  thread once one exists (it currently points at GitHub issues).

## Notes on the template internals

- The collector image's default entrypoint launches the web app, so the collector
  template overrides it: `--entrypoint /usr/local/nfdump/bin/nfcapd` in
  `<ExtraParams>`, with the nfcapd flags supplied via `<PostArgs>`.
- The collector needs no web UI, so its `<WebUI/>` is empty.
- Ports are declared with `Mode="tcp"` (UI 9000) and `Mode="udp"` (collector 9995).
- These are the same env vars documented in
  [`deploy/docker-compose.yml`](../docker-compose.yml); anything there can be added
  as an extra `Variable` config.
