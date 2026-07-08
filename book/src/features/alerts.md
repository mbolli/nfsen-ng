# Alerts

Threshold-based alert rules, evaluated automatically after every nfcapd
import (`Settings → Alerts`). Managed by `AlertManager`
(`backend/common/AlertManager.php`) and `AlertActions.php`.

![Alerts sub-tab](../images/06-settings-alerts.png)

## Rule shape

| Field | Notes |
|---|---|
| Metric | flows / packets / bytes |
| Operator | `>`, `>=`, `<`, `<=` |
| Threshold type | **Absolute** value, or **percent of a rolling average** window (10m–24h) |
| Cooldown | 5-minute slots to suppress re-firing after a fire |
| Traffic filter | Optional raw nfdump filter expression |
| Notifications | Email and/or webhook (HTTP POST, JSON payload) |

Percent-of-average rules return an unreachable threshold
(`PHP_FLOAT_MAX`) while the rolling average is still zero — a genuine
cold-start guard, not a bug: a rule can't fire against a baseline that
doesn't exist yet.

## Traffic filter

By default a rule's current value comes from the active datasource's
pre-aggregated totals (`fetchLatestSlot()` — cheap, but only ever "all
traffic for this source"). Setting a **traffic filter** — any nfdump filter
expression, e.g. `proto icmp`, `net 192.168.1.0/24`, or a combination —
switches that rule to `AlertManager::fetchCurrentSlot()`, which runs a real
`nfdump` query over the latest 5-minute slot with that filter and sums the
matching flows/packets/bytes instead. This is what lets a rule watch "ICMP
only" or "this one subnet" rather than just aggregate totals.

`fetchCurrentSlot()` is the single source of truth for "should this rule use
the filtered or aggregate path" — both the periodic evaluation loop and the
manual **Test** button call it, so testing a rule and actually evaluating it
behave identically. (They didn't always: see the note in
[Nfdump Integration](../architecture/nfdump-integration.md)'s neighbourhood
about how easy it is for a manual "preview" action to drift from the real
evaluation path if it's implemented as a separate code path instead of a
shared one.)

A malformed filter expression fails safe: `fetchCurrentSlot()` catches the
resulting exception and returns zero values rather than propagating the
error, so a typo in a filter suppresses that rule's firing rather than
crashing evaluation for every other rule.

## Cooldown & history

A fired rule's cooldown ticks down once per evaluation cycle regardless of
whether it fires again; **Recent Alert History** (bottom of the tab) shows
the last 50 dispatches across all rules, newest first.

## Notification templates

Email subject/body and webhook title/message are built from `{token}`
templates, not hardcoded strings. Resolution is a 3-tier fallback, checked
in `AlertManager::resolveTemplate()`:

1. The rule's own override (`AlertRule::$emailSubjectTemplate` /
   `$emailBodyTemplate` / `$webhookTitleTemplate` / `$webhookMessageTemplate`
   — nullable, `null` = unset, following the same convention as
   `$nfdumpFilter`).
2. A global default, stored in `UserPreferences`/`Settings`
   (`$defaultEmailSubjectTemplate` etc. — plain `string`, `''` = unset,
   matching that class's existing convention for fields like
   `$alertEmailFrom`) and saved via the existing `save-settings` action, not
   a new one.
3. `AlertManager`'s built-in `DEFAULT_EMAIL_SUBJECT` / `DEFAULT_EMAIL_BODY` /
   `DEFAULT_WEBHOOK_TITLE` / `DEFAULT_WEBHOOK_MESSAGE` constants — themselves
   just `{token}`-templated strings, so the "hardcoded" behavior from before
   this feature existed is really just tier 3 with tiers 1–2 both empty. A
   golden test in `AlertManagerTest.php` asserts the resolved+rendered
   output for an empty-override rule matches that pre-existing hardcoded
   text byte-for-byte.

`AlertManager::buildTemplateVars()` builds the substitution map (12 tokens —
see the [user guide](../guide/alerts.md#customizing-the-notification-text)
for the list); substitution itself is a plain `strtr()`. Notably,
`{flows}`/`{packets}`/`{bytes}` are always all three populated regardless of
the rule's own `metric`, since `fetchCurrentSlot()` already computes all
three together in one nfdump/datasource round-trip — no extra query cost to
expose them all as template variables.

The Settings UI's live preview (`frontend/js/components/alert-template-preview.js`)
is a client-side mirror of this same resolve/substitute logic, using fake
example numbers instead of real fired-alert data — it has no server
round-trip, so it works for a brand-new, unsaved rule. The preview `<pre>`
elements carry `data-ignore-morph`, same reasoning as `#series`/`#legend` in
[graph-view.html.twig](../../../backend/templates/partials/graph-view.html.twig):
they're empty in the server-rendered HTML and filled in entirely by
`data-effect`, so without it an SSE-pushed catch-up sync shortly after page
load morphs them back to the server's (empty) version, wiping the computed
preview text — confirmed against Datastar's actual `morphNode()`/
`morphChildren()` source, which reconciles an element's children toward the
freshly-rendered version whenever `isEqualNode()` says they differ, deleting
anything client JS added that the server doesn't know about.
