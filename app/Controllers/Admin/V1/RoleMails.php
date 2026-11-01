<?php

namespace App\Controllers\Admin\V1;

use App\Core\Api\ApiResponse;
use App\Modules\Receive\Controllers\Customer\Messages;
use App\Modules\Receive\Libraries\MessageStore;

/**
 * Mail to postmaster@/abuse@ of all projects (decision E-18/E-19: the operator may read them).
 * GET /admin/v1/role-mails?after_id=&limit=&acked=0|1   newest first when before_id is used, else oldest first
 * GET /admin/v1/messages/{message_id}                  metadata + analysis (role mails only)
 * GET /admin/v1/messages/{message_id}/raw              original mail (role mails only)
 */
class RoleMails extends AdminController
{
    public function index()
    {
        $limit = max(1, min(500, (int) ($this->request->getGet('limit') ?? 100)));
        $b     = db_connect()->table('receive_messages m')->select('m.' . str_replace(', ', ', m.', preg_replace('/\s+/', ' ', Messages::LIST_FIELDS)) . ', c.customer_key, p.project_key')
            ->join('projects p', 'p.id = m.project_id')->join('customers c', 'c.id = p.customer_id')
            ->where('m.role IS NOT NULL')->orderBy('m.id', 'DESC')->limit($limit);
        if (($before = (int) ($this->request->getGet('before_id') ?? 0)) > 0) {
            $b->where('m.id <', $before);
        }
        $acked = $this->request->getGet('acked');
        if ($acked === '0') {
            $b->where('m.acked_at', null);
        } elseif ($acked === '1') {
            $b->where('m.acked_at IS NOT NULL');
        }
        $rows = $b->get()->getResultArray();
        $data = array_map(static fn ($r) => ['project' => $r['customer_key'] . '/' . $r['project_key']] + Messages::present($r), $rows);

        return ApiResponse::ok(['data' => $data, 'next_before_id' => $rows === [] ? null : (int) end($rows)['id'], 'has_more' => count($rows) === $limit]);
    }

    public function show(string $messageId)
    {
        $row = $this->find($messageId);
        if ($row === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Role mail not found');
        }

        return ApiResponse::ok(['data' => ['project' => $row['customer_key'] . '/' . $row['project_key']] + Messages::present($row) + Messages::content($row)]);
    }

    public function raw(string $messageId)
    {
        $row = $this->find($messageId);
        if ($row === null || $row['raw_path'] === null) {
            return ApiResponse::error(404, 'RAW_NOT_AVAILABLE', 'No original mail for this message');
        }

        return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setContentType('message/rfc822')
            ->setBody(MessageStore::forNode()->raw($row['spool_id'], $row['raw_path']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $messageId): ?array
    {
        return db_connect()->table('receive_messages m')->select('m.' . str_replace(', ', ', m.', preg_replace('/\s+/', ' ', Messages::LIST_FIELDS)) . ', c.customer_key, p.project_key')
            ->join('projects p', 'p.id = m.project_id')->join('customers c', 'c.id = p.customer_id')
            ->where('m.role IS NOT NULL')->where('m.message_id', $messageId)->get()->getRowArray();
    }
}
