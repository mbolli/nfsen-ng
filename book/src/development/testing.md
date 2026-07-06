# Testing

```bash
composer test              # everything
composer test-coverage     # with coverage
docker compose exec nfsen pest   # from inside the dev container
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
