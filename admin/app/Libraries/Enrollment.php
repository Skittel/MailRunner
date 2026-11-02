<?php

namespace App\Libraries;

/**
 * One-time enrollment tokens for new nodes. The token is valid for TTL minutes and is consumed when the node
 * registers itself (install.sh --enroll-token). Only its sha256 is stored.
 */
final class Enrollment
{
    public const TTL_MINUTES = 60;
    public const NODE_KEY    = '/^[a-z0-9][a-z0-9.-]{1,62}$/';
    public const HOSTNAME    = '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/';

    /**
     * MX names "name=46, name4=4 name6=6": comma and/or spaces as separator. Returns the entries normalized
     * ("a=46, b=4") or null if one is invalid.
     */
    public static function normalizeMxNames(string $value): ?string
    {
        $entries = preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($entries as $e) {
            if (! preg_match('/^([a-z0-9.-]+)=(4|6|46)$/', $e, $m) || ! preg_match(self::HOSTNAME, $m[1])) {
                return null;
            }
        }

        return implode(', ', $entries);
    }

    /** Creates a token for the node; returns the token (shown once). */
    public static function create(string $nodeKey, string $hostname, string $mxNames = ''): string
    {
        $token = 'mre_' . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $now   = Clock::now();
        db_connect()->table('node_enrollments')->insert([
            'token_hash' => hash('sha256', $token), 'node_key' => $nodeKey, 'hostname' => $hostname, 'mx_names' => $mxNames, 'created_by' => session('user_id'),
            'created_at' => $now, 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60), 'used_at' => null,
        ]);

        return $token;
    }

    /**
     * The open (unused, unexpired) enrollment for a bearer token, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $authorization): ?array
    {
        if (! preg_match('/^Bearer (mre_[A-Za-z0-9_-]{20,80})$/', trim($authorization), $m)) {
            return null;
        }

        return db_connect()->table('node_enrollments')->where('token_hash', hash('sha256', $m[1]))->where('used_at', null)
            ->where('expires_at >', gmdate('Y-m-d H:i:s'))->get()->getRowArray();
    }
}
