# UAPI Ftp

> Agent contract notes for `uapi()->ftp()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->ftp()` exposes all 20 pinned Ftp functions for the selected account.
Mutations use form POST; addFtp password/passHash map to pass/pass_hash and are
sensitive. Preserve omitted defaults, false/zero, pipe-delimited filters, decimal
string disk amounts, quota unlimited semantics and optional nullable htmldir.
Both listFtp() and listFtpWithDisk() accept raw strings or nonempty sequential
string lists for includeAcctTypes and skipAcctTypes. Lists join once with | into
the original GET field; preserve order, duplicates, empty items and all other
bytes. Reject embedded pipes and malformed/empty lists locally after metadata
reset; null omits, [''] sends empty and ['', 'sub', ''] sends |sub|. Raw scalar
pipes, %7C and empty strings stay unparsed; query serialization encodes once.
Omitted include returns all account types; omitted skip excludes none. Do not
infer an enum, defaults or cross-field validation. All four generated collection
positions have SensitiveParameter for trace protection without a semantic
secret flag or POST override. Both direct and bridged calls remain GET; responses,
counts and all other FTP operations are unchanged. This list convenience has
only offline evidence. Filter live follow-up 8x0.2.80 remains open, as do existing
unknown-field/settings follow-ups 8x0.2.10/8x0.2.11 and wider adopter audit 8x0.28.8.
Historical scalar captures do not establish execution with PHP lists.
getQuota preserves documented strings and observed finite JSON integers as int|string;
its evidence-bound root union accepts no float/boolean/null coercion.
ftpExists returns a nullable opaque payload or API error, not a boolean.
Deleting with destroy=true also deletes the home directory; killSession without
login stops all account sessions. Anonymous settings are account-scoped. Keep
role/version caveats and sensitive parameters in the overlay, then regenerate.
