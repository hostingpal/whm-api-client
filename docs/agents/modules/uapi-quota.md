# UAPI Quota

> Agent contract notes for `uapi()->quota()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

`uapi()->quota()` exposes account quota totals in MB and local usage in bytes.
Preserve zero/unlimited/disabled semantics, optional fields and integer status flags;
local byte limits can lag updates and distributed totals differ from local usage.
