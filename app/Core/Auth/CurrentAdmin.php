<?php

namespace App\Core\Auth;

use App\Core\Support\ChangeContext;
use RuntimeException;

/**
 * Subject of the verified admin client certificate for the current request; the admin GUI adds
 * the acting user in the X-MailRunner-Actor header (journal actor "admin-dn | user").
 */
final class CurrentAdmin
{
    private static ?string $subject = null;

    public static function set(?string $subject): void
    {
        self::$subject = $subject;
    }

    public static function context(?string $user = null): ChangeContext
    {
        if (self::$subject === null) {
            throw new RuntimeException('No admin certificate for this request');
        }
        $user = $user === null ? '' : substr(preg_replace('/[^\w@.\- ]/u', '', $user) ?? '', 0, 80);

        return new ChangeContext('admin', substr(self::$subject . ($user !== '' ? ' | ' . $user : ''), 0, 128));
    }
}
