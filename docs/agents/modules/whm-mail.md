# WHM mail management

> Agent contract notes for `whm()->mail()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### Expanded WHM mail management

`whm()->mail()` adds 51 methods while preserving all sixteen existing overlay
entries, including the fourteen typed greylisting methods and two exclusions.
The group covers 65 of 67 pinned functions. Twenty additions use GET and 31 use
form POST for mail authentication/DNS, incoming blocks, outgoing-mail controls,
mailbox state, diagnostics, SNI, spamd/Exim settings and message/session actions.

Private keys, Exim configuration and mailbox search expressions remain sensitive.
Syntax validation uses POST for private configuration text despite the pinned GET
declaration; its POST compatibility remains live-unverified. Source-marked mutating
checks such as DKIM validation and Exim configuration checking also use POST.
Message, queue, private-key and mobileconfig results remain private payloads.
No returned URL or configuration text is executed or fetched.

Repeated and numbered families follow each operation's explicit source spelling.
Paired DKIM key and manual-MX lists retain caller responsibility for positional
matching. `installSpfRecords()` retains one scalar domain/record pair. The separate
`installSpfRecordBatch(records)` accepts a nonempty sequential list of complete
`domain`/`record` string rows and sends one form POST with interleaved
`domain-1`, `record-1`, `domain-2`, `record-2` fields, including suffixed singletons.
Row order, duplicate domains, empty strings and exact bytes survive one encoding;
these byte rules promise no clearing or preservation semantics. The whole argument
is sensitive. The pinned domain prose numbers fields but omits the record spelling;
[reviewer source inspection on 11.138.0.19](https://github.com/hostingpal/whm-api-client/pull/72#pullrequestreview-5722427592)
supports positional pairing. No live batch list was observed; `8x0.2.48` remains
open. Installing replaces each supplied complete SPF record, with no patch,
read/merge, retry, rollback, atomicity, persistence or DNS propagation guarantee.
Duplicate-domain resolution is not established. Returned messages/configuration
remain private payload data. Tracking filter, sort and chunk controls require
explicitly prepared generic parameters where
the typed source surface does not declare them. No dynamic field is invented.

Per-item domain/mailbox results remain separate from API success. DKIM and DMARC
validation payloads use source-described lists despite missing formal type nodes.
Other optional, nullable, opaque, absent and void contracts remain strict.
Each method preserves availability, role restrictions, defaults, units and effects.
Hold/release and suspend/unsuspend are distinct operations; expunge can permanently
remove messages. Enabling SNI can succeed with a warning that it is always enabled;
the deprecated disabling function stays excluded. The client adds no mail sending,
retry, rollback, automatic expunge or configuration restart.

All 51 additions have independent offline contracts. The fixed eight-GET catalog
reads version, default DMARC policy, SNI support/status, blocked country/domain
inventories, owned mailbox names and forwarding destinations for designated
`npr`/`npr.rs`. It reads no messages, queue contents, private keys or mobileconfig
and sends no messages or mutations. These reads establish no mail delivery,
filtering enforcement, TLS serving or key-installation behavior.
Remaining live work stays under `8x0.2.48`.

The corrected WHM mail-management capture at 437c951 on 11.138.0.19 passes all
eight GET observations and strict assessment with zero discrepancies. Only
get_user_email_forward_destination.forward_to additionally preserves literal empty
string alongside list<string>, without assigning meaning; source review now also
supports arbitrary strings, while null remains invalid. Inventories are empty and no mail mutation/delivery/key/queue inspection
ran. Initial e023749 evidence remains failed and immutable; 8x0.2.48 stays open.

### PR61 reviewed corrections and boundaries

The PR61 reviewer reported canonical integer strings for mailbox `messages` and
`vsize`, null DMARC `record`/`error`, and quoted PTR `ip_version` with null `error`
on `11.138.0.19` through WHM. These are separate reviewer observations, not a new
committed capture. Only those integer leaves normalize canonical bounded values;
other fields retain strict types. Matching DKIM/SPF `error` and SPF `ip_version`
compatibility follows the shared source family; those variants have no independent
live observation here. Omission stays distinct from null.

The reviewed `Cpanel/Email/UserForward.pm` alias branch supports a scalar string,
while `.forward` supports a string list. `forward_to` accepts either, preserving
exact empty strings without interpreting their meaning. Nonempty strings are
source-derived support, not new live evidence. Both historical capture files stay
byte-identical; the original failed finding remains unwaived.

Typed `applyDmarc(policies, domains)` and `removeDmarc(domains)` require explicit
targets. Client policy rejects blank strings/list entries and trimmed `0` before
I/O, because falsey omission can select every server domain. This is scope safety,
not DNS validation. Native missing/null argument failures occur before metadata
reset; policy errors clear metadata before dispatch. To deliberately select all
domains, use the generic default-POST `whm()->call('remove_dmarc', [])` or
`whm()->call('apply_dmarc', ['policy' => $policy])`. Those explicit generic calls
bypass typed validation and return unvalidated payloads.

`unblockIncomingEmailFromCountry(countryCodes)` now requires an explicit country
selection as client policy. `setUserEmailForwardDestination(user, forwardTo)` is
user-first; recipient-count methods put `user` first where present and `startTime`
before `endTime`. The Exim check's absent/null success payload is accepted based
on the pinned error-only message description; healthy/repair POST behavior is
source-only and unobserved here. Present Exim payloads remain strictly validated.

`enableDkim()`, `applyDmarc()` and `installSpfRecords()` retain the Flag outcome
policy. On API result `0`, the exception retains metadata but loses per-domain
`data`; pinned docs do not define partial-failure result semantics. Re-read domain
state before further action and never retry automatically. No new live request,
mutation, capture, waiver or outcome handling was introduced by these corrections.
