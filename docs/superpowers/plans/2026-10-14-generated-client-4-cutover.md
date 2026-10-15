# Generated Client, Plan 4: Cutover of the Existing 39 Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-written operation groups and DTOs with generated groups for the 39 existing operations, proven equivalent by the existing tests (adapted to the agreed renames and array returns) and the read-only live suite.

**Architecture:** Overlay files for the 8 in-scope groups (plus exclusion-only files for the 13 deprecated operations) drive `composer api-map:generate`. The handles use the generated `WhmGroups`/`UapiGroups` traits and a private `run()`/`dispatch()` pair that feeds `OperationRunner` (plan 1) through the existing request lifecycle. Hand-written groups and DTO classes are deleted; their tests are adapted to the new method names and array shapes and serve as the equivalence oracle.

**Tech Stack:** PHP 8.3 (PHPUnit 12, PHPStan max, PHPCS), Go 1.24 generator from plan 2, Composer scripts.

**Spec:** `docs/superpowers/specs/2026-10-14-generated-client-design.md` (sections "Public API surface", "Overlay", "Migration and phasing" phase 1).

**Prerequisite:** Plans 1, 2 and 3 are merged into `master`. Work on a branch `feat/generated-client-cutover` from up-to-date `master`.

**Scope note (deviation from the spec's phase 1 wording):** the existing hand-written tests are adapted and kept as the equivalence oracle. Generated fixture rows need probe recipes and live fingerprints, which phase 2 introduces; converting these tests into fixture rows happens there. Task 5 records this in the spec.

## Global Constraints

- Generated files are never edited by hand; fix the overlay or the generator (with golden-file updates in `tools/api-map/internal/phpgen/testdata/golden/`) and regenerate.
- Every overlay correction and exclusion carries evidence; live evidence for this plan cites `docs/api/source-audit.md` runtime observations (cPanel `11.138.0.15`).
- Breaking renames and array returns are intended; behaviour is not: validation, normalization of observed variants, omission versus explicit null, metadata lifecycle, outcome checks, POST for mutations and secrets, `#[SensitiveParameter]` on secrets must all be preserved.
- PHPStan maximum level and PHPCS pass with no ignores; `composer api-map:check` and `composer api-map:generate:check` pass.
- Live verification uses only the read-only `live` group on `test.oblak.host` (`WHM_API_LIVE=1 composer test:live`); the `live-mutating` group is not run in this plan. Never print or store tokens.
- Agent guidance lives in `AGENTS.md` only.

## Review Focus

- **Positional argument order for two-string mutations.** `changePackage`, `changePassword` and `verifyUserHasFeature` must keep `$user` first; a swapped order silently sends wrong values. Pinned in Task 1 (`order` entries) and Task 3 (adapted `WhmAccountsTest`/`WhmFeaturesTest` assertions on the encoded body/query).
- **Secret-bearing parameters.** `createAccount` (`pass`, `password`), `changePassword` (`password`) and `modifyAccount` (`PUSHBULLET_ACCESS_TOKEN`) must send POST bodies and carry `#[SensitiveParameter]`. Pinned in Task 2, Step 6 (generated-code assertions) and Task 3 (adapted POST encoding tests).
- **Observed wire variants.** String ports and `uid`, string and boolean feature flags, decimal-string load averages and null inode counts must still be accepted and normalized. Pinned in Task 3 (adapted runtime-compatibility tests) and Task 4 (live suite).
- **Unwrapped scalars must be present.** Methods that previously returned a scalar or list (for example `version()`, `phpGetInstalledVersions()`) must still reject a missing field. Pinned in Task 3 (adapted malformed-payload tests).
- **Account isolation and metadata lifecycle through the new dispatch.** Generated groups must update only their own handle's `responseMeta()` and clear it before validation and I/O. Pinned in Task 3 (adapted `InventorySelectorsTest` and isolation tests).

---

## File Structure

| File | Change |
| --- | --- |
| `docs/api/overlay/whm/*.json`, `docs/api/overlay/uapi/*.json` (create) | Overlay for the 39 operations and the 13 exclusions. |
| `src/Api/Whm/{Server,Domains,Php,Features,Accounts}.php`, `src/Api/Uapi/{Domains,Php,Dns,Features}.php` (delete) | Hand-written groups. |
| `src/Response/{AccountDomains,DomainInfo,ServerDomains,DiskPartition,ServerDiskUsage,ServerLoadAverage,FeatureCatalog,FeatureListData,FeatureMetadata,FeatureSetting,UserFeatureSetting,UserFeatureSettings}.php` (delete) | DTOs. |
| `src/Api/Whm/*.php`, `src/Api/Whm/Spec/*.php`, `src/Api/Whm/WhmGroups.php`, `src/Api/Uapi/...` (generated) | Generated groups, specs, traits. |
| `src/WhmClient.php`, `src/UapiClient.php` (modify) | Use generated traits; add `run()`/`dispatch()`. |
| `docs/api/coverage.md` (generated) | Coverage summary. |
| `tests/Api/*`, `tests/Response/*`, `tests/Live/*`, `tests/ClientTest.php`, `tests/Exception/ApiExceptionTest.php` (modify/delete) | Adapt to generated API; delete DTO tests. |
| `README.md`, `AGENTS.md`, `docs/superpowers/specs/2026-10-14-generated-client-design.md`, `docs/superpowers/specs/2026-10-13-php-client-design.md` (modify) | Document the generated surface. |

### Method mapping (old → new)

| Old call | New call | Return change |
| --- | --- | --- |
| `whm()->server()->version()` | `whm()->server()->version()` | unchanged (`string`) |
| `whm()->server()->hostname()` | `whm()->server()->getHostname()` | unchanged (`string`) |
| `whm()->server()->loadAverage()` | `whm()->server()->getLoadAverage()` | `ServerLoadAverage` → `array{fifteen?: int\|float\|numeric-string, five?: …, one?: …}` |
| `whm()->server()->diskUsage()` | `whm()->server()->getDiskUsage()` | `ServerDiskUsage` → `array{partition?: list<array{…}>}` |
| `whm()->domains()->list()` | `whm()->accounts()->getDomainInfo()` | `ServerDomains` → `array{domains?: list<array{…}>}`; ports normalized to `int` |
| `whm()->php()->listVersions()` | `whm()->webServer()->phpGetInstalledVersions()` | unchanged (`list<string>`) |
| `whm()->php()->systemDefaultVersion()` | `whm()->webServer()->phpGetSystemDefaultVersion()` | unchanged (`string`) |
| `whm()->php()->handlers($v)` | `whm()->webServer()->phpGetHandlers($v)` | unchanged (`list<array{…}>`) |
| `whm()->php()->defaultAccountsToFpm()` | `whm()->webServer()->phpGetDefaultAccountsToFpm()` | `bool` → `0\|1` |
| `whm()->php()->oldFpmFlag()` | `whm()->webServer()->phpGetOldFpmFlag()` | unchanged (`int`) |
| `whm()->php()->isConversionInProgress()` | `whm()->webServer()->isConversionInProgress()` | `bool` → `0\|1` |
| `whm()->php()->vhostsByVersion($v)` | `whm()->webServer()->phpGetVhostsByVersion($v)` | unchanged |
| `whm()->features()->metadata()` | `whm()->hostingPlans()->getFeatureMetadata()` | `FeatureCatalog` → array; `is_plugin` normalized to `0\|1` |
| `whm()->features()->names()` | `whm()->hostingPlans()->getFeatureNames()` | unchanged (array) |
| `whm()->features()->listData($l)` | `whm()->hostingPlans()->getFeaturelistData($l)` | `FeatureListData` → array; flags normalized to `0\|1` |
| `whm()->features()->lists()` | `whm()->hostingPlans()->getFeaturelists()` | unchanged (array) |
| `whm()->features()->listsByPackageType()` | `whm()->hostingPlans()->getFeaturelistsByPackageTypes()` | unchanged (array) |
| `whm()->features()->userSettings($u, $f)` | `whm()->hostingPlans()->getUsersFeaturesSettings($u, $f)` | `UserFeatureSettings` → array; `feature_list_setting` normalized |
| `whm()->features()->userHasFeature($u, $f)` | `whm()->hostingPlans()->verifyUserHasFeature($u, $f)` | `bool` → `0\|1` |
| `whm()->accounts()->list(...)` | `whm()->accounts()->listAccounts(...)` | array; `uid` normalized to `int` |
| `whm()->accounts()->summary($u)` | `whm()->accounts()->accountSummary(user: $u)` | array |
| `whm()->accounts()->packages($w)` | `whm()->hostingPlans()->listPackages($w)` | array |
| `whm()->accounts()->create($n, $opts)` | `whm()->accounts()->createAccount($n, ...named options)` | array |
| `whm()->accounts()->suspend(...)` | `whm()->accounts()->suspendAccount(...)` | `void` |
| `whm()->accounts()->unsuspend(...)` | `whm()->accounts()->unsuspendAccount(...)` | `void` |
| `whm()->accounts()->remove($n, $keep)` | `whm()->accounts()->removeAccount($n, $keep)` | `void` |
| `whm()->accounts()->changePassword($u, $p, ...)` | `whm()->accounts()->changePassword($u, $p, ...)` | array |
| `whm()->accounts()->changePackage($u, $p)` | `whm()->accounts()->changePackage($u, $p)` | `void` |
| `whm()->accounts()->modify($u, $changes)` | `whm()->accounts()->modifyAccount($u, ...named changes)` | array |
| `uapi()->domains()->list($h)` | `uapi()->domainInfo()->listDomains($h)` | `AccountDomains` → array |
| `uapi()->domains()->primary($h)` | `uapi()->domainInfo()->primaryDomain($h)` | unchanged (`?string`) |
| `uapi()->domains()->builtinAliases($h)` | `uapi()->domainInfo()->mainDomainBuiltinSubdomainAliases($h)` | unchanged |
| `uapi()->php()->listVersions()` | `uapi()->langPhp()->phpGetInstalledVersions()` | unchanged |
| `uapi()->php()->systemDefaultVersion()` | `uapi()->langPhp()->phpGetSystemDefaultVersion()` | unchanged (`?string`) |
| `uapi()->php()->domainHandler($t, $v)` | `uapi()->langPhp()->phpGetDomainHandler($t, $v)` | unchanged |
| `uapi()->php()->impactedDomains($d, $s)` | `uapi()->langPhp()->phpGetImpactedDomains($d, $s)` | unchanged |
| `uapi()->features()->list()` | `uapi()->features()->listFeatures()` | unchanged |
| `uapi()->dns()->lookup($d)` | `uapi()->dns()->lookup($d)` | unchanged |
| `uapi()->dns()->generatedDomains($d)` | `uapi()->dns()->fetchCpanelGeneratedDomains($d)` | unchanged |

DTO property access becomes array access with the wire key (`$domains->mainDomain` → `$domains['main_domain']`); omitted fields are absent keys (`array_key_exists`), explicit nulls stay `null`; `toArray()` calls disappear.

---

### Task 1: Overlay for the existing operations and the exclusions

**Files:**
- Create: the 17 overlay files below.
- Delete: `docs/api/overlay/uapi/.gitkeep`, `docs/api/overlay/whm/.gitkeep`.

**Interfaces:**
- Produces: an overlay that `api-map overlay validate` accepts with `8 groups, 39 operations, 13 excluded`.

- [ ] **Step 1: Run validation to see the empty state**

Run (with `GOCACHE`, `GOMODCACHE`, `GOPROXY=off`, `GOSUMDB=off`):
```bash
go -C tools/api-map run ./cmd/api-map map build --manifest ../../docs/api/sources/2026-10-13/manifest.json --annotations ../../docs/api/annotations.json --output ../../.cache/api-map/map.json
go -C tools/api-map run ./cmd/api-map overlay validate --map ../../.cache/api-map/map.json --overlay ../../docs/api/overlay
```
Expected: `overlay valid: 0 groups, 0 operations, 0 excluded`.

- [ ] **Step 2: Write the WHM overlay files**

The evidence objects used below are:

- `LOAD`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-13", "note": "systemloadavg returned load averages as decimal strings (for example \"0.50\"); docs/api/source-audit.md#runtime-observations-2026-10-13"}`
- `INODES`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-13", "note": "getdiskusage returned explicit null for the four inode fields; docs/api/source-audit.md#runtime-observations-2026-10-13"}`
- `PORTS`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-13", "note": "get_domain_info returned port and port_ssl as decimal strings (80, 443); docs/api/source-audit.md#runtime-observations-2026-10-13"}`
- `UID`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-14", "note": "listaccts and accountsummary returned uid as a decimal string; docs/api/source-audit.md"}`
- `FLAGS`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-13", "note": "feature endpoints returned exact string flags \"0\"/\"1\"; docs/api/source-audit.md#runtime-observations-2026-10-13"}`
- `BOOLS`: `{"kind": "live", "version": "11.138.0.15", "date": "2026-10-13", "note": "get_featurelist_data returned booleans for presentation flags; docs/api/source-audit.md#runtime-observations-2026-10-13"}`
- `DEPRECATED(note)`: `{"kind": "doc", "version": "pinned 2026-10-13", "date": "2026-10-13", "note": "<note>"}`

Write them out literally (JSON has no references) in each file.

`docs/api/overlay/whm/server-administration.json`:

```json
{
  "schema_version": "1.0",
  "family": "whm1",
  "group": "Server Administration",
  "accessor": "server",
  "operations": {
    "whm1:getdiskusage": {
      "name": "getDiskUsage",
      "response": {
        "partition[].inodes_available": {"nullable": true, "evidence": [INODES]},
        "partition[].inodes_ipercentage": {"nullable": true, "evidence": [INODES]},
        "partition[].inodes_total": {"nullable": true, "evidence": [INODES]},
        "partition[].inodes_used": {"nullable": true, "evidence": [INODES]}
      }
    },
    "whm1:gethostname": {"name": "getHostname", "unwrap": "hostname"},
    "whm1:systemloadavg": {
      "name": "getLoadAverage",
      "response": {
        "fifteen": {"accept": ["numeric-string"], "preserve": true, "evidence": [LOAD]},
        "five": {"accept": ["numeric-string"], "preserve": true, "evidence": [LOAD]},
        "one": {"accept": ["numeric-string"], "preserve": true, "evidence": [LOAD]}
      }
    },
    "whm1:version": {"name": "version", "unwrap": "version"}
  }
}
```

`docs/api/overlay/whm/accounts.json`:

```json
{
  "schema_version": "1.0",
  "family": "whm1",
  "group": "Accounts",
  "accessor": "accounts",
  "operations": {
    "whm1:accountsummary": {
      "name": "accountSummary",
      "response": {"acct[].uid": {"accept": ["numeric-string"], "evidence": [UID]}}
    },
    "whm1:changepackage": {
      "name": "changePackage",
      "order": ["user", "pkg"],
      "params": {"pkg": {"php": "package"}}
    },
    "whm1:createacct": {"name": "createAccount"},
    "whm1:get_domain_info": {
      "response": {
        "domains[].port": {"accept": ["numeric-string"], "evidence": [PORTS]},
        "domains[].port_ssl": {"accept": ["numeric-string"], "evidence": [PORTS]}
      }
    },
    "whm1:listaccts": {
      "name": "listAccounts",
      "response": {"acct[].uid": {"accept": ["numeric-string"], "evidence": [UID]}}
    },
    "whm1:modifyacct": {
      "name": "modifyAccount",
      "params": {
        "MXCHECK-*": {"omit": true},
        "PUSHBULLET_ACCESS_TOKEN": {"sensitive": true}
      }
    },
    "whm1:passwd": {
      "name": "changePassword",
      "order": ["user", "password"],
      "params": {"enabledigest": {"omit": true}}
    },
    "whm1:removeacct": {"name": "removeAccount", "params": {"user": {"omit": true}}},
    "whm1:suspendacct": {"name": "suspendAccount"},
    "whm1:unsuspendacct": {"name": "unsuspendAccount"}
  }
}
```

`docs/api/overlay/whm/hosting-plans.json`:

```json
{
  "schema_version": "1.0",
  "family": "whm1",
  "group": "Hosting Plans",
  "accessor": "hostingPlans",
  "operations": {
    "whm1:get_available_featurelists": {"exclude": {"reason": "Deprecated; use getFeaturelists() (get_featurelists), which does not treat an empty list as an error for resellers.", "evidence": [DEPRECATED("Flagged deprecated: true; DEPRECATED: Use get_featurelists instead.")]}},
    "whm1:get_feature_metadata": {
      "response": {"features[].is_plugin": {"accept": ["string-flag"], "evidence": [FLAGS]}}
    },
    "whm1:get_feature_names": {},
    "whm1:get_featurelist_data": {
      "response": {
        "features[].is_disabled": {"accept": ["string-flag"], "evidence": [FLAGS]},
        "features[].is_meridian_only": {"accept": ["bool"], "evidence": [BOOLS]},
        "features[].is_standalone_experience": {"accept": ["bool"], "evidence": [BOOLS]},
        "features[].suppress_plugin_label": {"accept": ["bool"], "evidence": [BOOLS]},
        "features[].value": {"accept": ["string-flag"], "evidence": [FLAGS]}
      }
    },
    "whm1:get_featurelists": {},
    "whm1:get_featurelists_by_package_types": {},
    "whm1:get_users_features_settings": {
      "params": {
        "feature": {"php": "features", "encoding": "numbered"},
        "user": {"php": "users", "encoding": "numbered"}
      },
      "response": {"users_features_settings[].feature_list_setting": {"accept": ["string-flag"], "evidence": [FLAGS]}}
    },
    "whm1:listpkgs": {"name": "listPackages"},
    "whm1:read_featurelist": {"exclude": {"reason": "Deprecated; use getFeaturelistData() (get_featurelist_data).", "evidence": [DEPRECATED("Flagged deprecated: true; DEPRECATED: Use get_featurelist_data instead.")]}},
    "whm1:verify_user_has_feature": {"order": ["user", "feature"], "unwrap": "has_feature"}
  }
}
```

`docs/api/overlay/whm/web-server-configuration.json`:

```json
{
  "schema_version": "1.0",
  "family": "whm1",
  "group": "Web Server Configuration",
  "accessor": "webServer",
  "operations": {
    "whm1:is_conversion_in_progress": {"unwrap": "inProgress"},
    "whm1:php_get_default_accounts_to_fpm": {"unwrap": "default_accounts_to_fpm"},
    "whm1:php_get_handlers": {"unwrap": "version_handlers"},
    "whm1:php_get_installed_versions": {"unwrap": "versions"},
    "whm1:php_get_old_fpm_flag": {"unwrap": "old_fpm_flag"},
    "whm1:php_get_system_default_version": {"unwrap": "version"},
    "whm1:php_get_vhosts_by_version": {"unwrap": "vhosts"}
  }
}
```

Exclusion-only WHM files (each `{"schema_version": "1.0", "family": "whm1", "group": <group>, "accessor": <accessor>, "operations": {...}}`):

| File | `group` | `accessor` | Excluded operation → reason / evidence note |
| --- | --- | --- | --- |
| `whm/authentication.json` | `Authentication` | `authentication` | `whm1:accesshash` → "Deprecated legacy access hash; API tokens replace it (api_token_create), not as a drop-in." / "Flagged deprecated: true; We deprecated this function." |
| `whm/mail.json` | `Mail` | `mail` | `whm1:disable_mail_sni` → "Deprecated and always fails." / "Flagged deprecated: true; This function is deprecated and always fails." |
| `whm/dns.json` | `DNS` | `dns` | `whm1:dumpzone` → "Deprecated; use parse_dns_zone, which returns the same zone data in a different format." / "Flagged deprecated: true; This function is deprecated." |
| `whm/commerce-integration.json` | `Commerce Integration` | `commerce` | `whm1:get_ecommerce` and `whm1:set_ecommerce` → "Deprecated without a successor." / "Flagged deprecated: true." |
| `whm/integrations.json` | `Integrations` | `integrations` | `whm1:get_remote_access_hash` → "Deprecated remote access key; API tokens replace it (api_token_list), not as a drop-in." / "Flagged deprecated: true; We deprecated this function." |

Each exclusion entry has the form `{"exclude": {"reason": "<reason>", "evidence": [{"kind": "doc", "version": "pinned 2026-10-13", "date": "2026-10-13", "note": "<note>"}]}}`.

- [ ] **Step 3: Write the UAPI overlay files**

`docs/api/overlay/uapi/DNS.json`:

```json
{
  "schema_version": "1.0",
  "family": "uapi",
  "group": "DNS",
  "operations": {
    "uapi:DNS/fetch_cpanel_generated_domains": {},
    "uapi:DNS/lookup": {"name": "lookup"}
  }
}
```

`docs/api/overlay/uapi/DomainInfo.json`:

```json
{
  "schema_version": "1.0",
  "family": "uapi",
  "group": "DomainInfo",
  "operations": {
    "uapi:DomainInfo/list_domains": {},
    "uapi:DomainInfo/main_domain_builtin_subdomain_aliases": {},
    "uapi:DomainInfo/primary_domain": {"unwrap": "primary_domain"}
  }
}
```

`docs/api/overlay/uapi/Features.json`:

```json
{
  "schema_version": "1.0",
  "family": "uapi",
  "group": "Features",
  "operations": {
    "uapi:Features/list_features": {}
  }
}
```

`docs/api/overlay/uapi/LangPHP.json`:

```json
{
  "schema_version": "1.0",
  "family": "uapi",
  "group": "LangPHP",
  "operations": {
    "uapi:LangPHP/php_get_domain_handler": {"unwrap": "php_handler"},
    "uapi:LangPHP/php_get_impacted_domains": {
      "params": {"domain": {"php": "domains", "encoding": "repeat"}},
      "unwrap": "domains"
    },
    "uapi:LangPHP/php_get_installed_versions": {"unwrap": "versions"},
    "uapi:LangPHP/php_get_system_default_version": {"unwrap": "version"}
  }
}
```

Exclusion-only UAPI files (`{"schema_version": "1.0", "family": "uapi", "group": <module>, "operations": {...}}`):

| File | Excluded operation → reason / evidence note |
| --- | --- |
| `uapi/DCV.json` | `uapi:DCV/ensure_domains_can_pass_dcv` → "Deprecated; use DCV/check_domains_via_http." / "Flagged deprecated: true; We deprecated this function." |
| `uapi/SSL.json` | `uapi:SSL/check_shared_cert` → "Deprecated without a successor." / "Flagged deprecated: true." |
| `uapi/Sitejet.json` | `uapi:Sitejet/get_templates`, `uapi:Sitejet/set_template` → "Deprecated without a successor." / "Flagged deprecated: true." |
| `uapi/Themes.json` | `uapi:Themes/get_theme_base` → "Deprecated; does not return useful output." / "Flagged deprecated: true; This function is deprecated and does not return useful output." |

- [ ] **Step 4: Validate and resolve reported problems**

Run: `go -C tools/api-map run ./cmd/api-map overlay validate --map ../../.cache/api-map/map.json --overlay ../../docs/api/overlay`
Expected: `overlay valid: 8 groups, 39 operations, 13 excluded`.

If it reports `duplicate argument name` (for example among `createacct`/`modifyacct` parameters that differ only in case) or `needs an overlay php name`, add `"params": {"<wire name>": {"php": "<distinct camelCase name>"}}` entries to that operation and re-run. If it reports a correction path that is not documented, check the path against the operation's documented payload (`partition[]`, `domains[]`, `acct[]`, `features[]`, `users_features_settings[]`) rather than deleting the correction. Do not proceed until validation is clean.

- [ ] **Step 5: Commit**

```bash
git rm -q docs/api/overlay/uapi/.gitkeep docs/api/overlay/whm/.gitkeep
git add docs/api/overlay
git commit -m "feat: overlay for the existing 39 operations and 13 exclusions"
```

### Task 2: Generate the groups and wire the handles

**Files:**
- Delete: hand-written groups and DTOs listed in the File Structure.
- Generated: `src/Api/Whm/**`, `src/Api/Uapi/**`, `docs/api/coverage.md`.
- Modify: `src/WhmClient.php`, `src/UapiClient.php`.

**Interfaces:**
- Consumes: plan 1 `OperationRunner::run(array $spec, array $arguments, Closure $dispatch): mixed`, `OperationSpec::text/method/outcome`; generated traits calling `$this->run(...)`.
- Produces: handles with `private function run(array $spec, array $arguments): mixed` and `private function dispatch(array $spec, array $parameters): ApiResponse`; public group accessors from the traits (`whm()->server()`, `accounts()`, `hostingPlans()`, `webServer()`; `uapi()->dns()`, `domainInfo()`, `features()`, `langPhp()`).

- [ ] **Step 1: Delete the hand-written groups and DTOs**

```bash
git rm -q src/Api/Whm/Server.php src/Api/Whm/Domains.php src/Api/Whm/Php.php src/Api/Whm/Features.php src/Api/Whm/Accounts.php
git rm -q src/Api/Uapi/Domains.php src/Api/Uapi/Php.php src/Api/Uapi/Dns.php src/Api/Uapi/Features.php
git rm -q src/Response/AccountDomains.php src/Response/DomainInfo.php src/Response/ServerDomains.php src/Response/DiskPartition.php src/Response/ServerDiskUsage.php src/Response/ServerLoadAverage.php src/Response/FeatureCatalog.php src/Response/FeatureListData.php src/Response/FeatureMetadata.php src/Response/FeatureSetting.php src/Response/UserFeatureSetting.php src/Response/UserFeatureSettings.php
```

- [ ] **Step 2: Generate**

Run: `composer api-map:generate`
Expected: `generated 19 files, removed 0 stale` (8 group classes, 8 spec classes, 2 traits and `docs/api/coverage.md`).

- [ ] **Step 3: Wire WhmClient**

In `src/WhmClient.php`:
- Remove the `use HostingPal\WhmApiClient\Api\Whm\{Accounts,Domains,Features,Php,Server};` imports, the five `private ?<Group> $... = null;` properties and the five accessor methods.
- Add `use WhmGroups;` as the first line in the class body, and imports `HostingPal\WhmApiClient\Api\Whm\WhmGroups`, `HostingPal\WhmApiClient\Api\Engine\OperationRunner`, `HostingPal\WhmApiClient\Api\Engine\OperationSpec` (keep imports sorted).
- Add before the existing private `request()` method:

```php
    /**
     * Run a generated operation spec through this handle.
     *
     * Generated groups receive this method as their run callback; selecting a
     * group performs no I/O.
     *
     * @param array<mixed> $spec
     * @param array<string, mixed> $arguments
     */
    private function run(array $spec, array $arguments): mixed
    {
        return OperationRunner::run($spec, $arguments, $this->dispatch(...));
    }

    /**
     * Route a generated operation to WHM API 1 with the spec's method and outcome policy.
     *
     * @param array<mixed> $spec
     * @param Parameters $parameters
     */
    private function dispatch(array $spec, array $parameters): ApiResponse
    {
        return $this->request(
            OperationSpec::text($spec, 'function'),
            $parameters,
            OperationSpec::method($spec),
            OperationSpec::outcome($spec),
        );
    }
