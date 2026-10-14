<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Api\Whm;

use Closure;
use HostingPal\WhmApiClient\Api\AbstractApiClient;
use HostingPal\WhmApiClient\Api\RequestMethod;
use HostingPal\WhmApiClient\Exception\ApiException;
use HostingPal\WhmApiClient\Response\ApiResponse;
use HostingPal\WhmApiClient\Response\PayloadReader;
use SensitiveParameter;

/**
 * Create, inspect, suspend, modify, and remove cPanel accounts through WHM.
 *
 * Inventory methods use GET. Mutations use POST so account passwords and other
 * parameters stay out of the URL that the server records in its access log.
 * Every method executes immediately against the configured server without a
 * cPanel target, applies the Flag outcome policy, and leaves the WHM envelope on
 * the owning client's responseMeta() and on exceptions. Records are array shapes:
 * each present field is validated against its pinned type, unknown response
 * fields are ignored, and numeric limits keep their integer or string wire form
 * (`unlimited`). Documented enumerations are not enforced at runtime. The one
 * runtime compatibility is `uid`, observed as a decimal string on test.oblak.host
 * on 2026-10-14: canonical nonnegative decimal strings within PHP's integer range
 * are normalized to the pinned integer.
 * Source: docs/api/sources/2026-10-13/whm.openapi.json.gz.
 *
 * @phpstan-import-type Parameters from AbstractApiClient
 * @phpstan-type Limit int|string
 * @phpstan-type AccountRecord array{
 *     backup?: 0|1,
 *     child_nodes?: list<mixed>,
 *     disklimit?: Limit,
 *     diskused?: string,
 *     domain?: string,
 *     email?: string,
 *     has_backup?: 0|1,
 *     inodeslimit?: Limit,
 *     inodesused?: int,
 *     ip?: string,
 *     ipv6?: list<string>,
 *     is_locked?: 0|1,
 *     is_temporary_domain?: 0|1,
 *     legacy_backup?: 0|1,
 *     mailbox_format?: string,
 *     max_defer_fail_percentage?: Limit,
 *     max_email_per_hour?: Limit,
 *     max_emailacct_quota?: Limit,
 *     maxaddons?: Limit,
 *     maxftp?: Limit,
 *     maxlst?: Limit,
 *     maxparked?: Limit,
 *     maxpop?: Limit,
 *     maxsql?: Limit,
 *     maxsub?: Limit,
 *     min_defer_fail_to_trigger_protection?: Limit,
 *     outgoing_mail_hold?: 0|1,
 *     outgoing_mail_suspended?: 0|1,
 *     owner?: string,
 *     partition?: string,
 *     plan?: string,
 *     shell?: string,
 *     standalone_feature?: string,
 *     standalone_feature_name?: string,
 *     startdate?: string,
 *     suspended?: 0|1,
 *     suspendreason?: string|null,
 *     suspendtime?: int|string|null,
 *     temporary?: 0|1,
 *     theme?: string,
 *     uid?: int,
 *     unix_startdate?: int,
 *     user?: string,
 * }
 * @phpstan-type PackageRecord array{
 *     BWLIMIT?: Limit,
 *     CGI?: string,
 *     CPMOD?: string,
 *     DIGESTAUTH?: string,
 *     FEATURELIST?: string,
 *     HASSHELL?: string,
 *     IP?: string,
 *     LANG?: string,
 *     MAXADDON?: Limit,
 *     MAXFTP?: Limit,
 *     MAXLST?: Limit,
 *     MAXPARK?: Limit,
 *     MAXPOP?: Limit,
 *     MAXSQL?: Limit,
 *     MAXSUB?: Limit,
 *     MAX_DEFER_FAIL_PERCENTAGE?: Limit,
 *     MAX_EMAILACCT_QUOTA?: Limit,
 *     MAX_EMAIL_PER_HOUR?: Limit,
 *     MAX_TEAM_USERS?: int|null,
 *     QUOTA?: Limit,
 *     _PACKAGE_EXTENSIONS?: string,
 *     name?: string,
 * }
 * @phpstan-type CreateAccountOptions array{
 *     account_enhancements?: string|list<string>,
 *     bwlimit?: Limit,
 *     cgi?: 0|1,
 *     contactemail?: string,
 *     cpmod?: string,
 *     customip?: string,
 *     digestauth?: 0|1,
 *     dkim?: 0|1,
 *     dmarc?: 0|1,
 *     domain?: string,
 *     enabledigest?: 0|1,
 *     featurelist?: string,
 *     forcedns?: 0|1,
 *     frontpage?: int,
 *     gid?: int,
 *     hasshell?: 0|1,
 *     hasuseregns?: 0|1,
 *     homedir?: string,
 *     ip?: 'y'|'n',
 *     language?: string,
 *     mail_node_alias?: string,
 *     mailbox_format?: 'mdbox'|'maildir',
 *     max_defer_fail_percentage?: Limit,
 *     max_email_per_hour?: Limit,
 *     max_emailacct_quota?: Limit,
 *     max_team_users?: int,
 *     maxaddon?: Limit,
 *     maxftp?: Limit,
 *     maxlst?: Limit,
 *     maxpark?: Limit,
 *     maxpop?: Limit,
 *     maxsql?: Limit,
 *     maxsub?: Limit,
 *     mxcheck?: 'local'|'secondary'|'remote'|'auto',
 *     owner?: string,
 *     ownerself?: 0|1,
 *     pass?: string,
 *     password?: string,
 *     pkgname?: string,
 *     plan?: string,
 *     quota?: int,
 *     reseller?: 0|1,
 *     reseller_without_domain?: 0|1,
 *     savepkg?: 0|1,
 *     showpass?: 'y'|'n',
 *     spamassassin?: 0|1,
 *     spambox?: 'y'|'n',
 *     spf?: 0|1,
 *     uid?: int,
 *     useregns?: 0|1,
 * }
 * @phpstan-type CreatedAccount array{
 *     ip?: string,
 *     nameserver?: string,
 *     nameserver2?: string,
 *     nameserver3?: string,
 *     nameserver4?: string,
 *     nameservera?: string,
 *     nameservera2?: string,
 *     nameservera3?: string,
 *     nameservera4?: string,
 *     nameserverentry?: string,
 *     nameserverentry2?: string,
 *     nameserverentry3?: string,
 *     nameserverentry4?: string,
 *     package?: string,
 * }
 * @phpstan-type AccountChanges array{
 *     account_enhancements?: string,
 *     BACKUP?: 0|1,
 *     BWLIMIT?: Limit,
 *     contactemail?: string,
 *     DBOWNER?: string,
 *     DISK_BLOCK_LIMIT?: int,
 *     DNS?: string,
 *     domain?: string,
 *     HASCGI?: 0|1,
 *     HASDKIM?: 0|1,
 *     HASDMARC?: 0|1,
 *     HASSHELL?: 0|1,
 *     HASSPF?: 0|1,
 *     LANG?: string,
 *     LOCALE?: string,
 *     mail_node_alias?: string,
 *     MAILBOX_FORMAT?: 'maildir'|'mbox',
 *     MAX_DEFER_FAIL_PERCENTAGE?: Limit,
 *     MAX_EMAIL_PER_HOUR?: Limit,
 *     MAX_EMAILACCT_QUOTA?: Limit,
 *     max_team_users?: int,
 *     MAXADDON?: Limit,
 *     MAXFTP?: Limit,
 *     MAXLST?: Limit,
 *     MAXPARK?: Limit,
 *     MAXPASSENGERAPPS?: Limit,
 *     MAXPOP?: Limit,
 *     MAXSQL?: Limit,
 *     MAXSUB?: Limit,
 *     modify_firewall?: 0|1,
 *     newuser?: string,
 *     notify_account_authn_link?: 0|1,
 *     notify_account_authn_link_notification_disabled?: 0|1,
 *     notify_autossl_expiry?: 0|1,
 *     notify_autossl_expiry_coverage?: 0|1,
 *     notify_autossl_renewal?: 0|1,
 *     notify_autossl_renewal_coverage?: 0|1,
 *     notify_contact_address_change?: 0|1,
 *     notify_contact_address_change_notification_disabled?: 0|1,
 *     notify_disk_limit?: 0|1,
 *     notify_password_change?: 0|1,
 *     notify_password_change_notification_disabled?: 0|1,
 *     notify_ssl_expiry?: 0|1,
 *     OUTGOING_EMAIL_SUSPENDED?: 0|1,
 *     owner?: string,
 *     PUSHBULLET_ACCESS_TOKEN?: string,
 *     QUOTA?: Limit,
 *     remove_missing_extensions?: string,
 *     rename_database_objects?: 0|1,
 *     reseller?: 0|1,
 *     RS?: string,
 *     shell?: string,
 *     spamassassin?: 0|1,
 *     STARTDATE?: int,
 *     STYLE?: string,
 *     update_existing_email_account_quotas?: 0|1,
 * }
 * @phpstan-type ModifiedAccount array{
 *     cpuser?: array<array-key, mixed>,
 *     domain?: string,
 *     setshell?: string,
 *     user?: string,
 * }
 */
