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
  replaces `httpGet()`/`sendToVM()`/`tcpConnect()` with in-memory stubs, so the
  suite runs without a real VictoriaMetrics. Its doubles are named classes, an
  older pattern that predates the rule below; new doubles are anonymous classes.
  If you add a method to `VictoriaMetrics` that a double overrides, keep the
  signatures in lockstep: PHP fatals on a parent/child signature mismatch.
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
- **Shared helpers.** Pest loads `tests/Helpers.php` before every test file:
  `makeCaptureTree()` builds a throwaway nfcapd tree and `removeTree()` removes
  it. A helper that two files need goes there, so every file also runs on its
  own.
- **Anonymous test doubles.** A named class at the top of a test file gives
  *Class not found* once Pest runs more than one file, and php-cs-fixer's PSR-4
  rule renames it after the file. Test processors and datasources are anonymous
  classes, or come from a function that returns one.
- **Coroutines hook file functions.** OpenSwoole's `Coroutine::run()` turns on
  every hook, so `mkdir` and `file_put_contents` yield inside it (`touch`,
  `is_dir`, `filemtime` and PDO do not). A test that races two coroutines
  creates its files before it starts them.

## Front-end and deployment checks

Five Pest files check things that are not PHP code:

- `FrontendAssetsTest`: `frontend/js/datastar-rocket.js` starts with the banner
  of Datastar 1.0.4, Rocket beta.2 and `patches/rocket`, the patch files match
  `patches/rocket/rocket.lock.json`, `package.json` pins that Datastar release,
  the import map's `datastar` URL is the bundle's script URL, the old
  `datastar.js` is gone, the licence files are there, and
  `StarbaseAssets::modules()` reads the Starbase lock (on, off, missing file,
  bad JSON, bad slug).
- `StarbaseVendorTest`: the offline checks of `scripts/starbase-vendor.mjs check`
  in PHP. Every vendored folder hashes to its version, every file to the lock,
  the licence is there, the lock's Datastar banner and sha256 match the bundle,
  and a vendored module imports only `'datastar'` or files of its own folder.
  Two fixture folders in `tests/Support/starbase-walk/` pin the order Starbase's
  Go code hashes files in.
- `StarbaseBridgeTest`: every `var(--sb-*)` a vendored module reads is defined in
  `frontend/css/starbase.css` or listed as a size knob, every token there maps to
  a token of `tokens.css`, and no module carries a pixel trait the tokens cannot
  neutralise (`steps(`, `pixelated`, uppercase labels, fixed notch clip paths).
- `PopoverMarkupTest`: every literal `<sb-popover>` block in `backend/templates`
  keeps to the popover exception of rule K1 in `AGENTS.md`. Its light DOM
  carries no Datastar attribute besides `data-on:*`, `data-attr:*`,
  `data-class:*`, `data-style:*`, `data-effect`, `data-text`, `data-show` and a
  value-form `data-bind`, no `$$`, and no `@name(` in a plain `data-*` value.
  String fixtures show that it reports each forbidden form with its line.
- `DeployFilesTest`: both Dockerfiles and the systemd unit give PHP the same
  `memory_limit`.

`NfdumpVersionTest` builds a capture with nfcapd, a flow and its reverse, and
checks that the installed nfdump pairs them under `-B`; it skips where nfdump is
older than 1.7.10 or not installed.

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
| `talkers`, `statistics`, `statistics-aggregation` | Top Talkers: the picker, a real run, the Export popover, Flow Records aggregation |
| `flows`, `columns` | Flows: run, the list, tabs, exports from the server, a new result's list, 10,000 rows in three tabs; the Export popover and the Columns popover of the Flows list and the Top Talkers table: keys, a sync while open, a second run, an export right after the result arrives, a phone screen |
| `vscroll` | The Flows list of 10,000 rows against the server's own exports: windows, the last and seeded rows, stable sorts, syncs and other result tabs that leave it alone, Tab and Shift+Tab through 200 rows, the header, the Columns picker kept across a Run and a reload, CSV, JSON and Print, a new result's list collected, themes, densities, and a context revived by a window request (`tests/e2e/lib/vscroll.mjs` holds the shared helpers; mutating) |
| `conversations` | One run as Sankey, Matrix and IP pairs, and the Export popover, with the PNG item disabled in IP pairs and the nfdump command copied unchanged |
| `filter-validation`, `drawer` | Live validation and estimates; the filter builder and saved filters |
| `alerts`, `health`, `settings` | The monitor and settings pages |
| `mobile` | The phone and tablet shell |
| `ui-controls` | The shared controls: tabs, menus, focus, forced colours; and the popover layer on fixture popovers: keys, the `.popover-list` item look, choosing, one open layer, a modal over a popover, a moved host, a sync around an open one, forced colours, a phone screen, a trigger before `sb-popover` is defined |
| `no-auto-query` | Nothing reads a capture file without a Run (checks the requests and the `query_runs` table) |
| `rocket` | The Rocket rules on every page, with a result on Flows, Top Talkers and Conversations: one engine, no `data-init` on a host and no plugin attribute in its light DOM except those a popover may carry (fixture popovers check that the scan reports the rest), toasts in all four stacks, elements that keep their identity through a run and every page, labels, copying, and the DOM nodes removed hosts leave behind, popovers included |
| `starbase-bridge` | Every colour token of `starbase.css` resolves to its nfsen-ng token in light and dark, every vendored Starbase component mounts without a console error, and the open `sb-popover` has nfsen-ng's shadow and the menu list's radius, padding and minimum width |

