# UAPI DomainInfo, Features and LangPHP

> Agent contract notes for `domainInfo()`, `features()`, `langPhp()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

DomainInfo (5), Features (5), and LangPHP (11) cover their complete pinned modules.
Domain details preserve list/hash alternatives, documented quoted flag variants
and exact empty-string userdirprotect values without mapping them to 0.
PHP vhost versions preserve list/single-record variants. INI writes and PHP version
changes use POST; INI content/directive inputs are sensitive. The content setter keeps
plain string PHPDoc for its pinned host/home enum-versus-prose discrepancy; on
11.138.0.19 home was accepted and host refused through both routes. The client
form-encodes supplied content once; the server entity-decodes it, prepends a comment
block, and the getter returns the file entity-encoded. Features/has_feature
returns nullable opaque data or an observed integer of unspecified meaning and
uses UAPI status as the feature result; inspect
responseMeta()->uapiStatus(), while retaining outer WHM failure checks. Response
arguments in EnvelopeReader and OperationRunner remain SensitiveParameter because
raw or decoded responses may contain secrets. Overlay parameter requiredness can
be overridden only with evidence; omission retains the pinned requiredness.
The 2026-10-30 reviewed integrated DomainInfo/Features/LangPHP artifact at 27abfe6
on 11.138.0.19 records all 67 GET-only API/payload probes passing, including 20 new
reads and normalized countPops() through both routes. Its strict review failed:
12 unknown-field entries across 10 observations, zero acknowledgments and two stale
11.138.0.15 Quota acknowledgments. No mutation ran and all historical artifacts stay
unchanged. The two Quota acknowledgments now bind the 2026-11-01 artifact at 5f9b001
on 11.138.0.19. That capture ran with those DomainInfo and INI fields typed; the
fields of unknown meaning were later relaxed to optional `mixed`, so its contract
hashes for those operations differ from the current specs. Of those eight fields only
list-form `customlog[].format` stays validated: `main_domain.phpopenbasedirprotect` is
also `mixed` because domains_data does not promise it, while the pinned
single_domain_data flag and other documented fields keep their validation.
The main domain omits userdirprotect; the 2026-11-02 domain-rows capture observed
the exact empty string on addon and subdomain rows in both domains_data forms
and in their single-domain results. The 2026-11-02 disposable-account capture at c4a124a on
11.138.0.19 passed the three PHP setters and the home INI write/read round trip
through both routes. Vhost-scope INI, FPM and impacted-domain variants and PHP
runtime behavior remain unverified under `whm-api-client-2dj`; the vhost-versions
root-union item stays open under `8x0.4.11.1`.
