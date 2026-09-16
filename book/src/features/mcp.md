# MCP Server

nfsen-ng ships an optional [Model Context Protocol](https://modelcontextprotocol.io) server:
read-only access to your NetFlow data for an AI agent, so investigating traffic does not mean
writing nfdump filter expressions by hand.

It is **off by default**. Nothing listens, nothing runs, until you start it or turn the HTTP endpoint on.

## What it is for

Triage, not mitigation. During an attack the response is already decided and usually
automated, and a model in that path only adds latency. The value is in the minutes before and
after, when someone is asking what this traffic actually *is* — the loop of filter, look,
pivot, filter again, which is exactly what an agent is good at.

## Running it

The server speaks MCP over stdio, so a client launches it as a subprocess:

```bash
php /var/www/html/nfsen-ng/backend/mcp.php
```

In Docker, point the client at the running container:

```json
{
  "mcpServers": {
    "nfsen-ng": {
      "command": "docker",
      "args": ["exec", "-i", "nfsen-ng", "php", "/var/www/html/nfsen-ng/backend/mcp.php"]
    }
  }
}
```

stdio means no listening socket and no credentials to manage: whoever can run the command
already has access to the data. It also runs as its own process, so an agent's queries never
compete with the web UI for the OpenSwoole worker.

## Over HTTP

For an agent that does not live on this host, set `NFSEN_MCP_HTTP=true` and the same tools are
served at `/_mcp`, **on the app's own port**. That is deliberate: nfsen-ng has no
authentication of its own and is protected by where you deploy it, so an endpoint on a second
port would sit outside whatever guards the dashboard. On the app's port it inherits that
protection exactly, and there are no separate credentials to manage.

```
NFSEN_MCP_HTTP=true
NFSEN_MCP_HOSTS=nfsen.example.com    # hostnames a client may address this server as
```

`NFSEN_MCP_HOSTS` exists for DNS rebinding protection, which the specification asks for: a
browser tricked into resolving an attacker's name to your address otherwise reaches a server
that trusts its own network position. Leave it empty and only `localhost` is accepted, which
is right for a client on the same machine and wrong for anything else. A request whose `Host`
is not listed gets `403`.

With `NFSEN_MCP_HTTP` off, `/_mcp` answers `404`, the same as any path that does not exist.

The endpoint speaks the stateless revision of the protocol, so there is no handshake and no
session: each request carries its own protocol version and client info. Any current MCP client
does this for you.

Anything reaching this endpoint can read your flow data, exactly as anything reaching the
dashboard can. Whatever protects one has to protect the other.

## The two tiers

Every tool states what it costs, because the difference is enormous and a caller that does not
know it will burn minutes learning something the graph already knew.

| Tier | Reads | Tools |
| --- | --- | --- |
| Cheap | Stored five-minute aggregates, answers immediately | `traffic_timeline`, `current_load`, `data_coverage`, `status`, `estimate_cost`, `lookup_address`, `list_alerts` |
| Expensive | Capture files, via nfdump, cost scales with the window | `top_talkers`, `flow_matrix`, `list_flows` |

The intended order is: `data_coverage` to see what exists, `traffic_timeline` or
`current_load` to find *when*, `estimate_cost` to price the window, then one of the expensive
tools to find *who* and *what*, then `lookup_address` to turn an address into a device or an
owner.

`estimate_cost` is deliberately in the cheap tier: it stats capture files rather than reading
them, and exists so an agent can check a window before committing to a scan whose progress it
cannot watch.

## Tools

### Cheap

- **`traffic_timeline`** — the series behind the Graphs tab, broken down by source, protocol or
  port, measured in flows, packets, bytes or bits.
- **`current_load`** — the latest interval next to its rolling average, with the multiple
  between them. A ratio of `0` means the average is zero, not that traffic stopped.
- **`data_coverage`** — first sample, last sample and last import per source. An import that
  has not caught up looks exactly like a quiet network; this is how you tell them apart.
- **`status`** — datasource reachability, capture collection and configuration checks, so an
  infrastructure failure is not reported as a change in traffic.
- **`estimate_cost`** — files, bytes and nfdump runs a window would cost.
- **`lookup_address`** — geolocation and Netbox context for an address. Private addresses skip
  the geolocation lookup entirely.
- **`list_alerts`** — the configured rules, to say whether something you found is already
  covered.

### Expensive

- **`top_talkers`** — top sources, destinations, ports or protocols, ranked by flows, packets,
  bytes or rate.
- **`flow_matrix`** — source to destination pairs, optionally through a destination port. One
  loud host and a distributed flood look very different here.
- **`list_flows`** — individual records, for when the aggregate is ambiguous.

## Limits

These are enforced by the server, not suggested to the model:

- **Time windows** are clamped to `NFSEN_MAX_STATS_WINDOW`, the same bound the Statistics and
  Sankey panels apply. The answer says when it shortened your range.
- **Row limits** default to 20 and are capped at 500, whatever the caller asks for.
- **A byte ceiling** of 16 GiB per call refuses a query that would read more capture data than
  that, with a message telling the caller to narrow the window or add a filter. Setting
  `NFSEN_MAX_STATS_WINDOW` to `0` opts out of both the window bound and this ceiling.
- **Filter expressions** reach nfdump as a single escaped argument, never interpolated into a
  shell command. Obvious mistakes such as unbalanced parentheses are rejected with a readable
  error rather than run.

## Alert-triggered triage

The strongest use is asynchronous rather than interactive: an alert fires, an agent
investigates while you are still reading the notification, and the summary arrives with the
addresses already enriched.

Point a rule's webhook at a receiver that runs `scripts/alert-triage.sh`, with the rule's
webhook template producing JSON that carries the tokens the script reads:

```json
{"rule":"{rule}","sources":"{sources}","time":"{time}","condition":"{condition}"}
```

The script builds a prompt that walks the tools in the intended order, cheap before
expensive, and asks for a short answer that says plainly when the data does not support a
conclusion. It is a worked example rather than something nfsen-ng runs: the agent binary, its
credentials and where it posts the result are yours to choose.

One detail that decides whether it works at all: an agent started non-interactively cannot ask
anyone to approve a tool, so the tools have to be allowlisted when it launches. The script does
that through `ALLOWED_TOOLS`, listing only read-only tools. Without it the run ends with
"permission not granted" and no investigation.

Note also that the server needs no credentials of its own. The only credentials involved are
the agent's own access to whichever model it uses.

This runs *beside* the incident rather than inside it. It does not decide anything and it
cannot act, which is what makes it safe to wire up.

## What it cannot do

Nothing in the server writes. There is no tool to create an alert rule, trigger an import,
change settings or act on the network. The worst case for a compromised or confused client is
disclosure of flow data, not control of the installation.

That matters more than it sounds: flow data is a record of who talked to whom, on your
network. Treat access to this server as equivalent to access to the web UI.
