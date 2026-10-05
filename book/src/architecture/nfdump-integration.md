# Nfdump Integration

`backend/processor/Nfdump.php` is the one place that runs queries through the
real `nfdump` binary. Top Talkers, Flows, Conversations, the filtered graphs, the
Overview exact run, the top-N collector and the alert traffic filters all go
through it. Only filter validation (`FilterValidator`) runs nfdump on its own, for
a parse-only check.

## Composing the filter

A page never hands its filter text to nfdump as is. `FilterComposer`
(`backend/query/FilterComposer.php`) joins the parts with `and`, each in its own
parentheses: the global protocol as a term from `ProtocolFilter` (`proto tcp`,
`proto udp`, `(proto icmp or proto icmp6)`, or the complement for `other`), the
byte limits as `bytes > n` and `bytes < n`, an `ipv4` term where the query needs
one, and the user's filter. So an `or` in the user's filter cannot escape the
byte limits or the protocol. It counts parentheses the way nfdump 1.7.8 tokenises
them (outside one-line quoted strings and `#` comments) and rejects an unbalanced
filter with *Unbalanced parentheses in the filter.* before anything runs; a
closing parenthesis could otherwise close the wrapper and drop the rest.

## Command construction

```
{binary} {flattened options} -- {escapeshellarg(filter)}
```

Options (`-R`, `-M`, `-o json`, `-a`, …) are set via `setOption()` and flattened
in registration order. Each value is shell-escaped, and an empty value gives a
bare flag. A list value repeats the flag once per item, in order: the top-N
collector sets `-s` to a list of eight statistics, the most nfdump takes, and gets
all of them from one run (`-s 'srcip/bytes' -s 'dstip/bytes' …`).

The filter, if any, is appended as a single **trailing, shell-escaped, bare
argument** after `--`, so a filter starting with a dash is never read as an
option (`-w /tmp/x` would otherwise write a file). It is not passed with `-f`:
nfdump reserves that flag for "read the filter from a file", and a filter string
given to `-f` fails with a `path does not exist` error rather than a filter
syntax error, which is easy to misdiagnose when testing a filter by hand.

