# WHM IP address administration

> Agent contract notes for `whm()->ipAddresses()`. Moved verbatim from `AGENTS.md` on 2026-11-17;
> dated headings and coverage totals inside are historical. Index: [docs/agents](../README.md).

### WHM IP address administration

`whm()->ipAddresses()` exposes all sixteen pinned IPv4, IPv6 and NAT functions.
Six reads use GET and ten mutations or NAT-check helpers use form POST. Each
method retains its source availability floor and parameter order. Addresses,
netmasks, ranges and notes are forwarded without rewriting or new validation.

`getSharedIp()` preserves the literal `~` value when no shared address exists.
IPv6 inventory retains the `SHARED` range name, optional rows and lists, and
integer status flags. Only the observed IPv6 range `enabled` field additionally
accepts exact quoted 0/1 flags, and its `mostrecent` and `note` fields accept null.
Other fields retain their reviewed contracts. Explicit false becomes zero,
while omitted options retain server defaults.

`disableIpv6Account()` returns null for absent/null data on clean success,
following reviewed upstream source. Present failure fields remain validated,
including mixed `reason` values; inspect them even after envelope success. This
write response is source-backed and offline-tested, not live-observed.
`addIps()` returns void; pinned per-address messages remain available at
`responseMeta()->whm['output']['messages']`.

On `11.138.0.19`, the reviewer observed `getIpv6RangeUsage()` returning the upstream
constants `used=20`, `free=1000000` and `forbidden=65536`. These placeholders do
not measure actual address capacity or utilization on that version.

`deleteIp(skipIfShutdown: true)` prevents removal when the interface is already
down; false or omission permits removal. The switch does not shut it down. NAT
and site-IP changes can alter connectivity and hosted configuration. Account IPv6
enablement/disablement and range removal are separate operations. Read each
method's selector and destructive/default caveats before calling it. The client
adds no retries, rollback, NAT probing or network reconfiguration beyond the
single requested operation. API success does not prove reachability, DNS, mail
routing, HTTP delivery or TLS behavior.

All sixteen methods have independent offline contracts. The fixed five-GET live
catalog selects WHM version, the default shared IP, IPv6 ranges and IPv4/IPv6
inventories. It guesses no address or range, performs no NAT check and changes
no account, address, interface, mapping or network state. Empty inventories do
not establish populated row schemas. Follow-up `8x0.2.37` retains all unobserved
variants and lifecycle behavior.

The corrected WHM IP-address capture at `fde160d` on `11.138.0.19` passes all five
GET observations and strict assessment with zero discrepancies. IPv6 range
`enabled` accepts exact quoted flags; `mostrecent` and `note` accept null only
where observed. IPv4/IPv6/range rows are populated; range-user lists are empty.
No network or address/range change ran. The original failed capture is immutable
and follow-up `8x0.2.37` retains all remaining live coverage.
