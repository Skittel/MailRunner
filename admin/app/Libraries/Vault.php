<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Encryption of secrets kept in the admin database (XChaCha20-Poly1305, key file Config\Admin::$secretsKeyFile,
 * same key and format as CommonSecrets). The context binds a value to its place (e.g. "send-config:<project id>").
 */
class Vault
{
    private const MAGIC = 'MRA1';

    public function __construct(private readonly string $keyFile)
    {
    }

    public static function make(): self
    {
        return new self(config('Admin')->secretsKeyFile);
    }

    public function encrypt(string $plain, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::MAGIC . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::MAGIC . $context, $nonce, $this->key());
    }

    public function decrypt(string $blob, string $context): string
    {
        $n     = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = str_starts_with($blob, self::MAGIC)
            ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, 4 + $n), self::MAGIC . $context, substr($blob, 4, $n), $this->key())
            : false;
        if ($plain === false) {
            throw new RuntimeException('cannot decrypt ' . $context . ' (wrong secrets key?)');
        }

        return $plain;
    }

    private function key(): string
    {
        $raw = is_readable($this->keyFile) ? base64_decode(trim((string) file_get_contents($this->keyFile)), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('secrets key not readable: ' . $this->keyFile . ' (install-admin.sh creates it)');
        }

        return $raw;
    }
}
