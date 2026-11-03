<?php

namespace App\Libraries;

use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Random public ids (format xxxx-xxxx, a-z0-9) for URLs. Internal auto-increment ids never appear in URLs,
 * so they reveal neither counts nor order and cannot be enumerated.
 */
final class PublicId
{
    public const PATTERN = '[0-9a-z]{4}-[0-9a-z]{4}';
    public const TABLES  = ['nodes', 'customers', 'projects'];

    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /** A new id that is not yet used in $table. */
    public static function generate(string $table): string
    {
        $db = db_connect();
        do {
            $id = self::random();
        } while ($db->table($table)->where('public_id', $id)->countAllResults() > 0);

        return $id;
    }

    /** A random id in the same format (no uniqueness check), e.g. a revision. */
    public static function random(): string
    {
        $id = '';
        for ($i = 0; $i < 8; $i++) {
            $id .= self::ALPHABET[random_int(0, 35)] . ($i === 3 ? '-' : '');
        }

        return $id;
    }

    /** Internal id for a public id, or 404. */
    public static function id(string $table, string $publicId): int
    {
        if (! in_array($table, self::TABLES, true) || ! preg_match('/^' . self::PATTERN . '$/', $publicId)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $id = db_connect()->table($table)->select('id')->where('public_id', $publicId)->get()->getRow('id');

        return $id === null ? throw PageNotFoundException::forPageNotFound() : (int) $id;
    }
}
