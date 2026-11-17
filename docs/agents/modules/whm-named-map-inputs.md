# WHM named-map inputs

> Agent contract notes for feature lists, Greylisting providers, AutoSSL providers, addon conversion, `massModifyAccount()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

The seven named-map WHM adopters add source-backed form POST support for feature
lists, Greylisting providers, AutoSSL providers, addon conversion and bulk account
modification. Canonical coverage is 1,142 (548 UAPI, 594 WHM), 99 groups and 201
files. See README and source-audit for exact contracts and review provenance.
Required update features are nonempty; create permits [] with version-scoped
all-disabled evidence. Optional empty/null maps still dispatch. AutoSSL logical
keys omit x_ and already-prefixed keys reject via explicit descriptor policy;
other maps preserve literal prefix text. massModifyAccount retains 50 fields,
excludes MXCHECK-* and returns strict rows under Unchecked with native integer
0/1 metadata. Reviewer observations do not establish corrected-head live coverage;
no automatic retry, polling or rollback is added.