```

- Update the class docblock: "Typed groups are generated from the pinned API map and overlay (see docs/api/coverage.md); call() reaches every documented WHM API 1 function through the same routing, authentication, and envelope handling."

- [ ] **Step 4: Wire UapiClient**

In `src/UapiClient.php` make the same changes with `use UapiGroups;` (`HostingPal\WhmApiClient\Api\Uapi\UapiGroups`), removing the hand-written `domains()`, `php()`, `dns()`, `features()` accessors and their properties/imports, and this dispatch:

```php
    /**
     * Route a generated operation for this account through direct cPanel or WHM.
     *
     * @param array<mixed> $spec
     * @param Parameters $parameters
     */
    private function dispatch(array $spec, array $parameters): ApiResponse
    {
        return $this->request(
            OperationSpec::text($spec, 'module'),
            OperationSpec::text($spec, 'function'),
            $parameters,
            OperationSpec::method($spec),
            OperationSpec::outcome($spec),
        );
    }
```

plus the same `run()` method as WhmClient. Update its class docblock the same way.

- [ ] **Step 5: Static checks on the generated code**

Run: `vendor/bin/phpstan analyse --memory-limit=512M --no-progress src && vendor/bin/phpcs -q src`
Expected: no errors. If the generated code fails a rule, fix `tools/api-map/internal/phpgen` (and its golden files with `go -C tools/api-map test ./internal/phpgen/ -run Golden -update`, reviewing the diff), then re-run `composer api-map:generate`. Never edit generated files.

- [ ] **Step 6: Inspect the generated surface**

Run these and confirm each expectation:
```bash
grep -n 'public function' src/Api/Whm/Accounts.php
grep -n -B1 'password\|PUSHBULLET' src/Api/Whm/Accounts.php | grep -c SensitiveParameter
grep -n "'method' =>" src/Api/Whm/Spec/AccountsSpec.php
grep -n 'function changePackage\|function changePassword' -A3 src/Api/Whm/Accounts.php
grep -n 'function verifyUserHasFeature' -A3 src/Api/Whm/HostingPlans.php
cat docs/api/coverage.md
```
Expected: the ten Accounts methods from the mapping table; `SensitiveParameter` precedes `$pass`, `$password` (createAccount, changePassword) and `$pushbulletAccessToken`; every mutating operation in `AccountsSpec` has `'method' => 'POST'` and read-only ones `'GET'`; `changePackage(string $user, string $package)`, `changePassword(string $user, string $password, ...)`, `verifyUserHasFeature(string $user, string $feature)`; the coverage summary shows UAPI 4 groups/10 operations/5 excluded and WHM API 1 4 groups/29 operations/8 excluded.

- [ ] **Step 7: Commit (tests are adapted in Task 3)**

```bash
git add -A src docs/api/coverage.md
git commit -m "feat: generate the existing operation groups and wire the handles"
```

### Task 3: Adapt the offline tests

**Files:**
- Delete: `tests/Response/{AccountDomains,DiskPartition,DomainInfo,ServerDiskUsage,ServerDomains,ServerLoadAverage,FeatureMetadata,FeatureSetting,UserFeatureSetting}Test.php`
- Modify: `tests/Api/{ReadOnlySliceTest,WhmServerTest,WhmDomainsTest,WhmPhpTest,WhmFeaturesTest,WhmAccountsTest,UapiPhpTest,UapiInventoryTest,InventorySelectorsTest,TransportFailureTest,PostEncodingTest,GenericCallTest}.php`, `tests/ClientTest.php`, `tests/Exception/ApiExceptionTest.php`

**Interfaces:**
- Consumes: the method mapping table above; generated accessors and methods.
- Produces: an offline suite that exercises every one of the 39 operations through the generated groups with the same behavioural assertions as before.

- [ ] **Step 1: Delete DTO tests**

```bash
git rm -q tests/Response/AccountDomainsTest.php tests/Response/DiskPartitionTest.php tests/Response/DomainInfoTest.php tests/Response/ServerDiskUsageTest.php tests/Response/ServerDomainsTest.php tests/Response/ServerLoadAverageTest.php tests/Response/FeatureMetadataTest.php tests/Response/FeatureSettingTest.php tests/Response/UserFeatureSettingTest.php
```

Their validation rules are now exercised by `ShapeValidatorTest` (plan 1) and by the adapted endpoint tests below.

- [ ] **Step 2: See the failures**

Run: `vendor/bin/phpunit`
Expected: errors such as `Call to undefined method ...WhmClient::domains()` and `...UapiClient::php()` in the endpoint tests. This is the oracle: each failing test must be adapted, not deleted.

- [ ] **Step 3: Adapt each test file**

For each file in the list, apply the mapping table mechanically and keep every behavioural assertion:

1. Replace each old call with its new call. Use named arguments where the new signature reorders or splits parameters (`createAccount(username: ..., plan: ..., password: ...)`, `modifyAccount(user: ..., contactemail: ...)`, `accountSummary(user: ...)`).
2. Replace DTO assertions with array assertions on wire keys:
   - `$x->mainDomain` → `$x['main_domain']`; `$x->domains` → `$x['domains']`; `$record->port` → `$record['port']`.
   - `assertNull($dto->field)` for an omitted field → `assertArrayNotHasKey('field', $x)`; explicit null stays `assertNull($x['field'])`.
   - `$dto->toArray()` expectations that asserted preserved wire variants (`'80'`, `'1'`, `true`) now assert the normalized values (`80`, `1`, `1`); keep the original payload fixture so the variant is still exercised.
   - Iteration/count over DTO collections → over the list (`$x['domains'] ?? []`).
3. Boolean returns that became flags: `assertTrue($r)` → `assertSame(1, $r)`, `assertFalse($r)` → `assertSame(0, $r)`.
4. Request assertions (paths, query strings, POST bodies, `Authorization` headers, `cpanel.*` bridge fields) stay byte-for-byte the same, except parameter names that the overlay renamed on the PHP side only (wire names are unchanged).
5. Tests that asserted hand-written-only behaviour with no generated equivalent — DTO `fromPayload()` called directly, `toArray()` detachment, or option-array shapes (`CreateAccountOptions`) — are rewritten to the equivalent call through the generated method, or deleted only when the behaviour no longer exists (record which ones in the commit message).
6. Selector tests (`InventorySelectorsTest`, `ClientTest`) assert the new accessors are cached (`assertSame($whm->accounts(), $whm->accounts())`) and perform no requests.

Run each file as you go, for example `vendor/bin/phpunit tests/Api/WhmFeaturesTest.php`. When an adapted assertion fails, decide whether the test or the generated behaviour is wrong by checking the source audit and the pinned schema; fix the overlay (with evidence) or the generator, regenerate, and re-run. Never edit generated files and never weaken an assertion to make it pass.

- [ ] **Step 4: Full gate**

Run: `composer check && composer api-map:check && composer api-map:generate:check`
Expected: all green.

- [ ] **Step 5: Commit**

```bash
git add -A tests
git commit -m "test: adapt endpoint tests to the generated groups"
```

### Task 4: Adapt and run the read-only live suite

**Files:**
- Modify: `tests/Live/{ReadOnlyOperationsTest,WhmServerLiveTest,InventoryLiveTest,AccountsLiveTest,AccountLifecycleLiveTest}.php`

**Interfaces:**
- Consumes: generated groups; `tests/Support/LiveCurlClient.php` and `LiveTestEnvironment.php` (unchanged).
- Produces: a passing opted-in live run on `test.oblak.host` for every read-only operation, on both UAPI routes.

- [ ] **Step 1: Adapt the live tests**

Apply the mapping table to the live tests exactly as in Task 3 (including `AccountLifecycleLiveTest`, which must still compile even though it is not run). Keep their assertions on HTTP status, outcomes and route metadata.

- [ ] **Step 2: Static checks**

Run: `composer check`
Expected: green (live tests are analysed but skipped without opt-in).

- [ ] **Step 3: Run the read-only live suite**

Load only the two token exports from `~/.zshrc.local` into the command environment (never print them), then run:
```bash
WHM_API_LIVE=1 composer test:live
```
Expected: all tests pass (at least the 46 tests of the PR #1 suite). If a live test fails on a payload shape, it is a discrepancy: add an overlay correction with `live` evidence (version, date, note describing the observed type), regenerate, re-run the offline gate and the live suite.

- [ ] **Step 4: Commit**

```bash
git add -A tests/Live docs/api/overlay src docs/api/coverage.md
git commit -m "test: run the read-only live suite through the generated groups"
```

### Task 5: Documentation, beads and pull request

**Files:**
- Modify: `README.md`, `AGENTS.md`, `docs/superpowers/specs/2026-10-14-generated-client-design.md`, `docs/superpowers/specs/2026-10-13-php-client-design.md`

- [ ] **Step 1: Update the documentation**

- `README.md`: replace the per-endpoint tables and DTO descriptions with (a) the method mapping table's "New call" column grouped by handle and accessor, (b) a note that results are arrays with PHPStan shapes and normalized values, (c) a pointer to `docs/api/coverage.md`, and (d) updated examples (for example `$client->uapi('npr')->domainInfo()->listDomains()['main_domain']`).
- `AGENTS.md` "Architecture and Conventions": replace the bullets that describe individual hand-written methods and DTOs (the bullets starting "`whm()->domains()->list(): ServerDomains`…", "`whm()->server()->hostname()`…", "The additional 22 read-only inventory methods…", "`whm()->accounts()` follows the lean pattern…") with one bullet:

```markdown
- Typed groups are generated (`src/Api/{Whm,Uapi}/**`, never edited by hand) from the
  pinned map plus `docs/api/overlay/`: UAPI groups per module, WHM groups per documented
  `x-tagGroups` entry; named arguments, plain arrays with generated PHPStan shapes, values
  normalized from observed wire variants recorded as overlay corrections with evidence.
  `docs/api/coverage.md` lists generated groups and exclusions. Handles route generated
  specs through `run()`/`dispatch()` and the shared request lifecycle.
