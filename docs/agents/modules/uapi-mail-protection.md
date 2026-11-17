# UAPI mail protection

> Agent contract notes for BoxTrapper, EmailAuth, SpamAssassin, cPGreyList. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The mail-protection section covers BoxTrapper25, EmailAuth12, SpamAssassin4 and
cPGreyList6: nineteen reads and twenty-eight form POST operations. Keep per-item
message/domain results separate from the envelope. BoxTrapper requires Receive
Mail; mailbox arguments and Webmail defaults follow each method's source. Its
rule/template inputs and EmailAuth PEM arguments remain sensitive. Repeated
queuefiles, domains, keys, records and rule fields follow documented parameter
examples/prose; source inconsistencies stay explicit in overlay notes.
SpamAssassin custom preference fields use an opt-in object values spec: declared
fields remain strictly typed, while undeclared keys are preserved under the
values child. Read and update custom values are mixed because both return the
complete preference object; declared fields remain strict. required_score list
items preserve observed numeric strings alongside native integers and floats.
The malformed literal additionalProperties source node is not a returned field.
Fingerprint every dynamic key as a wildcard, even on type failure. Default object
specs still discard unreviewed fields. updateUserPreference value accepts a scalar
string or nonempty sequential string list encoded as value-0/value-1. Null/omission
still removes settings; reject [] before HTTP. Preserve string items, empty strings,
zero, order and duplicates. Do not infer filtering enforcement
from API results or execute message delivery/Spam Box clearing as routine tests.
Review 5722987368 of ec0796271da96827835bb26d8fb38091cb882e94
(https://github.com/hostingpal/whm-api-client/pull/76#pullrequestreview-5722987368)
reports direct/bridged preference list writes on 11.138.0.19 preserving three
ordered values including a duplicate, scalar/singleton writes and deletion by
omission. Empty/sparse lists and integer items failed locally before dispatch.
Record these as observed under 8x0.2.23.1, separately from the failed/unwaived
historical required_score capture. No filtering or delivery proof is implied.
The corrected mail-protection read catalog at a063db0 on 11.138.0.19 passes all
19 API/payload checks, while strict status retains two PTR nested unknown-field
observations under 8x0.2.23. Only evidenced EmailAuth nullable fields, PTR integer
strings and precision-preserving SpamAssassin scores gained compatibility.
The final BoxTrapper capture at 3ea2166 passes all 45 observations (31 GET,
14 POST) with zero discrepancies and both fresh mailboxes confirmed cleaned.
It observes 14 BoxTrapper functions through both routes while protection stays
disabled. queue_days and log date accept canonical integer strings only where
explicitly corrected; direct-only observed log date strings do not establish
that variant on the bridge. Empty logs/queues prove no message-row schema or
filtering/delivery behavior. Initial captures cover 22/47 functions;
remaining operations/variants stay in 8x0.2.23. Preserve all earlier captures.
The correction capture at 6f3e0cb adds direct preference mutation execution
(23/47 combined) but stops at 26 observations: all HTTP/API/payload pass, removal
reply expectation fails on empty-list versus original omission. Both restoration
reads and final catalog reads match the original complete state. Keep the artifact
failed, direct restoration uncertain and bridge not_started; no mutation retry or
bridge write occurred. Review 8x0.2.23.1 before more mutations. PTR findings remain
strict failures and unwaived; historical evidence is immutable.
The subsequent fixture-only correction permits the observed empty-list removal
reply for original absence; getter equality and lost-outcome rules remain strict.
Claude review 5719375767 approved exact d499ab6 and independently verified both
routes on 11.138.0.19, restoring user_prefs byte-identically. PR36 merged as
52af23a with green CI and the same tree. No further capture is required; keep
8x0.2.23.1 open for future committed both-route evidence, and never rerun just
to clear the original artifact.
