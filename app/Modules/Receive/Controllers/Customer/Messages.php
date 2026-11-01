<?php

namespace App\Modules\Receive\Controllers\Customer;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Secrets\NodeCrypto;
use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use App\Core\Support\Transaction;

/**
 * Received messages of the token's project.
 *
 * GET  /v1/messages?after_id=&limit=&acked=0|1  list (metadata only)
 * GET  /v1/messages/{message_id}                metadata + record (header data) + debug (analysis JSON) + raw_headers
 * POST /v1/messages/ack {"message_ids":[...]}   confirm collection (idempotent)
 */
class Messages extends BaseController
{
    private const LIST_FIELDS = 'id, message_id, profile, status, received_at, queue_id, mail_from, rcpt, client_ip, size, sha256, acked_at, content_deleted_at, delete_reason';

    public function index()
    {
        $after   = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit   = max(1, min(1000, (int) ($this->request->getGet('limit') ?? 100)));
        $builder = db_connect()->table('receive_messages')->select(self::LIST_FIELDS)
            ->where('project_id', CurrentToken::projectId())->where('id >', $after)
            ->orderBy('id')->limit($limit);
        $acked = $this->request->getGet('acked');
        if ($acked === '0') {
            $builder->where('acked_at', null);
        } elseif ($acked === '1') {
            $builder->where('acked_at IS NOT NULL');
        }
        $rows = array_map([self::class, 'present'], $builder->get()->getResultArray());

        return ApiResponse::ok([
            'data'          => array_map(static fn ($r) => array_diff_key($r, ['cursor' => 1]), $rows),
            'next_after_id' => $rows === [] ? $after : end($rows)['cursor'],
            'has_more'      => count($rows) === $limit,
        ]);
    }

    public function show(string $messageId)
    {
        $row = db_connect()->table('receive_messages')->select(self::LIST_FIELDS . ', data_enc')
            ->where('project_id', CurrentToken::projectId())->where('message_id', $messageId)
            ->get()->getRowArray();
        if ($row === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Message not found');
        }
        $data = $row['data_enc'] === null ? null
            : NodeCrypto::forNode()->decryptJson($row['data_enc'], 'db:receive_messages:' . $row['message_id']);
        unset($row['data_enc']);

        return ApiResponse::ok(['data' => array_diff_key(self::present($row), ['cursor' => 1]) + [
            'record'      => $data['record'] ?? null,
            'debug'       => $data['debug'] ?? null,
            'raw_headers' => $data['raw_headers'] ?? null,
        ]]);
    }

    public function ack()
    {
        $ids = $this->request->getJSON(true)['message_ids'] ?? null;
        if (! is_array($ids) || $ids === [] || count($ids) > 1000) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'Body must be {"message_ids": [...]} with 1-1000 ids');
        }
        $ids       = array_values(array_unique(array_map('strval', $ids)));
        $projectId = CurrentToken::projectId();
        $tokenId   = CurrentToken::get()['token_id'];
        $db        = db_connect();

        $result = Transaction::run($db, static function () use ($db, $ids, $projectId, $tokenId): array {
            $rows = $db->table('receive_messages')->select('id, message_id, acked_at')
                ->where('project_id', $projectId)->whereIn('message_id', $ids)->get()->getResultArray();
            $found   = array_column($rows, null, 'message_id');
            $acked   = [];
            $project = $db->table('projects')->where('id', $projectId)->get()->getRowArray();
            foreach ($rows as $row) {
                if ($row['acked_at'] === null) {
                    $db->table('receive_messages')->where('id', $row['id'])->update(['acked_at' => Time::now()]);
                    service('journalWriter')->append('receive.acked', new ChangeContext('api', $tokenId), (int) $project['customer_id'], $projectId, [
                        'message_id' => $row['message_id'],
                    ]);
                }
                $acked[] = $row['message_id'];
            }

            return ['acked' => $acked, 'unknown' => array_values(array_diff($ids, array_keys($found)))];
        });

        return ApiResponse::ok($result);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'cursor'             => (int) $row['id'],
            'message_id'         => $row['message_id'],
            'profile'            => $row['profile'],
            'status'             => $row['status'],
            'received_at'        => Time::toIso($row['received_at']),
            'queue_id'           => $row['queue_id'],
            'mail_from'          => $row['mail_from'],
            'rcpt'               => $row['rcpt'],
            'client_ip'          => $row['client_ip'],
            'size'               => (int) $row['size'],
            'sha256'             => $row['sha256'],
            'acked_at'           => Time::toIso($row['acked_at']),
            'content_deleted_at' => Time::toIso($row['content_deleted_at']),
            'delete_reason'      => $row['delete_reason'],
        ];
    }
}
