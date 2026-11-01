<?php

namespace App\Core\Support;

use RuntimeException;

/**
 * Expected configuration error with a stable machine-readable code
 * (used by CLI output and later by the Admin API error format).
 */
class ConfigException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
