# Saved capability comparison

Origin: https://synthetic.example.invalid:2087

Server version: 11.88.0.9999

Account: root

cPanel context: synthetic\_context

Captured at: 2026\-10\-13T12:00:00Z

Capture status: success

Direct UAPI authentication: unverified

The documentation denominator is independent of this host. Advertised names describe this saved account observation; differences do not establish operation support or PHP coverage.

| API family | Runtime inventory | Documented operations | Unique advertised names |
| --- | --- | ---: | ---: |
| whm1 | available | 3 | 4 |
| uapi | unavailable | 1 | unavailable |

## Shared WHM names

- accountsummary
- listaccts

## Docs-only WHM names

- EcosystemFeatures/is\_ready

## Runtime-only WHM names

- custom\_plugin\_call
- is\_ready

## Capture evidence

| Function | Route | Status | HTTP status | API result | Error category |
| --- | --- | --- | --- | --- | --- |
| version | /json\-api/version?api.version=1 | success | 200 | 1 |  |
| applist | /json\-api/applist?api.version=1 | success | 200 | 1 |  |

## Explanations

- docs\-only EcosystemFeatures/is\_ready: namespace/alias (confirmed). Synthetic reviewed alias relationship only; not observed server behavior.
  Related exact name: is\_ready. Original difference sets are preserved.
  Evidence: synthetic\-review, SHA-256 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa, locator Synthetic alias example paragraph 1.
- runtime\-only custom\_plugin\_call: plugin (possible). Synthetic plugin hypothesis only; the saved advertised name establishes no cause.
  Evidence: synthetic\-review, SHA-256 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa, locator Synthetic plugin example paragraph 2.
- runtime\-only is\_ready: unknown (unknown). No reviewed evidence establishes the cause of this difference.
