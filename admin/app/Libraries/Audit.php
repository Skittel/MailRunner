<?php

namespace App\Libraries;

/** Who did what in the admin GUI (own database, never secrets). */
class Audit
{
    public static function log(string $action, ?string $object = null, array $details = []): void
    {
        db_connect()->table('audit_log')->insert([
            'created_at' => Clock::now(),
            'user_id'    => session('user_id'),
            'action'     => $action,
            'object'     => $object,
            'details'    => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
