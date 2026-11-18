# Agent reference notes

`AGENTS.md` holds the rules every session needs. This directory holds the detail an agent reads
only when a task touches it. Nothing here is loaded automatically.

- [architecture.md](architecture.md): runtime invariants for the operation runner, argument
  encoding, generic `call()`/`callJson()`/`callMultipart()`, response metadata and transport.
- [live-verify-recipes.md](live-verify-recipes.md): state of the FTP, PostgreSQL, MySQL and
  finite-quota recipes and the read-only runner catalog.
- [modules/](modules/): one file per module family with its contract caveats, evidence
  boundaries and open follow-up beads.

Find a module's file by accessor name with `grep -l 'webApp()' docs/agents/modules/*.md`.
Add new module notes to the matching file here, never to `AGENTS.md`.

## Module notes

| File | Covers |
| --- | --- |
| [Generic call entrypoints](modules/generic-calls.md) | `call()`, `callJson()`, `callMultipart()` scope and typed JSON/multipart adoption counts |
| [Delimited list inputs](modules/delimited-inputs.md) | pipe- and comma-joined list arguments across SSL, mail, account and WHM administration methods |
| [Log administration](modules/log-administration.md) | `uapi()->logManager()`, `whm()->logs()` |
| [UI and application discovery](modules/ui-app-discovery.md) | Chrome, Parser, Integration, InProductSurvey and Plugins modules |
| [Exim ports and reseller account discovery](modules/exim-ports-reseller-discovery.md) | UAPI Chkservd and Resellers modules |
| [UAPI account backup and restoration](modules/uapi-account-backup.md) | `backup()`, `restore()`, `wordPressBackup()`, `wordPressRestore()`, `backupInfo()`, `extractInfo()`, `websiteBackup()`, binary restore uploads |
| [UAPI account environment and preferences](modules/uapi-account-environment.md) | ContactInformation, Contactus, Locale, Themes, Notifications, AccountEnhancements, EA4, ServerInformation, Variables |
| [UAPI Branding](modules/uapi-branding.md) | `uapi()->branding()` |
| [UAPI account cache management](modules/uapi-cache.md) | `cacheBuster()`, `nginxCaching()` |
| [UAPI CCS calendar delegation](modules/uapi-ccs.md) | `uapi()->ccs()` |
| [UAPI DAV calendars, contacts and delegation](modules/uapi-dav-calendars.md) | `dav()`, `cpDavd()` |
| [UAPI DCV checks](modules/uapi-dcv.md) | `uapi()->dcv()` |
| [UAPI directory access](modules/uapi-directory-access.md) | DirectoryIndexes, DirectoryPrivacy, DirectoryProtection |
| [UAPI DNS](modules/uapi-dns.md) | `uapi()->dns()`, including `massEditZone()` and `swapIpInZones()` |
| [UAPI DNSSEC and Dynamic DNS](modules/uapi-dnssec-dynamic-dns.md) | `dnssec()`, `dynamicDns()` |
| [UAPI DomainRecommendations](modules/uapi-domain-recommendations.md) | `uapi()->domainRecommendations()` |
| [UAPI DomainInfo, Features and LangPHP](modules/uapi-domaininfo-features-langphp.md) | `domainInfo()`, `features()`, `langPhp()` |
| [UAPI domain management](modules/uapi-domains.md) | AddonDomain, Park, SubDomain, WebVhosts |
| [UAPI Email](modules/uapi-email.md) | `uapi()->email()` |
| [UAPI Fileman](modules/uapi-fileman.md) | `uapi()->fileman()`, including typed `uploadFiles()` |
| [UAPI Ftp](modules/uapi-ftp.md) | `uapi()->ftp()` |
| [UAPI GPG and two-factor authentication](modules/uapi-gpg-two-factor.md) | `gpg()`, `twoFactorAuth()` |
| [UAPI ImageManager](modules/uapi-imagemanager.md) | `uapi()->imageManager()` |
| [UAPI mail protection](modules/uapi-mail-protection.md) | BoxTrapper, EmailAuth, SpamAssassin, cPGreyList |
| [UAPI Mailboxes](modules/uapi-mailboxes.md) | `uapi()->mailboxes()` |
| [UAPI Market](modules/uapi-market.md) | `uapi()->market()` reads and actions |
| [UAPI Mime](modules/uapi-mime.md) | `uapi()->mime()` |
| [UAPI Mysql](modules/uapi-mysql.md) | `uapi()->mysql()` |
| [UAPI Nova and Sitejet site builders](modules/uapi-nova-sitejet.md) | `nova()`, `sitejet()` |
| [UAPI Personalization](modules/uapi-personalization.md) | `uapi()->personalization()` |
| [UAPI PostgreSQL](modules/uapi-postgresql.md) | `uapi()->postgresql()` |
| [UAPI Quota](modules/uapi-quota.md) | `uapi()->quota()` |
| [UAPI repositories, deployment and Passenger](modules/uapi-repositories-passenger.md) | `versionControl()`, `versionControlDeployment()`, `passengerApps()` |
| [UAPI resource and web statistics](modules/uapi-resource-statistics.md) | `bandwidth()`, `resourceUsage()`, `statsBar()`, `stats()`, `statsManager()` |
| [UAPI security and access](modules/uapi-security-access.md) | `blockIp()`, `modSecurity()`, `clamScanner()`, `knownHosts()`, `externalAuthentication()`, `ssh()`, `lastLogin()` |
| [UAPI ServiceProxy](modules/uapi-serviceproxy.md) | `uapi()->serviceProxy()` |
| [UAPI SiteQuality](modules/uapi-sitequality.md) | `uapi()->siteQuality()` |
| [UAPI SSL](modules/uapi-ssl.md) | `uapi()->ssl()` |
| [UAPI team and user management](modules/uapi-team-user-management.md) | `team()`, `teamRoles()`, `userManager()`, `webDisk()`, `users()`, `passwdStrength()` |
| [UAPI Trash](modules/uapi-trash.md) | `uapi()->trash()` |
| [UAPI user task queue](modules/uapi-user-tasks.md) | `uapi()->userTasks()` |
| [UAPI scoped userdata settings](modules/uapi-userdata.md) | `uapi()->userData()` |
| [UAPI WebApp](modules/uapi-webapp.md) | `uapi()->webApp()` |
| [UAPI Webmail credentials and session inventory](modules/uapi-webmail-sessions.md) | `session()`, `activeSessions()`, `webmailApps()` |
| [UAPI WebPros MCP](modules/uapi-webpros-mcp.md) | `uapi()->webProsMcp()` |
| [UAPI WPX WordPress lifecycle](modules/uapi-wpx.md) | `uapi()->wpx()` |
| [WHM 360 Monitoring](modules/whm-360-monitoring.md) | `whm()->monitoring()` |
| [WHM account management](modules/whm-accounts.md) | `whm()->accounts()` |
| [WHM API tokens, sessions and introspection](modules/whm-api-tools.md) | `whm()->apiTools()` |
| [WHM authentication](modules/whm-authentication.md) | `whm()->authentication()` |
| [WHM backup and restoration](modules/whm-backups-restoration.md) | `whm()->backups()`, `whm()->accountRestoration()` |
| [WHM Commerce and Market](modules/whm-commerce-market.md) | `whm()->commerce()`, `whm()->market()` |
| [WHM login and mail protection](modules/whm-cphulk-greylisting.md) | `whm()->cpHulk()`, Greylisting in `whm()->mail()` |
| [WHM interface customization and scoped data](modules/whm-customizations.md) | `whm()->customizations()`, `whm()->userData()`, `whm()->inProductSurvey()` |
| [WHM database administration](modules/whm-databases.md) | `whm()->databases()` |
| [WHM DNS management](modules/whm-dns.md) | `whm()->dns()` |
| [WHM hosting plans](modules/whm-hosting-plans.md) | `whm()->hostingPlans()` |
| [WHM integrations and hooks](modules/whm-integrations.md) | `whm()->integrations()` |
| [WHM IP address administration](modules/whm-ip-addresses.md) | `whm()->ipAddresses()` |
| [WHM JSON configuration](modules/whm-json-configuration.md) | typed whole-body JSON WHM methods |
| [WHM mail management](modules/whm-mail.md) | `whm()->mail()` |
| [WHM ModSecurity administration](modules/whm-modsecurity.md) | `whm()->modSecurity()` |
| [WHM named-map inputs](modules/whm-named-map-inputs.md) | feature lists, Greylisting providers, AutoSSL providers, addon conversion, `massModifyAccount()` |
| [WHM NGINX cache management](modules/whm-nginx-manager.md) | `whm()->nginxManager()` |
| [WHM reseller administration](modules/whm-resellers.md) | `whm()->resellers()` |
| [WHM root-access restrictions](modules/whm-root-access.md) | `whm()->security()` |
| [WHM server administration](modules/whm-server.md) | `whm()->server()`, configuration upload, reboot status |
| [WHM SSL certificate management](modules/whm-ssl.md) | `whm()->ssl()` |
| [WHM support-ticket administration](modules/whm-support-tickets.md) | `whm()->supportTickets()` |
| [WHM system package management](modules/whm-system-packages.md) | `whm()->systemPackages()` |
| [WHM account transfers](modules/whm-transfers.md) | `whm()->transfers()` |
| [WHM web-server configuration](modules/whm-web-server.md) | `whm()->webServer()` |
