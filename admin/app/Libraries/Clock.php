<?php

namespace App\Libraries;

final class Clock
{
    public const DB = 'Y-m-d H:i:s.u';

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(self::DB);
    }

    /** Seconds since a DB timestamp (UTC), null if none. */
    public static function ageSeconds(?string $ts): ?int
    {
        return $ts === null ? null : time() - (new \DateTimeImmutable($ts, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
