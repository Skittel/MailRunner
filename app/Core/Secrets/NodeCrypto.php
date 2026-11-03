<?php

namespace App\Core\Secrets;

use RuntimeException;

/**
 * Encryption at rest with the node key (XChaCha20-Poly1305, libsodium).
 *
 * Format v1: "MRE1" | nonce (24 bytes) | ciphertext+tag; associated data = "MRE1" . context.
 * Python counterpart: mailpath/receive/mailrunner_rx/crypto.py (shared test vectors in tests/shared).
 *
 * The node key is random, generated once per node (`spark mr:node:init`) and never leaves the node.
 */
class NodeCrypto
{
    public const MAGIC = 'MRE1';

    private function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('Node key must be 32 bytes');
        }
    }

    public static function fromKey(string $key): self
    {
        return new self($key);
    }

    public static function fromFile(string $path): self
    {
        if (! is_readable($path)) {
            throw new RuntimeException('Node key not readable: ' . $path . ' (run: sudo mailrunner mr:node:init)');
        }
        $key = base64_decode(trim((string) file_get_contents($path)), true);
        if ($key === false) {
            throw new RuntimeException('Node key file is not valid base64: ' . $path);
        }

        return new self($key);
    }

    public static function forNode(): self
    {
        return self::fromFile(config('Mailrunner')->nodeKeyFile());
    }

    public function encrypt(string $plaintext, string $context, ?string $nonce = null): string
    {
        $nonce ??= random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::MAGIC . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::MAGIC . $context, $nonce, $this->key);
    }

    public function decrypt(string $blob, string $context): string
    {
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (! str_starts_with($blob, self::MAGIC) || strlen($blob) < 4 + $nonceLength + 16) {
            throw new RuntimeException('Not a MailRunner encrypted blob');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($blob, 4 + $nonceLength),
            self::MAGIC . $context,
            substr($blob, 4, $nonceLength),
            $this->key,
        );
        if ($plain === false) {
            throw new RuntimeException('Decryption failed (wrong key, context or damaged data)');
        }

        return $plain;
    }

    /**
     * Encrypts a JSON-serialisable value.
     */
    public function encryptJson(mixed $value, string $context): string
    {
        return $this->encrypt(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $context);
    }

    public function decryptJson(string $blob, string $context): mixed
    {
        return json_decode($this->decrypt($blob, $context), true, 512, JSON_THROW_ON_ERROR);
    }
}
