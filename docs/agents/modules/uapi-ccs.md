# UAPI CCS calendar delegation

> Agent contract notes for `uapi()->ccs()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI CCS calendar delegation (2026-10-31)

`uapi()->ccs()` exposes all five pinned CCS functions from cPanel 90, requiring the
installed Calendar and Contacts Server cPanel plugin. This external plugin module
is separate from native CPDAVD; never transfer CPDAVD defaults, return corrections
or live behavior. The client does not install/migrate the plugin or provision
calendars. `listDelegates()` and `listUsers()` use GET; `addDelegate(delegatee,
delegator, readonly: null)`, `removeDelegate(delegatee, delegator)` and
`updateDelegate(delegatee, delegator, readonly: null)` use form POST and return void
because the pinned result has no data member. Normal Flag handling remains.

Addresses retain exact bytes under existing scalar conventions and are encoded
once in the mutation body; no new normalization, email regex or sensitivity
policy applies. Add's enum [1,0] lowers to bool naturally. Update's optional integer
readonly gains only explicit encoding:bool, bound to its parameter 2 schema and
1=read-only/0=full-access description. Native bools encode as 0/1; omission/null
omits the field. Both pinned defaults are 0 (full access), not an observed server
guarantee. Omission can select source full access, so callers should explicitly
choose when required. No local default/read/merge/retry/rollback is added.

`listDelegates()` returns a nullable list with optional nonnullable string delegatee
and delegator and strict integer read_only (0|1). Preserve null versus empty lists;
absent data mismatches. Payload read_only is separate from UAPI status.
`listUsers()` is a dynamic username-to-UUID map whose formal additionalProperties
contains only prose, without a value type. Keep existing map/values:mixed lowering
and array<array-key,mixed> PHPDoc; no inferred string correction or UUID validation.
Opaque values and PHP numeric-key behavior remain. Empty objects and existing
empty-array map compatibility are valid; null/missing/nonempty-list/scalar roots
mismatch. Do not turn map identities into a list or import CPDAVD response rules.

Coverage adds exactly five canonical UAPI operations and one group over `0898b8b`:
1,122 operations (534 UAPI, 588 WHM), 93 groups and 189 generated outputs (188 PHP
files plus coverage). Existing runtime/generator, pinned sources and historical
evidence remain unchanged. No live calls, installation, calendar/contact reads or
delegation mutations ran. CCS installed-plugin behavior and permission enforcement
remain open under `whm-api-client-8x0.2.61`; API responses alone do not establish
applied calendar access. The delivery bead is `whm-api-client-8x0.4.19`.

Ordered integration with approved master `7d5099b` yields 1,159 canonical
operations (563 UAPI, 596 WHM), 101 groups and 205 generated outputs (204 PHP
files plus coverage). Earlier slice counts above remain historical. The original
CCS delivery is closed under `whm-api-client-8x0.4.19`; installed-plugin runtime
verification remains open under `whm-api-client-8x0.2.61`.

[PR94 review 5724966468](https://github.com/hostingpal/whm-api-client/pull/94#pullrequestreview-5724966468)
of `259c8d6` reported plugin-absent `ApiErrorException` responses for both reads
through direct UAPI and the WHM bridge on cPanel 11.138.0.19. It saw no CCS handler
source or installed-plugin successful payload. Populated `read_only` wire types
remain unobserved: strict native integer 0|1 stays pinned, without speculative
digit-string acceptance. The opaque `listUsers()` map remains unchanged. This
integration performs no live call, plugin installation or calendar operation.
