# nfsen-ng: Claude Code notes

See [AGENTS.md](AGENTS.md) first for the stack, dev-stack startup, signal conventions, and Datastar template syntax. This file only covers things learned while verifying changes end-to-end in this specific dev sandbox that AGENTS.md doesn't cover.

## Driving the app over HTTP without a browser

The dev container's port is not reachable at `localhost:8080` on this box: something unrelated already holds that host port, and `docker exec` currently fails host-wide (`OCI runtime exec failed: open /run/user/0/runc-process...`), which affects every container, not just this one.

What works instead: the agent container sits on the default bridge while the stack sits on its compose network, and the two cannot route to each other. Join the network once, then curl the container IP directly:

```bash
docker network connect deploy_default <this-container-id>
curl -s http://172.19.0.2:9000/        # nfsen-ng
```

Curling the host gateway address is refused by the permission classifier, so don't reach for that.

To exercise a real save/delete/test action end-to-end (not just read the page), replicate what the browser's Datastar client does:

1. `GET /` with a cookie jar. It establishes the session and a per-tab context.
2. Scrape the response HTML for:
   - `via_ctx":"/_/<hash>"`: the context id, required in every action POST body.
   - `<name>____<hash>` occurrences: the actual wire-level signal ids. Each signal has its own hash, so scrape every id you post. Server code refers to signals by human name (`$c->getSignal('alert_form_nfdumpFilter')`), but the JSON POST body must use the hashed id (`alert_form_nfdumpFilter____<hash>`) as the key (see `SignalFactory::injectSignals()`). A server-owned `_` signal declared with `$c->signal()` is hashed too, so templates address it through `.id()` like any other.
   - Action URLs are `<basePath>_action/<name>`, the same in every tab; `via_ctx` in the body binds the request to its context.
3. `POST /_action/<action>` with `Content-Type: application/json` and a body of `{"via_ctx": "...", "<hashed_signal_id>": <value>, ...}`. Only send the keys you want to change; everything else keeps its previous value in that context.
4. Add an `Origin` header matching the request host (`Origin: http://172.19.0.2:9000` for the URL above): curl sends none, and outside dev mode (`NFSEN_DEV_MODE`, off in the dev stack) php-via answers `403 Forbidden: missing Origin`. An Origin for another host gets `403 Forbidden: untrusted origin`.
5. Actions that take an id (e.g. `delete-alert`, `test-alert`) read it via `$c->input('id')`, not a signal. Pass it as a query string on the POST URL, e.g. `_action/delete-alert?id=<ruleId>`.

To see `LOG_DEBUG`-level output (e.g. the exact `nfdump` command a feature runs), drive the real **save-settings** action and set `settings_logPriority____<hash>` to `"DEBUG"`. Don't hand-edit `backend/settings/preferences.json`'s `logPriority` for this, the save action is the real code path and persists it there anyway.

## Running the browser suite

`CHROME=/usr/bin/chromium BASE=http://172.19.0.2:9000 node tests/e2e/run.mjs` drives the real app over CDP; there is no Playwright dependency, and `CHROME` has to be set because the resolver looks under `~/.cache/ms-playwright` by default. The screenshot pipeline in `book/_capture.mjs` takes the same variables.

- `run.mjs` takes file names (`node tests/e2e/run.mjs flows rocket`) and runs only those; `node tests/e2e/<file>.test.mjs` runs one file on its own.
- Against the shared dev stack, always set `E2E_SKIP_MUTATING=1`: without it the `alerts`, `drawer` and `settings` files and the mutating steps of the others write rules, saved filters, settings and alert events into the instance other sessions use. Run the mutating files on an isolated instance instead: a copy of the tree, the RRD data, the captures and `preferences.json`, the store copied with `VACUUM INTO`, started with `--entrypoint php deploy-nfsen backend/app.php` on `deploy_default`, and `NFSEN_SQLITE` pointed at that store for `no-auto-query`. With the repository mounted read-only instead of copied, point `NFSEN_STATE_DIR`, `NFSEN_RRD_PATH` and `NFSEN_NFDUMP_PROFILES` elsewhere and set `NFSEN_SETTINGS_FILE` to a file holding `<?php $nfsen_config = [];`, or the repository's local `settings.php` applies; a `NFSEN_SETTINGS_FILE` that does not exist stops the server at start.
- A file that fails with `AppReloadedError` (*THE APP RELOADED the page mid-test*) hit a restart of the dev app, usually because somebody saved a watched file (a new file in `.redesign/` counts: it holds `.php` files). Rerun it once the tree is quiet.
- `E2E_FILE_TIMEOUT` (seconds, default 900) ends a hung file; its browsers are killed and its profile directory removed, whatever way Node ends.
- Before running `book/_capture.mjs` against the shared stack, check **Settings > General**: the book's images show Overview, Last 24 hours, Bits and compact tables off. `settings.test.mjs` saves Flows, 7 days, Bytes and compact tables, and a run that ends before its restore leaves them behind, and every image then shows them. Put them back through the page's **Save settings** (the real action), not by editing `preferences.json`.

