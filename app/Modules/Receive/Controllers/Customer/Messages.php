<?php

namespace App\Modules\Receive\Controllers\Customer;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Support\ChangeContext;
use App\Core\Support\Time;
use App\Core\Support\Transaction;
use App\Modules\Receive\Libraries\MessageStore;

/**
 * Received messages of the token's project.
 *
 * GET  /v1/messages?after_id=&limit=&acked=0|1[&include=content]
 *                                              list (metadata; with include=content also record/debug/raw_headers, limit max 100)
 * GET  /v1/messages/{message_id}                metadata + record (header data) + debug (analysis JSON) + raw_headers
 * GET  /v1/messages/{message_id}/raw            original mail (message/rfc822), only if kept (not for profile analysis)
 * POST /v1/messages/ack {"message_ids":[...]}   confirm collection (idempotent)
 */
class Messages extends BaseController
{
    /** Max page size when the list includes the content (decrypting is per message). */
    private const CONTENT_LIMIT = 100;

    private const LIST_FIELDS = 'id, message_id, spool_id, profile, role, status, received_at, queue_id, mail_from, rcpt, client_ip, helo,
        client_asn, client_as_org, client_country, client_city, client_continent, client_carrier, client_is_threat,
        header_from, header_to, subject, date_hdr, attachments, spf_status, dkim_status, analysis_status, analysis_ms,
        analysis_path, raw_path, size, sha256, acked_at, content_deleted_at, delete_reason';

    public function index()
    {
        $include = (string) ($this->request->getGet('include') ?? '');
        if (! in_array($include, ['', 'content'], true)) {
            return ApiResponse::error(400, 'INVALID_REQUEST', 'include must be "content" or empty');
        }
        $withContent = $include === 'content';
        $after       = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit       = max(1, min($withContent ? self::CONTENT_LIMIT : 1000, (int) ($this->request->getGet('limit') ?? 100)));
        $builder = db_connect()->table('receive_messages')->select(self::LIST_FIELDS)
            ->where('project_id', CurrentToken::projectId())->where('id >', $after)
            ->orderBy('id')->limit($limit);
        $acked = $this->request->getGet('acked');
        if ($acked === '0') {
            $builder->where('acked_at', null);
        } elseif ($acked === '1') {
            $builder->where('acked_at IS NOT NULL');
        }
        $raw  = $builder->get()->getResultArray();
        $rows = array_map([self::class, 'present'], $raw);
        $data = [];
        foreach ($rows as $i => $r) {
            $data[] = array_diff_key($r, ['cursor' => 1]) + ($withContent ? self::content($raw[$i]) : []);
        }

        return ApiResponse::ok([
            'data'          => $data,
            'next_after_id' => $rows === [] ? $after : end($rows)['cursor'],
            'has_more'      => count($rows) === $limit,
        ]);
    }

    public function show(string $messageId)
    {
        $row = $this->find($messageId);
        if ($row === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Message not found');
        }

        return ApiResponse::ok(['data' => array_diff_key(self::present($row), ['cursor' => 1]) + self::content($row)]);
    }

    /**
     * Decrypted analysis content (null fields once the content is deleted).
     *
     * @param array<string, mixed> $row
     *
     * @return array{record: mixed, debug: mixed, raw_headers: mixed}
     */
    private static function content(array $row): array
    {
        $analysis = $row['analysis_path'] === null ? null : MessageStore::forNode()->analysis($row['spool_id'], $row['analysis_path']);

        return [
            'record'      => $analysis['record'] ?? null,
            'debug'       => $analysis['debug'] ?? null,
            'raw_headers' => $analysis['raw_headers'] ?? null,
        ];
    }

    public function raw(string $messageId)
    {
        $row = $this->find($messageId);
        if ($row === null) {
            return ApiResponse::error(404, 'MESSAGE_NOT_FOUND', 'Message not found');
        }
        if ($row['raw_path'] === null) {
            return ApiResponse::error(404, 'RAW_NOT_AVAILABLE', 'The original mail is not kept for this message');
        }

        return $this->response->setStatusCode(200)
            ->setHeader('Cache-Control', 'no-store')
            ->setContentType('message/rfc822')
            ->setBody(MessageStore::forNode()->raw($row['spool_id'], $row['raw_path']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(string $messageId): ?array
    {
        return db_connect()->table('receive_messages')->select(self::LIST_FIELDS)
            ->where('project_id', CurrentToken::projectId())->where('message_id', $messageId)
            ->get()->getRowArray();
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
            'role'               => $row['role'],
            'status'             => $row['status'],
            'received_at'        => Time::toIso($row['received_at']),
            'envelope'           => [
                'mail_from' => $row['mail_from'],
                'rcpt'      => $row['rcpt'],
                'client_ip' => $row['client_ip'],
                'helo'      => $row['helo'],
                'queue_id'  => $row['queue_id'],
                'client'    => [
                    'asn'             => $row['client_asn'] === null ? null : (int) $row['client_asn'],
                    'as_organization' => $row['client_as_org'],
                    'country'         => $row['client_country'],
                    'city'            => $row['client_city'],
                    'continent'       => $row['client_continent'],
                    'carrier'         => $row['client_carrier'],
                    'is_threat'       => $row['client_is_threat'] === null ? null : (bool) $row['client_is_threat'],
                ],
            ],
            'header'             => [
                'from'    => $row['header_from'],
                'to'      => $row['header_to'],
                'subject' => $row['subject'],
                'date'    => $row['date_hdr'],
            ],
            'attachments'        => $row['attachments'] === null ? [] : json_decode($row['attachments'], true),
            'spf_status'         => $row['spf_status'],
            'dkim_status'        => $row['dkim_status'],
            'analysis_status'    => $row['analysis_status'],
            'analysis_ms'        => $row['analysis_ms'] === null ? null : (int) $row['analysis_ms'],
            'raw_available'      => $row['raw_path'] !== null,
            'size'               => (int) $row['size'],
            'sha256'             => $row['sha256'],
            'acked_at'           => Time::toIso($row['acked_at']),
            'content_deleted_at' => Time::toIso($row['content_deleted_at']),
            'delete_reason'      => $row['delete_reason'],
        ];
    }
}
