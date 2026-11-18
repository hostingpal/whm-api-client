# UAPI Mime

> Agent contract notes for `uapi()->mime()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The Mime group covers all 14 pinned functions: six reads use GET and eight
mutations use form POST. No domain selector is documented for hotlink
configuration. MIME deletion targets the MIME type, while handler deletion
targets extensions. Redirect filtering, defaults and source/destination behavior
follow each method's pinned PHPDoc. These calls do not test HTTP redirect delivery.

Mutation results generally preserve nullable opaque objects. The pinned
`add_handler` and `add_mime` schemas additionally declare a null-only enum; the
current effective validator keeps their nullable object boundary without enforcing
that enum. Missing payloads still fail. `deleteHotlink()` has an unspecified data
schema, so its payload remains mixed rather than acquiring invented fields.

The corrected 2026-10-30 Mime capture at `fdbafb6` on `11.138.0.19` records
13 GET observations passing HTTP, API, payload and probe checks with zero
discrepancies and a passed strict assessment. The observed null URL fields are
preserved. MIME and redirect lists were empty; handler rows were populated.
Separate [Claude reviewer evidence](https://github.com/hostingpal/whm-api-client/pull/35#pullrequestreview-5717739276)
at `933e4de` reports all eight mutations and populated MIME, handler and redirect
rows passing on `npr` through both routes, with the original `.htaccess` SHA-256
restored. Keep those reviewer observations separate from the committed read-only
captures. The committed [2026-11-02 disposable-account capture](../../api/evidence/2026-11-02-mime-directory.json)
at `965b329` on `11.138.0.19` runs all eight mutations through both routes on `wlmime`,
each read back on its own route, with populated MIME, handler and redirect rows, null
mutation data and zero discrepancies. HTTP redirect delivery and hotlink enforcement
remain unverified.
Only `get_redirect.url` and `list_hotlinks.redirect_url` gained evidenced nullable
compatibility. Keep initial `9262564` failures unchanged, null distinct from
omission/string, and follow-up `8x0.2.21` open.
