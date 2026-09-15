#!/bin/sh
# Example: turn an nfsen-ng alert webhook into an agent triage run.
#
# Point a rule's webhook at a receiver that runs this script. The webhook body is whatever
# the rule's template produced, so configure it as JSON and give it the tokens used below:
#
#   {"rule":"{rule}","sources":"{sources}","time":"{time}","condition":"{condition}"}
#
# The agent gets the MCP server from mcp-config.json (see book/src/features/mcp.md) and is
# asked to investigate the window around the alert. Nothing here is nfsen-ng specific beyond
# the prompt: any MCP-capable agent CLI works.
#
# Not run by nfsen-ng itself, and not installed anywhere. It is a worked example.
set -eu

PAYLOAD=${1:-$(cat)}
RULE=$(printf '%s' "$PAYLOAD" | sed -n 's/.*"rule":"\([^"]*\)".*/\1/p')
WHEN=$(printf '%s' "$PAYLOAD" | sed -n 's/.*"time":"\([^"]*\)".*/\1/p')
SOURCES=$(printf '%s' "$PAYLOAD" | sed -n 's/.*"sources":"\([^"]*\)".*/\1/p')

: "${AGENT:=claude}"
: "${MCP_CONFIG:=./mcp-config.json}"

# An agent started non-interactively cannot ask anyone to approve a tool, so every tool it is
# expected to use has to be allowlisted up front. Without this the run ends with "permission
# not granted" and no investigation. Only read-only tools are listed; status is included so a
# collector failure can be told apart from an idle network.
: "${ALLOWED_TOOLS:=mcp__nfsen-ng__data_coverage,mcp__nfsen-ng__traffic_timeline,mcp__nfsen-ng__current_load,mcp__nfsen-ng__status,mcp__nfsen-ng__estimate_cost,mcp__nfsen-ng__top_talkers,mcp__nfsen-ng__flow_matrix,mcp__nfsen-ng__list_flows,mcp__nfsen-ng__lookup_address,mcp__nfsen-ng__list_alerts}"

PROMPT=$(cat <<EOF
The nfsen-ng alert "$RULE" fired at $WHEN UTC for source(s): $SOURCES.

Investigate using the nfsen-ng MCP tools, in this order:
1. data_coverage, so you know what data exists.
2. traffic_timeline and current_load around that time, to establish when it started and how
   far above normal it is.
3. estimate_cost for the window you intend to read before any expensive call.
4. top_talkers and flow_matrix for that window, to find who and what.
5. lookup_address for the addresses that matter.
6. list_alerts, to say whether other rules already cover this.

Then write at most ten lines: what the traffic is, where it comes from, whether it is still
happening, and what you would check next. Say plainly if the data does not support a
conclusion. Do not speculate beyond what the tools returned.
EOF
)

exec "$AGENT" --mcp-config "$MCP_CONFIG" --allowedTools "$ALLOWED_TOOLS" -p "$PROMPT"
