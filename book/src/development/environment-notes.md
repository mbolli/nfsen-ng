# Environment Notes

Sandbox and dev-environment quirks met while working on this project. None of
them are bugs in nfsen-ng itself, but each cost real time to diagnose the first
time.

## Driving the app without a browser

There's no separate API to curl: every interaction is the same Datastar action
protocol the browser uses:

1. `GET /` with a cookie jar to get a session + context id (`via_ctx` appears
   in the response HTML).
2. Every signal's *wire* id is `name____<hash>`, not its human name; scrape it
   from the response rather than guessing.
3. `POST /_action/<name>` (the action name, the same in every tab) with a JSON
   body of `{"via_ctx": "...", "<hashed signal id>": <value>, ...}`; `via_ctx`
   binds the request to its context. Only the active page's actions and signals
   are in the first response. A hash never reaches the server, so to reach
   another page, post `navigate` with the `page` signal set to its id and read
   the re-rendered page from the context's SSE stream (`GET /_sse`).
4. Send an `Origin` header matching the request host, or expect
   `403 Forbidden: untrusted origin`; curl sends none by default.
5. Actions that take an id (`delete-alert`, `test-alert`, …) read it via
   `$c->input('id')`, not a signal: pass it as a query string on the POST
   URL.

## Known flakiness

- A dev container restarts on every watched file change, and has been seen
  restarting without one, which wipes every in-memory context and every tab's
  results. A previously scraped `via_ctx` will then 400 with
  `Invalid context`; re-fetch `GET /`. Everything persisted in
  `backend/settings/` (preferences and alert rules in `preferences.json`, saved
  filters, alert history and top-N data in `nfsen-ng.sqlite`) survives.
- Cross-container `inotify` (a sibling `nfcapd` container writing into a
  bind-mounted directory a *different* container watches) doesn't reliably
  propagate on some hosts, notably WSL2. If the import daemon's ongoing
  watch never seems to fire, check that before suspecting the daemon code;
  see [Import Pipeline](../architecture/import-pipeline.md).
- `git` inside a container whose bind-mounted repo is owned by a different
  uid refuses to run ("dubious ownership"). Run git from the host instead of
  patching the container's global git config.

## nfdump filter syntax

`nfdump`'s `-f` flag reads a filter **from a file**, not from an inline
string: a filter expression is a trailing, shell-escaped, bare positional
argument (`nfdump [options] -- "proto icmp"`). Passing a filter to `-f` by hand
gives a misleading `path does not exist: <filter>` error. See
[Nfdump Integration](../architecture/nfdump-integration.md) for how the app
itself constructs the command correctly.
