# UAPI DAV calendars, contacts and delegation

> Agent contract notes for `dav()`, `cpDavd()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## DAV calendars, contacts and delegation

`uapi()->dav()` and `uapi()->cpDavd()` each cover all six pinned functions:
six reads use GET and six mutations use form POST. They operate on the selected
cPanel account; identifiers and supplied values retain their exact wire encoding.
The client sends one request per call, with no retry, rollback, DAV connection,
synchronization or URL fetch. API success does not establish DAV authentication,
access enforcement or synchronization.

Review [5721214794](https://github.com/hostingpal/whm-api-client/pull/67#pullrequestreview-5721214794)
inspected head `2db7f7e077d68a63f89fbb15647202f6da832dc7` on cPanel `11.138.0.19`.
Its server implementation references are reviewer-inspected `Cpanel/API/DAV.pm`
and `Cpanel/API/CPDAVD.pm`; this correction performed no new server inspection.
The reviewer read `npr` through both routes and used a disposable `wrvdav` account,
a mailbox and a temporary token for writes; account/home/token cleanup was confirmed.
This report is separate from the immutable seven-GET capture.

`addDelegate()`, `updateDelegate()`, `removeDelegate()` and `manageCollection()`
accept successful missing/null data as `null`. A present object still validates
the pinned optional strict integer `shared` flag; that field was not sent by the
reviewed handlers. Earlier `PayloadMismatchException(outcomeConfirmed=true)`
followed successful writes; callers must not retry or automatically undo them.
Observed null successes cover collection create/delete through both routes,
collection update bridged, and delegation add/update/remove direct. Populated
`listDelegates()` was checked direct; bridged remove was checked only on empty state.

`updateDelegate(calendar, delegatee, delegator, readonly)` requires an explicit
nonnullable fourth boolean as client policy: false sends 0 and true sends 1.
`addDelegate()` retains its optional boolean. The reviewer-inspected implementation
defaults omission to 1, and an omitted update removed write access from a read-write
share. Mailbox add/update worked direct but failed bridged with undefined
`Cpanel::Email::Accounts::manage_email_accounts_db`; no fallback or retry occurs.
This observation does not establish that every possible bridged form fails.

`listUsers()` returns a map keyed by principal, then collection ID, preserving all
five optional collection fields and exact local keys. `protected` accepts native
0/1 and exact quoted "0"/"1", normalized to integers. Evidence paths wildcard both
dynamic key levels; private names and content are not persisted. Configuration
`no_ssl.port` and `ssl.port` accept canonical bounded integer strings observed direct
and native integers observed bridged. `activesync.port` uses the same explicit
source-family/offline policy; ActiveSync was disabled and its port unobserved.
Other flags, sibling types, omission and null rules remain strict.

On the reviewed version, shared-addressbook enable/disable are no-op stubs returning
`shared=0`. `hasSharedGlobalAddressbook()` is a return-0 stub that raises
`ApiErrorException` with empty `errors()`, with/without name and after enable;
that failure is not a false/not-shared result. `isHordeEnabled()` fails on both
routes because the server cannot find function `is_horde_enabled` in module `DAV`.
The pinned Horde prose promises 0; the current integer-only validator also accepts
1/-1 offline and adds no singleton-enum validation. These observed limitations
remain prominent in generated PHPDoc; pinned sources are unchanged.

Collection create requires name; update/delete require path, and create omission
autogenerates `<collection_type>-<uuid>`. Update needs at least one of name,
description or calendar-color. The reviewer-inspected source filters them by
truthiness: empty strings and "0" alone error and are ignored beside another field,
so they cannot clear a description. The client preserves supplied values and exact
`calendar-color` encoding without adding cross-field validation or undoing deletion.

The fixed seven-GET catalog is diagnostic, with unchanged account binding, order,
URIs and guards; it is no availability guarantee. The two misleading overlay
success recipes for shared-addressbook/Horde reads are removed, while the diagnostic
catalog retains those known failures. Its original capture remains failed and
unwaived. No new capture, live request or mutation ran for this correction.
Remaining availability, route/action and enforcement gaps stay open in `8x0.2.56`.

The immutable [seven-GET capture](../../api/evidence/2026-10-31-dav-calendar.json)
at `628c46eec9608e31a5bc32dedb1d374f25d6b1ac` on `11.138.0.19` has seven HTTP
200 observations: version and both DAV service reads passed, while shared-addressbook
and Horde failed through both routes with payload `not_checked`. Strict assessment
is failed despite zero schema discrepancies, with no waiver. SHA-256:
`e4cec00d478951bd22f0cc1e82609ebcdcac2041709111a08a8034007770b19a`.
It includes no private reads, writes, DAV access or synchronization proof.
