# UAPI account environment and preferences

> Agent contract notes for ContactInformation, Contactus, Locale, Themes, Notifications, AccountEnhancements, EA4, ServerInformation, Variables. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The account environment and preference slice exposes twenty compatible methods
across ContactInformation, Contactus, Locale, Themes, Notifications,
AccountEnhancements, EA4, ServerInformation and Variables. Fourteen reads use GET;
six secret-bearing or state-changing operations use form POST. The JSON-body
`ContactInformation/set_notification_preferences` operation remains excluded under
`8x0.1.18`, and the deprecated theme-base operation remains excluded.

Contact addresses use documented repeated form fields. `setEmailAddresses()`
permits omitting `oldAddresses` when the account has no current contact addresses;
with existing addresses, omission still fails the server's old-address check.
`unsetEmailAddresses()` requires the complete old-address list. New-address and
password inputs retain their source requiredness; passwords and Pushbullet tokens
are sensitive parameters. The token getter also uses POST and returns sensitive
payload data. Contact mutations with no documented data return void; locale
setting preserves its present unspecified payload, while theme update preserves
nullable opaque objects and rejects an absent payload.

Theme options preserve omission and explicit false. Enhancement availability data
is separate from UAPI status: a successful data value of zero is not an API error.
The enhancement-list source omits its container type and has inconsistent object
prose/items structure, so the result remains mixed rather than acquiring an
invented list contract. Notification preference rows retain the suspicious literal
`additionalProperties` source property without inferring a dynamic-field schema.

Variable reads preserve optional fields and nullable values.
`variables()->getUserInformation(name: ...)` and `getServerInformation(name: ...)`
accept one string or a nonempty list encoded as repeated `name` keys. Omission
retains the server's all-field behavior; `getSessionInformation()` keeps a scalar
selector. Source prose/examples establish dynamic feature-flag and domain/MX maps
where the formal schema misplaced `additionalProperties`. Server/session/user
fields and opaque values are returned as data; recommendations do not install
packages or change configuration.

Every user-information integer field, including integer alternatives and feature
flag values, accepts canonical lossless integer strings under the reviewed family
rule; flags accept only exact quoted `0`/`1`. Native integers and omission remain
unchanged. Existing string alternatives remain available, including noncanonical
and out-of-range strings in `maximum_*` fields; no new closed enum is enforced.
This family combines reviewer observations with source-based inference, without
claiming that every field was observed. `contact_email` and `contact_email_2` also
preserve explicit null, separately from empty strings and omitted keys.

The 32 observed server flags accept exact quoted `0`/`1`; eight integer quantities
accept canonical lossless integer strings. Six quantities were observed with
numeric values. Compatibility for `file_upload_maximum_bytes` and
`minimum_password_strength_mysql` is inferred from the reviewer-inspected shared
configuration-copy path; those two fields were seen only as null. Optional fields
and null remain distinct. Session host additionally accepts null, observed through
the bridge. Only outer EA4 recommendation URLs accept `bool|string`; option URLs
stay strings. Boolean URL values are payload data with no inferred sentinel
meaning. Unknown fields remain untyped, and failed EA4 calls do not establish a
successful response contract.

The live catalog selects twelve parameterless reads and the user-domain read
`getUserInformation(name: 'domain')` through both routes, plus WHM version (27 GETs).
The user selector requests only the documented domain field; the unfiltered user
read can include `pushbullet_access_token` and is excluded from this catalog.
Enhancement lookup by ID and token retrieval are also excluded. No contact, locale,
theme or preference settings change. Other user-field live coverage, successful
payload variants and mutations remain tracked under `8x0.2.28`; structural captures
never persist tokens, addresses or server/session/user values.

The corrected account-environment capture at `c85a39e` on `11.138.0.19` passes
24 of 27 GET API/payload/probe checks, with zero known-field type mismatches.
Two EA4 API failures, one EA4 bridge failure and five masked unknown-field
observations keep strict status failed and remain unwaived under `8x0.2.28`.
Eleven functions pass on both routes and general EA4 recommendations pass directly.
No mutation or secret retrieval ran; user information remains domain-only.

The final [reviewed user-information capture](../../api/evidence/2026-10-31-account-environment-reviewed.json)
at `c4576669980a1ab22952f96b976a34bf0db7be58` on `11.138.0.19` passes all three
GET observations: WHM version and the fixed 60-field user selector on both routes.
All HTTP/API/payload/probe checks and strict assessment pass with zero discrepancies.
The token selector and seven pinned fields absent on `npr` are excluded; no wire
form is established for those seven. No mutation or token retrieval ran. Earlier
EA4 failures and masked findings remain immutable and unwaived under `8x0.2.28`.
Reviewer mutation/lifecycle reports remain separate from this committed read capture.

Separate [Claude reviewer evidence](https://github.com/hostingpal/whm-api-client/pull/41#pullrequestreview-5720241923)
on cPanel `11.138.0.19` reproduces `getPhpRecommendations()` API failures through
direct UAPI and the WHM bridge. Inspection of `Cpanel/API/EA4.pm` ties the failures
to the absence of `/etc/cpanel/ea4/recommendations/custom_php_recommendation.json`.
`ApiErrorException` is the correct outcome for this observed server state; the
failed captures remain failed and unwaived.
