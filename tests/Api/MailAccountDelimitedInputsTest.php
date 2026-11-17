<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use HostingPal\WhmApiClient\Api\Uapi\BoxTrapper;
use HostingPal\WhmApiClient\Api\Uapi\Email;
use HostingPal\WhmApiClient\Api\Uapi\Mime;
use HostingPal\WhmApiClient\Api\Uapi\Mysql;
use HostingPal\WhmApiClient\Api\Uapi\Team;
use HostingPal\WhmApiClient\Api\Whm\Mail;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use HostingPal\WhmApiClient\UapiClient;
use HostingPal\WhmApiClient\WhmClient;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;
use TypeError;

/** Offline synthetic fixtures: no account, mail, database or server actions occur. */
final class MailAccountDelimitedInputsTest extends TestCase
{
    public function testBoxTrapperFromAddressesAcceptsProtectedListsAtItsExistingPosition(): void
    {
        self::assertSignature(BoxTrapper::class, 'saveConfiguration', 2, 'fromAddresses', false, [
            'email', 'enableAutoWhitelist', 'fromAddresses', 'queueDays', 'whitelistByAssociation',
            'fromName', 'spamScore',
        ]);
    }

    public function testForwardEmailAliasAcceptsProtectedOptionalListsAtItsExistingPosition(): void
    {
        self::assertSignature(Email::class, 'addForwarder', 4, 'forwardEmail', true, [
            'domain', 'email', 'forwardOption', 'failureMessage', 'forwardEmail', 'forwardSystem', 'pipeForward',
        ]);
    }

    public function testMailmanDelegatesAcceptsProtectedListsBeforeListName(): void
    {
        self::assertSignature(Email::class, 'addMailmanDelegates', 0, 'delegates', false, ['delegates', 'list']);
    }

    public function testHotlinkExtensionsAcceptsProtectedListsWithoutWideningUrls(): void
    {
        self::assertSignature(Mime::class, 'addHotlink', 0, 'extensions', false, [
            'extensions', 'redirectUrl', 'urls', 'allowNull',
        ]);
        self::assertSame('string', (string) (new ReflectionMethod(Mime::class, 'addHotlink'))
            ->getParameters()[2]->getType());
    }

    public function testMysqlPrivilegesAcceptsProtectedOptionalListsAfterDatabaseAndUser(): void
    {
        self::assertSignature(Mysql::class, 'setPrivilegesOnDatabase', 2, 'privileges', true, [
            'database', 'user', 'privileges',
        ]);
    }

    public function testTeamRolesAcceptsProtectedOptionalListsWithAllNeighboringArgumentsRetained(): void
    {
        self::assertSignature(Team::class, 'addTeamUser', 8, 'roles', true, [
            'email1', 'user', 'activationEmail', 'email2', 'expireDate', 'expireReason', 'notes', 'password', 'roles',
            'servicesEmailEnabled', 'servicesEmailQuota', 'servicesFtpEnabled', 'servicesFtpHomedir',
            'servicesWebdiskEnabled', 'servicesWebdiskEnabledigest', 'servicesWebdiskHomedir',
            'servicesWebdiskPerms', 'servicesWebdiskPrivate',
        ]);
    }

    public function testMobileProfileServicesAcceptsProtectedOptionalListsAfterAccountAndSsl(): void
    {
        self::assertSignature(Mail::class, 'generateMobileconfig', 2, 'selectedAccountServices', true, [
            'account', 'useSsl', 'selectedAccountServices',
        ]);
    }

    public function testSystemForwardDestinationsAcceptsProtectedListsAfterUser(): void
    {
        self::assertSignature(Mail::class, 'setUserEmailForwardDestination', 1, 'forwardTo', false, [
            'user', 'forwardTo',
        ]);
    }

