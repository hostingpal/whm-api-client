# UAPI Email

> Agent contract notes for `uapi()->email()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->email()` covers all 96 pinned functions: mailbox lifecycle, quotas and
suspension, domain and mailbox forwarders, filters, routing, autoresponders, spam
settings and delivery diagnostics. Mutations and secret-bearing requests use form
POST. Preserve per-method role/version caveats, omission/false/zero semantics and
sensitive inputs in the overlay. One-based suffix families encode filter lists as
action1, action2 (and analogous names), with integer values only in the documented
scalar family. getPopQuota returns int|'unlimited'; exact zero-fraction strings
normalize losslessly to int, while the explicit unlimited literal remains a string.
Trace destinations retain the same pinned leaf fields in object and list forms;
recursive routed trees are not inferred. The pinned countPops documentation does
not specify whether the main account is included.

The complete Email module exposes 96 pinned functions. Mutations and secret-bearing
requests use form POST. Preserve password/passwordHash alternatives, receive-mail
role/default/zero-unlimited caveats, and integer/floating-point quota units. Email
filter wire families use reviewed suffix-numbered encodings: filter*, action*, dest*,
match*, opt*, part*, val* become name1/name2; only val* allows integer items. Lists
must be nonempty/sequential, and expanded wire collisions fail before dispatch.
The formal trace_delivery destination alternatives are merged without dropping
fields from later optional-object alternatives; recursive example behavior remains
unverified. terminate_mailbox_sessions has an upstream envelope discrepancy and
no documented data payload; never claim successful live compatibility without
observing it. Live tests must never send welcome/configuration messages or deliver
mail, and only newly owned fixture mailboxes may be changed or removed.
The get_pop_quota result alone opts into lossless integral-fixed-point-string
normalization, backed by the original 2026-10-30 quota-format artifact. Reject
nonzero fractions, noncanonical whole parts and integer overflow; do not widen
other integer contracts. Remaining live/source gaps are tracked in 8x0.2.18.
The final owned Email capture at fd552b2 on 11.138.0.19 passes all 45 original
observations across 16 Email functions plus WHM version, with zero discrepancies
and both mailboxes confirmed absent. It verifies API password/quota/login-state
behavior; it does not establish IMAP/SMTP access or live coverage of all 96 methods.
