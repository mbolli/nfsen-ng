# Project Structure

```
backend/
  app.php                  entry point: server config, then one page('/') that asks every shell
                            module and page for its signals and actions and renders the Shell
  mcp.php                  MCP server over stdio, run as its own process (see features/mcp.md)
  pages/                   the UI composition (namespace mbolli\nfsen_ng\pages)
    Page.php, ShellModule.php   the two interfaces
    PageRegistry.php        page and module order, legacy view ids, query kind → page
    Shell.php               layout data, footer status, modal, the render itself
    OverviewPage.php, TalkersPage.php, FlowsPage.php, ConversationsPage.php,
    AlertsPage.php, HealthPage.php, SettingsPage.php        one class per page
    RangeControls.php, TrafficGraph.php, QueryKit.php, FilterDrawer.php   shell modules
    PageStates.php, Revival.php, state/   per-tab state: ShellState and one class per query page
  actions/                 action closures per area: RangeActions, GraphActions, StatsActions,
                            FlowActions, FlowGraphActions, ConversationActions, AlertActions,
                            ImportActions, SettingsActions, QueryKitActions, FilterDrawerActions,
                            ShellActions, UtilityActions, plus QueryRunner (progress, Kill, timings)
  query/                   transport-agnostic queries: TimeWindow, StatsQuery, FlowsQuery,
                            MatrixQuery, TopNQuery, TimelineQuery, LoadQuery, CoverageQuery,
                            FilterComposer, ProtocolFilter, StatisticCatalog, FilterGrammar,
                            QueryEstimator, CostEstimate, ConversationPayload, and
                            PartitionPlanner with PartitionMerge (one statistic as parallel
                            time slices, merged exactly).
                            Actions and the MCP tools both call these; neither calls the other
  store/                   the SQLite store (namespace mbolli\nfsen_ng\store): Database,
                            Migrator, migrations/, TopNRepository, SavedFilterRepository,
                            SavedFilterSeeder, AlertEventRepository, AlertSampleRepository,
                            QueryRunRepository
  common/                  Config, Settings, EnvRegistry, UserPreferences, HealthChecker,
                            HealthMetrics, AlertManager, ImportDaemon, Import, TopNCollector,
                            ImportStats, CpuBudget (cores and the nfdump process limit),
                            LoopLag (the event-loop lag probe), StarbaseAssets (the vendored
                            components to load), LogRing, Debug, GeoIpDatabase, IpLookup,
                            Table, ...
  mcp/                     optional read-only MCP server: ToolRegistry, Guard, HttpEndpoint,
                            Tool/ (one class per tool)
  datasources/             Datasource and TotalsProvider interfaces, Rrd, VictoriaMetrics
  processor/               Nfdump (the nfdump subprocess wrapper), NfdumpSlots (how many run
                            at once, in which class, and which query owns each),
                            FilterValidator (nfdump -Z), FilteredSeries, MultiStatCsvParser,
                            NfdumpSummary
  templates/
    layout.html.twig        the document: head scripts, sidebar, controls bar, graph, pages
    shell/                  sidebar, controls-bar, traffic-graph, page-header, page-skeleton,
                            footer, bottom-tabs, icons
    pages/                  one template per page (overview, talkers, flows, conversations,
                            alerts, health, settings) plus alert-test-result
    components/             filter-field, query-estimate, result-host (reused by the pages)
    drawer/                 filter-drawer
    partials/               aggregation-controls, progress-button, ip-info-modal
  settings/                env vars = deployment config; settings.php(.dist) = deprecated file
                            overlay; in dev also the state: preferences.json, alerts-state.json,
                            nfsen-ng.sqlite
frontend/
  css/
    tokens.css              design tokens: neutral surfaces and text, status and series colours
    starbase.css            Starbase's --sb-* tokens mapped onto the tokens above
    ui.css                  elements and shared components (button, card, tabs, menu, notice, ...)
    shell.css, controls-bar.css, traffic-graph.css, query-kit.css, drawer.css   shell parts
    nfsen-ng.css            pieces several pages share: chart containers, result tables, aggregation controls
    pages/                  one stylesheet per page
  js/components/            Rocket elements: nfsen-chart, nfsen-table, nfsen-sankey,
                            nfsen-matrix, nfsen-toast, nfsen-filter-editor.
                            Plain modules loaded before the bundle: nfsen-router,
                            alert-template-preview, filter-drawer, chunks (nfsen/chunks),
                            flows-list (the Flows list's exports and Columns choice).
                            Plain modules after it: datastar-persist (a Datastar plugin),
                            nfsen-controls, clipboard (nfsen/clipboard).
                            Imported only: format, download, host-state, theme-colors, tz-utils
  js/echarts.min.js         copied in by `pnpm install`'s postinstall (see package.json)
  js/starbase/              vendored Starbase components, one <slug>@<version>/ folder each,
                            starbase.lock.json and Starbase's LICENSE
                            (scripts/starbase-vendor.mjs; see AGENTS.md)
tests/
  Unit/                    Pest unit tests, one file per class roughly
  Feature/                 tests that exercise real I/O (RRD file creation, etc.)
  Arch/                    architecture rules (namespaces, dependencies)
  Helpers.php              functions Pest loads before every file (capture trees and the like)
  Support/                 FakeProcessor, fake nfdump binaries, nfcapd-written capture
                            fixtures (captures/) and the Starbase hash fixtures (starbase-walk/)
  e2e/                     browser tests driving a real headless Chrome over CDP; run.mjs runs
                            them against a live instance (BASE=, CHROME=)
deploy/
  Dockerfile, Dockerfile.dev, docker-compose*.yml, Caddyfile*, systemd/, unraid/
scripts/                   starbase-vendor.mjs (vendors and checks Starbase components),
                            seed and triage helpers
.github/workflows/
  release.yml              version bump + tag, manually triggered
  docker-publish.yml       builds/pushes the app image to GHCR (bundled Caddy uses the stock image)
  mdbook.yml               builds this book and deploys it to GitHub Pages
                            on every push to master that touches book/**
book/
  book.toml, src/          this book
  _capture.mjs             screenshot pipeline for the images in src/images (see Testing)
```

