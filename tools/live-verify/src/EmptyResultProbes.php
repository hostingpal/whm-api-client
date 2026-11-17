<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use Closure;
use HostingPal\WhmApiClient\Api\Uapi\Spec\BandwidthSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\EmailSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FeaturesSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\FilemanSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SslSpec;
use HostingPal\WhmApiClient\Api\Uapi\Spec\SubDomainSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\AccountsSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\HostingPlansSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\MailSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\ServerSpec;
use HostingPal\WhmApiClient\Api\Whm\Spec\WebServerSpec;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\UapiClient;
use LogicException;

/**
 * Bounded no-match audit: 15 curated read-only operations, each given one impossible or absent criterion.
 *
 * Selection follows pinned operation semantics and documented filters, never parameter names alone. The
 * catalog keeps listaccts as the fixed-path control and accountsummary as the entity-lookup control, which
 * must still fail.
 *
 * Each probe declares the outcome the 2026-11-01 capture recorded for it, and a record is assessed against
 * that declaration. The status therefore means something: a pass reproduces the recorded forms, and a client
 * exception, a populated result or a success where a failure was recorded fails. A declaration is an observed
 * form, not a contract, and a pass establishes no no-match semantics. Classification is reviewed per operation.
 *
 * No request targets another account, changes state or uses a live-derived value.
 */
final class EmptyResultProbes
{
    /**
     * Recipe label. v2 changed the assessment, not the requests: v1 applied the strict rule, which the catalog
     * cannot pass. Each label has one committed capture under docs/api/evidence.
     */
    public const RECIPE = 'empty-results-v2';

    /** The provenance read returned normally. */
    public const OUTCOME_VALUE = 'value';

    /** The typed method returned, with exactly the declared empty container and no discrepancy. */
    public const OUTCOME_EMPTY = 'empty';

    /**
     * listaccts only: the typed method returned from a response that omits data.
     *
     * The client's fixed path takes that form on result 0 with its exact diagnostic and on result 1, and the
     * record keeps neither value, so this names the path taken, not what the API reported. The discrepancy it
     * requires stays on the record: declaring it does not remove or waive it.
     */
    public const OUTCOME_FIXED_PATH_EMPTY = 'fixed_path_empty';

    /** The typed method raised ApiErrorException. The record keeps no upstream text, so no cause is established. */
    public const OUTCOME_API_FAILURE = 'api_failure';

    /**
     * Declared outcome and payload form per `operation|argument label`.
     *
     * The form maps payload paths to the one type each must hold. Both UAPI routes recorded the same result, so
     * they share a row.
     *
     * @var array<string, array{self::OUTCOME_*, array<string, string>}>
     */
    private const DECLARED = [
        'whm1:version|' => [self::OUTCOME_VALUE, []],
        'whm1:listaccts|fixed-path-control' => [self::OUTCOME_FIXED_PATH_EMPTY, ['$' => 'missing']],
        'whm1:showbw|absent-user-pcre' => [self::OUTCOME_EMPTY, ['$.acct' => 'empty-list']],
        'whm1:matchpkgs|absent-feature-list' => [self::OUTCOME_EMPTY, ['$.pkg' => 'empty-list']],
        'whm1:emailtrack_search|all-event-types-off' => [self::OUTCOME_EMPTY, ['$.records' => 'empty-list']],
        'whm1:emailtrack_user_stats|absent-sender-past-window' => [
            self::OUTCOME_EMPTY,
            ['$.records' => 'empty-list'],
        ],
        'whm1:get_unique_sender_recipient_count_per_user|past-window' => [
            self::OUTCOME_EMPTY,
            ['$.payload' => 'empty-list'],
        ],
        'whm1:php_get_vhosts_by_version|absent-php-package' => [self::OUTCOME_EMPTY, ['$.vhosts' => 'empty-list']],
        'whm1:accountsummary|entity-lookup-control' => [self::OUTCOME_API_FAILURE, ['$.acct' => 'null']],
        'uapi:Email/list_pops|absent-regex' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:Email/list_pops_with_disk|absent-regex' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:Email/list_pops_with_disk|absent-domain' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:SubDomain/listsubdomains|absent-regex' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:Features/list_features_like|absent-substring' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:Fileman/autocompletedir|absent-prefix' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
        'uapi:Bandwidth/query|absent-domain' => [self::OUTCOME_API_FAILURE, ['$' => 'null']],
        'uapi:Bandwidth/query|past-window' => [self::OUTCOME_EMPTY, ['$' => 'empty-object']],
        'uapi:SSL/list_ssl_items|absent-domain' => [self::OUTCOME_EMPTY, ['$' => 'empty-list']],
    ];

