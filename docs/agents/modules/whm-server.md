# WHM server administration

> Agent contract notes for `whm()->server()`, configuration upload, reboot status. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### Expanded WHM server administration

`whm()->server()` now exposes 78 of the 79 pinned Server Administration functions.
The original 72 additions for services, profiles, updates, configuration, linked
nodes, notifications, licensing and inventory use 23 GET requests and 49 form POST
requests. Configuration upload and reboot status add two further form/body POST
methods. The four original server methods retain their contracts.
Missing read-only declarations remain conservative POST calls, including the
three namespaced EcosystemFeatures functions.

JSON connected-application saving remains under `8x0.1.18`. Configuration upload
is described below. `systemNeedsReboot()` returns the evidence-corrected ordinary
`data` payload; generic calls retain their existing data-only extraction.
Connected-application reads preserve declared typed fields and opaque
additional properties through the existing object-values mechanism. Treat their
results and service/configuration values as private payload data.

Profile activation accepts documented serialized JSON text in its optional role
argument, with one form-encoding pass. Its native `?string` argument accepts
caller-serialized text, with null omission and no automatic object encoding or
JSON syntax validation. Available-tier
maps preserve the five pinned named fields alongside dynamic values. Both
service-configuration reads document `dovecot`; undocumented services are outside
the contract, and null data from such a service can fail validation. Configuration
values can contain credential material, including the observed key name
`hulk_auth_passwd`; neither the review nor committed evidence records its value.
Treat returned values and raw payloads attached to exceptions as sensitive.
The full configuration and individual-key reads retain the same string, integer
or nested map values with opaque contents.

On `11.138.0.19`, the reviewer observed quoted role flags and digit strings
in both application-importance reads. Compatibility uses the established bounded
canonical-integer normalization policy; the observation does not establish every
accepted spelling or boundary. Strict flag values and integer bounds remain. The documented empty
node-linkage object is source-backed and offline-tested; populated linked-node
behavior remains unobserved. Per-method availability, role requirements,
omission, null and explicit false/zero behavior remain in generated PHPDoc.

For update preferences, `RPMUP` or `SARULESUP` set to `never` requires the
administrator to run `scripts/update-packages`; it does not make updates
unnecessary. Feature-management inputs retain the pinned single feature-ID
description without an unsupported comma-separated batch claim.

EULA acceptance, license purchase, reboot/restart, updates, profile activation,
node linking, configuration changes and notification tests are explicit operations.
They may have legal, financial, connectivity or service effects; the client adds
no automatic invocation, retry, rollback or completion polling. Credential, token,
configuration and notification inputs remain sensitive. API success or a job ID
does not establish completion, service health or remote connectivity.

Independent offline contracts cover all 72 additions and preserve the original
four. The bounded eight-GET catalog reads version, contact-event importance,
available/current profiles, password-strength policy, installed software versions,
service status and dashboard hostname. Optional selectors are omitted. It reads
no credentials, linked-node secrets, arbitrary configuration values or sockets,
and sends no notification, URL probe or mutation. Follow-up `8x0.2.38` retains
unobserved variants and operational behavior.

The corrected expanded WHM server capture at `742eaa8` on `11.138.0.19` passes
all eight HTTP/API/payload/probe checks. Ten evidenced integer leaves accept
canonical integer strings losslessly; siblings remain unchanged. Strict status
still fails for two masked unknown-field observations in installed versions and
service rows. They remain untyped and unwaived; no key identity/count is inferred.
No mutation or private configuration read ran. Historical artifacts remain
immutable and `8x0.2.38` retains remaining live coverage and unknown findings.

The WHM configuration-upload adoption adds exactly one canonical operation:
current coverage is 1,149 operations (554 UAPI, 595 WHM), 99 groups and 201
outputs. `whm()->server()->restoreConfigFromUpload(module, Upload)` uses the
separate evidence-bound `multipart_upload` descriptor `{php, source, text}`.
Its only reviewed shape is required `module` literal text followed by scalar
binary `file`, with no endpoint query parameters. Source schema requiredness
is explicit despite absent requestBody.required; do not globally change legacy
body requiredness. Both public values are sensitive. Preserve `Basic`/`Main`
PHPDoc/case and existing scalar runtime policy; do not add enum validation.