    /** @return iterable<string, array{string, string, string, string, int, string}> */
    public static function adopters(): iterable
    {
        $uapi = [
            ['boxTrapper', 'saveConfiguration', 'fromAddresses', 2, 'BoxTrapper/save_configuration'],
            ['email', 'addForwarder', 'forwardEmail', 4, 'Email/add_forwarder'],
            ['email', 'addMailmanDelegates', 'delegates', 0, 'Email/add_mailman_delegates'],
            ['mime', 'addHotlink', 'extensions', 0, 'Mime/add_hotlink'],
            ['mysql', 'setPrivilegesOnDatabase', 'privileges', 2, 'Mysql/set_privileges_on_database'],
            ['team', 'addTeamUser', 'roles', 8, 'Team/add_team_user'],
        ];
        foreach (['direct', 'bridge'] as $route) {
            foreach ($uapi as $position) {
                yield "$route {$position[1]}" => [$route, ...$position];
            }
        }
        yield 'whm mobile profile' => ['whm', 'mail', 'generateMobileconfig', 'selectedAccountServices', 2,
            'generate_mobileconfig'];
        yield 'whm system forward' => ['whm', 'mail', 'setUserEmailForwardDestination', 'forwardTo', 1,
            'set_user_email_forward_destination'];
    }

