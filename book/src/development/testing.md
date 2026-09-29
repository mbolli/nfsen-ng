# Testing

```bash
composer test              # everything
composer test-coverage     # with coverage

# in the app image, which has the rrd extension and pdo_sqlite:
docker run --rm --entrypoint php -v "$PWD":/app -w /app deploy-nfsen vendor/bin/pest
```

Run them in the app image, not on a host PHP without the `rrd` extension: those
tests skip silently there, and whole files return early, so a green run can mean
"nothing ran". `phpunit.xml.dist` raises the memory limit to 512M, which the
architecture tests need.

Tests are [Pest](https://pestphp.com/) PHP, split into `tests/Unit/` (pure logic,
one file roughly per class), `tests/Feature/` (real I/O, actual RRD file creation
for example) and `tests/Arch/` (namespace and dependency rules).

## Patterns worth knowing

- **The SQLite store in tests.** Install an in-memory store with
  `Database::useShared(Database::open(':memory:'))` and drop it with
  `Database::resetShared()` afterwards, so no test touches the dev store. A file
  store that refuses WAL, as on a FUSE mount, is
  `Database::open('file:' . $path . '?vfs=unix-none')`. Repositories that compare
  a float with an aggregate need `CAST(? AS REAL)`, and a test for such a query
  should use a value where the TEXT comparison would give the wrong answer.
- **nfdump without nfdump.** `tests/Support/FakeProcessor.php` stands in for the
  processor and returns queued results in order (`queueRaw()` for raw output).
  `tests/Support/bin/` holds small shell scripts that behave like nfdump, and
  they must keep their executable bit. `nfdump-canned` prints
  `$NFDUMP_STUB_STDOUT` and `$NFDUMP_STUB_STDERR`, exits with `$NFDUMP_STUB_EXIT`
  and, when `$NFDUMP_STUB_ARGS` names a file, writes its arguments there, for
  tests that need a fixed answer or check the command line. The `nfdump-z-*`
  scripts answer `FilterValidator`'s `nfdump -Z` check: a valid filter, a
  syntax error, an unknown protocol, a host name that does not resolve, and a
  check that hangs until the timeout. `nfdump-no-nel` is an nfdump built
  without the NEL statistics, for `StatisticCatalog`.
- **Offline test doubles for I/O-bound classes.** `VictoriaMetricsTest.php`
  declares a `class VictoriaMetricsTest extends VictoriaMetrics` at the top of the
  file that overrides `httpGet()`/`sendToVM()`/`tcpConnect()` with in-memory
  stubs, so the suite runs without a real VictoriaMetrics. If you add a method to
  `VictoriaMetrics` that the double overrides, keep the signatures in lockstep:
  PHP fatals on a parent/child signature mismatch.
- **Env-var isolation.** A test asserting "defaults when no env vars are set" has
  to `putenv('NFSEN_SOURCES')` etc. itself, and clean up what it sets; `getenv()`
  sees the real ambient environment, which in a dev container may already have
  `NFSEN_SOURCES`/`NFSEN_PORTS` exported for the running app.
- **Profile-aware paths.** `Rrd::get_data_path()`/`create()` nest files under
  `{data_path}/{profile}/...`, not flat, and `write()` also drops a `.rrd.first`
  sidecar next to the `.rrd`, which a cleanup glob for `*.rrd` alone won't catch.
- **Pages render lazily.** `Shell::render()` renders only the active page, so a
  test that needs a page's template data sets the `page` signal to it before
  rendering. `PageRegistryTest` pins what each page and module declares and
  renders.

## End-to-end tests

```bash
pnpm run test-e2e                                   # every file in tests/e2e/ against BASE
BASE=http://localhost:8080 CHROME=/usr/bin/chromium pnpm run test-e2e
node tests/e2e/flows.test.mjs                       # one file on its own
```

`tests/e2e/` drives a real headless Chrome against a running app instance: actual
clicks, actual `nfdump` queries, actual SSE-pushed DOM updates. That catches bugs
the Pest suite structurally can't: JS runtime errors, races between client-side
state and server-pushed patches, and the client/server wire contract of actions.

**No Playwright/Puppeteer dependency.** `lib/cdp.mjs` talks raw Chrome DevTools
Protocol over Node 22's native `WebSocket`, using `CHROME` or the newest
Playwright-managed Chromium under `~/.cache/ms-playwright`
(`npx playwright install chromium` fetches one). `BASE` defaults to
`http://localhost:8080`, the dev compose port.

