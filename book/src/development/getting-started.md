# Getting Started

Requires Linux — OpenSwoole has no maintained FreeBSD/other-BSD port
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

The dev image runs the app under [`entr`](https://eradman.com/entrproject/):
any `.php`/`.twig`/`.js`/`.css` change under the mounted source kills and
restarts the server automatically — no manual restart, no build step.

The compose file's commented-out `nfcapd`/`nfcapd-test` services can inject
real (or `softflowd`-generated) traffic on ports 9995/9996 for local testing;
without them the app still runs, just against whatever nfcapd files already
exist under the mounted `profiles-data` volume.

## Useful commands

```bash
composer install        # PHP deps
composer test            # Pest test suite
composer test-phpstan    # static analysis, level 5
composer fix              # auto-format PHP (php-cs-fixer)
composer before-commit   # fix + phpstan — run this before every PHP commit

pnpm install              # JS deps (also vendors datastar/nouislider/echarts into frontend/js/)
pnpm run lint              # Biome lint
pnpm run format            # Biome format --write
```

See [Project Structure](structure.md) for where things live,
[Testing](testing.md) for the test suite in more depth, and
[Environment Notes](environment-notes.md) for sandbox-specific gotchas that
have nothing to do with the app itself but will otherwise cost you an hour.
