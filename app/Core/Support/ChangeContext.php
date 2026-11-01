<?php

namespace App\Core\Support;

/**
 * Who changes the configuration, and whether CLI may override admin-managed objects.
 */
final class ChangeContext
{
    /**
     * @param 'admin'|'api'|'cli'|'system' $source
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $actor = null,
        public readonly bool $force = false,
    ) {
    }

    public static function cli(bool $force = false): self
    {
        $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null;

        return new self('cli', $user ?? get_current_user(), $force);
    }
}
