<?php

namespace App\Core\Support;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Runs a callback in one database transaction; rolls back on any exception.
 */
final class Transaction
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(BaseConnection $db, callable $callback): mixed
    {
        $db->transException(true);
        $db->transBegin();

        try {
            $result = $callback();
            $db->transCommit();

            return $result;
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }
}
