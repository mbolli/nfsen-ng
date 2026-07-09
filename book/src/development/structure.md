# Project Structure

```
backend/
  app.php                  entry point — every signal, action, and view is defined here
  common/                  Config, Settings, HealthChecker, AlertManager, ImportDaemon, Misc, ...
  actions/                 one file per feature area: GraphActions, FlowActions, StatsActions,
                            SankeyActions, AlertActions, SettingsActions, ImportActions, UtilityActions
  datasources/             Datasource interface + Rrd, VictoriaMetrics implementations
  processor/               Nfdump — the nfdump subprocess wrapper
  templates/               Twig: layout.html.twig (shell) + partials/ (one per tab/section)
  settings/                env vars = deployment config; settings.php(.dist) = deprecated file overlay; preferences.json = user-saved overrides (win on overlap)
frontend/
  js/components/            Web Components: nfsen-chart, nfsen-table, nfsen-daterange, nfsen-sankey, ...
  js/datastar.js, nouislider.min.js, echarts.min.js
                            vendored, copied in by `pnpm install`'s postinstall (see package.json)
tests/
  Unit/                    Pest unit tests — one file per class, roughly
  Feature/                 tests that exercise real I/O (RRD file creation, etc.)
deploy/
  Dockerfile, Dockerfile.dev, docker-compose*.yml, Caddyfile*
.github/workflows/
  release.yml              version bump + tag, manually triggered
  docker-publish.yml       builds/pushes the app image to GHCR (bundled Caddy uses the stock image)
  mdbook.yml               builds this book and deploys it to GitHub Pages
                            on every push to master that touches book/**
book/
  book.toml, src/          this book
  _capture.mjs             screenshot driver — see the Introduction of this chapter's source
```

## Adding a feature end to end

1. **Signal(s)** in `app.php`, inside the `$app->page('/', ...)` closure.
2. **Action** in the relevant `*Actions.php` (or a new one, registered from
   `app.php`) — reads signals, does the work, calls `$c->sync()`.
3. **Template** in `backend/templates/partials/` — bind the signal
   (`{{ bind(signal) }}`), wire the action (`data-on:click="@post(...)"`).
4. **Test** in `tests/Unit/` — Pest, following the patterns already there.

`AGENTS.md` at the repo root has the exact Datastar attribute syntax
(`data-on:click`, not `data-on:click="${...}"`; signal binding via
`{{ bind(signal) }}`; `${{ signal.id() }}` for direct assignment inside an
event expression) and a list of common footguns — worth reading before your
first template edit.
