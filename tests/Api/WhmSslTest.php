<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tests\Api;

use Closure;
use HostingPal\WhmApiClient\Api\Whm\Ssl;
use HostingPal\WhmApiClient\Client;
use HostingPal\WhmApiClient\Exception\ApiErrorException;
use HostingPal\WhmApiClient\Exception\PayloadMismatchException;
use HostingPal\WhmApiClient\Http\HttpTransport;
use Http\Mock\Client as MockClient;
use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SensitiveParameter;
use stdClass;

/**
 * Independent literal fixtures from the pinned WHM SSL Certificates documentation.
 *
 * @phpstan-type SslCall Closure(Ssl): mixed
 * @phpstan-type Operation array{string, string, SslCall, string, ?string, mixed}
 */
final class WhmSslTest extends TestCase
{
    /** @param SslCall $call */
    #[DataProvider('operations')]
    public function testRequestsAndPayloads(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $whm = $client->whm();
        $ssl = $whm->ssl();
        self::assertSame($ssl, $whm->ssl());
        self::assertSame([], $http->getRequests());
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $call($ssl));
        self::assertRequest($http, $function, $method, $parameters);
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $whm->responseMeta()?->whmResult());
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    /** @return iterable<string, Operation> */
    public static function operations(): iterable
    {
        yield 'add exclusions numbered' => ['add_autossl_user_excluded_domains', 'POST',
            static function (Ssl $s): mixed {
                $s->addAutoSslUserExcludedDomains(['one.test', 'two.test'], 'alice');
                return null;
            }, 'domain=one.test&domain-1=two.test&username=alice', null, null];
        yield 'delete vhost' => ['delete_ssl_vhost', 'POST',
            static fn (Ssl $s): mixed => $s->deleteSslVhost('host +&'), 'host=host%20%2B%26',
            '{"output":["removed"],"removed_vhost_data":[{"ip_port":["192.0.2.1:443"],'
                . '"is_ssl":1,"primary_on_ip_port":"1","servername":"one.test","vhost_entry":"config"}]}',
            ['output' => ['removed'], 'removed_vhost_data' => [['ip_port' => ['192.0.2.1:443'],
                'is_ssl' => 1, 'primary_on_ip_port' => '1', 'servername' => 'one.test', 'vhost_entry' => 'config']]]];
        yield 'disable AutoSSL' => ['disable_autossl', 'POST',
            static function (Ssl $s): mixed {
                $s->disableAutoSsl();
                return null;
            }, '', null, null];
        yield 'enqueue paired repeated values' => ['enqueue_deferred_ssl_installations', 'POST',
            static fn (Ssl $s): mixed => $s->enqueueDeferredSslInstallations(
                ['', 'bundle +&'],
                ['cert1', 'cert2'],
                ['key1', 'key2'],
                ['alice', 'bob'],
                ['one.test', 'two.test'],
            ), 'cab=&cab=bundle%20%2B%26&crt=cert1&crt=cert2&key=key1&key=key2'
                . '&username=alice&username=bob&vhost_name=one.test&vhost_name=two.test',
            '{"opaque":{"queued":[1,null]}}', ['opaque' => ['queued' => [1, null]]]];
        yield 'service components preserve literal dotted fields' => ['fetch_service_ssl_components', 'POST',
            static fn (Ssl $s): mixed => $s->fetchServiceSslComponents(), '',
            '{"services":[{"cabundle":"BUNDLE","certificate":"CERT","key":"KEY","service":"cpanel",'
                . '"certificate_info":{"domains":["one.test"],"ecdsa_curve_name":null,"ecdsa_public":null,'
                . '"is_self_signed":0,"issuer.commonName":"issuer","issuer.organizationName":"org",'
                . '"issuer_text":"issuer text","key_algorithm":"rsaEncryption","modulus":"abcd",'
                . '"modulus_length":2048,"not_after":1601467200,"not_before":1501467200}}]}',
            ['services' => [['cabundle' => 'BUNDLE', 'certificate' => 'CERT', 'certificate_info' => [
                'domains' => ['one.test'], 'ecdsa_curve_name' => null, 'ecdsa_public' => null, 'is_self_signed' => 0,
                'issuer.commonName' => 'issuer', 'issuer.organizationName' => 'org', 'issuer_text' => 'issuer text',
                'key_algorithm' => 'rsaEncryption', 'modulus' => 'abcd', 'modulus_length' => 2048,
                'not_after' => 1601467200, 'not_before' => 1501467200], 'key' => 'KEY', 'service' => 'cpanel']]]];
        yield 'FQDN certificates retain untyped items fields' => ['fetch_ssl_certificates_for_fqdns', 'POST',
            static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test,two.test'),
            'domains=one.test%2Ctwo.test',
            '{"payload":[{"cab":null,"crt":"CERT","key":"KEY","created":1,"domains":["one.test"],'
                . '"friendly_name":"fixture","id":"id","is_self_signed":1,"modulus_length":null,'
                . '"validation_type":null,"verify_error":"","issuer.commonName":"issuer"}]}',
            ['payload' => [['cab' => null, 'created' => 1, 'crt' => 'CERT', 'domains' => ['one.test'],
                'friendly_name' => 'fixture', 'id' => 'id', 'is_self_signed' => 1, 'issuer.commonName' => 'issuer',
                'key' => 'KEY', 'modulus_length' => null, 'validation_type' => null, 'verify_error' => '']]]];
        yield 'vhosts retain nested literal subject field' => ['fetch_ssl_vhosts', 'POST',
            static fn (Ssl $s): mixed => $s->fetchSslVhosts(), '',
            '{"vhosts":[{"crt":{"created":1,"domains":["one.test"],"subject.commonName":{"commonName":"one.test"},'
                . '"is_self_signed":0,"modulus":null,"modulus_length":null,"public_exponent":"010001",'
                . '"validation_type":null},"docroot":"/home/alice/public_html","domains":["one.test"],'
                . '"ip":"192.0.2.1","iptype":"shared","ipv6":"","is_primary_on_ip":1,"mail_sni_status":0,'
                . '"needs_sni":1,"servername":"one.test","type":"main","user":"alice"}]}',
            ['vhosts' => [['crt' => ['created' => 1, 'domains' => ['one.test'], 'is_self_signed' => 0,
                'modulus' => null, 'modulus_length' => null, 'public_exponent' => '010001',
                'subject.commonName' => ['commonName' => 'one.test'], 'validation_type' => null],
                'docroot' => '/home/alice/public_html', 'domains' => ['one.test'], 'ip' => '192.0.2.1',
                'iptype' => 'shared', 'ipv6' => '', 'is_primary_on_ip' => 1, 'mail_sni_status' => 0,
                'needs_sni' => 1, 'servername' => 'one.test', 'type' => 'main', 'user' => 'alice']]]];
        yield 'vhost components' => ['fetch_vhost_ssl_components', 'POST',
            static fn (Ssl $s): mixed => $s->fetchVhostSslComponents(), '',
            '{"components":[{"cabundle":null,"cabundle_id":null,"certificate":"CERT","certificate_id":"cert-id",'
                . '"key":"KEY","key_id":"key-id","servername":"one.test"}]}',
            ['components' => [['cabundle' => null, 'cabundle_id' => null, 'certificate' => 'CERT',
                'certificate_id' => 'cert-id', 'key' => 'KEY', 'key_id' => 'key-id', 'servername' => 'one.test']]]];
        yield 'certificate info' => ['fetchcrtinfo', 'POST',
            static fn (Ssl $s): mixed => $s->fetchCertificateInfo('id +&', 'alice'), 'id=id%20%2B%26&user=alice',
            '{"cabundle":"BUNDLE","certificate":"CERT","is_self_signed":1,"key":"KEY","subject.commonName_ip":""}',
            ['cabundle' => 'BUNDLE', 'certificate' => 'CERT', 'is_self_signed' => 1,
                'key' => 'KEY', 'subject.commonName_ip' => '']];
        yield 'candidate SSL info inputs encoded once' => ['fetchsslinfo', 'POST',
            static fn (Ssl $s): mixed => $s->fetchSslInfo(crtdata: "CERT +&\n", domain: 'one.test'),
            'crtdata=CERT%20%2B%26%0A&domain=one.test',
            '{"cab":"BUNDLE","crt":"CERT","crt_origin":"store","domain":"one.test","ip":"192.0.2.1",'
                . '"key":"KEY","key_origin":"store","searched_users":["alice"],"user":"alice"}',
            ['cab' => 'BUNDLE', 'crt' => 'CERT', 'crt_origin' => 'store', 'domain' => 'one.test', 'ip' => '192.0.2.1',
                'key' => 'KEY', 'key_origin' => 'store', 'searched_users' => ['alice'], 'user' => 'alice']];
        yield 'generate all options false and zero' => ['generatessl', 'POST',
            static fn (Ssl $s): mixed => $s->generateSsl(
                domains: 'one.test,two.test',
                countryName: 'RS',
                emailAddress: 'a+ssl@example.test',
                keysize: 0,
                localityName: 'City',
                organizationalUnitName: '',
                organizationName: 'Org',
                passphrase: 'pass +&',
                skipCertificate: false,
                stateOrProvinceName: 'State',
            ), 'domains=one.test%2Ctwo.test&countryName=RS&emailAddress=a%2Bssl%40example.test&keysize=0'
                . '&localityName=City&organizationName=Org&organizationalUnitName=&pass=pass%20%2B%26'
                . '&skip_certificate=0&stateOrProvinceName=State',
            '{"cert":"CERT","cert_id":"c","certfile":"/c","csr":"CSR","csr_id":"s","csrfile":"/s",'
                . '"key":"KEY","key_id":"k","keyfile":"/k","sender":"root","sender_host":"host.test"}',
            ['cert' => 'CERT', 'cert_id' => 'c', 'certfile' => '/c', 'csr' => 'CSR', 'csr_id' => 's', 'csrfile' => '/s',
                'key' => 'KEY', 'key_id' => 'k', 'keyfile' => '/k', 'sender' => 'root', 'sender_host' => 'host.test']];
        yield 'check schedule' => ['get_autossl_check_schedule', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslCheckSchedule(), '',
            '{"cron":["0 3 * * *"],"next_time":"2026-11-01T03:00:00Z"}',
            ['cron' => ['0 3 * * *'], 'next_time' => '2026-11-01T03:00:00Z']];
        yield 'log content is payload' => ['get_autossl_log', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslLog('2026-10-31T00:00:00Z'),
            'start_time=2026-10-31T00%3A00%3A00Z',
            '{"payload":[{"contents":"text\n","indent":0,"partial":0,"pid":123,"timestamp":"now","type":"warn"}]}',
            ['payload' => [['contents' => "text\n", 'indent' => 0, 'partial' => 0, 'pid' => 123,
                'timestamp' => 'now', 'type' => 'warn']]]];
        yield 'log catalog' => ['get_autossl_logs_catalog', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslLogsCatalog(), '',
            '{"payload":[{"in_progress":0,"provider":"cPanel","start_time":"now","username":"alice"}]}',
            ['payload' => [['in_progress' => 0, 'provider' => 'cPanel', 'start_time' => 'now',
                'username' => 'alice']]]];
        yield 'metadata values and omitted keys' => ['get_autossl_metadata', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslMetadata(), '',
            '{"payload":{"clobber_externally_signed":0,"notify_autossl_expiry":1,'
                . '"notify_autossl_renewal_coverage_user":0}}',
            ['payload' => ['clobber_externally_signed' => 0, 'notify_autossl_expiry' => 1,
                'notify_autossl_renewal_coverage_user' => 0]]];
        yield 'domain problems missing root type inferred from properties' => ['get_autossl_problems_for_domain', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslProblemsForDomain('one.test'), 'domain=one.test',
            '{"problems_by_domain":[{"domain":"one.test","log":"text","problem":"dcv","time":"now"}]}',
            ['problems_by_domain' => [['domain' => 'one.test', 'log' => 'text', 'problem' => 'dcv', 'time' => 'now']]]];
        yield 'user problems' => ['get_autossl_problems_for_user', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslProblemsForUser('alice'), 'username=alice',
            '{"problems_by_domain":[{}, {"problem":"dcv"}]}', ['problems_by_domain' => [[], ['problem' => 'dcv']]]];
        yield 'providers named fields and opaque dynamic extras' => ['get_autossl_providers', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslProviders(), '',
            '{"payload":[{"display_name":"Provider","enabled":1,"module_name":"provider","specs":{'
                . '"AVERAGE_DELIVERY_TIME":5,"DCV_METHODS":["dns","http"],"DELIVERY_METHOD":"api",'
                . '"HTTP_DCV_MAX_REDIRECTS":0,"MAX_DOMAINS_PER_CERTIFICATE":100,'
                . '"RATE_LIMIT_CERTIFICATES_PER_REGISTERED_DOMAIN_PER_WEEK":50,"SUPPORTS_ANCESTOR_DCV":0,'
                . '"SUPPORTS_WILDCARD":1,"VALIDITY_PERIOD":7776000},"x_account_id":"account-id",'
                . '"x_terms_of_service":"https://terms.test",'
                . '"x_terms_of_service_accepted":"https://terms.test/accepted.pdf",'
                . '"x_fixture":{"opaque":[true,null,7]}}]}',
            ['payload' => [['display_name' => 'Provider', 'enabled' => 1, 'module_name' => 'provider',
                'specs' => ['AVERAGE_DELIVERY_TIME' => 5, 'DCV_METHODS' => ['dns', 'http'], 'DELIVERY_METHOD' => 'api',
                    'HTTP_DCV_MAX_REDIRECTS' => 0, 'MAX_DOMAINS_PER_CERTIFICATE' => 100,
                    'RATE_LIMIT_CERTIFICATES_PER_REGISTERED_DOMAIN_PER_WEEK' => 50, 'SUPPORTS_ANCESTOR_DCV' => 0,
                    'SUPPORTS_WILDCARD' => 1, 'VALIDITY_PERIOD' => 7776000], 'x_account_id' => 'account-id',
                'x_terms_of_service' => 'https://terms.test',
                'x_terms_of_service_accepted' => 'https://terms.test/accepted.pdf',
                'x_fixture' => ['opaque' => [true, null, 7]]]]]];
        yield 'excluded domain list' => ['get_autossl_user_excluded_domains', 'GET',
            static fn (Ssl $s): mixed => $s->getAutoSslUserExcludedDomains('alice'), 'username=alice',
            '{"payload":[{"excluded_domain":"one.test"},{}]}', ['payload' => [['excluded_domain' => 'one.test'], []]]];
        yield 'best service domain flags' => ['get_best_ssldomain_for_service', 'GET',
            static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'), 'service=cpanel',
            '{"cert_match_method":"none","cert_valid_not_after":0,"is_currently_valid":0,"is_self_signed":1,'
                . '"is_wild_card":0,"ssldomain":"one.test","ssldomain_matches_cert":0}',
            ['cert_match_method' => 'none', 'cert_valid_not_after' => 0, 'is_currently_valid' => 0,
                'is_self_signed' => 1,
                'is_wild_card' => 0, 'ssldomain' => 'one.test', 'ssldomain_matches_cert' => 0]];
        yield 'install service raw PEM encoded once' => ['install_service_ssl_certificate', 'POST',
            static fn (Ssl $s): mixed => $s->installServiceSslCertificate("CERT +&\n", 'KEY', 'cpanel', 'BUNDLE'),
            'crt=CERT%20%2B%26%0A&key=KEY&service=cpanel&cabundle=BUNDLE',
            '{"certificate":"CERT","certificate_info":{"domains":["one.test"],"issuer.commonName":null,'
                . '"modulus_length":null,"validation_type":null},"service":"cpanel",'
                . '"service_description":"Control panel"}',
            ['certificate' => 'CERT', 'certificate_info' => ['domains' => ['one.test'], 'issuer.commonName' => null,
                'modulus_length' => null, 'validation_type' => null], 'service' => 'cpanel',
                'service_description' => 'Control panel']];
        yield 'install website all inputs' => ['installssl', 'POST',
            static fn (Ssl $s): mixed => $s->installSsl('CERT', 'one.test', 'KEY', '', '192.0.2.1'),
            'crt=CERT&domain=one.test&key=KEY&cab=&ip=192.0.2.1',
            '{"action":"install","aliases":["mail.one.test"],"domain":"one.test","extra_certificate_domains":[], '
                . '"html":"html","ip":"192.0.2.1","message":"installed","servername":"host.test","status":1,'
                . '"statusmsg":"OK","user":"alice","warning_domains":[],"working_domains":["one.test"]}',
            ['action' => 'install', 'aliases' => ['mail.one.test'], 'domain' => 'one.test',
                'extra_certificate_domains' => [],
                'html' => 'html', 'ip' => '192.0.2.1', 'message' => 'installed',
                'servername' => 'host.test', 'status' => 1,
                'statusmsg' => 'OK', 'user' => 'alice', 'warning_domains' => [], 'working_domains' => ['one.test']]];
        yield 'certificate inventory false filter' => ['listcrts', 'GET',
            static fn (Ssl $s): mixed => $s->listCertificates(registered: false, user: 'alice'),
            'registered=0&user=alice',
            '{"crt":[{"domain":"one.test","domain_owners":["alice"],"domains":["one.test"],"ecdsa_curve_name":null,'
                . '"friendly_name":"fixture","id":"id","is_self_signed":0,"issuer.organizationName":"org",'
                . '"key_algorithm":"rsaEncryption","modulus_length":2048,"not_after":1,"registered":0,'
                . '"signature_algorithm":"sha256WithRSAEncryption","validation_type":null}]}',
            ['crt' => [['domain' => 'one.test', 'domain_owners' => ['alice'], 'domains' => ['one.test'],
                'ecdsa_curve_name' => null, 'friendly_name' => 'fixture', 'id' => 'id', 'is_self_signed' => 0,
                'issuer.organizationName' => 'org', 'key_algorithm' => 'rsaEncryption', 'modulus_length' => 2048,
                'not_after' => 1, 'registered' => 0, 'signature_algorithm' => 'sha256WithRSAEncryption',
                'validation_type' => null]]]];
        yield 'rebuild installed database documented no-op opaque result' => ['rebuildinstalledssldb', 'POST',
            static fn (Ssl $s): mixed => $s->rebuildInstalledSslDatabase(), '', '{"opaque":1}', ['opaque' => 1]];
        yield 'rebuild user database' => ['rebuilduserssldb', 'POST',
            static fn (Ssl $s): mixed => $s->rebuildUserSslDatabase(), '',
            '{"records":["alice","bob"]}', ['records' => ['alice', 'bob']]];
        yield 'remove exclusions numbered' => ['remove_autossl_user_excluded_domains', 'POST',
            static function (Ssl $s): mixed {
                $s->removeAutoSslUserExcludedDomains(['one.test', 'two.test'], 'alice');
                return null;
            }, 'domain=one.test&domain-1=two.test&username=alice', null, null];
        yield 'reset service certificate' => ['reset_service_ssl_certificate', 'POST',
            static fn (Ssl $s): mixed => $s->resetServiceSslCertificate('cpanel'), 'service=cpanel',
            '{"certificate":"CERT","certificate_info":{"ecdsa_curve_name":null,"ecdsa_public":null,'
                . '"modulus":null,"modulus_length":null,"is_self_signed":1},"service":"cpanel"}',
            ['certificate' => 'CERT', 'certificate_info' => ['ecdsa_curve_name' => null, 'ecdsa_public' => null,
                'is_self_signed' => 1, 'modulus' => null, 'modulus_length' => null], 'service' => 'cpanel']];
        yield 'metadata JSON supplied serialized encoded once' => ['set_autossl_metadata', 'POST',
            static function (Ssl $s): mixed {
                $s->setAutoSslMetadata('{"notify_autossl_expiry":0,"literal":"+&%"}');
                return null;
            }, 'metadata_json=%7B%22notify_autossl_expiry%22%3A0%2C%22literal%22%3A%22%2B%26%25%22%7D', null, null];
        yield 'single metadata key is not private key material' => ['set_autossl_metadata_key', 'POST',
            static function (Ssl $s): mixed {
                $s->setAutoSslMetadataKey('notify_autossl_expiry', false);
                return null;
            }, 'key=notify_autossl_expiry&value=0', null, null];
        yield 'replace exclusions numbered' => ['set_autossl_user_excluded_domains', 'POST',
            static function (Ssl $s): mixed {
                $s->setAutoSslUserExcludedDomains('alice', ['one.test', 'two.test']);
                return null;
            }, 'username=alice&domain=one.test&domain-1=two.test', null, null];
        yield 'enqueue all users check' => ['start_autossl_check_for_all_users', 'POST',
            static fn (Ssl $s): mixed => $s->startAutoSslCheckForAllUsers(), '', '{"pid":123}', ['pid' => 123]];
        yield 'enqueue one user check missing schema type still typed' => ['start_autossl_check_for_one_user', 'POST',
            static fn (Ssl $s): mixed => $s->startAutoSslCheckForOneUser('alice'), 'username=alice',
            '{"pid":123}', ['pid' => 123]];
    }

    /** @param SslCall $call */
    #[DataProvider('operations')]
    public function testApiFailureIsNeverRetried(
        string $function,
        string $method,
        Closure $call,
        string $parameters,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], '{"metadata":{"result":0,"reason":"Fixture failure"}}'));
        try {
            $call($client->whm()->ssl());
            self::fail('An API failure must throw.');
        } catch (ApiErrorException $exception) {
            self::assertSame(['Fixture failure'], $exception->errors());
            self::assertCount(1, $http->getRequests());
            self::assertSame(0, $client->whm()->responseMeta()?->whmResult());
            self::assertRequest($http, $function, $method, $parameters);
        }
    }

    /** @return iterable<string, array{SslCall, string, string}> */
    public static function omittedOptions(): iterable
    {
        yield 'candidate selector alternatives omitted' => [static fn (Ssl $s): mixed => $s->fetchSslInfo(),
            'fetchsslinfo', ''];
        yield 'certificate filters omitted' => [static fn (Ssl $s): mixed => $s->listCertificates(), 'listcrts', ''];
        yield 'generation defaults omitted' => [static fn (Ssl $s): mixed => $s->generateSsl('one.test'),
            'generatessl', 'domains=one.test'];
        yield 'installation defaults omitted' => [
            static fn (Ssl $s): mixed => $s->installSsl('CERT', 'one.test', 'KEY'),
            'installssl', 'crt=CERT&domain=one.test&key=KEY'];
        yield 'service bundle omitted' => [
            static fn (Ssl $s): mixed => $s->installServiceSslCertificate('CERT', 'KEY', 'cpanel'),
            'install_service_ssl_certificate', 'crt=CERT&key=KEY&service=cpanel'];
    }

    /** @param SslCall $call */
    #[DataProvider('omittedOptions')]
    public function testOmittedOptionsStayOmitted(Closure $call, string $function, string $parameters): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope('{}')));
        self::assertSame([], $call($client->whm()->ssl()));
        self::assertRequest($http, $function, $function === 'listcrts' ? 'GET' : 'POST', $parameters);
    }

    public function testOmittingReplacementDomainsEnablesEveryAccountDomain(): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(null)));
        $client->whm()->ssl()->setAutoSslUserExcludedDomains('alice');
        self::assertRequest($http, 'set_autossl_user_excluded_domains', 'POST', 'username=alice');
    }

    /** @return iterable<string, array{SslCall, ?string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'list root' => [static fn (Ssl $s): mixed => $s->listCertificates(), '[true]'];
        yield 'list explicit null' => [static fn (Ssl $s): mixed => $s->listCertificates(), 'null'];
        yield 'list absent' => [static fn (Ssl $s): mixed => $s->listCertificates(), null];
        yield 'list rows not object' => [static fn (Ssl $s): mixed => $s->listCertificates(), '{"crt":[1]}'];
        yield 'flag quoted remains strict' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"is_self_signed":"1"}]}'];
        yield 'timestamp leading zero remains strict' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"not_after":"01"}]}'];
        yield 'integer fractional remains strict' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"modulus_length":2048.5}]}'];
        yield 'issuer boolean remains strict' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"issuer.organizationName":false}]}'];
        yield 'FQDN row properties preserve typed object' => [
            static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
            '{"payload":[true]}'];
        yield 'FQDN leaf rejects noncanonical integer' => [
            static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
            '{"payload":[{"created":"01"}]}'];
        yield 'providers object rows' => [static fn (Ssl $s): mixed => $s->getAutoSslProviders(), '{"payload":[true]}'];
        yield 'provider known flag strict' => [static fn (Ssl $s): mixed => $s->getAutoSslProviders(),
            '{"payload":[{"enabled":"1"}]}'];
        yield 'provider known specs leaf strict' => [static fn (Ssl $s): mixed => $s->getAutoSslProviders(),
            '{"payload":[{"specs":{"VALIDITY_PERIOD":"5"}}]}'];
        yield 'opaque rebuild scalar' => [static fn (Ssl $s): mixed => $s->rebuildInstalledSslDatabase(), 'true'];
        yield 'queued pid strict' => [
            static fn (Ssl $s): mixed => $s->startAutoSslCheckForOneUser('alice'), '{"pid":"123"}'];
        yield 'domain problems root strict' => [
            static fn (Ssl $s): mixed => $s->getAutoSslProblemsForDomain('one.test'), 'true'];
        yield 'service install modulus noncanonical remains strict' => [
            static fn (Ssl $s): mixed => $s->installServiceSslCertificate('c', 'k', 'cpanel'),
            '{"certificate_info":{"modulus_length":"02048"}}'];
    }

    /** @param SslCall $call */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadPreservesConfirmedOutcomeWithoutRetry(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $call($client->whm()->ssl());
            self::fail('Invalid payload must fail validation.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
            self::assertCount(1, $http->getRequests());
        }
    }

    /** @return iterable<string, array{SslCall, ?string}> */
    public static function documentedObjectPayloads(): iterable
    {
        foreach (self::operations() as $name => $operation) {
            if ($operation[4] === null) {
                continue;
            }
            if (!in_array($operation[0], ['enqueue_deferred_ssl_installations', 'rebuildinstalledssldb'], true)) {
                yield $name . ' absent' => [$operation[2], null];
                yield $name . ' explicit null' => [$operation[2], 'null'];
            }
            yield $name . ' scalar' => [$operation[2], 'true'];
            yield $name . ' nonempty list' => [$operation[2], '[true]'];
        }
    }

    /** @param SslCall $call */
    #[DataProvider('documentedObjectPayloads')]
    public function testEveryDocumentedObjectRetainsItsRootContract(Closure $call, ?string $payload): void
    {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        try {
            $call($client->whm()->ssl());
            self::fail('Documented object payloads cannot become absent, null, scalar or nonempty list.');
        } catch (PayloadMismatchException $exception) {
            self::assertTrue($exception->outcomeConfirmed);
            self::assertCount(1, $http->getRequests());
            self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        }
    }

    /** @return iterable<string, array{SslCall, string, mixed}> */
    public static function observedCertificatePayloads(): iterable
    {
        yield 'best-domain expiry null' => [
            static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'),
            '{"cert_valid_not_after":null}', ['cert_valid_not_after' => null]];
        yield 'best-domain expiry integer retained' => [
            static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'),
            '{"cert_valid_not_after":1601467200}', ['cert_valid_not_after' => 1601467200]];
        yield 'best-domain expiry omitted' => [
            static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'), '{}', []];
        yield 'nullable owner list items' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{"domain_owners":[null,"alice",null]}]}',
            ['crt' => [['domain_owners' => [null, 'alice', null]]]]];
        yield 'owner list empty' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{"domain_owners":[]}]}', ['crt' => [['domain_owners' => []]]]];
        yield 'nullable literal issuer organization' => [
            static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{"issuer.organizationName":null}]}', ['crt' => [['issuer.organizationName' => null]]]];
        yield 'nullable modulus remains nullable' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{"modulus_length":null}]}', ['crt' => [['modulus_length' => null]]]];
        yield 'all reviewed variants together' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{"domain_owners":[null,"alice"],"issuer.organizationName":null,'
                . '"modulus_length":"2048","not_after":"1601467200","is_self_signed":0,"registered":1},{}]}',
            ['crt' => [['domain_owners' => [null, 'alice'], 'is_self_signed' => 0, 'issuer.organizationName' => null,
                'modulus_length' => 2048, 'not_after' => 1601467200, 'registered' => 1], []]]];
        yield 'reviewed fields omitted' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[{}]}', ['crt' => [[]]]];
        yield 'empty certificate list' => [static fn (Ssl $s): mixed => $s->listCertificates(user: 'alice'),
            '{"crt":[]}', ['crt' => []]];
    }

    /** @param SslCall $call */
    #[DataProvider('observedCertificatePayloads')]
    public function testObservedCertificateVariantsPreserveValuesAndOmission(
        Closure $call,
        string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $call($client->whm()->ssl()));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
    }

    /** @return iterable<string, array{string, int|string, int}> */
    public static function canonicalCertificateIntegers(): iterable
    {
        foreach (['modulus_length', 'not_after'] as $field) {
            yield $field . ' native zero' => [$field, 0, 0];
            yield $field . ' native value' => [$field, 2048, 2048];
            yield $field . ' string zero' => [$field, '0', 0];
            yield $field . ' string value' => [$field, '2048', 2048];
            yield $field . ' string negative' => [$field, '-1', -1];
            yield $field . ' string maximum' => [$field, '9223372036854775807', PHP_INT_MAX];
            yield $field . ' string minimum' => [$field, '-9223372036854775808', PHP_INT_MIN];
        }
    }

    #[DataProvider('canonicalCertificateIntegers')]
    public function testOnlyReviewedCertificateFieldsNormalizeCanonicalIntegers(
        string $field,
        int|string $wire,
        int $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope(json_encode(
            ['crt' => [[$field => $wire]]],
            JSON_THROW_ON_ERROR,
        ))));
        self::assertSame(['crt' => [[$field => $expected]]], $client->whm()->ssl()->listCertificates(user: 'alice'));
        self::assertCount(1, $http->getRequests());
    }

    /** @return iterable<string, array{SslCall, ?string}> */
    public static function rejectedCertificateCompatibilityVariants(): iterable
    {
        foreach (['modulus_length', 'not_after'] as $field) {
            foreach (
                [
                '', '00', '01', '-0', '-01', '+1', '1.0', '1.00', '1.5', '1e3', ' 1', '1 ',
                '9223372036854775808', '-9223372036854775809', true, false, 1.0, 1.5, [], new stdClass(),
                ] as $index => $value
            ) {
                yield $field . ' invalid ' . $index => [static fn (Ssl $s): mixed => $s->listCertificates(),
                    json_encode(['crt' => [[$field => $value]]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
            }
        }
        foreach ([0, 1, true, false, [], new stdClass()] as $index => $value) {
            yield 'owner item remains string or null ' . $index => [static fn (Ssl $s): mixed => $s->listCertificates(),
                json_encode(['crt' => [['domain_owners' => [$value]]]], JSON_THROW_ON_ERROR)];
            yield 'issuer remains string or null ' . $index => [static fn (Ssl $s): mixed => $s->listCertificates(),
                json_encode(['crt' => [['issuer.organizationName' => $value]]], JSON_THROW_ON_ERROR)];
        }
        yield 'owner list itself not nullable' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"domain_owners":null}]}'];
        yield 'owner list itself not scalar' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"domain_owners":"alice"}]}'];
        yield 'expiration itself not nullable' => [static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"not_after":null}]}'];
        foreach (['00', '1601467200.0', true, false, 1.0, [], new stdClass()] as $index => $value) {
            yield 'best-domain expiry remains integer or null ' . $index => [
                static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'),
                json_encode(['cert_valid_not_after' => $value], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }
        yield 'unrelated certificate self-signed flag stays strict' => [
            static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"is_self_signed":"0"}]}'];
        yield 'unrelated certificate registered flag stays strict' => [
            static fn (Ssl $s): mixed => $s->listCertificates(),
            '{"crt":[{"registered":"1"}]}'];
        yield 'unrelated certificate domains remain nonnullable strings' => [
            static fn (Ssl $s): mixed => $s->listCertificates(), '{"crt":[{"domains":[null]}]}'];
        yield 'unrelated best-domain flag stays strict' => [
            static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'), '{"is_currently_valid":"0"}'];
        yield 'sibling vhost expiration stays strict' => [static fn (Ssl $s): mixed => $s->fetchSslVhosts(),
            '{"vhosts":[{"crt":{"not_after":"1601467200"}}]}'];
    }

    /** @param SslCall $call */
    #[DataProvider('rejectedCertificateCompatibilityVariants')]
    public function testCertificateCompatibilityCorrectionsStayNarrow(Closure $call, ?string $payload): void
    {
        $this->testInvalidPayloadPreservesConfirmedOutcomeWithoutRetry($call, $payload);
    }

    /** @return iterable<string, array{SslCall, ?string, mixed}> */
    public static function reviewedSuccessPayloads(): iterable
    {
        foreach (['', '0', '1', 'https://terms.test/accepted.pdf', 'opaque acceptance text'] as $index => $value) {
            yield 'terms acceptance string ' . $index => [
                static fn (Ssl $s): mixed => $s->getAutoSslProviders(),
                json_encode(['payload' => [['x_terms_of_service_accepted' => $value]]], JSON_THROW_ON_ERROR),
                ['payload' => [['x_terms_of_service_accepted' => $value]]]];
        }
        foreach (self::canonicalIntegerValues() as $label => [$wire, $expected]) {
            yield 'best-domain expiry ' . $label => [
                static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'),
                json_encode(['cert_valid_not_after' => $wire], JSON_THROW_ON_ERROR),
                ['cert_valid_not_after' => $expected]];
        }
        foreach (self::reviewedCertificateFamilies() as $family => [$call, $wrap, $fields]) {
            foreach ($fields as $field) {
                foreach (self::canonicalIntegerValues() as $label => [$wire, $expected]) {
                    yield $family . ' ' . $field . ' ' . $label => [$call,
                        json_encode($wrap([$field => $wire]), JSON_THROW_ON_ERROR), $wrap([$field => $expected])];
                }
            }
            foreach ([[0, 0], [1, 1], ['0', 0], ['1', 1]] as $index => [$wire, $expected]) {
                yield $family . ' flag ' . $index => [$call,
                    json_encode($wrap(['is_self_signed' => $wire]), JSON_THROW_ON_ERROR),
                    $wrap(['is_self_signed' => $expected])];
            }
            foreach ([null, '', 'Organization'] as $index => $value) {
                yield $family . ' organization ' . $index => [$call,
                    json_encode($wrap(['issuer.organizationName' => $value]), JSON_THROW_ON_ERROR),
                    $wrap(['issuer.organizationName' => $value])];
            }
            yield $family . ' nullable modulus retained' => [$call,
                json_encode($wrap(['modulus_length' => null]), JSON_THROW_ON_ERROR), $wrap(['modulus_length' => null])];
            yield $family . ' record omissions retained' => [$call,
                json_encode($wrap(new stdClass()), JSON_THROW_ON_ERROR), $wrap([])];
        }
        foreach ([null, '', 'unrecognized issuer'] as $index => $value) {
            yield 'FQDN verification text ' . $index => [
                static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
                json_encode(['payload' => [['verify_error' => $value]]], JSON_THROW_ON_ERROR),
                ['payload' => [['verify_error' => $value]]]];
        }
        yield 'FQDN complete certificate keeps every known field' => [
            static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
            '{"payload":[{"cab":null,"created":"1700000000","crt":"CERT","domains":["one.test"],'
                . '"ecdsa_curve_name":null,"ecdsa_public":null,"friendly_name":"fixture","id":"certificate-id",'
                . '"is_self_signed":"0","issuer.commonName":"issuer","issuer.organizationName":null,"key":"KEY",'
                . '"key_algorithm":"rsaEncryption","modulus":"abcd","modulus_length":"2048",'
                . '"not_after":"1800000000","not_before":"1700000000","signature_algorithm":"sha256WithRSAEncryption",'
                . '"subject.commonName":"one.test","users":["alice"],"validation_type":null,"verify_error":null}]}',
            ['payload' => [['cab' => null, 'created' => 1700000000, 'crt' => 'CERT', 'domains' => ['one.test'],
                'ecdsa_curve_name' => null, 'ecdsa_public' => null, 'friendly_name' => 'fixture',
                'id' => 'certificate-id',
                'is_self_signed' => 0, 'issuer.commonName' => 'issuer', 'issuer.organizationName' => null,
                'key' => 'KEY',
                'key_algorithm' => 'rsaEncryption', 'modulus' => 'abcd', 'modulus_length' => 2048,
                'not_after' => 1800000000, 'not_before' => 1700000000,
                'signature_algorithm' => 'sha256WithRSAEncryption',
                'subject.commonName' => 'one.test', 'users' => ['alice'], 'validation_type' => null,
                'verify_error' => null]]]];
        foreach (['', 0, 1] as $index => $value) {
            yield 'vhost self-signed unset or native flag ' . $index => [
                static fn (Ssl $s): mixed => $s->fetchSslVhosts(),
                json_encode(['vhosts' => [['crt' => ['is_self_signed' => $value]]]], JSON_THROW_ON_ERROR),
                ['vhosts' => [['crt' => ['is_self_signed' => $value]]]]];
        }
        foreach ([[0, 0], [1, 1], ['0', 0], ['1', 1]] as $index => [$wire, $expected]) {
            yield 'deleted vhost flag ' . $index => [static fn (Ssl $s): mixed => $s->deleteSslVhost('one.test'),
                json_encode(['output' => ['removed'], 'removed_vhost_data' => [['is_ssl' => $wire,
                    'primary_on_ip_port' => '1', 'servername' => 'one.test']]], JSON_THROW_ON_ERROR),
                ['output' => ['removed'], 'removed_vhost_data' => [['is_ssl' => $expected,
                    'primary_on_ip_port' => '1', 'servername' => 'one.test']]]];
        }
        foreach (
            [
            'enqueue' => static fn (Ssl $s): mixed => $s->enqueueDeferredSslInstallations(
                [''],
                ['CERT'],
                ['KEY'],
                ['alice'],
                ['one.test'],
            ),
            'rebuild' => static fn (Ssl $s): mixed => $s->rebuildInstalledSslDatabase(),
            ] as $name => $call
        ) {
            yield $name . ' absent payload' => [$call, null, null];
            yield $name . ' null payload' => [$call, 'null', null];
            yield $name . ' empty object' => [$call, '{}', []];
            yield $name . ' opaque object preserved' => [$call, '{"opaque":{"values":[null,1,"text"]}}',
                ['opaque' => ['values' => [null, 1, 'text']]]];
        }
        yield 'installation payload status zero and warning domains are returned' => [
            static fn (Ssl $s): mixed => $s->installSsl('CERT', 'one.test', 'KEY'),
            '{"action":"install","aliases":["mail.one.test"],"domain":"one.test",'
                . '"extra_certificate_domains":["other.test"],"html":"details","ip":"192.0.2.1",'
                . '"message":"check domains","servername":"one.test","status":0,"statusmsg":"partial",'
                . '"user":"alice","warning_domains":["mail.one.test"],"working_domains":["one.test"]}',
            ['action' => 'install', 'aliases' => ['mail.one.test'], 'domain' => 'one.test',
                'extra_certificate_domains' => ['other.test'], 'html' => 'details', 'ip' => '192.0.2.1',
                'message' => 'check domains', 'servername' => 'one.test', 'status' => 0, 'statusmsg' => 'partial',
                'user' => 'alice', 'warning_domains' => ['mail.one.test'], 'working_domains' => ['one.test']]];
    }

    /** @param SslCall $call */
    #[DataProvider('reviewedSuccessPayloads')]
    public function testReviewedSuccessPayloadsDoNotBecomeFalseFailures(
        Closure $call,
        ?string $payload,
        mixed $expected,
    ): void {
        [$client, $http] = self::client();
        $http->addResponse(new Response(200, [], self::envelope($payload)));
        self::assertSame($expected, $call($client->whm()->ssl()));
        self::assertCount(1, $http->getRequests());
        self::assertSame(1, $client->whm()->responseMeta()?->whmResult());
        self::assertNull($client->uapi('alice')->responseMeta());
    }

    /** @return iterable<string, array{SslCall, ?string}> */
    public static function rejectedReviewedPayloads(): iterable
    {
        foreach (self::noncanonicalIntegers() as $index => $value) {
            yield 'best-domain noncanonical integer ' . $index => [
                static fn (Ssl $s): mixed => $s->getBestSslDomainForService('cpanel'),
                json_encode(['cert_valid_not_after' => $value], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
        }
        foreach (self::reviewedCertificateFamilies() as $family => [$call, $wrap, $fields]) {
            foreach ($fields as $field) {
                foreach (self::noncanonicalIntegers() as $index => $value) {
                    yield $family . ' ' . $field . ' invalid ' . $index => [$call,
                        json_encode($wrap([$field => $value]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)];
                }
                if ($field !== 'modulus_length') {
                    yield $family . ' ' . $field . ' remains nonnullable' => [$call,
                        json_encode($wrap([$field => null]), JSON_THROW_ON_ERROR)];
                }
            }
            foreach (
                [null, '', '2', '01', '-0', '+1', 'true', 2, true, false, 1.0, [], new stdClass()] as $i => $value
            ) {
                yield $family . ' invalid flag ' . $i => [$call,
                    json_encode(
                        $wrap(['is_self_signed' => $value]),
                        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                    )];
            }
            foreach ([0, true, [], new stdClass()] as $index => $value) {
                yield $family . ' organization remains text or null ' . $index => [$call,
                    json_encode($wrap(['issuer.organizationName' => $value]), JSON_THROW_ON_ERROR)];
            }
            foreach (
                ['domains' => [null], 'ecdsa_curve_name' => 1,
                    'key_algorithm' => null, 'modulus' => 1] as $field => $value
            ) {
                yield $family . ' strict sibling ' . $field => [$call,
                    json_encode($wrap([$field => $value]), JSON_THROW_ON_ERROR)];
            }
        }
        foreach ([0, 1, true, false, null, [], new stdClass()] as $index => $value) {
            yield 'terms acceptance must be string ' . $index => [
                static fn (Ssl $s): mixed => $s->getAutoSslProviders(),
                json_encode(['payload' => [['x_terms_of_service_accepted' => $value]]], JSON_THROW_ON_ERROR)];
        }
        foreach ([0, 1, true, [], new stdClass()] as $index => $value) {
            yield 'FQDN verification must be text or null ' . $index => [
                static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
                json_encode(['payload' => [['verify_error' => $value]]], JSON_THROW_ON_ERROR)];
        }
        foreach (['0', '1', 'false', ' ', null, true, false, 2, 1.0, [], new stdClass()] as $index => $value) {
            yield 'vhost unset flag is only exact empty string ' . $index => [
                static fn (Ssl $s): mixed => $s->fetchSslVhosts(),
                json_encode(
                    ['vhosts' => [['crt' => ['is_self_signed' => $value]]]],
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                )];
        }
        foreach ([null, '', '2', '01', '-0', '+1', 'true', 2, true, false, 1.0, [], new stdClass()] as $i => $value) {
            yield 'delete flag remains bounded ' . $i => [static fn (Ssl $s): mixed => $s->deleteSslVhost('one.test'),
                json_encode(
                    ['removed_vhost_data' => [['is_ssl' => $value]]],
                    JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
                )];
        }
        foreach (
            ['modulus_length' => '2048', 'not_before' => '1700000000', 'not_after' => '1800000000',
            'is_self_signed' => '1', 'issuer.organizationName' => null] as $field => $value
        ) {
            yield 'service component native contract unchanged ' . $field => [
                static fn (Ssl $s): mixed => $s->fetchServiceSslComponents(),
                json_encode(['services' => [['certificate_info' => [$field => $value]]]], JSON_THROW_ON_ERROR)];
        }
        yield 'FQDN common name remains nonnullable string' => [
            static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
            '{"payload":[{"issuer.commonName":null}]}'];
        yield 'reset common name remains nonnullable string' => [
            static fn (Ssl $s): mixed => $s->resetServiceSslCertificate('cpanel'),
            '{"certificate_info":{"issuer.commonName":null}}'];
        yield 'vhost common name retains documented object' => [static fn (Ssl $s): mixed => $s->fetchSslVhosts(),
            '{"vhosts":[{"crt":{"subject.commonName":"one.test"}}]}'];
        yield 'vhost common name inner leaf stays strict' => [static fn (Ssl $s): mixed => $s->fetchSslVhosts(),
            '{"vhosts":[{"crt":{"subject.commonName":{"commonName":null}}}]}'];
        yield 'delete sibling primary flag stays string' => [
            static fn (Ssl $s): mixed => $s->deleteSslVhost('one.test'),
            '{"removed_vhost_data":[{"primary_on_ip_port":1}]}'];
        yield 'installation payload status stays native flag' => [
            static fn (Ssl $s): mixed => $s->installSsl('CERT', 'one.test', 'KEY'), '{"status":"0"}'];
        yield 'installation warnings stay string list' => [
            static fn (Ssl $s): mixed => $s->installSsl('CERT', 'one.test', 'KEY'), '{"warning_domains":[null]}'];
    }

    /** @param SslCall $call */
    #[DataProvider('rejectedReviewedPayloads')]
    public function testReviewedCompatibilityDoesNotWidenOtherPayloads(Closure $call, ?string $payload): void
    {
        $this->testInvalidPayloadPreservesConfirmedOutcomeWithoutRetry($call, $payload);
    }

    /** @return iterable<string, array{int|string, int}> */
    private static function canonicalIntegerValues(): iterable
    {
        yield 'native zero' => [0, 0];
        yield 'native value' => [2048, 2048];
        yield 'quoted zero' => ['0', 0];
        yield 'quoted value' => ['2048', 2048];
        yield 'quoted negative' => ['-1', -1];
        yield 'quoted maximum' => ['9223372036854775807', PHP_INT_MAX];
        yield 'quoted minimum' => ['-9223372036854775808', PHP_INT_MIN];
    }

    /** @return list<mixed> */
    private static function noncanonicalIntegers(): array
    {
        return ['', '00', '01', '-0', '-01', '+1', '1.0', '1.5', '1e3', ' 1', '1 ',
            '9223372036854775808', '-9223372036854775809', true, false, 1.0, 1.5, [], new stdClass()];
    }

    /**
     * @return iterable<string, array{
     *     SslCall, Closure(array<string, mixed>|stdClass): array<string, mixed>, list<string>
     * }>
     */
    private static function reviewedCertificateFamilies(): iterable
    {
        yield 'FQDN' => [static fn (Ssl $s): mixed => $s->fetchSslCertificatesForFqdns('one.test'),
            static fn (array|stdClass $record): array => ['payload' => [$record]],
            ['created', 'not_before', 'not_after', 'modulus_length']];
        yield 'service reset' => [static fn (Ssl $s): mixed => $s->resetServiceSslCertificate('cpanel'),
            static fn (array|stdClass $record): array => ['certificate_info' => $record],
            ['not_before', 'not_after', 'modulus_length']];
        yield 'service install' => [
            static fn (Ssl $s): mixed => $s->installServiceSslCertificate('CERT', 'KEY', 'cpanel'),
            static fn (array|stdClass $record): array => ['certificate_info' => $record],
            ['not_before', 'not_after', 'modulus_length']];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sensitiveParameters(): iterable
    {
        yield 'queue PEM' => ['enqueueDeferredSslInstallations', ['cab', 'crt', 'key']];
        yield 'candidate certificate' => ['fetchSslInfo', ['crtdata']];
        yield 'generation passphrase' => ['generateSsl', ['passphrase']];
        yield 'service PEM' => ['installServiceSslCertificate', ['crt', 'key', 'cabundle']];
        yield 'website PEM' => ['installSsl', ['crt', 'key', 'cab']];
        yield 'serialized metadata' => ['setAutoSslMetadata', ['metadataJson']];
        yield 'metadata selector is nonsecret' => ['setAutoSslMetadataKey', []];
    }

    /** @param list<string> $expected */
    #[DataProvider('sensitiveParameters')]
    public function testSecretInputsAreSensitive(string $method, array $expected): void
    {
        $actual = [];
        foreach ((new ReflectionMethod(Ssl::class, $method))->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $actual[] = $parameter->getName();
            }
        }
        self::assertSame($expected, $actual);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidDomainLists(): iterable
    {
        yield 'empty' => [[]];
        yield 'associative' => [['name' => 'one.test']];
        yield 'integer' => [[1]];
        yield 'null' => [[null]];
        yield 'object' => [[new stdClass()]];
    }

    /** @param array<array-key, mixed> $domains */
    #[DataProvider('invalidDomainLists')]
    public function testInvalidDomainListsFailBeforeDispatch(array $domains): void
    {
        [$client, $http] = self::client();
        $this->expectException(InvalidArgumentException::class);
        try {
            // Deliberately invalid caller input exercises the runtime list boundary.
            $client->whm()->ssl()->addAutoSslUserExcludedDomains($domains, 'alice'); // @phpstan-ignore argument.type
        } finally {
            self::assertSame([], $http->getRequests());
            self::assertNull($client->whm()->responseMeta());
        }
    }

    private static function assertRequest(MockClient $http, string $function, string $method, string $parameters): void
    {
        $request = $http->getLastRequest();
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame($method, $request->getMethod());
        $query = 'api.version=1' . ($method === 'GET' && $parameters !== '' ? '&' . $parameters : '');
        self::assertSame(
            'https://api.example.test:2087/json-api/' . $function . '?' . $query,
            (string) $request->getUri(),
        );
        self::assertSame($method === 'POST' ? $parameters : '', (string) $request->getBody());
        self::assertSame(
            $method === 'POST' ? 'application/x-www-form-urlencoded' : '',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('whm root:fixture-token', $request->getHeaderLine('Authorization'));
    }

    private static function envelope(?string $payload): string
    {
        return '{"metadata":{"result":1,"reason":"OK","version":1}'
            . ($payload === null ? '' : ',"data":' . $payload) . '}';
    }

    /** @return array{Client, MockClient} */
    private static function client(): array
    {
        $factory = new Psr17Factory();
        $http = new MockClient($factory);
        return [new Client([
            'host' => 'api.example.test', 'authType' => 'whm', 'username' => 'root', 'token' => 'fixture-token',
        ], new HttpTransport($http, $factory, $factory)), $http];
    }
}
