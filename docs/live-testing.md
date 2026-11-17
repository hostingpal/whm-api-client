# Live testing on test.oblak.host

The owner authorized on 2026-11-17 that `test.oblak.host` may be used for **all
live API tests, including irreversible server-wide and lockout operations, except
operations that cost money**. A Hetzner snapshot is the recovery point: run the
batch, commit the evidence, then restore the server from the snapshot.

This extends the 2026-10-15 standing test-account authorization in `AGENTS.md`.
Keep live opt-in, explicit target guards and cleanup checks. Never print, store or
commit credentials.

[live-testing-operations.md](live-testing-operations.md) assigns every pinned
operation to one of the groups below. Groups are reviewed decisions; the `Client`
column follows the generated client. After generating new operations, refresh it with
`php tools/live-testing/refresh-operations-client.php` (`--check` exits 1 when stale).

## The box

| Item | Value |
| --- | --- |
| Hetzner Cloud server | `test.oblak.host`, ID `170057093`, `cpx22`, `fsn1` |
| cPanel version | `11.138.0.24` on 2026-11-17 (earlier committed evidence is from `11.138.0.19`; the server updates itself) |
| License limit | 5 accounts (`get_maximum_users`); 1 in use (`npr`) on 2026-11-17 |
| Baseline snapshot | image `457432750`, taken powered off at 2026-11-17T12:33:56Z, label `purpose=whm-api-client-live-baseline` |
| Credentials | `OBLAK_TEST_WHM_KEY`, `OBLAK_TEST_NPR_KEY`, `HETZNER_OBLAKHOST_TOKEN` (owner's interactive shell profile; non-interactive agent shells reach the Hetzner token only through `zsh -ic`, and must never print it) |

Evidence records the version it observed. Do not treat `11.138.0.24` results as
`11.138.0.19` results, or the reverse.

The license limit is a hard cap: account creation above it fails rather than
adding charges. Recipes that create accounts must stay under it and clean up.

## Groups

| Group | When to run |
| --- | --- |
| Read-only | Any time. |
| Account-scoped mutations | Any time, on designated or disposable test accounts (standing authorization). Unlisted UAPI mutations default here. |
| Server-wide mutations | Inside a snapshot window. Unlisted WHM mutations default here. |
| Lockout or version-changing | Last in a snapshot window, with Hetzner console access ready. Restore afterwards. |
| External effects | Only against owned or synthetic targets. A restore does not undo them. |
| Possibly paid | Excluded until the owner confirms they are free. |
| Costs money | Excluded. |

### Costs money (excluded)

Store and Market carts, orders, checkout and paid-order processing:
`whm1:purchase_a_license`, `uapi:DomainRecommendations/purchase_domain` and the
Market operations `create_shopping_cart`, `create_shopping_cart_non_ssl`,
`get_build_cart_url`, `request_ssl_certificates`, `set_url_after_checkout`,
`process_ssl_pending_queue`, `set_status_of_pending_queue_items` and
`cancel_pending_ssl_certificate`. A restore cannot undo a remote order or payment.

### Possibly paid (excluded until confirmed)

These register or use subscription services: 360 Monitoring server registration,
SiteQuality (Koality) account and project creation, Nova (AI App Builder) account
creation and publication, the WPX Nova website starter, and the WHM Market
provider commission ID (it decides who is credited for Store sales).

### External effects (allowed with owned or synthetic targets)

- Mail and notifications: send only to mailboxes on the box or addresses the owner
  controls. This includes BoxTrapper delivery, client-settings mail, team password
  resets, Pushbullet/Slack/POST URL tests, and parameters that send welcome or
  activation mail on otherwise account-scoped calls.
- cPanel support tickets (`ticket_*`): these reach cPanel support and can grant
  them server access. Do not open real tickets without the owner's approval.
- Vendors: ModSecurity rule reports go to the vendor; vendor add, preview and
  update fetch remote rule sets.
- Certificates: AutoSSL checks request real certificates and use CA rate limits.
  Provider reset and selection change the remote registration.
- Remote hosts: transfers, remote MySQL profiles, linked nodes, DNS cluster
  members and backup destinations must point at hosts the owner controls.
- External accounts: WebPros, Sitejet, Store sessions and SiteQuality accounts
  live outside the box.
- DNS: zone changes for public domains such as `npr.rs` are published. Resolvers
  keep cached answers until their TTL, even after a restore.

### Lockout or version-changing

Root access restrictions, cPHulk enablement and blocklists, the two-factor policy,
authentication providers, hostname, resolvers, IP removal and NAT, reboots and
service restarts, tweak settings, cPanel updates and package changes,
configuration restores, ModSecurity deployment, service certificates, server
profile activation, the local MySQL root password, SQL and PHP-FPM configuration,
and API token revocation. These can cut off API access or change the cPanel
version. Run them last, then restore.

## Snapshot window

1. Before a server-wide batch, decide whether the baseline is still the right
   recovery point. For a new one, power the server off and create a snapshot
   (`POST /v1/servers/170057093/actions/shutdown`, then `create_image` with
   `type: snapshot`). A snapshot of a running server is only crash-consistent.
2. Run account-scoped and server-wide tests, then the lockout group last.
3. Commit the evidence. It lives in this repository, so a restore does not lose it.
4. Restore explicitly: `POST /v1/servers/170057093/actions/rebuild` with
   `{"image": 457432750}`. This replaces the disk and keeps the server ID and IP.
   Verify WHM `version` afterwards.

A restore discards everything since the snapshot, including accounts, fixtures,
API tokens created later and cPanel updates. The two test tokens predate the
baseline snapshot and survive it. Restores are deliberate steps after a batch,
never automatic in code or triggered by a failing test. Pin every Hetzner call to
server ID `170057093`. The other servers on that account are production.

Hetzner bills snapshot storage per GB-month. Delete obsolete snapshots and keep
the baseline.