    /** Unused account-name, regex and path-prefix criterion; contains nothing that needs URL encoding. */
    public const ABSENT = 'wzzemptyaudit';

    /** Reserved-TLD domain criterion; it cannot resolve to a real or future account domain. */
    public const ABSENT_DOMAIN = 'wzzemptyaudit.invalid';

    /** Reserved-TLD sender criterion for mail tracking. */
    public const ABSENT_SENDER = 'nobody@wzzemptyaudit.invalid';

    /** A PHP package name that no EasyApache build provides. */
    public const ABSENT_PHP_PACKAGE = 'ea-php00';

    /** One-second window start in 2001, predating this cPanel release line by decades. */
    public const PAST_WINDOW_START = 1000000000;

    /** One-second window end paired with PAST_WINDOW_START. */
    public const PAST_WINDOW_END = 1000000001;

    /** @return list<Probe> */
    public static function load(): array
    {
        $whm = 'https://test.oblak.host:2087/json-api/';
        $probes = [
            new Probe(
                'whm1:version',
                'whm',
                ServerSpec::VERSION,
                $whm . 'version?api.version=1',
                static fn (Client $client): mixed => $client->whm()->server()->version(),
            ),
            new Probe(
                'whm1:listaccts',
                'whm',
                AccountsSpec::LISTACCTS,
                $whm . 'listaccts?api.version=1&search=' . self::ABSENT . '&searchtype=user&searchmethod=exact',
                static fn (Client $client): array => $client->whm()->accounts()->listAccounts(
                    search: self::ABSENT,
                    searchtype: 'user',
                    searchmethod: 'exact',
                ),
                argumentVariant: 'fixed-path-control',
            ),
            new Probe(
                'whm1:showbw',
                'whm',
                AccountsSpec::SHOWBW,
                $whm . 'showbw?api.version=1&search=' . self::ABSENT . '&searchtype=user',
                static fn (Client $client): array => $client->whm()->accounts()->showBandwidth(
                    search: self::ABSENT,
                    searchtype: 'user',
                ),
                argumentVariant: 'absent-user-pcre',
            ),
            new Probe(
                'whm1:matchpkgs',
                'whm',
                HostingPlansSpec::MATCHPKGS,
                $whm . 'matchpkgs?api.version=1&FEATURELIST=' . self::ABSENT,
                static fn (Client $client): array => $client->whm()->hostingPlans()->matchPackages(
                    featurelist: self::ABSENT,
                ),
                argumentVariant: 'absent-feature-list',
            ),
            new Probe(
                'whm1:emailtrack_search',
                'whm',
                MailSpec::EMAILTRACK_SEARCH,
                $whm . 'emailtrack_search?api.version=1&defer=0&failure=0&inprogress=0&max_results_by_type=1&success=0',
                static fn (Client $client): array => $client->whm()->mail()->emailtrackSearch(
                    defer: false,
                    failure: false,
                    inProgress: false,
                    maxResultsByType: 1,
                    success: false,
                ),
                argumentVariant: 'all-event-types-off',
            ),
            new Probe(
                'whm1:emailtrack_user_stats',
                'whm',
                MailSpec::EMAILTRACK_USER_STATS,
                $whm . 'emailtrack_user_stats?api.version=1&endtime=' . self::PAST_WINDOW_END
                    . '&sender=nobody%40' . self::ABSENT_DOMAIN . '&starttime=' . self::PAST_WINDOW_START,
                static fn (Client $client): array => $client->whm()->mail()->emailtrackUserStats(
                    endTime: self::PAST_WINDOW_END,
                    sender: self::ABSENT_SENDER,
                    startTime: self::PAST_WINDOW_START,
                ),
                argumentVariant: 'absent-sender-past-window',
            ),
            new Probe(
                'whm1:get_unique_sender_recipient_count_per_user',
                'whm',
                MailSpec::GET_UNIQUE_SENDER_RECIPIENT_COUNT_PER_USER,
                $whm . 'get_unique_sender_recipient_count_per_user?api.version=1&start_time='
                    . self::PAST_WINDOW_START . '&end_time=' . self::PAST_WINDOW_END,
                static fn (Client $client): array => $client->whm()->mail()->getUniqueSenderRecipientCountPerUser(
                    startTime: self::PAST_WINDOW_START,
                    endTime: self::PAST_WINDOW_END,
                ),
                argumentVariant: 'past-window',
            ),
            new Probe(
                'whm1:php_get_vhosts_by_version',
                'whm',
                WebServerSpec::PHP_GET_VHOSTS_BY_VERSION,
                $whm . 'php_get_vhosts_by_version?api.version=1&version=' . self::ABSENT_PHP_PACKAGE,
                static fn (Client $client): array => $client->whm()->webServer()->phpGetVhostsByVersion(
                    version: self::ABSENT_PHP_PACKAGE,
                ),
                argumentVariant: 'absent-php-package',
            ),
            new Probe(
                'whm1:accountsummary',
                'whm',
                AccountsSpec::ACCOUNTSUMMARY,
                $whm . 'accountsummary?api.version=1&user=' . self::ABSENT,
                static fn (Client $client): array => $client->whm()->accounts()->accountSummary(user: self::ABSENT),
                argumentVariant: 'entity-lookup-control',
            ),
        ];
        foreach (
            [
                ['Email', 'list_pops', EmailSpec::LIST_POPS, 'regex=' . self::ABSENT, 'absent-regex',
                    static fn (UapiClient $account): array => $account->email()->listPops(regex: self::ABSENT)],
                ['Email', 'list_pops_with_disk', EmailSpec::LIST_POPS_WITH_DISK, 'regex=' . self::ABSENT,
                    'absent-regex',
                    static fn (UapiClient $account): array => $account->email()->listPopsWithDisk(
                        regex: self::ABSENT,
                    )],
                ['Email', 'list_pops_with_disk', EmailSpec::LIST_POPS_WITH_DISK, 'domain=' . self::ABSENT_DOMAIN,
                    'absent-domain',
                    static fn (UapiClient $account): array => $account->email()->listPopsWithDisk(
                        domain: self::ABSENT_DOMAIN,
                    )],
                ['SubDomain', 'listsubdomains', SubDomainSpec::LISTSUBDOMAINS, 'regex=' . self::ABSENT,
                    'absent-regex',
                    static fn (UapiClient $account): array => $account->subDomain()->listSubdomains(
                        regex: self::ABSENT,
                    )],
                ['Features', 'list_features_like', FeaturesSpec::LIST_FEATURES_LIKE,
                    'pattern=' . self::ABSENT . '&is_regex=0', 'absent-substring',
                    static fn (UapiClient $account): ?array => $account->features()->listFeaturesLike(
                        pattern: self::ABSENT,
                        isRegex: false,
                    )],
                ['Fileman', 'autocompletedir', FilemanSpec::AUTOCOMPLETEDIR, 'path=' . self::ABSENT,
                    'absent-prefix',
                    static fn (UapiClient $account): array => $account->fileman()->autocompleteDirectory(
                        path: self::ABSENT,
                    )],
                ['Bandwidth', 'query', BandwidthSpec::QUERY, 'grouping=domain&domains=' . self::ABSENT_DOMAIN,
                    'absent-domain',
                    static fn (UapiClient $account): array => $account->bandwidth()->query(
                        grouping: 'domain',
                        domains: self::ABSENT_DOMAIN,
                    )],
                ['Bandwidth', 'query', BandwidthSpec::QUERY,
                    'grouping=domain&end=' . self::PAST_WINDOW_END . '&start=' . self::PAST_WINDOW_START,
                    'past-window',
                    static fn (UapiClient $account): array => $account->bandwidth()->query(
                        grouping: 'domain',
                        end: self::PAST_WINDOW_END,
                        start: self::PAST_WINDOW_START,
                    )],
                ['SSL', 'list_ssl_items', SslSpec::LIST_SSL_ITEMS, 'domains=' . self::ABSENT_DOMAIN,
                    'absent-domain',
                    static fn (UapiClient $account): array => $account->ssl()->listSslItems(
                        domains: self::ABSENT_DOMAIN,
                    )],
            ] as [$module, $function, $spec, $parameters, $variant, $invoke]
        ) {
            foreach (['direct-uapi', 'bridged-uapi'] as $route) {
                $probes[] = self::probe($module, $function, $route, $spec, $parameters, $variant, $invoke);
            }
        }

        return $probes;
    }

