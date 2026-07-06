# Setting Up Alerts

Alerts watch a metric (flows, packets, or bytes) and notify you when it
crosses a threshold — checked automatically every time new traffic data
comes in, no need to keep the dashboard open.

Find them under **Settings → Alerts**:

![Alerts screen](../images/06-settings-alerts.png)

## Creating a rule

Fill in the **New Alert Rule** form:

1. **Rule name** — anything memorable, e.g. "High traffic on gw1".
2. **Profile** — which nfdump profile this rule watches (usually `live`).
3. **Condition** — pick a **Metric** (Bytes/s, Packets/s, Flows/s), an
   **Operator** (`>`, `>=`, `<`, `<=`), a **Threshold type**, and a
   **Value**:
   - **Absolute value** — fire when the metric crosses this number
     directly.
   - **Percent of average** — fire when the metric is this percent above/below
     its own rolling average (choose the averaging window separately). Use
     this for "alert me when traffic is unusually high *for this network*"
     rather than a fixed number that might be normal for one link and
     alarming for another.
4. **Cooldown** — how many 5-minute cycles to wait before this rule is
   allowed to fire again, so a sustained spike doesn't flood you with
   repeat notifications.
5. **Notifications** — an email address and/or a webhook URL. Leave both
   blank for an in-app-only alert (visible in the Recent Alert History list
   below the form).

Click **Create rule** when you're done. Existing rules appear in the table
above, where you can enable/disable, delete, or test-fire each one on
demand with the **Test** button.

## Scoping an alert to specific traffic

By default, a rule watches your *total* traffic for its metric. Often you
want something narrower — "alert only on ICMP", "alert only for this
subnet." That's what the **Traffic filter** field is for:

![Traffic filter field](../images/guide-alerts-traffic-filter.png)

Type any nfdump filter expression — the same syntax as the
[Flows](browsing-flows.md) tab's filter field:

| You want to watch | Traffic filter |
|---|---|
| Only ICMP traffic | `proto icmp` |
| Only one subnet | `net 192.168.1.0/24` |
| Traffic from one subnet, TCP only | `src net 10.0.0.0/8 and proto tcp` |

When this field is set, the rule runs a real (small, fast) nfdump query over
the most recent data instead of using pre-aggregated totals — so it can
scope to exactly the traffic you described, at the cost of being slightly
more expensive to evaluate than an unfiltered rule. Leave it blank for
"total traffic," which is cheaper and is all you need for a general
high-traffic alert.

If you type something nfdump doesn't understand, the rule simply doesn't
fire (rather than erroring loudly) — double-check the syntax with the
[Flows](browsing-flows.md) tab first if a filtered alert never seems to
trigger.

## Testing before you rely on it

Click **Test** on any rule to evaluate it immediately against current data
and see whether it *would* fire — without waiting for the next automatic
cycle, and without needing to actually breach the threshold for real. Good
practice after creating or editing a rule, especially one with a traffic
filter, before trusting it to notify you unattended.
