# UI and application discovery

> Agent contract notes for Chrome, Parser, Integration, InProductSurvey and Plugins modules. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## UI and application discovery (2026-11-01)

The five-operation delivery `whm-api-client-8x0.4.35` adds complete Chrome,
Parser, Integration and InProductSurvey modules and one of two Plugins operations.
`chrome()->getDom(pageTitle)` and `parser()->getFirstFileRelativeUri()` use GET
from cPanel 11.42, matching source x-readonly:true. Chrome sends required
page_title once in the query and returns optional exact footer/header strings,
including arbitrary HTML/scripts/line breaks/empty values. Title input is not an
HTML safety contract. Parser's method/prose says cPanel-base relative, summary
session relative, but formal uri field/example says absolute server path
/usr/local/cpanel/base/favicon.ico. Preserve this contradiction and unchanged
bytes without base resolution, normalization or local file access.

`integration()->fetchUrl(app)` (cPanel 54) and
`inProductSurvey()->getInProductSurveyUrl()` (literal 11.32) use form POST because
source GET declares x-readonly:false/x-rollback:none. app is required and body
encoded once; Survey has no parameters and sends an empty POST body. Integration
preserves optional string redirect_url (primary) and url (fallback) separately,
without URL validation, navigation, fetch or fallback. All three required string
selectors, including Chrome title and Plugins plugin, retain empty, whitespace,
Unicode, percent/ampersand/plus bytes without local validation or normalization.

Survey retains seven optional fields. display/new_user use existing generator
flag-enum normalization of exact quoted 0/1 and native integers to int 0|1;
other strings, bools, floats and null fail. This is not an overlay response
correction or live observation. max_dismiss, link and submit_event are nullable
strings (null when no survey is configured, review 5726184111); server_type and
user_type stay strings; general string enums remain open. Parser uri is nullable:
outside a template it is null (direct) or a meaningless value such as [stdin]. Source
display 0 means do not render the banner, compared as normalized integer 0.
Source webmail versus cPanel/Team link templates establish no added authentication
mode/session route or tested webmail credentials/session coverage.

`plugins()->canShowPromotions(plugin)` requires the Plugins plugin and pinned
integer version 110. Source summary says Enable while description/result describe
visibility without an enabled argument. Source readonly/rollback are absent;
risk remains UNKNOWN and rollback is not established. Existing default selects
form POST despite source GET; do not claim read-only safety or known enabling
mutation. Formal plugin is required although CLI/URL samples omit it; only koality
is documented. Keep an open string signature without claiming server acceptance
of alternatives. can_show_promotions is strict native int 0|1; quoted flags,
bools, floats and null reject. Payload 0 under status 1 is normal; status 0 and
outer WHM failures still throw. Plugins/create_user stays absent and uncounted
under source/input gate 8x0.1.22; no new exclusion/waiver.

Every root is a required nonnull optional-field object. Preserve omission,
known-object filtering and inherited empty-array object compatibility (8x0.2.50).
Selectors are lazy/cached/account-bound without I/O or reset; each invocation
makes one immediate request with ordinary Flag outcome and plain payload.
No implicit rendering, evaluation, URL navigation/fetch/fallback, local file
access, extra request, cache of server data, retry or rollback. Returned content,
paths, URLs, metadata and mismatch payloads can be private; library-owned response
frames retain SensitiveParameter protection without third-party/caller secrecy
claims. Inspect warnings/raw metadata, including after mismatches; native pre-entry
TypeErrors retain prior metadata. Synthetic fixtures establish no live server
acceptance or UI/session/plugin/survey behavior. Live follow-up 8x0.2.77 stays OPEN.

Coverage is 1,219 canonical operations (631 UAPI, 588 WHM), 115 groups and 233
outputs (232 PHP plus coverage). Original sources/evidence, earlier operations
and shared generator/engine behavior stay unchanged. PHP 8.3 remains external CI;
local verification uses PHP 8.4.26.
