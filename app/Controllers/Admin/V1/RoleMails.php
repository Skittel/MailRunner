<?php

namespace App\Controllers\Admin\V1;

use App\Core\Api\ApiResponse;
use App\Modules\Receive\Controllers\Customer\Messages;
use App\Modules\Receive\Libraries\RoleMails as RoleMailService;

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
        $limit  = max(1, min(500, (int) ($this->request->getGet('limit') ?? 100)));
        $acked  = $this->request->getGet('acked');
        $before = (int) ($this->request->getGet('before_id') ?? 0);
        $rows   = (new RoleMailService())->list($limit, $before, $acked === null ? null : $acked === '1', $this->request->getGet('selftests') !== '0');
        $data   = array_map(static function ($r) { unset($r['id']); return $r; }, $rows);

        return ApiResponse::ok(['data' => $data, 'next_before_id' => $rows === [] ? null : end($rows)['id'], 'has_more' => count($rows) === $limit]);
    }

    public function show(string $messageId)
    {
        $row = (new RoleMailService())->find($messageId);
        if ($row === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Role mail not found');
        }

        return ApiResponse::ok(['data' => ['project' => $row['customer_key'] . '/' . $row['project_key']] + Messages::present($row) + Messages::content($row)]);
    }

    public function raw(string $messageId)
    {
        $service = new RoleMailService();
        $row     = $service->find($messageId);
        $raw     = $row === null ? null : $service->raw($row);
        if ($raw === null) {
            return ApiResponse::error(404, 'RAW_NOT_AVAILABLE', 'No original mail for this message');
        }

        return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'no-store')->setContentType('message/rfc822')->setBody($raw);
    }
}
