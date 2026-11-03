<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Common secrets for all nodes (Einstellungen -> Gemeinsame Schlüssel). Stored encrypted (XChaCha20-Poly1305,
 * key file Config\Admin::$secretsKeyFile); the GUI never shows a value again. Every save gets a new random
 * revision; Sync pushes the full set to every node whose reported revision differs (PUT /admin/v1/node/secrets).
 * A node's own non-empty value in its secrets.env wins (shown as "lokal überschrieben").
 */
class CommonSecrets
{
    /** Same names as the node (Config\Mailrunner::COMMON_SECRET_KEYS). */
    public const KEYS = [
        'SPAMHAUS_DQS_KEY'   => ['Spamhaus DQS-Schlüssel', 'DNSBL-Abfragen über zen.dq.spamhaus.net'],
        'BLM_USER'           => ['blacklistmaster Benutzer', 'API-Zugang blacklistmaster.com (zusammen mit dem Kennwort)'],
        'BLM_PASS'           => ['blacklistmaster Kennwort', 'API-Zugang blacklistmaster.com'],
        'IPREGISTRYIO_TOKEN' => ['ipregistry Token', 'AS, Land, Bedrohung der einliefernden IP'],
    ];

    private const MAGIC = 'MRA1';

    public function __construct(private readonly string $keyFile)
    {
    }

    public static function make(): self
    {
        return new self(config('Admin')->secretsKeyFile);
    }

    /** @return array<string, array{set: bool, updated_at: ?string, updated_by: ?string}> per known key, no values */
    public function overview(): array
    {
        $rows = [];
        foreach (db_connect()->table('common_secrets s')->select('s.name, s.updated_at, u.name AS user_name')->join('users u', 'u.id = s.updated_by', 'left')->get()->getResultArray() as $r) {
            $rows[$r['name']] = $r;
        }
        $out = [];
        foreach (array_keys(self::KEYS) as $k) {
            $out[$k] = ['set' => isset($rows[$k]), 'updated_at' => $rows[$k]['updated_at'] ?? null, 'updated_by' => $rows[$k]['user_name'] ?? null];
        }

        return $out;
    }

    /**
     * Applies changes: $set name => new value, $delete names. Returns the changed names; bumps the revision if any.
     *
     * @param array<string, string> $set
     * @param list<string>          $delete
     *
     * @return list<string>
     */
    public function save(array $set, array $delete, ?int $userId): array
    {
        $db      = db_connect();
        $changed = [];
        foreach ($set as $k => $v) {
            if (! isset(self::KEYS[$k])) {
                throw new RuntimeException('unknown secret ' . $k);
            }
            $db->table('common_secrets')->replace(['name' => $k, 'value_enc' => $this->encrypt($v, $k), 'updated_at' => Clock::now(), 'updated_by' => $userId]);
            $changed[] = $k;
        }
        foreach ($delete as $k) {
            if (isset(self::KEYS[$k]) && $db->table('common_secrets')->where('name', $k)->countAllResults() > 0) {
                $db->table('common_secrets')->where('name', $k)->delete();
                $changed[] = $k;
            }
        }
        if ($changed !== []) {
            $db->table('settings')->replace(['name' => 'common_secrets_revision', 'value' => PublicId::random(), 'updated_at' => Clock::now()]);
        }

        return array_values(array_unique($changed));
    }

    /** Current revision, null while nothing was ever saved (nodes keep their own state then). */
    public function revision(): ?string
    {
        $v = db_connect()->table('settings')->select('value')->where('name', 'common_secrets_revision')->get()->getRow('value');

        return $v === null ? null : (string) $v;
    }

    /** @return array<string, string> decrypted values (only for the push to the nodes) */
    public function values(): array
    {
        $out = [];
        foreach (db_connect()->table('common_secrets')->get()->getResultArray() as $r) {
            $out[$r['name']] = $this->decrypt($r['value_enc'], $r['name']);
        }
        ksort($out);

        return $out;
    }

    /**
     * Masked values for comparison: first two and last two characters ("ab…yz", 12 Zeichen).
     * Values shorter than 8 characters show only their length (two plus two would reveal most of them).
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        return array_map(static fn (string $v): string => strlen($v) >= 8
            ? substr($v, 0, 2) . '…' . substr($v, -2) . ' (' . strlen($v) . ' Zeichen)'
            : '•••• (' . strlen($v) . ' Zeichen)', $this->values());
    }

    /** Value is acceptable for a node (same rules as the node). */
    public static function validValue(string $v): bool
    {
        return $v !== '' && strlen($v) <= 512 && preg_match('/[\x00-\x1f\x7f]/', $v) !== 1;
    }

    private function key(): string
    {
        $raw = is_readable($this->keyFile) ? base64_decode(trim((string) file_get_contents($this->keyFile)), true) : false;
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new RuntimeException('secrets key not readable: ' . $this->keyFile . ' (install-admin.sh creates it)');
        }

        return $raw;
    }

    private function encrypt(string $plain, string $name): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::MAGIC . $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::MAGIC . $name, $nonce, $this->key());
    }

    private function decrypt(string $blob, string $name): string
    {
        $n     = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = str_starts_with($blob, self::MAGIC)
            ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($blob, 4 + $n), self::MAGIC . $name, substr($blob, 4, $n), $this->key())
            : false;
        if ($plain === false) {
            throw new RuntimeException('cannot decrypt secret ' . $name . ' (wrong secrets key?)');
        }

        return $plain;
    }
}