| File | Covers |
|---|---|
| `smoke` | Every page from the sidebar and the tab bar without console errors |
| `router` | Hash routing: the default page, old bookmarks, reload and history |
| `controls` | The controls bar: presets, custom duration, step buttons, sources, protocol, unit, profile |
| `graphs`, `graphs-ports`, `overview` | The traffic graph, the Ports display, and the Overview KPI and top-N |
| `talkers`, `statistics`, `statistics-aggregation` | Top Talkers: the picker, a real run, Flow Records aggregation |
| `flows`, `columns` | Flows: run, paging, tabs, exports, result hosts, the Columns menu |
| `conversations` | One run as Sankey, Matrix and IP pairs |
| `filter-validation`, `drawer` | Live validation and estimates; the filter builder and saved filters |
| `alerts`, `health`, `settings` | The monitor and settings pages |
| `mobile` | The phone and tablet shell |
| `ui-controls` | The shared controls: tabs, menus, focus, forced colours |
| `no-auto-query` | Nothing reads a capture file without a Run (checks the requests and the `query_runs` table) |

`run.mjs` runs every `*.test.mjs` file sequentially and exits non-zero on any
failure; each file also runs on its own through an `import.meta.url` check.

Switches:

- `E2E_SKIP_MUTATING=1` skips the files that change persisted state (`alerts`,
  `drawer`, `settings` export `MUTATING = true`) and the mutating parts of
  `controls` and `health`.
- `NFSEN_SQLITE=<path>` tells `no-auto-query` where the instance's SQLite store
  is, when it is not `backend/settings/nfsen-ng.sqlite`; `E2E_SKIP_QUERY_RUNS=1`
  skips that check.
- `NFDUMP_HAS_NEL=1` for an nfdump that computes the NEL statistics, which
  `talkers` otherwise expects to be disabled.
- `E2E_FAST=1` skips the wait for a real live tick in `filter-validation`.
- `E2E_SHOTS=<dir>` is where `flows` writes its forced-colours screenshots
  (default `/tmp`).

### Helpers and patterns

`withPage(fn, {width, height, mobile})` opens a tab and hands `fn` a page object
with, among others, `gotoPage(id)` (clicks the page's link in the sidebar, the
tab bar or its More menu, and waits for `#page-<id>[data-ready]`),
`setRangePreset(id)`, `runQuery(target)` (presses the
`button[data-run=<target>]` of a query and waits for it to finish),
`signalValue(name)` and `signalValues(names)` (read signals by name through the
hashed ids), `chooseTheme(choice)`, `withForcedColors(fn)`, `requestLog()` and
`realErrors()` (console errors, none of them tolerated).

- **Only the active page is in the DOM in full.** The other page sections hold a
  skeleton, so wait for `#page-<id>[data-ready]` before querying a page, and scope
  selectors to `#page-<id>`.
- **Read related signals in one evaluate.** `datestart`, `dateend`, `range_live`
  and `range_preset` change together; separate reads can tear across a patch.
- **Don't assume query results have rows.** A dev instance may have gaps in its
  stored series, and the top-N lists fill in over time. Tests assert on the result
  notice or the empty state where data may be missing, and say when they skip.
- **The dev app restarts on every file change**, anyone's. A failure at "Datastar
  to boot" or an `Invalid context` during a run is usually such a restart; rerun
  the file.
- **The mutating files clean up after themselves** where the page offers a way:
  `alerts` creates a uniquely named rule and deletes it, `drawer` deletes the
  filters it saved. Test events of alert rules stay in the history.

## Screenshots for this book

`book/_capture.mjs` drives the running app with the same CDP helpers and writes
every image in `book/src/images/`, each in the light and the dark theme stitched
side by side:

```bash
CHROME=/usr/bin/chromium BASE=http://localhost:8080 node book/_capture.mjs
```

It needs ImageMagick on `PATH`. `OUT` writes elsewhere (to check a run before it
touches the book), `ONLY` limits it to some images; the comment at the top of the
script lists the other variables. An image is replaced only when it changed by
more than antialiasing noise, so a rerun leaves an unchanged book untouched.

## Static analysis

```bash
composer test-phpstan     # phpstan analyse backend
```

The level is **8**, set in `phpstan.neon` rather than on the command line, so an
IDE or a bare `vendor/bin/phpstan` analyses exactly what CI does. In a
memory-constrained container, PHPStan's default 128M can run out before it
finishes; run it with an explicit limit:

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse backend -a backend/settings/settings.php --memory-limit=1G
```

`composer before-commit` runs `fix` (php-cs-fixer) then `test-phpstan`; the
convention is to run it after any PHP change, before committing. For the
frontend, `pnpm run lint` and `pnpm run format` run Biome over
`frontend/js/components` and `frontend/css`.
