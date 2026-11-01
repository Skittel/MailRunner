<?php

namespace App\Modules\Receive\Controllers\Customer;

use App\Controllers\BaseController;
use App\Core\Api\ApiResponse;
use App\Core\Auth\CurrentToken;
use App\Core\Support\Time;
use App\Core\Node\SelfTest;
use App\Modules\Receive\Libraries\SmtpSessionImporter;

/**
 * GET /v1/journal?after_id=0&limit=100[&event=a,b] - events of the token's project, oldest first.
 *
 * Besides its own events a project sees, depending on its setting journal_scope:
 *   own          nothing else
 *   connections  smtp.connection without project (SMTP sessions without MAIL FROM)
 *   unassigned   all smtp.* events without project
 * Node events (node.selftest) are visible to every project.
 * Every event carries "scope": "project", "unassigned" or "node".
 */
class Journal extends BaseController
{
    public function index()
    {
        $after = max(0, (int) ($this->request->getGet('after_id') ?? 0));
        $limit = max(1, min(1000, (int) ($this->request->getGet('limit') ?? 100)));
        $types = array_values(array_filter(array_map('trim', explode(',', (string) ($this->request->getGet('event') ?? '')))));
        foreach ($types as $type) {
            if (preg_match('/^[a-z_]+\.[a-z_]+$/', $type) !== 1) {
                return ApiResponse::error(400, 'INVALID_REQUEST', 'event must be a comma-separated list of event names, e.g. smtp.attempt,receive.received');
            }
        }

        $projectId = CurrentToken::projectId();
        $scope     = (string) (db_connect()->table('projects')->select('journal_scope')->where('id', $projectId)->get()->getRow('journal_scope') ?? 'own');
        $unassigned = match ($scope) {
            'connections' => [SmtpSessionImporter::EVENT_CONNECTION],
            'unassigned'  => [SmtpSessionImporter::EVENT_CONNECTION, SmtpSessionImporter::EVENT_ATTEMPT],
            default       => [],
        };

        $builder = db_connect()->table('journal')
            ->select('id, created_at, event, project_id, details')
            ->where('id >', $after)
            ->groupStart()->where('project_id', $projectId);
        $visible = array_merge($unassigned, [SelfTest::EVENT]);
        $builder->orGroupStart()->where('project_id', null)->whereIn('event', $visible)->groupEnd();
        $builder->groupEnd();
        if ($types !== []) {
            $builder->whereIn('event', $types);
        }
        $rows = $builder->orderBy('id')->limit($limit)->get()->getResultArray();

        $events = array_map(static fn (array $r): array => [
            'id'         => (int) $r['id'],
            'created_at' => Time::toIso($r['created_at']),
            'event'      => $r['event'],
            'scope'      => $r['project_id'] !== null ? 'project' : ($r['event'] === SelfTest::EVENT ? 'node' : 'unassigned'),
            'details'    => $r['details'] === null ? null : json_decode($r['details'], true),
        ], $rows);

        return ApiResponse::ok([
            'data'          => $events,
            'next_after_id' => $events === [] ? $after : end($events)['id'],
            'has_more'      => count($events) === $limit,
        ]);
    }
}