final readonly class Accounts
{
    /** @var array<string, string> Pinned listaccts/accountsummary record fields and their validation. */
    private const ACCOUNT_FIELDS = [
        'backup' => 'flag',
        'child_nodes' => 'json_list',
        'disklimit' => 'limit',
        'diskused' => 'string',
        'domain' => 'string',
        'email' => 'string',
        'has_backup' => 'flag',
        'inodeslimit' => 'limit',
        'inodesused' => 'int',
        'ip' => 'string',
        'ipv6' => 'strings',
        'is_locked' => 'flag',
        'is_temporary_domain' => 'flag',
        'legacy_backup' => 'flag',
        'mailbox_format' => 'string',
        'max_defer_fail_percentage' => 'limit',
        'max_email_per_hour' => 'limit',
        'max_emailacct_quota' => 'limit',
        'maxaddons' => 'limit',
        'maxftp' => 'limit',
        'maxlst' => 'limit',
        'maxparked' => 'limit',
        'maxpop' => 'limit',
        'maxsql' => 'limit',
        'maxsub' => 'limit',
        'min_defer_fail_to_trigger_protection' => 'limit',
        'outgoing_mail_hold' => 'flag',
        'outgoing_mail_suspended' => 'flag',
        'owner' => 'string',
        'partition' => 'string',
        'plan' => 'string',
        'shell' => 'string',
        'standalone_feature' => 'string',
        'standalone_feature_name' => 'string',
        'startdate' => 'string',
        'suspended' => 'flag',
        'suspendreason' => 'nullable_string',
        'suspendtime' => 'nullable_int_or_string',
        'temporary' => 'flag',
        'theme' => 'string',
        'uid' => 'int_or_decimal_string',
        'unix_startdate' => 'int',
        'user' => 'string',
    ];

    /** @var array<string, string> Pinned listpkgs record fields and their validation. */
    private const PACKAGE_FIELDS = [
        'BWLIMIT' => 'limit',
        'CGI' => 'string',
        'CPMOD' => 'string',
        'DIGESTAUTH' => 'string',
        'FEATURELIST' => 'string',
        'HASSHELL' => 'string',
        'IP' => 'string',
        'LANG' => 'string',
        'MAXADDON' => 'limit',
        'MAXFTP' => 'limit',
        'MAXLST' => 'limit',
        'MAXPARK' => 'limit',
        'MAXPOP' => 'limit',
        'MAXSQL' => 'limit',
        'MAXSUB' => 'limit',
        'MAX_DEFER_FAIL_PERCENTAGE' => 'limit',
        'MAX_EMAILACCT_QUOTA' => 'limit',
        'MAX_EMAIL_PER_HOUR' => 'limit',
        'MAX_TEAM_USERS' => 'nullable_int',
        'QUOTA' => 'limit',
        '_PACKAGE_EXTENSIONS' => 'string',
        'name' => 'string',
    ];

    /** @var array<string, string> Pinned createacct response fields (all optional strings). */
    private const CREATED_FIELDS = [
        'ip' => 'string',
        'nameserver' => 'string',
        'nameserver2' => 'string',
        'nameserver3' => 'string',
        'nameserver4' => 'string',
        'nameservera' => 'string',
        'nameservera2' => 'string',
        'nameservera3' => 'string',
        'nameservera4' => 'string',
        'nameserverentry' => 'string',
        'nameserverentry2' => 'string',
        'nameserverentry3' => 'string',
        'nameserverentry4' => 'string',
        'package' => 'string',
    ];

    /** @var array<string, string> Pinned modifyacct response fields. */
    private const MODIFIED_FIELDS = [
        'cpuser' => 'json_object',
        'domain' => 'string',
        'setshell' => 'string',
        'user' => 'string',
    ];

    /**
     * Bind account requests to their owning WHM client's lifecycle.
     *
     * The synchronous callback executes the named operation with its parameters
     * using the given request method (GET by default).
     *
     * @param Closure(string, Parameters, RequestMethod=): ApiResponse $request
     * @internal Obtain this group through WhmClient::accounts().
     */
    public function __construct(private Closure $request)
    {
    }

    /**
     * List the server's cPanel accounts, optionally filtered.
     *
     * Executes read-only WHM listaccts. Null parameters are omitted; the others
     * are forwarded unchanged (searchtype selects the field, searchmethod `exact`
     * or `regex`, want limits the returned fields). Returns the required
     * `data.acct` records; a missing list or a malformed field raises
     * InvalidResponseException. Pinned API-map identity: `whm1:listaccts#GET`.
     *
     * @return list<AccountRecord>
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/listaccts
     */
    public function list(
        ?string $search = null,
        ?string $searchType = null,
        ?string $searchMethod = null,
        ?string $want = null,
    ): array {
        $response = ($this->request)('listaccts', self::present([
            'search' => $search,
            'searchmethod' => $searchMethod,
            'searchtype' => $searchType,
            'want' => $want,
        ]));
        $read = self::reader($response);

        return $read->listOf(
            $read->field($response->data, 'acct'),
            static fn (mixed $record): array => self::account($record, $read),
        );
    }

    /**
     * Return one account's summary record.
     *
     * Executes read-only WHM accountsummary with the `user` parameter. The
     * required `data.acct` list must hold exactly one record, which is returned;
     * an unknown account is reported by WHM as a failed outcome
     * (ApiErrorException). Pinned API-map identity: `whm1:accountsummary#GET`.
     *
     * @return AccountRecord
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/accountsummary
     */
    public function summary(string $user): array
    {
        $response = ($this->request)('accountsummary', ['user' => $user]);
        $read = self::reader($response);
        $records = $read->listOf(
            $read->field($response->data, 'acct'),
            static fn (mixed $record): array => self::account($record, $read),
        );

        return count($records) === 1 ? $records[0] : $read->fail();
    }

    /**
     * List the hosting packages (plans) visible to the authenticated WHM user.
     *
     * Executes read-only WHM listpkgs. A null want omits the filter (upstream
     * default `all`); `creatable`, `editable`, and `viewable` are forwarded
     * unchanged. Returns the required `data.pkg` records.
     * Pinned API-map identity: `whm1:listpkgs#GET`.
     *
     * @return list<PackageRecord>
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/listpkgs
     */
    public function packages(?string $want = null): array
    {
        $response = ($this->request)('listpkgs', self::present(['want' => $want]));
        $read = self::reader($response);

        return $read->listOf(
            $read->field($response->data, 'pkg'),
            static fn (mixed $record): array => self::package($record, $read),
        );
    }

    /**
     * Create a cPanel account.
     *
     * Executes WHM createacct over POST with the username and the documented
     * options forwarded unchanged (`password` or `pass`, `domain`, `plan`, limits,
     * flags as 0/1 integers, `y`/`n` strings where documented). A `username` option
     * is overridden by the argument. Returns the optional nameserver and package
     * strings from `data`, or an empty array when WHM returns no data; the
     * creation log stays in responseMeta() under metadata.output.raw.
     * Pinned API-map identity: `whm1:createacct#GET` (parameters sent in the body).
     *
     * @param CreateAccountOptions $options
     * @return CreatedAccount
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/createacct
     */
    public function create(string $username, #[SensitiveParameter] array $options = []): array
    {
        $response = ($this->request)('createacct', ['username' => $username] + $options, RequestMethod::Post);

        /** @var CreatedAccount $values Each present field was validated as a string. */
        $values = self::optionalRecord($response, self::CREATED_FIELDS);

        return $values;
    }

    /**
     * Suspend a cPanel account.
     *
     * Executes WHM suspendacct over POST. Null flags are omitted (upstream
     * defaults apply); true sends 1 and false sends 0 for `disallowun` (only root
     * may unsuspend) and `leave-ftp-accts-enabled`. The upstream default reason is
     * `Unknown`; provide one. A failed outcome raises ApiErrorException.
     * Pinned API-map identity: `whm1:suspendacct#GET` (parameters sent in the body).
     *
     * @throws ApiException If transport, HTTP, or the WHM outcome fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/suspendacct
     */
    public function suspend(
        string $user,
        ?string $reason = null,
        ?bool $onlyRootMayUnsuspend = null,
        ?bool $leaveFtpAccountsEnabled = null,
    ): void {
        ($this->request)('suspendacct', self::present([
            'user' => $user,
            'reason' => $reason,
            'disallowun' => self::flag($onlyRootMayUnsuspend),
            'leave-ftp-accts-enabled' => self::flag($leaveFtpAccountsEnabled),
        ]), RequestMethod::Post);
    }

    /**
     * Unsuspend a cPanel account.
     *
     * Executes WHM unsuspendacct over POST. A null flag omits
     * `retain-service-proxies`; true sends 1 and false sends 0. A failed outcome
     * raises ApiErrorException.
     * Pinned API-map identity: `whm1:unsuspendacct#GET` (parameters sent in the body).
     *
     * @throws ApiException If transport, HTTP, or the WHM outcome fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/unsuspendacct
     */
    public function unsuspend(string $user, ?bool $retainServiceProxies = null): void
    {
        ($this->request)('unsuspendacct', self::present([
            'user' => $user,
            'retain-service-proxies' => self::flag($retainServiceProxies),
        ]), RequestMethod::Post);
    }

    /**
     * Remove a cPanel account and its data.
     *
     * Executes WHM removeacct over POST with the documented `username`
     * parameter. A null flag omits `keepdns`; true retains the DNS zone (1) and
     * false deletes it (0). This is destructive and not reversible by the API;
     * a failed outcome raises ApiErrorException.
     * Pinned API-map identity: `whm1:removeacct#GET` (parameters sent in the body).
     *
     * @throws ApiException If transport, HTTP, or the WHM outcome fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/removeacct
     */
    public function remove(string $username, ?bool $keepDns = null): void
    {
        ($this->request)('removeacct', self::present([
            'username' => $username,
            'keepdns' => self::flag($keepDns),
        ]), RequestMethod::Post);
    }

    /**
     * Change a cPanel account's password.
     *
     * Executes WHM passwd over POST so the password never enters the URL. Null
     * flags are omitted; `db_pass_update` (also change the MySQL password,
     * upstream default 1) and `digestauth` send 1 or 0. Returns the optional
     * `data.app` list of services that were updated, or an empty array when WHM
     * returns no data. Pinned API-map identity: `whm1:passwd#GET` (parameters sent in the body).
     *
     * @return array{app?: list<string>}
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/passwd
     */
    public function changePassword(
        string $user,
        #[SensitiveParameter] string $password,
        ?bool $updateDatabasePassword = null,
        ?bool $digestAuthentication = null,
    ): array {
        $response = ($this->request)('passwd', self::present([
            'user' => $user,
            'password' => $password,
            'db_pass_update' => self::flag($updateDatabasePassword),
            'digestauth' => self::flag($digestAuthentication),
        ]), RequestMethod::Post);
        $read = self::reader($response);
        if (!$response->hasData || $response->data === null) {
            return [];
        }
        $data = $read->object($response->data);

        return property_exists($data, 'app') ? ['app' => $read->strings($data->app)] : [];
    }

    /**
     * Move a cPanel account to another hosting package.
     *
     * Executes WHM changepackage over POST with `user` and `pkg`. A failed
     * outcome (unknown package or account) raises ApiErrorException.
     * Pinned API-map identity: `whm1:changepackage#GET` (parameters sent in the body).
     *
     * @throws ApiException If transport, HTTP, or the WHM outcome fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/changepackage
     */
    public function changePackage(string $user, string $package): void
    {
        ($this->request)('changepackage', ['user' => $user, 'pkg' => $package], RequestMethod::Post);
    }

    /**
     * Modify a cPanel account's settings.
     *
     * Executes WHM modifyacct over POST with `user` and the documented changes
     * forwarded unchanged (upper-case keys as documented; flags as 0/1). A `user`
     * change is overridden by the argument; rename with `newuser`. Per-domain
     * `MXCHECK-<domain>` keys are not part of the typed shape; send them through
     * `call()`. Returns the optional `data` fields: the opaque `cpuser` object as
     * an array plus `domain`, `setshell`, and `user` strings, or an empty array
     * when WHM returns no data. Pinned API-map identity: `whm1:modifyacct#GET`
     * (parameters sent in the body).
     *
     * @param AccountChanges $changes
     * @return ModifiedAccount
     * @throws ApiException If transport, HTTP, the WHM outcome, or the payload fails.
     * @see https://api.docs.cpanel.net/openapi/whm/operation/modifyacct
     */
    public function modify(string $user, #[SensitiveParameter] array $changes): array
    {
        $response = ($this->request)('modifyacct', ['user' => $user] + $changes, RequestMethod::Post);

        /** @var ModifiedAccount $values Each present field was validated against its pinned type. */
        $values = self::optionalRecord($response, self::MODIFIED_FIELDS);

        return $values;
    }

    /** Bind payload validation failures to this response's snapshot. */
    private static function reader(ApiResponse $response): PayloadReader
    {
        return new PayloadReader($response->metadata, 'account payload');
    }

    /**
     * Drop omitted (null) parameters, keeping the documented order.
     *
     * @param array<string, string|int|null> $parameters
     * @return Parameters
     */
    private static function present(array $parameters): array
    {
        return array_filter($parameters, static fn (string|int|null $value): bool => $value !== null);
    }

    /** Encode an optional boolean as the documented 0/1 integer, or omit it. */
    private static function flag(?bool $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }

    /**
     * Validate one account record.
     *
     * @return AccountRecord
     */
    private static function account(mixed $record, PayloadReader $read): array
    {
        /** @var AccountRecord $values Each present field was validated against its pinned type. */
        $values = self::record($read->object($record), self::ACCOUNT_FIELDS, $read);

        return $values;
    }

    /**
     * Validate one package record.
     *
     * @return PackageRecord
     */
    private static function package(mixed $record, PayloadReader $read): array
    {
        /** @var PackageRecord $values Each present field was validated against its pinned type. */
        $values = self::record($read->object($record), self::PACKAGE_FIELDS, $read);

        return $values;
    }

    /**
     * Validate an optional data object, treating an omitted or null data member as empty.
     *
     * @param array<string, string> $fields
     * @return array<array-key, mixed>
     */
    private static function optionalRecord(ApiResponse $response, array $fields): array
    {
        if (!$response->hasData || $response->data === null) {
            return [];
        }
        $read = self::reader($response);

        return self::record($read->object($response->data), $fields, $read);
    }

    /**
     * Copy the known fields of a decoded record with per-field validation.
     *
     * Unknown fields are ignored; present known fields must match their kind.
     *
     * @param array<string, string> $fields Field name to validation kind.
     * @return array<array-key, mixed>
     */
    private static function record(object $record, array $fields, PayloadReader $read): array
    {
        $values = [];
        foreach (get_object_vars($record) as $field => $value) {
            $kind = $fields[$field] ?? null;
            if ($kind === null) {
                continue;
            }
            $values[$field] = match ($kind) {
                'flag' => $read->flag($value),
                'int' => is_int($value) ? $value : $read->fail(),
                'int_or_decimal_string' => self::decimal($value, $read),
                'limit' => is_int($value) || is_string($value) ? $value : $read->fail(),
                'nullable_int' => $value === null || is_int($value) ? $value : $read->fail(),
                'nullable_int_or_string' => $value === null || is_int($value) || is_string($value)
                    ? $value : $read->fail(),
                'nullable_string' => $read->nullableString($value),
                'string' => $read->string($value),
                'strings' => $read->strings($value),
                'json_list' => $read->listOf($value, $read->json(...)),
                default => $read->json($read->object($value)),
            };
        }

        return $values;
    }

    /**
     * Accept the pinned integer or the observed canonical decimal string, normalized to int.
     *
     * Only strings that round-trip through PHP's integer conversion (no sign,
     * leading zeros, fraction, or overflow) are accepted.
     */
    private static function decimal(mixed $value, PayloadReader $read): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value === (string) (int) $value && (int) $value >= 0
            ? (int) $value : $read->fail();
    }
}
