# UAPI Nova and Sitejet site builders

> Agent contract notes for `nova()`, `sitejet()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## Nova and Sitejet site builders

`uapi()->nova()` covers eleven pinned functions and `uapi()->sitejet()` twelve
nondeprecated functions: six GETs and seventeen conservative form POSTs. Missing
`x-readonly` is a source fact; choosing POST is client policy. The two deprecated
Sitejet template operations remain excluded. Pinned availability is cPanel 110+,
except Sitejet restore-point/restoration at 120+; Nova requires AI App Builder.
Only nine Nova descriptions say disabling that feature disables the operation;
`accountExists()` and `getTier()` retain the feature requirement alone.

[Reviewer 5721328890](https://github.com/hostingpal/whm-api-client/pull/69#pullrequestreview-5721328890)
on `11.138.0.19` reports all six Nova reads they called failing on both routes
because `Cpanel::API::Nova` could not load, and its file was absent. All eleven
methods are unavailable for verification there. Write failures are expected from
the missing module but were not executed; every Nova response, including overlay
repairs, remains unobserved.

`createWebsite()` preserves a native integer ID or the pinned object with optional
integer `websiteID`. The reviewer inspected `Cpanel/API/Sitejet.pm` and
`Cpanel/Sitejet/Connector.pm`: Connector returns `$ret->{websiteId}` and the handler
sets data to that bare ID. This is a source-based container correction; remote
creation and its scalar type were not observed. The bounded integer type comes
from pinned `websiteID` and inventory `websiteId`, without string, null or float
coercion. `websiteId` is the request parameter for `getPreviewUrl()` (which returns
a string) and an inventory metadata key. Contact details, tokens, signatures,
credential URLs and metadata remain sensitive. Metadata is caller-supplied JSON
text encoded once as a form field; the client does not decode it or fetch URLs.

Both publication `cleanup` arguments are optional nullable booleans: omit/null
omits the field, false sends 0 and true sends 1, deleting previous document-root
files. Nova pins default 0. Sitejet pins no default; the reviewer inspected
`get('cleanup') || 0`, making omission zero on that server version. Remote
publication was not executed. Strict PHP callers reject non-null, nonboolean cleanup before
I/O; this follows the existing API surface without stronger weak-call guarantees.

`createRestorePoint()` is destructive and is not an automatically reversible
backup. The reviewer inferred deletion from `_clean_up(document_root, exclusions)`
in server source; live deletion was not witnessed. They observed creation success
and `restoreDocumentRoot()` refusal on both routes for a never-published domain
without its `<domain>-files` tracking file. The archive remains at
`~/<domain>_<time>.gz`, requiring manual recovery. Successful restoration remains
unobserved. Calls make one request without automatic retry, polling or undo;
a confirmed write with an invalid payload still throws `PayloadMismatchException`
with its explicit payload and metadata. Do not retry site creation after that
confirmed outcome.

The reviewer separately reports `getAllUserSitejetInfo()` with and without domain
passing quota/status and empty metadata, plus `canCreateDomains()`,
`isPublishInProgress()` and `getPreviewUrl()` on both routes. Published metadata
remains unobserved. Local writes on disposable `wrvsite` returned null for
`createRestorePoint()`/`addApiToken()` and a string for `getApiToken()` after a valid
stored token; missing-token and foreign-domain cases raised API errors. They
revoked the temporary token and confirmed account/home removal. These attributed
observations establish no unreported HTTP method, route variant or private value.
`addApiToken()` malformed input exposed a generic failure/Error ID; inspected
source uses `/^[a-z0-9]{32}\z/` and logs specific Invalid token text server-side.
No local token validator or log access is added.

The historical five-GET capability capture remains failed and immutable: version
and both Sitejet probes pass, both Nova probes are API failures with payload
`not_checked`; zero schema discrepancies do not waive those failures. The catalog
and recipe are unchanged. No new capture or corrected-head live acceptance ran.
Remote `createAccount()`, `createWebsite()`, `getSsoLink()` and `startPublish()`,
successful restoration, Nova and published variants remain open under
`8x0.2.57`.
