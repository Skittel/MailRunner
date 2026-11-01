<?php

namespace App\Modules\Receive\Controllers\Customer;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Support\Time;

/**
 * GET /v1/journal?after_id=0&limit=100 - events of the token's project, oldest first.
 */
class Journal extends BaseController
{
    public function index()
    {
        $after = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit = max(1, min(1000, (int) ($this->request->getGet('limit') ?? 100)));

        $rows = db_connect()->table('journal')
            ->select('id, created_at, event, details')
            ->where('project_id', CurrentToken::projectId())
            ->where('id >', $after)
            ->orderBy('id')->limit($limit)
            ->get()->getResultArray();

        $events = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'created_at' => Time::toIso($r['created_at']),
            'event'      => $r['event'],
            'details'    => $r['details'] === null ? null : json_decode($r['details'], true),
        ], $rows);

        return ApiResponse::ok([
            'data'          => $events,
            'next_after_id' => $events === [] ? $after : end($events)['id'],
            'has_more'      => count($events) === $limit,
        ]);
    }
}
