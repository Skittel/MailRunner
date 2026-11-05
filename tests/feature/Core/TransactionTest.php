<?php

namespace Tests\Feature\Core;

use App\Core\Support\Transaction;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Concurrent journal writers on MariaDB 11.8 (innodb_snapshot_isolation): no "Record has changed since last read".
 *
 * @internal
 */
final class TransactionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testLockingReadAfterAConcurrentChange(): void
    {
        $db    = db_connect();
        $other = \Config\Database::connect('tests', false);
        $next  = Transaction::run($db, static function () use ($db, $other): int {
            $db->query('SELECT next_id FROM journal_sequence WHERE id = 1')->getRow(); // first read: snapshot
            $other->query('UPDATE journal_sequence SET next_id = next_id + 1 WHERE id = 1'); // another writer commits

            return (int) $db->query('SELECT next_id FROM journal_sequence WHERE id = 1 FOR UPDATE')->getRow('next_id');
        });
        $this->assertSame((int) $db->query('SELECT next_id FROM journal_sequence WHERE id = 1')->getRow('next_id'), $next, 'sees the committed value');
    }

    public function testConflictsAreRetried(): void
    {
        $calls  = 0;
        $result = Transaction::run(db_connect(), static function () use (&$calls): string {
            if (++$calls === 1) {
                throw new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction', 1213);
            }

            return 'done';
        });
        $this->assertSame(['done', 2], [$result, $calls]);

        $this->expectException(\LogicException::class);
        Transaction::run(db_connect(), static fn () => throw new \LogicException('not retried'));
    }
}
