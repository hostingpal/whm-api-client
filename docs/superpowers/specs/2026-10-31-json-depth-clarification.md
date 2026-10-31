# JSON nesting boundary clarification

This clarifies the nesting budget in the [reviewed JSON inputs design, PR #73](https://github.com/hostingpal/whm-api-client/pull/73).
Both raw JSON and schema-guided typed output must satisfy native
`json_validate($json, 512)`. This accepts at most **511 nested JSON containers**.
Count each object or list, including the root and an empty deepest container;
scalar leaves and scalar `anyOf` schema descriptors add no container level.

Independent native probes with bracket-nested arrays ending in a scalar show:

| Nested containers | `json_encode($tree, JSON_THROW_ON_ERROR, 512)` | `json_validate($json, 512)` |
| --- | --- | --- |
| 511 | Accepts | Accepts |
| 512 | Accepts | Rejects |
| 513 | Throws | Rejects |

Validation also rejects 512 or 513 nested containers when the deepest container
is empty. The encoder and validator count their depth arguments differently;
`json_encode(..., 512)` alone cannot enforce the shared acceptance boundary.

Typed serialization therefore checks the entire schema against the 511-container
limit, bounds value traversal by the same limit, and validates the complete output
with `json_validate(..., 512)` before returning it. Malformed or over-budget schema
descriptors raise `LogicException`; caller value mismatches raise
`InvalidArgumentException`. No partial body is returned or dispatched.

This ruling keeps typed results eligible for the raw syntax boundary. Its cost is
rejecting the one deepest container level that native encoding alone permits,
before any API call. It adds no endpoint-specific depth claim or public API.
