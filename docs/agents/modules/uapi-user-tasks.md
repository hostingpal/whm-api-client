# UAPI user task queue

> Agent contract notes for `uapi()->userTasks()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### UAPI user task queue (2026-11-01)

`uapi()->userTasks()` covers both pinned UserTasks functions from cPanel 72 for
the selected account. `listTasks()` sends one GET and returns the queue in server
order, including an empty list. Row `action`, `args`, `id` and `subsystem` fields
remain optional. The source-inferred compatibility additions are optional
nonnullable string `sse_url` and optional nonnullable `exclusive` as `string|int`.
`exclusive` is a key, not a boolean flag: strings (including empty and numeric
strings) and native integers retain their primitive values without coercion or
flag bounds. `sse_url` is a relative progress path, not a full URL.

Present `args` remains a closed object with optional strings `repository_root`,
`domain`, `install_id` and `log_file`. Unknown row and argument fields, including
`title` and `site_settings`, are filtered. Empty strings and omissions remain
valid; explicit null and other primitive/container types fail these string leaves.
The pinned `create`/`VersionControl`-only prose is incomplete: reviewer-retained
WPX source queues `install` and `import`. Action and subsystem remain open strings;
these examples do not define a closed enum or arbitrary future argument contracts.

The five additions follow attributed source review
[5725807972](https://github.com/hostingpal/whm-api-client/pull/105#pullrequestreview-5725807972)
and retained-source answer
[6560200145](https://github.com/hostingpal/whm-api-client/pull/105#issuecomment-6560200145).
Producer string expressions and the integer-zero `exclusive` default support the
compatibility policy; `Directory::Queue::Normal` serialization and `Task.adopt`
were not inspected. Exact wire types and universal nullability remain unproven.

```php
$tasks = $account->userTasks()->listTasks();
// An explicit deletion call with a synthetic task ID; use an intentionally selected ID.
$deleted = $account->userTasks()->deleteTask(id: '00000000\/5a9ec8dd4c345d');
```

`deleteTask(id)` sends one form POST. Its required string ID retains every byte:
literal backslash/slash, slash, percent escapes, Unicode, plus and empty string
are distinct values, encoded once without repair, normalization or unescaping.
The result is a nullable opaque object: object fields survive, explicit null is
returned as null, and missing data fails validation. Nullable is not void.
Inventory rejects missing/null roots and invalid known field types. Existing
empty-array object compatibility is retained; the decoder distinguishes `{}` and
`[]`. Post-processing `metadata.transformed` and warnings remain available through
`responseMeta()`, separate from task payloads and the UAPI outcome status.

Deleting a queue entry does not establish task completion or running-process
termination. The client performs no implicit inventory, polling, task execution,
retry, restore or rollback. Each explicit call makes at most one request; API or
outer-WHM failures precede payload validation. A payload mismatch after API success
can follow a state change, so do not retry automatically.

Task IDs, arguments, repository paths and progress paths are private data. WPX
import `args.site_settings` can be JSON containing `source_password`. Typed lists
filter it without parsing it; raw responses, generic `call()` payloads and
`PayloadMismatchException::$payload` can retain the password. Avoid logging those
values indiscriminately. Envelope metadata does not always contain task payloads.
IDs remain sensitive method parameters as client privacy policy, without claiming
they are authentication tokens. Library-owned trace arguments conceal sensitive
inputs and responses; explicit application logging is outside that protection.
Returned paths are never opened or fetched.

Generated coverage is 1,240 canonical operations (644 UAPI, 596 WHM), 114 groups
and 231 outputs (230 PHP files plus coverage), adding the original two operations
and one group to approved master `2b3e61c0abbc9e2556d81c8253767c67f6f0113a`.
The five response leaves add no endpoints. Delivery `whm-api-client-8x0.4.30` and
this correction have independent synthetic offline coverage; synthetic WPX rows
are not real queued execution or capture. Reviewer-injected rows bypassed the
producer add method and establish no producer-wire type proof. Live follow-up
`whm-api-client-8x0.2.72` and serialization follow-up `8x0.2.72.1` remain OPEN.
No runtime task success, cleanup or held-lock behavior is established here; no
queue entry, path, process, account or server was accessed by this correction.
