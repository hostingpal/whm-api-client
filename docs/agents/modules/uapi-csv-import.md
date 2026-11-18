# UAPI CSVImport

`uapi()->csvImport()->doImport(domain, id, type)` invokes the selected account's
prepared mailbox (`email`) or forwarder (`fwd`) batch in one form POST.
The pinned GET identity dates from cPanel 94 and declares a mutation without rollback.

On 11.138.0.24, cPanel API 2 `CSVImport/uploadimport` stages the file and supplies
its ID. `processdata` parses the delimiter and header, `loaddata` can load prepared
data, and `configimport` maps the columns and saves the selected rows. The UAPI
handler opens the account-local `~/tmp/cpcsvimport/<id>.import`. It requires the
`csvimport` feature, refuses demo mode and leaves the staged files in place.
The ID, raw CSV and serialized staging files can lead to mailbox passwords.
Per-row reasons and envelope errors may also identify mail addresses or internal paths.

The handler passes the staged email, password and quota and the supplied domain
to `Email/add_pop` for each mailbox row. For forwarding rows, it passes the staged
source and target and the supplied domain to the forwarder handler. It continues
after reported row failures. The source-bound `rows` policy
returns every validated nonempty batch on either UAPI status, including all-failed
batches. Inspect the envelope and each row. Reconcile inventory before retrying.
A header-only batch can succeed with an explicit null `results` key. A failed
batch with explicit null `data` or an empty or null `results` list raises
`ApiErrorException`. Omitted data, a status-zero object missing `results`, and
malformed known row fields raise `PayloadMismatchException`. WHM's outer result
is independently enforced.
