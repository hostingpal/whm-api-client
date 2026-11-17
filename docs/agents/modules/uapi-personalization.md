# UAPI Personalization

> Agent contract notes for `uapi()->personalization()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->personalization()->get(array $query)` is a required whole JSON object
body on direct UAPI only (cPanel 74+), with optional nonnullable `names: list<string>`.
Preserve `{}` versus `{"names":[]}`, empty strings, duplicates, order and bytes;
add no NVData byte/format checks or server-default inference. The whole input is
sensitive. WHM bridge refusal and structural rejection clear metadata before I/O;
native argument failures retain pre-entry behavior. Keep the formal optional
`personalization` map, optional reason:string, success:integer 0|1 and nullable mixed value;
row success 0 under envelope status 1 is data. Preserve omission/null and inherited
empty-array object/map compatibility. Do not log private names/values/reasons or
mismatch payloads. The earlier failed protocol probe establishes only structural
wrapper observations: its parser lost actual API outcome and row identity.
No API/typed outcome follows from that original probe. A separate bounded typed
capture through clean committed 646612249d9b86dc19ed17dea0b7babf15b3c516 on
11.138.0.19 passed exactly two requests (WHM version GET, direct getter JSON POST),
both HTTP 200/API status 1. The exact two fresh requested rows had value:null and
both row success flags 1; the bare data.personalization wrapper supports the formal schema.
This is custom developer evidence, not schema-2 catalog or strict unknown-field
acceptance. Review 5728223669 on 11.138.0.19 observed stored float, boolean, list and
object values read back as those JSON types (integer 5 as "5"); value is therefore
nullable mixed, so one non-string name cannot fail the whole call. It also saw API
errors for omitted/empty names and row success 0 for an empty-string name. Keep other
versions, setter and persistence unverified. Original failed evidence remains
unchanged; source-audit records exact hashes. This group is 1/2; Personalization/set
remains excluded under 8x0.29.6. No implicit reads, retries, fallbacks, URL fetches,
writes or persistence claims are added.
