# Log administration

> Agent contract notes for `uapi()->logManager()`, `whm()->logs()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## Log administration

`uapi()->logManager()` covers all six pinned functions for the selected account;
`whm()->logs()->listAccountsRetention()` is WHM account-retention inventory.
Five reads use GET and two mutations use form POST. LogManager requires cPanel
82+, with deleteArchive() at 136+ and the error-log methods at 138+; the WHM
retention inventory requires 136+. Reviewer 5721279104 inspected the server's
`Cpanel/API/LogManager.pm` on `11.138.0.19`: shared `%API` requirements are the
WebServer role and rawlog feature for all six UAPI operations. The pinned snapshots
declare neither requirement, and disabled-role/feature behavior was not tested.

The pinned settings text uses current `~/.cpanel-logs` flag values when present,
with `cpanel.config` defaults when absent. Client policy omits null arguments and
transmits explicit false as 0 and explicit zero unchanged. The reviewer stored
and read back false/zero flags; omitted flags kept their current values after
`setSettings(retentionDays: -1)`. Here -1 clears the per-user retention override,
while zero retains indefinitely. The pinned schema supplies a minimum of -1;
the reviewer observed a server bound of -1..999 and an API error for 1000.
Native optional bool/int inputs and server-side range/cross-field validation stay
unchanged, including rejection of a call without arguments. No local maximum
check is added. These observations do not establish rotation or pruning.

Integer 0|1 UAPI flags, boolean WHM using_default, optional fields and nullable
retention/error-log metadata retain their contracts. The existing four capture-bound
conversions remain scoped to WHM default_retention and UAPI get_settings
archive_logs, prune_archive and retention_days. Separately, the reviewer observed
empty direct viewErrorLog() returning `log_content: []` and `lines_read: "0"`;
nonempty direct and bridged counts were native integers. Only lines_read additionally
accepts canonical signed decimal strings within PHP integer bounds, normalizing
to int. Native integer/omitted counts and strict sibling mtime/size/flags remain.
Neither immutable log-administration capture contains viewErrorLog().

Log contents are private payload data; do not log them or explicitly accessible
PayloadMismatchException payloads. The client forwards paths/selectors unchanged,
performs no local filesystem access, and makes one request with no retry or undo.
Deletion/settings can permanently affect archives. Mutations retain unconstrained
present JSON payloads, including null, and reject absent data. All seven methods
have offline contracts, including both UAPI routes; the private-log mismatch test
uses the shared trace suite with argument capture and positive controls.
StatsManager/save_configuration is separately adopted as a direct typed JSON POST;
its successful-write gate remains open under `8x0.1.18`.

The fixed eight-GET catalog observes version, WHM retention and selected-account
settings/archive/error-log metadata. It reads no log contents and makes no writes.
The initial `e5476c1` artifact remains failed and immutable; the corrected
`dd5e626` artifact on `11.138.0.19` passes all eight checks and strict assessment
with zero discrepancies. Account/archive rows are populated; error-log lists are
empty. Separate reviewer observations at `984c5c1` cover all seven methods on a
disposable account, including reads, writes and API-error cases through both UAPI
routes and the single WHM route. The reviewer revoked its token and removed the
account/home. They establish no live verification of the current corrected head.
Unreadable/symlink error rows with null size/mtime, php_fpm_error_log and actual
rotation/pruning remain unobserved under `8x0.2.55`.
