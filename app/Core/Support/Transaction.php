<?php

namespace App\Core\Support;

use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Runs a callback in one database transaction; rolls back on any exception.
 *
 * Outermost transactions run with READ COMMITTED: MariaDB 11.8 checks REPEATABLE READ snapshots strictly
 * (innodb_snapshot_isolation=ON), so a locking read of a row that another transaction changed after the first read
 * failed with "Record has changed since last read" (e.g. journal_sequence while the import and an API ack write the
 * journal at the same time). Row locks (SELECT ... FOR UPDATE) still serialise the writers. A deadlock or such a
 * conflict is retried up to RETRIES times; callbacks must therefore only change the database.
 */
final class Transaction
{
    private const RETRIES = 3;

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
        $outer = $db->transDepth < 1;

        for ($attempt = 1; ; $attempt++) {
            if ($outer) {
                $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); // applies to the next transaction only
            }
            $db->transBegin();

            try {
                $result = $callback();
                $db->transCommit();

                return $result;
            } catch (Throwable $e) {
                $db->transRollback();
                if (! $outer || $attempt >= self::RETRIES || ! self::isRetryable($e)) {
                    throw $e;
                }
                usleep(random_int(20_000, 100_000) * $attempt);
            }
        }
    }

    private static function isRetryable(Throwable $e): bool
    {
        $m = $e->getMessage();

        return in_array((int) $e->getCode(), [1020, 1213], true)
            || str_contains($m, 'Record has changed since last read') || str_contains($m, 'Deadlock found');
    }
}
