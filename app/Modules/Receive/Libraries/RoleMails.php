<?php

namespace App\Modules\Receive\Libraries;

use App\Core\Node\SelfTest;
use App\Modules\Receive\Controllers\Customer\Messages;

/**
 * Mails to postmaster@/abuse@ of all projects (E-18). Shared by the node admin API and the CLI
 * (mr:rolemail:list / mr:rolemail:show), so a node without MailRunner Admin has the same view.
 */
class RoleMails
{
    /**
     * @return list<array<string, mixed>> newest first, each with "project" and the fields of Messages::present()
     */
    public function list(int $limit = 100, int $beforeId = 0, ?bool $acked = null, bool $selftests = true): array
    {
        $b = $this->query()->orderBy('m.id', 'DESC')->limit(max(1, min(500, $limit)));
        if ($beforeId > 0) {
            $b->where('m.id <', $beforeId);
        }
        if ($acked === false) {
            $b->where('m.acked_at', null);
        } elseif ($acked === true) {
            $b->where('m.acked_at IS NOT NULL');
        }
        if (! $selftests) {
            $b->where('c.customer_key !=', SelfTest::CUSTOMER);
        }

        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'project' => $r['customer_key'] . '/' . $r['project_key']] + Messages::present($r),
            $b->get()->getResultArray());
    }

    /**
     * Database row of a role mail, or null (also for normal mails).
     *
     * @return array<string, mixed>|null
     */
    public function find(string $messageId): ?array
    {
        return $this->query()->where('m.message_id', $messageId)->get()->getRowArray();
    }

    /** Original mail (role mails always keep it), or null. */
    public function raw(array $row): ?string
    {
        return $row['raw_path'] === null ? null : MessageStore::forNode()->raw($row['spool_id'], $row['raw_path']);
    }

    private function query(): \CodeIgniter\Database\BaseBuilder
    {
        return db_connect()->table('receive_messages m')
            ->select('m.id, m.' . str_replace(', ', ', m.', preg_replace('/\s+/', ' ', Messages::LIST_FIELDS)) . ', c.customer_key, p.project_key')
            ->join('projects p', 'p.id = m.project_id')->join('customers c', 'c.id = p.customer_id')
            ->where('m.role IS NOT NULL');
    }
}
