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

- `rows`: the decoded records (JSON array or newline-delimited, csv, or the
  fixed-width text of a bidirectional aggregation read back into columns);
- `rawOutput`: nfdump's stdout, **untouched on every path**, which the Flows Raw
  output tab shows and `NfdumpSummary::fromTextFooter()` parses;
- `command`: the exact command line, as shown to the user;
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

## The concurrency guard

`NfdumpSlots` caps how many nfdump processes run at once, at
`Config::$settings->nfdumpMaxProcesses`, rather than piling up parallel scans on a
system that is likely I/O-bound already. `execute()` takes a slot before spawning
and releases it afterwards, so a caller that finds none free waits briefly and
only fails if none frees up in time.

A slot is taken per nfdump run, not per query, because a filtered graph build
runs one per time bin and would otherwise hold the cap for its whole duration.
The import daemon's runs and the top-N collector's take slots too, which is why
the default is `2` rather than `1`: browsing while an import is in progress should
not queue behind it. The collector additionally leaves one slot free for users
(see [Import Pipeline](import-pipeline.md#top-n-collection)).

`NfdumpSlots` also records which query owns each running process, keyed by a
query handle: the caller's context id for a browser tab, `topn` for the
collector. The Kill action (`kill-nfdump`) stops the processes of its own tab's
handle only, so with several queries in flight it hits the right one, and never a
collector run.

The cap counts this worker's own processes. An nfdump someone starts by hand, or a
second nfsen-ng on the same host, is not counted.