    /**
     * Name the outcome this catalog declares for a probe.
     *
     * @return self::OUTCOME_*
     * @throws LogicException If the probe has no declaration, so a catalog change cannot pass undeclared.
     */
    public static function declaredOutcome(Probe $probe): string
    {
        return self::declared($probe)[0];
    }

    /**
     * Report whether a record shows its probe's declared outcome and payload form.
     *
     * This is the per-probe rule ReadOnlySectionRunner::passes() applies for this recipe; provenance stays there.
     * Records are untrusted evidence, so anything absent or malformed fails closed.
     *
     * @param array<mixed> $record
     * @throws LogicException If the probe has no declaration.
     */
    public static function matchesDeclaredOutcome(Probe $probe, array $record): bool
    {
        [$outcome, $form] = self::declared($probe);
        $returned = ['api_outcome' => 'success', 'payload_validation' => 'passed', 'probe_outcome' => 'passed',
            'failure_kind' => null];
        $expected = match ($outcome) {
            self::OUTCOME_API_FAILURE => ['api_outcome' => 'failure', 'payload_validation' => 'not_checked',
                'probe_outcome' => 'failed', 'failure_kind' => 'api_failure', 'discrepancies' => []],
            self::OUTCOME_FIXED_PATH_EMPTY => $returned
                + ['discrepancies' => [['path' => '$', 'kind' => 'missing_required']]],
            default => $returned + ['discrepancies' => []],
        };
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $record) || $record[$key] !== $value) {
                return false;
            }
        }
        $paths = $record['payload_paths'] ?? null;
        foreach ($form as $path => $type) {
            if (!is_array($paths) || ($paths[$path] ?? null) !== [$type]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{self::OUTCOME_*, array<string, string>}
     * @throws LogicException If the probe has no declaration.
     */
    private static function declared(Probe $probe): array
    {
        return self::DECLARED[$probe->operation . '|' . ($probe->argumentVariant ?? '')]
            ?? throw new LogicException('Every no-match probe must declare its outcome.');
    }

    /**
     * @param 'direct-uapi'|'bridged-uapi' $route
     * @param array<mixed> $spec
     * @param Closure(UapiClient): mixed $invoke
     */
    private static function probe(
        string $module,
        string $function,
        string $route,
        array $spec,
        string $parameters,
        string $variant,
        Closure $invoke,
    ): Probe {
        $direct = $route === 'direct-uapi';

        return new Probe(
            'uapi:' . $module . '/' . $function,
            $route,
            $spec,
            $direct
                ? 'https://test.oblak.host:2083/execute/' . $module . '/' . $function . '?' . $parameters
                : 'https://test.oblak.host:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=npr'
                    . '&cpanel.module=' . $module . '&cpanel.function=' . $function . '&' . $parameters,
            static fn (Client $client): mixed => $invoke($direct ? $client->uapi() : $client->uapi('npr')),
            argumentVariant: $variant,
        );
    }
}
