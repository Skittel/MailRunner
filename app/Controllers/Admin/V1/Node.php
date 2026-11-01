<?php

namespace App\Controllers\Admin\V1;

use App\Core\Api\ApiResponse;
use App\Core\Node\NodeInfo;
use App\Core\Node\SelfTest;
use App\Core\Support\Time;

/**
 * GET /admin/v1/node     identity, version, roles, last self test (full), key figures
 * GET /admin/v1/journal  node-wide journal (all projects), ?after_id=&limit=&event=a,b
 */
class Node extends AdminController
{
    public function show()
    {
        $db     = db_connect();
        $info   = (new NodeInfo(config('Mailrunner')))->toArray();
        $last   = $db->table('journal')->select('created_at, details')->where('event', SelfTest::EVENT)->orderBy('id', 'DESC')->limit(1)->get()->getRowArray();
        $since  = Time::nowObject()->modify('-24 hours')->format(Time::DB_FORMAT);
        $counts = [
            'customers'           => $db->table('customers')->countAllResults(),
            'projects'            => $db->table('projects')->countAllResults(),
            'messages_24h'        => $db->table('receive_messages')->where('received_at >=', $since)->countAllResults(),
            'messages_unacked'    => $db->table('receive_messages')->where('acked_at', null)->where('content_deleted_at', null)->countAllResults(),
            'role_mails_unacked'  => $db->table('receive_messages')->where('role IS NOT NULL')->where('acked_at', null)->where('content_deleted_at', null)->countAllResults(),
            'smtp_sessions_24h'   => $db->table('journal')->whereIn('event', ['smtp.attempt', 'smtp.connection'])->where('created_at >=', $since)->countAllResults(),
        ];

        return ApiResponse::ok([
            'node_id'  => $info['node_id'] ?? config('Mailrunner')->nodeId,
            'hostname' => (string) parse_url((string) config('App')->baseURL, PHP_URL_HOST),
            'version'  => $info['version'],
            'roles'    => $info['roles'],
            'selftest' => $last === null ? null : ['recorded_at' => Time::toIso($last['created_at'])] + (json_decode((string) $last['details'], true) ?: []),
            'counts'   => $counts,
        ]);
    }

    public function journal()
    {
        $after = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit = max(1, min(1000, (int) ($this->request->getGet('limit') ?? 100)));
        $types = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('event') ?? '')))));
        $b     = db_connect()->table('journal j')
            ->select('j.id, j.created_at, j.event, j.source, j.actor, j.details, c.customer_key, p.project_key')
            ->join('customers c', 'c.id = j.customer_id', 'left')->join('projects p', 'p.id = j.project_id', 'left')
            ->where('j.id >', $after)->orderBy('j.id')->limit($limit);
        if ($types !== []) {
            $b->whereIn('j.event', $types);
        }
        $rows = array_map(static fn ($r) => [
            'id'         => (int) $r['id'],
            'created_at' => Time::toIso($r['created_at']),
            'event'      => $r['event'],
            'source'     => $r['source'],
            'actor'      => $r['actor'],
            'customer'   => $r['customer_key'],
            'project'    => $r['project_key'] === null ? null : $r['customer_key'] . '/' . $r['project_key'],
            'details'    => $r['details'] === null ? null : json_decode($r['details'], true),
        ], $b->get()->getResultArray());

        return ApiResponse::ok(['data' => $rows, 'next_after_id' => $rows === [] ? $after : end($rows)['id'], 'has_more' => count($rows) === $limit]);
    }
}
