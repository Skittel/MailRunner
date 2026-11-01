<?php

namespace App\Core\Support;

/**
 * Random public ids and secrets.
 */
final class RandomId
{
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'; // 32 chars, no 0/o/1/l

    /**
     * Public id like "mrt_k3f9x2hq7m4a" (prefix + 16 chars = 80 bits).
     */
    public static function publicId(string $prefix, int $length = 16): string
    {
        $id    = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $id .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $prefix . '_' . $id;
    }

    /**
     * 256-bit secret, base64url without padding (43 chars).
     */
    public static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Human-typable password for SMTP devices (24 chars, ~120 bits).
     */
    public static function password(int $length = 24): string
    {
        return substr(self::publicId('x', $length), 2);
    }
}
