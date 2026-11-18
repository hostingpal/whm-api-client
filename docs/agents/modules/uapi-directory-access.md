# UAPI directory access

> Agent contract notes for DirectoryIndexes, DirectoryPrivacy, DirectoryProtection. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The directory groups cover all ten pinned functions, available from cPanel 88.
Six reads use GET and four mutations use form POST. Directory paths are forwarded
unchanged. Only `DirectoryProtection/list_directories` documents paths relative to
the account home directory. `configureDirectoryProtection()` documents absolute
or relative paths without defining the relative base; `isDirectoryProtected()`
documents absolute paths. The [PR #34 reviewer](https://github.com/hostingpal/whm-api-client/pull/34#pullrequestreview-5717732052)
independently observed `public_html/...` relative paths working for both privacy
methods through direct UAPI and the WHM bridge.
Password-protection state belongs to DirectoryPrivacy; DirectoryProtection reports
leech protection. A successful unprotected-directory result has payload flag 0;
that does not make UAPI status 0 a success.

`configureDirectoryProtection(dir, enabled, authname)` can omit `authname` when
disabling protection, following the source's conditional requirement. Supply a
realm name when enabling it. `addUser()` keeps its password sensitive throughout
the request. User mutations preserve nullable opaque results and reject an absent
payload. Directory fields retain optional presence; only `auth_name` and
`auth_type` in the configure response additionally accept explicit null, based on
the reviewer's successful disable calls and the committed 2026-11-02 disables on
both routes below. Read-operation field types are unchanged.

The 2026-10-30 directory-access capture at `e3aa06b` on `11.138.0.19`: All 13 GET observations passed HTTP, API, payload and probe checks, with zero discrepancies and a passed strict assessment.
Directory records were present, but every captured `auth_name` and `passwd_file`
was an empty string, and the privacy-user list was empty. This capture made no
indexing, password-protection or leech-protection changes and establishes no
mutation or HTTP-enforcement coverage. Separate reviewer mutation observations
are recorded in the source audit; they are not part of this artifact. Follow-up `8x0.2.22` remains open.

The committed [2026-11-02 disposable-account capture](../../api/evidence/2026-11-02-mime-directory.json)
at `965b329` on `11.138.0.19` runs all four directory mutations through direct UAPI
and the WHM bridge on a fresh `wlmime` directory, each read back on its own route,
with zero discrepancies and populated protection and user states. Leech protection
has no UAPI mutation and was only read: through direct UAPI before the changes and
through the bridge after them. HTTP indexing, authentication and leech enforcement
remain unverified.