## Adding a feature end to end

1. **Signals** in the page's `signals()` (`backend/pages/<Name>Page.php`), or in
   the shell module that owns the concern. Global state (range, sources, protocol,
   unit) belongs to `RangeControls`.
2. **Action** in the page's `register()`, usually by calling a static
   `register()` of a class in `backend/actions/`. It reads signals, does the work
   (a query runs through `QueryRunner::run()` with a query kind), stores the
   result in the page's state, and calls `$c->sync()`. Catch `\Throwable`.
3. **View data** in the page's `viewData()`: it returns what the template reads as
   `pages.<id>`, and runs only while the page is active. Keep it cheap: read what
   actions stored, never run a query or a large SQLite read.
4. **Template** in `backend/templates/pages/<id>.html.twig`: bind signals with
   `{{ bind(signal) }}`, wire actions with `data-on:click="@post('{{ myAction.url() }}')"`.
   Styles go into `frontend/css/pages/<id>.css`, using the tokens and the
   components of `ui.css`.
5. **Tests**: Pest in `tests/Unit/`, and an e2e check in `tests/e2e/<page>.test.mjs`
   for anything a user clicks.

`AGENTS.md` at the repo root has the Datastar attribute syntax, the CSS rules, the
rules for Rocket elements and Starbase components, and the common pitfalls; read
it before your first template edit.

## Third-party notices

The files nfsen-ng ships from other projects keep their licence next to them:
`frontend/js/echarts.LICENSE` and `frontend/js/echarts.NOTICE` (Apache ECharts,
Apache-2.0) and `frontend/js/starbase/LICENSE` (Starbase, MIT). `pnpm install`
copies the first two, `scripts/starbase-vendor.mjs pull` the last; the Docker
image ships them with `frontend/`. The Datastar bundle and its notices (Datastar
and Starbase, MIT) come with php-via, in `vendor/mbolli/php-via/public/DATASTAR.md`.
