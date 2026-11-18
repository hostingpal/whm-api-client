# WHM support-ticket administration

> Agent contract notes for `whm()->supportTickets()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM support-ticket administration

`whm()->supportTickets()` covers all fourteen pinned functions: four GET reads
classified `x-readonly=true` and ten form POST operations. Required integer server
and ticket selectors remain required, including for `list()`. Grant/list SSH
usernames can be omitted; revoke requires one. Omission does not infer root.
Grant secure IDs, OAuth codes and redirect URIs remain sensitive.

The source auths/servers list repair follows explicit array prose and item schemas.
Support-agreement dates preserve optional null values; flags remain integer 0|1.
Reviewed source-family compatibility also permits null on optional support-contact
fields and on the conditional nested grant timestamp for `NOT_AUTHED` rows.
Those nulls are inferred from source prose, not explicit pinned nullable flags or
live payload observations. Omission, null and exact empty strings stay distinct.
Grant/revoke echoed server and ticket IDs use the existing bounded canonical
integer-string normalization, with native integers preserved and invalid strings
rejected. Other IDs, timestamps, strict siblings and input types are unchanged.

Agreement acceptance and OAuth validation have source-documented void results.
SSH-test startup returns null for successful absent/null data and preserves a
present opaque object, including unknown nested fields; malformed present values
still fail after API success. This compatibility remains source-inferred and
offline-tested. A payload error can occur after state changed: never retry a
mutation automatically. Required fields and API outcome checks remain enforced.

The PR65 reviewer reports `getSupportAgreement()`, `getSupportInfo()`,
`whitelistCheck()` and `list()` through WHM GET on `11.138.0.19` with this client's
stateless token authentication all raising `ApiErrorException`:
"The system failed to restore the saved session." These failed reads establish no
successful payload compatibility. The pinned URL samples use `/cpsess##########/`
and the OAuth source says the validated token is stored on the current session.
The [final reviewer source trace 5722449280](https://github.com/hostingpal/whm-api-client/pull/65#pullrequestreview-5722449280)
on `11.138.0.19` establishes that `Whostmgr::TicketSupport::Token->new` calls
`Cpanel::Session::Restore::restoreSession()` and fails with the recorded error
when no session exists. `Whostmgr/TicketSupport.pm:31` builds remote Customer
Portal calls through that object; `ticket_validate_oauth2_code` directly calls
`Token->new()->fetch($args)`. Thus `validateOauth2Code()` cannot succeed
statelessly. This is an attributed reviewer source trace, not code inspected in
the locally pinned documentation. It applies to paths reaching the Customer
Portal; no session requirement is established for local-only methods.

The client has no session or cookie handling. All fourteen methods have no
observed success path and all ten POST methods remain unobserved. This carries
forward the documentation correction `whm-api-client-m6p`; session compatibility,
all success payloads and remaining live variants stay under `8x0.2.53`.

Stub-ticket creation, access grants/revocations, firewall changes, remote SSH
checks, OAuth exchange/session state and agreement acceptance can have external
or server effects. Returned support text, tokens, links and commands are payload
data and never executed or fetched. API success does not establish remote SSH
access or security enforcement. Exact per-method version floors remain in PHPDoc.
All fourteen methods have independent offline contracts; generated coverage,
offline validation and the separate reviewer report remain distinct.
