<?php

namespace App\Core\Send;

use App\Core\Support\Time;
use CodeIgniter\Database\BaseConnection;

/**
 * Send queue figures for the admin API (E-27 step 7): per node or per project. The message list carries only the
 * recipient domain, never the address, subject or content: the admin keeps no customer data (E-19).
 */
class SendQueueStats
{
    public const OPEN  = ['queued', 'waiting_test', 'sending', 'deferred'];
    public const FINAL = ['sent', 'bounced', 'failed'];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * Messages per open status, finished in the last 24 h, the oldest open message.
     *
     * @return array<string, mixed>
     */
    public function figures(?int $projectId = null): array
    {
        $where = $projectId === null ? '' : ' AND project_id = ' . $projectId;
        $out   = array_fill_keys(self::OPEN, 0);
        foreach ($this->db->query("SELECT status, COUNT(*) AS n FROM send_messages WHERE status IN ('queued','waiting_test','sending','deferred')" . $where . ' GROUP BY status')->getResultArray() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        $since = Time::nowObject()->sub(new \DateInterval('P1D'))->format(Time::DB_FORMAT);
        $last  = array_fill_keys(self::FINAL, 0);
        foreach ($this->db->query("SELECT status, COUNT(*) AS n FROM send_messages WHERE status IN ('sent','bounced','failed') AND updated_at >= ?" . $where . ' GROUP BY status', [$since])->getResultArray() as $r) {
            $last[$r['status']] = (int) $r['n'];
        }
        $oldest = $this->db->query("SELECT MIN(created_at) AS t FROM send_messages WHERE status IN ('queued','waiting_test','sending','deferred')" . $where)->getRow('t');

        return $out + ['last_24h' => $last, 'oldest_open' => Time::toIso($oldest)];
    }

    /**
     * The latest messages of a project, open ones first, then the most recently changed.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $projectId, int $limit = 30): array
    {
        $rows = $this->db->query(
            "SELECT m.message_id, s.submission_id, s.profile_name, m.recipient_domain, m.status, m.status_reason, m.attempts, m.next_attempt_at,
                    m.expires_at, m.last_code, m.last_enhanced, m.last_response, m.sent_at, m.created_at, m.updated_at
               FROM send_messages m JOIN send_submissions s ON s.id = m.submission_row_id
              WHERE m.project_id = ?
              ORDER BY (m.status IN ('queued','waiting_test','sending','deferred')) DESC, m.updated_at DESC, m.id DESC
              LIMIT " . max(1, min(200, $limit)),
            [$projectId]
        )->getResultArray();

        return array_map(static fn (array $r) => [
            'message_id' => $r['message_id'], 'submission_id' => $r['submission_id'], 'profile' => $r['profile_name'],
            'recipient_domain' => $r['recipient_domain'], 'status' => $r['status'], 'reason' => $r['status_reason'], 'attempts' => (int) $r['attempts'],
            'last' => $r['last_code'] === null ? null : ['code' => (int) $r['last_code'], 'enhanced' => $r['last_enhanced'], 'response' => $r['last_response']],
            'next_attempt_at' => Time::toIso($r['next_attempt_at']), 'expires_at' => Time::toIso($r['expires_at']), 'sent_at' => Time::toIso($r['sent_at']),
            'created_at' => Time::toIso($r['created_at']), 'updated_at' => Time::toIso($r['updated_at']),
        ], $rows);
    }
}
