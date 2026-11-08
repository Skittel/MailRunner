<?php

namespace App\Core\Send;

use App\Core\Support\Time;
use CodeIgniter\Database\BaseConnection;

/**
 * Deletion periods of the send role (mr:maintenance):
 *   the encrypted mail of a submission is deleted as soon as every recipient is final (sent, bounced, failed);
 *   submissions, messages and attempts are purged after the project's metadata_days.
 */
final class SendRetention
{
    private const FINAL = ['sent', 'bounced', 'failed'];

    public function __construct(private readonly BaseConnection $db, private readonly string $storeDir)
    {
    }

    /** @return array{raw_deleted: int, purged: int} */
    public function run(): array
    {
        $stats = ['raw_deleted' => 0, 'purged' => 0];
        $done  = $this->db->query(
            'SELECT s.id, s.raw_path FROM send_submissions s WHERE s.raw_path IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM send_messages m WHERE m.submission_row_id = s.id AND m.status NOT IN (\'sent\', \'bounced\', \'failed\'))
             LIMIT 5000',
        )->getResultArray();
        foreach ($done as $s) {
            $path = $this->storeDir . '/' . $s['raw_path'];
            if (! is_file($path) || @unlink($path)) {
                $this->db->table('send_submissions')->where('id', $s['id'])->update(['raw_path' => null]);
                $stats['raw_deleted']++;
            }
        }
        foreach ($this->db->table('projects')->select('id, metadata_days')->where('type', 'send')->get()->getResultArray() as $p) {
            $days   = max(1, (int) $p['metadata_days']);
            $before = Time::nowObject()->sub(new \DateInterval('P' . $days . 'D'))->format(Time::DB_FORMAT);
            $this->db->query(
                'DELETE FROM send_submissions WHERE project_id = ? AND created_at < ? AND raw_path IS NULL
                 AND NOT EXISTS (SELECT 1 FROM send_messages m WHERE m.submission_row_id = send_submissions.id AND m.status NOT IN (\'sent\', \'bounced\', \'failed\'))',
                [$p['id'], $before],
            );
            $stats['purged'] += $this->db->affectedRows();
        }

        return $stats;
    }
}