`run.mjs` runs every `*.test.mjs` file, or the files named as arguments
(`node tests/e2e/run.mjs flows rocket`), one after another, and exits non-zero on
any failure. A file that has not finished after `E2E_FILE_TIMEOUT` seconds (900)
fails, and its browsers are killed. Each file also runs on its own through an
`import.meta.url` check.

Switches:

- `E2E_SKIP_MUTATING=1` skips the files that change persisted state (`alerts`,
  `drawer`, `settings` export `MUTATING = true`) and the mutating parts of
  `controls`, `health` and `rocket` (its alert Test dialog step).
- `NFSEN_SQLITE=<path>` tells `no-auto-query` where the instance's SQLite store
  is, when it is not `backend/settings/nfsen-ng.sqlite`; `E2E_SKIP_QUERY_RUNS=1`
  skips that check.
- `NFDUMP_HAS_NEL=1` for an nfdump that computes the NEL statistics, which
  `talkers` otherwise expects to be disabled.
- `E2E_FAST=1` skips the wait for a real live tick in `filter-validation`.
- `E2E_SHOTS=<dir>` is where `flows` writes its forced-colours screenshots
  (default `/tmp`).
- `STARBASE_DIR=<Starbase clone>` makes `starbase-bridge` also mount 23
  catalog components from the clone (drawer, popover, checkbox, checkbox-group,
  date-picker, virtual-scroll, data-table, input, select, tabs and the like; a
  vendored one comes from the clone too) and fail on a colour that is neither
  neutral nor an nfsen-ng status or series colour, on text below 4.5:1, and on
  a selected state that differs only in font weight. It reads the clone from
  disk. `OUT=<dir>` is where its screenshots go (default `/tmp/starbase-bridge`).

### Helpers and patterns

`withPage(fn, {width, height, mobile})` opens a tab and hands `fn` a page object
with, among others, `gotoPage(id)` (clicks the page's link in the sidebar, the
tab bar or its More menu, and waits for `#page-<id>[data-ready]`),
`setRangePreset(id)`, `runQuery(target)` (presses the
`button[data-run=<target>]` of a query and waits for it to finish),
`signalValue(name)` and `signalValues(names)` (read signals by name through the
hashed ids), `syncNow(id)` (posts `refresh-graphs` as page `id` and waits for
the morph it causes, to check what an open popover keeps across a sync),
`chooseTheme(choice)`, `withForcedColors(fn)`, `requestLog()` and
`realErrors()` (console errors, none of them tolerated). `runQuery` waits up to
30 seconds for the Run button, since it stays disabled while any query of the
tab runs, and also accepts an action that answers without a run (a cached
build). A test reaches Datastar's store through the page's import map,
`(await import('datastar')).root`.

- **Only the active page is in the DOM in full.** The other page sections hold a
  skeleton, so wait for `#page-<id>[data-ready]` before querying a page, and scope
  selectors to `#page-<id>`.
- **Read related signals in one evaluate.** `datestart`, `dateend`, `range_live`
  and `range_preset` change together; separate reads can tear across a patch.
- **Don't assume query results have rows.** A dev instance may have gaps in its
  stored series, and the top-N lists fill in over time. Tests assert on the result
  notice or the empty state where data may be missing, and say when they skip.
- **The dev app restarts on every file change**, anyone's, and php-via then
  reloads every open tab. The page object notices a load it did not ask for:
  the next `evaluate`, `waitFor`, `gotoPage` or `runQuery` throws
  `AppReloadedError` (*THE APP RELOADED the page mid-test*), and a test that
  fails for another reason after a reload says so in its message. Rerun the
  file. `navigate()` and `reload()` are expected loads; a test that causes one
  some other way (a link, `location.reload()`) calls `page.expectNavigation()`
  first, and `page.reloadCount` counts the ones nobody announced.
- **No browser outlives the run.** Every Chromium gets a debugging pipe, so it
  exits when Node does, even after a `SIGKILL`; exit and signal handlers kill it
  otherwise, and a detached watchdog removes its profile directory. Each browser
  picks a free debugging port.
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
IDE or a bare `vendor/bin/phpstan` analyses exactly what CI does. The app image
gives PHP the server's `memory_limit` of 512M, which PHPStan can run out of;
run it with an explicit limit:

```bash
php -d memory_limit=1G vendor/bin/phpstan analyse backend -a backend/settings/settings.php --memory-limit=1G
```

`composer before-commit` runs `fix` (php-cs-fixer) then `test-phpstan`; the
convention is to run it after any PHP change, before committing. For the
frontend, `pnpm run lint` and `pnpm run format` run Biome over
`frontend/js/components` and `frontend/css`; Biome leaves the vendored
`frontend/js/starbase/` alone.

Two scripts check the vendored JavaScript offline:

```bash
sh scripts/vendor-rocket.sh --check          # datastar-rocket.js is the build of Datastar + patches/rocket
node scripts/starbase-vendor.mjs check       # frontend/js/starbase/ matches its lock
```
