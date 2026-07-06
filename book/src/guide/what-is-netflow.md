# What is NetFlow?

**NetFlow** (and its open standard successor, **IPFIX**) is a way routers and
switches summarize the traffic passing through them. Instead of recording
every packet, a network device groups packets into **flows** — one flow per
unique combination of source IP, destination IP, source port, destination
port, and protocol — and periodically exports a compact record for each
flow: how many packets, how many bytes, when it started, when it ended.

A single flow record looks roughly like:

> `192.168.1.50:52184 → 74.50.105.85:443, TCP, 85 packets, 10.7 KB, lasted 4m48s`

That's it — not the actual content of the traffic (no payload, no URLs, no
message bodies), just the shape of the conversation. This is what makes
NetFlow practical at scale: a busy link might carry gigabits of traffic per
second, but the flow *records* summarizing it are a tiny fraction of that
volume.

## What problem does it solve?

Without flow data, answering basic questions about your network requires
either expensive full packet capture (storage-hungry, and often
legally/privacy sensitive) or nothing at all. With flow export enabled on
your routers, you can answer things like:

- **"What's using all our bandwidth right now?"** — rank talkers by bytes.
- **"Did anything unusual happen last Tuesday at 3am?"** — go back in time,
  something full packet capture rarely lets you do affordably.
- **"Is this subnet talking to that one, and how much?"** — the
  [Sankey diagram](sankey.md) answers exactly this, visually.
- **"Alert me if ICMP traffic to this network spikes"** — see
  [Setting Up Alerts](alerts.md).

## Where nfsen-ng fits

nfsen-ng doesn't *capture* NetFlow itself — that's [nfcapd](https://github.com/phaag/nfdump)'s
job (part of the nfdump suite), a small daemon that listens for NetFlow/IPFIX/sFlow
packets sent by your routers and writes them to disk in 5-minute-rotated
files. nfsen-ng is the web UI on top: it reads those files (via
[nfdump](https://github.com/phaag/nfdump), the query tool), aggregates them
into graphs, lets you search and filter individual flows, and can alert you
when traffic crosses a threshold you define.

If you already have nfcapd running and writing files somewhere, nfsen-ng
just needs to be pointed at that directory — see
[Quick Tour](quick-tour.md) to get oriented, or the
[Developer Reference's Getting Started](../development/getting-started.md)
page if you're setting up nfcapd/nfdump for the first time.