The WHM route keeps api.version=1 only in the URL, uses normal flag/metadata
handling and returns void. Available from cPanel 74; unchanged backups do not
rewrite configuration. This can affect server-wide configuration, with no retry,
rollback, transactional or restart guarantee. Empty JSON sample/port 2083 are
source inconsistencies. No live root restore or Fileman fixture restore ran.
The disjoint descriptor is mutually exclusive with multipart_uploads and JSON;
Fileman's required list, query placement and direct-only policy remain intact.
Four Backup binary methods are now typed; server-path modes remain in 8x0.30.9. Approved parent source and preparation-exception corrections are integrated;
PHP 8.3/native Windows and reliable-size metadata follow-ups remain separate. Upload factories, bounded
stream/source/transport primitives and pinned sources/evidence are unchanged.

Caller warnings follow PR88 review5724678599's source inspection on 11.138.0.19:
invalid settings retain old values and reject details are discarded, so success
requires configuration readback for the caller's settings of interest. An unchanged
restore may surface as an error; compiled-wrapper behavior remains unverified in
8x0.30.7.1. Source inspection indicates possible root-only uploaded-backup retention
under /root/tmp/Cpanel_Form_file.upload.*; restore-specific retention is unverified.
The reviewer's separate read-only file-part check is not restore evidence. Keep
one Upload: get_uploaded_files_ar chooses the first entry in hash order. No new
return schema, ACL, enum policy, cleanup, retry or restore execution is introduced.

### WHM reboot status and corrected ordinary data payload

`whm()->server()->systemNeedsReboot(): array` exposes the cPanel 64+ reboot-status
operation. It determines whether quotas, software package updates or kernel
updates require a reboot; upstream says detection cannot work inside LXC.
The pinned schema incorrectly puts `details` and `needs_reboot` beside `metadata`.
[External review5727236239](https://github.com/hostingpal/whm-api-client/pull/121#pullrequestreview-5727236239)
at HEAD `789dfb0` on `11.138.0.19` observed the ordinary `data` envelope on GET
and typed form POST (HTTP 200/API result 1), with no reboot and empty details.
The overlay corrects that exact pinned response root. The shared runtime uses
ordinary data extraction; no sibling fallback or projection descriptor exists.
Missing data and explicit null are mismatches. Omitted optional fields remain
omitted; `{data:{details:[],needs_reboot:0}}` retains that exact result shape.

Every result field is optional and nonnullable. `needs_reboot` accepts only
native integer `0|1`. `details.kernel` has optional string `boot_version` (the
version the kernel updated to) and `running_version` (the current version).
The kernel object appears only if the kernel updates and requires a reboot.
`quota` stays an ordinary integer under the existing singleton-enum policy;
its pinned note says it appears only if the kernel updates and requires a reboot.
`updates` preserves an opaque map of mixed values; its pinned note says it appears
only if software packages require updates. Existing empty-array object
compatibility remains unchanged. The same external review reports handler source
in `Whostmgr/API/1/ApplicationVersions.pm` assigning integer 1 to optional
`details.needs_cloudlinux` and `details.securetmp_size_update`; both remain `int`
under the existing singleton-integer policy. Populated kernel/quota/updates and
these two reasons are source-read/offline cases, not observed live conditions.

The documented variant/example uses GET, while `x-readonly: false` selects one
empty form POST to `/json-api/system_needs_reboot?api.version=1`. The external
review establishes POST acceptance only on `11.138.0.19`. Reviewer handler-source
observations also say the server schedules `recache_system_reboot_data` unless
the unpinned `no_cache_update` option is set; no typed argument is added. A failed
sub-check other than `Unsupported` sets API result 0 and raises the ordinary
`ApiErrorException`. `Unsupported` is skipped, so `needs_reboot: 0` does not prove
every check ran. The client adds no request, retry, polling or implicit read.

This correction uses attributed external evidence and offline synthetic fixtures;
it performs no new live capture. Other unobserved states remain under live
follow-up `8x0.2.87`. Current totals remain 1,235 canonical operations (636 UAPI,
599 WHM), 119 groups and 241 outputs. Source snapshots, historical evidence and
all 55 inherited diagnostics remain unchanged and unwaived.