**`-M` must be registered before `-R`.** Unlike the other options, `-R`'s handler
doesn't just store its value: it calls `convert_date_to_path()` immediately, which
resolves the time range to nfcapd file paths by scanning the sources `-M` has
recorded so far. Register `-R` first and that scan sees zero sources, finds no
files, and throws. This is how a filtered alert rule once could never fire
([#153](https://github.com/mbolli/nfsen-ng/issues/153)).

Option values the client can influence are checked before they reach the command
line: the statistic element against `StatisticCatalog`, the order against
`StatisticCatalog::ORDER_BY`, the row limits against the offered values.

## Execution

`execute()` runs the command via `proc_open`, prefixed with `exec` so
`proc_get_status()['pid']` is nfdump's own PID and not a wrapping shell's
(otherwise Kill would stop the shell and leave nfdump running). It separates
stdout and stderr, and returns:

- `decoded`: the records (JSON array or newline-delimited, csv, or the
  fixed-width text of a bidirectional aggregation read back into columns);
- `rawOutput`: nfdump's stdout, **untouched on every path**, which the Flows Raw
  output tab shows and `NfdumpSummary::fromTextFooter()` parses;
- `command`: the exact command line, as shown to the user;
- `stderr`: what nfdump printed on its error output, without the notices it
  prints on every run (such as its lowered worker count); the key is missing
  when nothing else was printed;
- `notes`: what nfdump printed beside the data, such as a reached limit,
  `No matching flows`, a non-zero exit code or the execution time;
- `exitCode`.

Exit codes are read from `proc_get_status()` while the process ends, with
`Nfdump::exitCodeFrom()` as the fallback: under OpenSwoole's process hook
`proc_close()` returns the raw wait status (exit 254 arrives as 65024) and
sometimes 0 for a failed run. A non-zero exit with no rows throws
`NfdumpException` (a `RuntimeException`) with a readable message: 254 is a
filter syntax error, 127 a missing binary, 255 an initialisation failure, 250 an
internal error. `NfdumpException::wasStopped()` is true for a run ended by
signal 9 or 15, which the pages report as *Query cancelled.* rather than as an
error. `No matching flows` with exit 0 is a normal empty result.

nfdump's error text can quote the filter, markup included, so every message and
command reaches the page as plain text that Twig escapes. The exact command
is logged at `LOG_DEBUG`, the fastest way to see what a UI action asked nfdump
for.

## Filter validation

`FilterValidator` (`backend/processor/FilterValidator.php`) runs
`nfdump -Z -- <filter>`, which only parses the filter (1 to 4 ms), with a
two-second timeout. Exit code 0 means valid; otherwise the message is nfdump's
`Line N:` text (without `Line 1: ` for a one-line filter). It bypasses
`execute()`, so a check takes no nfdump slot and never waits behind a running
query. Answers are cached per binary and filter (128 entries); a timeout is not
cached. The `validate-filter` action writes the answer into `_flt_<target>`, and
the newest request wins.

`StatisticCatalog::unsupported()` uses the same parse-only run to probe the NEL
statistics once per binary: `nfdump -Z '' -s nevent/bytes` exits 1 with
*Unknown statistic* on an nfdump built without them.

## Processes and slots

`NfdumpSlots` caps how many nfdump processes run at once, at
`Config::$settings->nfdumpMaxProcesses`: `NFSEN_NFDUMP_MAX_PROCESSES`, or with
`auto` a third of the CPU cores this process may use, between 2 and 8
(`CpuBudget`). One nfdump keeps 2 to 3 cores busy (a reader thread, the main
thread that aggregates, a filter thread) and holds its own aggregation table, so
the cap bounds CPU and memory together. See
[nfdump processes and CPU cores](../deployment/configuration.md#nfdump-processes-and-cpu-cores)
for the settings.

`execute()` takes a slot before it spawns nfdump and gives it back afterwards. A
slot belongs to one of two classes, set per coroutine with `NfdumpSlots::runAs()`:

- **Interactive** (the default): a user waits for it. Top Talkers, Flows,
  Conversations, the filtered graphs, the Overview exact run and an alert's
  **Test**. It may take every free slot and waits up to 30 seconds for one.
- **Background**: the import, the top-N collector and the live alert
  evaluation. It holds at most half the slots, starts only while a slot stays
  free for a user query (with one slot: only while nothing else runs), and never
  while a user query waits. It waits up to 10 minutes, so a long query delays an
  import rather than dropping a file. The live alert evaluation limits its waits
  to 60 seconds in total.

A user query therefore waits only for a running nfdump to end, never in a queue
behind background work. The import, the alert checks and the collector each run
their own `Nfdump` instance, so a `reset()` of one never changes the options of
another while it waits for its slot.

Every run passes `-W` after the caller's options: `NFSEN_NFDUMP_WORKERS` filter
threads, 2 by default, from nfdump 1.7.3 on. Without it every nfdump starts half
the host's cores as filter threads. nfdump's notice that it lowered the count to
the cores online is dropped from what the pages show.

`NfdumpSlots` also records which query owns each running process, keyed by a
query handle: the caller's context id for a browser tab, `topn` for the
collector. The Kill action (`kill-nfdump`) stops every process of its own tab's
handle and names their PIDs, so with several queries in flight it hits the right
ones, and never a collector run. The cap counts this worker's own processes. An
nfdump someone starts by hand, or a second nfsen-ng on the same host, is not
counted.

## Filtered graphs in parallel

A filtered graph (**Apply filter** on Overview, **Build graph** on Flows) runs
one `nfdump -s proto` per time bin (`FilteredSeries`). The build takes a pool of
interactive slots with `acquireMany()` and runs one bin per slot, taking slots
that free up. After each bin it gives one back to a user query that waits, and
leaves background work the room it needs: two free slots to start a run, one
while it runs. Both wait for one bin at most. **Kill** stops every bin in flight
and keeps the bins that finished. With 4 slots, a 7-day build over busy captures
took 14 s instead of 42 s (504 bins).

## Statistics in parallel

nfdump aggregates on one thread whatever `-W` says, so a large statistic runs as
several nfdump processes over consecutive time slices, merged into exactly the
rows one process prints (`PartitionPlanner`, `PartitionMerge`). Top Talkers and
its side panels, Conversations and the Overview exact run use it.

- **When.** A read estimated at more than a second over at least 12 capture
  files, split into slices of at least 6 files each, balanced by size. The
  number of parts is the free interactive slots, at most 8. Once the limit is 3
  or more, a split leaves one slot free for another query when 3 or more are
  free, and otherwise takes up to 2. Two processes gave 1.6
  times the speed of one on `-s srcip`, four 1.9 times, eight 2.2 times.
- **Always one process.** Rankings by a rate (pps, bps, bpp), Flow Records without
  an `-A` aggregation (nfdump's per-record output cannot be summed), the
  bi-directional aggregation, Conversations on nfdump 1.7.5, and windows within a
  day of a daylight saving fall-back, whose local times repeat.
- **First pass.** Each part lists up to its share of 40,000 keys (at most 10,000,
  at least twice the rows asked for), which bounds the worker's memory. A part
  that listed fewer keys than that is complete.
- **Proof.** The merged top N is exact when every top key is known in every part
  (listed there, or found or ruled out by a lookup), and when no other key could
  still reach the N-th total: its known total plus the cutoff (the last listed
  value) of each truncated part that did not list it. Otherwise the truncated
  parts run again with `-n 0` and a filter naming the missing keys, and a lookup
  keeps only the keys it asked for. If the filter cannot name them (at most 2,000
  keys, 64 KiB) or the second pass still cannot prove the top, the query runs as
  one process, as it does after any failure short of a Kill.
- **Merge.** Counters add up, first seen is the earliest and last seen the
  latest, and pps, bps, bpp, duration and the shares are recomputed the way
  nfdump computes them. `-A` records and pairs rank by in plus out, as nfdump's
  `-O` does.
- **Progress and Kill.** The progress line counts files read across the parts,
  Kill stops every part, each part gives its slot back when it ends, and the
  final status names the processes the result came from (*Done in 3.1s with 4
  nfdump processes.*). `query_runs` records the parts and passes of every run.

With 24 million flows and 4 processes, Top Talkers ran 1.7 to 2.3 times faster
and Conversations up to 2.8 times.

When several sources are selected, `-M` names first a source that holds the
window's first capture file: nfdump reads nothing at all when the first source
lacks it, as after a capture gap or for a source added later.
