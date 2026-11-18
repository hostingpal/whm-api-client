# WHM backup and restoration

> Agent contract notes for `whm()->backups()`, `whm()->accountRestoration()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The WHM backup and restoration groups expose 42 methods: 26 in `backups()` and
all sixteen in `accountRestoration()`. Fourteen explicit source reads use GET;
28 operations use form POST, including inventory-like backup methods whose pinned
`x-readonly` value is false. `backup_destination_set` remains excluded under
`8x0.1.22`: its composed provider-specific form schema is not supported by the
current generator, and its JSON examples conflict with the formal form content.
Generic compatible form access does not establish typed destination-update coverage.

Destination creation requires name, type and disabled; provider-specific inputs
are optional in the PHP signature only where pinned prose makes their requirement
conditional. Supply the fields required by the selected provider. Credentials,
private keys, passwords and OAuth secrets remain sensitive. Returned secrets and
OAuth URLs are payload data; the client does not fetch them. Archive and staging
paths refer to server-side files, not local uploads.

Omitted settings retain server defaults; false and zero are sent explicitly.
The backup setter's `keeplocal` prose contradicts the getter's retention meaning;
the client forwards the flag without inversion. `backupMonthlyDates` accepts
an integer or a comma-separated string, following the pinned prose and reviewed
upstream validator; the weekly day remains a single integer. Migration's
`no_convert` name conflicts with its positive conversion prose; the flag is
forwarded unchanged. `backup_set_list.backup_set` preserves a list of user/date
rows. A combined listing can have a null destination legend, and remote `where`
values may be transport IDs rather than the pinned `remote` label.

The two pkgacct session GETs were observed to create an empty server directory
before failing for an unknown session ID. Use only IDs returned by
`startBackgroundPkgacct()`; other input forms were not tested. These observations
come from [Claude review 5720277974](https://github.com/hostingpal/whm-api-client/pull/42#pullrequestreview-5720277974),
not the committed GET capture. Remaining coverage stays in `8x0.2.29`.

Backup configuration and strict retention can affect stored archives. Packaging
and restoration can overwrite account content; restoration may delete its source
archive when requested. Queue activation acts on queued accounts, while each
clear method documents the tasks it removes. Restricted restoration carries the
source's experimental limitations. Session identifiers do not establish completion.
The client does not retry or roll back these operations. Metadata-only operations,
including username conflict verification, return void and use API errors for
failure. Completed queue records preserve nullable counts/warnings, integer result
codes and string timestamps; omitted fields remain absent.

All 42 methods have offline contract coverage. The fixed live catalog selects WHM
version, four parameterless backup reads and six restoration status/module reads
(11 GETs). It excludes destination credentials, OAuth requests, session logs,
backup creation, account restoration, queue changes, migration and configuration
writes. Populated variants and all remaining live behavior stay in `8x0.2.29`.

The immutable WHM backup/restoration capture at `a5b6e3a` on `11.138.0.19`
records 11 HTTP/API successes and ten payload/probe passes. Its `prebackup`
validation failure and one masked unknown-field observation with integer values
remain strict failures, unwaived under `8x0.2.29`. A wildcard identifies neither
the underlying name nor field count. Backup and queue inventories are empty,
module rows populated, and no mutation ran. Later reviewer observations and
contract corrections are separate from that historical capture.
