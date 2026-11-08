<?php

namespace App\Core\Send;

use CodeIgniter\Database\BaseConnection;

/**
 * change_seq of send_messages (E-27 step 4). The counter row stays locked until the writing transaction commits, so
 * a reader of "changes after N" never misses a change that commits later with a smaller number. mr-sender (Python)
 * takes numbers the same way.
 */
final class SendSequence
{
    /** Reserves $n numbers inside the current transaction; returns the first. */
    public static function take(BaseConnection $db, int $n = 1): int
    {
        $db->query('UPDATE send_sequence SET seq = LAST_INSERT_ID(seq + ?) WHERE id = 1', [$n]);
        $top = (int) $db->query('SELECT LAST_INSERT_ID() AS s')->getRow()->s;

        return $top - $n + 1;
    }
}
