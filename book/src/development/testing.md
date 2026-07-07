# Testing

```bash
composer test              # everything
composer test-coverage     # with coverage
docker compose exec nfsen vendor/bin/pest   # from inside the dev container
```

Tests are [Pest](https://pestphp.com/) PHP, split into `tests/Unit/` (pure
logic, one file roughly per class) and `tests/Feature/` (real I/O — actual
RRD file creation, for example).

## Patterns worth knowing

- **Offline test doubles for I/O-bound classes.** `VictoriaMetricsTest.php`
  declares a `class VictoriaMetricsTest extends VictoriaMetrics` at the top
  of the file that overrides `httpGet()`/`sendToVM()`/`tcpConnect()` with
  in-memory stubs, so the whole suite runs without a real VictoriaMetrics
  instance. If you add a new abstract-ish method to `VictoriaMetrics`, this
  double's override signature has to stay in lockstep — PHP fatals on a
  parent/child signature mismatch, not a soft warning.
- **Env-var isolation.** A test asserting "defaults when no env vars are
  set" has to `putenv('NFSEN_SOURCES')` etc. itself; `getenv()` sees the
  real ambient environment, which in a dev container may already have
  `NFSEN_SOURCES`/`NFSEN_PORTS` exported for the running app.
- **Profile-aware paths.** `Rrd::get_data_path()`/`create()` nest files
  under `{data_path}/{profile}/...`, not flat — a test that hardcodes a
  flat path or a cleanup helper that only globs the top level will drift the
  moment that changes. `write()` also drops a `.rrd.first` sidecar file
  alongside the `.rrd` — a cleanup glob for `*.rrd` alone won't catch it.
- **`Misc::countProcessesByName()` needs real `ps`/`pgrep`.** A container
  image missing `procps` makes this (and the "finds running php processes"
  test) silently return 0 rather than fail loudly — see
  [Nfdump Integration](../architecture/nfdump-integration.md) for why that
  matters beyond tests.

## End-to-end tests

```bash
npm run test-e2e            # runs everything in tests/e2e/ against BASE
BASE=http://localhost:8080 npm run test-e2e   # override the target (default shown)
node tests/e2e/graphs.test.mjs                # run one file directly
```

`tests/e2e/` drives a real headless Chrome against a running app instance —
actual clicks, actual `nfdump` queries, actual SSE-pushed DOM updates. This
catches classes of bug the Pest suite structurally can't: JS runtime errors,
races between client-side state and server-pushed patches, and the
client/server wire contract for actions (signal names vs. hashed ids, query
params vs. JSON body — see below).

There's one file per tab (`smoke`, `graphs`, `flows`, `statistics`,
`sankey`, `settings`, `alerts`), plus `lib/cdp.mjs`, the shared driver, and
`run.mjs`, which runs every `*.test.mjs` file in the directory sequentially
and exits non-zero on any failure. Each test file is also directly
runnable/standalone (`node tests/e2e/<name>.test.mjs`) via an
`import.meta.url` check at the bottom.

**No Playwright/Puppeteer dependency.** `lib/cdp.mjs` talks raw Chrome
DevTools Protocol over Node 22's native `WebSocket`, resolving whatever
Playwright-managed Chromium build is newest under `~/.cache/ms-playwright`
(override with `CHROME=/path/to/chrome`). If none is cached yet:
`npx playwright install chromium`. This mirrors the pattern `book/_capture.mjs`
already established for screenshotting the book.

### Patterns worth knowing

- **`data-show` only toggles CSS visibility — every settings sub-section's
  markup is in the DOM at once**, whichever tab is "active". An unscoped
  query like `document.querySelector('table tbody')` silently grabs
  whichever table happens to come first in document order, not the one in
  the visible panel. Scope queries to the active container instead, e.g.
  `[data-show="$_settingsSection == 'alerts'"] table tbody`
  (`alerts.test.mjs`) — and prefer `page.waitForPanel(signal, value)` over a
  substring `[data-show*="..."]` match, since hashed signal ids can contain
  the target string as a substring (`import_running____<hash>` matches
  `*="import"`).
- **`alerts.test.mjs` is the one test that mutates real, persisted state**
  (`backend/settings/preferences.json`). It creates a uniquely-named rule,
  toggles it, deletes it, and asserts the rule-count badge returns to its
  pre-test baseline — so a run never leaves a stray rule behind even if an
  earlier assertion in the same run fails partway through.
- **Don't assume query results have rows.** This sandbox's RRD data only
  covers a recent window and one datatype (bits/bytes) is often gappier than
  flows/packets (see [Import Pipeline](../architecture/import-pipeline.md)).
  `graphs.test.mjs` tries Flows → Packets → Traffic in order and falls back
  to asserting the empty-state message if none have data; `flows`/`statistics`
  assert on the always-present result notification rather than a non-empty
  table.
- **This suite already found two real bugs**, not just test-authoring
  issues: a `startViewTransition` race in `nfsen-chart.js` where a deferred
  callback could call `setOption` on an already-disposed chart, and the
  Alerts Delete/Enable/Test buttons using `@post(url, {id: '...'})` — the
  second argument to Datastar's `@post` is a request-options object, not a
  body payload, so `id` never reached `$c->input('id')` and every click was
  a silent no-op. Both are fixed in the app, not papered over in the test.

## Static analysis

```bash
composer test-phpstan     # phpstan analyse backend -l 5
```

In a memory-constrained container, PHPStan's default 128M can OOM before it
finishes; run with an explicit override if that happens:

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse backend -l 5 -a backend/settings/settings.php --memory-limit=1G
```

`composer before-commit` runs `fix` (php-cs-fixer) then `test-phpstan` — the
convention is to run it after any PHP change, before committing.
