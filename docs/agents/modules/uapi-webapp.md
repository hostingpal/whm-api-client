# UAPI WebApp

> Agent contract notes for `uapi()->webApp()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI WebApp (2026-10-31)

`uapi()->webApp()` covers all thirteen pinned WebApp functions from cPanel 138.
`fetchLogs()`, `getAvailable()`, `hasFeature()` and `listApplications()` use GET;
the nine lifecycle/configuration mutations use form POST. `stage()` and
`redeploy()` select the documented POST variant, preserving optional sensitive
`sshKeyPass` as body-only `sshkeypass` on direct and WHM bridge routes.
`configureStructured()` is a companion of `configure()`, with the same operation,
route, outcome and response; it adds no operation to the coverage count.

```php
$app = $client->uapi('exampleuser')->webApp();
$current = $app->listApplications(); // Read the current environment separately.
// Prepare the COMPLETE intended environment; this replaces every old variable.
$configured = $app->configureStructured('my-app', env: [
    'NODE_ENV' => 'production',
    'FEATURE_ENABLED' => true,
]);
```

Only supplied configure options change, but `env` and `envText` both replace ALL
variables. Read the current set and submit the complete intended variables;
neither method merges or reads implicitly. The server rejects both inputs
together; the client forwards both if supplied. Structured `env: []`, raw `'{}'`
and text yielding no variables clear the environment. Optional null omits the
input. Nonempty settings require `.env` to be untracked and ignored; clearing is
allowed despite `env_not_editable`. Server limits are 16 KiB JSON, 64 variables,
names matching `[A-Za-z_][A-Za-z0-9_]*` up to 128 characters, values up to 4096
characters without control characters, and reserved `PORT`. These remain server
restrictions rather than new client validation. Text splits at the first `=`,
later duplicate names win, and quotes/spaces after it remain literal. Leading
whitespace is trimmed upstream; comments/blank lines are skipped and lines
without `=` are rejected. Raw JSON and text bytes are otherwise forwarded once.

Structured environment input accepts string keys with string/int/finite-float/
bool/null values. It sends JSON inside one form field, through the existing
serializer; it is not a whole JSON request body. Empty input serializes as `{}`;
null values remain JSON null in client encoding. The attributed server conversion
is described below; no universal conversion or persistence guarantee is made. Integer keys, nested containers, objects, resources, invalid UTF-8
and nonfinite floats fail locally before I/O, clearing metadata without exposing
values in exceptions. The raw string method stays available without auto-parsing.

Stage registers previously uploaded ZIP/tar paths relative to the account home,
or Git source URLs; it does not deploy, upload, read or fetch the source. Use
trusted sources because the server builds and executes them. Never embed Git
credentials: upstream stores that URL in plaintext. Prefer the selected account
SSH key for `git@` URLs. A staged-name collision overwrites the staged source;
a deployed-name collision assigns the next free name-N. Use the returned name
and domain for later calls. Protected-key passphrases stay body-only; omission
stays omitted. The source accepts modern encrypted OpenSSH keys and rejects
legacy encrypted PEM keys. The client never prompts for or stores passphrases.

Changing appdir reruns preflight/defaults; empty resets to the source root and
explicit options take precedence. Empty commands clear overrides; nonstatic apps
without both commands are undeployable. Empty domain regenerates the default.
`useExistingDomain: true` confirms takeover and hides the existing website;
omission does not confirm and false cannot revoke prior confirmation. Changing
domain releases the prior include; another application's domain stays rejected.

Deploy/redeploy return asynchronous IDs and an SSE URL as payload data. Envelope
success establishes neither completion nor health. Upstream documents in-flight
idempotence; the client never retries or polls. A protected-key redeploy pull runs
synchronously before queueing per source, so latency may increase; upstream says
its passphrase never persists in task arguments, without live verification here.
Inspect `listApplications()` last_deploy separately from immediate
`responseMeta()` metadata.error_category. Only list's last_deploy gains an optional
mixed error_category, explicitly placed there by deploy/redeploy prose although
omitted in its formal schema. No field type/enum or sibling correction is inferred.
Configure/stage do not borrow that field. Known optional and nullable fields,
0|1 state flags and dynamic string environment maps stay distinct. The three
optional state fields deployed/env_editable/staged on configure/list/stage accept
observed native integer 0|1; pinned booleans normalize to 0|1 as source compatibility.
configureStructured inherits configure; no sibling response is widened.

`delete(name, verify)` requires explicit confirmation with no default; false
sends 0 for upstream rejection. It stops/removes the container, moves the directory
to .bak and removes the registry entry; the domain is unaffected and there is no
client rollback. Start/stop/restart/setMode apply only to server-category apps
with a completed deploy. `restarted=0` can retain the mode without finishing a
restart; inspect it independently. Logs are a private, static oldest-first tail,
with deployId meaningful only for build logs. Runtime/tag map keys and empty
manager lists remain intact; runtime apps.used is required when apps is present.
`hasFeature()` retains normal UAPI failure checking and strict integer data flag.

Historical original slice: thirteen canonical operations and one group were added over
`9e47d959cbf562bf91bd1b32944360297acfbcaf`: 1,163 operations (575 UAPI, 588 WHM),
98 groups and 199 generated outputs (198 PHP files plus coverage). It adds only
an opt-in generator `json.scalar_map: true` path with source hash/prose/schema
binding, plus synchronized published/embedded overlay schema. Existing formal
content-JSON aliases and PHP runtime remain unchanged. Delivery is
`whm-api-client-8x0.4.24`; separate live `whm-api-client-8x0.2.66` remains OPEN.
The original author ran no live call, source upload/clone, build, deployment, domain
takeover, lifecycle mutation or URL fetch. This integration also performed none.
Offline fixtures establish no server execution,
persistence, passphrase storage, application health or destructive compatibility.
Required PHP 8.3 CI and the 55 inherited diagnostics remain external-owned and
unwaived; local PHP 8.4 does not replace that CI evidence.


Current integration (2026-11-01) combines the approved master with WebApp: 1,200
canonical operations (604 UAPI, 596 WHM), 106 groups and 215 generated outputs
(214 PHP plus coverage). Only nine state leaves in configure/list/stage are
corrected; deploy/redeploy have job-ID responses without these flags.

On cPanel 11.138.0.19, seebeen reported on both direct UAPI and WHM bridge at original
revision 4ab5b80587f8d524bbc0ccf5129f9901ef9ab8df that the server stores environment
values as TEXT: number 5 becomes "5", float 1.5 becomes "1.5", true becomes "1", false
becomes "0", and null becomes "". The nonempty string "0" can be truthy in application
code. This is server conversion: configureStructured still serializes the original
native JSON types and bytes in one form field without client text conversion. Its
parameter description that scalar null stays JSON null describes client encoding. Each
supplied environment replaces the full set; structured [] emits {} and clears it, while
null for the whole optional input omits the field. These are attributed version-scoped
observations, not a universal conversion or persistence guarantee.

The observations above are attributed to [seebeen review 5725334504](https://github.com/hostingpal/whm-api-client/pull/99#pullrequestreview-5725334504)
and its [correction 6550246600](https://github.com/hostingpal/whm-api-client/pull/99#issuecomment-6550246600).
They concern original `4ab5b80` on `11.138.0.19`, both routes and disposable `wrvapp`,
not the combined tree. Stage/configure succeeded before payload validation failed;
complete-set replacement and clear were reported. Cleanup/token revocation were
reported by the reviewer, not independently checked here. No deployment or running
container behavior was observed; `8x0.2.66` remains OPEN.
