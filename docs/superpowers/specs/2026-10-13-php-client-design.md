# PHP client interface and UAPI routing

Status: public interface direction agreed on 2026-10-13. Base configuration,
WHM/UAPI account selection, and metadata lifecycle scaffolding are implemented
under `whm-api-client-8x0.1.3`. Authenticated execution, response parsing, and
endpoint groups remain open. Endpoint examples below describe the planned API.

Decision record: `whm-api-client-8x0.1.2`. Runtime execution and authentication:
`whm-api-client-8x0.1`. Concrete payload returns and response metadata:
`whm-api-client-8x0.1.1`.

## Public interface

Expose WHM operations through `whm()` and cPanel UAPI operations through
`uapi(?string $user = null)`. The configured credential type determines whether
UAPI calls go directly to cPanel or through WHM. The bridge is internal; callers
do not need a separate public `bridge()` method.

```php
// Client configured with WHM credentials, such as a root token.
$serverDomains = $client->whm()->domains()->list();
$accountDomains = $client->uapi('npr')->domains()->list();

// Client configured with the cPanel account npr and its cPanel token.
$accountDomains = $client->uapi()->domains()->list();
$versions = $client->uapi()->php()->listVersions();
```

Use declared classes and methods with plural resource groups where appropriate
and camelCase operation names. These provide autocomplete and concrete parameter
and return types for PHPStan. Keep an explicit mapping from each PHP operation
to its documented upstream operation; friendly names do not change wire names.

| Planned PHP call | Documented upstream operation |
| --- | --- |
| `whm()->domains()->list()` | WHM `get_domain_info` |
| `uapi()->domains()->list()` | UAPI `DomainInfo/list_domains` |
| `uapi()->php()->listVersions()` | UAPI `LangPHP/php_get_installed_versions` |

The same UAPI mappings apply when a username selects access through WHM.
WHM domain information and UAPI account domains have different scopes and
payloads; similarly named PHP methods retain their own return contracts.

API selectors and resource group accessors perform no I/O. The terminal operation
executes the request immediately and returns the documented payload as a DTO,
collection, scalar, or `void`, as appropriate. There is no trailing `execute()`
step and no queue of pending commands implied by chaining.

## Credentials and account selection

The authentication username identifies the owner of the configured token. The
UAPI target username identifies the cPanel account whose data the operation uses.
For direct UAPI these are the same account. With WHM credentials they can differ.

```php
public function uapi(?string $user = null): UapiClient;
```

| Configured credentials | Requested target | Behavior |
| --- | --- | --- |
| cPanel token for `npr` | Omitted or `null` | Direct UAPI as `npr` |
| cPanel token for `npr` | `npr` | Direct UAPI as `npr` |
| cPanel token for `npr` | Another account | Reject the account mismatch before making a request |
| WHM root or reseller token | Explicit cPanel account | UAPI through WHM for that account, subject to permissions |
| WHM root or reseller token | Omitted or `null`, with a configured default cPanel account | UAPI through WHM for the configured default account |
| WHM root or reseller token | Omitted or `null`, without a default cPanel account | Report a missing-target error before making a request |

`root` may be the WHM authentication username. It is not an implicit UAPI target
or a shorthand for all cPanel accounts. A cPanel token does not gain impersonation
capability when another username is passed to `uapi()`. Credential type is explicit
in client configuration; the library does not guess it from the token string.
WHM operations require WHM credentials.

