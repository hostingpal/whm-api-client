# WHM database administration

> Agent contract notes for `whm()->databases()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`whm()->databases()` exposes all 27 pinned database-administration methods: nine
explicit reads use GET, seventeen operations use form POST, and `updateSqlConfig()`
uses a typed JSON POST (see WHM JSON configuration below). Remote profile reads
marked non-readonly in the pinned source also use POST, and their returned password
fields remain sensitive payload data.

The profile-list source misplaces `additionalProperties`. Its dynamic-name prose
and multi-profile example establish a root profile map containing exactly the eight
documented optional fields. Profile-read ports accept bounded canonical integer
strings observed on the stock localhost profile. The two list flags accept exact
quoted 0/1 from the pinned example; live values were native integers. Typed
`cpcloud` create-result flags use source-family compatibility only; no profile
write was observed. Database/profile keys remain unchanged and wildcarded in
evidence. Unknown-user `mysql_databases: null` remains distinct from an existing
account's empty list; it is not a universal account-existence test. Missing and
null root object payloads remain invalid. Six explicitly metadata-only
rename/password operations return void. Both profile reads can expose plaintext
`mysql_pass` in `PayloadMismatchException::$payload`; do not log that raw payload.

Database passwords, SSH/root passwords and key passphrases remain sensitive.
Stored SSH key names are identifiers. Optional credentials retain documented
alternatives and conditional requirements without invented validation. Explicit
false/zero/empty values remain distinct from omission. Root-password updates retain
the pinned `update_config` and root client-configuration caveats.

Renames can copy data/grants and delete original resources. The pinned rename
warning describes possible partial failure, but its response location has not
been observed; no WHM warnings accessor or text-based detector is implied.
Check the API outcome and explicitly read affected inventory after a rename.
Profile activation can change credentials, network access and server configuration.
Password setters take user before password; rename methods take old name before
new name, with optional `cpuser` last. Passwords retain sensitive-parameter marking.

Pass discovered upgrade versions as exact strings, such as `version: '10.10'`.
The evidence-gated input union also preserves existing integer/float callers;
casting a string to float can change the requested version to `10.1`. Upgrades
have no rollback, and selecting the same version reinstalls the engine. Upgrade
checks/starters return initiation information and require separate status
inspection. Available versions do not establish upgrade compatibility or
completion. The client does not poll, retry or roll back these operations;
method-specific role/version/prefix caveats remain in generated documentation.

All 26 methods have offline contracts. The fixed nine-GET catalog reads WHM
version, current/latest/installable database versions, optimization information,
database/user inventory, `npr` database details and profile-activation status.
It retrieves no profile passwords and guesses no upgrade ID or remote host.
No upgrade/checker run, profile validation/activation, password change, rename or
configuration write occurs. Source/live gaps remain under `8x0.2.33`.

The corrected [WHM database capture](../../api/evidence/2026-10-31-whm-databases-corrected.json) at
`00ba48d402f0a60cd975ec38cf5166545a8c0286` on `11.138.0.19` passes all nine
HTTP/API/payload/probe observations and strict assessment, with zero discrepancies.
Optimization map records now establish the named `name`, `reason` and `value`
string leaves. The selected prefix flag passes exact string-flag normalization;
activation state retains both explicit null fields. Installable version rows are
present, while database/user inventories are empty. Empty inventories do not
establish their populated row schemas.

The other eighteen methods were outside this committed catalog. This capture
read no profile credentials and ran no upgrade, checker, profile activation,
password, rename or configuration change. Successful inventory calls establish neither upgrade safety nor
completion, remote trust or service enforcement. The initial failed artifact is
unchanged; follow-up `8x0.2.33` owns the remaining variants. SHA-256:
`b97b7f1bbf91bccc8d048026a1d99c38ecaa049730c165507fde5fe53b111ba1`.
