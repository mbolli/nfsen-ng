# Environment Notes

Sandbox/dev-environment quirks that have bitten while working on this
project — none of them are bugs in nfsen-ng itself, but each cost real time
to diagnose the first time.

## Driving the app without a browser

There's no separate API to curl — every interaction is the same Datastar
action protocol the browser uses:

1. `GET /` with a cookie jar to get a session + context id (`via_ctx` appears
   in the response HTML).
2. Every signal's *wire* id is a hash (`name____<hash>`), not its human name —
   scrape it from the response rather than guessing.
3. `POST /_action/<action-id>` (also scraped from the HTML — action ids are
   randomized per context) with a JSON body of `{"via_ctx": "...", "<hashed
   signal id>": <value>, ...}`.
4. Send an `Origin` header matching the request host, or expect
   `403 Forbidden: untrusted origin` — curl sends none by default.
5. Actions that take an id (`delete-alert`, `test-alert`, …) read it via
   `$c->input('id')`, not a signal — pass it as a query string on the POST
   URL.

## Known flakiness

- A dev container can restart on its own (observed with no corresponding
  file edit), which wipes every in-memory context. A previously-scraped
  `via_ctx`/action id will then 400 with `Invalid context` — just re-fetch
  `GET /`. Anything persisted to `backend/settings/preferences.json` (alert
  rules, saved settings) survives fine, since it's re-read from disk at boot.
- Cross-container `inotify` (a sibling `nfcapd` container writing into a
  bind-mounted directory a *different* container watches) doesn't reliably
  propagate on some hosts, notably WSL2. If the import daemon's ongoing
  watch never seems to fire, check that before suspecting the daemon code —
  see [Import Pipeline](../architecture/import-pipeline.md).
- `git` inside a container whose bind-mounted repo is owned by a different
  uid refuses to run ("dubious ownership"). Run git from the host instead of
  patching the container's global git config.

## nfdump filter syntax

`nfdump`'s `-f` flag reads a filter **from a file**, not from an inline
string — a filter expression is a trailing, shell-escaped, bare positional
argument (`nfdump [options] "proto icmp"`). Passing a filter to `-f` by hand
gives a misleading `path does not exist: <filter>` error. See
[Nfdump Integration](../architecture/nfdump-integration.md) for how the app
itself constructs the command correctly.