```

- `docs/superpowers/specs/2026-10-14-generated-client-design.md`: change the status line to "implemented through phase 1 (plans 1–4)" and, under "Migration and phasing", phase 1, note that the hand-written endpoint tests were adapted as the equivalence oracle and convert to generated fixture rows in phase 2.
- `docs/superpowers/specs/2026-10-13-php-client-design.md`: under "Public interface", add a note that the operation table is superseded by `docs/api/coverage.md` and the generated-client design.

- [ ] **Step 2: Final gates**

Run:
```bash
composer validate && composer check && composer api-map:check && composer api-map:generate:check
go -C tools/api-map test ./... && go -C tools/api-map vet ./... && test -z "$(gofmt -l tools/api-map)"
```
Expected: all green.

- [ ] **Step 3: Beads, push and pull request**

```bash
bd close whm-api-client-8x0.10 --reason="Generated client implemented for the existing 39 operations (plans 1-4)."
git add -A
git commit -m "docs: document the generated client surface"
git push -u origin HEAD
gh pr create --base master --title "feat: generated client for the existing 39 operations" --body "Implements plan 4 of docs/superpowers/specs/2026-10-14-generated-client-design.md: overlay for 39 operations and 13 exclusions, generated groups wired into the handles, hand-written groups and DTOs removed, tests adapted as the equivalence oracle, read-only live suite passing. Breaking: method names follow the generated surface (see README mapping) and results are arrays."
bd dolt push
```
