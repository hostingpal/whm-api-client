# UAPI WebPros MCP

> Agent contract notes for `uapi()->webProsMcp()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

## WebPros MCP connection configuration and account unlinking

`uapi()->webProsMcp()` covers both pinned `WebProsMCP` operations from cPanel
138 and requires the WebProsMCP plugin. Its lazy cached handle belongs to the
selected account; selecting it makes no request or metadata reset.

```php
$webPros = $client->uapi('fixture')->webProsMcp();
$config = $webPros->getConnectionConfig();
$isLinked = $config['is_linked'] ?? null;
$sessionRelativeLink = $config['link_url'] ?? null;
$snippet = $config['mcp_config'] ?? null; // Data only; no installation or connection.
```

Both `getConnectionConfig()` and `unlinkWebProsAccount()` are parameterless and
send one immediate empty form POST on direct cPanel and WHM bridge routes;
routing fields remain in the URL. The pinned declarations stay GET.
Configuration discovery lacks readonly/rollback annotations, so its source risk
remains UNKNOWN and the existing default POST policy applies despite its name.
This establishes neither a read-only guarantee nor a known mutation. Unlink
explicitly declares `x-readonly:false`; neither operation documents rollback.

`unlinkWebProsAccount()` removes **all WebPros authentication links** for the
selected cPanel account. It disables WebPros Account login and other WebPros
integrations as well as MCP until relinked. Call it only when that complete
unlink is intended. The source describes an idempotent no-op when no link exists;
this is documented behavior, not successful live verification or evidence that
an uncertain current state is safe. The example above does not invoke unlink.
The client adds no inventory/read before unlink, retry, relink or rollback.

The [generated result shapes](../../../src/Api/Uapi/WebProsMcp.php) preserve optional fields:
configuration has `is_linked`, `link_url`, `environment` and `mcp_config`; the
snippet has `name`, `type`, `url` and `oauth`, whose exact keys are `clientId`
and `callbackPort`. Unlink has only optional `is_linked`. All scalar fields are
strings except strict native integer `is_linked` and `callbackPort`. No quoted,
boolean or float conversion, inferred `0|1` enum, constant-zero narrowing or port
range guard is added. Source prose describes linked 0/1 and successful unlink 0;
the formal integer schemas have no enum. Synthetic acceptance of integer 2 does
not claim that a server returns it. General string values stay open.

`link_url` is session-relative according to the source. `environment` describes
production defaults versus overridden MCP settings, and `mcp_config` contains a
connection snippet. Empty strings, Unicode and URL/path punctuation remain exact.
Returned links, configuration, URLs and OAuth client IDs are inert private payload;
the client does not navigate, authenticate, exchange tokens, install configuration
or contact an MCP server, and does not promise installability or reachability.

Both roots are required nonnull known objects. All fields and nested objects are
optional and nonnullable; omission differs from a present empty object. Unknown
fields are filtered at every known-object level. Existing empty JSON array object
compatibility (`whm-api-client-8x0.2.50`) remains; absent/null root data mismatches.
Payload `is_linked: 0` under UAPI status 1 is valid. UAPI status 0 and outer WHM
failure throw before payload validation. Inspect `responseMeta()` warnings and
raw metadata, including after a mismatch; exception snapshots retain their data
after later calls. Existing library response-frame protections do not conceal
caller-readable values, mismatch payloads or third-party transport traces.

At LinkedNode delivery, coverage was 1,221 canonical operations (633 UAPI, 588 WHM), 116 groups
and 235 outputs (234 PHP plus coverage). Delivery `whm-api-client-8x0.4.36` is
offline synthetic coverage only. Live follow-up `whm-api-client-8x0.2.78` remains
OPEN; no live POST acceptance, authentication/link changes, login, MCP connectivity
or integration behavior is established.
