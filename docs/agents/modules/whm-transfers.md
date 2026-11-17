# WHM account transfers

> Agent contract notes for `whm()->transfers()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM account transfers

`whm()->transfers()` exposes fourteen functions: six GET reads and eight form
POST operations for transfer sessions, controls, metadata, analysis, logs and
archive deletion. The module-dependent form operation `enqueue_transfer_item`
remains outside the typed interface under `8x0.1.22`. After creating a root
transfer session, enqueue the intended items with `whm()->call()` and their
source-documented module parameters before calling `startTransferSession()`.
A returned process ID does not prove that any items are queued or completed.

`createRemoteRootTransferSession()` puts `host` and `user` first; use named
arguments for its flags and authentication alternatives. The non-root creation
method has no username parameter. Its required `unrestrictedRestore: true`
argument sends the pinned literal `1`, skipping Restricted Restore; other values
are rejected before a request. Passwords and key passphrases stay sensitive.
The source requires unrestricted restore to be disabled in the root flow when
using the enqueue operation's `force` option. Remote checks and session creation
can contact another host, and transfer controls can change account state.

Module names are open strings. On reviewed WHM `11.138.0.19`,
`FeatureListRemoteRoot` works while the pinned `FeaturesListRemoteRoot` spelling
fails. Module priority values remain strings. The analysis flag
`skip_reseller_privs=1` means reseller privileges transfer by default, despite
its name. `valid_for_transfer` means suitable for a transferred account but not
a new account; it is not a general username-validity flag. Analysis
`remote.conflicts` remains opaque because its pinned object type conflicts with
its error-message description; populated analysis is unobserved.

Logs and analysis may contain private paths or configuration. Unknown-session
reads exposed internal paths in the separate reviewer report. Treat upstream
errors and payloads as sensitive. The client does not retry, poll, roll back,
execute returned text or follow URLs. Archive deletion can remove backup files.
The original three-GET metadata capture and separate two-GET reviewed module
schema capture are complete and immutable; both strictly pass. All eight
POST methods and populated sessions/logs/analysis remain unobserved under
`8x0.2.47`. See the [source audit](../../api/source-audit.md#whm-account-transfers)
for evidence boundaries and input exclusion `8x0.1.22`.
