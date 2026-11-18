# WHM system package management

> Agent contract notes for `whm()->systemPackages()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM system package management

`whm()->systemPackages()` exposes all fourteen pinned functions for server RPM
metadata, package inventory and planning, plugin processes, background actions,
cache repair and upgrades. Seven methods use GET and seven use form POST.

The source requires either `namespaces` or `packages` for `getPackageInfo()`;
its individually optional parameters do not enforce that combination locally.
Supply the intended selector. `resolveActions()` requires both packages and a
namespace: the reviewer observed a missing-namespace API error despite the
pinned optional declaration. The formal namespace enum names `ea`; source prose
also permits configured additional prefixes. Repeated inputs use repeated wire
keys without inferring which additional namespaces this server supports.

`submitActions()` has no local nonempty-action combination guard. Omitting every
action sends an empty form body, and an empty scalar sends an empty field;
explicit empty lists reject before dispatch. Supply the intended nonempty
install, uninstall or upgrade actions before calling. Neither server rejection
nor a harmless no-op is guaranteed for an empty call. Background acceptance
and a returned build ID do not establish completion. The client does not poll,
retry, roll back or execute scripts automatically.

Package dependency requirements retain package-name strings and nested string
lists; a nested list means any one of its members, following the reviewed
PackMan source and the separate six-package GET report. Conflicts remain a
flat string list. Optional `vendor` is a string; the reviewed dnf backend uses
a source-only `NYI` placeholder rather than identifying an actual vendor.
`version_installed` remains a non-nullable string. The reviewer observed an
exact empty string on four non-installed packages and nonempty strings on two
installed packages, then withdrew the nullable recommendation. Null remains
unobserved and rejected; no empty-to-null conversion is added. These reviewer
reads and source findings are separate from the committed capture artifacts.

RPM metadata maps accept string or string-list leaves, including nested section
maps. The reviewer observed scalar values in `target_settings` and list values
in `rpm_groups`; arbitrary section names remain strings. For RPM edits/deletions,
`metadata.result=1` can accompany `data.status=0`. The returned zero means the
RPM change failed; callers must inspect it because envelope success alone does
not cause an exception. These metadata changes are distinct from installing or
uninstalling packages. Upstream recommends a later package-check script; the
client does not run it. Cache-repair success documents literal 1, while the
existing integer contract does not enforce a singleton enum.

Log offsets are bytes. Build IDs come from submitted actions and are not guessed;
log text is private payload data. Installation, removal, upgrade and cache repair
can change the server immediately. All seven POST methods remain unobserved.

Both historical three-GET package captures are complete and immutable. The
corrected `b8ebf87` capture on `11.138.0.19` passes HTTP/API/payload/probe checks,
with populated installed-package rows and metadata-only `listRpms()` success.
That method accepts absent successful data; its inherited explicit-null boundary
is unobserved. Present payloads remain validated, and absence/null does not mean
zero missing dependencies. The original strict `missing_required` finding is
an evidence-tooling mismatch: the old fingerprint path dropped `spec.optional`.
The correction carries optional-payload semantics into new fingerprint analysis;
it does not rewrite or waive either historical artifact. The corrected artifact
remains strict failed under `8x0.2.46`, SHA-256
`870dcf28decf6b60de972f9876115506ca2ee129a0b7b3c64e683e73016af1d4`.

The expanded fixed GET catalog includes package information, both RPM map
sections and action resolution. A separate review recipe selects only version
and those newly requested reads, without replaying the old inventory probes.
No package mutation, cache repair or build-log read is part of either recipe.

The separately requested review capture is now complete at `dd72691` on
`11.138.0.19`: exactly five WHM GETs (version, `ea-apache24` information,
`target_settings`, `rpm_groups`, and action resolution for that package in
namespace `ea`). All HTTP/API/payload/probe and strict checks pass, with zero
discrepancies. It observes mixed string/nested-string-list requirements, string
conflicts and vendor, a string installed version, nested RPM maps with string
leaves and with string-list leaves, and populated unaffected/uninstall plan
lists. The plan was returned only; no package action was submitted. Dynamic
names and all payload values are absent from the artifact. The nested RPM map
observations now support the previously inferred section-map compatibility.
This capture does not establish empty installed-version values, literal vendor
values, zero dependencies or any POST behavior. The separate reviewer report
retains the empty-string observation and source placeholder attribution.

The new artifact is `2026-10-31-whm-system-packages-reviewed.json`, SHA-256
`44b3540940eeda781608957a57d12e5b9b5bdf10343c84f4f5a272e071d156ed`.
It does not rerun either historical inventory probe or alter their findings.
No mutation, cache repair, build-log read or URL fetch ran. All seven POSTs and
remaining compatibility stay under `8x0.2.46`; no repeat is needed for integration.
