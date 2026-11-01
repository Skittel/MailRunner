<?php

namespace App\Core\Support;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Time helpers. All stored times are UTC with microseconds (DATETIME(6)).
 */
final class Time
{
    public const DB_FORMAT = 'Y-m-d H:i:s.u';

    public static function nowObject(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function now(): string
    {
        return self::nowObject()->format(self::DB_FORMAT);
    }

    /**
     * Returns "now + seconds" in DB format.
     */
    public static function inSeconds(int $seconds): string
    {
        return self::nowObject()->add(new DateInterval('PT' . max(0, $seconds) . 'S'))->format(self::DB_FORMAT);
    }

    /**
     * Converts a DB value to ISO 8601 UTC ("2026-11-01T15:04:37.123456Z"), null stays null.
     */
    public static function toIso(?string $dbValue): ?string
    {
        if ($dbValue === null) {
            return null;
        }

        return (new DateTimeImmutable($dbValue, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
