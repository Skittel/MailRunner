<?php

namespace App\Libraries;

use RuntimeException;

/**
 * API tokens of the projects (central, decision: tokens like settings come from the admin). The admin creates
 * the secret, shows it once and stores only its SHA-256; every push of a project carries the full token set to
 * every node of the project, so one bearer works on all of them (move, later several API nodes behind a load
 * balancer). Tokens that existed on a node before are imported once with their hash (the bearer stays valid).
 */
class Tokens
{
    public const ID_PREFIX = 'mrt';

    /** Node system projects (self test): tokens stay local on each node, never central. */
    public const LOCAL_CUSTOMER = 'system';

    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'; // same as the node (RandomId)

    /** @return array{token_id: string, bearer: string, expires_at: ?string} */
    public function create(int $projectId, string $label, ?int $days, ?int $userId): array
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new RuntimeException('Bezeichnung fehlt (max. 100 Zeichen).');
        }
        $db = db_connect();
        do {
            $tokenId = self::ID_PREFIX . '_' . self::randomId(16);
        } while ($db->table('api_tokens')->where('token_id', $tokenId)->countAllResults() > 0);
        $secret  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $days !== null && $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * 86400) . '.000000' : null;
        $db->table('api_tokens')->insert([
            'project_id' => $projectId, 'token_id' => $tokenId, 'label' => $label, 'secret_hash' => hash('sha256', $secret),
            'created_at' => Clock::now(), 'created_by' => $userId, 'origin' => 'admin', 'expires_at' => $expires, 'revoked_at' => null,
        ]);

        return ['token_id' => $tokenId, 'bearer' => $tokenId . '.' . $secret, 'expires_at' => $expires];
    }

    public function revoke(int $projectId, string $tokenId): bool
    {
        $db = db_connect();
        $db->table('api_tokens')->where('project_id', $projectId)->where('token_id', $tokenId)->where('revoked_at', null)->update(['revoked_at' => Clock::now()]);

        return $db->affectedRows() > 0;
    }

    /** @return list<array<string, mixed>> without hashes, newest first */
    public function list(int $projectId): array
    {
        return db_connect()->table('api_tokens')->select('token_id, label, origin, created_at, expires_at, revoked_at')
            ->where('project_id', $projectId)->orderBy('id', 'DESC')->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> the full set for the node (PUT projects/...: "tokens") */
    public function forPush(int $projectId): array
    {
        return array_map(static fn ($t) => [
            'token_id' => $t['token_id'], 'label' => $t['label'], 'secret_hash' => $t['secret_hash'],
            'created_at' => self::iso($t['created_at']), 'expires_at' => self::iso($t['expires_at']), 'revoked_at' => self::iso($t['revoked_at']),
        ], db_connect()->table('api_tokens')->where('project_id', $projectId)->orderBy('id')->get()->getResultArray());
    }

    /**
     * Takes over the tokens a node has for the project (GET projects/... with secret_hash). Returns the number
     * imported; tokens already known here are left as they are.
     *
     * @param list<array<string, mixed>> $nodeTokens
     */
    public function import(int $projectId, array $nodeTokens): int
    {
        $db = db_connect();
        $n  = 0;
        foreach ($nodeTokens as $t) {
            $id = (string) ($t['token_id'] ?? '');
            if (preg_match('/^' . self::ID_PREFIX . '_[a-z0-9]{16}$/', $id) !== 1 || preg_match('/^[0-9a-f]{64}$/', (string) ($t['secret_hash'] ?? '')) !== 1
                || $db->table('api_tokens')->where('token_id', $id)->countAllResults() > 0) {
                continue;
            }
            $db->table('api_tokens')->insert([
                'project_id' => $projectId, 'token_id' => $id, 'label' => mb_substr((string) ($t['label'] ?? '') ?: $id, 0, 100), 'secret_hash' => $t['secret_hash'],
                'created_at' => self::db($t['created_at'] ?? null) ?? Clock::now(), 'created_by' => null, 'origin' => 'imported',
                'expires_at' => self::db($t['expires_at'] ?? null), 'revoked_at' => self::db($t['revoked_at'] ?? null),
            ]);
            $n++;
        }

        return $n;
    }

    private static function randomId(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, 31)];
        }

        return $out;
    }

    private static function iso(?string $db): ?string
    {
        return $db === null ? null : (new \DateTimeImmutable($db, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function db(mixed $iso): ?string
    {
        return $iso === null || $iso === '' ? null : (new \DateTimeImmutable((string) $iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
