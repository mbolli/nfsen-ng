# Nfdump Integration

`backend/processor/Nfdump.php` is the one place that shells out to the real
`nfdump` binary. Every other consumer — the Flows tab, Statistics, Sankey,
alert traffic filters — goes through it.

## Command construction

```
{binary} {flattened options} {escapeshellarg(filter)}
```

Options (`-R`, `-M`, `-o json`, `-a`, …) are set via `setOption()` and
flattened in registration order; the filter expression, if any, is appended
as a single **trailing, shell-escaped, bare argument** — not `-f`. That flag
is reserved by nfdump for "read the filter from a file"; passing a filter
string to `-f` fails with a `path does not exist` error rather than a filter
syntax error, which is easy to misdiagnose if you're testing a filter by
hand outside the app.

## Execution

`execute()` runs the command via `proc_open` (prefixed with `exec` so
`proc_get_status()['pid']` is nfdump's own PID, not a wrapping shell's —
otherwise `kill-nfdump` would kill the shell and leave nfdump running),
separates stdout/stderr, and:

- treats known exit codes specially (127 = binary missing, 254 = filter
  syntax error, 255 = init failure, 250 = internal error),
- treats `"No matching flows"` as a normal empty result, not an error,
- decodes JSON (array or newline-delimited, depending on query type),
- logs the exact constructed command at `LOG_DEBUG` — the fastest way to
  confirm what a given UI action actually asked nfdump for.

## The concurrency guard

`Config::$settings->nfdumpMaxProcesses` caps how many nfdump processes may
run at once; `execute()` checks `Misc::countProcessesByName('nfdump')`
before starting a new one and throws if the cap is already hit, rather than
piling up parallel scans on a system that's likely I/O-bound already.

That counter needs `ps` or `pgrep` on `PATH` — if neither is present (e.g. a
minimal container image missing `procps`), it silently returns `0` and the
guard never trips. That failure mode is exactly why there's a "Process
inspection" entry in the Admin health panel's `nfdump` group: it flags a
missing `ps`/`pgrep` explicitly, rather than leaving the guard's silence to
look like "no other nfdump running" (see
[Health Checks & Admin](../features/health-admin.md)).
