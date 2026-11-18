# WHM interface customization and scoped data

> Agent contract notes for `whm()->customizations()`, `whm()->userData()`, `whm()->inProductSurvey()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM interface customization and scoped data

`whm()->customizations()`, `whm()->userData()` and `whm()->inProductSurvey()`
provide five methods. `retrieveCustomizations()` uses GET; the other four use
form POST as client policy following pinned `x-readonly=false`. Customizations
requires cPanel 100 and Jupiter; scoped data and survey functions require 86.
Application and theme remain exact supplied strings.

`retrieveCustomizations()` preserves present data as opaque `mixed`, without
inventing a branding field schema. The pinned response omits a data contract.
[Review 5721307565](https://github.com/hostingpal/whm-api-client/pull/70#pullrequestreview-5721307565)
on `11.138.0.19` reports the raw retrieval map and the old typed void result;
this is reviewer runtime evidence, with no new local call or capture. Explicit
null and other JSON variants are compatibility at the opaque boundary, not
universal observations. Absent data still raises `PayloadMismatchException`.

`deleteCustomizations(application, theme, path)` requires an explicit nonnullable
selective dot path and still returns void. Reviewer-inspected
`Whostmgr/API/1/Customizations.pm` and `Whostmgr/Customizations.pm` show that omitted
path, `''` and `'0'` select the entire default customization section. The existing
client guard rejects trimmed blanks and trimmed zero before HTTP; rejecting
whitespace variants is additional conservative policy. Valid paths are forwarded
without trimming. Missing/null arguments fail before the group callback and
retain prior metadata; runner guard failures clear it. Deliberate whole-section
deletion remains available through generic form POST. Deletion has no rollback.

Scoped data belongs to root for any WHM caller, according to reviewer-inspected
`UserData.pm`. `getScopedUserdata()` creates scope directories under
`/var/cpanel/userdata/root/scope/` when absent, so it is not a pure read. Its
pinned string-valued map remains strict; the reviewer separately observed typed
and raw reads of existing `inproductsurvey`, including digit-string `start_time`.
`setScopedUserdata(scope, key, value, json)` returns an object map with opaque
values: inspected source assigns arbitrary JSON and returns the in-memory map
after saving. This is a source-based write correction, not observed execution.
Null/missing roots, scalars and nonempty root lists remain invalid; existing
empty-container compatibility remains.

Scope, key, value and pre-serialized JSON arguments stay sensitive strings. JSON
text is form-encoded once; no JSON request body or PHP object input is added.
The client forwards exact supplied strings without new cross-field validation.
Empty key, value or json, missing write input and combining json with key/value
raise upstream API errors. Zero is forwarded without inferring server acceptance.

Survey `display` and `new_user` retain exact native/quoted 0|1 normalization.
Only `link`, `max_dismiss` and `submit_event` additionally preserve null, omission
and exact strings. Reviewer-inspected `InProductSurvey.pm` and
`Cpanel/InProductSurvey.pm` plus undefined configuration support those nulls;
no survey call was made. The function saves `start_time` and a generated `uuid`
in root's `inproductsurvey` scope, and `last_survey_link` only when a link exists.
Other fields remain strict; URLs are private payload data and are never fetched.

`update_customizations` remains excluded: required `data` is application/json
content in a query parameter, not a JSON request body. Its serialization is
tracked under `8x0.1.22`, with field-JSON design under `8x0.29`.
All five methods have offline contracts and issue one request without retries,
rollback or interface-rendering guarantees. The three mutating methods remain
unobserved under `8x0.2.58`; reviewer reads do not establish corrected-head live
acceptance or other scope/application/theme variants.
