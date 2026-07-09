# Installation

nfsen-ng sits on top of an existing [nfdump](https://github.com/phaag/nfdump)
capture setup: `nfcapd` writes rotated flow files, and nfsen-ng reads, imports,
and visualises them. It does **not** capture traffic itself — you need a working
`nfcapd` (or an equivalent NetFlow/sFlow/IPFIX collector) writing files before
nfsen-ng has anything to show.

> **Linux only.** The backend runs on the [OpenSwoole](https://openswoole.com/)
> PHP extension, which has no maintained FreeBSD/other-BSD port
> ([openswoole/ext-openswoole#233](https://github.com/openswoole/ext-openswoole/issues/233)).
> Docker images are Linux-only.

Two ways to install: **Docker** (recommended) or **bare-metal**. For a local
development setup with source-mounted auto-reload, see
[Getting Started](../development/getting-started.md) instead.

## Option A — Docker

### Prerequisites

- Docker and Docker Compose
- `nfcapd` writing files under a directory you can bind-mount (default
  `/var/nfdump/profiles-data`), in the `-S 1` subdirectory layout — see
  [nfcapd setup](#nfcapd-setup) below

### Quick start

No clone needed — grab the compose file and edit it:

```bash
curl -O https://raw.githubusercontent.com/mbolli/nfsen-ng/master/deploy/docker-compose.yml
```

At minimum set `NFSEN_SOURCES` and `NFSEN_PORTS`, and make sure the bind-mount
and `NFSEN_NFDUMP_PROFILES` point at your capture directory. For standard setups
the environment variables are all you need — no `settings.php`. The full list of
variables lives in [Configuration](configuration.md).

> **A Docker deployment needs two persistent volumes** (the shipped compose files
> wire up both — don't drop either):
>
> 1. **Capture data** — the `nfcapd` flow-file tree, mounted at `/data/nfsen-ng`
>    (`NFSEN_NFDUMP_PROFILES`). This is the directory your collector writes to and
>    nfsen-ng reads; you point it at your existing capture output.
> 2. **nfsen-ng's own data** — the RRD database plus saved preferences and alerts,
>    on the `nfsen-data` named volume at `/var/lib/nfsen-ng`.
>
> They're separate on purpose (the collector owns the capture tree; it grows with
> traffic and has its own retention). Skipping volume 2 means a container
> recreation — **every image upgrade** — wipes your graphs, preferences, and
> alerts. See [State & persistence](configuration.md#state--persistence).

> **When you *do* want a `settings.php`** (custom filter presets, many sources,
> a bare-metal path layout): copy `backend/settings/settings.php.dist` to
> `backend/settings/settings.php`, edit it, and mount it read-only. It is a
> **deprecated** overlay on the environment — any key it omits still falls back
> to the matching `NFSEN_*` variable. See
> [Configuration → Settings file](configuration.md#settings-file-deprecated).
> Note it must assign the global `$nfsen_config` array — a file that `return`s an
> array is silently ignored.

### Deployment modes

The app container (`nfsen`) listens on **port 9000** inside the Docker network
only. How you expose it is the choice:

#### Mode 1 — bundled Caddy (simplest public setup)

`deploy/docker-compose.yml` ships an optional `caddy` service gated behind the
`proxy` compose profile. It terminates TLS (automatic HTTPS via Let's Encrypt,
HTTP/3) and reverse-proxies everything to `nfsen:9000`.

```bash
# Edit deploy/Caddyfile.prod first: replace `yourdomain.com` with your domain.
docker compose -f deploy/docker-compose.yml --profile proxy up -d
```

Access at `https://<your-domain>` (ports 80, 443, and 443/udp are published).

> **Caddy here is a pure reverse proxy.** It does *not* compress responses or
> serve static files — the app does that itself. php-via serves and
> Brotli-compresses `/frontend/*` from inside OpenSwoole (`withStaticDir()` /
> `withBrotli()` in `backend/app.php`). The provided `Caddyfile.prod` therefore
> has **no** `encode` directive on purpose: SSE streams (the live-update
> channel) must never be compressed or buffered. It also uses
> `handle_errors 5xx { abort }` so Datastar's SSE client reconnects cleanly
> across a server restart instead of seeing an error page.

#### Mode 2 — behind your own reverse proxy

If you already run Traefik, nginx, or your own Caddy, start only the app:

```bash
docker compose -f deploy/docker-compose.yml up -d   # just the nfsen service
```

Point your proxy at `http://nfsen:9000` if it shares the Docker network, or
uncomment the `ports` entry in the compose file to publish 9000 on the host:

```yaml
# deploy/docker-compose.yml — nfsen service
# ports:
#   - "9000:9000"
```

Requirements for any fronting proxy:

- **Do not compress proxied responses.** The app already Brotli/gzip-compresses
  its own output; double-compression wastes CPU, and compressing the SSE stream
  breaks live updates. (Only ever compress at the proxy if you strip the app's
  compression, which you shouldn't.)
- **Do not buffer.** Disable response buffering / set an immediate flush
  interval so SSE frames reach the browser as they're written
  (`proxy_buffering off` / `X-Accel-Buffering: no` / `flush_interval -1`).
- **Pass 5xx through as a connection abort** (or equivalent) so the Datastar SSE
  client retries on server restart rather than rendering an error page.

#### Mode 3 — hardened production (read-only capture, bundled Caddy)

`deploy/docker-compose.prod.yml` is a variant that always starts Caddy (no
profile), mounts the capture directory **read-only**, and keeps RRD files and app
state on the persistent `nfsen-data` volume (`/var/lib/nfsen-ng`). It also
bind-mounts an optional (deprecated) `backend/settings/settings.php`; new setups
can skip that and configure via `NFSEN_*` variables alone.

### Images and tags

Only one image is published: **`ghcr.io/mbolli/nfsen-ng`**, built from
`deploy/Dockerfile` (PHP 8.4 CLI + OpenSwoole + a source-compiled nfdump 1.7.8 +
the `rrd`, `inotify`, and `brotli` extensions). The Caddy service uses the stock
[`caddy:latest`](https://hub.docker.com/_/caddy) image — there is no custom Caddy
image to build.

| Tag | Tracks |
|-----|--------|
| `latest` | newest tagged release (betas included while pre-1.0) |
| `edge` | newest `master` build |
| `x.y.z` / `x.y` / `x` | a specific release, pinnable |

### Useful commands

```bash
docker compose -f deploy/docker-compose.yml logs -f nfsen   # tail app logs
docker compose -f deploy/docker-compose.yml ps              # container status
```

---

## Option B — Bare-metal (Ubuntu / Debian)

The Docker image is the reference install; the steps below reproduce it by hand.
nfsen-ng needs **PHP 8.4** with the `openswoole`, `inotify`, `brotli`, and `rrd`
extensions, plus an `nfdump` binary.

### nfcapd setup

nfsen-ng expects `nfcapd` files in the `-S 1` subdirectory layout
(`YYYY/MM/DD/`). A typical invocation for nfdump ≥ 1.7.x:

```bash
nfcapd -w /var/nfdump/profiles-data/live/<source> -z=lz4 -S 1 -T all -p <port> -D
```

| Flag | Meaning |
|------|---------|
| `-w <path>` | Output directory. Must be `<profiles-data>/<profile>/<source>` (e.g. `.../live/gw1`). |
| `-z=lz4` | Compress capture files (also `=lzo`, `=zstd`). The legacy bare `-z` was removed in nfdump 1.8.x — use the explicit `=<algo>` form. |
| `-S 1` | `YYYY/MM/DD/` subdirectory structure. **Required** for nfsen-ng to locate files. |
| `-T all` | Capture all flow extensions (recommended). |
| `-p <port>` | UDP listen port (e.g. `9995`). |
| `-D` | Daemonize. |

> **Timezone:** `nfcapd` names files from the host's *local* time. If nfsen-ng
> then runs in a container at `TZ=UTC`, set `NFCAPD_TZ` to the capture host's
> timezone (e.g. `Europe/Berlin`) so filenames parse to the correct epoch —
> otherwise RRD timestamps and chart labels are off by the UTC offset. See
> [Configuration](configuration.md#timezones).

The ready-to-use `deploy/systemd/nfcapd.service` unit already uses
`-z=lz4 -S 1`.

### Install the stack

```bash
# As root.

# --- PHP 8.4 repository ---
# Ubuntu:
add-apt-repository -y ppa:ondrej/php
# Debian:
apt install -y apt-transport-https lsb-release ca-certificates curl gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor > /etc/apt/trusted.gpg.d/sury-php.gpg
apt update

# --- Packages ---
apt install -y git pkg-config brotli \
    php8.4 php8.4-dev php8.4-xml php8.4-mbstring php8.4-curl \
    rrdtool librrd-dev \
    flex bison libbz2-dev zlib1g-dev build-essential autoconf automake libtool unzip wget

# --- nfdump 1.7.8 from source (matches the Docker image) ---
wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.8.zip
unzip v1.7.8.zip && cd nfdump-1.7.8
./autogen.sh && ./configure --prefix=/usr/local/nfdump && make && make install && ldconfig
cd ..
# binary is now /usr/local/nfdump/bin/nfdump

# --- PHP extensions ---
# openswoole + inotify + brotli via PECL:
pecl install openswoole inotify
echo "extension=openswoole.so" > /etc/php/8.4/mods-available/openswoole.ini
echo "extension=inotify.so"    > /etc/php/8.4/mods-available/inotify.ini
pecl install rrd
echo "extension=rrd.so"        > /etc/php/8.4/mods-available/rrd.ini
# brotli: install ext-brotli (https://github.com/kjdev/php-ext-brotli), then:
echo "extension=brotli.so"     > /etc/php/8.4/mods-available/brotli.ini
phpenmod openswoole inotify rrd brotli mbstring curl xml

# --- nfsen-ng ---
cd /var/www
git clone https://github.com/mbolli/nfsen-ng
chown -R www-data:www-data nfsen-ng
cd nfsen-ng

# Composer (https://getcomposer.org/download/):
php composer.phar install --no-dev --optimize-autoloader

# Optional (deprecated) settings file — or set the same NFSEN_* env vars instead,
# e.g. via a systemd EnvironmentFile:
cp backend/settings/settings.php.dist backend/settings/settings.php
$EDITOR backend/settings/settings.php   # set sources, ports, nfdump.binary, profiles-data

# Start the HTTP server (listens on port 9000):
sudo -u www-data php backend/app.php
```

On a bare-metal install point `nfdump.binary` (or `NFSEN_NFDUMP_BINARY`) at
`/usr/local/nfdump/bin/nfdump` if you compiled it as above. On first start the
database is empty — trigger the first import from the web UI (see
[First import](#first-import)).

### Front it with a reverse proxy

The app serves and compresses its own static assets, so a fronting proxy only
needs to terminate TLS and forward everything to port 9000 — no static-file root,
no `encode`. The provided `deploy/Caddyfile.prod` is a complete example; replace
`yourdomain.com`, then point Caddy at it. Any proxy must follow the SSE rules in
[Mode 2](#mode-2--behind-your-own-reverse-proxy) above (no compression, no
buffering, pass 5xx through).

---

## systemd services

Pre-built units are in `deploy/systemd/`. They reference `/var/www/nfsen-ng` and
`eth0` as placeholders — `sed`-replace those for your host.

| File | Purpose |
|------|---------|
| `nfsen-ng-docker.service` | Run nfsen-ng via `docker compose … --profile proxy up -d` (recommended) |
| `nfsen-ng.service` | Run the app directly on bare metal (`php backend/app.php`, as `www-data`) |
| `nfcapd.service` | NetFlow capture daemon (`-z=lz4 -S 1`) |
| `softflowd.service` | Software NetFlow exporter for testing/dev |

```bash
# Docker:
sed -i 's|/var/www/nfsen-ng|/your/path|g' deploy/systemd/nfsen-ng-docker.service
sudo cp deploy/systemd/nfsen-ng-docker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfsen-ng-docker.service

# Bare-metal:
sed -i 's|/var/www/nfsen-ng|/your/path|g' deploy/systemd/nfsen-ng.service
sudo cp deploy/systemd/nfsen-ng.service /etc/systemd/system/
sudo systemctl enable --now nfsen-ng.service

# Capture on the same host (edit eth0 first):
sed -i 's/eth0/YOUR_INTERFACE/g' deploy/systemd/softflowd.service
sudo cp deploy/systemd/nfcapd.service deploy/systemd/softflowd.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now nfcapd.service softflowd.service
```

## Unraid

`deploy/unraid/` packages the same single image into two Community Applications
roles (web UI + `nfcapd` collector) sharing one appdata directory, plus a
`docker-compose.unraid.yml` for the Compose Manager plugin. See
[`deploy/unraid/README.md`](https://github.com/mbolli/nfsen-ng/blob/master/deploy/unraid/README.md)
for the walkthrough. The collector role reuses the app image with
`--entrypoint /usr/local/nfdump/bin/nfcapd`; the UI's `NFSEN_SOURCES` must
include the collector's source name (both default to `flows`).

---

## First import

nfsen-ng embeds its import daemon inside the server process — there is **no
separate CLI binary**. On a fresh install with no data yet, nothing is imported
automatically: open the app, go to the **Settings → Import** panel, and click
**Trigger Import**. That scans every `nfcapd` file back to `NFSEN_IMPORT_YEARS`
and builds the database. Once data exists, each server start does an incremental
gap-fill for the files written while it was offline. See
[Health & Admin](../features/health-admin.md) for the full panel.

## Troubleshooting

**No data showing**
- Verify the file layout: `<profiles-data>/<profile>/<source>/YYYY/MM/DD/nfcapd.*`
- Confirm the container mount and `NFSEN_NFDUMP_PROFILES` point at the same tree.
- Trigger the first import: **Settings → Import → Trigger Import**.
- Check RRD files were created under `backend/datasources/data/<profile>/`.

**nfdump warnings: `appendix offset error` or `read() error … Success`**

These surface in the Statistics/Flows warning toast when nfdump reads a truncated
`nfcapd` file — usually one killed mid-write (container restart, OOM, power loss).
nfdump still processes every other file in the range; nothing already imported is
lost. To find corrupt files:

```bash
docker exec nfsen-ng sh -c 'find /data/nfsen-ng/live -name "nfcapd.*" | \
  xargs -P4 -I{} sh -c "nfdump -r {} -c 1 -q 2>&1 | grep -q error && echo {}"'
```

Delete the listed files; already-imported RRD/VictoriaMetrics data is unaffected.

**SSE not connecting**
- DevTools → Network → filter `_sse` should show one persistent connection.
- Confirm the proxy isn't buffering or compressing the stream (see the proxy
  requirements above).

**Rebuild all data from scratch**

Use **Settings → Import → Force Rescan** in the web UI. This wipes the selected
profile's datasource and re-imports from the capture files. (There is no
force-import environment variable; the rescan is a UI action.)
