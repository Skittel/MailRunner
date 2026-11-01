<?php

namespace App\Core\Support;

use InvalidArgumentException;

/**
 * Parses short durations like "0", "30m", "24h", "7d" into seconds.
 */
final class Duration
{
    public static function toSeconds(string $value): int
    {
        $value = strtolower(trim($value));
        if ($value === '0') {
            return 0;
        }
        if (preg_match('/^(\d{1,6})([smhd])$/', $value, $m) !== 1) {
            throw new InvalidArgumentException('Invalid duration "' . $value . '", use e.g. 0, 30m, 24h, 7d');
        }

        return (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
}
