# nfsen-ng — Claude Code Notes

See [AGENTS.md](AGENTS.md) first for the stack, dev-stack startup, signal conventions, and Datastar template syntax. This file only covers things learned while verifying changes end-to-end in this specific dev sandbox that AGENTS.md doesn't cover.

## Driving the app over HTTP without a browser

The dev container's port is not reachable at `localhost:8080` on this box — something unrelated already holds that host port. Skip the host port entirely and talk to the app on its internal port from inside the container:

```bash
docker exec nfsen-ng curl -s http://localhost:9000/ ...
```

To exercise a real save/delete/test action end-to-end (not just read the page), replicate what the browser's Datastar client does:

1. `GET /` with a cookie jar — establishes the session and a per-tab context.
2. Scrape the response HTML for:
   - `via_ctx":"/_/<hash>"` — the context id, required in every action POST body.
   - `<name>____<hash>` occurrences — the actual wire-level signal ids (the hash is the same for every signal in one context). Server code refers to signals by human name (`$c->getSignal('alert_form_nfdumpFilter')`), but the JSON POST body must use the hashed id (`alert_form_nfdumpFilter____<hash>`) as the key — see `SignalFactory::injectSignals()`.
   - `_action/<name>-<randomid>` — the action URL. Actions default to TAB scope, so the id is randomized per context; re-scrape it, don't hardcode it.
3. `POST /_action/<action>` with `Content-Type: application/json` and a body of `{"via_ctx": "...", "<hashed_signal_id>": <value>, ...}`. Only send the keys you want to change — everything else keeps its previous value in that context.
4. Add `Origin: http://localhost:9000` (matching the request host) or the request gets `403 Forbidden: untrusted origin` — curl sends no Origin header by default, and this env doesn't reliably resolve as "dev mode" for the no-Origin-allowed fallback.
5. Actions that take an id (e.g. `delete-alert`, `test-alert`) read it via `$c->input('id')`, not a signal — pass it as a query string on the POST URL: `_action/delete-alert-xxx?id=<ruleId>`.

To see `LOG_DEBUG`-level output (e.g. the exact `nfdump` command a feature runs), drive the real **save-settings** action and set `settings_logPriority____<hash>` to `"DEBUG"` — don't hand-edit `backend/settings/preferences.json`'s `logPriority` for this, the save action is the real code path and persists it there anyway.

## Known flakiness in this sandbox

- **The dev container restarts itself often**, independent of any file edits you make (observed multiple times with no corresponding watched-file change). Every restart wipes all in-memory contexts — a previously-scraped `via_ctx`/action id will start returning `400 Invalid context`. If you get that, just re-scrape a fresh `GET /`. Rule/settings state itself survives fine since it's persisted to `backend/settings/preferences.json` on disk.
- **`git` inside the container** refuses to run ("dubious ownership") because the bind-mounted repo is owned by a different uid than the container's. Don't run `git config --global --add safe.directory` inside the container to work around it — just run git from the host; the working tree is the same bind-mounted files either way.
- **PHPStan OOMs at the container's default 128M memory_limit.** Run it as `php -d memory_limit=1G vendor/bin/phpstan analyse backend -l 5 -a backend/settings/settings.php --memory-limit=1G` instead of plain `composer test-phpstan`.
- **Baseline `composer test` failures on a clean checkout**: 39 pre-existing failures in `tests/Unit/VictoriaMetricsTest.php` (`Class "TestVM" not found`) and `tests/Feature/RrdFeatureTest.php` (RRD file creation assertions) exist on committed `HEAD` in this container, unrelated to any in-progress change. Confirmed via `git stash` + rerun. Don't mistake these for a regression — diff the failure count against a stashed clean run before attributing new failures to your change.
- **Cross-container inotify does not reliably fire in this WSL2 setup.** `nfcapd` (a sibling container) writes rotated capture files into a bind-mounted host directory that the main `nfsen-ng` container watches via `inotify_add_watch()`. In this sandbox that watch has never fired even once across the container's full log history — so `ImportDaemon`'s ongoing poll → `AlertManager::runPeriodic()` cannot be observed live here. Don't burn time waiting for a periodic cycle to fire "for real" in this environment; verify that code path by reading the branch logic + unit tests instead, and rely on live HTTP-driven checks only for things that don't depend on the inotify watch (saves, deletes, manual test/action triggers, persistence, rendering).
- **`nfdump`'s `-f` flag reads a filter from a FILE, not an inline string.** A filter expression is passed as a trailing bare (shell-escaped) positional argument — `nfdump [options] "proto icmp"`, not `nfdump [options] -f "proto icmp"`. The app's `Nfdump::execute()` already does this correctly (`escapeshellarg($filter)` appended after the flattened options); if you're sanity-checking a filter by hand in the container, match that form or you'll get a misleading `path does not exist: <filter>` error.