## Known flakiness in this sandbox

- **The dev container restarts itself often**, independent of any file edits you make (observed multiple times with no corresponding watched-file change). Every restart wipes all in-memory contexts: a previously-scraped `via_ctx`/action id will start returning `400 Invalid context`. If you get that, just re-scrape a fresh `GET /`. Rule/settings state itself survives fine since it's persisted to `backend/settings/preferences.json` on disk.
- **`git` inside the container** refuses to run ("dubious ownership") because the bind-mounted repo is owned by a different uid than the container's. Don't run `git config --global --add safe.directory` inside the container to work around it; run git from the host; the working tree is the same bind-mounted files either way.
- **PHPStan needs more memory than the image gives PHP.** The `deploy-nfsen` image sets `memory_limit = 512M` (the server's value); run PHPStan as `php -d memory_limit=1G vendor/bin/phpstan analyse backend -a backend/settings/settings.php --memory-limit=1G` instead of plain `composer test-phpstan`.
- **Composer needs `/develop/php-via`** until php-via 0.14.0 is published: `composer.json` takes the branch `nfsen-ng/release-0.14` from that local repository. In a container, mount it at the same path, read-only: `docker run --rm --user 99:100 -e HOME=/tmp --entrypoint composer -v "$PWD":"$PWD" -w "$PWD" -v /develop/php-via:/develop/php-via:ro deploy-nfsen install`. Composer ignores a branch that is checked out in a git worktree (`git branch` marks it with `+`), which is why nfsen-ng points at its own ref instead of `release/0.14`.
- **Run the test suite in the app image, not on the host.** The host PHP has no `rrd` extension, so every RRD test silently skips (9 skipped, and whole files `return` early). `docker run --rm --entrypoint php -v /develop/nfsen-ng:/app -w /app deploy-nfsen vendor/bin/pest` runs them for real, and the suite is green there. An earlier note here claimed 39 pre-existing failures on a clean checkout; that is stale, the suite is green in the image.
- **The import daemon picks up live capture files here.** `nfcapd` (a sibling container) writes a file every five minutes into the bind-mounted profiles directory, and the inotify watch of the `nfsen-ng` container fires within seconds (the Health log shows `ImportDaemon: processed nfcapd.<stamp>` about 2 s after each five-minute mark). A live alert cycle (`AlertManager::runPeriodic()`) can therefore be observed by waiting for the next file.
- **`nfdump`'s `-f` flag reads a filter from a FILE, not an inline string.** A filter expression is passed as a trailing bare (shell-escaped) positional argument: `nfdump [options] "proto icmp"`, not `nfdump [options] -f "proto icmp"`. The app's `Nfdump::execute()` already does this correctly (`escapeshellarg($filter)` appended after the flattened options); if you're sanity-checking a filter by hand in the container, match that form or you'll get a misleading `path does not exist: <filter>` error.
- **The Docker daemon cannot see this container's `/tmp` or scratchpad.** A path passed to `docker run -v` is resolved on the host, so files for another container (an isolated nfsen-ng instance, generated captures) go under `/develop`.
- **The shared dev stack pins one nfdump process.** Its gitignored `backend/settings/settings.php` sets `'max-processes' => 1`, so split queries, parallel filtered graphs and the parallel top-N collection never show there, and Health says *1, set by NFSEN_NFDUMP_MAX_PROCESSES or settings.php*. Measure them on an isolated instance with `NFSEN_NFDUMP_MAX_PROCESSES` set and a `settings.php` that leaves `max-processes` out (the file wins over the variable). The book's `05-page-health`, `guide-health-nfdump` and `08-page-settings-system` show the `auto` default and come from such an instance: take them there with `ONLY=`, since a capture run on the shared stack replaces them with the pinned value.
