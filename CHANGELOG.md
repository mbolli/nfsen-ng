# Changelog

All notable changes to nfsen-ng are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **Sources can have names, and a searchable picker** ([#177](https://github.com/mbolli/nfsen-ng/issues/177), requested by [@hawk128](https://github.com/hawk128)). `NFSEN_SOURCES=10-20-100-3:dc1rt310,...` (or `'source' => 'name'` in `settings.php`) shows the name in the sources picker, the graph legend, the alert rules and notifications, Health and Settings, which is what makes the directories `nfcapd -M` names after exporter addresses readable. The source itself stays what queries and stored data use. The sources menu is now a picker you can type in: it finds a source by name or by source, picks several, has **Select all** and **Clear**, and reads *All sources* or *3 of 84 sources* when closed.

### Changed

- **IP info and the alert Test result open in Starbase's `sb-modal`**, the same dialog the rest of Starbase's components use, with its heading, close button and focus handling. A toast shown while one is open still lands inside it.

- **The Export menus of Top Talkers, Flows and Conversations are Starbase's `sb-dropdown`**: a real menu, opened and walked with the keyboard like one, with a caret that turns while it is open.

- **The filter builder slides in as Starbase's `sb-drawer`**, with its close button and focus handling. Escape, the close button, a click beside it and **Cancel** all keep an unapplied draft for the next time, as before.

- **The start and end of the range are picked in a calendar** (Starbase's `sb-date-picker`): both days and both times in one place, in five-minute steps like the capture files, in the display timezone, with the days outside the stored data struck out. **Apply** sets the range. It replaces the two date-time fields.

### Fixed

- **All sources could not be unticked** ([#176](https://github.com/mbolli/nfsen-ng/issues/176), reported by [@hawk128](https://github.com/hawk128)), so picking a few of many sources meant unticking the rest one by one. The new sources picker starts from all and picks only what you choose; **Clear** goes back to every source.

## [1.0.0-beta.6] - 2026-10-05

### Added

- **A new layout.** A sidebar leads to seven pages, each with its own address (`#/overview`, `#/flows`, ...), so a page can be bookmarked, opened in a new tab and reached with the back button. One controls bar sets the time range, sources, protocol and unit for every page, and the traffic graph above every analysis page is the range picker: drag across it to set the range, or Ctrl + wheel to preview a zoom. The server renders only the page you are on. Below 768px the sidebar becomes a tab bar with Overview, Flows, Conversations, Alerts and More.

- **Overview top talkers from precomputed data.** While importing, nfsen-ng stores the top 50 of nine statistics (addresses, ports, protocol, AS and interfaces, per direction) for every capture file in SQLite, with exact hourly and daily sums. Overview reads them for its top-N table and three KPI cards (top source, top destination, top protocol) beside the total traffic, without running nfdump, and labels them as approximate. `NFSEN_TOPN_RETENTION_DAYS` (default 31, `0` turns collection off) sets how far back they reach; for older ranges the card offers an exact run with nfdump. Budget about 12 MB per source and day.

- **Top Talkers** (the Statistics tab) picks the common statistics as tabs (Talkers, Ports, Protocols, ASNs, Interfaces) with a Source/Destination/Any direction, and all 58 statistic types under More statistics; types the installed nfdump cannot compute are shown disabled with the reason. Choosing a statistic never runs it. Two side panels, Protocol share and Top ASNs, each run on their own.

- **Flows** shows the files, size and time a run will read before it runs, checks the filter with `nfdump -Z` while you type, and splits the result into Flows, Raw output (nfdump's output and command, with Copy and Download) and Summary tabs. Every returned row is in one scrolling list (up to the limit of 10,000) that the server fills as you scroll, so a large result needs neither pages nor every row in the browser; the line next to the tabs says when nfdump's row limit cut it short. Sorting and hidden columns apply to the whole result, are remembered per browser for the next Run, and Tab and Shift+Tab walk the rows one at a time. CSV, JSON and Print come from the server with every row in the list's order and columns. Summary adds the unfiltered totals of the range from the stored series and, on request, the filtered totals without the row limit.

- **Conversations** (the Sankey tab, [#152](https://github.com/mbolli/nfsen-ng/issues/152)) groups pairs by address, /24, /16 or destination port, merges both directions of a pair or keeps them apart, and shows one result as a Sankey, a Matrix and a ranked IP pairs table, with the traffic outside the top pairs as Others. Export as CSV, JSON or PNG.

- **Filter builder.** A drawer for every filter field, the alert rule's included: a field reference and examples that insert at the cursor, keyword completion, a raw editor, live validation, the estimate, and **Apply and run**. Saved filters live on the server in SQLite, shared by every browser, with search, stars, rename and delete. Each browser's old locally saved filters and the filter presets from Settings are moved in once; `NFSEN_FILTERS` presets are added once each.

- **Alerts page.** Rules and the recent history side by side, with each rule's status and last firing. A rule that fired and then finds its condition false records a *resolved* event (not notified). The history lives in SQLite; the old `alerts-log.json` is moved in once on the first start and renamed. Test opens a dialog with the verdict, the figures, the interval and the rendered messages.

- **Health page.** Import controls and rate, pending files, the top-N collector, capture freshness per source, disk usage of the capture, data and state directories, uptime and versions, the grouped checks (now with a Storage (SQLite) group and a check that nfcapd file names match their write time), and the last 200 log lines with a level filter.

- **Settings tabs** General, Sources, Storage, Integrations and System, with new preferences for the default time range, the default unit, the instance theme (the default for browsers without their own choice), compact tables, and a reverse DNS switch. The theme menu in the sidebar offers Light, Dark, System and the instance default.

- **Local GeoIP.** With `NFSEN_GEOIP_DB` pointing at a MaxMind GeoLite2/GeoIP2 City or Country `.mmdb`, IP lookups answer locally, with no rate limit and no request leaving the host. It uses the pure-PHP `maxmind-db/reader` package, so no PHP extension is needed.

- **The Flow Records statistic can be aggregated** ([#174](https://github.com/mbolli/nfsen-ng/issues/174), reported by [@bbaugnies](https://github.com/bbaugnies)). `nfdump -s record` ranks whole flows, so what counts as one flow decides what the ranking means, and the option was not reachable from nfsen-ng at all. Top Talkers now carries the same aggregation controls as Flows, shown for **Flow Records** only: nfdump applies an aggregation to that statistic alone and answers every other one with `Aggregation ignored for element statistics`.

- **Event loop lag on Health.** The System card shows how late the server's event loop answers: the p95, p50 and maximum delay of a 100 ms timer over the last minute, with the warning glyph from a p95 of 100 ms and the error glyph from 1 s. Whatever holds the loop (a render, a file scan, an SQLite write) delays every tab's actions and live updates by as long.

- **Components from [Starbase](https://github.com/zweiundeins/starbase) (MIT) are bundled** under `frontend/js/starbase/` with their licence, pinned by Starbase's content hash in a lock file; nothing loads from outside the instance. The Alerts page uses its `sb-relative-time`, and every menu its `sb-popover`. The ECharts licence notices ship as well (`echarts.LICENSE`, `echarts.NOTICE`); Datastar's comes with php-via (`public/DATASTAR.md`).

### Changed

- **One logo**: flows fanning out, in the interface's neutral greys, for the sidebar, the favicon, the home-screen icon and the Unraid app icon. The favicon kept the Unraid icon's teal bands until now.

- **SQLite is now required.** The Docker image already has it; bare-metal installs need `php8.4-sqlite3` and `phpenmod pdo_sqlite`. Saved filters, the alert history, recorded query timings and the Overview top-N live in `<state dir>/nfsen-ng.sqlite`; without the driver, or with a read-only state directory, the app still starts and says what is unavailable. A new composer dependency, `maxmind-db/reader`, comes with `composer install`, and the Docker image now installs from `composer.lock`.

- **Graphs, Statistics and Sankey are now Overview, Top Talkers and Conversations**, and Alerts and Health left Settings for pages of their own. A browser that last had a tab open under the old layout opens the page that replaced it, and the old names work in the address (`#/graphs` opens Overview).

- **Neutral light and dark themes.** Surfaces, text, borders and controls are neutral greys, so colour marks only status and data series. The graph colours come in fixed slots: a protocol or a port keeps its colour across the graph, the tables and the side panels.

- **Bootstrap is gone.** It was a CSS-only dependency: no JavaScript, no icon font, and 171 of its 2031 class selectors were ever used, so roughly two thirds of the 227 KB it shipped were rules for classes this app never writes. A token layer (`tokens.css`) and a semantic stylesheet (`ui.css`) of 56 KB together replace it, plus one small stylesheet per shell part and page; all of them come to 137 KB, against 250 KB before. Colours are `oklch()` with `light-dark()`, and the theme sets a real `color-scheme`, so native controls, scrollbars and form widgets follow the theme instead of being restyled. The markup says what things are: a button is a `button`, progress is `<progress>`, and which option is selected is `:checked` or `aria-pressed` rather than a class the server has to keep in sync.

- **`data-bs-theme` is now `data-theme`.** Only relevant if you styled nfsen-ng from outside.

- **Alert rules are evaluated once per five-minute interval**, when every source has delivered its file for it, in any arrival order; a source that stops delivering is left out after one interval instead of holding back the others. Rules without a traffic filter read each source's stored value right after its import. `{time}` in a notification is now the start of that interval, for Test too, and the cooldown counts intervals.

- **Filter presets are saved filters.** `NFSEN_FILTERS` and the `filters` key of `settings.php` seed the saved filters instead of a separate preset list, and the Settings textarea for presets is gone.

- **nfdump's raw output is passed on untouched**, on every path, with the command line and nfdump's side notes kept apart. The exit code is read with `proc_get_status()`, because under OpenSwoole's hooks `proc_close()` sometimes returned the raw wait status or 0 for a failed run.

- **php-via 0.14.0.** It needs ext-openswoole 26 (the Docker image has 26.2.0; bare-metal installs upgrade the extension first). It seeds a context revived by a request without signals (the Flows list's) from its next SSE connect, and keeps a context that an action reached while its stream was down for 60 s after that action, longer than Datastar's longest wait between reconnects, so the patches the action queued (a window of the Flows list) arrive when the stream comes back. The worker count moved from the Swoole settings to `withWorkerNum()`, which 0.14 requires. php-via no longer installs Twig, so nfsen-ng requires `twig/twig` itself. `NFSEN_LOG_LEVEL` now sets php-via's own log level too (`warning` shows its warnings and up, `err` and above its errors). Outside dev mode an action POST without an `Origin` header is refused as before, now with `403 Forbidden: missing Origin` and one warning in the log; browsers always send the header, so only a script or a probe has to add it. The tab-close beacon (`/_session/close`) is checked the same way. 0.13 drops element patches for a tab with more than 1 MB unsent instead of parking its stream; nfsen-ng keeps that, since a parked stream lost patches anyway and held a stop. The tab's next render replaces a dropped render. A dropped chunk of raw output or of an export is appended content that no render replaces: the page asks for it three times, 8 s apart, and then says which output did not arrive or that the export failed. The production image's build stops with *php-via is missing from vendor/* when Composer could not install php-via, instead of producing an image that fails at start.

- **The front end runs on Datastar 1.0.4 and its Rocket component system.** nfsen-ng's own elements (the toasts, the filter editor, the result table, the traffic graph and Flows chart, the Sankey and the Matrix) are Rocket components now; pages work as before. The bundle is Starbase's build of Datastar 1.0.4 with Rocket beta.2 and twelve fixes, eight of them filed upstream (listed in php-via's `public/DATASTAR.md`: teardown after a throwing effect, an atomic move that keeps an element as it is, form association, boolean props, shadow-tree attribute cleanup, one shared stylesheet per component, observers that hear a write of an equal value, a morph that starts inside another, which a component rendering for the first time during a page update did and which made the page lose the state of elements it moved later, a light component inside a `data-ignore-morph` container, removed components that were never garbage collected, a late definition that skipped a component's children, and a rescan per moved element). php-via serves it (`withDatastarRocket()`), and the layout loads it through `via_head()` and `via_foot()`, which also give a tab the reconnect php-via asks for after a session-cookie rotation or a worker stop.

- **Alerts: Last triggered keeps counting** while the page is open (*12 minutes ago*, *yesterday*, *last week*), and its tooltip shows the date and time in the display timezone.

- **Copy buttons share one implementation** that also works on plain HTTP, where the browser offers no Clipboard API, and say *Nothing to copy* when there is nothing yet.

- **Export menus and the Columns picker are Starbase popovers.** The CSV, JSON and Print buttons of the Flows and Top Talkers tables are now the items of one **Export** menu, as on Conversations. A click, Enter or Space opens a popover with focus on its first item; ArrowDown or ArrowUp on the button opens it on the first or last item, the arrows, Home and End move between the items and Tab walks them, and the popover closes once focus leaves it. Choosing an item or pressing Escape closes it and puts focus back on the button, a click outside closes it, and ticking a column keeps it open. The Columns picker used to have no arrow, Home or End keys and left the focus on its button when it opened. Screen readers announce both buttons as opening a dialog.

- **nfdump 1.7.10 in the Docker images** (was 1.7.8). 1.7.9 fixed remotely triggerable crashes in nfcapd's IPFIX and NetFlow v9 option template handling and in sfcapd's sFlow decoder, and out-of-bounds reads in the file parsers. 1.7.10 fixes bidirectional pairing in gcc builds: the 1.7.8 in earlier images listed both directions of a flow as separate rows, so the **Bi-directional** aggregation on Top Talkers and Flows showed an empty Out side; it now merges them. Capture files written by 1.7.8 read unchanged. Bare-metal installs: build 1.7.10 as the installation page shows, point `nfcapd.service` at `/usr/local/nfdump/bin/nfcapd` and restart it. The Health page warns below 1.7.10 and says which of the two fixes the installed version lacks.

- **`NFSEN_NFDUMP_MAX_PROCESSES` defaults to `auto`**: a third of the CPU cores the process may use (the container's CPU limit or its CPU affinity), at least 2 and at most 8. It was 2; a number keeps working as before, and the shipped `settings.php` templates now say `auto` too. **`NFSEN_NFDUMP_WORKERS`** (default 2) passes `-W` to every nfdump run from 1.7.3 on, where nfdump otherwise starts filter threads for half the host's cores in every process; nfdump's notice that it lowered the thread count is no longer shown as a warning. The **nfdump** checks on Health, the System card and **Settings > System** show the cores and where the count came from, the process limit, the `-W` in use and the processes in use per class.

- **User queries go ahead of background work.** nfdump processes come in two classes: a user query may take every free one; the import, the top-N collector and the live alert checks hold at most half of them, start only while one stays free, and wait while a user query waits, so a user query waits only for a running nfdump to end. Live alert evaluation waits at most 60 s in total for its processes, and a rule still without one is left out of that interval with `no free nfdump process` in the log. Alert checks, imports and the collector each run their own nfdump instance, so one no longer changes another's options while it waits.

- **Filtered graphs build in parallel.** **Apply filter** on Overview and **Build graph** on Flows run their per-interval nfdump calls side by side, one per free nfdump process, instead of one after another. With 4 processes, a 7-day build over busy captures took 14 s instead of 42 s (504 intervals), or 9 s instead of 21 s (144). A user query, an import or an alert check that needs a process gets one after the interval in flight, and **Kill** stops every interval in flight at once and keeps the intervals that finished.

- **Large statistics run in parallel.** Top Talkers (and its side panels), Conversations and the Overview exact run split a read of more than a second and at least 12 capture files into time slices of at least 6 files, one nfdump process per free slot (up to 8; once the limit is 3 or more, one stays free for another query when 3 or more are free), and merge them exactly: every row is the one a single nfdump run prints, proven from the parts or completed by a second, filtered pass, else the query runs as one process. The parts list at most 40,000 keys between them, so a split stays within a worker's memory. Kill stops every part, each part gives its process back when it ends, the progress counts files, and the status says how many processes the result came from. With 24 million flows and 4 processes: Top Talkers 1.7 to 2.3 times faster, Conversations up to 2.8 times.

- **Top-N collection runs in parallel.** The collector runs up to half the nfdump processes at once, one nfdump per capture file, on background processes only, so it still yields to user queries, imports and alerts. A backfill no longer pauses 10 minutes after every 500 files: the next pass starts while the queue still has 250 files, until nothing is missing or a file fails. With 8 processes on a 20-core host, capture files of 300,000 flows took 85 to 110 ms each instead of 250 to 300 ms. With fewer than 4 processes (`auto` on fewer than 12 cores), files are still collected one at a time. Each interval is now written in one small transaction, and the hour and day sums are brought up to date every 48 intervals, one bucket per transaction: during a 31-day backfill, nearly every event-loop stall of 30 ms or more had fallen inside one interval's write, the slowest 245 ms. Range answers stay exact in between.

- **The server runs with a 512 MB memory limit.** The Docker images set `memory_limit = 512M`, and `deploy/systemd/nfsen-ng.service` starts PHP with `-d memory_limit=512M`. One worker holds every tab, so running out of memory ends every session and starts a catch-up import. On bare metal, a CLI `php.ini` often says `-1`, which also turns off the Flows memory check; use the unit's setting or put `memory_limit = 512M` into the CLI configuration.

### Removed

- The date slider (replaced by the controls bar and the traffic graph), the browser-local filter manager and the filter presets textarea (replaced by the saved filters), and the count of matching files under the query panels (replaced by the estimate).

### Fixed

- **A `settings.php` without an RRD `data_path` ignored `NFSEN_RRD_PATH`**, so a Docker install that mounts one wrote its RRD files into the container instead of the volume, where an image upgrade lost them. The variable now applies unless `settings.php` sets the path, and the same holds for the VictoriaMetrics host and port. The template `settings.php.dist` already read the variable.

- **Min/max bytes could be bypassed by an `or` in the filter**, and a `)` in the filter could close the wrapper and drop the byte limits and the protocol. Every part of a composed filter is now parenthesised, and an unbalanced filter is rejected before nfdump runs.

- **Filter syntax errors were reported as success** in some runs, and a failed query showed an empty result. A non-zero exit of nfdump is now an error with nfdump's message.

- **Saving preferences reset the selected profile.** Saves now merge with what `preferences.json` holds.

- **The series visibility checkboxes and the Original view toggle work**, and IPv6 addresses sort by value.

- **Backfill skipped captures older than the newest stored sample**, which is exactly what it exists for. It now offers every capture file to the datasource.

- **Alert names and nfdump messages are escaped** everywhere they are shown; nfdump quotes a filter's markup back in its error text.

- **The IP info dialog closed on every live update.** It now survives them, as does the alert Test dialog.

- **A bi-directional query is now a table like any other.** nfdump prints its merged-flow output as fixed-width text whatever output format it is asked for, csv and json included, so it used to be shown as preformatted text: no IP lookups, no formatted byte counts, no CSV/JSON export, for the one query that merges both directions of a conversation. That table is now read back into rows, with nfdump's own text, and the summary it ends with, still under **Original** on Top Talkers and on the **Raw output** tab on Flows.

- **An aggregated table named its columns after nfdump's internal fields.** Aggregating forces csv output, whose field names differ from the json ones, so the same column read `SrcAddr` and `DstPort` where an unaggregated query said Source IP and Destination Port.

- **A query that worked no longer reports an nfdump warning.** nfdump prints `Command line switch -s overwrites -a` for every aggregated statistic while honouring the aggregation, and that went to the panel as a yellow warning beside a correct result. Messages nfdump always prints are filtered out of what the pages show, and still logged at debug level.

- The import's "no traffic" notice named every silent port ([#173](https://github.com/mbolli/nfsen-ng/issues/173)). A configured port list runs to dozens, so the line grew to a wall of port numbers that buried the sentence explaining what it meant. It now leads with the count and names at most nine of them.

- A graph with many series was hard to read, which the **Ports** display makes routine: one line per configured port, and a real port list runs to dozens ([#173](https://github.com/mbolli/nfsen-ng/issues/173)). Hovering a line now lifts it and fades the rest, since the palette cycles long before sixty series and colour alone cannot identify one. The tooltip and the Legend list show the largest series first, capped, with a count of the rest, instead of listing every port in source order with most of them at zero. The Series list gained a colour swatch per entry and scrolls instead of growing: with sixty ports it measured 1500px and pushed the chart off the top of the screen.

- **Stopping nfsen-ng is quick and clean.** php-via 0.12 never ran shutdown code on SIGTERM, so a stop abandoned the import, the top-N collector and every running query wherever they were, and ended in `[FATAL ERROR]: all coroutines are asleep - deadlock!`. Now the import daemon, the collector and the alert checks end, the nfdump of a tab's query or of an MCP call is stopped, and an import finishes the capture file it is on. Measured on the dev data: `docker stop` takes 0.3 s idle, 0.5 s during a Flows query (its nfdump stopped) and 0.3 s during a rescan or a top-N backfill; a file whose top-N collection the stop cut short is collected on the next start instead of counting as a failed attempt. The development container now passes the stop on to the server; it used to wait out Docker's 10 s timeout and kill it. `docker-compose.dev.yml` runs the entrypoint script from the mounted source, so this needs no rebuild of the dev image, only `docker compose up -d` to recreate the container.

- **A tab that comes back from the background no longer thinks a query is still running.** When php-via rebuilt its context, it took the browser's copy of every signal, so `query_running` could come back true with no query behind it and the Run buttons stayed disabled until a reload. Signals only the server sets (the query state, the graph figures, the data range, the timezones) now ignore the browser's copy, which php-via 0.13 enforces; a pinned time range and a built Flows graph still come back.

- **A page render or a timer that throws no longer takes the worker down**, and with it every open tab: php-via 0.13 answers 500 and logs the error instead.

- **Alert rules with a traffic filter no longer crash the server on busy captures.** They listed every matching flow into the server's memory: 89 MB of JSON and about 460 MB of PHP memory for `dst port 443` over two 300,000-flow captures, so the 128 MB worker restarted on every interval. nfdump now sums the matching traffic itself (`-s proto`), and the server reads one line per protocol. One evaluation of the same rules takes 160 ms instead of 3 s, with the same values.

- **Percent-of-average alert rules with a traffic filter compare with the filter's own traffic.** Such a rule compared its total per 5 minutes with the stored per-second average of all traffic, which made it far too easy to fire. It now averages its own totals, recorded at each check, over the window before the interval it checks. It has no baseline until it has checked one interval, nor while its filter matched nothing in the window: a burst of traffic that is normally absent (`proto icmp` on a quiet link) cannot fire it, and a firing *below* rule stays firing while the filter matches nothing. History entries such rules recorded before this version keep their threshold, a per-second figure, now labelled as a total per 5 minutes. Rules without a filter no longer count the checked interval in their average (a 10 min rule compared the interval with itself); on VictoriaMetrics they read the checked interval instead of 0, and a source that stopped reporting no longer counts in the latest interval, for alerts and the MCP `current_load` alike. The Test dialog reports each channel on its own (sent, failed and why, not configured, not sent), waits up to 10 s for the webhook's answer, and names the average window as the form does (*1 h*).

- **An import with many tabs open no longer runs the server out of memory.** Every imported file re-rendered every open tab, and the frames piled up for the tabs that could not keep up: with 6 tabs, a 17,856-file import ran the worker out of its 128 MB after about 4 minutes. Import progress and the live graph update now go out at most every 250 ms, always with the latest state. Measured on an import with six tabs open, that is 34 to 41 renders a second instead of 252 to 322, the p95 event-loop lag 11 to 18 ms instead of 26 to 31 ms, and the peak memory 28 to 32 MB instead of 32 to 45 MB.

- **Queries over several sources no longer come back empty when the first source lacks the window's first capture file** (a capture gap, or a source added later): nfdump then read nothing, so the command now names first a source that holds that file.

- **A tab opened within five minutes of a fired alert shows its toast.** When the alert's message arrived before the toast code had loaded, the tab logged `TypeError: window.showMessage is not a function` instead.

- **Kill names every nfdump process it stopped** (*3 nfdump processes (PIDs 11, 12, 13) were killed.*), since a split query or a filtered graph runs several.

- **The filtered graph's cost line counts the nfdump runs the build makes**: it said 144 where the build ran 145.

- **The Import log keeps its newest 100 lines and counts the rest** (*2 warnings, 1 error, the last 100 shown*); a pass kept every warning and copied the whole list on each update.

- **The Flows *Traffic over time* chart keeps its unit and display timezone** when the page updates.

### Security

- **A filter starting with `-` reached nfdump's option parser**, so a typed filter such as `-w /tmp/x` made nfdump write a file. Every command now passes `--` before the filter, and the statistic order is checked against the allowed values like the statistic itself.

### Internal

- `scripts/starbase-vendor.mjs` vendors Starbase components from a local clone and checks them offline, including that they expect the Datastar bundle php-via serves. `FrontendAssetsTest`, `StarbaseVendorTest` and `StarbaseBridgeTest` cover it, and `DeployFilesTest` keeps the memory limit of both images and the systemd unit in step. `PopoverMarkupTest` checks the Datastar attributes in every `<sb-popover>` of the templates against the popover exception of rule K1. See `AGENTS.md` for bumping Datastar and the Starbase pin, and for the popover contract.

- The browser tests: `run.mjs` takes file names and fails a file after `E2E_FILE_TIMEOUT` seconds (900); an app restart in the middle of a test fails its next call with `AppReloadedError` instead of a timeout; no Chromium and no profile directory outlives the run. `rocket` and `starbase-bridge` are new files. Pest loads `tests/Helpers.php` before every file.

---

## [1.0.0-beta.5] - 2026-09-16

### Added

- **Graphs can be plotted through an nfdump filter** ([#166](https://github.com/mbolli/nfsen-ng/issues/166), requested by [@gmt4](https://github.com/gmt4)). The datasources store per-interval totals, so a filter cannot be applied to them after the fact; the new **Filtered** source-data mode goes back to the capture files and runs one nfdump per interval. It is expensive, so it never runs on its own: only an explicit **Apply filter**, with the cost stated up front and the result cached.

- **Traffic over time on the Flows tab.** Filter as usual, then expand the section above the results to plot that same filter. Same builder, same explicit build. The row limit and aggregation options do not apply to it, since they truncate and regroup the table rather than change which records match.

- **Real progress on long queries.** **Process data** and **Apply filter** report how far along they are and can be killed, instead of spinning indeterminately. The filtered build knows its interval count exactly; single-shot nfdump queries report bytes read against bytes to read, marked as an estimate. Linux only, elsewhere the previous spinner remains.

- **An optional [MCP](https://modelcontextprotocol.io) server**, off by default, giving an AI agent read-only access to this installation's data so investigating traffic does not mean writing nfdump filters by hand. Ten tools in two cost tiers: the cheap ones answer from stored aggregates immediately, the rest read capture files. Runs over stdio (`php backend/mcp.php`), or set `NFSEN_MCP_HTTP=true` to serve it at `/_mcp` on nfsen-ng's own port. Nothing in it writes, so access to it is equivalent to access to the dashboard. See [the MCP chapter](https://mbolli.github.io/nfsen-ng/features/mcp.html).

- **Backfill** on the Import panel reads capture files older than the newest sample already stored ([#171](https://github.com/mbolli/nfsen-ng/issues/171), reported by [@alexgill](https://github.com/alexgill)). A normal import resumes at that sample and never looks behind it, so an archive collected before the install was skipped. It deletes nothing and appears only on VictoriaMetrics; RRD keeps the destructive **Force rescan**, because RRDTool refuses writes behind a file's last update. Note that VictoriaMetrics drops samples past `--retentionPeriod` silently, so set that to cover your oldest capture first.

- `NFSEN_IPINFO_URL` / `NFSEN_IPINFO_TOKEN` make the geolocation service configurable ([#163](https://github.com/mbolli/nfsen-ng/issues/163), reported with a patch by [@gmt4](https://github.com/gmt4)); the endpoint was hardcoded to ipapi.co.

- The IP-info modal's country flag is an emoji rendered server-side instead of an image from `flagcdn.com` ([#165](https://github.com/mbolli/nfsen-ng/issues/165), suggested by [@gmt4](https://github.com/gmt4)). That was the frontend's last third-party request.

- nfsen-ng ships a favicon, so its tab is identifiable at a glance ([#170](https://github.com/mbolli/nfsen-ng/issues/170), suggested by [@gmt4](https://github.com/gmt4)).

### Fixed

- **Port graphs stayed empty on a source rename** while the per-source graphs kept working ([#173](https://github.com/mbolli/nfsen-ng/issues/173), reported by [@Ondo-Dulguun](https://github.com/Ondo-Dulguun)). Only a bulk import ever wrote the per-port databases, because the ongoing importer was built without port processing enabled.

- **One flow of an unusual protocol could cost a capture file all of its port data.** Per-port statistics wrote the raw protocol name as a field, so a GRE or ESP flow made RRDTool reject the whole write with `unknown DS name 'flows_gre'`. Everything but TCP, UDP and ICMP now lands in `other`, as the per-source series always did.

- **A configured port with no traffic never got a database**, and selecting it took the whole graph down with `rrd_xport failed … No such file or directory` ([#172](https://github.com/mbolli/nfsen-ng/issues/172), reported with a root-cause analysis by [@Ondo-Dulguun](https://github.com/Ondo-Dulguun)). Incremental imports now create the missing port databases up front, and the graph treats a database that does not exist yet as an empty series.

- `NFSEN_PORT_DIRECTION` picks which side of a flow a per-port graph counts ([#173](https://github.com/mbolli/nfsen-ng/issues/173)). An exporter that reports one direction of each flow left these graphs empty, because every flow named the port as its *source* and the importer asked for `dst port N`. Set it to `any` if that is your case. The default stays `dst`, since the numbers the other settings produce are not comparable with what is already on disk.

- **Kill is no longer ambiguous with more than one query running**, and `NFSEN_NFDUMP_MAX_PROCESSES` is enforced against the processes nfsen-ng started rather than every nfdump on the machine. The old check counted runs this app never started, raced between counting and spawning, and enforced nothing at all when neither `ps` nor `pgrep` was installed. A caller with no free slot now waits briefly instead of erroring, and the default is `2`.

- **The page no longer flashes light before switching to dark** ([#167](https://github.com/mbolli/nfsen-ng/issues/167), reported by [@not7cd](https://github.com/not7cd)). The theme was applied by Datastar, a module at the bottom of `<body>`, so the browser painted a complete light frame first; it is now resolved by a blocking script at the top of `<head>`.

- **`NFSEN_DEFAULT_THEME=auto` follows the operating system again.** The resolved value was persisted on every change *including the initial seed*, so the first visit's preference was restored forever after. What is stored now is the explicit choice you make with the toggle. The old key cannot be told apart from an auto-resolved value, so it is cleared once: a hand-toggled preference resets to the deployment default one time.

- **The IP-info modal showed a bare heading over an empty table for every private address** ([#168](https://github.com/mbolli/nfsen-ng/issues/168), reported with a patch by [@gmt4](https://github.com/gmt4)), and a geolocation lookup that could not reach the service at all reported nothing rather than the failure. The Netbox table also picks up the geo table's styling.

- **The worker segfaulted on every request that made a curl call** once libcurl reached 8.20.0, which meant every page render on a VictoriaMetrics install and every alert webhook anywhere. openswoole 26.2's native-curl hook cannot handle libcurl's changed threaded resolver ([curl#21558](https://github.com/curl/curl/issues/21558)), so that hook is now switched off exactly when the running libcurl is 8.20.0 or newer.

- **The published compose file could not start the container**: it pointed the entrypoint at a source-tree path absent from the image, so `docker compose up` exited 127 ([#162](https://github.com/mbolli/nfsen-ng/issues/162), reported by [@alexgill](https://github.com/alexgill)).

- **Latent crashes found by raising PHPStan from level 5 to 8.** A VictoriaMetrics call that came back without a body returned `false` from a `string`-typed method; a datasource answering with an error string hit `count()` on it; the byte formatters indexed past the start of their unit table for sub-unit values; and client-writable signals are now normalised at the boundary instead of being indexed on trust.

- **Controls that never showed which option was active**: Linear/Logarithmic, Stacked/Line, Step plot/Curve plot and the filtered-mode toggle set the `checked` attribute, which only seeds `defaultChecked`, while Bootstrap lights the label off the property. The graph had defaulted to a step plot all along without saying so. The **Protocols** buttons had the same problem in filtered mode, where they now filter the series rather than sitting inert.

### Internal

- Query building moved out of the action closures into a `backend/query/` layer that the UI and the MCP tools both call, which is what made the MCP server possible without duplicating every nfdump invocation. See [Project Structure](https://mbolli.github.io/nfsen-ng/development/structure.html).

---

## [1.0.0-beta.4] - 2026-08-12

### Fixed

- Hovering the right-hand end of a graph threw `TypeError: Cannot read properties of null (reading 'toFixed')` and left the tooltip broken ([#158](https://github.com/mbolli/nfsen-ng/issues/158)). Empty buckets are deliberately `null` ([#154](https://github.com/mbolli/nfsen-ng/issues/154)), and the value formatters didn't handle that. They now render a dash instead, and all three call sites (y-axis, tooltip, legend) share one formatter selector.
- The Sankey tab failed outright on nfdump 1.7.5 with `Can not use print format fmt:… to aggregate flows` ([#159](https://github.com/mbolli/nfsen-ng/issues/159)). That one release rejects a custom `-o fmt:` format while `-A` aggregation is active, so the Sankey reads nfdump's aggregated CSV there instead and keeps `fmt:` on every other version.
- Changing the source selection on the Graphs tab did nothing on installations with more than one source: the graph kept showing every source, the clicked entry didn't stay highlighted, and a `rrd_xport failed. opening '<rrd>/<profile>/.rrd'` banner could appear ([#160](https://github.com/mbolli/nfsen-ng/issues/160)). Datastar binds a `<select>` by reading `el.multiple` once, and the Sources select only became `multiple` afterwards via `data-attr`, so the selection was posted back as `null`. It is now bound explicitly, and the selection is seeded per display mode and sanitized server-side.
- The Graphs tab's **Ports** display broke on a filter change, leaving the correct caption but no graph, and stayed dead until a page reload ([#160](https://github.com/mbolli/nfsen-ng/issues/160)). `graph_ports`/`graph_sources` had no value in the rendered page, so Datastar invented them from the DOM: port option values as *strings*, which then reached `Rrd::get_data_path(): Argument #2 ($port) must be of type int, string given`, or came out of `getTitle()`'s `JSON.parse()` as a bare number and threw `displayItems.join is not a function`. Only the Ports view was affected, because only port values parse as JSON scalars. Recovery was impossible because the error message replaced the chart container while the ECharts instance stayed alive inside it, so every later update redrew into an orphan. Both signals are now seeded and normalized server-side, title parsing always yields an array, and replacing the container disposes the chart so the next update rebuilds it.
- A failure while building the graph data left the graph silently frozen on its previous contents, with no error anywhere in the UI ([#160](https://github.com/mbolli/nfsen-ng/issues/160)). Only the datasource call was guarded; anything else that threw was swallowed by php-via's action handler. The whole fetch is now guarded and reports through the usual error banner, and non-finite RRD measures are treated as empty buckets like `NaN` already was.
- `Step plot`, `Logarithmic`, `Stacked` and the date-range auto-sync toggle lost their defaults permanently, so graphs quietly rendered as curves. All four are listed in `data-persist` on `<html>` but were seeded further down the page, so `data-persist` read them before they existed and stored the resulting `''` over the seed on every later load. They are now seeded on `<html>` ahead of `data-persist`, which also refuses to store or restore `''` so already-affected browsers heal themselves.
- The **Columns** button on the Flows and Statistics tables did nothing when clicked ([#161](https://github.com/mbolli/nfsen-ng/issues/161)). The dropdown was still declared the Bootstrap way (`data-bs-toggle="dropdown"`), but Bootstrap's JS and Popper were removed from the bundle in the v1 frontend rewrite, and this was the last widget depending on them: nothing was left to put the `.show` class on the menu, which Bootstrap's CSS keeps at `display: none` without it. Open/close now runs on a browser-local Datastar signal (`data-on:click` to toggle, `data-class:show` on the menu, `data-on:click__outside` and `Escape` to close), one signal per table so the two tables can't open each other's menu. The menu is also right-aligned and statically marked `data-bs-popper`, since Popper is no longer there to position it or keep it inside the viewport. Covered by a new `tests/e2e/columns.test.mjs`.

---

## [1.0.0-beta.3] - 2026-07-30

### Added

- Environment-variable registry with Health-page validation. Every environment variable is now defined once in a single source of truth (`EnvRegistry` / `EnvVar`), one typed parse, validator, and default apiece, that all consumers (`Settings`, the `app.php` bootstrap, `Config`, the import daemon) resolve through, replacing scattered `getenv() ?: default` reads and the per-code-path default drift they had accumulated. A new **Configuration** group on Settings → Health surfaces misconfiguration that previously failed silently: values set but invalid (so a default quietly applied), deprecated variable names still in use, unknown `NFSEN_`-prefixed variables (typos that do nothing), malformed NetBox URL / alert-email addresses, an active (deprecated) `settings.php`, and a saved preference overriding `NFSEN_LOG_LEVEL`.
- Consolidated, upgrade-safe app data via `NFSEN_STATE_DIR`. nfsen-ng's own mutable data, the RRD database plus `preferences.json` and the alert rules/state/log, now lives under one directory (`/var/lib/nfsen-ng`, split into `rrd/` and `state/`), mounted as a single `nfsen-data` volume and declared `VOLUME` in the image so even a bare `docker run` persists it. This closes a silent data-loss-on-upgrade hole: the standard compose persisted neither the RRD database nor the saved preferences/alerts, so recreating the container (every image update) wiped them. The nfcapd capture tree stays on its own separate volume, the collector owns it and it grows with traffic, so a Docker deployment now uses two clearly-separated volumes.
- `NFSEN_DEFAULT_THEME` (`auto`|`dark`|`light`) sets a deployment-level default for the dark-mode toggle, seeding a fresh browser that has no saved choice yet (e.g. after a cache wipe) instead of always falling back to the OS `prefers-color-scheme`. An explicit user toggle still persists client-side and wins over it. Also settable as `frontend.defaults.theme`.

### Changed

- `settings.php` is deprecated in favour of environment variables. When present it now acts as an overlay on the env baseline: a key it defines wins, a key it omits falls back to the matching `NFSEN_*` variable, then the built-in default, instead of the previous all-or-nothing behaviour where a settings file silently caused `NFSEN_SOURCES`/`PORTS`/`FILTERS`/`PROCESSOR` to be ignored. An active file is flagged on the Health page, and the deployment docs were reworked around the env-first model.
- `VM_HOST` / `VM_PORT` renamed to `NFSEN_VM_HOST` / `NFSEN_VM_PORT` for prefix consistency with every other variable. The old names keep working as deprecated aliases (with a Health-page nudge to rename); compose files, the VictoriaMetrics seed script, and docs updated.
- Docker volumes consolidated: the separate `rrd-data` volume folded into the single `nfsen-data` volume alongside app state (see Added). Deployments upgrading from an earlier beta's `rrd-data` volume need a one-time copy of their RRD files into `nfsen-data/rrd` (or a Force Rescan), documented in the upgrade guide. The Unraid UI template gained a matching **App data** path (`/var/lib/nfsen-ng`, defaulting to `/mnt/user/appdata/nfsen-ng-data`), which existing Unraid installs must add when they update the container.
- `preferences.json` is no longer tracked in git: it is runtime user state, like the already-ignored `settings.php` / `alerts-*.json`, and the committed dev copy (DEBUG log level, test alert rules) had been leaking into from-source installs. It is recreated on first save.
- `NFSEN_DATASOURCE` / `NFSEN_PROCESSOR` accepted-value lists now derive from the internal datasource/processor class maps rather than a duplicated hard-coded list, so the two can't drift.
- Documentation moved from the GitHub wiki into the in-repo mdBook (`book/`), so the operational guides version and ship with the code.
- Dev/packaging housekeeping: added a repo-root `ca_profile.xml` (the location Unraid's Community Applications expects) for the CA submission, renamed `phpunit.xml` to `phpunit.xml.dist`, tidied stale env-var comments in the compose files, and ran php-cs-fixer across the backend and test suite.

### Fixed

- nfdump binary default drift: the settings-file path defaulted `nfdump.binary` to `/usr/bin/nfdump` while the env path and the Docker images used `/usr/local/nfdump/bin/nfdump`. Both now use the latter, so a `settings.php` that omits the key resolves the same binary as an env-only deployment.
- Reloaded graphs could render light on a dark page ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). On a full reload the initial SSE sync morphs `<html>`; the server markup carries only the `data-attr:data-bs-theme` directive (never a resolved value, since `_darkMode` is client-local), so idiomorph stripped the resolved `data-bs-theme` on every morph and Datastar re-added it a tick later, and an async chart rebuild (`notMerge`) landing in that null window read "no theme" as light and won the race against the dark correction. The attribute is now preserved across morphs.
- Flow/statistics/Sankey results were blanked when a backgrounded tab's context was revived ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). php-via 0.12.0 context revival re-runs the page handler on SSE reconnect, re-initializing the plain-PHP result containers to empty without re-running the action that filled them, so a returning tab kept its filters and active tab but showed an empty results panel. Each context's rendered results are now snapshotted in app-global state keyed by context id and restored on revival; a genuine reload gets a fresh id and starts clean.
- `NFSEN_DEFAULT_THEME` never actually seeded a fresh or cache-wiped browser: a deployment set to `dark` still loaded light ([#156](https://github.com/mbolli/nfsen-ng/issues/156)). The seed ran in a `data-init` on `<html>`, but Datastar applies that element's attributes in document order, so `data-persist` ran first and its restore effect immediately wrote `nfsen-persist:_darkMode` to `localStorage`; the seed's `=== null` freshness check then always saw a non-null value and skipped. (It only appeared to work for `auto` on a light-`prefers-color-scheme` OS, where the wrong fallback happened to match.) The seed now lives in the `data-signals__ifmissing` initializer on `<html>`, set before `data-persist` on the same element reads it, so a fresh browser takes the deployment default, while a saved toggle (restored by `data-persist`, whose restore runs before its own write-effect) still wins. For explicit `dark`/`light` installs the default is additionally rendered as the initial `data-bs-theme` on `<html>`, so the first frame already matches and the page no longer flashes light before Datastar boots; `auto` still resolves client-side (only the browser knows its OS preference), and `data-preserve-attr` keeps a saved toggle winning across SSE morphs.
- Mixed IPv4/IPv6 flow results only showed the addresses of one family, leaving the rest of the rows with blank Src/Dst cells ([#157](https://github.com/mbolli/nfsen-ng/issues/157)). nfdump's `-o json` names address fields after each record's address family (`src4_addr`/`dst4_addr` for IPv4, `src6_addr`/`dst6_addr` for IPv6, likewise `ip4_router`, `bgp6_next_hop`, the tunnel/XLATE addresses), so a result set spanning both families carries two different record schemas, while `Table::generate()` derived its columns from the first row alone and dropped every field that row happened not to have. Those keys are now normalized to family-agnostic names (`src_addr`, `dst_addr`, and so on) so both families share one Source/Destination IP column, and the table's columns are the union of all rows' keys, which also restores the per-protocol fields that went missing the same way (a first-row TCP record hid the `icmp_type`/`icmp_code` of ICMP rows and vice versa). IPv6 addresses additionally sort correctly in the shared column instead of falling back to string order.

---

## [1.0.0-beta.2] - 2026-07-08

### Added

- Sankey diagram: optional **Ports** toggle that inserts the destination L4 port as a middle column, turning the src→dst view into src IP → dst port → dst IP ([#152](https://github.com/mbolli/nfsen-ng/issues/152)). When on, the nfdump aggregation key gains `dstport` (`-A srcip,dstport,dstip`) and the payload becomes three columns; the shared middle `port:` nodes pool all traffic per port, with src→port and port→dst ribbons summed so a busy port renders as one node rather than a stack of duplicates.
- Unraid packaging under `deploy/unraid/`: an all-in-one `docker-compose.unraid.yml` (the web UI plus an `nfcapd` collector sharing one appdata volume, both roles run the single published image) for the Compose Manager plugin, and two Community Applications templates (`nfsen-ng.xml` for the UI, `nfsen-ng-nfcapd.xml` for the collector) with a Sankey-derived icon. The README covers the shared-volume/source-name rule, the flow-exporter prerequisite, and CA submission. Nothing new to build: nfdump, nfcapd and the app already ship in the one image.

### Changed

- Sankey diagram: destination-port labels now sit as a small centered chip on each port node instead of floating above it ([#152](https://github.com/mbolli/nfsen-ng/issues/152)). A top-anchored label detached from the bulk of a thick flow, making the port hard to associate with its ribbons; the label is now vertically centered on the node bar (position `inside`) and backed by a rounded chip so a short port number stays legible where it crosses ribbons on both sides. Only the middle `port:` column changed: src/dst labels are unchanged.
- Docker `:latest` now tracks the newest release regardless of pre-release status: while pre-1.0 that means it follows the current beta/RC instead of only being published on a final stable tag ([`docker-publish.yml`](.github/workflows/docker-publish.yml)). The reduced `:1` / `:1.0` semver tags stay reserved for stable releases (`docker/metadata-action` suppresses them on pre-releases), and `:edge` still tracks `master`. Digest-based update checks (Unraid's Docker page, Watchtower, etc.) now flag each new release for anyone tracking `:latest`.
- Bundled Caddy no longer serves `/frontend/*` static assets directly: everything is proxied to php-via, which already serves and Brotli-compresses static files itself. Retired the custom `caddy-cbrotli` build (`deploy/Dockerfile.caddy`, `ghcr.io/mbolli/nfsen-ng-caddy`); the bundled-Caddy profile now uses the stock `caddy:latest` image. Bare-metal Caddy configs copied from the old template continue to work unchanged; the `handle /frontend/*` block can be dropped if desired, but isn't required.
- Alert webhook payload now also includes `title`/`message`/`body` fields, so the webhook URL can point directly at a Gotify (`.../message?token=...`) or Apprise API (`.../notify/...`) endpoint without an intermediary ([#153](https://github.com/mbolli/nfsen-ng/issues/153))
- Bumped `mbolli/php-via` to `~0.12.0` and switched to its new `Config::withStaticCacheControl()`: versioned static assets (`?v=` cache-busted CSS/JS, `datastar.js`, `via.css`) now get `public, max-age=31536000, immutable`, unversioned vendor libs (`bootstrap.min.css`, `nouislider.min.js`, `echarts.min.js`) get `public, max-age=604800, must-revalidate`, and everything still gets `no-cache` in dev mode. All static responses now emit `ETag`/`Last-Modified` and honor conditional GET, courtesy of the php-via upgrade.
- `nouislider.min.js` / `echarts.min.js` are now loaded with `defer` instead of blocking the parser, found via a Lighthouse pass while tuning cache headers; nothing consumes them synchronously (only later `type="module"` components do, and those already tolerate late availability).

### Fixed

- The Flows tab ignored the source selector: picking a single source still queried every configured source ([#155](https://github.com/mbolli/nfsen-ng/issues/155)). The Flows source `<select>` binds to the `graph_sources` signal, but `FlowActions` passed `Config::$settings->sources` (all sources) to nfdump's `-M` unconditionally instead of the selection. It now resolves the selected sources the same way the Statistics tab already did, honouring the "any"/empty = all-sources fallback, via a shared `Helpers::resolveSources()` (also adopted by the Statistics and count-files actions to remove the duplicated logic).
- The traffic graph's most recent data point always rendered as 0, one slot behind the real latest value ([#154](https://github.com/mbolli/nfsen-ng/issues/154)). RRDtool always returns one trailing empty (NaN) row past the last written slot; `Rrd::get_graph_data()` set that NaN to `null` and then, for the bits/traffic graph, unconditionally ran the `bytes → bits` conversion `$measure *= 8` over it, and PHP evaluates `null * 8` as `0`, so the empty gap became a real zero and the line dropped to the baseline at the right edge. Only the bits traffic graph was affected (flows/packets never multiply, so their gaps stayed `null`). The `*= 8` conversion now runs only on valid measures.
- Alert rules with an nfdump traffic filter always evaluated to zero, so they could never fire: `AlertManager::fetchFilteredSlot()` set nfdump's `-R` (time range) option before `-M` (sources), but `-R` resolves file paths immediately using the sources `-M` records, so it always found zero nfcapd files ([#153](https://github.com/mbolli/nfsen-ng/issues/153))
- The Flows/Statistics/Sankey NFDUMP filter (and other free-text fields) could be silently cleared by an unrelated SSE re-render while the user was still typing ([#151](https://github.com/mbolli/nfsen-ng/issues/151)): the vendored Datastar bundle predated the `v1.0.2` release's fix for exactly this case (a morph compared a `<textarea>`'s live value against the incoming server render instead of against its own `defaultValue`, so any not-yet-submitted edit lost to the next full-page patch). Upgraded the pin from an untagged commit to `v1.0.2` and added an import map (`frontend/js/components/datastar-persist.js` resolves `datastar` via a bare specifier) so a second, independent copy of the engine can't get loaded by a differently-cache-busted relative import.
- A forced page reload (e.g. when the SSE context's cleanup grace period elapses while a tab is backgrounded) always reset the active tab to Graphs and dark mode to the system default ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). Added a `data-persist` Datastar attribute plugin that round-trips the current tab, settings sub-section, dark-mode choice, and graph display preferences (log scale, stacked/line, step/curve plot, follow-zoom) through `localStorage`, so a forced reload restores them instead of resetting to defaults.
- The tab/theme persistence fix above still didn't reach already-visited browsers, because the `?v=` cache-busting query string on `frontend/js`/`frontend/css` URLs used `Config::VERSION`, which is only hand-bumped on `release:` commits: a mid-release JS/CSS fix reuses the same URL as before, so it stays invisible to any browser that had already cached the old content under `public, max-age=31536000, immutable` ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). Added `Config::assetVersion()`, derived from the newest mtime under `frontend/js` and `frontend/css`, and switched all `?v=` asset URLs in `layout.html.twig` to it, any change to those files now busts the cache automatically, without relying on remembering to bump `VERSION`.
- The `data-persist` plugin from the fix above never actually loaded in any browser, cache notwithstanding ([#151](https://github.com/mbolli/nfsen-ng/issues/151)): the `<script type="importmap">` mapping the bare `datastar` specifier came after two earlier `<script type="module">` blocks (`alert-template-preview.js`, plus two inline ones). Per the import maps spec, an import map is only honoured if no module script, inline or external, has been "prepared" yet, which happens the moment the parser reaches its tag, not when it runs. Any earlier module script silently voids the import map, so `datastar-persist.js`'s `import ... from 'datastar'` failed to resolve and the module (and everything it was supposed to persist) never loaded, consistently, independent of cache state or browser, which is why clearing the cache didn't help. Moved the import map to be the first `type="module"`-adjacent tag in the document.
- A backgrounded tab whose SSE context was torn down after the cleanup grace period no longer hard-reloads (and loses live state) when it returns ([#151](https://github.com/mbolli/nfsen-ng/issues/151)). php-via `0.12.0` adds *context revival*: on an SSE reconnect to a destroyed context it rebuilds an equivalent context server-side, same ID, so the already-loaded DOM's signal/action references keep resolving; page handler re-run; client-held signal values re-seeded from the reconnect, instead of pushing `window.location.reload()`. That preserves the browser's entire live state (active tab, dark mode, scroll, focus, and in-progress unsubmitted filter text), with no reload flash, for any return within the 10-minute revival window (on by default). The `data-persist` localStorage restore above stays as the fallback for a genuine user-initiated reload, which always wipes the client store and never triggers revival.

### Security

- `withStaticDir()` was pointed at the project root instead of `frontend/`, and php-via's static-file handler has no extension allowlist: it served any file under that root over plain HTTP, including `composer.json`, every `backend/**/*.php` source file, `backend/settings/settings.php`, and the entire `.git/` directory (i.e. the full commit history, dumpable with any standard git-dumper tool). Scoped `withStaticDir()` to `frontend/` only and dropped the now-redundant `frontend/` prefix from asset URLs in `layout.html.twig` to match.

---

## [1.0.0-beta.1] - 2026-07-07

### Added

- Sankey diagram tab visualizing src→dst traffic flow ([#152](https://github.com/mbolli/nfsen-ng/issues/152))
- `DEMO_MODE` with a synthetic live-data generator (`DemoDaemon`) for running the UI without a real capture pipeline
- `displayTimezone` user preference (browser vs. capture timezone), applied across chart/date-range formatting and alert emails
- Custom duration input (numeric + h/d/w unit) alongside the date-range preset buttons ([#148](https://github.com/mbolli/nfsen-ng/issues/148))
- Optional nfdump traffic filter on alert rules
- Health check: surface missing `ps`/`pgrep` as a warning
- Animated tab switching via the View Transitions API
- mdBook documentation site, published to GitHub Pages on every push to `book/`
- End-to-end browser test suite (`tests/e2e/`): drives a real headless Chrome over raw CDP (no Playwright/Puppeteer dependency) against every tab: smoke, graphs, flows, statistics, sankey, settings, alerts, plus a `npm run test-e2e` runner

### Changed

- Graphs tab migrated from Dygraphs to Apache ECharts
- Frontend linting/formatting migrated from ESLint+Prettier to Biome
- `php-via` upgraded to `~0.10.0`, resolving 15 Dependabot alerts
- Settings and Admin tabs merged into a single Settings tab with vertical sub-navigation
- Dark mode migrated from a `filter:invert` hack to Bootstrap 5.3's `data-bs-theme`
- Settings/alert/graph-reconnect toast notifications consolidated to a single bottom-right container

### Fixed

- Table column visibility and sort state now persist across SSE re-renders, not just full page reloads ([#151](https://github.com/mbolli/nfsen-ng/issues/151))
- Import daemon catches up nfcapd files written before its inotify watch was registered, e.g. the first slot(s) after a midnight day-directory rollover ([#146](https://github.com/mbolli/nfsen-ng/issues/146))
- Graph `_error` signal now clears on a subsequent successful load instead of staying stuck
- nfcapd filenames are parsed in `NFCAPD_TZ` instead of always UTC
- Alert emails use `gmdate()`; added timezone-related health checks
- Alerts Delete/Enable/Test-fire buttons were a silent no-op: `@post(url, {id: '...'})` passes its second argument as Datastar request *options*, not a body payload, so `id` never reached `$c->input('id')`: switched to a query-string id, matching the working pattern used elsewhere in the app
- ECharts race condition: rapid datatype switching could call `setOption` on an already-disposed chart instance and crash
- `procps` installed in the test image; stale `Rrd`/`Settings`/`VictoriaMetrics` test fixtures repaired
- Graphs zoom-slider fill color was an off-palette gray, reading too dark against the light theme

---

## [1.0.0-RC.1] - 2026-05-04

Full architectural rewrite milestone. Server-rendered hypermedia replaces the jQuery/JSON-API stack. The new stack runs as a long-lived OpenSwoole coroutine server, pushing UI updates over SSE without page reloads.

### Added

- **Hypermedia architecture**: [Datastar](https://data-star.dev) SSE, server-rendered Twig templates, no JSON API
- **OpenSwoole coroutine server** via [php-via](https://github.com/mbolli/php-via) replacing Apache/PHP-FPM
- **Caddy reverse proxy**: TLS, HTTP/3, Brotli/zstd/gzip (`deploy/Dockerfile.caddy`, `deploy/Caddyfile`)
- **Real-time graph updates**: inotify watching nfcapd files triggers `rrd:live` broadcast
- dygraphs with zoom + noUiSlider date range slider + quick time-range presets (1 h, 6 h, 1 d, 1 w, 1 m, 1 y)
- Multi-profile UI: profile selector, per-profile datasource storage, auto-detect nfdump profiles
- Alert rules: `AlertRule`/`AlertState` models, `AlertManager` service, UI panel with recent alert history
- Alert notifications: toast overlay, email and webhook delivery, SSE-pushed via `execScript`
- User preferences UI: Settings tab with live save to `preferences.json`
- Filter manager component showing global filters from deployment config
- Netbox IP lookup for private addresses, configurable via `NFSEN_NETBOX_URL`/`NFSEN_NETBOX_TOKEN` ([#112](https://github.com/mbolli/nfsen-ng/issues/112))
- Kill running nfdump process from the UI ([#7](https://github.com/mbolli/nfsen-ng/issues/7))
- Byte threshold filters (lower/upper) for Flows and Statistics tabs ([#8](https://github.com/mbolli/nfsen-ng/issues/8))
- Cap maximum query time window for Flows and Statistics (`NFSEN_MAX_STATS_WINDOW`) ([#6](https://github.com/mbolli/nfsen-ng/issues/6))
- NSEL/NAT support for statistics and table formatting ([#87](https://github.com/mbolli/nfsen-ng/issues/87))
- Graph zoom auto-sync with noUiSlider date range ([#3](https://github.com/mbolli/nfsen-ng/issues/3))
- Date-range slider bound to actual RRD data range
- Service name shown alongside port numbers in flow/stats tables
- Skip auto-import on fresh install; gap-fill on daemon restart
- Admin: scan ports checkbox for import and rescan operations
- VictoriaMetrics datasource as an alternative to RRD (`deploy/docker-compose.victoriametrics.yml`)
- VictoriaMetrics UI link displayed in health check panel
- Health check panel (Admin tab): nfdump binary/version, nfcapd paths, RRD/VM storage, daemon status
- `ImportDaemon` embedded in `app.php`; runs as an inotify-based coroutine loop in `onStart`
- Configurable RRD retention depth (`NFSEN_IMPORT_YEARS`, default 3)
- Seed scripts for RRD and VictoriaMetrics with synthetic port data (`scripts/seed_rrd_data.php`, `scripts/seed_vm_data.php`)
- Serve frontend static assets directly from php-via (no Caddy required for standalone use)
- Docker images published to GHCR: `ghcr.io/mbolli/nfsen-ng` and `ghcr.io/mbolli/nfsen-ng-caddy`
- PHPUnit/Pest test infrastructure with unit tests for `Rrd`, `VictoriaMetrics`, `AlertManager`, `Table`, `Nfdump`, and more

### Changed

- PHP minimum version raised to 8.4; PHP 8.4 asymmetric visibility applied throughout
- `Config::$cfg` array replaced by typed `Settings` class
- `nfcapd.service`: use `-z=lz4` flag (nfdump 1.7.x+)
- Bump nfdump build version 1.7.6 → 1.7.8; minimum version health check ≥ 1.7.2
- Upgrade php-via to `~0.8.0` (auto-inject, `getSignal`/`getAction` refactor)
- Split `app.php` actions into PSR-4 static action classes under `backend/actions/`
- Refactor `deploy/Dockerfile`: `COPY` source from build context instead of cloning git at build time
- Consolidate all Docker files into `deploy/`; `systemd/` → `deploy/systemd/`
- Bootstrap 3.3.7 → 5.3.2; dark-mode support; FooTable/jQuery/ion.RangeSlider removed
- PHPStan level raised to 5; all warnings resolved

### Fixed

- VictoriaMetrics `last_update()` and `date_boundaries()` stale-data lookup
- Cancel import: forward `shouldCancel` flag through all import paths
- `exec`-prefix `proc_open` so `SIGTERM` kills nfdump directly
- Profile propagation through the entire import/nfdump call stack
- Merge `settings.php` filters with `preferences.json` filters on startup ([#4](https://github.com/mbolli/nfsen-ng/issues/4))
- Replace 5-minute-slot loop with day-directory scan in `convert_date_to_path` (fixes silent failures on large date ranges)
- Config system correctness; remove ghost environment variables
- Graph filters layout and filter manager heading alignment

### Removed

- Apache/PHP-FPM stack; `.htaccess`; `backend/index.php`; `backend/listen.php`; `backend/cli.php`
- Old jQuery-based `frontend/index.html` and `frontend/js/nfsen-ng.js`
- Unimplemented `Akumuli` datasource
- Legacy `backend/server.php`, `routes/`, `scripts/start.sh`

---

## [0.4.0] - 2025-09-29

### Added

- Docker setup with `Dockerfile` and `docker-compose.yml` ([@MrAriaNet](https://github.com/MrAriaNet), [#137](https://github.com/mbolli/nfsen-ng/pull/137))
- Dark mode support ([@gmt4](https://github.com/gmt4), [#117](https://github.com/mbolli/nfsen-ng/pull/117))
- Persistent config/localStorage filters ([@gmt4](https://github.com/gmt4), [#119](https://github.com/mbolli/nfsen-ng/pull/119))
- Link formatter for src/dst IPs in Statistics view ([@gmt4](https://github.com/gmt4), [#120](https://github.com/mbolli/nfsen-ng/pull/120))
- Custom Output Format option ([@kw4tts](https://github.com/kw4tts), [#123](https://github.com/mbolli/nfsen-ng/pull/123))
- README overhaul ([@FontouraAbreu](https://github.com/FontouraAbreu), [#106](https://github.com/mbolli/nfsen-ng/pull/106))
- Updated install instructions for Debian/Ubuntu 24.04 ([@Dona21](https://github.com/Dona21))
- Updated dev dependencies: PHPStan 1.9 → 2.1, Rector 0.x → 2.x

### Fixed

- Reload loop when `frontend.defaults.view` not set ([#102](https://github.com/mbolli/nfsen-ng/issues/102))
- nfdump `dst_port=80` output format ([#115](https://github.com/mbolli/nfsen-ng/issues/115))
- `host` command returning multiple lines ([#132](https://github.com/mbolli/nfsen-ng/issues/132))
- Various code style fixes

---

## [0.3.1] - 2024-03-13

### Fixed

- Timezone offset in `Api.php` was in seconds, not hours ([@BobRyan530](https://github.com/BobRyan530), [#105](https://github.com/mbolli/nfsen-ng/issues/105))
- Submit button loading indefinitely when daemon was running ([#104](https://github.com/mbolli/nfsen-ng/issues/104))

---

## [0.3] - 2024-02-28

### Added

- PHP 8.1 minimum (8.3 recommended); PSR-4 autoloader
- Reverse DNS and WHOIS lookup on IP address click ([#84](https://github.com/mbolli/nfsen-ng/issues/84))
- Bits/bytes toggle in traffic mode ([#21](https://github.com/mbolli/nfsen-ng/issues/21))
- Human-readable byte numbers with KMG/KMB units
- Execution time display and thousand-separator formatting
- Client timezone support ([#68](https://github.com/mbolli/nfsen-ng/issues/68))
- 1-hour quick time button ([@jp-asdf](https://github.com/jp-asdf))
- nfdump column name display ([@jp-asdf](https://github.com/jp-asdf))
- PHP CS Fixer, PHPStan, Psalm static-analysis tooling

### Changed

- Bootstrap 3.3.7 → 5.3.2; FooTable updated for Bootstrap 5
- dygraphs 2.1.0 → 2.2.1

### Fixed

- `preg_match` null warning ([#88](https://github.com/mbolli/nfsen-ng/issues/88))
- Class not found error ([#96](https://github.com/mbolli/nfsen-ng/issues/96))
- Source/destination port not visible ([#48](https://github.com/mbolli/nfsen-ng/issues/48))
- Custom output format ([#49](https://github.com/mbolli/nfsen-ng/issues/49))
- Load defaults from config ([#54](https://github.com/mbolli/nfsen-ng/issues/54))
- Subnet field defaults ([#31](https://github.com/mbolli/nfsen-ng/issues/31), [#55](https://github.com/mbolli/nfsen-ng/issues/55))
- Hide aggregation on statistics view ([#56](https://github.com/mbolli/nfsen-ng/issues/56))
- Zoom to data point on click ([#53](https://github.com/mbolli/nfsen-ng/issues/53))
- `TERM` not set in systemd service ([@dominiquefournier](https://github.com/dominiquefournier), [#71](https://github.com/mbolli/nfsen-ng/issues/71))
- Font size overlap ([@dehnli](https://github.com/dehnli), [#97](https://github.com/mbolli/nfsen-ng/issues/97))
- Drop `full IPv6` nfdump flag ([#62](https://github.com/mbolli/nfsen-ng/issues/62))

---

## [0.2] - 2020-05-08

### Added

- Step plot option ([@nrensen](https://github.com/nrensen))
- Processor interface ([@nrensen](https://github.com/nrensen))
- CentOS 7 install instructions ([@Dona21](https://github.com/Dona21))
- nfcapd folder structure documentation
- Support output format for record stats ([@nrensen](https://github.com/nrensen))

### Changed

- Remove "auto" output format ([@nrensen](https://github.com/nrensen))
- Better stats dropdown titles ([@nrensen](https://github.com/nrensen))
- PHP binary via `/usr/bin/env` ([@panaceya](https://github.com/panaceya))
- jQuery 3.2.1 → 3.5.0; Ion.RangeSlider 2.1.7 → 2.2.0; FooTable 3.1.4 → 3.1.6; dygraphs 2.0.0 → 2.1.0

### Fixed

- Log error if RRD database could not be created
- PHP 7.4 deprecation warnings
- Broken port-specific data import
- CLI can run from any working directory
- Passing empty arguments to nfdump
- Missing `use` declaration ([@luizgb](https://github.com/luizgb))
- Flows/stats CSV output row count off by one ([@nrensen](https://github.com/nrensen))

---

## [0.1] - 2018-11-02

Initial release. See the [wiki comparison page](https://github.com/mbolli/nfsen-ng/wiki/Comparison) for a feature comparison with the original NfSen.

[1.0.0-beta.6]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.5...v1.0.0-beta.6
[1.0.0-beta.5]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.4...v1.0.0-beta.5
[1.0.0-beta.4]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.3...v1.0.0-beta.4
[1.0.0-beta.3]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.2...v1.0.0-beta.3
[1.0.0-beta.2]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-beta.1...v1.0.0-beta.2
[1.0.0-beta.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0.0-RC.1...v1.0.0-beta.1
[1.0.0-RC.1]: https://github.com/mbolli/nfsen-ng/compare/v1.0-alpha...v1.0.0-RC.1
[0.4.0]: https://github.com/mbolli/nfsen-ng/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/mbolli/nfsen-ng/compare/v0.3...v0.3.1
[0.3]: https://github.com/mbolli/nfsen-ng/compare/v0.2...v0.3
[0.2]: https://github.com/mbolli/nfsen-ng/compare/v0.1...v0.2
[0.1]: https://github.com/mbolli/nfsen-ng/releases/tag/v0.1
