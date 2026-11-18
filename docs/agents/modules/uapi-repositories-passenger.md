# UAPI repositories, deployment and Passenger

> Agent contract notes for `versionControl()`, `versionControlDeployment()`, `passengerApps()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The repository, deployment and Passenger groups cover fourteen pinned functions.
`versionControl()` manages cPanel Git repositories, `versionControlDeployment()`
starts and inspects deployment tasks, and `passengerApps()` manages Application
Manager registrations. Passenger inventory uses GET; the other thirteen methods
use form POST, including repository and deployment retrievals whose source marks
them `x-readonly=false`.

Repository `sourceRepository` is caller-supplied JSON text, sent once as a form
parameter. The client does not JSON-encode a PHP object or decode/re-encode the
string. The native parameter is `?string`; supply the
documented serialized JSON string, or omit it. Clone URLs may contain credentials,
so this argument stays sensitive. The pinned source warns that repository deletion
permanently removes directory contents. The PR40 reviewer instead observed
registration deletion leaving the directory on the test server; retain the
source's destructive-operation warning rather than relying on that discrepancy.
Updating a branch can pull remote changes;
source URLs cannot be changed by the update method.

Deployment requires a clean working tree, branches and a checked-in `.cpanel.yml`.
Returned task IDs and event/log URLs do not prove completion and are not fetched
by the client. Reviewed integer deployment IDs are preserved, and `delete()`
accepts the returned integer or documented string identifier. A status-success
`create()` response with explicit null was observed for a non-deployable repository
with no deployment queued; absent data remains a mismatch. A payload error after
queueing does not undo the deployment and must not trigger a retry. Creation's
unspecified `last_update` schema includes a null-only
enum but remains mixed under the current effective validator; no stronger field
contract is invented.

Passenger registration requires an existing application; unregistering it does
not delete its files. Environment names and values use matching repeated fields;
supply one value per name. Editing environment variables replaces the current
set. Omitting `enabled` when editing uses the documented default of 1 and can
re-enable a disabled application; pass false explicitly to retain disabled state.
Values remain sensitive, and opaque returned environment objects preserve
their fields. Inventory `enabled` has an unspecified schema and remains mixed;
mutation flags retain their declared types. `ensureDeps()` starts asynchronous
installation. These methods manage Application Manager; the pinned source
recommends WebApp for applications deployed with that newer feature.

The bounded live catalog reads only WHM version and Passenger inventory on both
routes. Repository/deployment POST reads, mutations and populated/secret-bearing
variants remain tracked under `8x0.2.27`. No deployment, dependency installation,
repository deletion or application configuration change is part of that catalog.
The reviewer diagnosed its Passenger API failures: `npr` lacks the `passengerapps`
feature and the server has no Passenger packages. No server-wide installation was
made. All seven Passenger methods remain unobserved, including dependency-result
and mutation flag/environment variants. The failed capture remains unwaived.


The [application-management capture](../../api/evidence/2026-10-31-application-deployment.json)
at `344fffc` on `11.138.0.19` records three HTTP-200 GETs. WHM version passes;
both Passenger inventory calls fail at the API layer before payload validation,
so strict assessment remains failed. This is no successful empty-inventory or
payload-schema evidence. All fourteen methods are offline-tested; separate
reviewer lifecycle observations and their limits are recorded in the source audit.
Committed lifecycle evidence remains under `8x0.2.27`. This capture changed no
repository or application state.
