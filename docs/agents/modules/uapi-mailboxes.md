# UAPI Mailboxes

> Agent contract notes for `uapi()->mailboxes()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI Mailboxes (2026-10-31)

`uapi()->mailboxes()` covers all five pinned Mailboxes functions. The two inventory
reads, `getMailboxStatusList(account)` and `hasUtf8MailboxNames(user: null)`, use
GET. `expungeMailboxMessages(account, mailbox, query)`,
`expungeMessagesForMailboxGuid(account, mailboxGuid, query)` and
`setUtf8MailboxNames(enabled)` use form POST. Inventory and expunge require
cPanel 64; UTF-8 mailbox-name settings require cPanel 78.

The expunge operations mark selected messages as deleted and declare no rollback.
Mailbox wildcard characters such as `*` cannot be escaped; upstream recommends
GUID-based expunge. Obtain names and GUIDs separately with getMailboxStatusList.
Required queries are sensitive and forwarded unchanged; the client does not parse,
narrow or escape them, verify mailbox ownership, protect arbitrary mail from
deletion, retry or roll back. Account, mailbox and GUID strings retain their exact
bytes, without email, domain, path or UUID validation, trimming or name conversion.

Mailbox-name expunge preserves any present JSON data as mixed, including null;
missing data fails validation. GUID expunge preserves opaque object fields and
existing empty-array object compatibility, while null and missing data fail. No
message counts or per-message outcomes are invented. Status-list rows have optional
string guid/mailbox and integer messages/vsize; vsize is bytes, zero remains zero
and an empty list remains valid. Source minimum 0 is documentation under the
existing runtime, with no new range validation or numeric-string coercion.

Omitted or null user retains the current-user default; an explicit empty string
is supplied. hasUtf8MailboxNames returns an object with optional integer enabled
(0|1), rather than an unwrapped boolean. setUtf8MailboxNames requires a native bool
and sends 0/1. It changes Roundcube mailbox-name encoding for the selected cPanel
account's email accounts, not message-content charset. Inspect optional integer
success (0|1) independently of API status; a successful envelope does not prove
mail-client behavior. Known optional fields remain distinct from explicit null.

This generated slice adds five canonical UAPI operations and one group over
`3872d384a2e4f83a348758085808452230459d8c`: 1,150 operations (562 UAPI, 588 WHM),
97 groups and 197 generated outputs (196 PHP files plus coverage). Prior contracts,
pinned sources and the map checksum are preserved; no runtime or generator change
is needed. Delivery is `whm-api-client-8x0.4.23`; separate live follow-up
`whm-api-client-8x0.2.65` remains OPEN. Synthetic offline fixtures establish no live
expunge, encoding or mail-client compatibility. No live API call, mailbox creation,
mail delivery or settings change ran; later live work requires separately owned
fixtures and cleanup. Required PHP 8.3 CI and the 55 inherited map diagnostics
remain visible, external-owned and unwaived.


### UAPI Mailboxes compatibility correction (2026-11-01)

The dated 2026-10-31 Mailboxes section above records the original offline
implementation and its then-effective contract. This correction integrates
approved master `3b81970c2d13c6f8f336a592eb12a9e4510a1d49` with original
`9e47d959cbf562bf91bd1b32944360297acfbcaf`: 1,187 canonical operations
(591 UAPI, 596 WHM), 105 groups and 213 generated outputs (212 PHP plus coverage).
Original evidence records, including their corrected provenance notes, remain
historical; the pinned source has not changed.

[Review 5725223473](https://github.com/hostingpal/whm-api-client/pull/98#pullrequestreview-5725223473)
of original `3872d38..9e47d95` reports cPanel `11.138.0.19` results through direct
UAPI and the WHM bridge. The effective GUID expunge result now preserves explicit
null alongside opaque object fields and inherited empty-array compatibility;
missing data, scalars and nonempty lists still fail. The formal pin declares a
nonnullable object, so this is an evidenced correction rather than a source
refresh. Name-based expunge still accepts any present JSON value, including null,
and rejects missing data.

Only status-list `messages` and `vsize` additionally normalize canonical decimal
integer strings losslessly within the platform integer range. Native integers,
zero, omitted fields and empty lists retain their meaning; `vsize` remains bytes.
Malformed spellings, overflow, booleans, native floats and null reject. Existing
negative-value behavior and the descriptive minimum/range policy are unchanged.
Unknown row fields continue to be filtered; GUID/mailbox strings and native UTF-8
0|1 flags remain strict. `hasUtf8MailboxNames(user)` takes a cPanel username, not a
mailbox address; omitted/null user selects the current user and an explicit empty
string is forwarded unchanged.

Both expunge methods can report success while deleting nothing: the reviewer
observed a nonmatching query and a nonexistent GUID succeed. Separately verify
the intended inventory with `getMailboxStatusList(account)`. Required sensitive
queries and exact name/GUID bytes are still forwarded unchanged; wildcard escaping
limits, no deletion count, no ownership protection, one request, no retry and no
rollback remain. No automatic verification, concurrency, atomicity or recovery
guarantee follows.

The reviewer reports seeded-message counts of 4 to 3 for name expunge and 3 to 2
for GUID expunge, with the latter throwing on explicit null at the original head.
Quoted status integers, strict native UTF-8 flags/settings readback, invalid-query
and unknown-account API errors, account/home removal and token revocation are
reviewer-attributed observations, not checks by this integration. This integration
made no live request, mailbox/message/settings change, source fetch or new capture.
Synthetic local responses verify the bounded contract only. Runtime follow-up
`whm-api-client-8x0.2.65`, the 55 inherited diagnostics, platform/stress/metadata/
release/full-parity gaps and deferred consumer integration remain open and
unwaived. Historical PR95 provenance finding I1 / `whm-api-client-qrk` remains open.
