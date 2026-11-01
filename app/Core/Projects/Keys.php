<?php

namespace App\Core\Projects;

use App\Core\Support\ConfigException;

/**
 * Validation of customer and project keys and "customer/project" references.
 */
final class Keys
{
    /** 3-40 chars, lower case letters, digits and inner hyphens. */
    public const PATTERN = '/^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$/';

    public static function assertValid(string $key, string $what): void
    {
        if (preg_match(self::PATTERN, $key) !== 1) {
            throw new ConfigException('INVALID_KEY', sprintf(
                '%s key "%s" is invalid (3-40 chars: a-z, 0-9, inner "-")',
                ucfirst($what),
                $key,
            ));
        }
    }

    /**
     * Splits "customer/project" into its two keys.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitProjectRef(string $ref): array
    {
        $parts = explode('/', $ref);
        if (count($parts) !== 2) {
            throw new ConfigException('INVALID_REFERENCE', 'Project reference must be "customer/project", got "' . $ref . '"');
        }
        self::assertValid($parts[0], 'customer');
        self::assertValid($parts[1], 'project');

        return [$parts[0], $parts[1]];
    }
}
