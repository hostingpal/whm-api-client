<?php

declare(strict_types=1);

namespace HostingPal\WhmApiClient\Tools\LiveVerify;

use OpenSSLAsymmetricKey;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use SensitiveParameter;

/** Fresh RSA key and self-signed certificate retained only in process memory for account storage probes. */
final readonly class SslSectionMaterial
{
    private function __construct(
        #[SensitiveParameter] public string $key,
        #[SensitiveParameter] public string $certificate,
        public string $modulus,
    ) {
    }

    /** Generate a new 2048-bit RSA pair for npr.rs; never read, write or install a server certificate. */
    public static function generate(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        if ($key === false) {
            throw new RuntimeException('SSL fixture material unavailable.');
        }
        $csr = openssl_csr_new(
            ['commonName' => 'npr.rs', 'organizationName' => 'Temporary API fixture'],
            $key,
            ['digest_alg' => 'sha256'],
        );
        if (!$csr instanceof OpenSSLCertificateSigningRequest || !$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('SSL fixture material unavailable.');
        }
        $cert = openssl_csr_sign(
            $csr,
            null,
            $key,
            1,
            ['digest_alg' => 'sha256'],
            random_int(1, 2147483647),
        );
        $private = '';
        $certificate = '';
        if ($cert === false || !openssl_pkey_export($key, $private) || !openssl_x509_export($cert, $certificate)) {
            throw new RuntimeException('SSL fixture material unavailable.');
        }
        if (!is_string($private) || !is_string($certificate)) {
            throw new RuntimeException('SSL fixture export unavailable.');
        }
        $modulus = self::keyModulus($private);
        if ($modulus === null) {
            throw new RuntimeException('SSL fixture public identity unavailable.');
        }
        return new self($private, $certificate, $modulus);
    }

    /** Compare a returned private key by its public RSA identity, allowing equivalent PEM serialization. */
    public static function keyModulus(#[SensitiveParameter] string $pem): ?string
    {
        $key = @openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        $rsa = $details !== false ? ($details['rsa'] ?? null) : null;
        return is_array($rsa) && is_string($rsa['n'] ?? null) ? self::normalize(bin2hex($rsa['n'])) : null;
    }

    /** Normalize a hexadecimal public modulus, never a private exponent. */
    public static function normalize(string $hex): string
    {
        if (preg_match('/\A[0-9a-fA-F]+\z/', $hex) !== 1) {
            throw new RuntimeException('SSL fixture public identity invalid.');
        }
        return ltrim(strtolower($hex), '0');
    }
}
