# WHM JSON configuration

> Agent contract notes for typed whole-body JSON WHM methods. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WHM JSON configuration

Three generated methods send one JSON POST through the configured WHM route:
`whm()->webServer()->phpFpmConfigGet(array $configuration): array`,
`phpFpmConfigSet(array $configuration): void`, and
`whm()->databases()->updateSqlConfig(array $configuration): void`. Each required
whole-body array has `SensitiveParameter`, no default and no nullable alternative.
The input aliases are `PhpFpmConfigGetInput`, `PhpFpmConfigSetInput` and
`UpdateSqlConfigInput`. Selectors are lazy and cached. The request has exactly
`Content-Type: application/json`, no query string and a library-owned root
`"api.version":1` member inserted first (WHM reads JSON arguments only from the
body, so a query version gets HTTP 400 per review 5726712933); body fields
never become query/form fields. All `api.` root controls remain reserved.

PHP-FPM methods require cPanel 78 and the Web Server role. The get method remains
POST despite its name and source `x-readonly: true`. Its root has only optional
`domain: string`; `[]` sends the required present `{}`. Omitted domain keeps the
source empty/system default; an explicit empty string stays supplied. A nonempty
local domain selects that domain's configuration. The response requires nonnull
object data with optional `config`, a list of objects with optional string
`base_flag_name`, integer `trinary_admin_value` and string `value`. Omitted fields,
empty lists and empty objects remain distinct; existing empty-array object
compatibility and unknown-field filtering remain. Null or missing root data fails.

The setter requires `config`, a list of objects requiring all three fields:
`base_flag_name: string`, `trinary_admin_value: int`, `value: string`. Optional
`domain` is a string and optional `validate_only` is a native bool. Omitted domain
retains the empty/system default; omitted `validate_only` retains false. Explicit
false/true encode as JSON booleans, while trinary 0 (pool), 1 (INI ADMIN) and
2 (INI) remain integers. General enum/range enforcement is unchanged, so other
integers are type-valid without a server-acceptance promise. `config: []` is
formally permitted; no no-op or reset behavior is inferred. Setting configuration
can affect applications. The source recommends at least 2 GB RAM available or
30 MB per domain before enabling Apache PHP-FPM, to avoid severe performance
issues. Validation-only is a documented mode, not proof of no side effects.

SQL configuration requires cPanel 11.100; it is disabled when the MySQL/MariaDB
role is disabled and remote MySQL is not configured. Root `data` is a required
list whose object rows have only optional `name: string`, `remove: bool`,
`section: string` and `value: string`. `data: []` sends `[]`, whereas `data: [[]]`
sends `[{}]`. Native false stays false and zero-looking values remain strings.
Empty rows/lists imply no server acceptance, no-op or atomicity guarantee.

```php
// These explicit calls send requests; all values shown are synthetic.
$system = $client->whm()->webServer()->phpFpmConfigGet(configuration: []);
$client->whm()->webServer()->phpFpmConfigSet(configuration: [
    'domain' => 'example.test',
    'config' => [
        ['base_flag_name' => 'pm_max_children', 'trinary_admin_value' => 0, 'value' => '10'],
    ],
    'validate_only' => true,
]);
$client->whm()->databases()->updateSqlConfig(configuration: ['data' => [
    ['name' => 'max_allowed_packet', 'section' => 'mysqld', 'value' => '268435456', 'remove' => false],
]]);
// Inspect responseMeta() warnings and raw metadata after each call.
```

Both setters return void because the formal responses declare metadata only.
The existing void policy ignores unspecified data if present and checks normal
WHM Flag outcomes. Metadata result 0 wins over invalid payload data. Success
alone does not establish application or persistence. Get payload mismatches after
result 1 retain confirmed-outcome metadata; callers inspect warnings and snapshots.

The pinned PHP-FPM HTTP samples use port 2083/cpsess cookies and include quoted
`api.version` in the body despite WHM identity. The client retains its configured
WHM route/authentication and reserved body controls. Set samples also quote trinary
values, contradicting the formal integer schema; strict formal integers govern,
without coercion. Samples are source evidence, not live observations.

Source names, array insertion order, row order/duplicates and string bytes are
preserved. Unknown members, nulls, wrong native types, non-list/sparse collections,
objects/resources/JsonSerializable and malformed UTF-8 reject before I/O after
metadata reset; an invalid last row sends no partial request. PHP signature errors
occur before method entry and retain the previous snapshot. Whole bodies are
private. Existing library frames hide sensitive arguments/responses; returned
configuration, mismatch payloads, raw metadata and caller/third-party transport
traces still require care. The client evaluates no INI/SQL/path/URL values and adds
no implicit read, polling, auth/account change, retry, merge, restore or rollback.

Coverage is 1,234 canonical operations (636 UAPI, 598 WHM), 119 groups and
241 generated outputs. Four of thirteen pinned JSON-body operations now have
typed methods; nine remain deferred under the broader `8x0.29.6` audit.
StatsManager stays direct-UAPI-only with its independent successful-live-write
gates `8x0.1.18`/`8x0.1.19` open. WHM configuration delivery is `8x0.4.39`;
its separate live follow-up `8x0.2.83` stays OPEN. All fixtures are offline and
synthetic: no request, validation-only call, server setting or credential access
ran. No persistent configuration, role enforcement or live acceptance is established.
All 55 inherited source diagnostics remain visible and unwaived.
