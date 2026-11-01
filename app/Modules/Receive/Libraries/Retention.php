<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Journal\JournalWriter;
use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use CodeIgniter\Database\BaseConnection;

/**
 * Deletion periods per project:
 * Content = the encrypted files in store/ (analysis, raw mail if kept).
 * - content N hours after ack (delete_after_ack_hours)  -> event receive.deleted (ack_retention)
 * - content of unacknowledged messages after N days     -> event receive.expired
 * - metadata rows after N days (metadata_days); the journal keeps its own period.
 * - journal entries of delivery attempts (smtp.*) after the node setting smtpJournalDays.
 */
class Retention
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly JournalWriter $journal,
        private readonly ?MessageStore $files = null,
        private readonly int $smtpJournalDays = 30,
    ) {
    }

    /**
     * @return array{deleted: int, expired: int, purged: int, smtp_journal: int}
     */
    public function run(): array
    {
        $stats = ['deleted' => 0, 'expired' => 0, 'purged' => 0, 'smtp_journal' => 0];
        $now   = Time::nowObject();

        foreach ($this->db->table('projects')->get()->getResultArray() as $project) {
            $ackLimit     = $now->modify('-' . (int) $project['delete_after_ack_hours'] . ' hours')->format(Time::DB_FORMAT);
            $unackedLimit = $now->modify('-' . (int) $project['unacked_days'] . ' days')->format(Time::DB_FORMAT);
            $metaLimit    = $now->modify('-' . (int) $project['metadata_days'] . ' days')->format(Time::DB_FORMAT);

            $stats['deleted'] += $this->clearContent($project, 'acked_at IS NOT NULL AND acked_at <= ?', [$ackLimit], 'ack_retention', 'receive.deleted');
            $stats['expired'] += $this->clearContent($project, 'acked_at IS NULL AND received_at <= ?', [$unackedLimit], 'expired', 'receive.expired');

            $this->db->query('DELETE FROM receive_messages WHERE project_id = ? AND content_deleted_at IS NOT NULL AND received_at <= ?', [$project['id'], $metaLimit]);
            $stats['purged'] += $this->db->affectedRows();
        }

        $smtpLimit = $now->modify('-' . max(1, $this->smtpJournalDays) . ' days')->format(Time::DB_FORMAT);
        $this->db->query("DELETE FROM journal WHERE event IN ('smtp.connection', 'smtp.attempt') AND created_at <= ?", [$smtpLimit]);
        $stats['smtp_journal'] = $this->db->affectedRows();
        $this->db->query('DELETE FROM receive_smtp_sessions WHERE imported_at <= ?', [$smtpLimit]);

        return $stats;
    }

    /**
     * @param array<string, mixed> $project
     * @param list<string>         $bind
     */
    private function clearContent(array $project, string $where, array $bind, string $reason, string $event): int
    {
        $rows = $this->db->query(
            'SELECT id, message_id, analysis_path, raw_path FROM receive_messages WHERE project_id = ? AND content_deleted_at IS NULL AND ' . $where . ' LIMIT 1000',
            [$project['id'], ...$bind],
        )->getResultArray();

        foreach ($rows as $row) {
            Transaction::run($this->db, function () use ($row, $project, $reason, $event): void {
                $this->db->table('receive_messages')->where('id', $row['id'])->update([
                    'analysis_path' => null, 'raw_path' => null, 'content_deleted_at' => Time::now(), 'delete_reason' => $reason,
                ]);
                $this->journal->append($event, new ChangeContext('system', 'mr:maintenance'), (int) $project['customer_id'], (int) $project['id'], [
                    'message_id' => $row['message_id'], 'reason' => $reason,
                ]);
            });
            // files after commit: a crash leaves at most an orphan file, never a row pointing to nothing
            $this->files?->delete($row['analysis_path']);
            $this->files?->delete($row['raw_path']);
        }

        return count($rows);
    }
}