    #[DataProvider('adopters')]
    public function testListsAndRawScalarsRetainLiteralRequestsRoutingAndResponses(
        string $route,
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $cases = [
            [['b', 'a', 'b'], 'b%2Ca%2Cb'],
            ['b,a,b', 'b%2Ca%2Cb'],
            [',%2C%7C|', '%2C%252C%257C%7C'],
            [['%2C', '%7C|'], '%252C%2C%257C%7C'],
            [[''], ''],
            ['', ''],
        ];
        foreach ($cases as $index => [$value, $encoded]) {
            self::assertSame(self::fixtureResult($method), self::invoke(
                $handle,
                $group,
                $method,
                [$parameter => $value],
                true,
            ));
            self::assertRequest($http, $route, $method, $operation, self::fields($method, $encoded, true));
            self::assertCount($index + 1, $http->getRequests());
            $metadata = $handle->responseMeta();
            self::assertNotNull($metadata);
            if ($route !== 'whm') {
                self::assertSame(['fixture warning'], $metadata->uapiWarnings());
                self::assertIsArray($metadata->uapi);
                self::assertArrayHasKey('metadata', $metadata->uapi);
                self::assertSame(['transformed' => 1], $metadata->uapi['metadata']);
            }
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function optionalPositions(): iterable
    {
        foreach (['direct', 'bridge'] as $route) {
            yield "$route forwarder" => [$route, 'email', 'addForwarder', 'forwardEmail'];
            yield "$route privileges" => [$route, 'mysql', 'setPrivilegesOnDatabase', 'privileges'];
            yield "$route roles" => [$route, 'team', 'addTeamUser', 'roles'];
        }
        yield 'whm services' => ['whm', 'mail', 'generateMobileconfig', 'selectedAccountServices'];
    }

    #[DataProvider('optionalPositions')]
    public function testOptionalOmittedNullAndSuppliedEmptyAreDistinctFromRejectedEmptyList(
        string $route,
        string $group,
        string $method,
        string $parameter,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $operation = match ($method) {
            'addForwarder' => 'Email/add_forwarder',
            'setPrivilegesOnDatabase' => 'Mysql/set_privileges_on_database',
            'addTeamUser' => 'Team/add_team_user',
            default => 'generate_mobileconfig',
        };
        foreach ([[], [$parameter => null], [$parameter => ''], [$parameter => ['']]] as $index => $arguments) {
            self::assertSame(self::fixtureResult($method), self::invoke($handle, $group, $method, $arguments));
            self::assertRequest($http, $route, $method, $operation, self::fields($method, $index < 2 ? null : ''));
            self::assertCount($index + 1, $http->getRequests());
        }
        $old = $handle->responseMeta();
        self::assertNotNull($old);
        $raw = [$old->whm, $old->uapi];
        try {
            self::invoke($handle, $group, $method, [$parameter => []]);
            self::fail('Empty list must not become omission or a server default.');
        } catch (InvalidArgumentException) {
            self::assertNull($handle->responseMeta());
            self::assertSame($raw, [$old->whm, $old->uapi]);
            self::assertCount(4, $http->getRequests());
        }
    }

    #[DataProvider('adopters')]
    public function testInvalidEnteredArraysClearMetadataWithoutDispatchAndHideActualArgumentFrames(
        string $route,
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $invalid = [[], ['synthetic-private-first', 'synthetic-private-late,']];
        $invalid[] = match ($method) {
            'saveConfiguration' => [1 => 'synthetic-private-sparse'],
            'addForwarder' => ['key' => 'synthetic-private-keyed'],
            'addMailmanDelegates' => ['synthetic-private-first', null],
            'addHotlink' => ['synthetic-private-first', 0],
            'setPrivilegesOnDatabase' => ['synthetic-private-first', false],
            'addTeamUser' => ['synthetic-private-first', 1.5],
            'generateMobileconfig' => ['synthetic-private-first', ['synthetic-private-nested']],
            default => ['synthetic-private-first', new \stdClass()],
        };
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ($invalid as $value) {
                self::invoke($handle, $group, $method, [$parameter => ['seed']]);
                $old = $handle->responseMeta();
                self::assertNotNull($old);
                $raw = [$old->whm, $old->uapi];
                $count = count($http->getRequests());
                try {
                    self::invoke($handle, $group, $method, [$parameter => $value]);
                    self::fail('Invalid array accepted for ' . $operation);
                } catch (InvalidArgumentException $error) {
                    self::assertNull($handle->responseMeta());
                    self::assertSame($raw, [$old->whm, $old->uapi]);
                    self::assertCount($count, $http->getRequests());
                    self::assertStringNotContainsString('synthetic-private-', $error->getMessage());
                    $seen = false;
                    foreach ($error->getTrace() as $frame) {
                        $class = $frame['class'] ?? '';
                        if ($class === self::group($handle, $group)::class) {
                            self::assertSame($method, $frame['function']);
                            self::assertInstanceOf(
                                SensitiveParameterValue::class,
                                ($frame['args'] ?? [])[$position] ?? null,
                            );
                            $seen = true;
                        }
                        if (
                            str_starts_with($class, 'HostingPal\\WhmApiClient\\')
                            && !str_contains($class, '\\Tests\\')
                        ) {
                            self::assertFalse(self::containsPrivateValue($frame['args'] ?? []));
                        }
                    }
                    self::assertTrue($seen, 'Generated public method frame must be inspected.');
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }

    #[DataProvider('adopters')]
    public function testNativeWrongTypesFailBeforeEntryAndRetainPriorMetadata(
        string $route,
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        self::invoke($handle, $group, $method, [$parameter => 'seed']);
        $old = $handle->responseMeta();
        self::assertNotNull($old);
        $wrong = in_array($method, ['saveConfiguration', 'addMailmanDelegates', 'addHotlink',
            'setUserEmailForwardDestination'], true) ? [null, 0, false, 1.5, new \stdClass()] : [0];
        foreach ($wrong as $value) {
            try {
                self::invoke($handle, $group, $method, [$parameter => $value]);
                self::fail('Native wrong type accepted for ' . $operation);
            } catch (TypeError) {
                self::assertSame($old, $handle->responseMeta());
                self::assertCount(1, $http->getRequests());
            }
        }
    }

    public function testBytePreservationAndMysqlPrivilegeSpacesAreEncodedExactlyOnce(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'addMailmanDelegates');
            $byteCases = [
                [['', 'a', ''], '%2Ca%2C'],
                [[" a+&=%2C%7C/雪\n\0\xFF", '|'], '%20a%2B%26%3D%252C%257C%2F%E9%9B%AA%0A%00%FF%2C%7C'],
            ];
            foreach ($byteCases as [$value, $encoded]) {
                self::invoke($handle, 'email', 'addMailmanDelegates', ['delegates' => $value]);
                self::assertRequest(
                    $http,
                    $route,
                    'addMailmanDelegates',
                    'Email/add_mailman_delegates',
                    'delegates=' . $encoded . '&list=fixture-list',
                );
            }
            self::assertCount(2, $http->getRequests());
            [$handle, $http] = self::client($route, 'setPrivilegesOnDatabase');
            $privilegeCases = [
                [
                    ['ALL PRIVILEGES', 'CREATE TEMPORARY TABLES', 'ALL PRIVILEGES'],
                    'ALL%20PRIVILEGES%2CCREATE%20TEMPORARY%20TABLES%2CALL%20PRIVILEGES',
                ],
                ['ALL PRIVILEGES,%2C', 'ALL%20PRIVILEGES%2C%252C'],
            ];
            foreach ($privilegeCases as [$value, $encoded]) {
                self::invoke($handle, 'mysql', 'setPrivilegesOnDatabase', ['privileges' => $value]);
                self::assertRequest(
                    $http,
                    $route,
                    'setPrivilegesOnDatabase',
                    'Mysql/set_privileges_on_database',
                    'database=fixture_db&user=fixture_user&privileges=' . $encoded,
                );
            }
            self::assertCount(2, $http->getRequests());
        }
    }

    public function testPositionalBoxTrapperAndForwarderAliasAndSystemOrderRemainCompatible(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            [$handle, $http] = self::client($route, 'saveConfiguration');
            self::assertInstanceOf(UapiClient::class, $handle);
            self::assertSame(self::fixtureResult('saveConfiguration'), $handle->boxTrapper()->saveConfiguration(
                'owner@example.test',
                false,
                ['first', 'second'],
                0,
                false,
                '',
                2.5,
            ));
            self::assertRequest(
                $http,
                $route,
                'saveConfiguration',
                'BoxTrapper/save_configuration',
                self::fields('saveConfiguration', 'first%2Csecond', true),
            );
            [$handle, $http] = self::client($route, 'addForwarder');
            self::assertInstanceOf(UapiClient::class, $handle);
            self::assertSame(self::fixtureResult('addForwarder'), $handle->email()->addForwarder(
                'example.test',
                'alias@example.test',
                'fwd',
                'synthetic-failure',
                ['first', 'second'],
                'daemon',
                'synthetic-pipe',
            ));
            self::assertRequest(
                $http,
                $route,
                'addForwarder',
                'Email/add_forwarder',
                self::fields('addForwarder', 'first%2Csecond', true),
            );
            self::assertCount(1, $http->getRequests());
        }
        [$handle, $http] = self::client('whm', 'setUserEmailForwardDestination');
        self::assertInstanceOf(WhmClient::class, $handle);
        $handle->mail()->setUserEmailForwardDestination('fixture-system', ['first', 'second']);
        self::assertSame(1, $handle->responseMeta()?->whmResult());
        self::assertRequest(
            $http,
            'whm',
            'setUserEmailForwardDestination',
            'set_user_email_forward_destination',
            'user=fixture-system&forward_to=first%2Csecond',
        );
        self::assertCount(1, $http->getRequests());
    }

    public function testNullableOpaqueResultsPreserveUnknownFieldsAndNullButRejectMissing(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            $operations = [
                ['boxTrapper', 'saveConfiguration', 'fromAddresses'], ['mime', 'addHotlink', 'extensions'],
                ['mysql', 'setPrivilegesOnDatabase', 'privileges'],
            ];
            foreach ($operations as [$group, $method, $parameter]) {
                $responses = [['null', null], ['{"future":{"opaque":null}}', ['future' => ['opaque' => null]]]];
                foreach ($responses as [$payload, $expected]) {
                    [$handle, $http] = self::client($route, $method);
                    $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                    self::assertSame($expected, self::invoke($handle, $group, $method, [$parameter => ['a']]));
                    self::assertCount(1, $http->getRequests());
                }
                [$handle, $http] = self::client($route, $method);
                $http->addResponse(new Response(200, [], self::envelope($route, null)));
                try {
                    self::invoke($handle, $group, $method, [$parameter => ['a']]);
                    self::fail('Missing opaque payload must not become null.');
                } catch (PayloadMismatchException) {
                    self::assertNotNull($handle->responseMeta());
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    public function testTeamResponseKeepsReviewedNullableCanonicalTimestampContract(): void
    {
        foreach (['direct', 'bridge'] as $route) {
            foreach ([['null', null], ['"1700000000"', 1700000000]] as [$payload, $expected]) {
                [$handle, $http] = self::client($route, 'addTeamUser');
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                self::assertSame($expected, self::invoke($handle, 'team', 'addTeamUser', ['roles' => ['email']]));
                self::assertCount(1, $http->getRequests());
            }
            foreach ([null, '"01700000000"', 'true', '{}'] as $payload) {
                [$handle, $http] = self::client($route, 'addTeamUser');
                $http->addResponse(new Response(200, [], self::envelope($route, $payload)));
                try {
                    self::invoke($handle, 'team', 'addTeamUser', ['roles' => ['email']]);
                    self::fail('Missing or wrong Team timestamp must fail.');
                } catch (PayloadMismatchException) {
                    self::assertCount(1, $http->getRequests());
                }
            }
        }
    }

    #[DataProvider('adopters')]
    public function testApiFailureWinsOverValidPayloadWithoutRetry(
        string $route,
        string $group,
        string $method,
        string $parameter,
        int $position,
        string $operation,
    ): void {
        [$handle, $http] = self::client($route, $method);
        $payload = $method === 'setUserEmailForwardDestination' ? null
            : json_encode(self::fixtureResult($method), JSON_THROW_ON_ERROR);
        $http->addResponse(new Response(200, [], self::envelope($route, $payload, 0)));
        try {
            self::invoke($handle, $group, $method, [$parameter => ['a']]);
            self::fail('API failure must win over valid payload for ' . $operation);
        } catch (ApiErrorException) {
            self::assertSame(0, $route === 'whm' ? $handle->responseMeta()?->whmResult()
                : $handle->responseMeta()?->uapiStatus());
            self::assertCount(1, $http->getRequests());
        }
    }

    public function testBridgeOuterWhmFailureWinsOverNestedSuccess(): void
    {
        [$handle, $http] = self::client('bridge', 'addForwarder');
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"fixture failure"},'
            . '"data":{"uapi":{"status":1,"data":[]}}}'));
        try {
            self::invoke($handle, 'email', 'addForwarder', ['forwardEmail' => ['a']]);
            self::fail('Outer WHM failure must win over nested UAPI success.');
        } catch (ApiErrorException) {
            self::assertSame(0, $handle->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function invoke(
        WhmClient|UapiClient $handle,
        string $group,
        string $method,
        array $arguments,
        bool $neighbors = false,
    ): mixed {
        $required = match ($method) {
            'saveConfiguration' => ['email' => 'owner@example.test', 'enableAutoWhitelist' => false,
                'queueDays' => 0, 'whitelistByAssociation' => false],
            'addForwarder' => ['domain' => 'example.test', 'email' => 'alias@example.test', 'forwardOption' => 'fwd'],
            'addMailmanDelegates' => ['list' => 'fixture-list'],
            'addHotlink' => ['redirectUrl' => 'https://redirect.example.test/?x=1&y=2',
                'urls' => "https://site.example.test/\nhttps://other.example.test/"],
            'setPrivilegesOnDatabase' => ['database' => 'fixture_db', 'user' => 'fixture_user'],
            'addTeamUser' => ['email1' => 'member@example.test', 'user' => 'fixture-member'],
            'generateMobileconfig' => ['account' => 'fixture-account', 'useSsl' => false],
            default => ['user' => 'fixture-system'],
        };
        $optional = !$neighbors ? [] : match ($method) {
            'saveConfiguration' => ['fromName' => '', 'spamScore' => 2.5],
            'addForwarder' => ['failureMessage' => 'synthetic-failure', 'forwardSystem' => 'daemon',
                'pipeForward' => 'synthetic-pipe'],
            'addHotlink' => ['allowNull' => false],
            'addTeamUser' => ['activationEmail' => 0, 'email2' => '', 'expireDate' => '120days', 'expireReason' => '',
                'notes' => 'public note', 'password' => 'synthetic-password', 'servicesEmailEnabled' => false,
                'servicesEmailQuota' => '0', 'servicesFtpEnabled' => false, 'servicesFtpHomedir' => '',
                'servicesWebdiskEnabled' => false, 'servicesWebdiskEnabledigest' => false,
                'servicesWebdiskHomedir' => '', 'servicesWebdiskPerms' => 'ro', 'servicesWebdiskPrivate' => false],
            default => [],
        };
        return self::group($handle, $group)->$method(...($arguments + $required + $optional));
    }

    private static function group(WhmClient|UapiClient $handle, string $group): BoxTrapper|Email|Mime|Mysql|Team|Mail
    {
        if ($handle instanceof WhmClient) {
            return $handle->mail();
        }
        return match ($group) {
            'boxTrapper' => $handle->boxTrapper(),
            'email' => $handle->email(),
            'mime' => $handle->mime(),
            'mysql' => $handle->mysql(),
            'team' => $handle->team(),
            default => throw new \LogicException('Unknown synthetic API group.'),
        };
    }

    private static function fields(string $method, ?string $encoded, bool $neighbors = false): string
    {
        $target = match ($method) {
            'addForwarder' => 'fwdemail',
            'setPrivilegesOnDatabase' => 'privileges',
            'addTeamUser' => 'roles',
            default => 'selected_account_services',
        };
        $optionalField = $encoded === null ? '' : '&' . $target . '=' . $encoded;
        return match ($method) {
            'saveConfiguration' => 'email=owner%40example.test&enable_auto_whitelist=0&from_addresses=' . $encoded
                . '&queue_days=0&whitelist_by_association=0' . ($neighbors ? '&from_name=&spam_score=2.5' : ''),
            'addForwarder' => 'domain=example.test&email=alias%40example.test&fwdopt=fwd'
                . ($neighbors ? '&failmsgs=synthetic-failure' : '') . $optionalField
                . ($neighbors ? '&fwdsystem=daemon&pipefwd=synthetic-pipe' : ''),
            'addMailmanDelegates' => 'delegates=' . $encoded . '&list=fixture-list',
            'addHotlink' => 'extensions=' . $encoded
                . '&redirect_url=https%3A%2F%2Fredirect.example.test%2F%3Fx%3D1%26y%3D2'
                . '&urls=https%3A%2F%2Fsite.example.test%2F%0Ahttps%3A%2F%2Fother.example.test%2F'
                . ($neighbors ? '&allow_null=0' : ''),
            'setPrivilegesOnDatabase' => 'database=fixture_db&user=fixture_user' . $optionalField,
            'addTeamUser' => 'email1=member%40example.test&user=fixture-member'
                . ($neighbors ? '&activation_email=0&email2=&expire_date=120days&expire_reason=&notes=public%20note'
                    . '&password=synthetic-password' : '') . $optionalField
                . ($neighbors ? '&services.email.enabled=0&services.email.quota=0&services.ftp.enabled=0'
                    . '&services.ftp.homedir=&services.webdisk.enabled=0&services.webdisk.enabledigest=0'
                    . '&services.webdisk.homedir=&services.webdisk.perms=ro&services.webdisk.private=0' : ''),
            'generateMobileconfig' => 'account=fixture-account&use_ssl=0' . $optionalField,
            default => 'user=fixture-system&forward_to=' . $encoded,
        };
    }

    private static function fixtureResult(string $method): mixed
    {
        return match ($method) {
            'saveConfiguration', 'addHotlink', 'setPrivilegesOnDatabase' => ['future' => ['opaque' => null]],
            'addForwarder' => [['domain' => 'example.test', 'email' => 'alias@example.test',
                'forward' => 'first@example.test,second@example.test']],
            'addMailmanDelegates' => ['delegates' => ['second@example.test', 'first@example.test'],
                'metadata' => ['transformed' => ['future' => null]]],
            'generateMobileconfig' => ['payload' => "SYNTHETIC PROFILE %2C\nnot-base64"],
            default => null,
        };
    }

    private static function envelope(string $route, ?string $payload, int $status = 1): string
    {
        $data = $payload === null ? '' : ',"data":' . $payload;
        $uapi = '{"status":' . $status . ',"warnings":["fixture warning"],"metadata":{"transformed":1}' . $data . '}';
        return match ($route) {
            'whm' => '{"metadata":{"result":' . $status . ',"reason":"fixture"}' . $data . '}',
            'bridge' => '{"metadata":{"result":1},"data":{"uapi":' . $uapi . '}}',
            default => '{"result":' . $uapi . '}',
        };
    }

    /** @return array{WhmClient|UapiClient, MockClient} */
    private static function client(string $route, string $method): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        $payload = $method === 'setUserEmailForwardDestination' ? null
            : json_encode(self::fixtureResult($method), JSON_THROW_ON_ERROR);
        $http->setDefaultResponse(new Response(200, [], self::envelope($route, $payload)));
        $client = new Client(
            ['host' => 'api.example.test', 'authType' => $route === 'direct' ? 'cpanel' : 'whm',
            'username' => $route === 'direct' ? 'fixture' : 'root', 'token' => 'fixture-token'],
            new HttpTransport($http, $factory, $factory),
        );
        return [$route === 'whm' ? $client->whm() : $client->uapi('fixture'), $http];
    }

    private static function assertRequest(
        MockClient $http,
        string $route,
        string $method,
        string $operation,
        string $fields,
    ): void {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        $get = $method === 'generateMobileconfig';
        self::assertSame($get ? 'GET' : 'POST', $request->getMethod());
        $url = match ($route) {
            'whm' => 'https://api.example.test:2087/json-api/' . $operation . '?api.version=1',
            'bridge' => 'https://api.example.test:2087/json-api/uapi_cpanel?api.version=1&cpanel.user=fixture'
                . '&cpanel.module=' . explode('/', $operation)[0] . '&cpanel.function=' . explode('/', $operation)[1],
            default => 'https://api.example.test:2083/execute/' . $operation,
        };
        self::assertSame($url . ($get ? '&' . $fields : ''), (string) $request->getUri());
        self::assertSame($get ? '' : $fields, (string) $request->getBody());
        self::assertSame($get ? '' : 'application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            $route === 'direct' ? 'cpanel fixture:fixture-token' : 'whm root:fixture-token',
            $request->getHeaderLine('Authorization'),
        );
    }

    private static function containsPrivateValue(mixed $value): bool
    {
        if ($value instanceof SensitiveParameterValue) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (self::containsPrivateValue($key) || self::containsPrivateValue($item)) {
                    return true;
                }
            }
        }
        return is_string($value) && str_contains($value, 'synthetic-private-');
    }

    /**
     * @param class-string $class
     * @param list<string> $names
     */
    private static function assertSignature(
        string $class,
        string $method,
        int $position,
        string $name,
        bool $optional,
        array $names,
    ): void {
        $reflection = new ReflectionMethod($class, $method);
        $parameters = $reflection->getParameters();
        $parameter = $parameters[$position];
        self::assertSame($optional ? 'array|string|null' : 'array|string', (string) $parameter->getType());
        self::assertSame($names, array_map(static fn ($p): string => $p->getName(), $parameters));
        self::assertSame($name, $parameter->getName());
        self::assertSame($optional, $parameter->isOptional());
        self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
        self::assertStringContainsString(
            '@param string|non-empty-list<string>' . ($optional ? '|null' : '') . ' $' . $name,
            (string) $reflection->getDocComment(),
        );
        if ($optional) {
            self::assertNull($parameter->getDefaultValue());
        }
    }
}
