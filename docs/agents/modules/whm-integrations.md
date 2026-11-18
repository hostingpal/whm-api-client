# WHM integrations and hooks

> Agent contract notes for `whm()->integrations()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The WHM integrations capture at `7b6f5ce` on `11.138.0.19` passes two GET checks and
strict assessment with zero discrepancies. Integration groups are empty; no
populated rows, hooks, credentials, user configurations or mutations are observed
in that capture. Separate PR62 reviewer lifecycle 5722110065 at `160fc83` on
`11.138.0.19` observes six POST methods using disposable `wrvint` and fake tokens,
with account/home cleanup verified and no URL fetch. Removing nonexistent links
and groups succeeds with null. Hook edit/delete/reorder remain unobserved under
`8x0.2.49`; `8x0.10.5` retains the depth-limit generator audit.

## WHM integrations and hooks

`whm()->integrations()` exposes all twelve nondeprecated functions: three GET
reads and nine form POST operations for account integration groups/links, token
replacement and server-wide hook inventory/editing/deletion/order. The deprecated
remote-access-hash function remains excluded. `getIntegrationLinkUserConfig()` is
a read sent conservatively as POST because the source sets `x-readonly=false`;
that flag alone does not establish mutation or link creation.

Subscriber IDs, tokens, autologin/fallback URLs and executable hook/check/rollback
text are sensitive. Returned configuration, commands and URLs are payload data;
the client never executes them or fetches URLs. `createIntegrationLink()` accepts
custom group IDs as strings alongside the source's listed built-in groups.
Hook ordering takes caller-prepared CSV IDs, encoded once.

The pinned `editHook()` source does not specify partial-update semantics for
omitted options. Its `escalateprivs` default of 0 is a separate documented fact.
Read the current hook and supply intended option values that matter, including
escalation. The client makes no preservation, transaction or rollback guarantee.
Hook changes can affect later events and privilege escalation; requests are not
retried or automatically restored.

The three removal/token responses have unconstrained, nonrequired `data` in the
pinned source. Successful absent or null payloads return `null`; present JSON
values retain the existing mixed contract, including scalars and opaque fields.
API/envelope failures still raise exceptions with response metadata. The four
source data-free operations remain void.

All thirteen optional action fields are preserved by one source-backed `actions`
list correction instead of thirteen leaf overrides. Only `check`, `description`
and `rollback` permit null. Canonical in-range integer-string weights normalize
to integers; exact quoted 0/1 action flags normalize to integer flags. Strict
neighboring fields are unchanged. The global depth limit is unchanged and
`8x0.10.5` remains open.

The immutable two-GET capture at `7b6f5ce` on `11.138.0.19` passes strict
assessment with zero discrepancies: version and empty integration-group
inventory for `npr`. It establishes no populated item shapes. Separately,
[PR62 review 5721050295](https://github.com/hostingpal/whm-api-client/pull/62#pullrequestreview-5721050295)
reports `listHooks()` failing on digit-string action `weight` and `escalateprivs`,
and empty `listIntegrationGroups()`/`listIntegrationLinks()` for `npr`.
`listIntegrationLinks()` also returned empty for an unknown user, so an empty
list does not prove account existence. Other action flags and `reorderHooks()`
compatibility are reviewed source-family policy tested offline, not additional
live observations. No capture rerun, hook execution or URL fetch accompanied
these corrections.

Separately, [PR62 review 5722110065](https://github.com/hostingpal/whm-api-client/pull/62#pullrequestreview-5722110065)
at `160fc83` on WHM `11.138.0.19` reports a disposable `wrvint` lifecycle using
fake tokens: create group/link, get link user config, remove group/link and update
the link token (six POST methods). Removing a nonexistent group or link also
succeeded with null, so success does not prove an item was removed. Account and
home cleanup were verified; no URL was fetched. These reviewer observations do
not expand or replace the immutable two-GET capture. Server-hook writes
`editHook`, `deleteHook` and `reorderHooks` remain unobserved under `8x0.2.49`;
the depth-limit audit `8x0.10.5` stays open.