The default account setting belongs to client configuration. The base scaffold
accepts an RNIDS-style options array or immutable `ClientConfig` through
`Client::__construct`, plus an optional PSR-backed `HttpTransport`. See the
[base client configuration](../../../README.md#base-client) for the implemented
options and validation behavior. API execution exceptions remain separate work.

## Shared UAPI implementation

Direct and bridged access expose the same `UapiClient` interface, operation groups,
and payload types. Shared UAPI operation implementations delegate execution to
the selected route. That route handles authentication, request addressing, and
response envelope parsing while preserving the documented operation contract.

| Route | Normal HTTPS address | Authentication and account context |
| --- | --- | --- |
| WHM | `https://host:2087/json-api/<function>` | WHM username and token |
| Direct UAPI | `https://host:2083/execute/<Module>/<function>` | cPanel account username and token |
| UAPI through WHM | `https://host:2087/json-api/uapi_cpanel` | WHM credentials plus the target cPanel account |

The bridge supplies `api.version=1`, `cpanel.user`, `cpanel.module`, and
`cpanel.function` according to the reviewed contract. It preserves both outer
WHM and inner UAPI outcomes; outer success alone does not establish UAPI success.
HTTP methods, parameter encoding, and payload schemas remain source-driven.
Bridge coverage does not establish direct cPanel-token authentication coverage.

The core remains framework-independent, using PSR-18 and PSR-17 injection or
discovery. Laravel integration remains a separate Composer package.

## Account isolation and response metadata

Each selected UAPI client retains its account and credential context. Selecting
another account must not change an existing client's context:

```php
$npr = $client->uapi('npr');
$other = $client->uapi('anotheruser');

$domains = $npr->domains()->list(); // Still operates on npr.
$meta = $npr->responseMeta();
```

Repeated `whm()` and default `uapi()` access returns stable configured client
handles. Retaining the selected client, as above, makes metadata ownership
explicit for account-specific calls. Account selection must never rebind a
shared mutable client or expose another account's response metadata.

`responseMeta(): ?ResponseMetadata` belongs to the selected API client and
describes its latest attempted request. It performs no I/O and is initially
`null`. Clear previous metadata at the start of each request attempt so a failed
attempt cannot expose stale metadata. Populate an immutable snapshot when a
usable response envelope is available, including before an API-level exception;
the exception retains the same snapshot. Previously retained snapshots remain
unchanged after later calls.

Preserve WHM and UAPI metadata separately for bridged calls. Apply each documented
operation's status semantics rather than assuming every status field represents
generic success or failure. Concrete payload returns do not require a universal
response wrapper. Last-request metadata initially assumes synchronous use; future
concurrent execution requires per-request snapshots.

## Implementation verification

Runtime implementation must exercise both UAPI routes with offline fixtures,
including matching payload types, distinct envelope parsing, and independent
coverage evidence. Verify omitted and explicit targets, defaults, permission
failures, cPanel account mismatches, and account isolation. Verify that selectors
perform no I/O and terminal operations execute immediately without implicit retries.

Metadata tests cover initial state, successful calls, API errors over HTTP 200,
HTTP errors with decodable envelopes, transport failures, malformed responses,
consecutive requests, retained immutable snapshots, and per-client isolation.
Record endpoint mappings and contract evidence in the existing coverage registry.
Runtime implementation and endpoint coverage remain tracked in Beads.

## Contract sources

The [pinned source audit](../../api/source-audit.md) records authentication,
family boundaries, bridge parameters, and response discrepancies. The
[source manifest](../../api/sources/2026-10-13/manifest.json) identifies the exact
official snapshots used for the operation mappings above. Ordinary checks remain
offline and do not refresh those snapshots.

Official references: [cPanel API tokens](https://api.docs.cpanel.net/cpanel/tokens),
[WHM bridge guide](https://api.docs.cpanel.net/whm/use-whm-api-to-call-cpanel-api-and-uapi),
and [WHM UAPI execution](https://api.docs.cpanel.net/openapi/whm/operation/uapi_cpanel).

Client organization follows the configuration and service boundaries in
[RNIDS Client](https://github.com/oblakhost/rnids-rsreg-client-php/blob/master/src/Client.php)
and its [configuration factory](https://github.com/oblakhost/rnids-rsreg-client-php/blob/master/src/Config/ClientConfigFactory.php).
RNIDS uses EPP and supplies no WHM authentication or HTTP contract. The
[PHP GitHub client HTTP builder](https://github.com/KnpLabs/php-github-api/blob/master/lib/Github/HttpClient/Builder.php)
provides a reference for PSR injection and discovery. These are architectural
references; their source code is not copied into this package.
