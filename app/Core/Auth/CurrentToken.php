<?php

namespace App\Core\Auth;

use LogicException;

/**
 * The API token of the current request (set by BearerTokenFilter).
 */
final class CurrentToken
{
    /** @var array<string, mixed>|null */
    private static ?array $token = null;

    /**
     * @param array<string, mixed>|null $token
     */
    public static function set(?array $token): void
    {
        self::$token = $token;
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return self::$token ?? throw new LogicException('No authenticated token');
    }

    public static function projectId(): int
    {
        return (int) self::get()['project_id'];
    }
}
