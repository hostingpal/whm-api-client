# UAPI SSL

> Agent contract notes for `uapi()->ssl()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->ssl()` exposes installed-host certificates, AutoSSL exclusions, problems,
renewal status and check progress, plus secure-redirect capability. Renewal status
requires cPanel 134 or later; `has_problems` also becomes 1 when the problems database
is unreachable, and `will_renew` depends on provider, certificate, exclusion and DCV
conditions. Flags remain `0|1` integers. Preserve optional and nullable certificate
fields and the pinned literal dotted issuer/subject keys. The certificate PEM and
upstream problem text are payload data; evidence keeps only their structural types.

The SSL module exposes all 49 nondeprecated functions. Typed fetch-key/cabundle,
upload-cert and upload-key calls use reviewed multipart string fields, configured
by an explicit body: multipart spec. Legacy call() remains form-only;
callMultipart() provides separate generic streamed file/text parts on WHM and direct UAPI only, without typed SSL adoption.
The separate callJson() entrypoints do not expand typed SSL coverage. Multipart names/values are
validated after metadata reset, all value-bearing frames are sensitive, routing
stays in the URL and a collision-free delimiter preserves field bytes. Generator
body_fields corrections require evidence and compatible duplicate schemas.
The upload_key key/crt source contradiction remains explicit; the key field was
accepted on both routes in the final owned fixture on 11.138.0.19.
The optional install_ssl domain_dcv_method map is omitted until its serialization
is established (8x0.2.16). SSL selector alternatives use evidenced required:false;
preserve opaque/null/missing payload distinctions and private PEM data. Only fresh
owned SSL fixture resources may be deleted in live verification; never install or
replace account certificates as part of that fixture.
The expanded owned SSL capture at `1f67a43` on `11.138.0.19` records all
31 HTTP/API/payload/probe/expectation passes (13 GET, 18 POST), ten SSL functions
on both routes plus WHM version, and six confirmed cleaned resources. It includes
all three multipart methods, generated keys and both certificate-information reads.
Strict status remains failed for six masked unknown-field observations in
upload_cert/delete_key, including two added generated-key cleanup observations.
All six match prior operation/variant/route/method/version/source/effective-contract,
full payload paths and discrepancy tuples; masking cannot establish unknown key
identities/counts. Preserve every finding under `8x0.2.19`; no waiver or new typed
unknown field is added. No TLS serving, installation, trust, AutoSSL issuance or
renewal behavior is established.
SSL response compatibility applies reviewed field rules consistently to all
21 operations declaring 66 affected certificate/key/CSR fields. Preserve integer
normalization, exact 0/1 string flags and reviewed issuer organization nullability.
Family inference is separate from operation-specific live evidence. getCabundle
uses form POST for PEM input; installSsl certificate/bundle inputs are sensitive,
and generation refuses binary multipart file fields. Generated ownership requires
the original successful validated response, a fresh unique ID, matching PEM/public
modulus and preflight absence. Lost/malformed generation stays uncertain; never
infer ownership from later inventories or retry.
The earlier `d159935` capture remains failed at 27 responses, with four uploaded
resources cleaned and generated keys not_started. Its observed null cab,
validation_type and verify_error support only the three reviewed nullable fields
on fetch_certificates_for_fqdns, preserving optional string|null contracts.
validation_type also follows explicit pinned null prose. Sibling endpoints are
unchanged. The corrected code at `1f67a43` was explicitly cleared for one capture;
the resulting `2026-10-30-ssl-section-expanded-final.json` is separate immutable
evidence. Follow-ups `8x0.4.11.3.3`/`8x0.4.11.3.4` retain final external acceptance,
and `8x0.2.16` retains the omitted install_ssl DCV map.
