# Introduction

nfsen-ng is an in-place replacement for the ageing
[NfSen](http://nfsen.sourceforge.net/) web frontend. It sits on top of the
existing [nfdump](https://github.com/phaag/nfdump) tool suite and adds real-time
SSE push, a responsive interface, and a choice of RRD or VictoriaMetrics as the
storage backend, without changing how nfcapd itself captures traffic.

![Overview, light and dark](images/00-page-overview.png)

## Who this is for

Anyone already running (or migrating from) NfSen/nfdump to monitor NetFlow
traffic: source and destination breakdowns, protocol and port distributions,
flow search, who-talks-to-whom, and threshold alerting, all served from the
nfcapd files nfdump already writes.

## The shape of the app

A sidebar leads to seven pages:

- **Overview**: the traffic graph with key figures and the busiest addresses,
  ports and protocols of the range, from data collected during import.
- **Top Talkers**: exact top-N rankings by any statistic nfdump offers.
- **Flows**: individual flow records, their raw nfdump output and a summary.
- **Conversations**: source and destination pairs as a Sankey, a Matrix and a
  ranked table.
- **Alerts**: threshold rules and their fired and resolved history.
- **Health**: the import, capture sources, disks, checks and the recent log.
- **Settings**: preferences, and how the instance is deployed.

A controls bar on top sets the time range, sources, protocol and unit for all of
them, and the traffic graph above every analysis page doubles as the range
picker. Anything that reads capture files runs only when you press Run, after
showing what it will cost.

Every page has its own address (`#/flows`), but it's one route (`/`) on the
server. The server side is PHP 8.4 running on [OpenSwoole](https://openswoole.com/)
coroutines via a small in-house framework
([php-via](https://github.com/mbolli/php-via)), pushing UI updates to the browser
over Server-Sent Events using [Datastar](https://data-star.dev/). There's no
separate REST API and no client-side framework build step: the server renders
Twig templates, and Datastar patches the DOM. Time series live in RRD or
VictoriaMetrics; saved filters, alert history and the precomputed top-N live in
an SQLite file next to the preferences.

To get it running, start with [Installation](deployment/installation.md) and
[Configuration](deployment/configuration.md). The [Quick Tour](guide/quick-tour.md)
walks through the interface; the [Architecture](architecture/overview.md) chapter
covers how the pieces fit together; and
[Development](development/getting-started.md) covers running it locally.

OpenSwoole's FreeBSD/other-BSD port is unmaintained
([openswoole/ext-openswoole#233](https://github.com/openswoole/ext-openswoole/issues/233)),
so nfsen-ng currently requires Linux.

## Status

This book documents the `v1.0.0-beta.5` line and the unreleased changes on top of
it; see the [Roadmap](roadmap.md) for what's tracked and what's next, and the
[changelog](https://github.com/mbolli/nfsen-ng/blob/master/CHANGELOG.md) for what
changed.
