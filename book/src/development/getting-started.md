# Getting Started

Requires Linux: OpenSwoole has no maintained FreeBSD/other-BSD port
([openswoole/ext-openswoole#233](https://github.com/openswoole/ext-openswoole/issues/233)).

## Quick start (production-ish)

```bash
curl -O https://raw.githubusercontent.com/mbolli/nfsen-ng/master/deploy/docker-compose.yml
# edit NFSEN_SOURCES, NFSEN_NFDUMP_PROFILES, etc. in the compose file
docker compose --profile proxy up -d   # bundled Caddy, ports 80/443
# or: docker compose up -d             # app only, port 9000, behind your own proxy
```

## Development

```bash
git clone https://github.com/mbolli/nfsen-ng
cd nfsen-ng
docker compose -f deploy/docker-compose.dev.yml up -d
docker compose -f deploy/docker-compose.dev.yml logs -f nfsen
```

The dev container runs the app under [`entr`](https://eradman.com/entrproject/):
any `.php`/`.twig`/`.js`/`.css` change under the mounted source stops the server,
waits for its shutdown and starts it again: no manual restart, no build step.
Its entrypoint, `deploy/docker-entrypoint-dev.sh`, also runs from the mounted
source, so a change to it takes effect when `docker compose up -d` recreates the
container, without an image rebuild. The dev
state (preferences, alert rules and the SQLite store `nfsen-ng.sqlite`) lives in
`backend/settings/`, next to the code, and is ignored by git.

The compose file's commented-out `nfcapd`/`nfcapd-test` services can inject
real (or `softflowd`-generated) traffic on ports 9995/9996 for local testing;
without them the app still runs, just against whatever nfcapd files already
exist under the mounted `profiles-data` volume.

## Useful commands

```bash
composer install        # PHP deps
composer test            # Pest test suite
composer test-phpstan    # static analysis, level 8
composer fix              # auto-format PHP (php-cs-fixer)
composer before-commit   # fix + phpstan; run this before every PHP commit

pnpm install              # JS deps; rebuilds frontend/js/datastar-rocket.js, copies ECharts
pnpm run lint             # Biome lint of frontend/js/components and frontend/css
pnpm run format           # Biome format --write
pnpm run test-e2e         # the browser suite against a running instance (BASE, CHROME)
```

`pnpm install` builds the Datastar bundle from `node_modules/datastar` and the
patches in `patches/rocket/` with `scripts/vendor-rocket.sh`, offline, and keeps
the committed bundle with a warning when esbuild or patch(1) is missing. The
bundle, the Starbase components under `frontend/js/starbase/` and the licence
files are committed, so a checkout runs without `pnpm install`; see
[Project Structure](structure.md) and `AGENTS.md` for updating them.

Until php-via 0.13.0 is published, `composer.json` takes it from the local
repository at `/develop/php-via`, so `composer install` needs that path (mount it
into a Composer container too). Once it is out, require `"mbolli/php-via": "^0.13.0"`,
drop the `repositories` entry and run `composer update mbolli/php-via`. Until
then `deploy/Dockerfile` cannot install php-via, and its build stops with
*php-via is missing from vendor/* rather than producing an image that fails at
start.

See [Project Structure](structure.md) for where things live,
[Testing](testing.md) for the test suite in more depth, and
[Environment Notes](environment-notes.md) for sandbox-specific gotchas that
have nothing to do with the app itself but will otherwise cost you an hour.
