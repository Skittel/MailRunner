<?php

namespace App\Core\Send;

use App\Core\Support\ConfigException;

/**
 * DKIM keys (decision E-27): generation for applications that do not want to create keys themselves, and validation
 * of keys set over the API. Formats as dkimpy expects them in the delivery worker:
 *   rsa      PEM (PKCS#8 "PRIVATE KEY" or PKCS#1 "RSA PRIVATE KEY"), 1024-4096 bit, DNS p= is the SubjectPublicKeyInfo
 *   ed25519  base64 of the 32-byte seed (RFC 8463), DNS p= is the base64 32-byte public key
 */
final class DkimKeys
{
    public const MIN_RSA_BITS     = 1024;
    public const MAX_RSA_BITS     = 4096;
    public const DEFAULT_RSA_BITS = 2048;

    /** One DNS label or several (e.g. "mr2611-k3a9" or "s1.mail"), 63 characters at most. */
    public static function validSelector(string $selector): bool
    {
        return strlen($selector) <= 63 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $selector) === 1;
    }

    public static function newSelector(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $rand     = '';
        for ($i = 0; $i < 4; $i++) {
            $rand .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return 'mr' . gmdate('ym') . '-' . $rand;
    }

    /**
     * @return array{algorithm: string, bits: int, private_key: string, public: string}
     */
    public static function generate(string $algorithm = 'rsa', int $bits = self::DEFAULT_RSA_BITS): array
    {
        if ($algorithm === 'ed25519') {
            $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
            $pair = sodium_crypto_sign_seed_keypair($seed);
            $out  = ['algorithm' => 'ed25519', 'bits' => 256, 'private_key' => base64_encode($seed),
                'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
            sodium_memzero($seed);

            return $out;
        }
        if ($algorithm !== 'rsa') {
            throw new ConfigException('INVALID_DKIM_KEY', 'algorithm must be rsa or ed25519');
        }
        if (! in_array($bits, [2048, 3072, 4096], true)) {
            throw new ConfigException('INVALID_DKIM_KEY', 'bits must be 2048, 3072 or 4096');
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits])
            ?: throw new \RuntimeException('openssl could not generate an RSA key');
        openssl_pkey_export($key, $pem);

        return ['algorithm' => 'rsa', 'bits' => $bits, 'private_key' => $pem, 'public' => self::rsaPublic($key)];
    }

    /**
     * Checks a private key from the API and derives its public half.
     *
     * @return array{algorithm: string, bits: int, private_key: string, public: string} private_key normalised
     */
    public static function parse(string $privateKey, ?string $algorithm = null): array
    {
        $privateKey = trim($privateKey);
        if ($privateKey === '' || strlen($privateKey) > 16384) {
            throw new ConfigException('INVALID_DKIM_KEY', 'private_key is missing or too long');
        }
        $isPem = str_starts_with($privateKey, '-----BEGIN');
        $algorithm ??= $isPem ? 'rsa' : 'ed25519';
        if ($algorithm === 'ed25519') {
            $raw = base64_decode($privateKey, true);
            if ($raw === false || ! in_array(strlen($raw), [SODIUM_CRYPTO_SIGN_SEEDBYTES, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES], true)) {
                throw new ConfigException('INVALID_DKIM_KEY', 'ed25519 private_key must be the base64 32-byte seed (RFC 8463)');
            }
            $seed = substr($raw, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES); // a 64-byte libsodium secret key starts with the seed
            $pair = sodium_crypto_sign_seed_keypair($seed);
            if (strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES && substr($raw, 32) !== sodium_crypto_sign_publickey($pair)) {
                throw new ConfigException('INVALID_DKIM_KEY', 'ed25519 secret key is inconsistent');
            }

            return ['algorithm' => 'ed25519', 'bits' => 256, 'private_key' => base64_encode($seed), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
        }
        if ($algorithm !== 'rsa') {
            throw new ConfigException('INVALID_DKIM_KEY', 'algorithm must be rsa or ed25519');
        }
        $key = $isPem ? openssl_pkey_get_private($privateKey) : false;
        if ($key === false) {
            while (openssl_error_string() !== false) {
                // clear the openssl error queue
            }

            throw new ConfigException('INVALID_DKIM_KEY', 'rsa private_key must be an unencrypted PEM private key');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new ConfigException('INVALID_DKIM_KEY', 'private_key is not an RSA key');
        }
        if ($details['bits'] < self::MIN_RSA_BITS || $details['bits'] > self::MAX_RSA_BITS) {
            throw new ConfigException('INVALID_DKIM_KEY', sprintf('RSA key must have %d-%d bits (2048 recommended), has %d', self::MIN_RSA_BITS, self::MAX_RSA_BITS, $details['bits']));
        }
        openssl_pkey_export($key, $pem);

        return ['algorithm' => 'rsa', 'bits' => (int) $details['bits'], 'private_key' => $pem, 'public' => self::rsaPublic($key)];
    }

    /**
     * The TXT record to publish: name, full value and the value split into strings of at most 255 characters
     * (a TXT string is limited to 255 bytes; zone files and DNS panels often need the parts).
     *
     * @return array{name: string, type: string, value: string, value_parts: list<string>}
     */
    public static function dnsRecord(string $domain, string $selector, string $algorithm, string $public): array
    {
        $value = 'v=DKIM1; k=' . $algorithm . '; p=' . $public;

        return ['name' => $selector . '._domainkey.' . $domain, 'type' => 'TXT', 'value' => $value, 'value_parts' => str_split($value, 255)];
    }

    private static function rsaPublic(\OpenSSLAsymmetricKey $key): string
    {
        $pem = openssl_pkey_get_details($key)['key'];

        return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }
}
