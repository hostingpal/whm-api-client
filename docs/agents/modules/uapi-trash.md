# UAPI Trash

> Agent contract notes for `uapi()->trash()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

Trash returns optional integer 0|1 removal flags, accepting pinned booleans with
normalization. Only usage.items accepts lossless signed canonical integer strings;
bytes/exists remain strict int. Original-revision observations and missing old
wire spelling/serializer cause are detailed in the Trash section.

### UAPI Trash apparent usage and permanent removal (2026-11-01)

`uapi()->trash()` covers both pinned functions from cPanel 136 with source plugin
annotation `Trash`; version alone does not establish availability.

```php
$trash = $client->uapi('example')->trash();
$usage = $trash->getUsage(); // GET; optional int bytes, exists and items.
// Synthetic path: this explicit call permanently removes an item on the server.
$removed = $trash->removeItem(path: '/.trash/backup-7.14.2026_16-35-25');
```

`getUsage()` reports apparent entry lstat bytes, excluding root `.trash_restore`,
not allocated blocks or quota impact. `bytes` and `exists` remain strict integers
without new ranges or an `exists` enum. Only `items` accepts lossless canonical
in-range integer strings and returns int: optional minus, canonical decimal digits,
exact integer roundtrip. Signed negative strings and boundaries are compatibility
policy beyond the retained nonnegative count producer. `-0`, plus, whitespace,
leading zeros, exponents, fractions and overflow fail; native integer policy is
unchanged.

[Original review 5725860648](https://github.com/hostingpal/whm-api-client/pull/106#pullrequestreview-5725860648)
at `210cc400a55f7cc10c08f06d369fe17d8a5f9e14` on `11.138.0.19` reports native
`removed=1` and `alreadyGone=1` through direct UAPI and WHM bridge after actual
permanent deletion/already-absent success and fixture cleanup. Both optional
nonnull fields now return integer `0|1`. Integer zero and pinned booleans are
bounded compatibility policy: false/true now return integer 0/1, a public output
type correction. Strings, floats, null, other integers and containers fail.
Pinned boolean schema and alreadyGone false example versus true/omitted prose
remain historical evidence. API status stays authoritative.

Separate root artifact `open-pr-live-results-b-pr106-v3.json`, SHA-256
`4507897ac11f49570867428c699a13075e7a178ab6e9d685f11e37e364958c73`, classified
direct `items` as `is_string && is_numeric`, while bridge returned int at O/build
above. Exact lexical bytes were not retained: this grammar does not prove the old
payload passes. The reviewer also observed integer counts on both routes.
[Retained-source answer 6561140130](https://github.com/hostingpal/whm-api-client/pull/106#issuecomment-6561140130)
attributes the producer to literal zero or `scalar @entries`, excluding dot,
dotdot and `.trash_restore`; this establishes the count producer, not recovered
wire grammar or the missing direct serializer cause.

`removeItem(#[SensitiveParameter] string $path): array` makes one form POST and
permanently, recursively deletes one item, including permission-locked entries.
Already-absent success requires an existing resolvable parent: no trash or a deeper
nonexistent parent can error. The server resolves symlinks and `..`, confines
targets strictly inside account home/.trash and treats leading slash as
home-relative. The original reviewer observed an outside-target symlink itself
remain unremovable because confinement follows its target. No general idempotence,
confinement, quota-release or rollback guarantee follows; no Fileman.empty_trash
fallback is added. Exact path bytes are form-encoded once, including empty string
as `path=`. The client adds no path normalization or validation.

Both roots require a nonnullable known-field object; missing data/null fail,
omission stays omitted, unknown siblings are filtered and inherited empty-array
object compatibility remains. Selectors cache by account without I/O; calls make
one attempt without implicit inventory, readback, retry, restore or rollback.
Paths, response/error values, metadata and explicit mismatch payloads are private.
Library frames conceal sensitive arguments; callers must avoid indiscriminate
logging. Observations belong to original-revision reviewers/root, not this
integrated candidate. This correction performs no live request or capture.
`whm-api-client-8x0.2.73` and lexical/serializer gaps remain open; final exact-HEAD
`4g9` disposition is still required.

Combined generated coverage is 1,242 canonical operations (646 UAPI, 596 WHM),
115 groups and 233 outputs (232 PHP plus coverage), verified by generation.
PHP 8.3 remains the supported minimum.
