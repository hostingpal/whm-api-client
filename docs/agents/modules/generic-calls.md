# Generic call entrypoints

> Agent contract notes for `call()`, `callJson()`, `callMultipart()` scope and typed JSON/multipart adoption counts. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`whm()->call()` and `uapi()->call()` provide generic GET and form-encoded POST
access for operations compatible with the parameter representation, route and
envelope handling. These legacy call() methods do not support JSON or multipart
request bodies. Separate callJson() entrypoints provide raw JSON POST through WHM
and direct cPanel UAPI; bridged UAPI rejects JSON locally after metadata reset.
Separate callMultipart() entrypoints stream ordered text/file parts through WHM
and direct cPanel UAPI; generic bridged multipart rejects before source I/O. The
pinned inventory includes 13 JSON operations (8 WHM, 5 UAPI) and 9 multipart
operations (1 WHM, 8 UAPI). Five of thirteen JSON-body operations are typed:
StatsManager/save_configuration, Personalization/get and WHM php_fpm_config_get,
php_fpm_config_set and update_sql_config. The other eight JSON endpoints remain
deferred, with remaining selection/source/route audit under `whm-api-client-8x0.29.6`. Selected
implementations and the StatsManager successful-live-write gate remain open under
`whm-api-client-8x0.1.18` and `whm-api-client-8x0.1.19`. Generic access is not
typed or live-verified coverage. The 2026-10-13 spec's
[scaling decision (typed core plus generic calls)](../../superpowers/specs/2026-10-13-php-client-design.md#scaling-decision-typed-core-plus-generic-calls)
is superseded: its hand-written-group decision is replaced by the generated-client
design (bead `whm-api-client-8x0.10`).
