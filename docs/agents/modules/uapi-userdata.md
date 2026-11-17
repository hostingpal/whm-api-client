# UAPI scoped userdata settings

> Agent contract notes for `uapi()->userData()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI scoped userdata settings (2026-11-01)

`uapi()->userData()` covers both pinned UserData functions from cPanel 11.32,
without a pinned role or plugin restriction. The original 11.138.0.10 source
declares GET, `x-readonly: false` and `x-rollback: none`; both methods send one
form POST under repository policy. [Review 5725906568](https://github.com/hostingpal/whm-api-client/pull/107#pullrequestreview-5725906568)
at original revision `08dacc43c15a6d58aa536da8352a3696642d129f` on
`11.138.0.19` observed both POSTs through direct UAPI and the WHM bridge.
The reviewer observed that a new-scope getter creates its scoped directory and
attributed this to `Guard::new_scoped` source. That does not prove why the source
annotation was chosen or establish behavior on every build.

```php
$settings = $client->uapi('example')->userData();
$mapping = $settings->getScopedUserdata(scope: 'prefs');
// Synthetic inputs: each explicit setter call changes scoped data on the server.
$mapping = $settings->setScopedUserdata(scope: 'prefs', key: 'theme', value: 'dark');
$mapping = $settings->setScopedUserdata(scope: 'prefs', json: '{"theme":"dark"}');
```

Both results are required nonnullable `array<array-key, mixed>` maps. The original
string-only value pin is contradicted by the attributed review and the separate
root 25-call observation at that original revision/build. Successful mixed writes
had already persisted before the old typed response failed; later reads also
failed for null/list/object entries. The correction accepts decoded JSON entries
using existing mixed handling: scalar values and null remain unchanged, lists are
copied, and nested objects recursively become associative PHP arrays. Numeric
object keys follow PHP conversion; empty object/list identity can collapse.
Arbitrary empty, whitespace, Unicode, dotted and reserved-looking keys remain.
Missing/null data and scalar/nonempty-list roots still fail; empty-object and
inherited empty-array map compatibility remain.

The review's synthetic-compatible shape `t/n/f/l/o` returned integer 1 and float
1.5 from the write, then strings "1" and "1.5" on the read, with null/list/object
entries retained. These are server response observations, not client scalar
coercion or guarantees for other versions. Independently authored literal offline
fixtures reflect those attributed shapes and the existing PHP object-to-array
policy; they are not reconstructed exact old wire bytes or new live captures.
Mixed compatibility accepts other decoded JSON values as policy, beyond those
observed variants.

Scope is a required string. Setter `json`, `key` and `value` are optional strings:
null omits, empty sends. The source forbids combining json with key or value;
the canonical method forwards every supplied string, leaving conflicts, pairing
and scope-only validity to server validation. The raw scalar json form field is
encoded once. Whitespace, duplicate keys, percent/plus/Unicode and empty,
malformed, scalar or list text pass through without local JSON parsing,
reserialization, input normalization or mode selection.

That original review observed writes merging existing keys for both input modes;
an empty value stored an empty string rather than deleting a key. Recovery by
overwriting a mixed scope with strings was not tested. There is no universal
confinement, atomicity, persistence, transaction or rollback promise.
The client adds no implicit merge, read, readback, key deletion, retry, rollback,
server-settings cache, returned-path access or URL fetch. A deliberate caller
set/get pair remains two explicit invocations.

Scope identities, keys, values, results, errors and metadata are private account
settings. All inputs retain `SensitiveParameter`; library-owned trace arguments
conceal private inputs and responses, while third-party transports own their
traces. Deliberate `responseMeta()` and `PayloadMismatchException::$payload`
access remains private caller-accessible data. Do not log it indiscriminately.
API and outer-WHM failures precede payload validation; a mismatch after success
may follow a state change, so callers must not retry automatically. Metadata
clears on entry; native argument errors before entry retain the previous snapshot.

The separate immutable root artifact has six payload observations/25 API calls,
structural predicates and API cleanup, not saved lexical value bytes or a
current-candidate runtime capture. Reviewer cleanup and root API cleanup are
distinct. Root's later filesystem check exited 255 without JSON, with unknown
cause; filesystem absence remains unproved and no retry is authorized.
No implementation-file SHA is retained or inferred from reviewer source prose.
This correction performs no live call or account-settings access. Live follow-up
`whm-api-client-8x0.2.74`, filesystem follow-up nyz and broader runtime work stay
OPEN. Generated coverage is 1,244 operations (648 UAPI, 596 WHM), 116 groups and
235 outputs (234 PHP plus coverage); construction is separate from live coverage.
