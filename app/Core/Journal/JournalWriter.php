<?php

namespace App\Core\Journal;

use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use CodeIgniter\Database\BaseConnection;
use LogicException;

/**
 * Appends journal events with gap-free ids from journal_sequence.
 *
 * Must be called inside the caller's transaction so that the change and its
 * journal event are committed together.
 */
class JournalWriter
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param array<string, mixed> $details Never put secrets or mail content here.
     */
    public function append(
        string $event,
        ChangeContext $context,
        ?int $customerId = null,
        ?int $projectId = null,
        array $details = [],
    ): int {
        if ($this->db->transDepth < 1) {
            throw new LogicException('JournalWriter::append() requires an open transaction');
        }

        if ($context->force) {
            $details['forced'] = true; // CLI override of an admin-managed object
        }

        $row = $this->db->query('SELECT next_id FROM journal_sequence WHERE id = 1 FOR UPDATE')->getRowArray();
        $id  = (int) $row['next_id'];
        $this->db->query('UPDATE journal_sequence SET next_id = next_id + 1 WHERE id = 1');

        $this->db->table('journal')->insert([
            'id'          => $id,
            'created_at'  => Time::now(),
            'event'       => $event,
            'source'      => $context->source,
            'actor'       => $context->actor,
            'customer_id' => $customerId,
            'project_id'  => $projectId,
            'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return $id;
    }
}
